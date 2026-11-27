---
name: wp-blockwright
description: Builds WordPress FSE sites as an AI agent — a child theme (on Ipsum or Twenty Twenty-Five) holding role-named design tokens, header, footer and mobile menu; page sections as owner-editable custom blocks (SCF with inline editing, or WP 7.0 PHP-only) in a small site plugin; and dynamic content via Query Loop + Block Bindings, all deployed through Novamira. Use whenever the user wants to build, port, restyle, or extend a WordPress site with an AI agent — including "build this design on WordPress", converting a static/HTML/Astro site to WordPress, adding a section, page, blog, or custom post type to a WP site, or making sections editable for a site owner.
---

# WP-Blockwright — build WordPress FSE sites as an AI agent

Turns a design (static HTML site, mockup, or brief) into a WordPress site the owner can edit
without code: design tokens, header, footer and mobile menu in a child theme; page sections as
custom blocks the owner edits by clicking and typing; dynamic content (blog, CPT grids, custom
fields) with native blocks. Block code lives in one small site plugin (`<site>-blocks`); all
writes reach the site through Novamira. Every site also gets an AGENTS.md and DESIGN.md so the
next agent can pick up the work without re-deriving it.

Whoever invoked this skill has already chosen the outcome: an editable WordPress site. That
choice is made — don't put it back to the owner as a question.

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

Recommend it; don't insist. Building directly on WordPress is fully supported. Either way,
this choice is only about *where the design is iterated* — the finished site is still built
as editable blocks. If the owner
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
   WordPress ≥ 7.0 (PHP-only blocks need it; 7.2 tested), an FSE **block theme** (Ipsum is
   the recommended parent from WP 7.2; Twenty Twenty-Five below that; classic themes are NOT
   supported), SCF 6.9+ **or** ACF active (SCF 6.9 adds inline editing), required media/fonts
   available. FluentSnippets is optional (small tweaks only).
3. Confirm tooling: Novamira is connected (MCP or CLI — either works, the abilities are the
   same), and a browser-automation tool (Playwright MCP, the agent's built-in browser, or
   similar) is available — it is required to finalize Gutenberg content batches and to check
   the result against the design.
4. **Local or hosted?** If the site's files are on this machine (a Local / localhost / `.local`
   site), deploys are a folder copy and photos import straight from disk; otherwise use the
   zip and upload-link paths. Decide once, now — references/novamira.md, "Local or hosted".

