# Plan: Integrate Pencil Editor + .pen Format into LiteCMS

## Context

LiteCMS currently has a form-based page builder with 8 seed elements, slot-based content editing, and a style panel. The editing experience is functional but non-visual -- users fill in form fields and must save/preview to see results.

The **Pencil VS Code Extension** (by High Agency, Inc.) is a Figma-like visual design tool that:
- Uses `.pen` format: an open JSON document with flexbox layout, components, theming, variables
- Offers a full visual editor (layers, canvas, property panels) as a VS Code extension
- Exposes **MCP tools** that allow AI to create/modify designs programmatically
- Has built-in design systems (Shadcn UI, Halo, Lunaris, Nitro)
- Supports code generation guidelines (.pen -> React/Tailwind/HTML)

**Critical discovery:** The Pencil editor is a **self-contained browser SPA** that gets downloaded into VS Code's globalStorage. It consists of:
- `index.html` (1.2KB) -- entry point
- `assets/index.js` (7MB) -- full editor bundle (React-based UI with canvas, layers, properties panels)
- `assets/index.css` (122KB) -- editor styles
- `assets/pencil.wasm` (7.7MB) -- CanvasKit/Skia WASM rendering engine
- `assets/browserAll*.js`, `webworkerAll*.js` -- browser and web worker entries

The VS Code coupling is minimal: just `acquireVsCodeApi().postMessage()` for IPC. Everything else is browser-native (Pixi.js, CanvasKit WASM, standard DOM events).

**The vision:** Embed the Pencil editor directly in LiteCMS's admin as a full Figma-like design tab:
1. AI generates page designs as `.pen` files (using MCP tools or direct JSON)
2. Users visually edit designs in the **embedded browser editor** (no VS Code required)
3. LiteCMS converts `.pen` designs to HTML/CSS for the public site
4. This gives LiteCMS a Figma-like design capability by embedding an existing editor

---

## The .pen Format (Key Concepts)

### Node types
`frame` (container with flexbox), `text`, `rectangle`, `ellipse`, `line`, `polygon`, `path`, `icon_font`, `group`, `ref` (component instance), `note`, `prompt`, `context`

### Layout model
CSS flexbox: `layout: "horizontal"|"vertical"|"none"`, `gap`, `padding` (1/2/4 values), `justifyContent`, `alignItems`. Sizing: `"fill_container"`, `"fit_content"`, or pixel numbers.

### Styling
`fill` (color hex, gradient, image), `stroke` (align, thickness per-side, dash pattern), `effect` (blur, shadow inner/outer), `cornerRadius`, `opacity`

### Typography
`fontFamily`, `fontSize`, `fontWeight`, `fontStyle`, `letterSpacing`, `lineHeight`, `textAlign`, `textGrowth` ("auto"|"fixed-width"|"fixed-width-height")

### Components
Any node with `reusable: true` is a component. Instances are `type: "ref"` pointing to component `id`. Override properties via `descendants` map with slash-separated ID paths. `slot` marks frames designed for child insertion.

### Variables/Themes
Design tokens: `$--primary`, `$--background`, `$--font-primary`, etc. Theme axes: `{ "mode": ["light", "dark"] }`. Variables can have different values per theme combination.

---

## Phase 1: Embed Pencil Editor in LiteCMS Admin

**Impact: CRITICAL | Gives LiteCMS a Figma-like visual editor**

Port the Pencil editor SPA to run standalone in LiteCMS's admin, replacing the VS Code webview host with our own.

### Source location

The editor SPA is downloaded by the VS Code extension to:
`%APPDATA%/Code/User/globalStorage/highagency.pencildev/editor/`

Files to copy to `public/assets/pencil-editor/`:
- `index.html` -- SPA entry point
- `assets/index.js` (7MB) -- full editor bundle
- `assets/index.css` (122KB) -- styles
- `assets/pencil.wasm` (7.7MB) -- CanvasKit/Skia WASM
- `assets/browserAll.js`, `browserAll2.js` -- browser worker
- `assets/webworkerAll.js`, `webworkerAll2.js` -- web worker

