<?php

declare(strict_types=1);

namespace BrainCLI\Console\Traits;

use BrainCLI\Enums\Agent;
use BrainCLI\Services\Contracts\CompileContract;
use BrainCLI\Support\Brain;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;

use function Illuminate\Support\php_binary;

trait CompilerBridgeTrait
{
    use HelpersTrait;

    protected Agent $agent;
    protected CompileContract $compiler;

    /**
     * @return array<Agent>
     */
    protected function detectExistsAgents(): array
    {
        $agents = Agent::cases();
        $existsAgents = [];
        foreach ($agents as $agent) {
            try {
                $compiler = $this->laravel->make($agent->containerName());
                if (is_file($compiler->brainFile())) {
                    $existsAgents[] = $agent;
                }
            } catch (\Throwable $e) {
                if (Brain::isDebug()) {
                    dd($e);
                }
            }
        }
        return $existsAgents;
    }

    protected function initCommand(Agent|string $agent): int
    {
        $this->checkWorkingDir();

        $enum = is_string($agent) ? Agent::tryFrom($agent) : $agent;

        if ($enum === null) {
            $this->components->error("Unsupported agent: {$agent}");
            return ERROR;
        }

        $this->agent = $enum;

        return OK;
    }

    protected function applyComplier(callable $cb): int
    {
        try {
            $compiler = $this->laravel->make($this->agent->containerName());
            if ($compiler instanceof CompileContract) {
                $this->compiler = $compiler;
                return $cb();
            } else {
                $this->components->error("Compiler for agent {$this->agent->value} does not implement CompileContract");
                return ERROR;
            }
        } catch (\Throwable $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $this->components->error("Compiler failed!");
            return ERROR;
        }
    }

    /**
     * @param  string  $path
     * @param  bool  $vendor
     * @return array<string>
     */
    public function getWorkingFiles(string $path = '', bool $vendor = false): array
    {
        $dir = Brain::workingDirectory();
        if ($vendor) {
            $fo = DS . 'vendor' . DS . 'jarvis-brain' . DS . 'core' . DS . 'src';
            $nodeFolderName = $fo . (! empty($path) ? DS . $path : '');
        } else {
            $fo = DS . 'node';
            $nodeFolderName = $fo . (! empty($path) ? DS . $path : '');
        }

        if (! is_dir($dir . $nodeFolderName)) {
            return [];
        }
        $files = File::allFiles($dir . $nodeFolderName);
        return array_filter(array_map(function ($file) use ($vendor, $dir, $fo) {
            $path = str_replace($dir . $fo . DS, '', $file->getPathname());
            return $this->getWorkingFile($path, $vendor);
        }, $files));
    }

    public function getWorkingFile(string $file, bool $vendor = false): string|null
    {
        $resultFormat = 'xml';
        $detectFormat = true;

        if (preg_match('/::([a-z]+)$/', $file, $matches)) {
            $file = preg_replace('/(::[a-z]+)$/', '', $file);
            $resultFormat = $matches[1];
            $detectFormat = false;
        }

        if ($vendor) {
            $nodeFolderName = DS . 'vendor' . DS . 'jarvis-brain' . DS . 'core' . DS . 'src' . DS . $file;
        } else {
            $nodeFolderName = DS . 'node' . DS . $file;
        }

        $bf = to_string(config('brain.dir', '.brain'));
        $projectPathToNodes = $bf . $nodeFolderName;

        $fullPath = Brain::projectDirectory($projectPathToNodes);

        if (($fullPath = realpath($fullPath)) === false || ! is_file($fullPath)) {
            return null;
        }

        $checkPath = str_replace(Brain::projectDirectory() . DS, '', dirname($fullPath));
        $checkPath = str_replace($bf . DS, '', $checkPath);

        if ($detectFormat) {
            $formats = $this->compiler->formats();
            foreach ($formats as $path => $format) {
                $path = str_replace($bf . DS, '', $path);
                if ($checkPath === $path) {
                    $resultFormat = $format;
                    break;
                }
            }
        }
        if (! str_ends_with($projectPathToNodes, '.php')) {
            return null;
        }
        return $projectPathToNodes . "::" . $resultFormat;
    }

