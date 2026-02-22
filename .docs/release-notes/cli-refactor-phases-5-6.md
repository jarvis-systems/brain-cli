---
name: "CLI Refactor — Phases 5/6A/6B"
description: "Debug dedup, CommandKernel exception boundary, exit→CTE migration, golden parity tests"
type: "release-notes"
version: "v0.0.2"
date: "2026-02-22"
status: "complete"
---

# CLI Refactor — Phases 5 / 6A / 6B

## What Changed (Internal)

### Phase 5: Debug Exception Deduplication

- Added `Core::debugException(\Throwable $e, string $prefix)` — centralized debug logging.
- Replaced 9 inline `if (Brain::isDebug()) { error_log(...) }` blocks with `Brain::debugException($e)`.
- Migrated files: `CommandBridgeAbstract` (5 sites), `DocsCommand` (1), `Ai\HelpersTrait` (1), `CustomRunCommand` (1), `Lab\Screen` (1).
- Intentionally NOT migrated: `ServiceProvider.php` (bootstrap — facade unavailable), `ClaudeClient.php` (conditional env, not error_log), `HelpersTrait:61` (info output, not error_log).

### Phase 6A: CommandKernel + exit→CTE Migration

- Created `CommandTerminatedException` (CTE) — replaces `exit(ERROR)` for testability and cleanup.
- Created `CommandKernel::run()` — unified exception boundary: CTE→exitCode, Throwable→1+debug+onError.
- Migrated 11 `exit(ERROR)` calls to `throw new CommandTerminatedException()`:
  - `CompileCommand` (2), `DocsCommand` (6), `CommandBridgeAbstract` (1), `HelpersTrait` (2).
- Adopted `CommandKernel` in `CommandBridgeAbstract::handle()` and `DocsCommand::handle()`.
- NOT migrated (intentional): `ProcessFactory` (POSIX signal handlers), `RunCommand`/`CustomRunCommand`/`CommandLinePrompt` (AI lifecycle).

### Phase 6B: Golden Parity Tests

- Created `CliOutputCapture` trait — stdout/stderr capture, output normalization, temp dir helpers.
- Created 3 golden test classes:
  - `BridgeCommandGoldenTest` (4 tests) — CommandKernel pass-through, error callback, CTE routing.
  - `CompileCommandGoldenTest` (3 tests) — lock conflict exit code, error message format, debug silence.
  - `DocsCommandGoldenTest` (5 tests) — validation guards, CTE usage, kernel adoption.
- Created exit() guard: `NoProcessTerminationCallsTest` — scans `cli/src/` for new `exit()` calls with allowlist.

## What Did NOT Change

- **CLI UX:** All commands behave identically. Same exit codes, same output, same flags.
- **AI commands:** `RunCommand`, `CustomRunCommand`, `Lab/` — untouched exit() lifecycle.
- **Signal handlers:** `ProcessFactory` POSIX signal handlers remain as-is.
- **External API:** No public interface changes.

## New Classes

| Class | Namespace | Purpose |
|-------|-----------|---------|
| `CommandTerminatedException` | `BrainCLI\Exceptions` | Graceful exit with testable exit code |
| `CommandKernel` | `BrainCLI\Console\Kernel` | Unified exception→exit-code boundary |
| `CliOutputCapture` | `BrainCLI\Tests\Support` | Test trait for output capture |

## Env Flags

No new env flags introduced. Existing `BRAIN_CLI_DEBUG=1` now routes through `Core::debugException()` with `[brain-debug]` prefix format: `[brain-debug] ClassName: message`.

## Migration Notes

None. This is a fully internal refactor. No breaking changes to CLI commands, config, or external behavior.

## Test Coverage

- **Before:** 175 tests
- **After:** 199 tests, 412 assertions
- **New test files:** 9
- **Quality gates:** PHPUnit green, PHPStan 0 errors
