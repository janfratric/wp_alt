# Learnings from "Building a C Compiler with Parallel Claude Agents" for LiteCMS

## Context

Nicholas Carlini (Anthropic) used 16 parallel Claude agents over 2 weeks to build a 100K-line Rust C compiler that compiles Linux 6.9. The project cost ~$20K across ~2,000 Claude sessions and 2 billion tokens. This document extracts actionable learnings for the LiteCMS project — a chunk-based, agent-built PHP CMS.

---

## 1. Automated Verification > Manual Acceptance Tests

**Article insight**: "Most of my effort went into designing the environment around Claude — the tests, the environment, the feedback." The test harness must be nearly perfect because agents will autonomously solve whatever they're given, including working around broken tests.

**Current LiteCMS state**: Each chunk has manual acceptance tests (e.g., "visit `/about`, verify it renders"). These require human presence and are not repeatable.

**Action**: Create a `tests/` directory with automated PHP test scripts for each chunk. After each chunk implementation, the agent should run these tests itself before declaring completion. Example:

```
tests/
  chunk-1.1-verify.php   # Boots app, tests routing, 404, config, middleware
  chunk-1.2-verify.php   # Tests DB connection, migration idempotency, CRUD
  chunk-1.3-verify.php   # Tests login, session, CSRF, rate limiting
  ...
```

Each script returns exit code 0 on success, non-zero with specific error messages on failure. This turns every acceptance test into an automatable gate.

---

## 2. Regression Prevention via Cumulative Test Suite

**Article insight**: Agents frequently break existing functionality when adding features. Carlini implemented continuous integration that ran after every change to catch regressions immediately.

**Current LiteCMS state**: The verification strategy mentions "verify no regressions in previously implemented chunks" but this is manual and vague.

**Action**: Build a cumulative `tests/run-all.php` that executes all verification scripts for completed chunks. Every new chunk implementation must pass ALL previous chunk tests, not just its own. The agent's prompt for each chunk should include: "Before declaring this chunk complete, run `php tests/run-all.php` and ensure all tests pass."

---

## 3. Chunk Parallelization Opportunities

**Article insight**: Early stages worked well with independent test failures — each agent tackled different tests. But monolithic tasks caused all agents to hit the same bugs and overwrite each other's work.

**Current LiteCMS state**: The dependency graph already identifies some parallelism (2.2/2.3/2.4 share the same prerequisite of 2.1). But the plan treats implementation as strictly sequential.

**Action**: After Phase 1 (Foundation), these chunks can genuinely run in parallel with separate agents:

| Parallel Group | Chunks | Why independent |
|---|---|---|
| After 2.1 | 2.2 (Content CRUD) + 2.4 (User Mgmt) | Different controllers, templates, DB tables. No shared files. |
| After 2.2 | 2.3 (Media) + 3.1 (Front Controller) | Media extends content editor; Front Controller reads content DB. No file overlap. |
| After Phase 2 | 4.1 (AI Backend) + 3.2 (Public Templates) | AI is admin-only backend; templates are public-only frontend. |

To coordinate: each parallel agent works in a git branch and merges back. Conflicts are unlikely due to file-level isolation.

---

## 4. Context Window Optimization for Chunk Plans

**Article insight**: Context pollution is a real problem. The harness minimized output and used standardized formats. Agents waste time on irrelevant information.

**Current LiteCMS state**: CHUNK-1.1-PLAN.md is 925 lines with full code templates. This is thorough but may overwhelm context, leaving less room for the agent's own problem-solving.

**Action**:
- Keep detailed chunk plans as reference documents, but give the agent a **condensed prompt** that includes: (a) which files to create, (b) the public API of each class (signatures only), (c) key constraints, (d) the acceptance test script to run.
- Move full code templates to an appendix section the agent can reference if stuck, rather than front-loading everything.
- Use standardized error output in test scripts (e.g., `[PASS] Router dispatches GET /` or `[FAIL] Expected 404, got 200`) so the agent can quickly parse what's broken.

---

## 5. Agent Specialization Roles

**Article insight**: Beyond core compilation work, Carlini assigned specialized roles: code deduplication agent, performance optimizer, design critique agent, documentation agent.

**Action**: After core implementation is done (all 13 chunks), run specialized review passes:

| Specialist Agent | Task |
|---|---|
| **Security Auditor** | Review all controllers for missing CSRF, unescaped output, SQL injection vectors. Check file upload handling. Verify CSP headers. |
| **Performance Reviewer** | Profile page render times, identify N+1 queries, verify < 50ms target. Check query builder generates efficient SQL. |
| **Code Deduplicator** | Find repeated patterns across controllers/templates. Extract shared helpers. Ensure DRY without over-abstraction. |
| **Accessibility Reviewer** | Check all templates for semantic HTML, heading hierarchy, alt text, form labels, ARIA attributes. |
| **LOC Auditor** | Verify the < 5,000 PHP LOC and < 10 Composer packages constraints are met. Flag bloat. |

