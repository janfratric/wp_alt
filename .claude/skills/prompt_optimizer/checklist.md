# Optimization Checklists

Quick-reference checklists for evaluating prompts and guideline documents.

---

## Prompt Mode Checklist

Use when optimizing individual prompts or user requests.

### Structure & Clarity
- [ ] **Clear task statement** — Is the main task explicitly stated in the first 1-2 sentences?
- [ ] **Context provided** — Does Claude have the background info needed to understand the task?
- [ ] **Constraints defined** — Are limitations, requirements, or rules clearly specified?
- [ ] **Output format specified** — Is the expected response format (length, structure, style) defined?

### Technique Application
- [ ] **Examples included** — Would 1-3 examples improve output consistency?
- [ ] **CoT needed** — Does this task require reasoning? Should thinking be made visible?
- [ ] **XML beneficial** — Would XML tags help organize multiple components?
- [ ] **Role helpful** — Would domain expertise framing improve quality?
- [ ] **Extended thinking appropriate** — Would deep reasoning (STEM, optimization, analysis) benefit from extended thinking?
- [ ] **Effort level considered** — For Claude 4.x, is the effort parameter set appropriately (low/medium/high)?

### Guardrails
- [ ] **Uncertainty allowed** — Can Claude say "I don't know" when appropriate?
- [ ] **Grounding present** — For document tasks, is quote extraction required?
- [ ] **Sources cited** — Should Claude cite sources for claims?
- [ ] **Investigate-before-answering** — For agentic/coding tasks, does Claude read files before speculating?

### Claude 4.x Compatibility
- [ ] **No anti-laziness prompts** — Removed "be thorough", "think carefully", "do not be lazy" (causes overthinking on 4.6)?
- [ ] **Softened tool-use language** — Using "Use [tool] when helpful" instead of "You MUST use [tool]"?
- [ ] **No prefill reliance** — Migrated away from prefilled responses (deprecated on 4.6)?
- [ ] **Anti-overengineering present** — For coding tasks, does the prompt constrain scope?
- [ ] **Action vs. suggestion clear** — Is it explicit whether Claude should act or just suggest?

### Scoring Priority

High-impact improvements (fix first):
1. Missing or unclear task statement
2. No output format specification
3. Complex task without examples

Medium-impact improvements:
4. Missing context that affects output
5. No CoT / extended thinking for reasoning tasks
6. Unstructured multi-part prompt
7. Claude 4.x anti-patterns present (anti-laziness, aggressive tool language)

Lower-impact refinements:
8. Adding role framing
9. XML structuring for organization
10. Effort level tuning
11. Subagent orchestration guidance

---

## Guideline Mode Checklist

Use when optimizing system prompts, .cursorrules, or instruction documents.

### Identity & Role
- [ ] **Role defined** — Is the AI's role/persona clearly established?
- [ ] **Expertise specified** — Are relevant skills or knowledge domains stated?
- [ ] **Tone/style set** — Is the communication style defined (formal, casual, technical)?

### Rule Organization
- [ ] **Logical structure** — Are rules organized into clear sections/categories?
- [ ] **Priority indicated** — Are critical rules distinguished from preferences?
- [ ] **No conflicts** — Do any rules contradict each other?
- [ ] **Actionable language** — Are rules stated as clear do/don't instructions?

### Examples & Patterns
- [ ] **Examples for key patterns** — Do complex rules have examples?
- [ ] **Edge cases covered** — Are common exceptions or special cases addressed?
- [ ] **Anti-patterns shown** — Are "don't do this" examples included where helpful?

### Output Specifications
- [ ] **Formats defined** — Are expected output formats specified?
- [ ] **Templates provided** — Are reusable templates included for common outputs?
- [ ] **Length guidelines** — Are response length expectations set?

### Guardrails & Safety
- [ ] **Boundaries set** — Are limitations and restrictions clear?
- [ ] **Uncertainty handling** — Is Claude told when to ask for clarification?
- [ ] **Error handling** — Are fallback behaviors defined?
- [ ] **Consistency measures** — Are examples/prefills used for critical outputs?

### Maintainability
- [ ] **Modular sections** — Can sections be updated independently?
- [ ] **Version/date tracked** — Is there a last-updated indicator?
- [ ] **Source references** — Are authoritative sources cited for guidelines?

---

## Quick Scoring Guide

Rate the prompt/document on each dimension (1-5):

| Dimension | 1 (Poor) | 3 (Adequate) | 5 (Excellent) |
|-----------|----------|--------------|---------------|
| **Clarity** | Vague, ambiguous | Mostly clear | Precise, unambiguous |
| **Structure** | Unorganized | Some structure | Well-organized hierarchy |
| **Examples** | None | 1-2 basic | 3+ diverse, realistic |
| **Guardrails** | None | Some present | Comprehensive coverage |
| **Format spec** | Not defined | Partially defined | Fully specified |

**Optimization priority:** Address lowest-scoring dimensions first.

---

## Red Flags

Immediate issues to fix:

- ❌ No clear task or goal statement
- ❌ Assumes Claude knows context it doesn't have
- ❌ Complex task with no examples
- ❌ Multiple unrelated requests in one prompt
- ❌ Contradictory instructions
- ❌ Output format left entirely to Claude's discretion
- ❌ High-stakes task with no hallucination guardrails
- ❌ Document-based task with no grounding requirement

### Claude 4.x Red Flags (NEW)

- ❌ Uses "CRITICAL", "MUST", "ALWAYS" for tool triggering (causes overtriggering on 4.6)
- ❌ Contains anti-laziness prompts like "be thorough" or "do not be lazy" (causes runaway thinking)
- ❌ Relies on prefilled assistant responses (deprecated on 4.6)
- ❌ Uses explicit think-tool instructions like "use the think tool to plan" (causes over-planning)
- ❌ Says "suggest changes" when action is intended (Claude 4.6 follows literally)
- ❌ No autonomy/safety guidance for agentic tasks (risky actions without confirmation)
