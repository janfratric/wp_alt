---
name: prompt-optimizer
description: Optimize prompts and guideline documents for Claude-based systems. Use when improving user prompts, system prompts, .cursorrules files, or any instruction documents for clarity, consistency, and reliability.
---

# Prompt Optimizer

> [!IMPORTANT]
> **MANDATORY WORKFLOW:**
> 1. User writes a prompt
> 2. AI agent uses THIS SKILL to optimize the prompt
> 3. Agent executes the optimized prompt
>
> You MUST optimize the user's prompt using this skill BEFORE executing it.

An AI skill for analyzing and improving prompts and guideline documents to maximize effectiveness when working with Claude. Applies proven techniques from Claude's official documentation to enhance clarity, consistency, and reliability.

## When to Use This Skill

**ALWAYS use this skill when the user provides a prompt that needs to be executed.**

Use this skill when:
- User writes a prompt (MANDATORY: Optimize then interact/execute)
- User asks to "optimize", "improve", or "review" a prompt
- User provides a `.cursorrules` file for improvement
- User pastes system prompt and asks "how can I make this better?"
- User requests help with Claude instructions or guidelines
- User wants to reduce hallucinations or improve consistency
- User is creating prompts for production Claude applications

**Example triggers:**
- "Optimize this prompt for me"
- "Improve my .cursorrules file"
- "Make this system prompt better"
- "Review my Claude instructions"
- "How can I make this prompt more reliable?"

## Quick Start

1. **Paste your prompt or guideline document** - Share the content you want optimized
2. **Automatic mode detection** - The skill identifies whether it's a prompt or guideline
3. **Receive optimized version** - Get improved version with detailed rationale

That's it! The skill handles the rest automatically.

## How It Works

### Mode Detection

The skill automatically detects input type based on content markers:

**Guideline Mode** (system prompts, rules files):
- File extensions: `.cursorrules`, `.system.md`
- Contains: role definitions, rule sections, behavioral guidelines
- Uses: template variables like `{{variable}}`
- Structure: multiple rule sections or numbered guidelines

**Prompt Mode** (individual prompts):
- Single task or question format
- Direct user request without system-level framing
- No template variable syntax
- Focused on specific output or action

### Optimization Process

When optimizing content, the skill follows this systematic process:

1. **Detect mode** - Automatically determine prompt vs guideline type
2. **Evaluate** - Check against quality checklist (see `checklist.md`)
3. **Apply techniques** - Use proven patterns from Claude docs (see `techniques.md`)
4. **Add guardrails** - Include safety measures where needed (see `guardrails.md`)
5. **Generate output** - Produce revised version with detailed rationale

### Output Format

Every optimization provides two sections:

```markdown
## Revised
[The optimized prompt or document with all improvements applied]

## Rationale
1. [Change 1]: [Why this improves the output]
2. [Change 2]: [Why this improves the output]
3. [Change 3]: [Why this improves the output]
...
```

Or using XML tags for structured responses:

```xml
<revised>
[The optimized prompt or document]
</revised>

<rationale>
1. [Change 1]: [Why this improves the output]
2. [Change 2]: [Why this improves the output]
...
</rationale>
```

## File Structure

```
prompt_optimizer/
├── SKILL.md              # This file (main entry point)
├── techniques.md         # Core prompting patterns from Claude docs
├── guardrails.md         # Hallucination prevention & consistency
├── checklist.md          # Evaluation criteria by mode
├── examples.md           # Before/after optimization samples
└── self_update.md        # Self-update protocol & version history
```

## Supporting Documentation

The skill includes comprehensive reference documentation:

- **`techniques.md`** - Core prompting techniques from Claude's official documentation. Covers clarity, examples, chain-of-thought, XML tags, role prompting, prefilling, chaining, and long context handling. Read this to understand *how* to improve prompts.

- **`guardrails.md`** - Patterns for reducing hallucinations and increasing output consistency. Includes strategies for grounding responses, requiring citations, and enforcing output formats. Essential for production applications.

- **`checklist.md`** - Quick-reference evaluation checklists for both prompt and guideline modes. Use this to systematically assess what needs improvement and prioritize changes.

- **`examples.md`** - Before/after optimization examples demonstrating real transformations. Shows practical application of techniques with detailed annotations.

