<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\Contracts\CompileContract;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class CompileCommand extends Command
{
    protected $signature = 'compile {agent=claude : Agent for which compilation}';

    protected $description = 'Compile the Brain configurations files';

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
            $files = $this->getFile($this->getFileList());
            $this->compiler->boot(collect($files));
            if ($this->compiler->compile()) {
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
            if (getenv('BRAIN_CLI_DEBUG') === '1') {
                dump($e);
            }
            $this->components->error("Compiler failed: " . $e->getMessage());
            return ERROR;
        }
    }

    /**
     * @return array<string>
     */
    public function getFileList(string $path = ''): array
    {
        $dir = Brain::workingDirectory();
        $nodeFolderName = DS . 'node' . (! empty($path) ? DS . $path : '');
        $projectPathToNodes = to_string(config('brain.dir', '.brain'))
            . $nodeFolderName . DS;
        $files = File::allFiles($dir . $nodeFolderName);
        $formats = $this->compiler->formats();
        return array_filter(array_map(function ($file) use ($projectPathToNodes, $formats) {
            $pn = $file->getPathname();
            $rp = $file->getRelativePathname();
            $resultFormat = 'xml';
            foreach ($formats as $path => $format) {
                if (str_starts_with($pn, $path)) {
                    $resultFormat = $format;
                    break;
                }
            }
            if (! str_ends_with($rp, '.php')) {
                return null;
            }
            return $projectPathToNodes . $rp . "::" . $resultFormat;
        }, $files));
    }

    /**
     * @param  non-empty-string|array<non-empty-string>  $file
     * @param  'xml'|'json'|'yaml'|'toml'|'meta'  $format
     * @return array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'namespaceType': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}
     */
    public function getFile(string|array $file, string $format = 'xml'): array
    {
        $dir = Brain::workingDirectory();
        $command = [
            php_binary(),
            '-d', 'xdebug.mode=off', '-d', 'opcache.enable_cli=1',
            $dir . DS . 'vendor' . DS . 'bin' . DS . 'brain-core',
            'get:file',
            is_array($file) ? implode(' && ', $file) : $file,
            '--' . $format,
        ];

        $result = trim((new Process($command, Brain::projectDirectory()))
            ->setTimeout(null)
            ->mustRun()
            ->getOutput());

        try {
            if ($result) {

                $result = tag_replace(to_string($result), [
                    'PROJECT_DIRECTORY' => Brain::projectDirectory(relative: true) ?: '.',
                    'BRAIN_DIRECTORY' => Brain::workingDirectory(relative: true),
                    'NODE_DIRECTORY' => Brain::workingDirectory('node', true),
                    'TIMESTAMP' => time(),
                    'DATE_TIME' => date('Y-m-d H:i:s'),
                    'DATE' => date('Y-m-d'),
                    'TIME' => date('H:i:s'),
                    'YEAR' => date('Y'),
                    'MONTH' => date('m'),
                    'DAY' => date('d'),
                    'UNIQUE_ID' => uniqid(),
                    'AGENT' => $this->agent->value,
                    'BRAIN_FILE' => $this->compiler->brainFile(),
                    'MCP_FILE' => $this->compiler->mcpFile(),
                    'BRAIN_FOLDER' => $this->compiler->brainFolder(),
                    'AGENTS_FOLDER' => $this->compiler->agentsFolder(),
                    'COMMANDS_FOLDER' => $this->compiler->commandsFolder(),
                    'SKILLS_FOLDER' => $this->compiler->skillsFolder(),
                ], '{{ * }}');

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
}

