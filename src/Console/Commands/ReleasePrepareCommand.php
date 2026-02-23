<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Services\Release\ReleasePrepareRunner;
use Illuminate\Console\Command;

/**
 * Release pack generator with optional apply mode.
 *
 * Delegates to ReleasePrepareRunner for evidence collection and version bumps.
 * Default is dry-run. With --apply, bumps version fields in composer.json files.
 * Supports dual output: JSON (default) and human-readable (--human).
 */
class ReleasePrepareCommand extends Command
{
    use HelpersTrait;

    /**
     * @var string
     */
    protected $signature = 'release:prepare
        {version? : Target version (e.g. v0.3.0). Auto-suggests if omitted}
        {--apply : Apply version bumps to composer.json files (requires passing readiness)}
        {--dry-run : Explicit dry-run mode (default behavior)}
        {--evidence : Collect readiness and compile-diff evidence}
        {--human : Human-readable output instead of JSON}
        {--json : JSON output (default)}
    ';

    /**
     * @var string
     */
    protected $description = 'Generate a release preparation pack with version detection, optional evidence, and apply mode';

    public function handle(): int
    {
        return CommandKernel::run(
            fn () => $this->executeCommand(),
            'release:prepare',
            fn (\Throwable $e) => $this->components->error($e->getMessage()),
        );
    }

    protected function executeCommand(): int
    {
        $this->checkWorkingDir();

        $cwd = getcwd();

        if ($cwd === false) {
            $this->components->error('Unable to determine working directory.');

            return ERROR;
        }

        $targetVersion = $this->argument('version');

        if ($targetVersion === null || $targetVersion === '') {
            $targetVersion = $this->suggestNextVersion($cwd);
            $this->components->info("Auto-suggested target version: {$targetVersion}");
        }

        /** @var string $targetVersion */
        $runner = new ReleasePrepareRunner($cwd);

        $applyMode = (bool) $this->option('apply');

        if ($applyMode) {
            $result = $runner->apply($targetVersion, true);
        } else {
            $result = $runner->prepare($targetVersion, (bool) $this->option('evidence'));
        }

        if ($this->option('human')) {
            $this->renderHuman($result);
        } else {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        $status = $result['status'] ?? '';

        if ($status === 'validation_failed') {
            return 2;
        }

        if ($applyMode && $status === 'blocked') {
            return 2;
        }

        return OK;
    }

    /**
     * Auto-suggest next minor version from root composer.json.
     */
    private function suggestNextVersion(string $cwd): string
    {
        $composerPath = $cwd . '/composer.json';

        if (! file_exists($composerPath)) {
            return 'v0.1.0';
        }

        $json = json_decode((string) file_get_contents($composerPath), true);

        if (! is_array($json) || ! isset($json['version'])) {
            return 'v0.1.0';
        }

        $current = ltrim((string) $json['version'], 'v');

        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $m)) {
            return 'v0.1.0';
        }

        $major = (int) $m[1];
        $minor = (int) $m[2] + 1;

        return "v{$major}.{$minor}.0";
    }

    /**
     * Render results in human-readable format.
     *
     * @param  array<string, mixed>  $result
     */
    private function renderHuman(array $result): void
    {
        $this->newLine();
        $this->components->info('Release Preparation');
        $this->newLine();

        $status = $result['status'] ?? 'unknown';
        $statusBadge = match ($status) {
            'ready' => '<fg=green>ready</>',
            'applied' => '<fg=green>applied</>',
            'blocked' => '<fg=red>blocked</>',
            'validation_failed' => '<fg=red>validation_failed</>',
            'evidence_only' => '<fg=yellow>evidence_only</>',
            default => $status,
        };

        $this->components->twoColumnDetail('Status', $statusBadge);
        $this->components->twoColumnDetail('Current version', (string) ($result['current_version'] ?? 'unknown'));
        $this->components->twoColumnDetail('Target version', (string) ($result['target_version'] ?? 'unknown'));

        $this->newLine();
        $this->components->info('Detected versions');

        /** @var array<string, array{path: string, version: string|null, tag: string|null}> $versions */
        $versions = $result['versions'] ?? [];

        foreach ($versions as $name => $info) {
            $version = $info['version'] ?? 'n/a';
            $tag = $info['tag'] ?? 'n/a';

            $this->components->twoColumnDetail(
                "  {$name}",
                "{$version} (tag: {$tag})",
            );
        }

        $this->renderEvidenceSection($result);

        if ($result['applied'] ?? false) {
            $this->newLine();
            $this->components->info('Applied changes');

            /** @var array<int, array{file: string, field: string, before: string|null, after: string}> $applyPlan */
            $applyPlan = $result['apply_plan'] ?? [];

            foreach ($applyPlan as $change) {
                $this->components->twoColumnDetail(
                    "  {$change['file']}:{$change['field']}",
                    "{$change['before']} → {$change['after']}",
                );
            }
        }

        $this->newLine();

        $packDir = $result['pack_dir'] ?? '';
        $this->components->twoColumnDetail('Pack', (string) $packDir);
        $this->components->twoColumnDetail('Next', "{$packDir}/next-steps.md");
    }

    /**
     * Render evidence section with present/missing/skipped badges.
     *
     * @param  array<string, mixed>  $result
     */
    private function renderEvidenceSection(array $result): void
    {
        /** @var array{readiness: array{status: string, reason: string|null}, compile_diff: array{status: string, reason: string|null}}|null $evidence */
        $evidence = $result['evidence'] ?? null;

        if ($evidence === null) {
            return;
        }

        $this->newLine();
        $this->components->info('Evidence');

        $labels = [
            'readiness' => 'Readiness',
            'compile_diff' => 'Compile diff',
        ];

        foreach ($labels as $key => $label) {
            $meta = $evidence[$key] ?? ['status' => 'skipped', 'reason' => null];
            $badge = match ($meta['status']) {
                'present' => '<fg=green>present</>',
                'missing' => '<fg=red>missing</>',
                'skipped' => '<fg=gray>skipped</>',
                default => $meta['status'],
            };

            $value = $badge;

            if ($meta['reason'] !== null) {
                $value .= " ({$meta['reason']})";
            }

            $this->components->twoColumnDetail("  {$label}", $value);
        }
    }
}
