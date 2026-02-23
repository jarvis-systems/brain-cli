<?php

declare(strict_types=1);

namespace BrainCLI\Services\Release;

/**
 * Release pack generator with optional apply mode.
 *
 * Collects version info from 3 repos, optionally runs readiness and compile-diff,
 * then generates a structured release pack under .work/releases/{version}/.
 * Default is dry-run (read-only). With apply mode, bumps version fields in composer.json files.
 */
class ReleasePrepareRunner
{
    private const VERSION = '1.2.0';

    /**
     * @var array<string, array{composer: string, dir: string}>
     */
    private const REPOS = [
        'node' => ['composer' => 'composer.json', 'dir' => '.'],
        'core' => ['composer' => 'core/composer.json', 'dir' => 'core'],
        'cli' => ['composer' => 'cli/composer.json', 'dir' => 'cli'],
    ];

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Run the release preparation and return structured result.
     *
     * @return array<string, mixed>
     */
    public function prepare(string $targetVersion, bool $collectEvidence = false): array
    {
        $startTime = hrtime(true);

        $versions = $this->detectVersions();
        $currentVersion = $versions['node']['version'] ?? 'unknown';

        $validation = $this->validateVersion($targetVersion, $versions);

        $readiness = null;
        $compileDiff = null;

        if ($collectEvidence) {
            $readiness = $this->collectReadiness();
            $compileDiff = $this->collectCompileDiff();
        }

        $evidenceMeta = $this->buildEvidenceMeta($readiness, $compileDiff, $collectEvidence);

        $packDir = $this->generatePack(
            $targetVersion,
            $currentVersion,
            $versions,
            $readiness,
            $compileDiff,
            $evidenceMeta,
        );

        $status = $this->computeStatus($readiness, $compileDiff, $collectEvidence);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [
            'version' => self::VERSION,
            'status' => $status,
            'target_version' => $targetVersion,
            'current_version' => $currentVersion,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $durationMs,
            'readiness_overall' => $readiness['overall'] ?? null,
            'compile_diff_status' => $this->extractCompileDiffStatus($compileDiff),
            'evidence_collected' => $collectEvidence,
            'evidence' => $evidenceMeta,
            'validation' => $validation,
            'pack_dir' => $packDir,
            'versions' => $versions,
        ];
    }

    /**
     * Apply version bumps to composer.json files and generate apply plan.
     *
     * Requires readiness to be collected and not FAIL.
     *
     * @return array<string, mixed>
     */
    public function apply(string $targetVersion, bool $collectEvidence = true): array
    {
        $startTime = hrtime(true);

        $versions = $this->detectVersions();
        $currentVersion = $versions['node']['version'] ?? 'unknown';

        $validation = $this->validateVersion($targetVersion, $versions);

        if (! $validation['valid']) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return [
                'version' => self::VERSION,
                'status' => 'validation_failed',
                'target_version' => $targetVersion,
                'current_version' => $currentVersion,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'duration_ms' => $durationMs,
                'readiness_overall' => null,
                'compile_diff_status' => null,
                'evidence_collected' => false,
                'evidence' => $this->buildEvidenceMeta(null, null, false),
                'validation' => $validation,
                'applied' => false,
                'apply_plan' => null,
                'pack_dir' => null,
                'versions' => $versions,
            ];
        }

        $readiness = null;
        $compileDiff = null;

        if ($collectEvidence) {
            $readiness = $this->collectReadiness();
            $compileDiff = $this->collectCompileDiff();
        }

        // Block apply if readiness is FAIL
        if ($readiness !== null && ($readiness['overall'] ?? null) === 'FAIL') {
            $evidenceMeta = $this->buildEvidenceMeta($readiness, $compileDiff, $collectEvidence);

            $packDir = $this->generatePack(
                $targetVersion,
                $currentVersion,
                $versions,
                $readiness,
                $compileDiff,
                $evidenceMeta,
            );

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return [
                'version' => self::VERSION,
                'status' => 'blocked',
                'target_version' => $targetVersion,
                'current_version' => $currentVersion,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'duration_ms' => $durationMs,
                'readiness_overall' => $readiness['overall'] ?? null,
                'compile_diff_status' => $this->extractCompileDiffStatus($compileDiff),
                'evidence_collected' => true,
                'evidence' => $evidenceMeta,
                'validation' => $validation,
                'applied' => false,
                'apply_plan' => null,
                'pack_dir' => $packDir,
                'versions' => $versions,
            ];
        }

        $evidenceMeta = $this->buildEvidenceMeta($readiness, $compileDiff, $collectEvidence);

        // Apply version bumps
        $applyPlan = $this->applyVersionBumps($targetVersion, $currentVersion);

        // Re-detect versions after apply
        $versionsAfter = $this->detectVersions();