### What needs to change

The VS Code webview injection script (function `J9e` in the extension) does 5 things:
1. Rewrites `./assets/` URLs to VS Code webview URIs -- **not needed** (we serve directly)
2. Strips `crossorigin` attributes -- **not needed** (same-origin)
3. Injects CSP meta tag -- **replace** with our own CSP
4. Pre-loads `pencil.wasm` as `window.canvaskitWasm = new Uint8Array([...])` -- **replace** with `fetch()` loading from our server
5. Injects `acquireVsCodeApi()` bridge -- **replace** with our IPC bridge

### The IPC Bridge

The editor communicates via `window.postMessage()` with `source: 'application'` (editor -> host) and `source: 'vscode-extension'` (host -> editor). Messages follow the `IPCMessage` protocol: `{ id, type: "request"|"response"|"notification", method, payload }`.

Key IPC methods the editor calls (that we must handle):
- `save(path)` -- save .pen content to a file
- `file-update` notification -- reload file content
- `undo`/`redo` notifications -- undo/redo state changes
- `add-to-chat` -- send text to AI assistant (we can integrate with our AI)

Key IPC methods the host calls on the editor:
- `color-theme-changed` -- notify of light/dark theme change
- `file-update` -- push new file content to editor

### Files to create

| File | Purpose | ~Lines |
|---|---|---|
| `public/assets/pencil-editor/` | Directory containing the copied editor SPA bundle | N/A |
| `public/assets/js/pencil-bridge.js` | IPC bridge: replaces `acquireVsCodeApi()`. Intercepts `postMessage` from editor, routes `save`/`load` requests to PHP backend via `fetch()`. Provides `window.vscodeapi` mock. | ~200 |
| `templates/admin/design/editor.php` | Full-page admin template that hosts the editor in an iframe or directly embeds it. Passes the .pen file path and content. | ~100 |
| `app/Admin/DesignController.php` | PHP backend for file I/O: `GET /admin/design/load`, `POST /admin/design/save`, `POST /admin/design/import-file`, `GET /admin/design/fonts/*`. | ~200 |

### How the bridge works

```
┌─────────────────────────────────────────────────────┐
│ LiteCMS Admin Page                                   │
│                                                      │
│  ┌─────────────────────────────────────────────┐    │
│  │ <iframe src="pencil-editor/index.html">     │    │
│  │                                              │    │
│  │   Pencil Editor SPA                          │    │
│  │   (index.js + pencil.wasm + pixi.js)         │    │
│  │                                              │    │
│  │   postMessage({source:'application',...})  ──────> │
│  │                                              │    │
│  └──────────────────────────────────────────────┘    │
│                                                      │
│  pencil-bridge.js listens for postMessage            │
│    ├── save(path) → fetch('/admin/design/save')      │
│    ├── file-update → fetch('/admin/design/load')     │
│    └── add-to-chat → LiteCMS AI assistant            │
│                                                      │
│  DesignController.php                                │
│    ├── saves .pen JSON to designs/ folder            │
│    ├── loads .pen JSON from designs/ folder           │
│    └── handles image import to uploads/              │
└─────────────────────────────────────────────────────┘
```

### Modifications to index.html

Create a patched version of `index.html` that:
1. Removes the CSP meta tag (or replaces with LiteCMS-appropriate one)
2. Adds `<script src="/assets/js/pencil-bridge.js"></script>` before the editor script
3. The bridge script:
   - Creates `window.vscodeapi = { postMessage: (msg) => parent.postMessage(msg, '*') }`
   - Sets `window.PENCIL_APP_NAME = "LiteCMS"`
   - Loads WASM via `fetch('/assets/pencil-editor/assets/pencil.wasm')` and sets `window.canvaskitWasm`
   - Provides `window.VSCODE_WEBVIEW_BASE_URI = '/assets/pencil-editor/'`

### Increments

