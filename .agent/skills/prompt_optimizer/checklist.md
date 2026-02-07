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

### Guardrails
- [ ] **Uncertainty allowed** — Can Claude say "I don't know" when appropriate?
- [ ] **Grounding present** — For document tasks, is quote extraction required?
- [ ] **Sources cited** — Should Claude cite sources for claims?

### Scoring Priority

High-impact improvements (fix first):
1. Missing or unclear task statement
2. No output format specification
3. Complex task without examples

Medium-impact improvements:
4. Missing context that affects output
5. No CoT for reasoning tasks
6. Unstructured multi-part prompt

Lower-impact refinements:
7. Adding role framing
8. XML structuring for organization
9. Prefill optimization

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
