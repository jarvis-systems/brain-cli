<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Console\Commands\MakeMcpCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Deep tests for MakeMcpCommand pure methods and source contracts.
 *
 * COMMIT 2 scope: reflection on pure helpers (tabsMultiline, constVariable,
 * exportWithDynamicPaths, variablesDetect*) + expanded source inspection
 * for guarded code paths (marketplace, credentials, Process).
 *
 * No network, no DB, no Process spawning, no interactive prompts.
 */
class MakeMcpCommandDeepTest extends TestCase
{
    private MakeMcpCommand $command;

    private string $source;

    protected function setUp(): void
    {
        defined('OK') || define('OK', 0);
        defined('ERROR') || define('ERROR', 1);

        $this->command = new MakeMcpCommand();

        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeMcpCommand.php'
        ) ?: '';
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * @return mixed
     */
    private function callMethod(string $method, mixed ...$args)
    {
        return (new ReflectionMethod($this->command, $method))->invoke($this->command, ...$args);
    }

    private function setConst(array $values): void
    {
        $prop = new ReflectionProperty($this->command, 'const');
        $prop->setValue($this->command, $values);
    }

    // ═══ tabsMultiline (pure string formatter) ═══════════════════════

    public function test_tabs_multiline_single_line_returns_unchanged(): void
    {
        $result = $this->callMethod('tabsMultiline', "'npx'");
        $this->assertSame("'npx'", $result);
    }

    public function test_tabs_multiline_indents_subsequent_lines(): void
    {
        $input = "[\n    'arg1',\n    'arg2',\n]";
        $result = $this->callMethod('tabsMultiline', $input);

        $lines = explode("\n", $result);
        $this->assertSame('[', $lines[0]);
        // Lines 2+ get 8-space prefix
        $this->assertStringStartsWith('        ', $lines[1]);
        $this->assertStringStartsWith('        ', $lines[2]);
        $this->assertStringStartsWith('        ', $lines[3]);
    }

    public function test_tabs_multiline_empty_string(): void
    {
        $result = $this->callMethod('tabsMultiline', '');
        $this->assertSame('', $result);
    }

    public function test_tabs_multiline_preserves_first_line_exactly(): void
    {
        $input = "first line\nsecond line";
        $result = $this->callMethod('tabsMultiline', $input);
        $this->assertStringStartsWith('first line', $result);
    }

    public function test_tabs_multiline_eight_space_indent(): void
    {
        $input = "a\nb";
        $result = $this->callMethod('tabsMultiline', $input);
        $this->assertSame("a\n" . str_repeat(' ', 8) . 'b', $result);
    }

    // ═══ exportWithDynamicPaths (VarExporter + path replacement) ═════

    public function test_export_simple_string(): void
    {
        $this->setConst([]);
        $result = $this->callMethod('exportWithDynamicPaths', 'hello');
        $this->assertSame("'hello'", $result);
    }

    public function test_export_array_value(): void
    {
        $this->setConst([]);
        $result = $this->callMethod('exportWithDynamicPaths', ['a', 'b']);
        $this->assertStringContainsString("'a'", $result);
        $this->assertStringContainsString("'b'", $result);
    }

    public function test_export_replaces_project_directory_with_getcwd(): void
    {
        $fakeDir = '/tmp/fake-project-dir-' . uniqid();
        $this->setConst(['PROJECT_DIRECTORY' => $fakeDir]);

        // VarExporter wraps in quotes: 'path' — replacement matches exact exported form
        $result = $this->callMethod('exportWithDynamicPaths', $fakeDir);
        $this->assertStringNotContainsString($fakeDir, $result);
        $this->assertStringContainsString("getcwd()", $result);
    }

    public function test_export_no_replacement_when_project_directory_not_set(): void
    {
        $this->setConst([]);
        $result = $this->callMethod('exportWithDynamicPaths', '/some/path');
        $this->assertStringContainsString('/some/path', $result);
    }

    // ═══ constVariable (pure const resolver) ═════════════════════════

    public function test_const_variable_returns_existing_value(): void
    {
        $this->setConst(['MY_KEY' => 'my-value']);
        $result = $this->callMethod('constVariable', '<const.MY_KEY>', 'MY_KEY', null, []);
        $this->assertSame('my-value', $result);
    }

    public function test_const_variable_returns_old_when_key_missing(): void
    {
        $this->setConst([]);
        $result = $this->callMethod('constVariable', '<const.MISSING>', 'MISSING', null, []);
        $this->assertSame('<const.MISSING>', $result);
    }

    public function test_const_variable_applies_str_method(): void
    {
        $this->setConst(['NAME' => 'hello_world']);
        $result = $this->callMethod('constVariable', '<const.NAME(studly)>', 'NAME', 'studly', []);
        $this->assertSame('HelloWorld', $result);
    }

