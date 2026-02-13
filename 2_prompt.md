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