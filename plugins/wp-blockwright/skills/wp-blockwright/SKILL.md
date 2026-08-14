---
name: wp-blockwright
description: Builds WordPress FSE sites as an AI agent — design tokens in a child theme's theme.json, header/footer template parts, page sections as owner-editable custom blocks (WP 7.0 PHP-only or SCF), and dynamic content via Query Loop + Block Bindings, pushed through Novamira with code hosted in FluentSnippets. Use whenever the user wants to build, port, restyle, or extend a WordPress site with an AI agent — including "build this design on WordPress", converting a static/HTML/Astro site to WordPress, adding a section, page, blog, or custom post type to a WP site, or making sections editable for a site owner.
---

# WP-Blockwright — build WordPress FSE sites as an AI agent

Turns a design (static HTML site, mockup, or brief) into a WordPress site the owner can edit
without code: design tokens in a child theme, header/footer as template parts, page sections as
custom blocks with side-panel fields, dynamic content (blog, CPT grids, custom fields) with
native blocks. All writes reach the site through Novamira; custom code is hosted in
FluentSnippets so the owner can see and manage it from the dashboard.

## First: which job is this? Survey, then ask — don't infer

Look at what's running (front page, pages, active theme) before anything else. Two supported
jobs, and they diverge immediately:

- **New site** (empty or throwaway) → recommend the static-HTML-first flow below, agree the
  design system, then port.
- **Existing site** → skip the design conversation entirely; go straight to the safety gate.

**If the request doesn't match what's running — say, a bakery homepage requested on a live
dental-clinic site — STOP and ask which the owner means: replace the current site, add a new
page alongside it, or is the wrong site connected?** Do not answer this yourself. "I trust
you, just build it" answers none of those three questions — a real agent under exactly that
instruction began converting a finished client site to another business's branding (global
palette, front page, site title) without ever asking.

## Building a whole site from scratch? Recommend static HTML first

**Only for a brand-new site with no design yet.** Porting an existing design, adding a section,
restyling, or extending a live site — skip this, go straight to the safety gate.

Every design change made in WordPress costs a write, a batch finalize, a browser session and a
screenshot pass to check it. The same change in a static HTML page costs a file save and a
refresh. So say that out loud and let the owner choose: settle layout, type scale, spacing and
colour in plain HTML/CSS where iteration is free, then use this skill to port the finished
design once. The WordPress build becomes a port, not a design session.

Recommend it; don't insist. Building directly on WordPress is fully supported. If the owner
picks that, agree the design system with them first — colours, fonts, spacing scale, section
layouts — and get it signed off **before** writing `theme.json`, because from that point on
every change carries the WordPress cost above.

## Before ANY write: the safety gate

1. **The owner must explicitly confirm, in this conversation, that the target is a staging
   site AND backups are on. No confirmation → no writes. Not one.** Stop and report that the
   work is blocked on those two answers. Never assume, never build on production —
   `execute-php` runs arbitrary PHP; it is not a security sandbox. The rationalizations that
   break this gate are known; every one below was recorded verbatim from a real agent that
   then wrote to a site this gate should have protected:
   - *"The owner said 'just get it live' / 'I trust you, just build it'"* — an instruction to
     build is NOT a staging-and-backups confirmation. Ask the two questions.
   - *"The owner is unreachable, so I proceeded under the end-to-end authorization"* — an
     unreachable owner means the gate cannot pass. Stop; report what you're blocked on.
   - *"My writes are only additive / it's a tiny change"* — additive writes still run
     arbitrary PHP on someone's site, and still need the gate.
2. Only after the gate passes: run ONE read-only prerequisite check via `execute-php` —
   WordPress ≥ 7.0 (PHP-only blocks need it), an FSE **block theme** active (any block theme
   works; Twenty Twenty-Five is the tested default and the recommendation for new sites;
   classic themes are NOT supported), FluentSnippets active, SCF **or** ACF active (either
   works), required media/fonts already uploaded.
3. Confirm tooling: Novamira is connected (MCP or CLI — either works, the abilities are the
   same), and a browser-automation tool (Playwright MCP, the agent's built-in browser, or
   similar) is available — it is required to finalize Gutenberg content batches.

