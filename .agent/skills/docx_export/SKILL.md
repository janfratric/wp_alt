---
name: docx-export
description: Convert Markdown documents to DOCX format. Use when creating Word documents from markdown files for sharing, printing, or professional document delivery.
---

# DOCX Export Skill

Convert Markdown (.md) files to Microsoft Word (.docx) format for professional document delivery, sharing, or printing.

## When to Use This Skill

Use this skill when:
- User asks to "convert", "export", or "save" a markdown file to DOCX/Word format
- User needs to share a document with non-technical stakeholders
- User wants to create a professional document from meeting notes, reports, or documentation
- User mentions "Word document", "DOCX", or "Microsoft Word"

**Example triggers:**
- "Export this markdown to DOCX"
- "Convert Meeting_Synthesis.md to Word"
- "Create a Word document from this file"
- "Save as DOCX"
- "I need this as a Word file"

## Prerequisites

This skill requires **Pandoc** to be installed on the system.

### Install Pandoc

**Windows (winget):**
```powershell
winget install --id JohnMacFarlane.Pandoc -e
```

**Windows (Chocolatey):**
```powershell
choco install pandoc
```

**Verify installation:**
```powershell
pandoc --version
```

## How to Convert

### Basic Conversion

Use the following command pattern to convert a markdown file to DOCX:

```powershell
pandoc "<input.md>" -o "<output.docx>"
```

**Example:**
```powershell
pandoc "C:\!Agents\autoniq\Meeting_Synthesis.md" -o "C:\!Agents\autoniq\Meeting_Synthesis.docx"
```

### Enhanced Conversion Options

For better formatting and professional output, use these additional flags:

```powershell
pandoc "<input.md>" -o "<output.docx>" --from=markdown --to=docx --standalone
```

**With a reference document for custom styling:**
```powershell
pandoc "<input.md>" -o "<output.docx>" --reference-doc="<template.docx>"
```

### Common Options

| Option | Description |
|--------|-------------|
| `-o <file>` | Output file path |
| `--from=markdown` | Explicitly set input format |
| `--to=docx` | Explicitly set output format |
| `--standalone` | Create standalone document |
| `--toc` | Include table of contents |
| `--reference-doc=<file>` | Use custom DOCX template for styling |
| `--metadata title="Title"` | Set document title metadata |

### Table of Contents

To include an auto-generated table of contents:

```powershell
pandoc "<input.md>" -o "<output.docx>" --toc --toc-depth=3
```

## For AI Agents

### Step-by-Step Process

1. **Identify source file** - Determine the markdown file path to convert
2. **Determine output path** - Use same directory and base name with `.docx` extension
3. **Check if Pandoc is installed** - Run `pandoc --version` first
4. **Execute conversion** - Run the pandoc command with appropriate options
5. **Verify output** - Confirm the DOCX file was created successfully

### Example Workflow

```
User: "Export Meeting_Synthesis.md to Word"

Agent workflow:
1. Identify source: C:\!Agents\autoniq\Meeting_Synthesis.md
2. Determine output: C:\!Agents\autoniq\Meeting_Synthesis.docx
3. Run: pandoc --version (verify installation)
4. Run: pandoc "C:\!Agents\autoniq\Meeting_Synthesis.md" -o "C:\!Agents\autoniq\Meeting_Synthesis.docx"
5. Confirm: File created at C:\!Agents\autoniq\Meeting_Synthesis.docx
```

### Handling Missing Pandoc

If Pandoc is not installed, inform the user and offer to install it:

```powershell
# Check if Pandoc exists
pandoc --version

# If command not found, suggest:
winget install --id JohnMacFarlane.Pandoc -e
```

### Output Path Convention

When user doesn't specify output location:
- Use the **same directory** as the input file
- Use the **same base name** with `.docx` extension
- Example: `Report.md` → `Report.docx`

When user specifies a directory:
- Use the specified directory with the original base name

### Quality Considerations

- **Images**: Pandoc automatically embeds images referenced in markdown
- **Tables**: Standard markdown tables convert cleanly to Word tables
- **Code blocks**: Preserved with monospace formatting
- **Headers**: Converted to proper Word heading styles (H1, H2, etc.)
- **Lists**: Numbered and bulleted lists convert properly

### Troubleshooting

| Issue | Solution |
|-------|----------|
| "pandoc: command not found" | Install Pandoc using winget or chocolatey |
| Images not appearing | Ensure image paths are correct relative to markdown file |
| Encoding issues | Add `--metadata encoding=utf-8` |
| Poor styling | Use `--reference-doc` with a pre-styled template |

## Advanced: Custom Templates

For consistent corporate styling, create a reference document:

1. Generate a default reference doc:
   ```powershell
   pandoc -o reference.docx --print-default-data-file reference.docx
   ```

2. Open in Word and modify styles (Heading 1, Normal, etc.)

3. Use as template:
   ```powershell
   pandoc "input.md" -o "output.docx" --reference-doc="reference.docx"
   ```
