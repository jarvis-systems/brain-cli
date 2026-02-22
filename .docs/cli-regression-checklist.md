---
name: "CLI Regression Checklist"
description: "Sanity-check commands, risk areas, and debug instructions after CLI refactoring"
type: "checklist"
date: "2026-02-22"
status: "active"
---

# CLI Regression Checklist

## Sanity-Check Commands

Run these after any internal CLI refactoring to verify no behavioral regression.

### Compile

```bash
brain compile claude           # Must: exit 0, produce .claude/CLAUDE.md
brain compile claude --dry-run # Must: exit 0, no file writes
BRAIN_CLI_DEBUG=1 brain compile claude  # Must: debug output on stderr with [brain-debug] prefix
```

### Docs

```bash
brain docs                     # Must: list docs index, exit 0
brain docs --validate          # Must: validate front matter, exit 0 on clean
brain docs keyword             # Must: return JSON results, exit 0
brain docs --download=<url> --as=filename.md  # Must: save to .docs/, exit 0
```

### Bridge Commands

```bash
brain <any-bridge-command>     # Must: execute through CommandKernel, exit 0 on success
```

### Quality Gates

```bash
cd cli && composer test        # Must: 199+ tests, 0 failures
cd cli && composer analyse     # Must: 0 errors (PHPStan)
```

## Known Risk Areas

### 1. Dynamic AI Commands (RunCommand, CustomRunCommand)

- **Risk:** These still use `exit()` directly (intentional — AI lifecycle).
- **Impact:** Not covered by CommandKernel. CTE migration excluded.
- **Monitor:** If behavior changes in AI command error handling, check `exit()` calls in `RunCommand.php` and `CustomRunCommand.php`.

### 2. Board State / Lab Screen

- **Risk:** `Lab\Screen` debug logging migrated to `Brain::debugException()`.
- **Impact:** Debug output format changed from ad-hoc `error_log()` to `[brain-debug] ClassName: message`.
- **Monitor:** If Lab screen throws silently, enable `BRAIN_CLI_DEBUG=1` and check stderr.

### 3. Compile Lock (Single-Writer)

- **Risk:** `CompileCommand` exit→CTE migration.
- **Impact:** Lock conflict now throws `CommandTerminatedException` instead of `exit(ERROR)`. Behavior unchanged (still exits with code 1).
- **Monitor:** Run two concurrent `brain compile` — second must fail with lock conflict message.

### 4. DocsCommand Guards

- **Risk:** 6 exit→CTE migrations in DocsCommand (URL validation, filename checks, etc.).
- **Impact:** Same exit codes and error messages. CTE caught by CommandKernel.
- **Monitor:** `brain docs --download=invalid-url` — must show error and exit 1.

### 5. ProcessFactory Signal Handlers

- **Risk:** NOT migrated. Uses `exit(128 + $signal)` — POSIX convention.
- **Impact:** None (intentionally excluded from CTE migration).
- **Monitor:** Only relevant if signal handling behavior changes.

## Debug Instructions

### General Debug Mode

```bash
BRAIN_CLI_DEBUG=1 brain <command>
```

Enables `Core::debugException()` output. Format: `[brain-debug] ExceptionClass: message`.

For context-specific prefixes: `[brain-debug:compile]`, `[brain-debug:docs]`, `[brain-debug:bridge]`.

### Debug Env Variables

| Variable | Purpose |
|----------|---------|
| `BRAIN_CLI_DEBUG=1` | Enable debug exception logging to stderr |
| `DEBUG=1` | Alternative debug flag (fallback) |

### Reading Debug Output

Debug output goes to `stderr` via `error_log()`. In terminal:

```bash
BRAIN_CLI_DEBUG=1 brain compile claude 2>/tmp/brain-debug.log
cat /tmp/brain-debug.log
```

### Test-Level Debugging

```bash
composer test -- --filter=CompileCommandGolden  # Run specific golden test
composer test -- --filter=CommandKernel          # Run kernel tests only
composer test -- --filter=NoProcessTermination   # Run exit() guard
```

## Automated Guards

| Guard | File | What It Catches |
|-------|------|-----------------|
| No exit() regression | `NoProcessTerminationCallsTest` | New `exit()` calls in core commands |
| CTE adoption | `CommandKernelAdoptionTest` | CommandKernel not used in bridge/docs |
| Debug migration parity | `DebugMigrationParityTest` | Inline debug blocks returning |
| Golden exit codes | `*GoldenTest` | Exit code regressions |