1. **Copy editor bundle** -- Copy SPA files, verify they load standalone in browser
2. **Create bridge script** -- Replace `acquireVsCodeApi()` with mock, handle postMessage
3. **Create DesignController** -- File save/load endpoints
4. **Create editor template** -- Admin page that embeds the editor iframe
5. **Test full loop** -- Create .pen file in editor, save via PHP, reload, verify persistence
6. **Wire "add-to-chat"** -- Connect editor's AI prompt button to LiteCMS AI assistant

---

## Phase 2: .pen-to-HTML Converter (The Critical Bridge)

**Impact: CRITICAL | Enables public rendering of .pen designs**

Build a PHP class that reads `.pen` JSON and generates semantic HTML + CSS.

### Mapping: .pen properties -> CSS

| .pen property | CSS output |
|---|---|
| `layout: "horizontal"` | `display: flex; flex-direction: row;` |
| `layout: "vertical"` | `display: flex; flex-direction: column;` |
| `layout: "none"` | `position: relative;` (children get `position: absolute`) |
| `gap: 16` | `gap: 16px;` |
| `padding: [24, 16]` | `padding: 24px 16px;` |
| `padding: [10, 20, 10, 20]` | `padding: 10px 20px 10px 20px;` |
| `justifyContent: "space_between"` | `justify-content: space-between;` |
| `alignItems: "center"` | `align-items: center;` |
| `width: "fill_container"` | `flex: 1; min-width: 0;` |
| `width: "fit_content"` | `width: fit-content;` |
| `width: 400` | `width: 400px;` |
| `fill: "#FF5C00"` | `background-color: #FF5C00;` |
| `fill: { type: "gradient", ... }` | `background: linear-gradient(...);` |
| `fill: { type: "image", url, mode }` | `background-image: url(...); background-size: cover/contain;` |
| `stroke: { thickness: 1, fill: "#ccc" }` | `border: 1px solid #ccc;` |
| `stroke: { thickness: { bottom: 1 }, ... }` | `border-bottom: 1px solid #ccc;` |
| `cornerRadius: 8` | `border-radius: 8px;` |
| `cornerRadius: [8, 8, 0, 0]` | `border-radius: 8px 8px 0 0;` |
| `opacity: 0.5` | `opacity: 0.5;` |
| `clip: true` | `overflow: hidden;` |
| `effect: { type: "shadow", ... }` | `box-shadow: ...;` |
| `effect: { type: "blur", radius: 4 }` | `filter: blur(4px);` |
| `effect: { type: "background_blur" }` | `backdrop-filter: blur(...)` |
| Text `fill: "#000"` | `color: #000;` |
| `fontFamily: "Inter"` | `font-family: 'Inter', sans-serif;` |
| `fontSize: 14` | `font-size: 14px;` |
| `fontWeight: "700"` | `font-weight: 700;` |
| `textAlign: "center"` | `text-align: center;` |
| `lineHeight: 1.5` | `line-height: 1.5;` |
| `letterSpacing: 0.5` | `letter-spacing: 0.5px;` |

### Node type -> HTML element mapping

| .pen type | HTML output |
|---|---|
| `frame` (top-level) | `<section>` or `<div>` |
| `frame` (nested, with layout) | `<div>` |
| `frame` (with `name` containing "header") | `<header>` |
| `frame` (with `name` containing "nav") | `<nav>` |
| `frame` (with `name` containing "footer") | `<footer>` |
| `text` | `<p>`, `<h1>`-`<h6>` (inferred from fontSize/fontWeight), or `<span>` |
| `rectangle` | `<div>` (styled box) |
| `ellipse` | `<div>` with `border-radius: 50%` |
| `icon_font` | `<i>` with icon font class or SVG icon |
| `ref` | Resolved to its component, rendered as a `<div>` with component class |
| `path` | `<svg>` with the path geometry |
| `image` (fill type) | `<div>` or `<img>` with background-image |
| `group` | `<div>` |

### Component resolution
- Walk the node tree
- When encountering `type: "ref"`, find the referenced component by `ref` ID
- Deep-clone the component's node tree
- Apply root overrides from the `ref` node
- Apply `descendants` overrides using slash-path keys
- Render the resolved tree as HTML