If a plugin is missing, offer to install it (via execute-php — don't assume a wp-cli ability)
— cheap on a staging site the gate has already confirmed. Anything else missing: stop and tell the user what to install or confirm
before building.

## The core mental model: two kinds of section

1. **One-off editable sections** (hero, about, CTA, feature grid) → a **custom block**. Content
   lives in the block instance; the owner edits it in the side panel.
   - Rich fields (image, link/button, WYSIWYG, repeater rows) → **SCF block** (block.json,
     v3) — the default for most sections, because SCF inline editing lets the owner type
     straight into the preview and click images/buttons to change them.
   - Only plain fields (text, number, boolean, dropdown) and no need for in-place typing →
     **WP 7.0 PHP-only block** (`autoRegister`, side-panel only).
2. **Dynamic / repeating sections** (blog lists, CPT card grids, same-layout item pages —
   services, team, projects) → **Query Loop + core post
   blocks**. A single custom-field value inside any block layout → **Block Bindings**
   (`core/post-meta`) on a plain core block — no custom block, no PHP rendering. A listing
   Query Loop can't express (tabs, groups, a featured item) → a custom block that queries the
   entries in PHP (references/dynamic-content.md).

Never place a block that stores its own fields inside a loop — every card would show the same
content. Inside loops, data must come from the *looped post*.

## Model the content, don't just copy the pages

While surveying the source site, ask what each piece of content *is*, not just what pages
exist. Then **ask the owner, in one go before building**, how each kind should be stored —
using the WordPress terms people know, each with a short plain hint, and the organised option
marked *Recommended*. If the request already says (e.g. "team members as a CPT"), that decides
it; don't ask again. Never silently pick either way: these answers shape the whole build.

| Content | Recommend | Plain hint for the owner |
|---|---|---|
| Single values used in many places (phone, email, address, hours, socials) | **Options page** | typed once, every page updates |
| Repeated real things that may show on several pages (services, team members, testimonials, products, locations) | **Custom post type (CPT)** + custom fields | each one its own entry, like a post |
| A list that only lives in one section (FAQ, feature list, steps) | **Repeater field** in the block | a list inside the section |
| One-off text or image in one section (a headline, an intro) | **Custom field** in the block | one editable box in the section |

(A real port silently built three same-layout service pages as separate block pages; adding a
fourth meant hand-copying one.) Recipes:
options page and CPTs in references/dynamic-content.md, block fields in
references/custom-blocks.md.

In the same message, ask the user to **sign in to WordPress in the browser** you'll test with
(only if it isn't already). Draft previews and the editor need it later, and asking now — while
the user is answering anyway — lets building and checking run without waiting for them.

**Anything that submits data** (contact form, newsletter signup, booking, quote request) →
  **not ours to build.** Raise it during the survey, before building anything: the owner creates
  the form in their form plugin (Fluent Forms, WPForms, Contact Form 7, Gravity — whichever
  they already run) and hands back a **shortcode or block name**; you place and style it
  (recipe in references/custom-blocks.md). Flagging it at survey time matters — making the
  form is the owner's homework, so they can do it while you build the rest. Porting the design's
  `<form>` markup instead gives the owner a form that looks perfect and submits nowhere: no
  handler, no spam filter, no entries list, no email. *"I'll just write the handler myself in
  the plugin or a snippet"* is the rationalization to refuse — it strands the owner with a form they
  cannot see or manage from their dashboard. **Search and comments are NOT this**: core ships
  working blocks for both, so place and style those normally. (A login form on a page has no
  core block — that one is plugin side.)

## The two pushes (they are different in kind)

**Push 1 — the block.** Code. A block folder (block.json, render.php, style.css, field group)
describing a Hero block's fields and the HTML it produces. Goes into the **site plugin**,
deployed as a zip. Done once; makes **Hero** appear in the editor's block list. Not attached
to any page. *Like adding a new Lego brick shape to the box.*

**Push 2 — the page.** Data. "The About page contains a Hero with heading X, then a CTA with
text Y." Goes through the **Gutenberg queue** (or the fast lane — references/novamira.md).
*Like actually placing the bricks.*

**Order matters.** Push 1 only → the block exists but nothing uses it. Push 2 first → the
batch fails; WordPress has never heard of `acme/hero`. Always code first, then page.

| Where it lives | Build it with | Data source |
|---|---|---|
| Rich one-off section (image/link/WYSIWYG/repeater) | SCF block (block.json) in the site plugin | block instance fields |
| Simple one-off section | PHP-only block (`autoRegister`) in the site plugin | block instance fields |
| Header / footer / mobile menu | Child theme parts → hidden patterns of core blocks | edited in Site Editor |
| Archive / list / CPT grid | Query Loop + core post blocks | the looped post |
| Single post / CPT page | `wp_template` of core post blocks | the queried post |
| A custom-field value | Block Bindings on a core block | registered post meta |

## Build order

Work through the steps in order. **Skip any step with no matching content** — a one-page site
has no blog: do not invent CPTs or posts to fill the workflow.

```
Build progress:
- [ ] 0. Safety gate + prerequisite check (above)
- [ ] 1. Design system → child theme + theme.json,
        AGENTS.md + DESIGN.md                      → read references/design-system.md
- [ ] 2. Header, footer, mobile menu overlay      → read references/site-frame.md
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
section. It doubles as the end-to-end test that the whole pipeline (plugin deploy, queue, finalizer)
works on this particular site, while one section is still cheap to redo. Then checkpoint with the owner — this is the useful moment,
because they can now see a result: *"Hero's built and looks right. Want the remaining sections
in one go, or one at a time so you can check each?"* (Asking at the start is useless; nobody
can answer it yet.) If the owner is unreachable at the checkpoint, don't stall and don't revert
to batch-everything: continue section by section with per-section verification, keep new pages
as drafts until verified, and record the skipped checkpoint in your report.

Before step 1, read **references/novamira.md** (how every write reaches the site — the zip
deploy, the Gutenberg queue, self-finalization, approvals). Read **references/css-rules.md**
before writing any CSS — where each kind of CSS lives, and WordPress ships none of the resets a
static site's CSS assumes. On Ipsum, read its design guide (`ipsum/get-design-guide` ability)
before designing anything.

## Standing rules (each one earned by a real bug)

- **Author locally first.** Every theme, plugin and stylesheet file has a canonical copy in a
  local `wp-build/` tree (`themes/`, `plugins/`, `tools/`); lint PHP locally before pushing. No
  server-only code, ever.
- **Verify every write.** API success ≠ content landed. After each push, read back a distinctive
  marker string or compare an md5 hash of the stored result. A placeholder once shipped silently
  while the API reported success.
- **Compose content as block JSON** `{name, attributes, innerBlocks}` — never hand-write
  serialized block HTML. The Gutenberg queue is the default write path; a page composed
  ENTIRELY of this skill's own custom blocks may take the `serialize_blocks()` fast lane
  instead (references/novamira.md). Use preset slugs in attributes
  (`backgroundColor: "dark"`, `var:preset|spacing|50`) so the owner gets real editor controls.
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
- **Our own token names, by role, on every site** (`surface`, `ink`, `dark`, `accent`…) — not the
  parent theme's slugs; re-point the parent's styles to them by script (references/design-system.md).
  **Never name a preset `text`, `background`, `link` or `border`** — they collide with core's own
  classes and silently override every custom colour.
- **Reference theme tokens** (`--wp--preset--*`, `--wp--custom--*`) in every stylesheet and inline
  SVG — never hardcode colors, sizes, or spacing — and **give every CSS rule a one-line "why"
  comment**. Global Styles then re-skins everything, and the next agent knows which rules matter.
  Design sizes between tokens round to the nearest one; illustrations keep their own colours.
- **One source per shared look.** Buttons, links and headings come from theme.json elements
  (custom blocks add `wp-element-button`); other shared looks from theme.json custom values;
  only what the plugin's blocks alone share goes in its `common.css` (references/css-rules.md).
- **Don't reformat block markup** in patterns or templates once it works. The editor compares it
  exactly; a tidy-up can turn valid blocks invalid.
- **Keep AGENTS.md and DESIGN.md current** with every change (what lives where, tokens by role,
  generated vs hand-built files).
- **Cross-section anchors are a checklist item.** Every `#anchor` the nav links to must exist on
  the page (set the anchor on the section's wrapper group).
- **Field keys are the contract.** Once a page stores SCF block data, never change the field
  keys — block data references keys, not names.
- **Never hand-build a form that submits data.** The owner's form plugin owns contact,
  newsletter and booking forms — you get a shortcode and style around it; core owns search and
  comments. You never write the part that receives a submission.
- **Set heading levels explicitly.** `core/post-title` and heading-type blocks default to h2;
  archives and single templates need `level: 1`. Give custom heading blocks a level attribute.
- **Name block folders and titles after page sections** (`hero`, `services`, "Hero") with a
  block `description` that tells the owner how to edit it — that's how owners think.
- **Mobile is part of done.** Verify at ~390px, ~700px, and desktop — including the nav overlay
  OPEN — before calling any step complete.

## Reference files

| File | Read it when |
|---|---|
| references/novamira.md | Before the first write — zip deploy, queue, approvals |
| references/design-system.md | Step 1 — parent theme, child theme, tokens by role, AGENTS/DESIGN.md |
| references/site-frame.md | Step 2 — header/footer patterns, logo, menu, overlay, mobile header |
| references/custom-blocks.md | Step 3 — site plugin, SCF blocks with inline editing, PHP-only blocks, page assembly |
| references/dynamic-content.md | Step 5 — posts, custom fields, Query Loop, single template |
| references/css-rules.md | Before writing CSS — where CSS lives, resets, tokens, specificity |
| references/verification.md | Steps 4 & 6 — the QA recipes that actually catch bugs |
| references/fluentsnippets.md | Only if adding a small non-block tweak as a snippet |
| scripts/write-blocks.php | Writing SCF block content to a page — turns plain values into SCF's stored format |
