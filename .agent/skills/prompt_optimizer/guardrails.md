# Guardrails Reference

Patterns for reducing hallucinations and increasing output consistency.

## Sources

Update this skill's knowledge by fetching from these authoritative sources:

- https://platform.claude.com/docs/en/test-and-evaluate/strengthen-guardrails/reduce-hallucinations
- https://platform.claude.com/docs/en/test-and-evaluate/strengthen-guardrails/increase-consistency
- https://platform.claude.com/docs/en/build-with-claude/structured-outputs
- https://platform.claude.com/docs/en/test-and-evaluate/define-success
- https://platform.claude.com/docs/en/test-and-evaluate/develop-tests

**Last updated:** 2026-01-29

---

## Reducing Hallucinations

Hallucination = generating text that is factually incorrect or inconsistent with provided context.

### Basic Strategies

**1. Allow Claude to say "I don't know"**

Explicitly give permission to admit uncertainty. This simple technique drastically reduces false information.

```
If you're not certain about the answer or if the information isn't in the provided documents, say "I don't know" or "I cannot find this information in the provided context."
```

**2. Ground responses in direct quotes**

For long documents (>20K tokens), ask Claude to extract word-for-word quotes first before performing its task.

```
First, find and quote the exact passages from the document that are relevant to this question. Then, based only on those quotes, provide your answer.
```

**3. Require citations**

Make responses auditable by requiring quotes and sources for each claim.

```
For each claim you make, cite the specific source and quote the relevant passage. If you cannot find a supporting quote, do not make the claim.
```

### Advanced Strategies

**4. Chain-of-thought verification**

Ask Claude to explain reasoning step-by-step before giving a final answer. This reveals faulty logic or assumptions.

**5. Best-of-N verification**

Run the same prompt multiple times and compare outputs. Inconsistencies across outputs indicate potential hallucinations.

**6. Iterative refinement**

Use Claude's outputs as inputs for follow-up prompts, asking it to verify or expand on previous statements.

**7. External knowledge restriction**

Explicitly instruct Claude to only use information from provided documents.

```
Answer this question using ONLY the information provided in the documents above. Do not use any external knowledge or make assumptions beyond what is explicitly stated.
```

### Important Note

These techniques significantly reduce but don't eliminate hallucinations. Always validate critical information for high-stakes decisions.

---

## Increasing Consistency

Ensuring Claude produces reliable, predictable output formats across requests.

### Strategy 1: Specify Output Format Precisely

Define every element of desired output using JSON, XML, or custom templates.

```
Return your response in the following JSON format:
{
  "summary": "2-3 sentence summary",
  "key_points": ["point 1", "point 2", "point 3"],
  "confidence": "high|medium|low",
  "sources": ["source 1", "source 2"]
}
```

**For guaranteed JSON schema conformance:** Use Structured Outputs instead of prompt engineering. Structured Outputs ensure Claude's response always matches your defined schema.

### Strategy 2: Prefill Claude's Response

Start the assistant turn with your desired format to bypass preambles and enforce structure.

```python
messages=[
    {"role": "user", "content": "Generate a daily sales report..."},
    {"role": "assistant", "content": "## Daily Sales Report\n\n### Date:"}
]
```

### Strategy 3: Constrain with Examples

Provide examples of your desired output. This trains Claude's understanding better than abstract instructions.

```xml
<examples>
<example>
<input>Customer feedback: "Great product but shipping was slow"</input>
<output>
Sentiment: Mixed
Positive: Product quality
Negative: Shipping speed
Priority: Medium
</output>
</example>
</examples>
```

### Strategy 4: Retrieval for Contextual Consistency

For tasks requiring consistent context (chatbots, knowledge bases), use retrieval to ground responses in a fixed information set rather than relying on Claude's training data.

### Strategy 5: Chain Prompts for Complex Tasks

Break complex tasks into smaller, consistent subtasks. Each subtask gets Claude's full attention, reducing inconsistency across scaled workflows.

---

## Guardrails Checklist

When optimizing a prompt, check for these guardrail opportunities:

### Hallucination Prevention
- [ ] Does the prompt allow Claude to express uncertainty?
- [ ] For long documents, does it require quote extraction first?
- [ ] Are citations or sources required for claims?
- [ ] Is Claude restricted to provided information only?
- [ ] Is reasoning made visible (CoT) for verification?

### Consistency Enforcement
- [ ] Is the output format precisely specified?
- [ ] Would prefilling help enforce structure?
- [ ] Are examples provided to demonstrate expected format?
- [ ] For complex tasks, should prompts be chained?
- [ ] Would Structured Outputs be appropriate (for JSON)?