### Variable resolution
- Collect all `$--variable` references
- Replace with CSS custom properties: `$--primary` -> `var(--primary)`
- Generate `:root` block from document `variables`
- Support theme axes via `[data-theme="dark"]` selectors

### Files to create

| File | Purpose | ~Lines |
|---|---|---|
| `app/PageBuilder/PenConverter.php` | Main converter: `convertFile($penPath)` -> HTML+CSS string. Methods: `parseDocument()`, `resolveComponents()`, `renderNode()`, `nodeToHtml()`, `nodeToCSS()`, `resolveVariables()` | ~500 |
| `app/PageBuilder/PenNodeRenderer.php` | Per-node-type rendering: `renderFrame()`, `renderText()`, `renderIconFont()`, `renderRef()`, `renderRectangle()`, `renderPath()`. Each returns `{html, css}` | ~400 |
| `app/PageBuilder/PenStyleBuilder.php` | Property-to-CSS conversion: `buildFill()`, `buildStroke()`, `buildEffect()`, `buildLayout()`, `buildTypography()`, `buildSizing()` | ~300 |

### Files to modify

| File | Changes |
|---|---|
| `app/PageBuilder/PageRenderer.php` | Add `renderFromPen($penFilePath, $contentId)` method that uses PenConverter to render a .pen file, wrapping output in the standard page structure |
| `app/Templates/FrontController.php` | Add route/logic to detect when a content item uses a .pen design file, call PenConverter instead of slot-based rendering |
| `templates/public/layout.php` | Support injecting PenConverter's CSS output alongside existing element CSS |

### Increments

1. **PenStyleBuilder** -- Convert individual properties to CSS (fill, stroke, layout, typography, effects). Unit test with sample property objects.
2. **PenNodeRenderer** -- Render individual node types (frame, text, rectangle). Test with simple .pen snippets.
3. **PenConverter** -- Full document conversion: parse JSON, walk tree, collect CSS, output HTML. Test with a simple 2-frame .pen file.
4. **Component resolution** -- Handle `ref` nodes: clone, apply overrides, render. Test with a .pen file containing reusable components.
5. **Variable resolution** -- Replace `$--var` references with CSS custom properties, generate `:root` block. Test with themed variables.
6. **Integration** -- Wire PenConverter into PageRenderer and FrontController. Test rendering a .pen design as a full public page.

---

## Phase 3: LiteCMS Design System as .pen File

**Impact: HIGH | Creates the component library AI will use**

Create a `.pen` file containing reusable components that represent LiteCMS's page elements, designed to work with the converter.

### Components to create (matching LiteCMS seed elements)

| Component | .pen structure | Slots |
|---|---|---|
| `Hero Section` | Frame (vertical, full-width, padding 64, bg image) > Frame (centered, max-width) > Heading text + Subheading text + CTA button frame | heading, subheading, cta_text, cta_url, bg_image |
| `Text Section` | Frame (vertical, padding 48) > Heading text + Body richtext | heading, body |
| `Feature Grid` | Frame (vertical, padding 48) > Heading + Frame (horizontal, gap 24, wrap) > Feature cards (icon + title + description) | heading, features[] |
| `CTA Banner` | Frame (horizontal, padding 32, bg color, rounded) > Text frame + Button frame | heading, body, cta_text, cta_url |
| `Image + Text` | Frame (horizontal, gap 32, padding 48) > Image frame + Text frame (heading + body) | image, heading, body, layout_direction |
| `Testimonial Section` | Frame (vertical, padding 48) > Heading + Grid of testimonial cards (quote + author + role) | heading, testimonials[] |
| `FAQ Section` | Frame (vertical, padding 48) > Heading + FAQ items (question + answer) | heading, faqs[] |
| `Footer` | Frame (horizontal, padding 32, bg dark) > Logo/copyright + Nav links + Social icons | copyright, links[], socials[] |

### Design tokens (variables)

