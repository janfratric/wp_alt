# Prompting Techniques Reference

## Sources

Update this skill's knowledge by fetching from these authoritative sources:

- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/overview
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/be-clear-and-direct
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/multishot-prompting
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/chain-of-thought
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/use-xml-tags
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/system-prompts
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prefill-claudes-response
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/chain-prompts
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/long-context-tips
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/extended-thinking-tips
- https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-4-best-practices

**Last updated:** 2026-02-19

---

## Technique Hierarchy

Apply techniques in this order (most broadly effective to most specialized):

1. Be Clear and Direct
2. Use Examples (Multishot)
3. Let Claude Think (Chain of Thought)
4. Use XML Tags
5. Give Claude a Role (System Prompts)
6. Prefill Claude's Response *(deprecated on Claude 4.6 — see migration notes)*
7. Chain Complex Prompts
8. Long Context Tips
9. Extended Thinking Tips *(NEW)*
10. Claude 4.x Best Practices *(NEW)*

---

## 1. Be Clear and Direct

**When to use:** Always. This is the foundation for all prompts.

**Key principles:**
- Be explicit — don't assume Claude knows your context, norms, or preferences
- Provide context — explain the problem, constraints, and objectives (explain *why*, not just *what*)
- Be specific — use precise language rather than vague instructions
- Give examples — even simple examples establish expectations
- If you want "above and beyond" behavior, explicitly request it

**Pattern:**
```
Task: [What you want Claude to do]
Context: [Background information Claude needs]
Constraints: [Limitations or requirements]
Output format: [How you want the result formatted]
```

**Key insight:** Treat Claude as a brilliant new employee with amnesia who needs explicit instructions.

**Claude 4.x note:** Adding context or motivation behind instructions (e.g., "Your response will be read aloud by TTS, so never use ellipses") helps Claude generalize better than bare rules.

---

## 2. Use Examples (Multishot Prompting)

**When to use:** When you need consistent output format, specific style, or handling of edge cases.

**Key principles:**
- Examples are a "secret weapon" for getting exact outputs
- Use 3-5 diverse examples for near-optimal performance
- Examples should be representative of real use cases
- Wrap in `<example>` and `<examples>` XML tags
- Be vigilant — Claude pays close attention to details in examples and may reproduce unintended patterns

**Pattern:**
```xml
<examples>
<example>
<input>First example input</input>
<output>First example output</output>
</example>
<example>
<input>Second example input</input>
<output>Second example output</output>
</example>
</examples>
```

**Impact:** 1-2 examples = significant improvement; 3-5 examples = near-optimal for most tasks.

**Extended thinking note:** Multishot works well with extended thinking. Include `<thinking>` or `<scratchpad>` tags in examples to demonstrate canonical reasoning patterns. Claude will generalize the pattern to its formal extended thinking process.

---

## 3. Chain of Thought (CoT)

**When to use:** Complex tasks requiring reasoning — math, logic, analysis, multi-step problems, decisions with many factors.

**Key principles:**
- Claude must output thinking for it to occur
- Use `<thinking>` and `<answer>` tags to separate reasoning from response
- Guide the thinking process with specific steps when needed
- Trade-off: increases output length and latency

**Pattern (structured guided CoT):**
```xml
<instructions>
Before answering, work through this step-by-step in <thinking> tags:
1. [First reasoning step]
2. [Second reasoning step]
3. [Third reasoning step]
Then provide your final answer in <answer> tags.
</instructions>
```

**When NOT to use:** Simple factual questions, straightforward tasks where thinking adds unnecessary latency.

**Extended thinking alternative:** For Claude models with extended thinking, consider using the built-in thinking feature (Section 9) instead of manual CoT tags. If your thinking budget is below the minimum (1024 tokens), use standard CoT with XML tags instead.

---

## 4. Use XML Tags

**When to use:** Complex prompts with multiple components (context, instructions, examples, constraints).