If a plugin is missing, offer to install it via wp-cli — cheap on a staging site the gate has
already confirmed. Anything else missing: stop and tell the user what to install or confirm
before building.

## The core mental model: two kinds of section

1. **One-off editable sections** (hero, about, CTA, feature grid) → a **custom block**. Content
   lives in the block instance; the owner edits it in the side panel.
   - Simple fields (text, number, boolean, dropdown) → **WP 7.0 PHP-only block** (`autoRegister`).
   - Rich fields (image picker, WYSIWYG, repeater rows) → **SCF block**.
2. **Dynamic / repeating sections** (blog lists, CPT card grids, same-layout item pages —
   services, team, projects) → **Query Loop + core post
   blocks**. A single custom-field value inside any block layout → **Block Bindings**
   (`core/post-meta`) on a plain core block — no custom block, no PHP rendering.

Never place a block that stores its own fields inside a loop — every card would show the same
content. Inside loops, data must come from the *looped post*.

## Model the content, don't just copy the pages

While surveying the source site, ask what each piece of content *is*, not just what pages
exist. When a better model applies, recommend it and **check with the owner before
deciding** — never silently pick either way:

- **Items of the same kind sharing one layout** (services, team members, projects,
  locations…) → a **CPT + custom fields** (step 5, references/dynamic-content.md): one
  template keeps them identical, the listing updates itself via Query Loop, the owner adds
  the next item by filling in fields. (A real port silently built three same-layout service
  pages as separate block pages; adding a fourth now means hand-copying one.)

- **Global values repeated across pages** (contact email, phone, address, socials) → an
  **SCF options page**, pulled into blocks via Block Bindings — the owner changes them
  once, every page updates (recipe in references/dynamic-content.md).

## The two pushes (they are different in kind)

**Push 1 — the block.** Code. A PHP file describing a Hero block's fields and the HTML it
produces. Goes to **FluentSnippets**. Done once; makes **Hero** appear in the editor's block
list. Not attached to any page. *Like adding a new Lego brick shape to the box.*

**Push 2 — the page.** Data. "The About page contains a Hero with heading X, then a CTA with
text Y." Goes through the **Gutenberg queue** (or the fast lane — references/novamira.md).
*Like actually placing the bricks.*

**Order matters.** Push 1 only → the block exists but nothing uses it. Push 2 first → the
batch fails; WordPress has never heard of `acme/hero`. Always code first, then page.

| Where it lives | Build it with | Data source |
|---|---|---|
| Simple one-off section | PHP-only block (`autoRegister`) in FluentSnippets | block instance fields |
| Rich one-off section (image/WYSIWYG/repeater) | SCF block | block instance fields |
| Header / footer | Template parts of core blocks | edited in Site Editor |
| Archive / list / CPT grid | Query Loop + core post blocks | the looped post |
| Single post / CPT page | `wp_template` of core post blocks | the queried post |
| A custom-field value | Block Bindings on a core block | registered post meta |

## Build order

Work through the steps in order. **Skip any step with no matching content** — a one-page site
has no blog: do not invent CPTs or posts to fill the workflow.

```
Build progress:
- [ ] 0. Safety gate + prerequisite check (above)
- [ ] 1. Design system → child theme + theme.json  → read references/design-system.md
- [ ] 2. Header + footer template parts            → read references/site-frame.md
- [ ] 3. FIRST section end to end — block PHP + CSS,
        on the page, verified — then the owner
        checkpoint, then the remaining sections    → read references/custom-blocks.md
- [ ] 4. Verify vs the design (3 viewports, editor
        editability)                               → read references/verification.md
- [ ] 5. Content + dynamic pages (posts, CPTs,
        custom fields, archive + single templates) → read references/dynamic-content.md
- [ ] 6. Final QA pass                             → references/verification.md again
```