```json
{
  "themes": { "mode": ["light", "dark"] },
  "variables": {
    "--primary": { "type": "color", "value": [
      { "value": "#3b82f6", "theme": { "mode": "light" } },
      { "value": "#60a5fa", "theme": { "mode": "dark" } }
    ]},
    "--background": { "type": "color", "value": [
      { "value": "#ffffff", "theme": { "mode": "light" } },
      { "value": "#0f172a", "theme": { "mode": "dark" } }
    ]},
    "--foreground": { "type": "color", "value": [
      { "value": "#0f172a", "theme": { "mode": "light" } },
      { "value": "#f8fafc", "theme": { "mode": "dark" } }
    ]},
    "--muted-foreground": { "type": "color", "value": "#64748b" },
    "--card": { "type": "color", "value": "#ffffff" },
    "--border": { "type": "color", "value": "#e2e8f0" },
    "--font-primary": { "type": "string", "value": "Inter" },
    "--font-secondary": { "type": "string", "value": "Inter" },
    "--radius-m": { "type": "number", "value": 8 },
    "--radius-pill": { "type": "number", "value": 9999 },
    "--spacing-section": { "type": "number", "value": 64 },
    "--spacing-content": { "type": "number", "value": 32 },
    "--max-width": { "type": "number", "value": 1200 }
  }
}
```

### Files to create

| File | Purpose |
|---|---|
| `designs/litecms-system.pen` | The design system .pen file with all reusable components + variables. Created using Pencil MCP tools (batch_design). |
| `designs/README.md` | Documents the component library: component names, IDs, slot structure, how to create pages |

### Workflow
- Use Pencil MCP tools to create the design system file programmatically
- Each component is `reusable: true` with `slot` markers for content insertion points
- Use `$--variable` references for all colors, fonts, spacing
- Place components on the canvas side-by-side for visual browsing
- Test by creating a sample page that references these components

### Increments

1. Create the .pen file with variables/themes
2. Build structural components (Hero, Text Section, CTA Banner) as reusable frames
3. Build data-driven components (Feature Grid, Testimonials, FAQ) with slot patterns
4. Build navigation components (Header, Footer)
5. Create a sample page layout using the components, verify it converts to HTML via Phase 2

---

## Phase 4: AI Design Pipeline

**Impact: HIGH | Enables AI-to-visual-design workflow**

Extend LiteCMS's AI assistant to generate `.pen` designs using the component library.

### How it works

1. User requests a page via LiteCMS AI chat (existing `ai-assistant.js` / `AIController.php`)
2. AI generates a `.pen` file using:
   - The LiteCMS design system components (Phase 3)
   - Pencil MCP tools (`batch_design`) for programmatic creation
   - Or direct JSON generation following the .pen schema
3. The `.pen` file is saved in the `designs/` folder
4. LiteCMS converts it to HTML/CSS via PenConverter (Phase 2)
5. User can open the `.pen` file in the embedded Pencil editor for visual editing
6. After edits, LiteCMS re-converts to HTML

### Integration with existing AI system

| Existing file | Changes |
|---|---|
| `app/AIAssistant/AIController.php` | Add `generateDesign()` action that: (a) sends page requirements to AI with the .pen schema + component library as context, (b) receives .pen JSON, (c) saves to `designs/pages/{slug}.pen`, (d) converts via PenConverter, (e) stores HTML in content body |
| `public/assets/js/page-generator.js` | Add "Visual Design" mode alongside existing "HTML" and "Elements" modes. When selected, AI generates a .pen file. Show preview via PenConverter endpoint. Add "Open in Editor" button. |
| `public/assets/js/ai-assistant.js` | Support `.pen` code blocks in AI responses. Auto-save to designs folder. Show preview. |
| `app/Admin/ContentController.php` | Add `GET/POST /admin/content/{id}/design` routes: GET returns current .pen file, POST accepts .pen JSON and re-renders. Add field to content table for `design_file` path. |

### AI prompt context

When generating designs, provide the AI with:
- The .pen schema (from `get_editor_state` with `include_schema=true`)
- The component library IDs and structure (from `batch_get` on litecms-system.pen)
- The variable definitions (from `get_variables`)
- The user's content requirements (title, sections, style preferences)

