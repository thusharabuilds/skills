# Step 1 — Design system: child theme + theme.json

## Contents
- Pick the parent theme
- Why a child theme (and the ruled-out alternatives)
- Token names: our own, by role
- Re-pointing the parent's styles (generate, don't hand-copy)
- Child theme recipe (files, functions.php, activation)
- What goes in theme.json (v3)
- AGENTS.md, DESIGN.md and the design guide
- Self-hosting Google Fonts
- Site identity
- Verifying step 1

## Pick the parent theme

Any FSE block theme works. Two tested parents:

| Parent | When |
|---|---|
| **Ipsum** (default theme from WP 7.2, Dec 8 2026) | New sites on WP 7.2+. Built for agents: semantic token roles, a `ipsum/get-design-guide` ability, one-line templates that delegate to hidden patterns, full pattern titles/descriptions, its own AGENTS.md. |
| **Twenty Twenty-Five** | Sites below 7.2, or a site already on it. |

Before building on Ipsum, read its design guide so you know what each token is for:
`wp_get_ability('ipsum/get-design-guide')->execute([])` via execute-php. Read the parent's
AGENTS.md too if it ships one.

## Why a child theme

The design system lives in `theme.json`, but editing the parent theme's own file gets wiped by
theme updates. Home for the customized `theme.json`: a **child theme** of the parent. Anything
the child doesn't override falls through to the parent.

Ruled out: style variations (wiped on update outside a child theme); Create Block Theme plugin
(exports a standalone theme, loses parent updates).

## Token names: our own, by role

Use **one systematic set of names you choose**, named by role, on every site — not the parent's
slugs (`base`, `contrast`, `accent-1`…). Then every block stylesheet you write reads the same
across sites, and an agent can tell from the name what a colour is for.

A tested colour set (15 roles):
- Grounds: `surface`, `surface-soft`, `surface-hover`
- Text on light grounds: `ink`, `ink-strong`, `ink-muted`
- Dark grounds and text on them: `dark`, `dark-soft`, `dark-muted`
- Brand: `accent`, `accent-hover`, `accent-soft`, `accent-strong`
- Extras as the design needs: `leaf` (a decorative colour), `line` (borders)

Two roles may share a hex (`ink` and `dark` were the same green) — keep both; the role is the
point. Font sizes `xs sm md lg xl xxl xxxl display`; families `body`, `heading`, `mono`.

**The naming trap — never name a preset after a word core uses in its own classes.** A colour
slug `text` generates `.has-text-color{color:var(--wp--preset--color--text)!important}`, and core
adds `.has-text-color` to *every* block with a custom text colour — so that one colour overrides
all of them, silently. Same risk: `background`, `link`, `border`. (Real build: a `text` slug
turned every custom-coloured heading the same colour; renamed to `ink`.)

**Preset slugs: pure letters or pure digits, never mixed.** A slug like `2xl` breaks silently:
WP kebab-cases the emitted variable (`--wp--preset--font-size--2-xl`) but translates
`var:preset|font-size|2xl` literally — a dead variable, sizes fall back. Use `xxl`/`xxxl`.

## Re-pointing the parent's styles (generate, don't hand-copy)

A child preset array (palette, font sizes, families, spacing) **replaces** the parent's. So every
parent style, template and pattern that names a parent slug now points at nothing and loses its
colour. Fix it once, by script, not by hand:

1. Copy the parent theme into `wp-build/reference/<parent>/` (read-only reference).
2. A small script (e.g. `wp-build/tools/build-child.py`) holds maps — parent slug → our slug
   (`base→surface`, `contrast→ink`, `accent-1→accent`…; a slug used as a background may map
   differently, e.g. `accent-3→dark`) — and writes the child theme:
   - `theme.json`: our presets + only the parent `styles` leaves the map changed, deep-merged
     with our design overrides.
   - Copies of the parent's templates, parts and patterns with slugs swapped (namespace too:
     `ipsum/…` → `<site>/…`).
   - Skips a `HAND_BUILT` set (header, footer, overlay…) so hand work is never overwritten.
   - Strips anything the owner doesn't want (e.g. Ipsum's pinned search-bar part from every
     template).
3. Parent CSS that names its own slugs (Ipsum: tables, arrival bar, two block styles with inline
   CSS) gets the same selectors re-declared in the child `style.css` with our tokens.
4. After every parent update: re-run the script and grep the child and the rendered page for any
   parent slug left over (`--wp--preset--color--accent-1`, `contrast`…). Expect only core's own
   default variables.

The parent's style variations (colour palettes, font pairings) still show in Styles and set the
parent's slugs, so applying one breaks the look. Core always lists a parent's variations for a
child theme and has no filter for it; hide them by emptying the Site Editor's REST response in
the child `functions.php` (tested):
```php
add_filter('rest_request_after_callbacks', function ($response, $handler, $request) {
  if ($response instanceof WP_REST_Response && 'GET' === $request->get_method()
      && preg_match('#^/wp/v2/global-styles/themes/[^/]+/variations$#', $request->get_route())) {
    $response->set_data([]);
  }
  return $response;
}, 10, 3);
```
Block-style partials (`styles/blocks/`) use another route and stay; check they don't name parent
slugs.

## Child theme recipe

