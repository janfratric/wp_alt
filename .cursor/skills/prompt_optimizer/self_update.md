# Self-Update Protocol

This document defines how the Prompt Optimizer skill updates its knowledge from external sources.

---

## Trigger Phrases

The following user requests activate the update protocol:

- "Update prompt optimizer skill"
- "Refresh skill from sources"
- "Update skill knowledge"
- "Learn from [URL]"
- "Update techniques documentation"
- "Update guardrails documentation"
- "Refresh [document-name] from sources"

---

## Update Protocol

### Step 1: Clarify Scope

When update is triggered, ask the user:

```
I can update the Prompt Optimizer skill documentation. Please clarify:

1. **Which documents to update?**
   - All documentation
   - Specific: techniques.md, guardrails.md, checklist.md, examples.md

2. **Any new sources to include?**
   - Use existing sources only
   - Add new URL(s): [user provides URLs]
```

Wait for user response before proceeding.

### Step 2: Fetch Sources

For each document to be updated:

1. Read the `## Sources` section to get authoritative URLs
2. Include any new URLs the user provided
3. Fetch content from each source
4. Extract relevant information

### Step 3: Validate & Summarize

Before making changes, present a summary to the user:

```
## Update Summary for [document-name]

**Sources fetched:**
- [URL 1]: [Brief description of what was found]
- [URL 2]: [Brief description of what was found]

**Proposed changes:**
1. [Change 1]: [What will be added/modified]
2. [Change 2]: [What will be added/modified]
...

**Confirm update?** (yes/no)
```

Wait for user confirmation.

### Step 4: Preserve Previous Version

Before applying changes:

1. Copy current content to `## Version History` section below
2. Include date and brief description of what changed

### Step 5: Apply Changes

1. Update the document content
2. Update the `Last updated:` timestamp
3. If new sources were added, add them to the `## Sources` section

### Step 6: Confirm Completion

```
✅ Updated [document-name]
- Changes applied: [count]
- Previous version preserved in Version History
- Last updated: [date]
```

---

## Source Validation

When user provides new URLs to learn from:

1. **Fetch the content** from the provided URL
2. **Assess relevance**: Does it contain Claude/LLM prompting guidance?
3. **Summarize findings**: Present key information found
4. **Request confirmation**: Ask user if this should be incorporated

```
## Source Validation: [URL]

**Content found:** [Brief description]
**Relevance:** [High/Medium/Low] - [Reason]
**Key information:**
- [Point 1]
- [Point 2]

**Add to skill knowledge?** (yes/no)
```

If relevance is Low, recommend against adding but defer to user decision.

---

## Document-Specific Update Notes

### techniques.md
- Primary source: platform.claude.com prompt engineering docs
- Focus on: technique definitions, when-to-use guidance, patterns
- Preserve: technique hierarchy order (matches official docs)

### guardrails.md
- Primary sources: reduce-hallucinations, increase-consistency pages
- Focus on: strategies, implementation patterns, checklists
- Preserve: separation between hallucination and consistency sections

### checklist.md
- Derived from: techniques.md + guardrails.md
- Update after: updating the source documents
- Focus on: actionable checklist items, scoring guidance

### examples.md
- Can incorporate: examples from official docs, user-provided examples
- Focus on: clear before/after transformations with annotations

---

## Version History

### 2026-01-29 — Initial Release
- Created Prompt Optimizer skill
- Established core documentation structure
- Sources: Claude platform documentation (prompt engineering, guardrails)

---

*Add new version entries above this line when updating.*
