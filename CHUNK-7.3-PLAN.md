# Chunk 7.3 — LiteCMS Design System as .pen File
## Detailed Implementation Plan

---

## Overview

This chunk creates a `.pen` design system file containing 8 reusable visual components that correspond to LiteCMS's existing page builder elements (hero, text section, feature grid, CTA, image+text, testimonials, FAQ, footer). Components use `.pen` variables for colors, fonts, and spacing with light/dark theme support. This file serves as the component library AI will use to generate visual pages via the Pencil editor.

**This chunk is unique** — it produces a design artifact (`.pen` JSON file) rather than PHP application code. The primary output is `designs/litecms-system.pen`, a documentation file, and a verification test script.

---

## File Creation Order

Files are listed in dependency order.

---

### 1. `designs/litecms-system.pen`

**Purpose**: Design system file with 8 reusable components and design token variables. This is the primary deliverable.

**Notes**:
- The file is JSON in the `.pen` format as understood by both the embedded Pencil editor and `PenConverter`.
- Components are marked `reusable: true` — they are templates, not rendered directly.
- All styling uses `$--variable` references (resolved by PenStyleBuilder to `var(--variable)` in CSS).
- Child node `id` values are meaningful — they serve as slot identifiers for `descendants` overrides when creating instances.
- The file can be created by writing JSON directly, or via the Pencil MCP tools (`batch_design`). Both approaches produce the same result.

---

### 2. `designs/README.md`

**Purpose**: Documents the design system — component names, IDs, slot structure, variable definitions, and usage instructions.

---

### 3. `tests/chunk-7.3-verify.php`

**Purpose**: Automated verification script. Validates the design system file structure, component definitions, variable usage, and PenConverter integration.

---

## Document Structure

The `.pen` document has this top-level structure:

```json
{
  "version": "2.7",
  "variables": { ... },
  "children": [
    { "id": "hero-section", "reusable": true, ... },
    { "id": "text-section", "reusable": true, ... },
    { "id": "feature-grid", "reusable": true, ... },
    { "id": "cta-banner", "reusable": true, ... },
    { "id": "image-text", "reusable": true, ... },
    { "id": "testimonial-section", "reusable": true, ... },
    { "id": "faq-section", "reusable": true, ... },
    { "id": "footer-section", "reusable": true, ... }
  ]
}
```

Since all children are `reusable: true`, PenConverter produces empty HTML when converting this file directly — that's correct, it's a library, not a page. Pages reference these components using `ref` nodes.

---

## Design Token Variables

All variables are defined in the document's `variables` object. Themed variables use the array-of-entries format that PenConverter's `buildVariableCss()` method processes.

### Variable Format Reference

PenConverter processes variables as follows:
- **Non-themed**: `{"type": "color", "value": "#2563eb"}` → `:root { --name: #2563eb; }`
- **Themed**: `{"type": "color", "value": [{"value": "#hex", "theme": {}}, {"value": "#hex", "theme": {"mode": "dark"}}]}` → `:root { --name: light-value; } [data-theme-mode="dark"] { --name: dark-value; }`

PenStyleBuilder resolves `$--primary` in node properties → `var(--primary)` in CSS output.

### Variable Definitions

```json
{
  "primary": {
    "type": "color",
    "value": [
      {"value": "#2563eb", "theme": {}},
      {"value": "#3b82f6", "theme": {"mode": "dark"}}
    ]
  },
  "primary-foreground": {
    "type": "color",
    "value": [
      {"value": "#ffffff", "theme": {}},
      {"value": "#ffffff", "theme": {"mode": "dark"}}
    ]
  },
  "background": {
    "type": "color",
    "value": [
      {"value": "#ffffff", "theme": {}},
      {"value": "#0f172a", "theme": {"mode": "dark"}}
    ]
  },
  "foreground": {
    "type": "color",
    "value": [
      {"value": "#0f172a", "theme": {}},
      {"value": "#f8fafc", "theme": {"mode": "dark"}}
    ]
  },
  "muted": {
    "type": "color",
    "value": [
      {"value": "#f1f5f9", "theme": {}},
      {"value": "#1e293b", "theme": {"mode": "dark"}}
    ]
  },
  "muted-foreground": {
    "type": "color",
    "value": [
      {"value": "#64748b", "theme": {}},
      {"value": "#94a3b8", "theme": {"mode": "dark"}}
    ]
  },
  "card": {
    "type": "color",
    "value": [
      {"value": "#ffffff", "theme": {}},
      {"value": "#1e293b", "theme": {"mode": "dark"}}
    ]
  },
  "card-foreground": {
    "type": "color",
    "value": [
      {"value": "#0f172a", "theme": {}},
      {"value": "#f8fafc", "theme": {"mode": "dark"}}
    ]
  },
  "border": {
    "type": "color",
    "value": [
      {"value": "#e2e8f0", "theme": {}},
      {"value": "#334155", "theme": {"mode": "dark"}}
    ]
  },
  "accent": {
    "type": "color",
    "value": [
      {"value": "#f59e0b", "theme": {}},
      {"value": "#f59e0b", "theme": {"mode": "dark"}}
    ]
  },
  "font-primary": {
    "type": "string",
    "value": "Inter, system-ui, sans-serif"
  },
  "font-secondary": {
    "type": "string",
    "value": "Inter, system-ui, sans-serif"
  },
  "radius-m": {
    "type": "number",
    "value": 8
  },
  "radius-pill": {
    "type": "number",
    "value": 9999
  },
  "spacing-section": {
    "type": "number",
    "value": 80
  },
  "spacing-content": {
    "type": "number",
    "value": 40
  },
  "max-width": {
    "type": "number",
    "value": 1200
  }
}
```

### CSS Output (Light/Dark)

```css
:root {
  --primary: #2563eb;
  --primary-foreground: #ffffff;
  --background: #ffffff;
  --foreground: #0f172a;
  --muted: #f1f5f9;
  --muted-foreground: #64748b;
  --card: #ffffff;
  --card-foreground: #0f172a;
  --border: #e2e8f0;
  --accent: #f59e0b;
  --font-primary: Inter, system-ui, sans-serif;
  --font-secondary: Inter, system-ui, sans-serif;
  --radius-m: 8;
  --radius-pill: 9999;
  --spacing-section: 80;
  --spacing-content: 40;
  --max-width: 1200;
}
[data-theme-mode="dark"] {
  --primary: #3b82f6;
  --primary-foreground: #ffffff;
  --background: #0f172a;
  --foreground: #f8fafc;
  --muted: #1e293b;
  --muted-foreground: #94a3b8;
  --card: #1e293b;
  --card-foreground: #f8fafc;
  --border: #334155;
  --accent: #f59e0b;
}
```