These can run in parallel since they're read-only review tasks.

---

## 6. Oracle-Based Validation

**Article insight**: Using GCC as an "oracle" — compiling the same code with a known-good compiler and comparing output — enabled parallel debugging by giving agents different files to fix.

**Action for LiteCMS**: Use a real WordPress installation as a behavioral oracle:
- Create the same content (pages, posts) in both WordPress and LiteCMS
- Compare public HTML output for structural correctness (not pixel-perfect, but SEO-equivalent: correct meta tags, heading hierarchy, navigation)
- Compare admin workflows: can the same CRUD operations be performed?
- This is most valuable for Chunks 3.1/3.2 (public templates) and 2.2 (content CRUD)

---

## 7. Fast Feedback Loops (--fast Flag Concept)

**Article insight**: Claude can't track elapsed time and will spend hours on unnecessary work. The solution was a `--fast` flag that runs a random sample (1-10%) of tests, allowing quick iteration.

**Action**: For the LiteCMS test suite, implement two modes:
- `php tests/run-all.php --quick` — Only tests the current chunk + a smoke test of previous chunks (e.g., "can the homepage still load?", "does login still work?")
- `php tests/run-all.php --full` — Runs every test for every completed chunk

The agent should use `--quick` during iterative development and `--full` before declaring a chunk complete.

---

## 8. Lock Files for Task Claiming (Multi-Agent Coordination)

**Article insight**: Agents create lock files in `current_tasks/` to claim work, preventing duplicate effort.

**Action**: If you decide to parallelize chunks (see #3), create a `current_tasks/` directory:
```
current_tasks/
  chunk-2.2.lock   # Contains: "agent-1, started 2024-01-15T10:00:00Z"
  chunk-2.4.lock   # Contains: "agent-2, started 2024-01-15T10:00:00Z"
```

Each agent checks for existing locks before starting work. This is lightweight coordination that prevents wasted effort.

---

## 9. Autonomous Loop Design

**Article insight**: A bash loop continuously spawns Claude instances. Each session receives a prompt describing the current state, picks the next task, and completes it autonomously.

**Action**: Create a `build-loop.sh` script that:
1. Checks which chunks are completed (by running their test suites)
2. Identifies the next unblocked chunk from the dependency graph
3. Feeds the chunk plan + project context to a Claude agent
4. Runs the verification tests
5. If tests pass, commits and moves to the next chunk
6. If tests fail, re-spawns the agent with the failure output

This turns the 13-chunk build into a hands-off process. The human's role shifts from "implement each chunk" to "design the test suite and review results."

---

## 10. Documentation as Agent Onboarding

**Article insight**: Fresh Docker containers need clear status records so each new agent session can self-orient. Extensive documentation helps autonomous systems understand what's been done and what's next.

**Current LiteCMS state**: PLAN.md and PROMPT.md are excellent. But there's no machine-readable status tracker.

**Action**: Add a `STATUS.md` that is **auto-generated by `tests/run-all.php`** after every test run. It includes:
- Chunk checklist with status (complete / failing / ready / blocked)
- Next steps with preparation readiness (which files exist vs need creating)
- Preparation inventory table (plan, prompt, test script per chunk)
- Known issues (preserved across regenerations)

Each agent session starts by reading STATUS.md to understand context. The file stays accurate because it's regenerated from test results — not manually maintained.

---

## Summary: Priority-Ordered Actions

| Priority | Action | Effort | Impact |
|---|---|---|---|
| **P0** | Automated test scripts per chunk | Medium | Eliminates manual testing, enables autonomous loop |
| **P0** | Cumulative regression suite | Low | Prevents the #1 failure mode: breaking existing features |
| **P1** | STATUS.md as machine-readable tracker | Low | Each agent session self-orients instantly |
| **P1** | Condensed agent prompts (vs. full code templates) | Low | Better context window utilization |
| **P1** | Standardized test output format | Low | Agents can parse failures and fix them |
| **P2** | Chunk parallelization (2.2+2.4, 3.2+4.1) | Medium | ~30% faster overall build |
| **P2** | Specialized review agents post-build | Low | Security, performance, accessibility quality gates |
| **P2** | Autonomous build loop script | Medium | Hands-off multi-chunk implementation |
| **P3** | Oracle comparison with WordPress | High | Validates behavioral correctness of public site |
| **P3** | Fast/full test modes | Low | Faster iteration during development |
