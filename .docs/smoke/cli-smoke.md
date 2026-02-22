---
name: "CLI Smoke Runbook"
description: "6-step manual smoke test for CLI after refactoring or release"
type: "runbook"
date: "2026-02-22"
status: "active"
---

# CLI Smoke Runbook

Manual 6-step verification. Run from the `cli/` directory.
Each step: command, expected exit code, expected output fragment.

## Step 1: Diagnose

```bash
bin/brain diagnose --human
```

- **Exit code:** `0`
- **Expected output:** Contains `Self-dev mode:` and `Version:` lines
- **Purpose:** Verifies CLI bootstrap, env detection, JSON/human output

## Step 2: Compile (dry-run)

```bash
bin/brain compile claude --json
```

- **Exit code:** `0`
- **Expected output:** JSON with compilation result (agent, paths, timing)
- **Purpose:** Verifies compile pipeline, lock acquisition, config resolution
- **Note:** This runs a real compile. Use from the project root (jarvis-brain-node), not cli/

## Step 3: Docs search

```bash
bin/brain docs install --limit=1
```

- **Exit code:** `0`
- **Expected output:** JSON array with at least 1 result (path, name, score)
- **Purpose:** Verifies keyword search, content scoring, JSON output
- **Note:** Uses positional keyword argument, not `--search`. Requires project root context (auto-switches via workdir detection).

## Step 4: Docs validate

```bash
bin/brain docs --validate
```

- **Exit code:** `0`
- **Expected output:** Validation summary with `total`, `valid`, `invalid` counts
- **Purpose:** Verifies YAML front matter parser, file scanner, validation pipeline

## Step 5: Version check

```bash
bin/brain --version
```

- **Exit code:** `0`
- **Expected output:** `Brain CLI v0.0.2` (or current version)
- **Purpose:** Verifies version metadata is consistent

## Step 6: Debug mode

```bash
BRAIN_CLI_DEBUG=1 bin/brain compile claude 2>/tmp/brain-smoke-debug.log; echo "exit: $?"
```

- **Exit code:** `0`
- **Expected stderr:** If any exceptions occur during compile, lines matching `[brain-debug:compile]`
- **Expected stdout:** Normal compile output
- **Purpose:** Verifies `Core::debugException()` pipeline, context-prefixed format
- **Cleanup:** `rm -f /tmp/brain-smoke-debug.log`

## Quick one-liner (all 6)

```bash
bin/brain diagnose --human && \
bin/brain --version && \
bin/brain docs --validate && \
bin/brain docs install --limit=1 && \
echo "--- Smoke: 4/6 PASSED (compile/debug require project context) ---"
```

## After smoke

If all 6 pass:
- Quality gates confirmed: `composer test` + `composer analyse`
- Safe to commit / tag / release

If any fail:
- Check `BRAIN_CLI_DEBUG=1` output
- See `.docs/cli-regression-checklist.md` for known risk areas