### Increments

1. Add `design_file` column to content table (migration)
2. Add PenConverter preview endpoint (`POST /admin/content/preview-pen` -- accepts .pen JSON, returns rendered HTML)
3. Add "Visual Design" mode to page generator wizard
4. Integrate AI .pen generation with component library context
5. Add "Open in Editor" button that opens the .pen file in embedded editor
6. Add re-convert workflow: after editor save, re-render HTML

---

## Phase 5: Admin Integration & Preview

**Impact: HIGH | Makes the workflow seamless**

### Live preview in admin

| File | Changes |
|---|---|
| `templates/admin/content/edit.php` | Add "Design" tab alongside HTML/Elements. When active, shows: (a) iframe with embedded Pencil editor, (b) iframe preview of converted .pen design, (c) "Re-convert" button, (d) design file path display |
| `public/assets/js/page-builder-init.js` | Handle "design" editor mode. Load preview iframe. Watch for .pen file changes (poll or SSE). |
| `public/assets/css/admin.css` | Styles for design mode: preview panel, action buttons, file status indicator |

### Design file management

| File | Changes |
|---|---|
| `app/Admin/ContentController.php` | CRUD for design files: upload .pen, download .pen, delete .pen, re-convert to HTML. Store converted HTML in content body field for fast serving. |
| New: `app/Admin/DesignController.php` | Design file browser: list .pen files in `designs/`, preview thumbnails (via PenConverter), create new from template, duplicate existing |

### Public rendering

| File | Changes |
|---|---|
| `app/Templates/FrontController.php` | When serving a content item with `design_file`: use pre-converted HTML from content body (fast path). If body is empty or stale, convert on-the-fly via PenConverter. |
| `templates/public/layout.php` | Inject PenConverter's CSS variables into `<style>` block. Support theme switching via `data-theme` attribute. |

### Increments

1. "Design" tab in content editor with iframe preview
2. "Edit in Pencil" button (opens .pen file in VS Code via `vscode://file/...` URI)
3. Re-convert button with success/error feedback
4. Design file browser page
5. Public rendering with pre-converted HTML fast path

---

## Phase 6: Template System & Theme Integration

**Impact: MEDIUM | Extends design system to site-wide theming**

### Layout templates as .pen files

Currently layout templates are database records with header/footer config and block definitions. Extend to support `.pen`-based layouts.

| File | Changes |
|---|---|
| `app/Admin/LayoutController.php` | Add option to assign a .pen file as a layout template. When selected, PenConverter renders the template with content slots. |
| `app/Admin/SettingsController.php` | Add design system settings: choose active .pen design system file, configure theme axis values (light/dark mode), override variable values from admin UI. |

### Variable-to-settings bridge

- Read variables from the active design system .pen file
- Display color/font/spacing variables as editable fields in Settings
- Save overrides to `settings` table
- PenConverter reads overrides and injects them into CSS `:root`

### Site-wide theme switching

- Support `?theme=dark` query parameter or cookie-based switching
- PenConverter generates CSS for each theme axis value
- Public layout uses `data-theme` attribute to activate theme CSS

### Increments

1. Layout template .pen file assignment
2. Settings page: display/edit design variables from .pen file
3. Variable override persistence and injection
4. Theme switching (light/dark) in public layout
5. Per-page theme override support

---

## Risk Mitigation

The Pencil editor has a **proprietary commercial license** (High Agency, Inc.). For personal use this is fine. The `.pen` format is JSON with a fully documented schema.

### What we own
- **The .pen JSON schema** -- copied into `schemas/pen.schema.json`. This is a JSON format description.
- **PenConverter.php** -- our PHP converter. Works without the editor.
- **The design system .pen files** -- JSON files we create.
- **The AI pipeline** -- AI generates .pen JSON following the schema.
- **pencil-bridge.js** -- our IPC bridge. Original code.
- **DesignController.php** -- our PHP backend. Original code.

