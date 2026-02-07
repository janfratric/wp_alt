# How to Build LiteCMS

This guide explains how to use the agent infrastructure in this repo to build the CMS from planning docs to working software.

---

## Quick Overview

The project is split into **13 chunks** across 5 phases (see `PLAN.md`). Before implementing each chunk, you need three things:

1. A **detailed plan** (`CHUNK-{N.N}-PLAN.md`) — full spec with code templates
2. A **condensed prompt** (`chunks/{N.N}-prompt.md`) — what the implementing agent reads
3. An **automated test script** (`tests/chunk-{N.N}-verify.php`) — pass/fail gate

> **`{N.N}` = the next chunk to implement.** To find it, open `STATUS.md` and pick the first chunk that is NOT checked `[x]` and is marked `ready`. For example, if 1.1 is `[x]` and 1.2 is `ready`, then `{N.N}` = `1.2`.

For every chunk, you prepare these three files first, then implement. The cycle is: **prepare → implement → verify → repeat**.

---

## Per-Chunk Workflow

### Step 1: Check what's next

Open `STATUS.md` or run:

```bash
./build-loop.sh --status
```

This shows which chunks are done `[x]`, ready `[~]`, or blocked `[ ]`. Pick the next ready chunk.

### Step 2: Prepare the chunk (if not already done)

Check if the three files exist for your chunk. If not, create them:

#### a) Detailed plan — `CHUNK-{N.N}-PLAN.md`

Ask an agent to write the detailed plan. Use this prompt:

```
Read PLAN.md and PROMPT.md for the full project specification.
Read STATUS.md for current project state — find the first chunk not marked [x] and
marked "ready". That is the chunk to plan (referred to as {N.N} below).
Read CHUNK-1.1-PLAN.md as an example of the format and level of detail expected.

Write a detailed implementation plan for the next ready chunk from STATUS.md
to the file CHUNK-{N.N}-PLAN.md. Include:
- File creation order with dependency reasoning
- Complete class specifications (properties, constructor, all methods with signatures)
- Full code templates for every file
- Acceptance test procedures
- Implementation notes and edge cases

The plan must account for code already implemented in previous chunks.
Do not duplicate or rewrite existing code — build on top of it.
```

#### b) Condensed prompt — `chunks/{N.N}-prompt.md`

Ask an agent to condense the detailed plan. Use this prompt:

```
Read STATUS.md to identify the next ready chunk ({N.N}).
Read CHUNK-{N.N}-PLAN.md (the detailed plan for that chunk).
Read chunks/1.1-prompt.md as an example of the condensed format.

Create chunks/{N.N}-prompt.md — a condensed agent prompt containing:
- Goal (2-3 sentences)
- Context files to read
- File table (what to create/modify, in order)
- Class signatures (method names + types only, no implementation)
- Key constraints (5-10 bullet points)
- Verification commands (which test scripts to run)
- Pointer back to the detailed plan for reference

Target ~150 lines. Strip code templates — keep only signatures and constraints.
```

#### c) Test script — `tests/chunk-{N.N}-verify.php`

Ask an agent to write the automated tests. Use this prompt:

```
Read STATUS.md to identify the next ready chunk ({N.N}).
Read CHUNK-{N.N}-PLAN.md, specifically the acceptance test section.
Read tests/chunk-1.1-verify.php as an example of the format and conventions.

Write tests/chunk-{N.N}-verify.php — an automated test script that verifies
all acceptance criteria for the chunk. Follow these conventions:
- Output [PASS], [FAIL], or [SKIP] per test (standardized format)
- Exit code 0 if all pass, 1 if any fail
- Support smoke mode via LITECMS_TEST_SMOKE=1 env var (run only 2-3 core tests)
- Test by instantiating classes and calling methods directly (not HTTP requests)
- Include the autoloader: require_once $rootDir . '/vendor/autoload.php'
```

### Step 3: Implement the chunk

Once all three files exist, give the condensed prompt to an implementing agent:

```
Read STATUS.md to identify the next ready chunk ({N.N}).
Read chunks/{N.N}-prompt.md and implement everything it specifies.
After implementation, run: php tests/chunk-{N.N}-verify.php
Fix any [FAIL] results. Then run: php tests/run-all.php --full
```

### Step 4: Verify and commit

Once all tests pass:

```bash
php tests/run-all.php --full
git add -A && git commit -m "feat: chunk {N.N} — Description"
```

Go back to step 1. `STATUS.md` is updated automatically by the test runner — no manual editing needed.

---

## Automating with the Build Loop

The build loop handles steps 1, 3, and 4 automatically — but it requires the chunk files from step 2 to already exist.

```bash
./build-loop.sh --status     # What's next?
./build-loop.sh --run        # Implement the next ready chunk
./build-loop.sh --parallel   # Implement all ready chunks at once
```

The loop will refuse to run a chunk if its `chunks/{N.N}-prompt.md` doesn't exist. Prepare the chunk first.

---

## Parallel Build

After reaching a parallel step (see `PLAN.md` dependency graph), prepare and run multiple chunks at once:

```bash
./build-loop.sh --parallel
```

Each agent works on a git branch. Lock files in `current_tasks/` prevent collisions.

**Parallel groups:**
- After chunk 2.1 done: prepare + run 2.2 and 2.4 together
- After chunk 2.2 done: prepare + run 2.3 and 3.1 together
- After chunk 3.1 done: prepare + run 3.2 and 4.1 together

---

## After All 13 Chunks Are Done

Run the five specialist review agents (these are already prepared in `review/`):

```
Review LiteCMS using the checklist in review/security-audit.md. Produce the report.
```

| Review | Prompt File |
|--------|-------------|
| Security | `review/security-audit.md` |
| Performance | `review/performance-review.md` |
| Code Duplication | `review/code-dedup.md` |
| Accessibility | `review/accessibility-review.md` |
| Size & Constraints | `review/loc-audit.md` |

These can run in parallel (read-only). Fix any Critical/High findings, then the CMS is production-ready.

---

## Key Files Reference

| File | What it is |
|------|-----------|
| `PROMPT.md` | Full project specification (tech stack, schemas, constraints) |
| `PLAN.md` | Master plan — all 13 chunks, dependency graph, parallelization strategy |
| `STATUS.md` | Current build progress — auto-generated by test runner |
| `LEARNINGS.md` | Design rationale for this build infrastructure |
| `BUILD-GUIDE.md` | This file |
| `chunks/{N.N}-prompt.md` | Condensed agent prompt per chunk (you create these) |
| `CHUNK-{N.N}-PLAN.md` | Detailed plan per chunk (you create these) |
| `tests/run-all.php` | Cumulative test runner (`--quick` or `--full`) |
| `tests/chunk-{N.N}-verify.php` | Automated tests per chunk (you create these) |
| `review/*.md` | Post-build specialist review prompts (ready to use) |
| `build-loop.sh` | Autonomous build orchestrator |

---

## Tips

- **Always run `php tests/run-all.php --full` before committing.** Catches regressions.
- **Use `--quick` during iteration.** Smoke-tests previous chunks, fully tests the current one.
- **If implementation fails repeatedly**, point the agent at the detailed plan (`CHUNK-{N.N}-PLAN.md`) — it has full code templates.
- **Don't skip chunks.** The dependency graph exists for a reason.
- **Preparation is the real work.** A well-written detailed plan and test script means the implementing agent mostly just executes. Invest time in step 2.