Files (author locally in `wp-build/themes/<slug>/`):
- `style.css` — header (`Template: <parent dir>`) plus the site's global CSS (references/css-rules.md).
- `theme.json`, `functions.php`, `templates/`, `parts/`, `patterns/`, `assets/fonts/`, `assets/images/`.
- `AGENTS.md` and `DESIGN.md` (below).

`functions.php` essentials:
```php
add_action('wp_enqueue_scripts', function () {
  wp_enqueue_style('acme-style', get_stylesheet_uri(), ['ipsum-style'] /* the parent's style handle */, wp_get_theme()->get('Version'));
  wp_style_add_data('acme-style', 'path', get_stylesheet_directory() . '/style.css'); // lets WP inline it
});
```
The child `functions.php` loads **before** the parent's, so a parent function wrapped in
`function_exists` (Ipsum's `ipsum_get_color_roles()`) can be overridden by defining it first.
Register block styles here too (`register_block_style`) — references/css-rules.md.

Deploy as a zip (references/novamira.md). Activate via execute-php: `switch_theme('<slug>')` —
don't count on a wp-cli ability being there.

## What goes in theme.json (v3)

- `"version": 3`. All defaults inside theme.json use token syntax `var:preset|color|accent`
  (not raw `var()`), so the editor UI renders them correctly.
- **Palette**, **typography** (`fontFamilies` + `fontFace` for self-hosted fonts; `fontSizes`
  with per-size `fluid: {min, max}` plus global `"fluid": true`), **spacing** (`spacingSizes`,
  pure-digit slugs `10`–`90` are safe).
- **`settings.layout`**: `contentSize` = the design's default content width; `wideSize` = its
  widest breakout (tested: 680px / 1280px). Skip this and you silently inherit the parent's widths.
- **`settings.custom`** for tokens core has no preset for: radius, easing, gutter, shadow. They
  become `--wp--custom--{key}` variables; keys are kebab-cased and nested keys join with `--`
  (`radius.md` → `--wp--custom--radius--md`). Verify the emitted name before using it.
- **`appearanceTools: true`** — needed for controls like sticky position on groups.
- **`styles`**: root colours/font/size, root padding, element styles (`link`, `heading`, `h1`–`h6`,
  `button`). **Hover/focus/active states belong here** (`":hover"`, `":focus"`, `":active"` on
  `elements.button` and `elements.link`, WP 7.0+, tested) — the owner can retune them in Global
  Styles. Only animation (transitions, lifts) stays in CSS.
- **Block-level styles can out-rank element styles.** The parent may set `styles.blocks.core/button`
  typography; your `elements.button` change then doesn't show on Button blocks. Override at
  `styles.blocks.core/button` too (real build: buttons stayed at the parent's size/weight until this).
- **Root padding + full-bleed**: `styles.spacing.padding` left/right is the site gutter — pair it
  with `settings.useRootPaddingAwareAlignments: true` so `alignfull` sections reach the edge while
  their content keeps the gutter.
- **blockGap white stripes**: the root blockGap puts a top margin on every root sibling and every
  top-level section. Don't set `blockGap: "0px"` (collapses spacing in posts) — zero it with two
  scoped rules: `.wp-site-blocks > * { margin-block-start: 0 }` and
  `.entry-content > [class*="acme-"] { margin-block-start: 0 }`.
- **Per-screen-size styles (WP 7.1, not yet tested):** `@tablet` / `@mobile` keys in `styles` with
  breakpoints in `settings.viewport`. Worth trying before writing a media query for a core block;
  test it shows in Global Styles and renders at 390/700/desktop before relying on it.

## When a value isn't in the token set

Add it to `theme.json` and re-deploy — cheap. The boundary test: **would the owner ever want to
change it site-wide?** Colour, font size, spacing step, radius → theme.json. "This grid is
`1fr 2fr`" → the block's own CSS.

## AGENTS.md, DESIGN.md and the design guide

Every site gets two short files, in the child theme (and an AGENTS.md in the blocks plugin):
- **AGENTS.md** — what lives where, which files are generated (and by what) vs hand-built, the
  rules (tokens only, don't reformat block markup), what to re-check after a parent update.
- **DESIGN.md** — the design system by role: a table of every colour slug, hex and role; which
  text colours go on which grounds; type scale with uses; spacing with uses; custom tokens;
  button styles; known gaps.

They're how the next agent (or the next session of this one) works on the site without
re-deriving it. Keep them current with every change.

On Ipsum, also make the design-guide ability tell the truth: override `ipsum_get_color_roles()`
in the child `functions.php` to return your roles, so `ipsum/get-design-guide` describes your
tokens, not Ipsum's.

## Self-hosting Google Fonts

curl the css2 API **with a Chrome user-agent** (otherwise you get legacy formats) → take the
`latin` subset variable `.woff2` per style → child theme `assets/fonts/` → `fontFace` entries
with `"src": ["file:./assets/fonts/..."]` and a range `fontWeight` (e.g. `"100 900"`).

## Site identity

Via execute-php: `update_option('blogname', '...')`, `update_option('blogdescription', '...')`.
Logo: references/site-frame.md.

## Verifying step 1

curl the front page and grep for: your `--wp--preset--*` values, the layout widths
(`--wp--style--global--content-size` / `wide-size`), the `@font-face` URLs; HEAD each font file
(expect 200). Grep for parent slugs left over. On Ipsum, run the design-guide ability and check
its roles match your slugs.