### What we embed (Pencil editor, proprietary, personal use)
- The editor SPA bundle (index.js, index.css, pencil.wasm, workers)
- Copied from the extension's globalStorage, served from our web server

### If the editor becomes unavailable in the future
1. **The embedded copy still works** -- we have the SPA files in our repo
2. **AI still generates .pen files** -- schema is documented, AI follows it
3. **PenConverter still renders** -- reads JSON, outputs HTML
4. **Existing page builder still works** -- .pen mode is an enhancement, not a replacement
5. **HTML output is always preserved** -- dual storage in content table

### Concrete actions
- Copy `pen.schema.json` and `generated-types-public.ts` into `schemas/` during Phase 1
- Always store converted HTML alongside .pen source (dual storage)
- Keep the existing slot-based page builder as the default editor mode
- Version-pin the editor SPA bundle in `public/assets/pencil-editor/`

---

## What NOT to Build

| Temptation | Why skip |
|---|---|
| Build a canvas editor from scratch | The Pencil editor SPA already has a full Figma-like editor. We embed it, not rebuild it. |
| Port the VS Code extension host | We only need the browser SPA (webview side). The extension host manages VS Code integration we don't need. |
| Replace existing page builder entirely | The slot-based page builder still works for simple edits. .pen design is an additional, more powerful mode. |
| Copy the Evolus Pencil desktop app code | Wrong product entirely. The Pencil VS Code extension is the one with the .pen format. |

---

## Architecture Summary

```
┌─────────────────────────────────────────────────────────┐
│                  LiteCMS Admin                            │
│                                                          │
│  ┌──────────┐    ┌──────────────────────────────────┐   │
│  │ AI Chat  │───>│ Embedded Pencil Editor (iframe)   │   │
│  │ (Claude) │    │ (index.js + pencil.wasm + pixi)   │   │
│  └──────────┘    │                                    │   │
│                  │  Canvas + Layers + Properties       │   │
│                  └──────────┬───────────────────────┘   │
│                             │ postMessage (IPC)          │
│                             v                            │
│                  ┌──────────────────┐                    │
│                  │ pencil-bridge.js │                    │
│                  │ (IPC -> fetch)   │                    │
│                  └────────┬─────────┘                    │
│                           │ HTTP                         │
│                           v                              │
│                  ┌──────────────────┐                    │
│                  │ DesignController │                    │
│                  │ (save/load .pen) │                    │
│                  └────────┬─────────┘                    │
│                           │                              │
│                  designs/*.pen files                      │
│                           │                              │
│                           v                              │
│                  ┌──────────────────┐                    │
│                  │ PenConverter.php │                    │
│                  │ (.pen -> HTML+CSS)│                    │
│                  └────────┬─────────┘                    │
│                           │                              │
│                           v                              │
│                  ┌──────────────────┐                    │
│                  │ Public website   │                    │
│                  │ (HTML + CSS)     │                    │
│                  └──────────────────┘                    │
└─────────────────────────────────────────────────────────┘

Data flow:
  AI generates .pen  ──>  Saved to designs/ folder
  User opens embedded editor ──> editor loads .pen from server
  User edits visually ──> save triggers .pen update via PHP
  PenConverter reads .pen ──> Outputs HTML + CSS
  PageRenderer serves HTML ──> Public website
  Admin shows preview ──> iframe with converted HTML
```

---

## Dependency Map

```
Phase 1 (Embed Editor)        ─── MUST be first (gives users the visual editor)
Phase 2 (PenConverter)         ─── after Phase 1 (needs .pen files to convert)
Phase 3 (Design System .pen)   ─── after Phase 1 (create components in editor)
Phase 4 (AI Pipeline)          ─── after Phase 2 + 3 (needs converter + components)
Phase 5 (Admin Integration)    ─── after Phase 1 + 2 (needs editor + converter)
Phase 6 (Templates & Themes)   ─── after Phase 2 + 3
```

Phase 2 and Phase 3 can run in parallel after Phase 1.
Phase 5 can run in parallel with Phase 4 once Phase 2 is complete.

---

