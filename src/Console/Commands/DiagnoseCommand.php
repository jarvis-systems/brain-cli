<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\ServiceProvider;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

class DiagnoseCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'diagnose {--human : Human-readable output}';

    /**
     * @var string
     */
    protected $description = 'Diagnose Brain environment and self-dev mode';

    public function handle(): int
    {
        $diagnosis = $this->buildDiagnosis();

        if ($this->option('human')) {
            $this->renderHuman($diagnosis);
        } else {
            $this->line((string) json_encode(
                $diagnosis,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }

        return 0;
    }

    /**
     * Build the full diagnostic payload.
     *
     * @return array<string, mixed>
     */
    private function buildDiagnosis(): array
    {
        $projectRoot = Brain::projectDirectory();
        $brainDirName = to_string(config('brain.dir', '.brain'));
        $brainDirFullPath = Brain::workingDirectory();
        $dotBrainPath = $projectRoot . DS . $brainDirName;

        // Autodetect signals (match BrainIncludesTrait::isSelfDev() logic)
        $nodeBrainInRoot = is_file($projectRoot . DS . 'node' . DS . 'Brain.php');
        $nodeBrainInDotBrain = is_file($dotBrainPath . DS . 'node' . DS . 'Brain.php');
        $dotBrainIsSymlink = is_link($dotBrainPath);
        $dotBrainTarget = $dotBrainIsSymlink ? (readlink($dotBrainPath) ?: null) : null;

        // ENV-based detection
        $envHasSelfDev = ServiceProvider::hasEnv('SELF_DEV_MODE');
        $envSelfDevValue = $envHasSelfDev ? ServiceProvider::getEnv('SELF_DEV_MODE') : null;

        // Determine self-dev state and source
        // Match BrainIncludesTrait::isSelfDev(): (autodetect) || (env positive)
        $autodetectPositive = $nodeBrainInRoot && $nodeBrainInDotBrain;
        $envPositive = $envHasSelfDev && $this->isTruthy($envSelfDevValue);

        $selfDevMode = $autodetectPositive || $envPositive;

        if ($envPositive) {
            $selfDevSource = 'env';
        } elseif ($autodetectPositive) {
            $selfDevSource = 'autodetect';
        } else {
            $selfDevSource = 'off';
        }

        return [
            'self_dev_mode' => $selfDevMode,
            'self_dev_source' => $selfDevSource,
            'autodetect_signals' => [
                'node_brain_php_in_root' => $nodeBrainInRoot,
                'node_brain_php_in_dot_brain' => $nodeBrainInDotBrain,
                'dot_brain_is_symlink' => $dotBrainIsSymlink,
                'dot_brain_target' => $dotBrainTarget,
            ],
            'paths' => [
                'project_root' => $projectRoot,
                'brain_dir' => $brainDirFullPath,
                'dot_brain_path' => $dotBrainPath,
            ],
            'modes' => [
                'strict_mode' => Brain::getEnv('STRICT_MODE', 'not set'),
                'cognitive_level' => Brain::getEnv('COGNITIVE_LEVEL', 'not set'),
                'verbosity' => ServiceProvider::isDebug() ? 'debug' : 'normal',
            ],
            'version' => [
                'root' => $this->readVersion($projectRoot . DS . 'composer.json'),
                'core' => $this->readVersion($projectRoot . DS . 'core' . DS . 'composer.json'),
                'cli' => $this->readVersion($projectRoot . DS . 'cli' . DS . 'composer.json'),
            ],
        ];
    }

    /**
     * Check if a value is truthy (matches varIsPositive logic).
     */
    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /**
     * Read version from a composer.json file.
     */
    private function readVersion(string $composerPath): string|null
    {
        if (!is_file($composerPath)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($composerPath), true);

        if (is_array($json) && isset($json['version']) && is_string($json['version'])) {
            return $json['version'];
        }

        return null;
    }

    /**
     * Render diagnosis in human-readable format.
     *
     * @param  array<string, mixed>  $diagnosis
     */
    private function renderHuman(array $diagnosis): void
    {
        $this->components->info('Brain Diagnostics');
        $this->newLine();

        $this->components->twoColumnDetail(
            'Self-dev mode',
            $diagnosis['self_dev_mode'] ? '<fg=green>ACTIVE</>' : 'OFF'
        );
        $this->components->twoColumnDetail('Source', $diagnosis['self_dev_source']);
        $this->newLine();

        $this->components->info('Autodetect Signals');
        foreach ($diagnosis['autodetect_signals'] as $key => $value) {
            $display = is_bool($value) ? ($value ? 'YES' : 'NO') : ($value ?? 'null');
            $this->components->twoColumnDetail($key, (string) $display);
        }
        $this->newLine();

        $this->components->info('Paths');
        foreach ($diagnosis['paths'] as $key => $value) {
            $this->components->twoColumnDetail($key, (string) $value);
        }
        $this->newLine();

        $this->components->info('Modes');
        foreach ($diagnosis['modes'] as $key => $value) {
            $this->components->twoColumnDetail($key, (string) $value);
        }
        $this->newLine();

        $this->components->info('Versions');
        foreach ($diagnosis['version'] as $key => $value) {
            $this->components->twoColumnDetail($key, $value ?? 'unknown');
        }
    }
}