---

## Component Specifications

Each component below shows the complete `.pen` node structure. All components share:
- `"type": "frame"`, `"reusable": true`
- `"width": "fill_container"` — fills parent width
- Use `$--variable` references for all colors, fonts, and configurable spacing
- Semantic `name` values (PenNodeRenderer infers HTML tags from names: "header" → `<header>`, "footer" → `<footer>`, "section" → `<section>`)

### Component 1: Hero Section

**ID**: `hero-section`
**Purpose**: Full-width banner with heading, subheading, and CTA button. Primary background color.
**Slot nodes**: `hero-heading`, `hero-subheading`, `hero-cta`, `hero-cta-text`

```json
{
  "id": "hero-section",
  "type": "frame",
  "name": "Hero Section",
  "reusable": true,
  "layout": "vertical",
  "justifyContent": "center",
  "alignItems": "center",
  "gap": 24,
  "padding": [80, 40, 80, 40],
  "width": "fill_container",
  "fill": "$--primary",
  "children": [
    {
      "id": "hero-heading",
      "type": "text",
      "content": "Welcome to Our Site",
      "fontSize": 48,
      "fontWeight": "700",
      "fontFamily": "$--font-primary",
      "fill": "$--primary-foreground",
      "textAlign": "center",
      "width": "fill_container"
    },
    {
      "id": "hero-subheading",
      "type": "text",
      "content": "A brief tagline that describes what you do",
      "fontSize": 20,
      "fontWeight": "400",
      "fontFamily": "$--font-secondary",
      "fill": "#ffffffcc",
      "textAlign": "center",
      "width": "fill_container"
    },
    {
      "id": "hero-cta",
      "type": "frame",
      "name": "CTA Button",
      "layout": "horizontal",
      "justifyContent": "center",
      "alignItems": "center",
      "padding": [14, 32, 14, 32],
      "cornerRadius": "$--radius-pill",
      "fill": "$--background",
      "children": [
        {
          "id": "hero-cta-text",
          "type": "text",
          "content": "Get Started",
          "fontSize": 16,
          "fontWeight": "600",
          "fontFamily": "$--font-primary",
          "fill": "$--primary"
        }
      ]
    }
  ]
}
```

---

### Component 2: Text Section

**ID**: `text-section`
**Purpose**: Simple content section with heading and body text.
**Slot nodes**: `text-heading`, `text-body`

```json
{
  "id": "text-section",
  "type": "frame",
  "name": "Text Section",
  "reusable": true,
  "layout": "vertical",
  "gap": 16,
  "padding": [60, 40, 60, 40],
  "width": "fill_container",
  "alignItems": "center",
  "fill": "$--background",
  "children": [
    {
      "id": "text-content-wrapper",
      "type": "frame",
      "name": "Content Wrapper",
      "layout": "vertical",
      "gap": 16,
      "width": 800,
      "children": [
        {
          "id": "text-heading",
          "type": "text",
          "content": "Section Heading",
          "fontSize": 32,
          "fontWeight": "700",
          "fontFamily": "$--font-primary",
          "fill": "$--foreground",
          "width": "fill_container"
        },
        {
          "id": "text-body",
          "type": "text",
          "content": "Body text content goes here. This is a rich text area for detailed content about your topic. Write as much or as little as needed to communicate your message effectively.",
          "fontSize": 16,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground",
          "lineHeight": 1.7,
          "width": "fill_container"
        }
      ]
    }
  ]
}
```

---

### Component 3: Feature Grid

**ID**: `feature-grid`
**Purpose**: Section heading + row of 3 feature cards. Each card has an icon placeholder, title, and description.
**Slot nodes**: `feat-heading`, `feat-card-1`, `feat-card-1-title`, `feat-card-1-desc`, `feat-card-2`, `feat-card-2-title`, `feat-card-2-desc`, `feat-card-3`, `feat-card-3-title`, `feat-card-3-desc`

```json
{
  "id": "feature-grid",
  "type": "frame",
  "name": "Feature Grid Section",
  "reusable": true,
  "layout": "vertical",
  "gap": 40,
  "padding": [60, 40, 60, 40],
  "width": "fill_container",
  "alignItems": "center",
  "fill": "$--muted",
  "children": [
    {
      "id": "feat-heading",
      "type": "text",
      "content": "Our Features",
      "fontSize": 32,
      "fontWeight": "700",
      "fontFamily": "$--font-primary",
      "fill": "$--foreground",
      "textAlign": "center",
      "width": "fill_container"
    },
    {
      "id": "feat-row",
      "type": "frame",
      "name": "Feature Cards Row",
      "layout": "horizontal",
      "gap": 24,
      "width": "fill_container",
      "children": [
        {
          "id": "feat-card-1",
          "type": "frame",
          "name": "Feature Card",
          "layout": "vertical",
          "gap": 12,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "feat-card-1-icon",
              "type": "frame",
              "name": "Icon Placeholder",
              "width": 48,
              "height": 48,
              "cornerRadius": "$--radius-m",
              "fill": "$--primary",
              "layout": "horizontal",
              "justifyContent": "center",
              "alignItems": "center",
              "children": [
                {
                  "id": "feat-card-1-icon-text",
                  "type": "text",
                  "content": "1",
                  "fontSize": 20,
                  "fontWeight": "700",
                  "fill": "$--primary-foreground"
                }
              ]
            },
            {
              "id": "feat-card-1-title",
              "type": "text",
              "content": "Feature One",
              "fontSize": 20,
              "fontWeight": "600",
              "fontFamily": "$--font-primary",
              "fill": "$--card-foreground"
            },
            {
              "id": "feat-card-1-desc",
              "type": "text",
              "content": "A short description of this feature and the value it provides to users.",
              "fontSize": 14,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--muted-foreground",
              "lineHeight": 1.6
            }
          ]
        },
        {
          "id": "feat-card-2",
          "type": "frame",
          "name": "Feature Card",
          "layout": "vertical",
          "gap": 12,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "feat-card-2-icon",
              "type": "frame",
              "name": "Icon Placeholder",
              "width": 48,
              "height": 48,
              "cornerRadius": "$--radius-m",
              "fill": "$--primary",
              "layout": "horizontal",
              "justifyContent": "center",
              "alignItems": "center",
              "children": [
                {
                  "id": "feat-card-2-icon-text",
                  "type": "text",
                  "content": "2",
                  "fontSize": 20,
                  "fontWeight": "700",
                  "fill": "$--primary-foreground"
                }
              ]
            },
            {
              "id": "feat-card-2-title",
              "type": "text",
              "content": "Feature Two",
              "fontSize": 20,
              "fontWeight": "600",
              "fontFamily": "$--font-primary",
              "fill": "$--card-foreground"
            },
            {
              "id": "feat-card-2-desc",
              "type": "text",
              "content": "A short description of this feature and the value it provides to users.",
              "fontSize": 14,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--muted-foreground",
              "lineHeight": 1.6
            }
          ]
        },
        {
          "id": "feat-card-3",
          "type": "frame",
          "name": "Feature Card",
          "layout": "vertical",
          "gap": 12,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "feat-card-3-icon",
              "type": "frame",
              "name": "Icon Placeholder",
              "width": 48,
              "height": 48,
              "cornerRadius": "$--radius-m",
              "fill": "$--primary",
              "layout": "horizontal",
              "justifyContent": "center",
              "alignItems": "center",
              "children": [
                {
                  "id": "feat-card-3-icon-text",
                  "type": "text",
                  "content": "3",
                  "fontSize": 20,
                  "fontWeight": "700",
                  "fill": "$--primary-foreground"
                }
              ]
            },
            {
              "id": "feat-card-3-title",
              "type": "text",
              "content": "Feature Three",
              "fontSize": 20,
              "fontWeight": "600",
              "fontFamily": "$--font-primary",
              "fill": "$--card-foreground"
            },
            {
              "id": "feat-card-3-desc",
              "type": "text",
              "content": "A short description of this feature and the value it provides to users.",
              "fontSize": 14,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--muted-foreground",
              "lineHeight": 1.6
            }
          ]
        }
      ]
    }
  ]
}
```

