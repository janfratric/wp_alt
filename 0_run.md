Read STATUS.md to find the current project state.

## Determine What To Do

1. Look at the **Step Status** table.
2. If any chunk has Status **FAIL**, that chunk takes priority — its `4·Execute` column will show **FIX**.
3. Otherwise, find the first chunk whose Status is `ready`.
4. In that chunk's row, find the column marked **NEXT**. That tells you which step to run.

## Follow The Right Step File

| Column marked | What to do | Follow this file |
|---------------|------------|------------------|
| **NEXT** in 1·Plan | Create the detailed implementation plan | `1_plan.md` |
| **NEXT** in 2·Prompt | Create the condensed agent prompt | `2_prompt.md` |
| **NEXT** in 3·Test | Create the automated test script | `3_test.md` |
| **NEXT** in 4·Execute | Implement the chunk and verify | `4_execute.md` |
| **FIX** in 4·Execute | Tests are failing — fix them | `4_execute.md` |

Read the indicated file and follow its instructions. The chunk ID ({N.N}) is the one you
identified in Step Status.

## Quick Reference

- Steps must be completed in order: Plan → Prompt → Test → Execute.
- Each step file tells you exactly what to read, what to create, and how to verify.
- If STATUS.md shows "All chunks complete", the project is done.
