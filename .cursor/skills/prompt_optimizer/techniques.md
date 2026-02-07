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

**Last updated:** 2026-01-29

---

## Technique Hierarchy

Apply techniques in this order (most broadly effective to most specialized):

1. Be Clear and Direct
2. Use Examples (Multishot)
3. Let Claude Think (Chain of Thought)
4. Use XML Tags
5. Give Claude a Role (System Prompts)
6. Prefill Claude's Response
7. Chain Complex Prompts
8. Long Context Tips

---

## 1. Be Clear and Direct

**When to use:** Always. This is the foundation for all prompts.

**Key principles:**
- Be explicit — don't assume Claude knows your context, norms, or preferences
- Provide context — explain the problem, constraints, and objectives
- Be specific — use precise language rather than vague instructions
- Give examples — even simple examples establish expectations

**Pattern:**
```
Task: [What you want Claude to do]
Context: [Background information Claude needs]
Constraints: [Limitations or requirements]
Output format: [How you want the result formatted]
```

**Key insight:** Treat Claude as a brilliant new employee with amnesia who needs explicit instructions.

---

## 2. Use Examples (Multishot Prompting)

**When to use:** When you need consistent output format, specific style, or handling of edge cases.

**Key principles:**
- Examples are a "secret weapon" for getting exact outputs
- Use 3-5 diverse examples for near-optimal performance
- Examples should be representative of real use cases
- Wrap in `<example>` and `<examples>` XML tags

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
    model="claude-sonnet-4-5-20250929",
    system="You are a seasoned [ROLE] at [CONTEXT].",
    messages=[{"role": "user", "content": "..."}]
)
```

**Benefits:** Enhanced accuracy, tailored tone, improved focus on domain-specific requirements.

---

## 6. Prefill Claude's Response

**When to use:** Control output format, skip preambles, maintain character in roleplay, enforce structure.

**Key principles:**
- Add desired starting text in the `assistant` message
- Prefilling `{` forces JSON output without preamble
- Use bracketed `[ROLE_NAME]` to maintain character
- Cannot end with trailing whitespace
- Not available with extended thinking mode

**Pattern (API):**
```python
messages=[
    {"role": "user", "content": "Extract data from: ..."},
    {"role": "assistant", "content": "{"}  # Prefill
]
```

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