---

### Component 4: CTA Banner

**ID**: `cta-banner`
**Purpose**: Horizontal call-to-action banner with text and button. Colored background.
**Slot nodes**: `cta-heading`, `cta-body`, `cta-button`, `cta-button-text`

```json
{
  "id": "cta-banner",
  "type": "frame",
  "name": "CTA Banner Section",
  "reusable": true,
  "layout": "horizontal",
  "justifyContent": "space_between",
  "alignItems": "center",
  "gap": 40,
  "padding": [48, 60, 48, 60],
  "width": "fill_container",
  "cornerRadius": "$--radius-m",
  "fill": "$--primary",
  "children": [
    {
      "id": "cta-text-group",
      "type": "frame",
      "name": "CTA Text",
      "layout": "vertical",
      "gap": 8,
      "width": "fill_container",
      "children": [
        {
          "id": "cta-heading",
          "type": "text",
          "content": "Ready to get started?",
          "fontSize": 28,
          "fontWeight": "700",
          "fontFamily": "$--font-primary",
          "fill": "$--primary-foreground"
        },
        {
          "id": "cta-body",
          "type": "text",
          "content": "Join thousands of happy customers today.",
          "fontSize": 16,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "#ffffffcc"
        }
      ]
    },
    {
      "id": "cta-button",
      "type": "frame",
      "name": "CTA Button",
      "layout": "horizontal",
      "justifyContent": "center",
      "alignItems": "center",
      "padding": [14, 32, 14, 32],
      "cornerRadius": "$--radius-pill",
      "fill": "$--background",
      "children": [
        {
          "id": "cta-button-text",
          "type": "text",
          "content": "Sign Up Now",
          "fontSize": 16,
          "fontWeight": "600",
          "fontFamily": "$--font-primary",
          "fill": "$--primary"
        }
      ]
    }
  ]
}
```

---

### Component 5: Image + Text

**ID**: `image-text`
**Purpose**: Two-column layout — image on one side, text content on the other.
**Slot nodes**: `imgtext-image`, `imgtext-heading`, `imgtext-body`

```json
{
  "id": "image-text",
  "type": "frame",
  "name": "Image Text Section",
  "reusable": true,
  "layout": "horizontal",
  "gap": 40,
  "padding": [60, 40, 60, 40],
  "width": "fill_container",
  "alignItems": "center",
  "fill": "$--background",
  "children": [
    {
      "id": "imgtext-image",
      "type": "frame",
      "name": "Image Placeholder",
      "width": "fill_container",
      "height": 400,
      "cornerRadius": "$--radius-m",
      "fill": "$--muted",
      "layout": "horizontal",
      "justifyContent": "center",
      "alignItems": "center",
      "children": [
        {
          "id": "imgtext-image-label",
          "type": "text",
          "content": "Image",
          "fontSize": 18,
          "fontWeight": "500",
          "fill": "$--muted-foreground"
        }
      ]
    },
    {
      "id": "imgtext-content",
      "type": "frame",
      "name": "Text Content",
      "layout": "vertical",
      "gap": 16,
      "width": "fill_container",
      "children": [
        {
          "id": "imgtext-heading",
          "type": "text",
          "content": "About This Topic",
          "fontSize": 32,
          "fontWeight": "700",
          "fontFamily": "$--font-primary",
          "fill": "$--foreground"
        },
        {
          "id": "imgtext-body",
          "type": "text",
          "content": "Detailed information about the topic. Use this section to pair an image with descriptive content. The image can be replaced with any visual that supports the message.",
          "fontSize": 16,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground",
          "lineHeight": 1.7
        }
      ]
    }
  ]
}
```

---

### Component 6: Testimonial Section

**ID**: `testimonial-section`
**Purpose**: Section heading + grid of quote cards with author attribution.
**Slot nodes**: `testi-heading`, `testi-card-1`, `testi-card-1-quote`, `testi-card-1-author`, `testi-card-1-role`, etc.