**Step 3 is deliberately not batch-everything.** Build section one completely — its block PHP,
its CSS, placed on the page, verified — before authoring the rest. Concretely: **the first
queue batch for a page contains section one ALONE, and you verify it landed and renders before
queueing the remaining sections.** Authoring everything up front and queueing all sections in
one batch is still batch-everything, whatever your verification granularity — a real agent did
exactly that and reported it as "built section-by-section" because it had *verified* each
section. It doubles as the end-to-end test that the whole pipeline (snippets, queue, finalizer)
works on this particular site, while one section is still cheap to redo. Then checkpoint with the owner — this is the useful moment,
because they can now see a result: *"Hero's built and looks right. Want the remaining sections
in one go, or one at a time so you can check each?"* (Asking at the start is useless; nobody
can answer it yet.) If the owner is unreachable at the checkpoint, don't stall and don't revert
to batch-everything: continue section by section with per-section verification, keep new pages
as drafts until verified, and record the skipped checkpoint in your report.

Before step 1, read **references/novamira.md** (how every write reaches the site — the
Gutenberg queue, self-finalization, upload links) and **references/fluentsnippets.md** (where
all custom PHP/CSS lives and its API gotchas). Read **references/css-rules.md** before writing
any CSS — WordPress ships none of the resets a static site's CSS assumes.

## Standing rules (each one earned by a real bug)

- **Author locally first.** Every snippet, stylesheet, and theme file has a canonical copy in a
  local `wp-build/` tree; lint PHP locally before pushing. No server-only code, ever.
- **Verify every write.** API success ≠ content landed. After each push, read back a distinctive
  marker string or compare an md5 hash of the stored result. A placeholder once shipped silently
  while the API reported success.
- **Compose content as block JSON** `{name, attributes, innerBlocks}` — never hand-write
  serialized block HTML. The Gutenberg queue is the default write path; a page composed
  ENTIRELY of this skill's own custom blocks may take the `serialize_blocks()` fast lane
  instead (references/novamira.md). Use preset slugs in attributes
  (`backgroundColor: "contrast"`, `var:preset|spacing|50`) so the owner gets real editor controls.
- **One prefix, chosen at the start, used in all four places** — block namespace (`acme/hero`),
  PHP function names (`acme_render_hero`), CSS class names (`acme-header`), AND the box-sizing
  reset selector (`[class*="acme-"]`). The silent failure: picking a project prefix for class
  names but copying an example reset selector verbatim — the reset then matches nothing and the
  layout is subtly wrong everywhere with no error.
- **Page content lives in the PAGE, not a template.** Templates only supply the frame
  (header/footer parts + `core/post-content`). Baking sections into a template orphans the page.
- **Port the design's global resets before building sections** (scoped `box-sizing: border-box`,
  `:focus-visible` outline rules). WordPress provides none of them; each missing one surfaces
  later as a mystery bug report from the owner. See references/css-rules.md.
- **Reference theme tokens** (`--wp--preset--*`) in every stylesheet and inline SVG — never
  hardcode colors, sizes, or spacing. Global Styles then re-skins everything.
- **Cross-section anchors are a checklist item.** Every `#anchor` the nav links to must exist on
  the page (set the anchor on the section's wrapper group).
- **Field keys are the contract.** Once a page stores SCF block data, never change the field
  keys — block data references keys, not names.
- **Set heading levels explicitly.** `core/post-title` and heading-type blocks default to h2;
  archives and single templates need `level: 1`. Give custom heading blocks a level attribute.
- **Name FluentSnippets groups after page sections** (`Hero Section`, `Site Frame`, `Blog`) —
  that is how owners think; a generic "blocks" group is unreadable to them.
- **Mobile is part of done.** Verify at ~390px, ~700px, and desktop — including the nav overlay
  OPEN — before calling any step complete.

## Reference files

| File | Read it when |
|---|---|
| references/novamira.md | Before the first write — every recipe for reaching the site |
| references/fluentsnippets.md | Before creating any snippet — API, meta schema, gotchas |
| references/design-system.md | Step 1 — child theme + theme.json + self-hosted fonts |
| references/site-frame.md | Step 2 — template parts, navigation, mobile header |
| references/custom-blocks.md | Step 3 — PHP-only blocks, SCF blocks, page assembly |
| references/dynamic-content.md | Step 5 — posts, custom fields, Query Loop, single template |
| references/css-rules.md | Before writing CSS — resets, specificity vs core, token usage |
| references/verification.md | Steps 4 & 6 — the QA recipes that actually catch bugs |