**Key principles:**
- XML provides hierarchical structure Claude parses accurately
- Use semantic tag names relevant to your domain
- Supports nesting for complex hierarchies
- Common tags: `<instructions>`, `<context>`, `<examples>`, `<constraints>`, `<formatting>`

**Pattern:**
```xml
<request>
  <context>Background information</context>
  <instructions>
    <primary>Main task</primary>
    <secondary>Additional requirements</secondary>
  </instructions>
  <constraints>Limitations to follow</constraints>
  <examples>...</examples>
</request>
```

**Custom tags:** Create domain-specific tags like `<patient_data>`, `<codebase>`, `<requirements>`.

**Output steering:** Tell Claude to write output inside specific XML tags (e.g., `<smoothly_flowing_prose_paragraphs>`) to control formatting style.

---

## 5. System Prompts / Role Prompting

**When to use:** When domain expertise improves output quality — legal, financial, technical, creative tasks.

**Key principles:**
- Use the `system` parameter to set Claude's role
- Role prompting significantly boosts performance in specialized domains
- Experiment with role specificity ("data scientist" vs "data scientist specializing in customer insights for Fortune 500 companies")
- Put role in system prompt; task-specific instructions in user turn

**Pattern (API):**
```python
response = client.messages.create(
    model="claude-opus-4-6",
    system="You are a seasoned [ROLE] at [CONTEXT].",
    messages=[{"role": "user", "content": "..."}]
)
```

**Claude 4.x note:** Claude 4.6 models are more responsive to system prompts than previous models. If your system prompts were aggressive to reduce undertriggering (e.g., "CRITICAL: You MUST..."), dial back to normal language — the model may now overtrigger.

**Benefits:** Enhanced accuracy, tailored tone, improved focus on domain-specific requirements.

---

## 6. Prefill Claude's Response

**Status:** ⚠️ **Deprecated on Claude 4.6 models.** Prefills on the last assistant turn are no longer supported starting with Claude 4.6. Existing models continue to support prefills.

**When to use (pre-4.6):** Control output format, skip preambles, maintain character in roleplay, enforce structure.

**Key principles (pre-4.6):**
- Add desired starting text in the `assistant` message
- Prefilling `{` forces JSON output without preamble
- Cannot end with trailing whitespace
- Not available with extended thinking mode

**Migration strategies for Claude 4.6:**

| Previous prefill use | Migration approach |
|---|---|
| **Format control** (JSON/YAML) | Use Structured Outputs, or instruct Claude to conform to the schema directly |
| **Skip preambles** | System prompt: "Respond directly without preamble. Do not start with 'Here is...', 'Based on...'" |
| **Avoid refusals** | Claude 4.6 is better at appropriate refusals; clear prompting should suffice |
| **Continuations** | Move to user message: "Your previous response ended with `[text]`. Continue from there." |
| **Context hydration** | Inject reminders in user turn, or hydrate via tools |

**Power tip:** For guaranteed JSON schema conformance, use Structured Outputs instead of prefilling.

---

## 7. Chain Complex Prompts

**When to use:** Multi-step tasks where single prompts drop steps or lose quality — research synthesis, document analysis, iterative content creation.

**Key principles:**
- Each subtask gets Claude's full attention, reducing errors
- Use XML tags to pass outputs between prompts
- Each prompt should have a single, clear objective
- Can run independent subtasks in parallel for speed

**Pattern:**
```
Prompt 1: Extract key information → Output A
Prompt 2: Analyze Output A → Output B
Prompt 3: Generate recommendations from Output B → Final Output
```

**Advanced: Self-correction chains** — Have Claude review its own work in a follow-up prompt.

**Example workflows:**
- Content: Research → Outline → Draft → Edit → Format
- Data: Extract → Transform → Analyze → Visualize
- Decisions: Gather info → List options → Analyze each → Recommend

---

## 8. Long Context Tips

**When to use:** Documents >20K tokens, multiple documents, complex multi-document tasks.

**Key principles:**
- Put long documents at the TOP of the prompt, query at the BOTTOM
- Queries at end improve response quality by up to 30%
- Wrap documents in `<document>` tags with metadata subtags
- Ask Claude to extract relevant quotes BEFORE performing the task