```json
{
  "id": "testimonial-section",
  "type": "frame",
  "name": "Testimonial Section",
  "reusable": true,
  "layout": "vertical",
  "gap": 40,
  "padding": [60, 40, 60, 40],
  "width": "fill_container",
  "alignItems": "center",
  "fill": "$--background",
  "children": [
    {
      "id": "testi-heading",
      "type": "text",
      "content": "What Our Customers Say",
      "fontSize": 32,
      "fontWeight": "700",
      "fontFamily": "$--font-primary",
      "fill": "$--foreground",
      "textAlign": "center",
      "width": "fill_container"
    },
    {
      "id": "testi-row",
      "type": "frame",
      "name": "Testimonial Cards Row",
      "layout": "horizontal",
      "gap": 24,
      "width": "fill_container",
      "children": [
        {
          "id": "testi-card-1",
          "type": "frame",
          "name": "Testimonial Card",
          "layout": "vertical",
          "gap": 16,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "testi-card-1-quote",
              "type": "text",
              "content": "\"This product completely transformed how we work. Highly recommended!\"",
              "fontSize": 15,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--card-foreground",
              "lineHeight": 1.6,
              "fontStyle": "italic"
            },
            {
              "id": "testi-card-1-divider",
              "type": "line",
              "width": "fill_container",
              "stroke": {"thickness": 1, "fill": "$--border"}
            },
            {
              "id": "testi-card-1-meta",
              "type": "frame",
              "name": "Author Info",
              "layout": "vertical",
              "gap": 4,
              "children": [
                {
                  "id": "testi-card-1-author",
                  "type": "text",
                  "content": "Jane Smith",
                  "fontSize": 14,
                  "fontWeight": "600",
                  "fontFamily": "$--font-primary",
                  "fill": "$--card-foreground"
                },
                {
                  "id": "testi-card-1-role",
                  "type": "text",
                  "content": "CEO, Acme Corp",
                  "fontSize": 13,
                  "fontWeight": "400",
                  "fontFamily": "$--font-secondary",
                  "fill": "$--muted-foreground"
                }
              ]
            }
          ]
        },
        {
          "id": "testi-card-2",
          "type": "frame",
          "name": "Testimonial Card",
          "layout": "vertical",
          "gap": 16,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "testi-card-2-quote",
              "type": "text",
              "content": "\"Excellent service and support. The team went above and beyond for us.\"",
              "fontSize": 15,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--card-foreground",
              "lineHeight": 1.6,
              "fontStyle": "italic"
            },
            {
              "id": "testi-card-2-divider",
              "type": "line",
              "width": "fill_container",
              "stroke": {"thickness": 1, "fill": "$--border"}
            },
            {
              "id": "testi-card-2-meta",
              "type": "frame",
              "name": "Author Info",
              "layout": "vertical",
              "gap": 4,
              "children": [
                {
                  "id": "testi-card-2-author",
                  "type": "text",
                  "content": "John Doe",
                  "fontSize": 14,
                  "fontWeight": "600",
                  "fontFamily": "$--font-primary",
                  "fill": "$--card-foreground"
                },
                {
                  "id": "testi-card-2-role",
                  "type": "text",
                  "content": "Marketing Director, Beta Inc",
                  "fontSize": 13,
                  "fontWeight": "400",
                  "fontFamily": "$--font-secondary",
                  "fill": "$--muted-foreground"
                }
              ]
            }
          ]
        },
        {
          "id": "testi-card-3",
          "type": "frame",
          "name": "Testimonial Card",
          "layout": "vertical",
          "gap": 16,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "testi-card-3-quote",
              "type": "text",
              "content": "\"Simple, fast, and exactly what we needed. No bloat, just results.\"",
              "fontSize": 15,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--card-foreground",
              "lineHeight": 1.6,
              "fontStyle": "italic"
            },
            {
              "id": "testi-card-3-divider",
              "type": "line",
              "width": "fill_container",
              "stroke": {"thickness": 1, "fill": "$--border"}
            },
            {
              "id": "testi-card-3-meta",
              "type": "frame",
              "name": "Author Info",
              "layout": "vertical",
              "gap": 4,
              "children": [
                {
                  "id": "testi-card-3-author",
                  "type": "text",
                  "content": "Sarah Chen",
                  "fontSize": 14,
                  "fontWeight": "600",
                  "fontFamily": "$--font-primary",
                  "fill": "$--card-foreground"
                },
                {
                  "id": "testi-card-3-role",
                  "type": "text",
                  "content": "CTO, Gamma Labs",
                  "fontSize": 13,
                  "fontWeight": "400",
                  "fontFamily": "$--font-secondary",
                  "fill": "$--muted-foreground"
                }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

---

### Component 7: FAQ Section

**ID**: `faq-section`
**Purpose**: Section heading + list of Q&A items. Each item has a question and answer.
**Slot nodes**: `faq-heading`, `faq-item-1-q`, `faq-item-1-a`, `faq-item-2-q`, `faq-item-2-a`, `faq-item-3-q`, `faq-item-3-a`

```json
{
  "id": "faq-section",
  "type": "frame",
  "name": "FAQ Section",
  "reusable": true,
  "layout": "vertical",
  "gap": 32,
  "padding": [60, 40, 60, 40],
  "width": "fill_container",
  "alignItems": "center",
  "fill": "$--muted",
  "children": [
    {
      "id": "faq-heading",
      "type": "text",
      "content": "Frequently Asked Questions",
      "fontSize": 32,
      "fontWeight": "700",
      "fontFamily": "$--font-primary",
      "fill": "$--foreground",
      "textAlign": "center",
      "width": "fill_container"
    },
    {
      "id": "faq-list",
      "type": "frame",
      "name": "FAQ Items",
      "layout": "vertical",
      "gap": 16,
      "width": 800,
      "children": [
        {
          "id": "faq-item-1",
          "type": "frame",
          "name": "FAQ Item",
          "layout": "vertical",
          "gap": 8,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "faq-item-1-q",
              "type": "text",
              "content": "What is LiteCMS?",
              "fontSize": 18,
              "fontWeight": "600",
              "fontFamily": "$--font-primary",
              "fill": "$--card-foreground"
            },
            {
              "id": "faq-item-1-a",
              "type": "text",
              "content": "LiteCMS is a lightweight content management system designed as a simpler alternative to WordPress for small business websites.",
              "fontSize": 15,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--muted-foreground",
              "lineHeight": 1.6
            }
          ]
        },
        {
          "id": "faq-item-2",
          "type": "frame",
          "name": "FAQ Item",
          "layout": "vertical",
          "gap": 8,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "faq-item-2-q",
              "type": "text",
              "content": "How do I get started?",
              "fontSize": 18,
              "fontWeight": "600",
              "fontFamily": "$--font-primary",
              "fill": "$--card-foreground"
            },
            {
              "id": "faq-item-2-a",
              "type": "text",
              "content": "Simply clone the repository, run composer install, configure your database, and visit the URL. The setup wizard handles the rest.",
              "fontSize": 15,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--muted-foreground",
              "lineHeight": 1.6
            }
          ]
        },
        {
          "id": "faq-item-3",
          "type": "frame",
          "name": "FAQ Item",
          "layout": "vertical",
          "gap": 8,
          "padding": 24,
          "width": "fill_container",
          "cornerRadius": "$--radius-m",
          "fill": "$--card",
          "stroke": {"thickness": 1, "fill": "$--border"},
          "children": [
            {
              "id": "faq-item-3-q",
              "type": "text",
              "content": "Can I customize the design?",
              "fontSize": 18,
              "fontWeight": "600",
              "fontFamily": "$--font-primary",
              "fill": "$--card-foreground"
            },
            {
              "id": "faq-item-3-a",
              "type": "text",
              "content": "Yes! You can customize colors, fonts, and spacing through the design system variables, or use the built-in visual editor for pixel-perfect control.",
              "fontSize": 15,
              "fontWeight": "400",
              "fontFamily": "$--font-secondary",
              "fill": "$--muted-foreground",
              "lineHeight": 1.6
            }
          ]
        }
      ]
    }
  ]
}
```

---

### Component 8: Footer

**ID**: `footer-section`
**Purpose**: Dark-background footer with copyright, navigation links, and social links area.
**Slot nodes**: `footer-copyright`, `footer-link-1`, `footer-link-2`, `footer-link-3`, `footer-social-label`

```json
{
  "id": "footer-section",
  "type": "frame",
  "name": "Footer",
  "reusable": true,
  "layout": "horizontal",
  "justifyContent": "space_between",
  "alignItems": "center",
  "gap": 40,
  "padding": [40, 60, 40, 60],
  "width": "fill_container",
  "fill": "$--foreground",
  "children": [
    {
      "id": "footer-left",
      "type": "frame",
      "name": "Footer Left",
      "layout": "vertical",
      "gap": 8,
      "children": [
        {
          "id": "footer-copyright",
          "type": "text",
          "content": "© 2026 Your Company. All rights reserved.",
          "fontSize": 14,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground"
        }
      ]
    },
    {
      "id": "footer-nav",
      "type": "frame",
      "name": "Footer Navigation",
      "layout": "horizontal",
      "gap": 24,
      "children": [
        {
          "id": "footer-link-1",
          "type": "text",
          "content": "Privacy Policy",
          "fontSize": 14,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground"
        },
        {
          "id": "footer-link-2",
          "type": "text",
          "content": "Terms of Service",
          "fontSize": 14,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground"
        },
        {
          "id": "footer-link-3",
          "type": "text",
          "content": "Contact",
          "fontSize": 14,
          "fontWeight": "400",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground"
        }
      ]
    },
    {
      "id": "footer-social",
      "type": "frame",
      "name": "Social Links",
      "layout": "horizontal",
      "gap": 16,
      "children": [
        {
          "id": "footer-social-label",
          "type": "text",
          "content": "Follow Us",
          "fontSize": 14,
          "fontWeight": "500",
          "fontFamily": "$--font-secondary",
          "fill": "$--muted-foreground"
        }
      ]
    }
  ]
}
```

---

## Component Summary Table

| # | Component | ID | Slot Nodes | Background |
|---|---|---|---|---|
| 1 | Hero Section | `hero-section` | `hero-heading`, `hero-subheading`, `hero-cta-text` | `$--primary` |
| 2 | Text Section | `text-section` | `text-heading`, `text-body` | `$--background` |
| 3 | Feature Grid | `feature-grid` | `feat-heading`, `feat-card-{1-3}-title`, `feat-card-{1-3}-desc` | `$--muted` |
| 4 | CTA Banner | `cta-banner` | `cta-heading`, `cta-body`, `cta-button-text` | `$--primary` |
| 5 | Image + Text | `image-text` | `imgtext-heading`, `imgtext-body`, `imgtext-image` | `$--background` |
| 6 | Testimonials | `testimonial-section` | `testi-heading`, `testi-card-{1-3}-quote`, `testi-card-{1-3}-author`, `testi-card-{1-3}-role` | `$--background` |
| 7 | FAQ Section | `faq-section` | `faq-heading`, `faq-item-{1-3}-q`, `faq-item-{1-3}-a` | `$--muted` |
| 8 | Footer | `footer-section` | `footer-copyright`, `footer-link-{1-3}`, `footer-social-label` | `$--foreground` |

---

## Implementation Approach

### Option A: Direct JSON file (recommended for consistency)

Write the complete JSON to `designs/litecms-system.pen` using PHP's `json_encode()` with `JSON_PRETTY_PRINT`. This ensures the structure exactly matches what PenConverter expects. The implementation script or manual process assembles the full document from the component specifications above.

### Option B: Pencil MCP tools

Use the Pencil MCP `batch_design` operations to create the file programmatically via the editor. This approach renders the components visually in the editor for verification, but requires the editor to be running.

### Recommended: Option A with Option B verification

1. Write the JSON file directly (reliable, testable, exact control).
2. Load it in the embedded Pencil editor to visually verify components render correctly.
3. Run PenConverter on a test page that uses `ref` instances to verify HTML/CSS output.

---

## Test Script: `tests/chunk-7.3-verify.php`

### Test List

| # | Test | What It Checks |
|---|---|---|
| 1 | Design system file exists | `designs/litecms-system.pen` is present |
| 2 | File is valid JSON | `json_decode` succeeds without error |
| 3 | Document has correct structure | Has `children` array and `variables` object |
| 4 | Has exactly 8 reusable components | Count children with `reusable: true` |
| 5 | All component IDs present | Each of the 8 expected IDs found |
| 6 | Components have children | Each component has non-empty `children` array |
| 7 | Components use variable references | At least one `$--` reference found in each component tree |
| 8 | Variables section has color tokens | `primary`, `background`, `foreground`, `muted-foreground`, `card`, `border` defined |
| 9 | Variables section has typography tokens | `font-primary`, `font-secondary` defined |
| 10 | Variables section has spacing tokens | `radius-m`, `radius-pill`, `spacing-section`, `max-width` defined |
| 11 | Themed variables have light/dark values | Color variables use array-of-entries format with `mode: dark` entry |
| 12 | PenConverter builds component registry | All 8 components found via `getComponent()` reflection |
| 13 | PenConverter converts page with hero instance | `ref` to `hero-section` renders HTML containing text |
| 14 | PenConverter converts page with multiple instances | Multiple `ref` instances produce HTML with all content |
| 15 | Descendant overrides work | Instance with `descendants` override changes text content |
| 16 | Variable CSS output has :root block | Converted CSS contains `:root { --primary: ...` |
| 17 | Variable CSS output has dark theme block | Converted CSS contains `[data-theme-mode="dark"]` |
| 18 | HTML output uses semantic tags | Hero renders as `<section>`, footer as `<footer>` |
| 19 | README file exists | `designs/README.md` is present and non-empty |
| 20 | Full pipeline integration | Multi-component page converts to valid HTML+CSS with all expected elements |

### Test Script Template

```php
<?php declare(strict_types=1);

/**
 * Chunk 7.3 — LiteCMS Design System as .pen File
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Design system file exists
 *   2.  File is valid JSON
 *   3.  Document has correct structure (children + variables)
 *   4.  Has exactly 8 reusable components
 *   5.  All component IDs present
 *   6.  Components have children (slot nodes)
 *   7.  Components use $-- variable references
 *   8.  Variables include color tokens
 *   9.  Variables include typography tokens
 *  10.  Variables include spacing tokens
 *  11.  Themed variables have light/dark values
 *  12.  PenConverter builds component registry from design system
 *  13.  PenConverter converts page with hero instance
 *  14.  PenConverter converts page with multiple component instances
 *  15.  Descendant overrides customize component content
 *  16.  Variable CSS output has :root block
 *  17.  Variable CSS output has dark theme block
 *  18.  HTML output uses semantic tags
 *  19.  README file exists
 *  20.  Full pipeline integration test
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1-5
 */

$rootDir = dirname(__DIR__);
$isSmoke = (getenv('LITECMS_TEST_SMOKE') === '1');

$pass = 0;
$fail = 0;

function test_pass(string $description): void {
    global $pass;
    $pass++;
    echo "[PASS] {$description}\n";
}

function test_fail(string $description, string $reason = ''): void {
    global $fail;
    $fail++;
    $detail = $reason ? " — {$reason}" : '';
    echo "[FAIL] {$description}{$detail}\n";
}

function test_skip(string $description): void {
    echo "[SKIP] {$description}\n";
}

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found');
    echo "\n[FAIL] Cannot continue\n";
    exit(1);
}
require_once $autoloadPath;