        $packDir = $this->generatePack(
            $targetVersion,
            $currentVersion,
            $versionsAfter,
            $readiness,
            $compileDiff,
            $evidenceMeta,
        );

        // Write apply-plan.json to pack
        $this->writeApplyPlan($packDir, $applyPlan, $targetVersion, $currentVersion);

        // Overwrite next-steps.md with post-apply instructions
        $this->writeAppliedNextSteps($packDir, $targetVersion);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [
            'version' => self::VERSION,
            'status' => 'applied',
            'target_version' => $targetVersion,
            'current_version' => $currentVersion,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $durationMs,
            'readiness_overall' => $readiness['overall'] ?? null,
            'compile_diff_status' => $this->extractCompileDiffStatus($compileDiff),
            'evidence_collected' => $collectEvidence,
            'evidence' => $evidenceMeta,
            'validation' => $validation,
            'applied' => true,
            'apply_plan' => $applyPlan,
            'pack_dir' => $packDir,
            'versions' => $versionsAfter,
        ];
    }

    /**
     * Detect versions from all 3 repo composer.json files and git tags.
     *
     * @return array<string, array{path: string, version: string|null, tag: string|null}>
     */
    protected function detectVersions(): array
    {
        $versions = [];

        foreach (self::REPOS as $name => $repo) {
            $composerPath = $this->projectRoot . '/' . $repo['composer'];
            $repoDir = $this->projectRoot . '/' . $repo['dir'];

            $version = null;

            if (file_exists($composerPath)) {
                $json = json_decode((string) file_get_contents($composerPath), true);

                if (is_array($json) && isset($json['version'])) {
                    $version = (string) $json['version'];
                }
            }

            $tag = null;
            [$exitCode, $stdout] = $this->exec(
                'git describe --tags --abbrev=0 2>/dev/null',
                $repoDir,
            );

            if ($exitCode === 0 && trim($stdout) !== '') {
                $tag = trim($stdout);
            }

            $versions[$name] = [
                'path' => $repo['composer'],
                'version' => $version,
                'tag' => $tag,
            ];
        }

        return $versions;
    }

    /**
     * Validate target version is valid semver and not a downgrade.
     *
     * @param  array<string, array{path: string, version: string|null, tag: string|null}>  $versions
     * @return array{valid: bool, reason: string|null}
     */
    protected function validateVersion(string $targetVersion, array $versions): array
    {
        $targetParsed = $this->parseSemver($targetVersion);

        if ($targetParsed === null) {
            return ['valid' => false, 'reason' => "Invalid semver: {$targetVersion}"];
        }

        $currentVersion = $versions['node']['version'] ?? null;

        if ($currentVersion === null) {
            return ['valid' => true, 'reason' => null];
        }

        $currentParsed = $this->parseSemver($currentVersion);

        if ($currentParsed === null) {
            return ['valid' => true, 'reason' => null];
        }

        if ($this->compareSemver($targetParsed, $currentParsed) <= 0) {
            return [
                'valid' => false,
                'reason' => "Target {$targetVersion} is not greater than current {$currentVersion}",
            ];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Collect readiness check output via subprocess.
     *
     * @return array<string, mixed>|null
     */
    protected function collectReadiness(): ?array
    {
        [$exitCode, $stdout] = $this->exec(
            'php cli/bin/brain readiness:check --skip-memory --json 2>&1',
            $this->projectRoot,
        );

        $json = json_decode($stdout, true);

        if (is_array($json)) {
            return $json;
        }

        return null;
    }

    /**
     * Collect compile diff output via subprocess.
     *
     * @return array<string, mixed>|null
     */
    protected function collectCompileDiff(): ?array
    {
        [$exitCode, $stdout] = $this->exec(
            'php cli/bin/brain compile --diff --json 2>&1',
            $this->projectRoot,
        );

        $json = json_decode($stdout, true);

        if (is_array($json)) {
            return $json;
        }

        return null;
    }

    /**
     * Generate the release pack directory with all artifacts.
     *
     * @param  array<string, array{path: string, version: string|null, tag: string|null}>  $versions
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>|null  $compileDiff
     * @param  array{readiness: array{status: string, reason: string|null}, compile_diff: array{status: string, reason: string|null}}|null  $evidenceMeta
     */
    protected function generatePack(
        string $targetVersion,
        string $currentVersion,
        array $versions,
        ?array $readiness,
        ?array $compileDiff,
        ?array $evidenceMeta = null,
    ): string {
        $packDir = $this->projectRoot . '/.work/releases/' . $targetVersion;

        if (! is_dir($packDir)) {
            mkdir($packDir, 0755, true);
        }

        $status = $this->computeStatus($readiness, $compileDiff, $readiness !== null);

        $manifest = [
            'version' => self::VERSION,
            'target' => $targetVersion,
            'current' => $currentVersion,
            'status' => $status,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($evidenceMeta !== null) {
            $manifest['evidence'] = $evidenceMeta;
        }

        // manifest.json
        file_put_contents(
            $packDir . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // versions.json
        file_put_contents(
            $packDir . '/versions.json',
            (string) json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // readiness.json
        file_put_contents(
            $packDir . '/readiness.json',
            (string) json_encode($readiness, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // compile-diff.json
        file_put_contents(
            $packDir . '/compile-diff.json',
            (string) json_encode($compileDiff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // next-steps.md
        file_put_contents(
            $packDir . '/next-steps.md',
            $this->generateNextSteps($targetVersion, $currentVersion),
        );

        return '.work/releases/' . $targetVersion;
    }

    /**
     * Compute overall release status.
     *
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>|null  $compileDiff
     */
    protected function computeStatus(?array $readiness, ?array $compileDiff, bool $evidenceCollected): string
    {
        if (! $evidenceCollected) {
            return 'evidence_only';
        }

        if ($readiness !== null && ($readiness['overall'] ?? null) === 'FAIL') {
            return 'blocked';
        }

        return 'ready';
    }

    /**
     * Build structured evidence metadata describing presence/absence of each evidence source.
     *
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>|null  $compileDiff
     * @return array{readiness: array{status: string, reason: string|null}, compile_diff: array{status: string, reason: string|null}}
     */
    protected function buildEvidenceMeta(
        ?array $readiness,
        ?array $compileDiff,
        bool $requested,
    ): array {
        return [
            'readiness' => $this->buildSingleEvidenceMeta($readiness, $requested, 'readiness:check'),
            'compile_diff' => $this->buildSingleEvidenceMeta($compileDiff, $requested, 'compile --diff'),
        ];
    }

    /**
     * Build evidence status for a single source.
     *
     * @param  array<string, mixed>|null  $data
     * @return array{status: string, reason: string|null}
     */
    private function buildSingleEvidenceMeta(?array $data, bool $requested, string $commandName): array
    {
        if (! $requested) {
            return [
                'status' => 'skipped',
                'reason' => 'Evidence collection not requested (use --evidence or --apply)',
            ];
        }

        if ($data === null) {
            return [
                'status' => 'missing',
                'reason' => "Command '{$commandName}' returned no parseable JSON output",
            ];
        }

        return [
            'status' => 'present',
            'reason' => null,
        ];
    }

    /**
     * Apply version bumps to all 3 composer.json files.
     *
     * @return array<int, array{file: string, field: string, before: string|null, after: string}>
     */
    protected function applyVersionBumps(string $targetVersion, string $currentVersion): array
    {
        $changes = [];

        foreach (self::REPOS as $name => $repo) {
            $composerPath = $this->projectRoot . '/' . $repo['composer'];

            if (! file_exists($composerPath)) {
                continue;
            }

            $content = (string) file_get_contents($composerPath);
            $json = json_decode($content, true);

            if (! is_array($json)) {
                continue;
            }

            $oldVersion = $json['version'] ?? null;

            if ($oldVersion !== null) {
                $json['version'] = $targetVersion;
                $changes[] = [
                    'file' => $repo['composer'],
                    'field' => 'version',
                    'before' => $oldVersion,
                    'after' => $targetVersion,
                ];
            }

            // Update cli's core constraint
            if ($name === 'cli' && isset($json['require']['jarvis-brain/core'])) {
                $oldConstraint = $json['require']['jarvis-brain/core'];
                $newConstraint = '^' . $targetVersion;
                $json['require']['jarvis-brain/core'] = $newConstraint;
                $changes[] = [
                    'file' => $repo['composer'],
                    'field' => 'require.jarvis-brain/core',
                    'before' => $oldConstraint,
                    'after' => $newConstraint,
                ];
            }

            file_put_contents(
                $composerPath,
                (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            );
        }

        return $changes;
    }

    /**
     * Write apply-plan.json to pack directory.
     *
     * @param  array<int, array{file: string, field: string, before: string|null, after: string}>  $changes
     */
    protected function writeApplyPlan(
        string $packDir,
        array $changes,
        string $targetVersion,
        string $currentVersion,
    ): void {
        $absPackDir = $this->projectRoot . '/' . $packDir;

        [, $branchOutput] = $this->exec('git branch --show-current', $this->projectRoot);
        $branch = trim($branchOutput) ?: 'master';

        $plan = [
            'version' => self::VERSION,
            'target' => $targetVersion,
            'current' => $currentVersion,
            'changes' => $changes,
            'git_commands' => [
                "git add composer.json core/composer.json cli/composer.json",
                "git commit -m \"chore(release): bump version to {$targetVersion}\"",
                "git tag -a {$targetVersion} -m \"{$targetVersion}\"",
                "git push origin {$branch} && git push origin {$targetVersion}",
            ],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        file_put_contents(
            $absPackDir . '/apply-plan.json',
            (string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Write post-apply next-steps.md to pack directory.
     */
    protected function writeAppliedNextSteps(string $packDir, string $targetVersion): void
    {
        $absPackDir = $this->projectRoot . '/' . $packDir;

        [, $branchOutput] = $this->exec('git branch --show-current', $this->projectRoot);
        $branch = trim($branchOutput) ?: 'master';

        $content = <<<MD
        # Release {$targetVersion} — Next Steps (post-apply)

        Version bumps have been applied to composer.json files.
        Review the changes, then run the commands below.

        ## 1. Verify changes

        ```bash
        git diff composer.json core/composer.json cli/composer.json
        ```

        ## 2. Run quality gates

        ```bash
        cd core && composer analyse && composer test && cd ..
        cd cli && composer analyse && composer test && cd ..
        ```

        ## 3. Compile and verify

        ```bash
        brain compile
        brain readiness:check --human
        ```

        ## 4. Commit, tag, and push

        ```bash
        git add composer.json core/composer.json cli/composer.json
        git commit -m "chore(release): bump version to {$targetVersion}"
        git tag -a {$targetVersion} -m "{$targetVersion}"
        git push origin {$branch} && git push origin {$targetVersion}
        ```

        ## 5. Push core subtree

        ```bash
        git subtree push --prefix=core origin core-{$targetVersion}
        ```
        MD;

        file_put_contents($absPackDir . '/next-steps.md', $content);
    }

    /**
     * Generate next-steps markdown content.
     */
    protected function generateNextSteps(string $targetVersion, string $currentVersion): string
    {
        return <<<MD
        # Release {$targetVersion} — Next Steps

        Current version: {$currentVersion}
        Target version: {$targetVersion}

        ## 1. Update versions in all repos

        ```bash
        # node/composer.json
        sed -i '' 's/"version": "{$currentVersion}"/"version": "{$targetVersion}"/' composer.json

        # core/composer.json
        sed -i '' 's/"version": "{$currentVersion}"/"version": "{$targetVersion}"/' core/composer.json

        # cli/composer.json — version + core constraint
        sed -i '' 's/"version": "{$currentVersion}"/"version": "{$targetVersion}"/' cli/composer.json
        sed -i '' 's/"jarvis-brain\\/core": "\\^{$currentVersion}"/"jarvis-brain\\/core": "\\^{$targetVersion}"/' cli/composer.json
        ```

        ## 2. Run quality gates

        ```bash
        cd core && composer analyse && composer test && cd ..
        cd cli && composer analyse && composer test && cd ..
        ```

        ## 3. Compile and verify

        ```bash
        brain compile
        brain readiness:check --human
        ```

        ## 4. Commit and tag

        ```bash
        git add -A
        git commit -m "chore(release): bump version to {$targetVersion}"
        git tag {$targetVersion}
        git push origin master --tags
        ```

        ## 5. Push core subtree

        ```bash
        git subtree push --prefix=core origin core-{$targetVersion}
        ```
        MD;
    }

    /**
     * Parse a semver string into components.
     *
     * @return array{major: int, minor: int, patch: int}|null
     */
    protected function parseSemver(string $version): ?array
    {
        $version = ltrim($version, 'v');

        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
            return null;
        }

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
        ];
    }

    /**
     * Compare two parsed semver arrays.
     *
     * @param  array{major: int, minor: int, patch: int}  $a
     * @param  array{major: int, minor: int, patch: int}  $b
     * @return int Negative if a < b, zero if equal, positive if a > b
     */
    protected function compareSemver(array $a, array $b): int
    {
        if ($a['major'] !== $b['major']) {
            return $a['major'] <=> $b['major'];
        }

        if ($a['minor'] !== $b['minor']) {
            return $a['minor'] <=> $b['minor'];
        }

        return $a['patch'] <=> $b['patch'];
    }

    /**
     * Extract compile diff status string.
     *
     * @param  array<string, mixed>|null  $compileDiff
     */
    private function extractCompileDiffStatus(?array $compileDiff): ?string
    {
        if ($compileDiff === null) {
            return null;
        }

        $hasDiff = $compileDiff['has_diff'] ?? $compileDiff['diff'] ?? null;

        if ($hasDiff === false || $hasDiff === null) {
            return 'no_diff';
        }

        return 'has_diff';
    }

    /**
     * Execute a shell command and return [exit_code, stdout, stderr, duration_ms].
     *
     * @return array{0: int, 1: string, 2: string, 3: int}
     */
    protected function exec(string $command, ?string $cwd = null): array
    {
        $startTime = hrtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (! is_resource($process)) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return [1, '', 'Failed to start process', $durationMs];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [$exitCode, $stdout, $stderr, $durationMs];
    }
}