**Pattern:**
```xml
<documents>
<document>
<source>document_name.pdf</source>
<document_content>
[Long document content here]
</document_content>
</document>
</documents>

<query>Based on the above documents, [your question]</query>
```

**Quote grounding:** "First, extract word-for-word quotes relevant to the question. Then, answer based only on those quotes."

---

## 9. Extended Thinking Tips

**When to use:** Complex problems that benefit from deep reasoning — STEM, constraint optimization, multi-step analysis, complex coding. Requires extended thinking to be enabled via API.

**Key principles:**
- Start with general instructions, not step-by-step prescriptions — Claude's creative problem-solving often exceeds a human's ability to prescribe the optimal thinking process
- Start with minimum thinking budget (1024 tokens) and increase incrementally
- Extended thinking performs best in English (final output can be any language)
- For budgets above 32K, use batch processing to avoid timeout issues

**Pattern (general — preferred):**
```
Please think about this problem thoroughly and in great detail.
Consider multiple approaches and show your complete reasoning.
Try different methods if your first approach doesn't work.
```

**Pattern (structured — use when general doesn't work):**
```
Think through this step by step:
1. [First reasoning step]
2. [Second reasoning step]
Then provide your final answer.
```

**Multishot with extended thinking:**
```xml
Problem 1: What is 15% of 80?

<thinking>
To find 15% of 80:
1. Convert 15% to decimal: 0.15
2. Multiply: 0.15 × 80 = 12
</thinking>

The answer is 12.

Now solve: Problem 2: What is 35% of 240?
```

**Self-verification pattern:**
```
Write a function to calculate factorial.
Before you finish, verify your solution with test cases for n=0, n=1, n=5, n=10.
Fix any issues you find.
```

**When NOT to use:**
- Simple factual questions (adds latency without benefit)
- When thinking budget below minimum (1024) — use manual CoT with XML tags instead
- Don't pass Claude's thinking output back in the user text block (degrades results)
- Don't prefill extended thinking

**Use cases that benefit most:**
- Complex STEM problems (building mental models, sequential logic)
- Constraint optimization (multiple competing requirements)
- Thinking frameworks (Blue Ocean, Porter's Five Forces, etc.)

---

## 10. Claude 4.x Best Practices

**When to use:** When optimizing prompts specifically for Claude Opus 4.6, Sonnet 4.6, or Haiku 4.5. These models have fundamentally different behavior patterns from earlier generations.

### 10a. Adaptive Thinking & Effort Control

Claude 4.6 uses adaptive thinking (`thinking: {type: "adaptive"}`) where the model dynamically decides when and how much to think. Use the `effort` parameter as the primary control lever:

| Effort | Use case |
|---|---|
| `low` | High-volume, latency-sensitive, simple tasks |
| `medium` | Most applications (Sonnet 4.6 sweet spot) |
| `high` | Complex coding, agentic workflows, deep research |
| `max` | Hardest problems, large-scale migrations |

**Pattern (API):**
```python
client.messages.create(
    model="claude-opus-4-6",
    max_tokens=64000,
    thinking={"type": "adaptive"},
    output_config={"effort": "high"},
    messages=[{"role": "user", "content": "..."}],
)
```

**Key insight:** If the model is too aggressive after prompt cleanup, lower `effort` rather than adding more prompt constraints.

### 10b. Anti-Overengineering

Claude 4.6 tends to over-build. Add explicit constraints:

```
Avoid over-engineering. Only make changes that are directly requested or clearly necessary.
- Don't add features beyond what was asked
- Don't add error handling for scenarios that can't happen
- Don't create abstractions for one-time operations
- Don't design for hypothetical future requirements
```

### 10c. Anti-Overthinking

Remove patterns that were needed for older models:

- **Remove** anti-laziness prompts ("be thorough", "think carefully", "do not be lazy") — these cause runaway thinking on 4.6
- **Soften** tool-use language: replace "You MUST use [tool]" with "Use [tool] when it would help"
- **Remove** explicit think-tool instructions — the model thinks effectively without being told to
- **Use `effort`** as the primary control lever instead of prompt-based constraints

### 10d. Long-Horizon Reasoning & State Tracking

Claude 4.6 excels at tasks spanning multiple context windows:

- **First context window:** Set up framework (write tests, create setup scripts)
- **Future windows:** Iterate on a todo-list
- **State tracking:** Use structured formats (JSON) for test results, freeform text for progress notes
- **Git:** Use git for state tracking and checkpoints across sessions
- **Context awareness:** Claude 4.6 can track its remaining context budget; inform it about compaction if available

**Pattern:**
```
Your context window will be automatically compacted as it approaches its limit.
Do not stop tasks early due to token budget concerns. Save progress before
context refreshes. Be persistent and complete tasks fully.
```

### 10e. Subagent Orchestration

Claude 4.6 naturally delegates to subagents but may overuse them:

```
Use subagents when tasks can run in parallel, require isolated context,
or involve independent workstreams. For simple tasks, sequential operations,
or single-file edits, work directly rather than delegating.
```

### 10f. Parallel Tool Calling

Claude 4.6 excels at parallel tool execution. Steer explicitly:

```xml
<use_parallel_tool_calls>
If you intend to call multiple tools and there are no dependencies between
them, make all independent calls in parallel. Never use placeholders or
guess missing parameters.
</use_parallel_tool_calls>
```

### 10g. Tool Usage: Action vs. Suggestion

Claude 4.6 follows instructions precisely. If you say "suggest changes," it will only suggest. Be explicit about action:

- **Less effective:** "Can you suggest improvements?"
- **More effective:** "Make these improvements to the code."

To make Claude proactively take action:
```xml
<default_to_action>
By default, implement changes rather than only suggesting them.
If intent is unclear, infer the most useful action and proceed.
</default_to_action>
```

### 10h. Research & Information Gathering

For complex research tasks, use structured approaches:

```
Search systematically. Develop competing hypotheses.
Track confidence levels. Regularly self-critique your approach.
Update a hypothesis tree or research notes file.
Break down this complex research task.
```

### 10i. Balancing Autonomy and Safety

Guide Claude on when to confirm vs. act independently:

```
Take local, reversible actions freely (editing files, running tests).
For hard-to-reverse actions (deleting files, force-pushing, posting to
external services), ask the user before proceeding.
```

### 10j. Output Format Control

Effective format steering techniques for Claude 4.x:

1. **Tell what TO do** instead of what NOT to do ("Write flowing prose" > "Don't use markdown")
2. **Use XML format tags** ("Write in `<flowing_prose>` tags")
3. **Match prompt style to desired output** — removing markdown from your prompt reduces markdown in output
4. **For minimal markdown:** Provide explicit prose-style instructions

### 10k. Minimizing Hallucinations in Agentic Use

```xml
<investigate_before_answering>
Never speculate about code you have not opened. If the user references
a specific file, read it before answering. Give grounded,
hallucination-free answers.
</investigate_before_answering>
```

---

## Version History

### 2026-02-19 — Major Update: Extended Thinking + Claude 4.x Best Practices
- Added Section 9: Extended Thinking Tips (from new official docs page)
- Added Section 10: Claude 4.x Best Practices with 11 sub-techniques
- Updated Section 6: Prefill — marked as deprecated on Claude 4.6 with migration table
- Updated Section 1: Added context-motivation principle from Claude 4 docs
- Updated Section 2: Added vigilance note about examples, extended thinking multishot
- Updated Section 3: Added extended thinking alternative note
- Updated Section 4: Added output steering via XML tags
- Updated Section 5: Updated model ID, added overtriggering warning for 4.6
- Added 2 new sources to Sources section
- Sources: platform.claude.com/docs (extended-thinking-tips, claude-4-best-practices)

### 2026-01-29 — Initial Release
- Created techniques reference with 8 core techniques
- Sources: Claude platform documentation (prompt engineering section)