use App\PageBuilder\PenConverter;

$designFile = $rootDir . '/designs/litecms-system.pen';

// Expected component IDs
$expectedIds = [
    'hero-section',
    'text-section',
    'feature-grid',
    'cta-banner',
    'image-text',
    'testimonial-section',
    'faq-section',
    'footer-section',
];

$doc = null; // loaded after test 2

// ---------------------------------------------------------------------------
// Test 1: Design system file exists
// ---------------------------------------------------------------------------
if (file_exists($designFile)) {
    test_pass('Test 1: Design system file exists');
} else {
    test_fail('Test 1: Design system file exists', 'designs/litecms-system.pen not found');
    echo "\n[FAIL] Cannot continue — design system file missing\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 2: File is valid JSON
// ---------------------------------------------------------------------------
try {
    $json = file_get_contents($designFile);
    $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    test_pass('Test 2: File is valid JSON');
} catch (\JsonException $e) {
    test_fail('Test 2: File is valid JSON', $e->getMessage());
    echo "\n[FAIL] Cannot continue — invalid JSON\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 3: Document has correct structure
// ---------------------------------------------------------------------------
if (isset($doc['children']) && is_array($doc['children']) &&
    isset($doc['variables']) && is_array($doc['variables'])) {
    test_pass('Test 3: Document has correct structure (children + variables)');
} else {
    $missing = [];
    if (!isset($doc['children'])) $missing[] = 'children';
    if (!isset($doc['variables'])) $missing[] = 'variables';
    test_fail('Test 3: Document structure', 'missing: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
// Test 4: Has exactly 8 reusable components
// ---------------------------------------------------------------------------
$reusableCount = 0;
$foundComponents = [];
foreach ($doc['children'] as $child) {
    if (!empty($child['reusable'])) {
        $reusableCount++;
        $foundComponents[$child['id'] ?? ''] = $child;
    }
}
if ($reusableCount === 8) {
    test_pass('Test 4: Has exactly 8 reusable components');
} else {
    test_fail('Test 4: 8 reusable components', "found {$reusableCount}");
}

// ---------------------------------------------------------------------------
// Test 5: All component IDs present
// ---------------------------------------------------------------------------
$missingIds = [];
foreach ($expectedIds as $id) {
    if (!isset($foundComponents[$id])) {
        $missingIds[] = $id;
    }
}
if (empty($missingIds)) {
    test_pass('Test 5: All component IDs present');
} else {
    test_fail('Test 5: Component IDs', 'missing: ' . implode(', ', $missingIds));
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.3 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 6: Components have children (slot nodes)
// ---------------------------------------------------------------------------
$emptyComponents = [];
foreach ($foundComponents as $id => $comp) {
    if (empty($comp['children']) || !is_array($comp['children'])) {
        $emptyComponents[] = $id;
    }
}
if (empty($emptyComponents)) {
    test_pass('Test 6: Components have children (slot nodes)');
} else {
    test_fail('Test 6: Components with children', 'empty: ' . implode(', ', $emptyComponents));
}

// ---------------------------------------------------------------------------
// Test 7: Components use $-- variable references
// ---------------------------------------------------------------------------
function findVarRefs(array $node): bool {
    foreach ($node as $key => $value) {
        if (is_string($value) && str_starts_with($value, '$--')) {
            return true;
        }
        if (is_array($value) && findVarRefs($value)) {
            return true;
        }
    }
    return false;
}

$noVarComponents = [];
foreach ($foundComponents as $id => $comp) {
    if (!findVarRefs($comp)) {
        $noVarComponents[] = $id;
    }
}
if (empty($noVarComponents)) {
    test_pass('Test 7: Components use $-- variable references');
} else {
    test_fail('Test 7: Variable references', 'no refs in: ' . implode(', ', $noVarComponents));
}

// ---------------------------------------------------------------------------
// Test 8: Variables include color tokens
// ---------------------------------------------------------------------------
$colorTokens = ['primary', 'background', 'foreground', 'muted-foreground', 'card', 'border'];
$missingColors = [];
foreach ($colorTokens as $token) {
    if (!isset($doc['variables'][$token])) {
        $missingColors[] = $token;
    }
}
if (empty($missingColors)) {
    test_pass('Test 8: Variables include color tokens');
} else {
    test_fail('Test 8: Color tokens', 'missing: ' . implode(', ', $missingColors));
}

// ---------------------------------------------------------------------------
// Test 9: Variables include typography tokens
// ---------------------------------------------------------------------------
$typoTokens = ['font-primary', 'font-secondary'];
$missingTypo = [];
foreach ($typoTokens as $token) {
    if (!isset($doc['variables'][$token])) {
        $missingTypo[] = $token;
    }
}
if (empty($missingTypo)) {
    test_pass('Test 9: Variables include typography tokens');
} else {
    test_fail('Test 9: Typography tokens', 'missing: ' . implode(', ', $missingTypo));
}

// ---------------------------------------------------------------------------
// Test 10: Variables include spacing tokens
// ---------------------------------------------------------------------------
$spacingTokens = ['radius-m', 'radius-pill', 'spacing-section', 'max-width'];
$missingSpacing = [];
foreach ($spacingTokens as $token) {
    if (!isset($doc['variables'][$token])) {
        $missingSpacing[] = $token;
    }
}
if (empty($missingSpacing)) {
    test_pass('Test 10: Variables include spacing tokens');
} else {
    test_fail('Test 10: Spacing tokens', 'missing: ' . implode(', ', $missingSpacing));
}

// ---------------------------------------------------------------------------
// Test 11: Themed variables have light/dark values
// ---------------------------------------------------------------------------
try {
    $ok = true;
    $primary = $doc['variables']['primary'] ?? null;
    if ($primary === null || !is_array($primary['value'] ?? null)) {
        test_fail('Test 11: Themed variables', 'primary variable value is not an array');
        $ok = false;
    } else {
        $values = $primary['value'];
        $hasDefault = false;
        $hasDark = false;
        foreach ($values as $entry) {
            if (empty($entry['theme'] ?? [])) {
                $hasDefault = true;
            }
            if (($entry['theme']['mode'] ?? '') === 'dark') {
                $hasDark = true;
            }
        }
        if (!$hasDefault) {
            test_fail('Test 11: Themed variables', 'primary missing default (light) value');
            $ok = false;
        }
        if (!$hasDark) {
            test_fail('Test 11: Themed variables', 'primary missing dark theme value');
            $ok = false;
        }
    }
    if ($ok) {
        test_pass('Test 11: Themed variables have light/dark values');
    }
} catch (\Throwable $e) {
    test_fail('Test 11: Themed variables', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: PenConverter builds component registry from design system
// ---------------------------------------------------------------------------
try {
    // Convert the design system file — should succeed even with empty output
    $result = PenConverter::convertDocument($doc);

    // All 8 components should be registered internally (but not rendered).
    // We verify by creating instances that reference them.
    $ok = true;
    foreach ($expectedIds as $compId) {
        $testDoc = [
            'children' => array_merge(
                $doc['children'],
                [['id' => 'test-inst', 'type' => 'ref', 'ref' => $compId]]
            ),
            'variables' => $doc['variables'],
        ];
        $testResult = PenConverter::convertDocument($testDoc);
        if (empty($testResult['html']) || str_contains($testResult['html'], 'Component not found')) {
            test_fail("Test 12: Component registry — {$compId}", 'not found or not rendered');
            $ok = false;
        }
    }
    if ($ok) {
        test_pass('Test 12: PenConverter builds component registry from design system');
    }
} catch (\Throwable $e) {
    test_fail('Test 12: Component registry', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: PenConverter converts page with hero instance
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'test-page',
                'type' => 'frame',
                'name' => 'Test Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    ['id' => 'hero-inst', 'type' => 'ref', 'ref' => 'hero-section'],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    if (str_contains($result['html'], 'Welcome to Our Site') &&
        str_contains($result['html'], 'Get Started')) {
        test_pass('Test 13: PenConverter converts page with hero instance');
    } else {
        test_fail('Test 13: Hero instance', 'expected hero text content in HTML output');
    }
} catch (\Throwable $e) {
    test_fail('Test 13: Hero instance', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: PenConverter converts page with multiple component instances
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'multi-page',
                'type' => 'frame',
                'name' => 'Multi Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    ['id' => 'hero-i', 'type' => 'ref', 'ref' => 'hero-section'],
                    ['id' => 'text-i', 'type' => 'ref', 'ref' => 'text-section'],
                    ['id' => 'cta-i', 'type' => 'ref', 'ref' => 'cta-banner'],
                    ['id' => 'footer-i', 'type' => 'ref', 'ref' => 'footer-section'],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    $checks = [
        str_contains($result['html'], 'Welcome to Our Site'),
        str_contains($result['html'], 'Section Heading'),
        str_contains($result['html'], 'Ready to get started'),
        str_contains($result['html'], 'All rights reserved'),
    ];
    if (!in_array(false, $checks, true)) {
        test_pass('Test 14: PenConverter converts page with multiple component instances');
    } else {
        test_fail('Test 14: Multiple instances', 'not all component content found in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 14: Multiple instances', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Descendant overrides customize component content
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'override-page',
                'type' => 'frame',
                'name' => 'Override Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    [
                        'id' => 'hero-custom',
                        'type' => 'ref',
                        'ref' => 'hero-section',
                        'descendants' => [
                            'hero-heading' => ['content' => 'Custom Hero Title'],
                            'hero-subheading' => ['content' => 'Custom subtitle here'],
                        ],
                    ],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    if (str_contains($result['html'], 'Custom Hero Title') &&
        str_contains($result['html'], 'Custom subtitle here') &&
        !str_contains($result['html'], 'Welcome to Our Site')) {
        test_pass('Test 15: Descendant overrides customize component content');
    } else {
        test_fail('Test 15: Descendant overrides', 'override text not found or default still present');
    }
} catch (\Throwable $e) {
    test_fail('Test 15: Descendant overrides', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Variable CSS output has :root block
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [['id' => 'css-page', 'type' => 'frame', 'name' => 'CSS Page', 'layout' => 'vertical',
              'width' => 1200, 'children' => [
                  ['id' => 'css-hero', 'type' => 'ref', 'ref' => 'hero-section'],
              ]]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    if (str_contains($result['css'], ':root') &&
        str_contains($result['css'], '--primary')) {
        test_pass('Test 16: Variable CSS output has :root block');
    } else {
        test_fail('Test 16: :root CSS', 'missing :root or --primary in CSS output');
    }
} catch (\Throwable $e) {
    test_fail('Test 16: :root CSS', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Variable CSS output has dark theme block
// ---------------------------------------------------------------------------
try {
    // Reuse result from test 16
    if (str_contains($result['css'] ?? '', '[data-theme-mode="dark"]')) {
        test_pass('Test 17: Variable CSS output has dark theme block');
    } else {
        test_fail('Test 17: Dark theme CSS', 'missing [data-theme-mode="dark"] in CSS');
    }
} catch (\Throwable $e) {
    test_fail('Test 17: Dark theme CSS', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: HTML output uses semantic tags
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'sem-page',
                'type' => 'frame',
                'name' => 'Semantic Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    ['id' => 'sem-hero', 'type' => 'ref', 'ref' => 'hero-section'],
                    ['id' => 'sem-footer', 'type' => 'ref', 'ref' => 'footer-section'],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    $hasSection = str_contains($result['html'], '<section') || str_contains($result['html'], '<div');
    $hasFooter = str_contains($result['html'], '<footer');

    if ($hasSection && $hasFooter) {
        test_pass('Test 18: HTML output uses semantic tags');
    } else {
        $missing = [];
        if (!$hasSection) $missing[] = '<section> or <div> for hero';
        if (!$hasFooter) $missing[] = '<footer>';
        test_fail('Test 18: Semantic tags', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Test 18: Semantic tags', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: README file exists
// ---------------------------------------------------------------------------
$readmeFile = $rootDir . '/designs/README.md';
if (file_exists($readmeFile) && filesize($readmeFile) > 50) {
    test_pass('Test 19: README file exists');
} else {
    test_fail('Test 19: README file', 'designs/README.md missing or too small');
}

// ---------------------------------------------------------------------------
// Test 20: Full pipeline integration test
// ---------------------------------------------------------------------------
try {
    // Build a complete page using all 8 components
    $allRefs = [];
    foreach ($expectedIds as $i => $compId) {
        $allRefs[] = ['id' => "full-{$i}", 'type' => 'ref', 'ref' => $compId];
    }
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'full-page',
                'type' => 'frame',
                'name' => 'Full Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => $allRefs,
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    $ok = true;

    // Check HTML is non-empty and has substantial content
    if (strlen($result['html']) < 200) {
        test_fail('Test 20: Integration — HTML length', 'HTML too short: ' . strlen($result['html']));
        $ok = false;
    }

    // Check CSS has variables, theme, and node styles
    if (!str_contains($result['css'], ':root') ||
        !str_contains($result['css'], '--primary') ||
        !str_contains($result['css'], '[data-theme-mode="dark"]')) {
        test_fail('Test 20: Integration — CSS variables/themes', 'missing expected CSS blocks');
        $ok = false;
    }

    // Check key content from different components
    $expectedContent = [
        'Welcome to Our Site',       // hero
        'Section Heading',            // text section
        'Our Features',               // feature grid
        'Ready to get started',       // CTA
        'About This Topic',           // image-text
        'What Our Customers Say',     // testimonials
        'Frequently Asked Questions', // FAQ
        'All rights reserved',        // footer
    ];
    foreach ($expectedContent as $text) {
        if (!str_contains($result['html'], $text)) {
            test_fail("Test 20: Integration — content: {$text}", 'not found in HTML');
            $ok = false;
        }
    }

    // Check CSS has component-specific classes
    if (!str_contains($result['css'], 'pen-full-page')) {
        test_fail('Test 20: Integration — CSS classes', 'page CSS class not found');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 20: Full pipeline integration test');
    }
} catch (\Throwable $e) {
    test_fail('Test 20: Integration', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.3 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

---

## `designs/README.md` Content

The README should document:

1. **Overview** — What the design system file is, its purpose, and how it fits in the LiteCMS pipeline.
2. **Components** — Table listing all 8 components with ID, name, slot nodes, and description.
3. **Variables / Design Tokens** — Full list of variables with their light/dark theme values.
4. **Usage** — How to create a page using component instances:
   - Using `ref` nodes in a `.pen` document
   - Overriding content via `descendants`
   - Example page structure
5. **Integration** — How PenConverter processes the design system (component registry, variable CSS, semantic HTML).
6. **Customization** — How to modify variables for different branding.

---

## Acceptance Test Procedures

### Test 1: Design system file is valid JSON and loadable
```
1. Verify designs/litecms-system.pen exists.
2. Run json_decode() — no errors.
3. Document has children[] and variables{}.
```

### Test 2: All 8 components are reusable
```
1. Count children with reusable: true — must be exactly 8.
2. Check IDs: hero-section, text-section, feature-grid, cta-banner,
   image-text, testimonial-section, faq-section, footer-section.
```

### Test 3: Components use variable references
```
1. Recursively scan each component for strings starting with "$--".
2. At least one variable reference per component.
```

### Test 4: Variables have light/dark theme values
```
1. Color variables use array-of-entries format.
2. Each has an entry with empty theme (light/default).
3. Each has an entry with {"mode": "dark"} (dark theme).
```

### Test 5: PenConverter integration
```
1. Create a test document that combines the design system children
   with a page frame containing ref instances.
2. Call PenConverter::convertDocument().
3. Verify HTML output contains component content.
4. Verify CSS output contains :root and [data-theme-mode="dark"] blocks.
```

### Test 6: Descendant overrides work
```
1. Create a ref instance with descendants overrides.
2. Convert — verify overridden text appears, default text does not.
```

### Test 7: File loads in embedded editor
```
1. Navigate to /admin/design/editor.
2. Select litecms-system.pen from the file dropdown.
3. Editor loads — 8 components visible as reusable components.
4. No console errors.
(This is a manual test — not automated in the verify script.)
```

---

## Implementation Notes

### Variable Reference Syntax
- In .pen node properties: `"$--primary"` (string starting with `$`)
- PenStyleBuilder::resolveValue strips `$`, and if the remainder starts with `--`, wraps in `var()`: `"$--primary"` → `var(--primary)`
- In the variables definition, the key is `"primary"` (without `--`), and PenConverter prepends `--` in the CSS output: `:root { --primary: #2563eb; }`

### Semantic HTML Inference
PenNodeRenderer infers HTML tags from frame `name` values:
- `"name": "Footer"` → `<footer>` tag
- `"name": "Header Section"` → `<header>` tag
- `"name": "Section"` → `<section>` tag
- Other names → `<div>` tag

For text nodes, tags are inferred from `fontSize`:
- ≥32 → `<h1>`, ≥24 → `<h2>`, ≥20 → `<h3>`, ≥18 → `<h4>`, ≥16 (bold) → `<h5>`, else `<p>`

### Component Naming for Semantic HTML
To get semantic tags, component frame names should include keywords:
- Hero Section → rendered as `<section>` (contains "section")
- Footer → rendered as `<footer>` (contains "footer")
- Other sections → `<section>` if name contains "section", else `<div>`

### Sizing Considerations
- Components use `"width": "fill_container"` to fill parent width (responsive).
- Inner content wrappers (e.g., FAQ list) use fixed max-width (800px) for readability.
- Feature/testimonial cards use `"fill_container"` width to distribute evenly in flex row.

### No External Dependencies
The .pen file uses no external images or fonts — all styling is done via CSS variables. Image placeholders use colored frames with text labels. Real images would be added per-instance via `descendants` overrides or image fills.

### Edge Cases
- **Empty content**: Components should render cleanly even if all text slots are overridden to empty strings.
- **Missing variable**: If a variable is not defined, PenStyleBuilder outputs `var(--name)` which falls back gracefully in CSS.
- **Deeply nested overrides**: Descendant paths can be multi-level (e.g., `"testi-card-1/testi-card-1-quote"` for nested children). Single-level paths work when the child has a unique ID within the component.

---

## File Checklist

| # | File | Type |
|---|------|------|
| 1 | `designs/litecms-system.pen` | Design system (JSON) |
| 2 | `designs/README.md` | Documentation |
| 3 | `tests/chunk-7.3-verify.php` | Test script |

---

## Estimated Scope

- **Design system file**: ~700-900 lines of formatted JSON (8 components + variables)
- **README**: ~150-200 lines of documentation
- **Test script**: ~350-400 lines of PHP
- **PHP application code**: 0 lines (no app code changes in this chunk)
- **Total new files**: 3
