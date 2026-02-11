# LiteCMS Design System

Design system file for LiteCMS, containing 8 reusable visual components and 17 design token variables with light/dark theme support.

## File

- **`litecms-system.pen`** — Component library in `.pen` JSON format. All children are `reusable: true` templates; the file produces no rendered HTML on its own.

## Components

| # | Component | ID | Slot Nodes | Background |
|---|---|---|---|---|
| 1 | Hero Section | `hero-section` | `hero-heading`, `hero-subheading`, `hero-cta-text` | `$primary` |
| 2 | Text Section | `text-section` | `text-heading`, `text-body` | `$background` |
| 3 | Feature Grid | `feature-grid` | `feat-heading`, `feat-card-{1-3}-title`, `feat-card-{1-3}-desc` | `$muted` |
| 4 | CTA Banner | `cta-banner` | `cta-heading`, `cta-body`, `cta-button-text` | `$primary` |
| 5 | Image + Text | `image-text` | `imgtext-heading`, `imgtext-body`, `imgtext-image` | `$background` |
| 6 | Testimonials | `testimonial-section` | `testi-heading`, `testi-card-{1-3}-quote/author/role` | `$background` |
| 7 | FAQ Section | `faq-section` | `faq-heading`, `faq-item-{1-3}-q/a` | `$muted` |
| 8 | Footer | `footer-section` | `footer-copyright`, `footer-link-{1-3}`, `footer-social-label` | `$foreground` |

## Design Token Variables

### Colors (themed light/dark)

| Variable | Light | Dark |
|---|---|---|
| `primary` | `#2563eb` | `#3b82f6` |
| `primary-foreground` | `#ffffff` | `#ffffff` |
| `background` | `#ffffff` | `#0f172a` |
| `foreground` | `#0f172a` | `#f8fafc` |
| `muted` | `#f1f5f9` | `#1e293b` |
| `muted-foreground` | `#64748b` | `#94a3b8` |
| `card` | `#ffffff` | `#1e293b` |
| `card-foreground` | `#0f172a` | `#f8fafc` |
| `border` | `#e2e8f0` | `#334155` |
| `accent` | `#f59e0b` | `#f59e0b` |

### Typography

| Variable | Value |
|---|---|
| `font-primary` | `Inter, system-ui, sans-serif` |
| `font-secondary` | `Inter, system-ui, sans-serif` |

### Spacing & Sizing

| Variable | Value |
|---|---|
| `radius-m` | `8` |
| `radius-pill` | `9999` |
| `spacing-section` | `80` |
| `spacing-content` | `40` |
| `max-width` | `1200` |

## Usage

### Creating a page with component instances

Components are used via `ref` nodes. A page document includes the design system children (for the component registry) plus a page frame with `ref` instances:

```json
{
  "children": [
    // ... design system components (reusable: true) ...
    {
      "id": "my-page",
      "type": "frame",
      "name": "My Page",
      "layout": "vertical",
      "width": 1200,
      "children": [
        {"id": "hero-1", "type": "ref", "ref": "hero-section"},
        {"id": "text-1", "type": "ref", "ref": "text-section"},
        {"id": "footer-1", "type": "ref", "ref": "footer-section"}
      ]
    }
  ],
  "variables": { /* design tokens */ }
}
```

### Overriding content via descendants

Use the `descendants` property on a `ref` node to customize slot content:

```json
{
  "id": "hero-custom",
  "type": "ref",
  "ref": "hero-section",
  "descendants": {
    "hero-heading": {"content": "My Custom Title"},
    "hero-subheading": {"content": "My custom tagline"},
    "hero-cta-text": {"content": "Learn More"}
  }
}
```

## Integration with PenConverter

1. **Component Registry** — `PenConverter` scans `children` for nodes with `reusable: true` and registers them by ID. These are not rendered as HTML.
2. **Ref Resolution** — When a `ref` node is encountered, PenConverter deep-clones the referenced component and applies `descendants` overrides.
3. **Variable CSS** — `buildVariableCss()` generates `:root { --primary: #2563eb; ... }` and `[data-theme-mode="dark"] { --primary: #3b82f6; ... }`.
4. **Semantic HTML** — `PenNodeRenderer` infers HTML tags from frame `name` values: "Footer" becomes `<footer>`, names containing "Section" become `<section>`.
5. **Variable References** — `PenStyleBuilder` resolves `$primary` (or `$--primary`) in node properties to `var(--primary)` in CSS output. The `$name` syntax (without `--`) is preferred as it is compatible with both the Pencil visual editor and PenConverter.

## Customization

To rebrand, modify the color variables in `litecms-system.pen`. The variable key is the plain name (e.g., `"primary"`); PenConverter prepends `--` in CSS output. All components reference variables, so changing a token value updates every component that uses it.
