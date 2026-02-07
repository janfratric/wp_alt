# Prompt Optimization Rationale

## Original Prompt
> I need to create a lightweight alternative to wordpress. A simple web editing tool, php based, working with DB (postgre ot mariaDB), admin interface for moderating content. A couple of templates for webpage. prepare a plan to create such

## What Changed and Why

| # | Issue in Original | Fix Applied | Technique Used |
|---|-------------------|-------------|----------------|
| 1 | No role/expertise framing | Added "senior PHP developer and software architect" role | Role Prompting (techniques.md #5) |
| 2 | No target audience defined | Added context about small business users and their pain points | Be Clear and Direct (techniques.md #1) |
| 3 | "Lightweight" is undefined | 7 measurable constraints: <5K LOC, <50ms render, <10 deps, no frameworks, no Node.js, single entry point, shared hosting compatible | Be Specific (techniques.md #1) |
| 4 | Flat, unstructured text | Organized into XML sections: task, context, tech_stack, modules, file_structure, etc. | XML Tags (techniques.md #4) |
| 5 | AI assistant feature missing entirely | Full AIAssistant module with Claude API client, chat UI, conversation persistence | User requirement (captured via clarification) |
| 6 | "postgre or mariaDB" — unresolved choice | Dual-mode: SQLite for dev/simple + PostgreSQL/MariaDB for production, switchable via config | User requirement (captured via clarification) |
| 7 | No file/directory structure | Complete file tree with every directory and file specified | Be Explicit (techniques.md #1) |
| 8 | "prepare a plan" — no output format | 5-phase implementation order, each phase requires complete source code + migrations + test checklist | Chain Prompts (techniques.md #7) |
| 9 | No security guidance | Non-negotiable security section: prepared statements, XSS escaping, CSRF, file upload validation, encrypted API keys | Guardrails (guardrails.md) |
| 10 | No constraints on approach | Explicit prohibitions: no ORM, no frameworks, no build tools, strict types required | Constraints pattern (techniques.md #1) |
| 11 | No database schema | Complete 7-table schema with columns, types, foreign keys, and constraints | Be Specific (techniques.md #1) |
| 12 | "A couple of templates" | 7 named templates: home, page, blog-index, blog-post, contact, archive, 404 | Be Specific (techniques.md #1) |

## Checklist Score (Before → After)

| Dimension | Before | After |
|-----------|--------|-------|
| Clarity | 1/5 — Vague, ambiguous task | 5/5 — Precise task, role, context |
| Structure | 1/5 — Single paragraph | 5/5 — XML-organized hierarchy |
| Examples | 1/5 — None | 3/5 — AI assistant usage examples included |
| Guardrails | 1/5 — None | 5/5 — Security requirements, constraints |
| Format spec | 1/5 — "prepare a plan" | 5/5 — Phased output with complete code |
