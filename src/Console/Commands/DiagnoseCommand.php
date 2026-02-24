<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Services\CompileLock;
use BrainCLI\Services\SelfDev\SelfDevResolver;
use BrainCLI\ServiceProvider;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

class DiagnoseCommand extends Command
{
    protected $signature = 'diagnose
        {--json : JSON output (default)}
        {--human : Human-readable output}
    ';

    protected $description = 'Diagnose Brain environment and self-dev mode';

    public function handle(): int
    {
        return CommandKernel::run(
            fn () => $this->executeCommand(),
            'diagnose',
            fn (\Throwable $e) => $this->components->error($e->getMessage()),
        );
    }

    protected function executeCommand(): int
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

    private function buildDiagnosis(): array
    {
        $resolver = SelfDevResolver::make();
        $signals = $resolver->getSignals();
        $isSymlink = $signals['dot_brain_is_symlink'];
        $symlinkTarget = $signals['dot_brain_target'];
        $isSelfHosting = $isSymlink && $symlinkTarget === '.';

        return [
            'self_hosting' => $isSelfHosting,
            'self_dev_mode' => $resolver->isEnabled(),
            'self_dev_source' => $resolver->getSource(),
            'brain_dir_is_symlink' => $isSymlink,
            'brain_dir_target' => $symlinkTarget,
            'autodetect_signals' => [
                'node_brain_php_in_root' => $signals['node_brain_php_in_root'],
                'node_brain_php_in_dot_brain' => $signals['node_brain_php_in_dot_brain'],
                'dot_brain_is_symlink' => $isSymlink,
                'dot_brain_target' => $symlinkTarget,
            ],
            'paths' => [
                'project_root' => Brain::projectDirectory(),
                'brain_dir' => Brain::workingDirectory(),
                'dot_brain_path' => $resolver->getEnvFilePath(),
            ],
            'modes' => [
                'strict_mode' => Brain::getEnv('STRICT_MODE', 'not set'),
                'cognitive_level' => Brain::getEnv('COGNITIVE_LEVEL', 'not set'),
                'verbosity' => ServiceProvider::isDebug() ? 'debug' : 'normal',
            ],
            'test_mode_contract' => CompileLock::getContractDiagnostics(Brain::workingDirectory()),
            'version' => [
                'root' => $this->readVersion(Brain::projectDirectory('composer.json')),
                'core' => $this->readVersion(Brain::projectDirectory(['core', 'composer.json'])),
                'cli' => $this->readVersion(Brain::projectDirectory(['cli', 'composer.json'])),
            ],
        ];
    }

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

    public function isTruthy(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1'], true);
        }

        return (bool) $value;
    }

    private function renderHuman(array $diagnosis): void
    {
        $this->components->info('Brain Diagnostics');
        $this->newLine();

        $this->components->twoColumnDetail(
            'Self-hosting',
            $diagnosis['self_hosting'] ? '<fg=green>YES</>' : 'NO'
        );
        $this->components->twoColumnDetail(
            'Self-dev mode',
            $diagnosis['self_dev_mode'] ? '<fg=green>ACTIVE</>' : 'OFF'
        );
        $this->components->twoColumnDetail('Source', $diagnosis['self_dev_source']);
        $this->components->twoColumnDetail(
            'Brain dir symlink',
            $diagnosis['brain_dir_is_symlink'] ? 'YES → ' . $diagnosis['brain_dir_target'] : 'NO'
        );
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

        $this->components->info('Test Mode Contract');
        $tmc = $diagnosis['test_mode_contract'];
        $this->components->twoColumnDetail(
            'nolock_allowed',
            $tmc['nolock_allowed'] ? '<fg=green>YES</>' : '<fg=red>NO</>'
        );
        $this->components->twoColumnDetail('phpunit_detected', $tmc['phpunit_detected'] ? 'YES' : 'NO');
        $this->components->twoColumnDetail('test_mode_enabled', $tmc['test_mode_enabled'] ? 'YES' : 'NO');
        $this->components->twoColumnDetail('test_mode_source_ci', $tmc['test_mode_source_ci'] ? 'YES' : 'NO');
        $this->components->twoColumnDetail('under_temp_dir', $tmc['under_temp_dir'] ? 'YES' : 'NO');
        $this->components->twoColumnDetail('under_dist_tmp', $tmc['under_dist_tmp'] ? 'YES' : 'NO');
        $this->components->twoColumnDetail('has_marker', $tmc['has_marker'] ? 'YES' : 'NO');
        $this->components->twoColumnDetail('isolated_workdir', $tmc['isolated_workdir'] ? 'YES' : 'NO');
        if (! empty($tmc['reasons'])) {
            $this->components->twoColumnDetail('block_reasons', implode(', ', $tmc['reasons']));
        }
        $this->newLine();

        $this->components->info('Versions');
        foreach ($diagnosis['version'] as $key => $value) {
            $this->components->twoColumnDetail($key, $value ?? 'unknown');
        }
    }
}