    /**
     * @param  non-empty-string|array<non-empty-string>  $file
     * @param  'xml'|'json'|'yaml'|'toml'|'meta'|null  $format
     * @return array<array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>
     */
    public function convertFiles(string|array $file, string|null $format = null): array
    {
        if (empty($file)) {
            return [];
        }
        $dir = Brain::workingDirectory();
        $vars = $this->getDefaultVariables();
        $file = is_array($file) ? implode(' && ', $file) : $file;

        $command = array_filter([
            php_binary(),
            '-d', 'xdebug.mode=off', '-d', 'opcache.enable_cli=1',
            $dir . DS . 'vendor' . DS . 'bin' . DS . 'brain-core',
            'convert',
            $file,
            ($format ? '--' . $format : null),
            '--variables',
            json_encode(array_merge($vars, $this->compiler->compileVariables(), [
                'puzzle-agent' => $this->compiler->compileAgentPrefix(),
                'puzzle-store-var' => $this->compiler->compileStoreVarPrefixPrefix(),
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $process = (new Process($command, Brain::projectDirectory()))
            ->setTimeout(null)
            ->mustRun();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        $result = trim($process->getOutput());

        try{
            if ($result) {

                $result = tag_replace(to_string($result), $vars, '{{ * }}');

                $result = str_replace(["\\\\{\\\\{", "\\\\}\\\\}"], ["{{", "}}"], $result);

                return $result
                    ? json_decode($result, true, flags: JSON_THROW_ON_ERROR)
                    : throw new \JsonException("Empty JSON output");
            }
            throw new \JsonException("Unexpected JSON output");
        } catch (\JsonException $e) {
            if (getenv('BRAIN_CLI_DEBUG') === '1') {
                dump($e);
            }
            $this->components->error("Failed to decode JSON output: " . $e->getMessage());
            exit(ERROR);
        }
    }

    protected function getDefaultVariables(): array
    {
        return array_merge([
            'PROJECT_DIRECTORY' => (Brain::projectDirectory(relative: true) ?: '.') . DS,
            'BRAIN_DIRECTORY' => Brain::workingDirectory(relative: true) . DS,
            'NODE_DIRECTORY' => Brain::workingDirectory('node', true) . DS,
            'TIMESTAMP' => time(),
            'DATE_TIME' => date('Y-m-d H:i:s'),
            'DATE' => date('Y-m-d'),
            'TIME' => date('H:i:s'),
            'YEAR' => date('Y'),
            'MONTH' => date('m'),
            'DAY' => date('d'),
            'UNIQUE_ID' => uniqid(),
            'HAS_PYTHON' => file_exists(Brain::projectDirectory('requirements.txt')) || file_exists(Brain::projectDirectory('pyproject.toml')),
            'HAS_COMPOSER' => file_exists(Brain::projectDirectory('composer.json')),
            'HAS_LARAVEL' => file_exists(Brain::projectDirectory('composer.json')) && str_contains(file_get_contents(Brain::projectDirectory('composer.json')), 'laravel/framework'),
            'HAS_NODE_JS' => file_exists(Brain::projectDirectory('package.json')),
            'HAS_GO_LANG' => file_exists(Brain::projectDirectory('go.mod')),

            'AGENT' => $this->agent->value,
            'AGENT_CONST' => strtoupper($this->agent->value),
            'AGENT_LABEL' => $this->agent->label(),
            'BRAIN_FILE' => $this->compiler->brainFile(),
            'MCP_FILE' => $this->compiler->mcpFile(),
            'BRAIN_FOLDER' => $this->compiler->brainFolder() . DS,
            'AGENTS_FOLDER' => $this->compiler->agentsFolder() . DS,
            'COMMANDS_FOLDER' => $this->compiler->commandsFolder() . DS,
            'SKILLS_FOLDER' => $this->compiler->skillsFolder() . DS,
        ]);
    }
}