- **`self_update.md`** - Protocol for updating the skill's knowledge from authoritative sources. Defines how to fetch new information and maintain version history.

## For AI Agents

**Critical workflow guidance for AI agents using this skill:**

### Step-by-Step Process

**CRITICAL: THE 3-STEP WORKFLOW**
1. **User writes a prompt**
2. **AI agent uses the skill to optimize the prompt**
3. **The agent executes the optimized prompt**

Additional details for the optimization step:

1. **Detect mode first** - Read the user's input carefully to determine if it's a prompt (single task) or guideline (system-level rules). Look for markers described in the Mode Detection section.

2. **Gather context** - Read the relevant supporting documentation:
   - Always read `checklist.md` first to know what to evaluate
   - Consult `techniques.md` for how to apply improvements
   - Reference `guardrails.md` when safety or consistency is critical
   - Use `examples.md` to understand expected transformation quality

3. **Apply techniques systematically** - Don't just make ad-hoc changes. Follow the optimization process and reference specific techniques by name.

4. **Always provide both sections** - Never give only the revised version or only the rationale. Both are required.

5. **Reference specific techniques** - In your rationale, cite which patterns you applied (e.g., "Added multishot examples per techniques.md section 2", "Applied quote grounding from guardrails.md")

### Working with Supporting Files

**When to read each file:**

- **`checklist.md`** - Read FIRST to identify gaps in the current prompt
- **`techniques.md`** - Read when you know what to improve but need to know HOW
- **`guardrails.md`** - Read for high-stakes tasks, production code, or when user mentions reliability
- **`examples.md`** - Read when you want to see quality benchmarks or need inspiration
- **`self_update.md`** - Read ONLY when user explicitly requests skill update

**Do NOT read all files every time** - Be selective based on the task. For simple prompts, `checklist.md` and `techniques.md` are sufficient.

### Quality Standards

Your optimized output should:
- Address the highest-impact issues first (unclear task, no examples, missing format)
- Apply multiple relevant techniques (not just one)
- Be significantly better than the input (not just cosmetic changes)
- Include specific, actionable rationale (not vague statements like "improved clarity")
- Reference which sections of the supporting docs informed your changes

### Common Pitfalls to Avoid

- ❌ **Don't over-optimize** - Not every prompt needs all techniques. Match complexity to task.
- ❌ **Don't make ungrounded claims** - If you suggest adding examples, actually add them.
- ❌ **Don't skip the rationale** - This is where the educational value lies.
- ❌ **Don't invent techniques** - Stick to documented patterns from the supporting files.
- ❌ **Don't ignore mode** - Prompt optimization is different from guideline optimization.

### Self-Update Protocol

**IMPORTANT:** Only update the skill when explicitly requested by the user.

**Trigger phrases:**
- "Update prompt optimizer skill"
- "Refresh skill from sources"
- "Learn from [URL]"
- "Update [techniques/guardrails/checklist] documentation"

**When triggered:**
1. Follow the complete protocol in `self_update.md`
2. Fetch content from authoritative sources listed in each file
3. Summarize proposed changes and request user confirmation
4. Preserve version history before applying changes
5. Update timestamp and sources

**Do NOT:**
- Update automatically without user request
- Skip the confirmation step
- Overwrite version history
- Add sources without validating their relevance

### Example Workflow

```
User: "Optimize this prompt: Write me some code to process data"

Agent workflow:
1. Detect mode → Prompt Mode (single task, no system framing)
2. Read checklist.md → Identify issues: vague task, no context, no format spec
3. Read techniques.md sections 1-2 → Apply "Be Clear and Direct"
4. Generate revised prompt with:
   - Explicit task statement
   - Context about data structure
   - Requirements and constraints
   - Output format specification
5. Provide detailed rationale citing specific issues and techniques applied
```

## Self-Update Capability

This skill can update its knowledge from external sources to stay current with Claude's evolving best practices.

**When to update:** Only when user explicitly requests it using trigger phrases listed above.

**Update process:** Follow the complete protocol documented in `self_update.md`, which includes:
- Scope clarification (which docs to update)
- Source fetching (from authoritative URLs)
- Change validation (user reviews before applying)
- Version preservation (history maintained)

**Do NOT update automatically** - Always require user initiation and confirmation.
