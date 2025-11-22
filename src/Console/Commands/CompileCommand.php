<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\Contracts\CompileContract;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class CompileCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'compile 
        {agent=claude : Agent for which compilation}
        {--show-variables : Show available variables for compilation}
        ';

    protected $description = 'Compile the Brain configurations files';

    protected $aliases = ['c', 'generate', 'build', 'make'];

    protected Agent $agent;
    protected CompileContract $compiler;

    /**
     * @return int
     */
    public function handle(): int
    {
        if ($error = $this->initCommand()) {
            return $error;
        }

        $this->components->info('Starting compilation for agent: ' . $this->agent->value);

        return $this->applyComplier(function () {

            if ($this->option('show-variables')) {
                $brainFile = $this->getWorkingFile('Brain.php::meta');
                $brain = collect($this->convertFiles($brainFile))->first();
                if ($brain === null) {
                    $this->components->error("Brain configuration file not found.");
                    return ERROR;
                }
                $this->components->info('Available compilation variables:');
                $vars = $brain['meta'];
                $compilerVars = $this->compiler->compileVariables();
                $allVars = array_merge($vars, $compilerVars);
                foreach ($allVars as $key => $value) {
                    $this->line(" - <fg=cyan>{{ $key }}</>: " . to_string($value));
                }
                return OK;
            }

            $files = $this->convertFiles($this->getWorkingFiles());
            if (empty($files)) {
                $this->components->warn("No configuration files found for agent {$this->agent->value}.");
                return ERROR;
            }
            $this->compiler->boot(collect($files));
            if ($this->compiler->compile()) {
                $assets = __DIR__ . '/../../../assets/' . $this->agent->value . '/';
                if (File::exists($assets)) {
                    File::copyDirectory($assets, Brain::projectDirectory($this->compiler->brainFolder()));
                }
                $this->components->success("Compilation Brain configurations files successfully.");
                return OK;
            } else {
                $this->components->error("Compilation failed for agent {$this->agent->value}.");
                return ERROR;
            }
        });
    }

    protected function initCommand(): int
    {
        $this->checkWorkingDir();

        $agent = $this->argument('agent');
        $enum = Agent::tryFrom($agent);

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
            $nodeFolderName = DS . 'vendor' . DS . 'jarvis-brain' . DS . 'core' . DS . 'src' . DS . $path;
        } else {
            $nodeFolderName = DS . 'node' . (! empty($path) ? DS . $path : '');
        }

        if (! is_dir($dir . $nodeFolderName)) {
            return [];
        }
        $files = File::allFiles($dir . $nodeFolderName);
        return array_filter(array_map(function ($file) use ($vendor) {
            return $this->getWorkingFile($file->getRelativePathname(), $vendor);
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
     * @param  'xml'|'json'|'yaml'|'toml'|'meta'  $format
     * @return array<array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>
     */
    public function convertFiles(string|array $file, string $format = 'xml'): array
    {
        if (empty($file)) {
            return [];
        }
        $dir = Brain::workingDirectory();
        $vars = $this->getDefaultVariables();
        $file = is_array($file) ? implode(' && ', $file) : $file;

        $command = [
            php_binary(),
            '-d', 'xdebug.mode=off', '-d', 'opcache.enable_cli=1',
            $dir . DS . 'vendor' . DS . 'bin' . DS . 'brain-core',
            'convert',
            $file,
            '--' . $format,
            '--variables',
            json_encode(array_merge($vars, $this->compiler->compileVariables(), [
                'puzzle-agent' => $this->compiler->compileAgentPrefix(),
                'puzzle-store-var' => $this->compiler->compileStoreVarPrefixPrefix(),
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

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

