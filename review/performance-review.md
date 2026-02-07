# Performance Reviewer — Review Prompt

## Role

You are a performance engineer reviewing LiteCMS. The hard target is **page render under 50ms on modest shared hosting without opcode cache**. Identify bottlenecks and inefficiencies.

## Scope

Review all files under `app/`, `templates/`, `public/`, `config/`, and migration SQL files.

## Checklist

### Database Queries
- [ ] No N+1 query patterns (e.g., looping over content items and querying relations individually)
- [ ] Pagination uses `LIMIT`/`OFFSET` — never fetches all rows
- [ ] Dashboard counts use `COUNT(*)` queries, not fetching and counting in PHP
- [ ] Indexes exist on: `content.slug`, `content.type`, `content.status`, `content.author_id`, `media.uploaded_by`, `users.username`, `users.email`
- [ ] QueryBuilder doesn't run unnecessary queries (e.g., `SELECT *` when only specific columns needed)
- [ ] Migration SQL uses appropriate column types (not TEXT where VARCHAR suffices)

### PHP Performance
- [ ] Config loaded once per request (lazy singleton), not re-read per call
- [ ] No `file_get_contents()` or filesystem reads in hot paths (except template rendering)
- [ ] Template engine uses output buffering efficiently (no nested redundant buffers)
- [ ] No unnecessary object instantiation in loops
- [ ] Autoloader doesn't trigger excessive file lookups

### HTTP & Caching
- [ ] Static assets (CSS, JS, images) served directly by Apache (not through PHP)
- [ ] `.htaccess` rewrite rules don't route static file requests to `index.php`
- [ ] No unnecessary middleware running on every request (e.g., DB queries in middleware that aren't needed for static pages)

### Frontend Performance
- [ ] TinyMCE loaded from CDN (not bundled)
- [ ] Admin CSS + JS total under 50KB (excluding TinyMCE)
- [ ] Public CSS total under 15KB
- [ ] No render-blocking JavaScript in public templates
- [ ] Images referenced in templates have width/height attributes (CLS prevention)

### Memory
- [ ] No loading entire tables into memory (e.g., fetching all content to count)
- [ ] Media uploads use streaming, not `file_get_contents()` on large files
- [ ] Conversation history (AI) has a reasonable size cap

## Measurement

Add timing instrumentation to verify the 50ms target:
```php
// In public/index.php at the very top:
$start = hrtime(true);
// ... at the very end before send():
$elapsed = (hrtime(true) - $start) / 1e6; // milliseconds
```

Test pages to measure:
1. Homepage (`/`) — should be fastest
2. Blog listing with 20+ posts (`/blog`)
3. Single blog post (`/blog/{slug}`)
4. Admin dashboard (`/admin/dashboard`)
5. Content list with 50+ items (`/admin/content`)

## Output Format

```markdown
## Performance Review Report — LiteCMS

### Render Time Measurements
| Page | Time (ms) | Target | Status |
|------|-----------|--------|--------|

### Bottlenecks Found
- [FILE:LINE] Description
  Impact: estimated ms cost
  Fix: ...

### Query Analysis
- Total queries per page load: X
- Slowest query: ...
- Missing indexes: ...

### Asset Sizes
| Asset | Size | Budget | Status |
|-------|------|--------|--------|

### Passed Checks
- ...
```