## Verification

### Phase 1 (Embed Editor)
1. Copy editor SPA to `public/assets/pencil-editor/`
2. Navigate to `http://localhost/admin/design/editor` -- editor loads in iframe
3. Create frames, text, shapes in the visual editor
4. Click save -- .pen JSON persists to `designs/` folder
5. Reload page -- design reloads from server
6. Verify WASM loads (Skia rendering works, not blank canvas)
7. Verify keyboard/mouse interaction (select, move, resize, zoom/pan)

### Phase 2 (Converter)
1. Take a .pen file created in Phase 1
2. Run PenConverter, verify HTML output renders similar to editor canvas
3. Test component resolution with `ref` instances
4. Test variable replacement generates correct CSS custom properties
5. `php tests/run-all.php --full` for regression

### Phase 3 (Design System)
1. Create components in the embedded editor
2. Mark them as reusable
3. Create a page using component instances
4. Convert via PenConverter, verify HTML output

### Phase 4 (AI Pipeline)
1. Use AI chat to generate a page, verify .pen file is valid
2. Open .pen in embedded editor, verify it looks correct
3. Edit in editor, re-convert, verify HTML updates

### Phase 5 (Admin)
1. Create content item in "Design" mode
2. Preview shows converted HTML
3. Editor tab shows embedded Pencil editor
4. "Re-convert" reflects changes

---

## Key Source Files Reference

### Pencil Editor SPA (copy from)
- Editor SPA: `%APPDATA%/Code/User/globalStorage/highagency.pencildev/editor/` (index.html + assets/)
- `.pen` schema: `~/.vscode/extensions/highagency.pencildev-0.6.23/node_modules/@ha/schema/src/generated-types-public.ts`
- IPC types: `~/.vscode/extensions/highagency.pencildev-0.6.23/node_modules/@ha/shared/src/ipc-types.ts`
- IPC host: `~/.vscode/extensions/highagency.pencildev-0.6.23/node_modules/@ha/shared/src/ipc-host.ts`
- Extension webview setup: `~/.vscode/extensions/highagency.pencildev-0.6.23/out/main-fOH-bhLR.js` (function `J9e`)
- Render tests: `~/.vscode/extensions/highagency.pencildev-0.6.23/node_modules/@ha/pencil-editor/render-tests/*.pen`
- MCP tools: `batch_get`, `batch_design`, `get_editor_state`, `get_screenshot`, `get_variables`, `set_variables`, `snapshot_layout`, `get_guidelines`

### LiteCMS (create new)
- `public/assets/pencil-editor/` -- Copied editor SPA bundle (Phase 1)
- `public/assets/js/pencil-bridge.js` -- IPC bridge replacing VS Code postMessage (Phase 1)
- `templates/admin/design/editor.php` -- Editor hosting page (Phase 1)
- `app/Admin/DesignController.php` -- File I/O backend (Phase 1) + management (Phase 5)
- `app/PageBuilder/PenConverter.php` -- Main converter (Phase 2)
- `app/PageBuilder/PenNodeRenderer.php` -- Per-node HTML rendering (Phase 2)
- `app/PageBuilder/PenStyleBuilder.php` -- Property-to-CSS conversion (Phase 2)
- `designs/litecms-system.pen` -- Design system components (Phase 3)

### LiteCMS (modify)
- `app/PageBuilder/PageRenderer.php` -- Add .pen rendering path (Phase 2)
- `app/Templates/FrontController.php` -- Route .pen-based content (Phase 2)
- `templates/public/layout.php` -- Inject design CSS (Phase 2)
- `app/AIAssistant/AIController.php` -- AI design generation (Phase 4)
- `public/assets/js/page-generator.js` -- Visual design mode (Phase 4)
- `app/Admin/ContentController.php` -- Design file CRUD (Phase 5)
- `templates/admin/content/edit.php` -- Design tab (Phase 5)
- `app/Admin/SettingsController.php` -- Design variable editing (Phase 6)
- `app/Admin/LayoutController.php` -- .pen layout templates (Phase 6)