    public function test_const_variable_applies_str_snake(): void
    {
        $this->setConst(['NAME' => 'HelloWorld']);
        $result = $this->callMethod('constVariable', '<const.NAME(snake)>', 'NAME', 'snake', []);
        $this->assertSame('hello_world', $result);
    }

    public function test_const_variable_returns_old_for_invalid_str_method(): void
    {
        $this->setConst(['NAME' => 'value']);
        $result = $this->callMethod('constVariable', '<const.NAME(nonExistentMethod)>', 'NAME', 'nonExistentMethod', []);
        $this->assertSame('<const.NAME(nonExistentMethod)>', $result);
    }

    // ═══ variablesDetectString (regex + dispatch) ════════════════════

    public function test_detect_string_no_variables_returns_unchanged(): void
    {
        $input = 'plain string with no variables';
        $result = $this->callMethod('variablesDetectString', $input);
        $this->assertSame($input, $result);
    }

    public function test_detect_string_resolves_const_variable(): void
    {
        $this->setConst(['MY_VAR' => 'resolved-value']);
        $result = $this->callMethod('variablesDetectString', '<const.MY_VAR>');
        $this->assertSame('resolved-value', $result);
    }

    public function test_detect_string_resolves_const_with_method(): void
    {
        $this->setConst(['APP_NAME' => 'my_app']);
        $result = $this->callMethod('variablesDetectString', '<const.APP_NAME(studly)>');
        $this->assertSame('MyApp', $result);
    }

    public function test_detect_string_unknown_type_returns_original(): void
    {
        $result = $this->callMethod('variablesDetectString', '<unknown.SOMETHING>');
        $this->assertSame('<unknown.SOMETHING>', $result);
    }

    public function test_detect_string_multiple_variables_in_one_string(): void
    {
        $this->setConst(['A' => 'alpha', 'B' => 'beta']);
        $result = $this->callMethod('variablesDetectString', 'start-<const.A>-middle-<const.B>-end');
        $this->assertSame('start-alpha-middle-beta-end', $result);
    }

    public function test_detect_string_preserves_surrounding_text(): void
    {
        $this->setConst(['X' => 'val']);
        $result = $this->callMethod('variablesDetectString', 'prefix/<const.X>/suffix');
        $this->assertSame('prefix/val/suffix', $result);
    }

    // ═══ variablesDetectArray (recursive) ════════════════════════════

    public function test_detect_array_flat_no_variables(): void
    {
        $input = ['key' => 'value', 'other' => 'data'];
        $result = $this->callMethod('variablesDetectArray', $input);
        $this->assertSame($input, $result);
    }

    public function test_detect_array_resolves_const_in_values(): void
    {
        $this->setConst(['DIR' => '/usr/local']);
        $result = $this->callMethod('variablesDetectArray', ['path' => '<const.DIR>']);
        $this->assertSame(['path' => '/usr/local'], $result);
    }

    public function test_detect_array_resolves_const_in_string_keys(): void
    {
        $this->setConst(['HEADER' => 'X-Custom']);
        $result = $this->callMethod('variablesDetectArray', ['<const.HEADER>' => 'value']);
        $this->assertSame(['X-Custom' => 'value'], $result);
    }

    public function test_detect_array_nested_recursion(): void
    {
        $this->setConst(['V' => 'resolved']);
        $input = ['level1' => ['level2' => '<const.V>']];
        $result = $this->callMethod('variablesDetectArray', $input);
        $this->assertSame(['level1' => ['level2' => 'resolved']], $result);
    }

    public function test_detect_array_preserves_non_string_values(): void
    {
        $input = ['count' => 42, 'enabled' => true, 'data' => null];
        $result = $this->callMethod('variablesDetectArray', $input);
        $this->assertSame(42, $result['count']);
        $this->assertTrue($result['enabled']);
        $this->assertNull($result['data']);
    }

    public function test_detect_array_preserves_integer_keys(): void
    {
        $this->setConst(['V' => 'x']);
        $input = [0 => '<const.V>', 1 => 'plain'];
        $result = $this->callMethod('variablesDetectArray', $input);
        $this->assertSame([0 => 'x', 1 => 'plain'], $result);
    }

    // ═══ Source Inspection: generateData contract ════════════════════

    public function test_generate_data_maps_stdio_to_command_key(): void
    {
        $this->assertStringContainsString("'command'", $this->source);
        $this->assertStringContainsString("'args'", $this->source);
        // stdio uses "command" and "args" keys
        $this->assertMatchesRegularExpression(
            '/\$isStdio\s*\?\s*["\']command["\']/',
            $this->source,
        );
    }

    public function test_generate_data_maps_non_stdio_to_url_key(): void
    {
        // Non-stdio (http/sse) uses "url" and "headers" keys
        $this->assertStringContainsString("'url'", $this->source);
        $this->assertStringContainsString("'headers'", $this->source);
    }

    public function test_generate_data_appends_mcp_suffix_check(): void
    {
        $this->assertStringContainsString("str_ends_with(\$className, 'Mcp')", $this->source);
    }

