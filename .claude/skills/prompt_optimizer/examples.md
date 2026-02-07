# Optimization Examples

Before/after examples demonstrating prompt optimization techniques.

---

## Prompt Mode Examples

### Example 1: Vague Request → Structured Prompt

**Before:**
```
Write me some code to process data
```

**After:**
```
Task: Write a Python function that processes CSV sales data.

Context: I have CSV files with columns: date, product_id, quantity, unit_price.
Files can be 10,000-100,000 rows.

Requirements:
- Read CSV from a file path parameter
- Calculate total revenue (quantity × unit_price) per product
- Return a dictionary with product_id as key and total revenue as value
- Handle missing or malformed data gracefully

Output: Provide the function with docstring, type hints, and a usage example.
```

**Rationale:**
1. **Added explicit task**: Specifies language, data type, and operation
2. **Added context**: Describes data structure and scale
3. **Added requirements**: Lists specific functionality needed
4. **Added output format**: Requests docstring, types, and example

---

### Example 2: Missing Examples → Multishot Prompt

**Before:**
```
Categorize customer feedback into positive, negative, or neutral.

Feedback: "The product works great but arrived damaged."
```

**After:**
```
Categorize customer feedback into: positive, negative, neutral, or mixed.

<examples>
<example>
<feedback>Love this product! Best purchase I've made all year.</feedback>
<category>positive</category>
<reasoning>Strong positive language, no negatives mentioned</reasoning>
</example>
<example>
<feedback>Terrible quality, broke after one week. Want a refund.</feedback>
<category>negative</category>
<reasoning>Product failure, explicit dissatisfaction</reasoning>
</example>
<example>
<feedback>It's okay. Does what it's supposed to do.</feedback>
<category>neutral</category>
<reasoning>No strong positive or negative sentiment</reasoning>
</example>
<example>
<feedback>Amazing features but the battery life is disappointing.</feedback>
<category>mixed</category>
<reasoning>Contains both positive (features) and negative (battery) elements</reasoning>
</example>
</examples>

Now categorize this feedback:
<feedback>The product works great but arrived damaged.</feedback>

Respond with:
- Category: [category]
- Reasoning: [brief explanation]
```

**Rationale:**
1. **Added "mixed" category**: Original categories couldn't handle nuanced feedback
2. **Added 4 diverse examples**: Cover all categories with realistic cases
3. **Included reasoning in examples**: Shows expected thought process
4. **Specified output format**: Ensures consistent response structure

---

### Example 3: Complex Task → Chain of Thought

**Before:**
```
Should we acquire this company? Here's their financial data: [data]
```

**After:**
```
Analyze whether we should acquire the target company based on the financial data below.

<financial_data>
[data]
</financial_data>

<instructions>
Work through this analysis step-by-step in <thinking> tags:

1. **Financial Health**: Analyze revenue trends, profitability, and debt levels
2. **Valuation**: Assess if the implied valuation is reasonable given the financials
3. **Synergies**: Identify potential cost savings or revenue synergies
4. **Risks**: List key risks and red flags
5. **Alternatives**: Consider what else we could do with the acquisition capital

Then provide your recommendation in <recommendation> tags with:
- Decision: Acquire / Do Not Acquire / Need More Information
- Confidence: High / Medium / Low
- Key factors: Top 3 reasons driving the recommendation
</instructions>
```

**Rationale:**
1. **Added structured CoT**: Guides reasoning through specific analytical steps
2. **Used XML organization**: Separates data from instructions from output
3. **Specified output structure**: Ensures actionable recommendation format
4. **Added confidence level**: Helps calibrate how much to trust the analysis

---

## Guideline Mode Examples

### Example 4: Unstructured Rules → Organized System Prompt

**Before:**
```
You are a helpful assistant. Be concise. Don't make things up.
Use markdown. Be professional. Help with coding questions.
If you don't know something say so. Always be polite.
```

**After:**
```xml
<role>
You are a Senior Software Engineering Assistant specializing in code review,
debugging, and technical guidance. You have expertise in Python, JavaScript,
TypeScript, and cloud infrastructure.
</role>

<communication_style>
- Be concise and direct — developers value efficiency
- Use technical terminology appropriate to the context
- Format code with proper markdown code blocks and syntax highlighting
- Use bullet points for multiple items; avoid long paragraphs
</communication_style>

<response_guidelines>
- Lead with the solution or answer, then explain if needed
- For code questions: provide working code first, then explain key decisions
- For debugging: identify the likely cause, then suggest fixes
- For architecture: outline trade-offs before recommending an approach
</response_guidelines>

<guardrails>
- If uncertain about an answer, say "I'm not certain, but..." and explain your reasoning
- If a question is outside your expertise, acknowledge this and suggest resources
- Never invent APIs, library functions, or syntax — if unsure, say so
- For security-sensitive code, always note potential security considerations
</guardrails>

<output_formats>
Code blocks: Always specify language for syntax highlighting
```python
# Example format
```

Technical explanations: Use this structure:
1. **What**: Brief description
2. **Why**: Reasoning or context
3. **How**: Implementation details
</output_formats>
```

**Rationale:**
1. **Structured with XML sections**: Clear organization by purpose
2. **Specific role definition**: "Senior Software Engineering Assistant" vs generic "helpful"
3. **Actionable guidelines**: Each rule is specific and implementable
4. **Guardrails section**: Explicit uncertainty and limitation handling
5. **Output format templates**: Ensures consistent code and explanation formatting

---

### Example 5: Adding Hallucination Guardrails

**Before:**
```
Answer questions about our product documentation.

<documentation>
[product docs]
</documentation>
```

**After:**
```
Answer customer questions based ONLY on the product documentation provided below.

<documentation>
[product docs]
</documentation>

<instructions>
When answering:

1. First, find the relevant section(s) in the documentation
2. Quote the specific passage that answers the question
3. Provide your answer based on that quote
4. If the documentation doesn't contain the answer, respond:
   "I couldn't find information about [topic] in the product documentation.
   Please contact support@company.com for assistance."

Do NOT:
- Invent features or specifications not in the documentation
- Assume how features work if not explicitly documented
- Provide information from general knowledge about similar products
</instructions>

<response_format>
**Source:** [Section name from docs]
> [Direct quote from documentation]

**Answer:** [Your response based on the quote]
</response_format>
```

**Rationale:**
1. **Explicit grounding requirement**: "based ONLY on" sets hard boundary
2. **Quote-first approach**: Forces grounding before answering
3. **Graceful fallback**: Provides specific response for missing information
4. **Explicit prohibitions**: Lists specific behaviors to avoid
5. **Response template**: Makes grounding visible and auditable

---

## Optimization Annotations Key

| Symbol | Meaning |
|--------|---------|
| 🎯 | Added clear task/goal |
| 📋 | Added structure/organization |
| 💡 | Added examples |
| 🧠 | Added chain of thought |
| 🏷️ | Added XML tags |
| 🎭 | Added role/persona |
| 🛡️ | Added guardrails |
| 📊 | Added output format |
