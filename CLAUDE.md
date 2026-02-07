# CLAUDE.md

## Project Overview
LiteCMS — lightweight WordPress alternative. See `PROMPT.md` for full spec.

## How to Work
Follow `BUILD-GUIDE.md` for the chunk-based workflow. Read `STATUS.md` to see current progress.

## Behavioral Guardrails

- Default to the simplest approach. Add complexity only when the simpler approach demonstrably fails.
- After the same error occurs twice, change your approach — do not retry the same thing.
- After 3 failed attempts at the same problem, stop. Write what you tried, what failed, and what you think the root cause is. Ask for guidance.
- If your plan changes significantly during implementation, pause and state what changed and why before continuing.
- Do not jump to code before understanding the problem. Read the chunk plan first.

## Anti-Patterns (do not do these)
- Making sweeping changes across many files at once — work in small, testable increments
- Ignoring test failures and moving on
- Retrying the same failed approach without changing something
- Batching unrelated changes into one commit
- Generating boilerplate explanations instead of acting

## Key Commands
- `composer install` — install autoloader
- `php tests/chunk-X.X-verify.php` — verify current chunk
- `php tests/run-all.php --quick` — smoke test during development
- `php tests/run-all.php --full` — full regression before commit

## Code Conventions
- Every `.php` file: `<?php declare(strict_types=1);`
- No framework imports — native PHP only
- PSR-4: `App\` → `app/`
- Templates use `$this->e()` for all output (XSS prevention)
