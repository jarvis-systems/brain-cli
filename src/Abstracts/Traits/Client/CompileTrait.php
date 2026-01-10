<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts\Traits\Client;

use Bfg\Dto\Dto;
use BrainCLI\Dto\Compile\AgentInfo;
use BrainCLI\Dto\Compile\Collect;
use BrainCLI\Dto\Compile\CommandInfo;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Dto\Compile\Puzzle;
use BrainCLI\Services\SchemaGenerator;
use BrainCLI\Support\Brain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait CompileTrait
{
    /**
     * MCP file format
     */
    protected string $compileMcpFormat = 'json';

    /**
     * Puzzle configuration
     *
     * @var array<string, string>
     */
    protected array $compilePuzzle = [];

    /**
     * Compile files into brain structure.
     *
     * @param  Collection<int, Data>  $files
     */
    public function compile(Collection $files): bool
    {
        $collectedFiles = $this->collectCompileFiles($files);

        // Generate dynamic schema for .brain/ (non-blocking)
        $this->generateProjectSchema($collectedFiles);

        return $this->generateBrainFolder($this->folder(), $collectedFiles->brain)
            && $this->generateBrainAssets($this->folder(), $collectedFiles->brain)
            && $this->generateBrainFile($this->file(), $collectedFiles->brain)
            && $this->generateSettingsFile($this->settingsFile(), $collectedFiles->brain, $collectedFiles->mcp)
            && $this->generateMcpFile($this->mcpFile(), $collectedFiles->mcp, $collectedFiles->brain)
            && $this->generateAgentsFiles($this->agentsFolder(), $collectedFiles->agents, $collectedFiles->brain)
            && $this->generateCommandsFiles($this->commandsFolder(), $collectedFiles->commands, $collectedFiles->brain)
            && $this->generateSkillsFiles($this->skillsFolder(), $collectedFiles->skills, $collectedFiles->brain);
    }

    /**
     * Set unique formats for folders
     *
     * @return array<non-empty-string, non-empty-string>
     */
    public function compileFormats(): array
    {
        return [
            Brain::nodeDirectory('Mcp', true) => $this->compileMcpFormat,
        ];
    }

    /**
     * Get compile puzzle configuration
     */
    public function compilePuzzle(): Puzzle
    {
        return Puzzle::fromAssoc($this->compilePuzzle);
    }

    /**
     * Compile runtime variables for the brain.
     */
    public function compileVariables(): array
    {
        return [];
    }

    /**
     * Actions after compilation is done.
     */
    public function compileDone(): void
    {
        //
    }

    /**
     * Generate brain folder.
     */
    protected function generateBrainFolder(string $folder, Data $brain): bool
    {
        $path = Brain::projectDirectory($folder);
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }

    /**
     * Generate brain assets.
     */
    protected function generateBrainAssets(string $folder, Data $brain): bool
    {
        $assets = __DIR__ . '/../../../../assets/' . $this->agent()->value . '/';
        if (is_dir($assets)) {
            return File::copyDirectory($assets, Brain::projectDirectory($folder));
        }
        return true;
    }

    /**
     * Generate a brain file.
     */
    protected function generateBrainFile(string $filePath, Data $brain): bool
    {
        $filePath = Brain::projectDirectory($filePath);
        return !! file_put_contents(
            $filePath,
            $this->createBrainContent(
                $brain, is_file($filePath) ? file_get_contents($filePath) : null
            ),
        );
    }

    /**
     * Generate MCP file.
     *
     * @param  \Illuminate\Support\Collection<int, Data>  $mcp
     */
    protected function generateMcpFile(string $filePath, Collection $mcp, Data $brain): bool
    {
        $filePath = Brain::projectDirectory($filePath);
        $old = is_file($filePath) ? file_get_contents($filePath) : null;
        $old = $old && Dto::isJson($old) ? json_decode($old, true) : $old;
        $content = $this->createMcpContent($mcp, $brain, $old);
        if (is_array($content)) {
            $content = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return !! file_put_contents($filePath, $content);
    }

    /**
     * Generate settings file.
     *
     * @param  \Illuminate\Support\Collection<int, Data>  $mcp
     */
    protected function generateSettingsFile(string $filePath, Data $brain, Collection $mcp): bool
    {
        if (method_exists($this, 'createSettingsContent')) {
            $filePath = Brain::projectDirectory($filePath);
            $old = is_file($filePath) ? file_get_contents($filePath) : null;
            $old = $old && Dto::isJson($old) ? json_decode($old, true) : $old;
            $content = $this->createSettingsContent($brain, $mcp, $old);
            if (is_array($content)) {
                $content = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return !! file_put_contents($filePath, $content);
        }
        return true;
    }

    /**
     * Generate agents files.
     *
     * @param  \Illuminate\Support\Collection<int, Data>  $agents
     */
    protected function generateAgentsFiles(string $folder, Collection $agents, Data $brain): bool
    {
        if (method_exists($this, 'createAgentContent')) {
            File::cleanDirectory(Brain::projectDirectory($folder));
            $directory = Brain::projectDirectory($folder);

            foreach ($agents as $agent) {
                $info = AgentInfo::fromAssoc([
                    'filename' => ($agent->meta['id'] ?? $agent->id),
                    'insidePath' => $this->insidePath($agent->file, 'Agents'),
                    'model' => $agent->meta['model'] ?? ($brain->meta['model'] ?? null),
                    'color' => $agent->meta['color'] ?? 'blue',
                    'name' => $agent->meta['id'] ?? $agent->id,
                    'description' => $agent->meta['description'] ?? '',
                    'meta' => $agent->meta,
                ]);

                $content = $this->createAgentContent($agent, $brain, $info);

                if (is_string($content)) {
                    $file = implode(DS, [$directory, $info->insidePath, $info->filename . '.md']);
                } elseif (is_array($content)) {
                    $file = $content['file'] ?? throw new \RuntimeException('Agent content array must contain "file" key.');
                    $content = $content['content'] ?? throw new \RuntimeException('Agent content array must contain "content" key.');
                    $file = implode(DS, [$directory, $file]);
                } else {
                    continue;
                }

                $fileDir = dirname($file);
                if (!is_dir($fileDir) && !mkdir($fileDir, 0755, true)) {
                    return false;
                }

                if (! file_put_contents($file, $content)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Generate commands files.
     *
     * @param  \Illuminate\Support\Collection<int, Data>  $commands
     */
    protected function generateCommandsFiles(string $folder, Collection $commands, Data $brain): bool
    {
        File::cleanDirectory(Brain::projectDirectory($folder));
        $directory = Brain::projectDirectory($folder);

        foreach ($commands as $command) {
            $info = CommandInfo::fromAssoc([
                'filename' => preg_replace('/(.*)-command/', '$1', $command->id),
                'insidePath' => $this->insidePath($command->file, 'Commands'),
                'name' => $command->meta['id'] ?? $command->id,
                'description' => $command->meta['description'] ?? '',
                'meta' => $command->meta,
            ]);

            $content = $this->createCommandContent($command, $brain, $info);

            if (is_string($content)) {
                $file = implode(DS, array_filter([$directory, $info->insidePath ?: null, $info->filename . '.md']));
            } elseif (is_array($content)) {
                $file = $content['file'] ?? throw new \RuntimeException('Command content array must contain "file" key.');
                $content = $content['content'] ?? throw new \RuntimeException('Command content array must contain "content" key.');
                $file = implode(DS, [$directory, $file]);
            } else {
                continue;
            }

            $fileDir = dirname($file);
            if (!is_dir($fileDir) && !mkdir($fileDir, 0755, true)) {
                return false;
            }

            if (! file_put_contents($file, $content)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Generate skills files.
     */
    protected function generateSkillsFiles(string $folder, Collection $skills, Data $brain): bool
    {
        return true;
    }

    /**
     * Generate project schema for .brain/ directory.
     *
     * Schema generation is non-critical - if it fails, compilation continues.
     */
    protected function generateProjectSchema(Collect $files): bool
    {
        try {
            $generator = SchemaGenerator::createDefault();
            $outputPath = Brain::workingDirectory('agent-schema.json');

            return $generator->generateAndSave($files, $this->agent(), $outputPath);
        } catch (\Throwable $e) {
            // Schema generation is non-critical, log but don't fail compilation
            return false;
        }
    }

    /**
     * Create brain content.
     */
    protected function createBrainContent(Data $brain, string|null $old): string
    {
        return $brain->structure ?: throw new \RuntimeException('Brain structure is empty.');
    }

    /**
     * @param  Collection<int, Data>  $mcp
     * @return non-empty-string|array<string, mixed>
     */
    protected function createMcpContent(Collection $mcp, Data $brain, array|string|null $old): string|array
    {
        $settings = is_array($old) ? $old : [];
        $settings['mcpServers'] = [];
        foreach ($mcp as $file) {
            $server = $file->structure;
            if (is_array($server)) {
                $name = $file->meta['id'] ?? preg_replace('/(.*)-mcp/', '$1', $file->id);
                $settings['mcpServers'][$name] = $server;
            }
        }
        return $settings;
    }

    /**
     * Get inside path from file.
     */
    protected function insidePath(string $file, string $from): string
    {
        $path = trim(to_string(str_replace([
            Brain::nodeDirectory($from, true),
            Brain::nodeDirectory($from),
            DS.basename($file)
        ], '', $file)), DS);

        $path = array_map(function ($part) {
            return Str::snake($part, '-');
        }, explode(DS, $path));

        return implode(DS, $path);
    }

    /**
     * Collect compile files into categorized groups.
     *
     * @param  Collection<int, Data>  $files
     * @return Collect
     */
    protected function collectCompileFiles(Collection $files): Collect
    {
        $agents = [];
        $commands = [];
        $mcp = [];
        $skills = [];
        $brain = null;

        foreach ($files as $file) {
            if ($file->namespaceType && str_starts_with($file->namespaceType, 'Agents')) {
                $agents[] = $file;
            } elseif ($file->namespaceType && str_starts_with($file->namespaceType, 'Commands')) {
                $commands[] = $file;
            } elseif ($file->namespaceType && str_starts_with($file->namespaceType, 'Mcp')) {
                $mcp[] = $file;
            } elseif ($file->namespaceType && str_starts_with($file->namespaceType, 'Skills')) {
                $skills[] = $file;
            } elseif ($file->namespaceType === null && $file->classBasename === 'Brain') {
                $brain = $file;
            }
        }

        if ($brain === null) {
            throw new \RuntimeException('Brain file not found in the provided files.');
        }

        return Collect::fromAssoc(
            compact('agents', 'commands', 'mcp', 'skills', 'brain')
        );
    }
}
