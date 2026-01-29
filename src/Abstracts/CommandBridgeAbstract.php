<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts;

use BrainCLI\Console\Commands\UpdateCommand;
use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\LockFileFactory;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

use function Illuminate\Support\php_binary;

abstract class CommandBridgeAbstract extends Command
{
    use HelpersTrait;

    /**
     * @var Agent
     */
    protected Agent $agent;

    /**
     * @var ClientAbstract
     */
    protected ClientAbstract $client;

    /**
     * @return int
     */
    public function handle(): int
    {
        $this->checkWorkingDir();

        if (($updateResult = $this->autoupdate()) !== OK) {
            return $updateResult;
        }

        try {
            $result = $this->handleBridge();
            if (is_int($result)) {
                return $result;
            }
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return OK;
        } catch (Throwable $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $this->components->error("Unexpected error: " . $e->getMessage());
            return ERROR;
        }
    }

    abstract protected function handleBridge(): int|array;

    /**
     * Initialize bridge for agent
     *
     * @param  \BrainCLI\Enums\Agent|string  $agent
     * @return ClientAbstract
     */
    public function initFor(Agent|string $agent): ClientAbstract
    {
        $enum = is_string($agent) ? Agent::tryFromEnabled($agent) : $agent;

        if (! $enum instanceof Agent) {
            throw new InvalidArgumentException("Unsupported agent: {$agent}");
        }

        LockFileFactory::save('last-used-agent', $enum->value);

        try {
            $client = $this->laravel->make($enum->containerService(), [
                'command' => $this,
            ]);
            if (! $client instanceof ClientAbstract) {
                throw new InvalidArgumentException(
                    sprintf("Client for agent %s does not implement ClientAbstract", $enum->value)
                );
            }
            $this->agent = $enum;
            return $this->client = $client;
        } catch (Throwable $t) {
            throw new InvalidArgumentException($t->getMessage(), $t->getCode(), $t);
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

    /**
     * @param  string  $file
     * @param  bool  $vendor
     * @return string|null
     */
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
            $formats = $this->client->compileFormats();
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
     * @param  array<string, scalar>|null  $env
     * @return Collection<int, Data>
     */
    public function convertFiles(string|array $file, string|null $format = null, array|null $env = null): Collection
    {
        if (empty($file)) {
            /** @var Collection<int, Data> */
            return new Collection;
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
            json_encode(array_merge(
                $vars,
                $this->client->compileVariables(),
                $this->client->compilePuzzle()->toArray(),
                ($env ?: [])
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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

                //$result = str_replace('$ARGUMENTS', '`$ARGUMENTS`', $result);

                $fileCollection = $result
                    ? json_decode($result, true, flags: JSON_THROW_ON_ERROR)
                    : throw new \JsonException("Empty JSON output");

                if (is_array($fileCollection)) {
                    $resultCollection = [];
                    foreach ($fileCollection as $key => $file) {
                        if (
                            is_array($file)
                            && isset(
                                $file['id'], $file['file'], $file['meta'], $file['class'],
                                $file['namespace'], $file['classBasename'], $file['format']
                            )
                        ) {
                            $resultCollection[] = Data::fromAssoc($file);
                        } else {
                            throw new \JsonException("Invalid JSON structure for file at key {$key}");
                        }
                    }
                    /** @var Collection<int, Data> */
                    return new Collection($resultCollection);
                }
            }
            throw new \JsonException("Unexpected JSON output");
        } catch (\JsonException $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $this->components->error("Failed to decode JSON output: " . $e->getMessage());
            exit(ERROR);
        }
    }

    protected function autoupdate(): int
    {
        if ($this->hasOption('no-update') && ! $this->option('no-update')) {

            $detailUrl = "https://repo.packagist.org/p2/jarvis-brain/core.json";

            try {
                $data = Http::timeout(5)
                    ->connectTimeout(5)
                    ->get($detailUrl)
                    ->throw()
                    ->json();
            } catch (RequestException $e) {
                if (Brain::isDebug()) {
                    dd($e);
                }
                return OK;
            } catch (Throwable $e) {
                if (Brain::isDebug()) {
                    dd($e);
                }
                $this->components->warn("Failed to check for updates: " . $e->getMessage());
                return ERROR;
            }

            if (
                is_array($data)
                && isset($data['packages']['jarvis-brain/core'][0]['source']['reference'])
            ) {
                $reference = $data['packages']['jarvis-brain/core'][0]['source']['reference'];
                if (is_string($reference) && ! empty($reference)) {

                    $composerLockFile = Brain::workingDirectory('composer.lock');
                    $lockJson = is_file($composerLockFile) ?
                        json_decode((string) file_get_contents($composerLockFile), true)
                        : null;

                    if (
                        $lockJson
                        && is_array($lockJson)
                        && isset($lockJson['packages'])
                    ) {
                        $core = collect($lockJson['packages'])
                            ->firstWhere('name', 'jarvis-brain/core');
                        $currentReference = $core['source']['reference'] ?? null;

                        if (
                            $currentReference
                            && is_string($currentReference)
                            && $currentReference !== $reference
                        ) {
                            $this->call(UpdateCommand::class, [
                                '--cli' => true,
                            ]);
                        }
                    }
                }
            }
        }
        return OK;
    }

    /**
     * Detect agents from argument or prompt
     *
     * @return array<Agent>
     */
    protected function detectAgents(bool $exists = false): array
    {
        $selectAgent = $this->hasArgument('agent') ? $this->argument('agent') : null;
        $agents = [];
        if ($selectAgent === 'exists') {
            $agents = $this->detectExistsAgents();
        } else {
            foreach (explode(',', $selectAgent) as $item) {
                try {
                    $agents[] = Agent::fromEnabled($item);
                } catch (Throwable $t) {
                    throw new InvalidArgumentException("Unsupported agent: {$item}", $t->getCode(), $t);
                }
            }
        }

        if (! count($agents)) {
            try {
                if ($exists) {
                    $existsAgents = $this->detectExistsAgents();
                    if ($existsAgents) {
                        $agents[] = Agent::fromEnabled(
                            $this->components->choice('Select agent for compilation', $existsAgents, $existsAgents[0]->value)
                        );
                    }
                } else {
                    $agents[] = Agent::fromEnabled(
                        $this->components->choice('Select agent for compilation', Agent::list(), Agent::list()[0]->value)
                    );
                }
            } catch (Throwable $t) {
                throw new InvalidArgumentException("Before, you need to compile some agent configurations.", $t->getCode(), $t);
            }
        }
        if (! count($agents)) {
            throw new InvalidArgumentException("Before, you need to compile some agent configurations.");
        }
        return $agents;
    }

    /**
     * @return array<Agent>
     */
    protected function detectExistsAgents(): array
    {
        $agents = Agent::enabledCases();
        $existsAgents = [];
        foreach ($agents as $agent) {
            try {
                $client = $this->initFor($agent);
                if (is_dir($client->folder())) {
                    $existsAgents[] = $agent;
                }
            } catch (Throwable $e) {
                if (Brain::isDebug()) {
                    dd($e);
                }
            }
        }
        return $existsAgents;
    }

    /**
     * @return array<string, scalar>
     */
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
            'BRAIN_FILE' => $this->client->file(),
            'MCP_FILE' => $this->client->settingsFile(),
            'BRAIN_FOLDER' => $this->client->folder() . DS,
            'AGENTS_FOLDER' => $this->client->agentsFolder() . DS,
            'COMMANDS_FOLDER' => $this->client->commandsFolder() . DS,
            'SKILLS_FOLDER' => $this->client->skillsFolder() . DS,
        ]);
    }
}