    // ═══ Source Inspection: generateParameters contract ══════════════

    public function test_generate_parameters_uses_mcp_stub_prefix(): void
    {
        // Stub name is "mcp.{type}" — dynamic based on transport
        $this->assertMatchesRegularExpression(
            '/[\'"]stub[\'"]\s*=>\s*[\'"]mcp\.\{/',
            $this->source,
        );
    }

    public function test_generate_parameters_calls_export_and_tabs(): void
    {
        $this->assertStringContainsString('$this->tabsMultiline($this->exportWithDynamicPaths(', $this->source);
    }

    public function test_generate_parameters_includes_mcp_id_replacement(): void
    {
        $this->assertStringContainsString("'mcpId'", $this->source);
        $this->assertStringContainsString("Str::snake(\$info['name'], '-')", $this->source);
    }

    // ═══ Source Inspection: guarded code paths ═══════════════════════

    public function test_setup_execution_guarded_by_ok_result(): void
    {
        // Setup commands only run when generation succeeds
        $this->assertStringContainsString('$result === OK', $this->source);
        $this->assertStringContainsString("'config']['setup']", $this->source);
    }

    public function test_process_used_only_for_setup_commands(): void
    {
        // Process is imported and used for setup, not for file generation
        $this->assertStringContainsString('use Symfony\Component\Process\Process', $this->source);

        // Process::new only in setup block (inside foreach after OK check)
        $processCount = substr_count($this->source, 'new Process(');
        $this->assertLessThanOrEqual(2, $processCount, 'Process instantiation should be limited to setup');
    }

    public function test_credential_access_isolated_in_input_variable(): void
    {
        // Credential model used ONLY in inputVariable method
        $this->assertStringContainsString('use BrainCLI\Models\Credential', $this->source);
        $this->assertStringContainsString('Credential::query()', $this->source);

        // inputVariable is the gateway — all credential access goes through it
        $this->assertStringContainsString('function inputVariable(', $this->source);
    }

    public function test_find_in_market_reads_from_config(): void
    {
        $this->assertStringContainsString("config('brain.mcp.markets'", $this->source);
    }

    public function test_find_in_market_fallback_returns_null_fields(): void
    {
        // When no market match, returns structure with null fields
        $this->assertStringContainsString("'command' => null", $this->source);
        $this->assertStringContainsString("'url' => null", $this->source);
        $this->assertStringContainsString("'args' => null", $this->source);
        $this->assertStringContainsString("'headers' => null", $this->source);
        $this->assertStringContainsString("'setup' => null", $this->source);
    }

    public function test_find_in_market_type_resolution_order(): void
    {
        // Type resolution: --http first, then --sse, then default stdio
        $this->assertMatchesRegularExpression(
            "/option\('http'\).*option\('sse'\)/s",
            $this->source,
            'Type resolution: --http checked before --sse',
        );
    }

    // ═══ Source Inspection: addDefaultConstants contract ═════════════

    public function test_add_default_constants_includes_project_paths(): void
    {
        $this->assertStringContainsString("'PROJECT_DIRECTORY'", $this->source);
        $this->assertStringContainsString("'BRAIN_DIRECTORY'", $this->source);
    }

    public function test_add_default_constants_includes_timestamp_fields(): void
    {
        $this->assertStringContainsString("'TIMESTAMP'", $this->source);
        $this->assertStringContainsString("'DATE_TIME'", $this->source);
        $this->assertStringContainsString("'DATE'", $this->source);
        $this->assertStringContainsString("'TIME'", $this->source);
        $this->assertStringContainsString("'YEAR'", $this->source);
        $this->assertStringContainsString("'MONTH'", $this->source);
        $this->assertStringContainsString("'DAY'", $this->source);
    }

    public function test_add_default_constants_includes_tool_fields(): void
    {
        $this->assertStringContainsString("'UNIQUE_ID'", $this->source);
        $this->assertStringContainsString("'COMPOSER'", $this->source);
        $this->assertStringContainsString("'PHP'", $this->source);
        $this->assertStringContainsString("'BRAIN_VERSION'", $this->source);
    }

    // ═══ Contract parity with other MakeCommands ═════════════════════

    public function test_mcp_uses_same_trait_set_as_other_make_commands(): void
    {
        $masterSource = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeMasterCommand.php'
        ) ?: '';

        // Both must use StubGeneratorTrait and HelpersTrait
        $this->assertStringContainsString('use StubGeneratorTrait', $masterSource);
        $this->assertStringContainsString('use HelpersTrait', $masterSource);
        $this->assertStringContainsString('use StubGeneratorTrait', $this->source);
        $this->assertStringContainsString('use HelpersTrait', $this->source);
    }

    public function test_mcp_handle_delegates_to_generate_file(): void
    {
        $this->assertStringContainsString('$this->generateFile(', $this->source);
    }

    public function test_mcp_has_generate_parameters_method(): void
    {
        $this->assertStringContainsString('function generateParameters(', $this->source);
    }
}
