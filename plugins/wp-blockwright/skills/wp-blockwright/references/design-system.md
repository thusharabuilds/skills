# Step 1 — Design system: child theme + theme.json

## Contents
- Why a child theme (and the ruled-out alternatives)
- Child theme recipe
- What goes in theme.json (v3)
- Self-hosting Google Fonts
- Site identity
- Verifying step 1

## Why a child theme

The design system lives in `theme.json`, but editing the parent theme's own file gets wiped by
theme updates. Home for the customized `theme.json`: a **child theme** of the active block theme
(e.g. Twenty Twenty-Five). A child theme is an *overlay*, not a copy — only three items
(`style.css`, `theme.json`, `assets/fonts/`), everything not overridden falls through to the
parent. Novamira's `write-file` allows non-PHP files anywhere, so the child theme is fully
creatable remotely.

Ruled out: style variations (wiped on update outside a child theme); Create Block Theme plugin
(exports a standalone theme, loses parent updates). Plan B if a child theme is impossible: the
`wp_theme_json_data_theme` PHP filter (WP 6.1+) can inject tokens from a snippet — survives
updates and theme switches, but ties the whole design system to the snippets plugin, and fonts
still need disk files.

## Child theme recipe

1. Author locally in `wp-build/theme/<slug>/`: `style.css` (header with `Template:` naming the
   parent theme's directory), `theme.json`, `assets/fonts/*.woff2`.
2. Push via Novamira file tools or an upload-link zip into `wp-content/themes/<slug>/`.
3. Activate: `run-wp-cli` with `args: ["theme", "activate", "<slug>"]`.

## What goes in theme.json (v3)

- `"version": 3`. All defaults inside theme.json use token syntax `var:preset|color|primary`
  (not raw `var()`), so the editor UI renders them correctly.
- **Palette** (`settings.color.palette`): semantic slugs (`accent`, `accent-soft`, `surface`,
  `line`, `muted`, ...). **Keep the slug names the active theme's own theme.json uses for its
  core colours** (in Twenty Twenty-Five: `base` and `contrast`) — core templates reference
  them; renaming breaks compatibility.
- **Typography**: `fontFamilies` + `fontFace` for self-hosted fonts (a display + a body family
  is usually enough); `fontSizes` with per-size `fluid: {min, max}` plus the global
  `"fluid": true` — WP emits the `clamp()` for you (verify in the output CSS).
- **Preset slugs: pure letters or pure digits, never mixed.** A slug like `2xl` breaks
  silently: WP kebab-cases the emitted variable (`--wp--preset--font-size--2-xl`) but
  translates `var:preset|font-size|2xl` references literally — a dead variable, no error,
  sizes fall back. Use `xxl`/`xxxl` instead of `2xl`/`3xl`.
- **Spacing**: a `spacingSizes` scale (pure-digit slugs `10`–`120` work well and are safe —
  the mixed-slug trap above does not apply to them).
- **`settings.layout`**: `contentSize` = the design's default content max-width; `wideSize` =
  its widest breakout, or just above `contentSize` if the design has none — don't invent one.
  Narrow measures (article text) come from per-group overrides, not these (see
  references/dynamic-content.md). Skip this and you silently inherit the parent theme's widths,
  and every section drifts off the design.
- **`settings.custom`** for tokens core has no preset for: radius, shadow, letter-tracking,
  easing, gutter. They become `--wp--custom--{key}` variables.
- **`appearanceTools: true`** — needed for controls like sticky position on groups.
- **`styles`**: body background (gradients OK via `styles.css`), root padding, and element
  styles (`headings`, `h1`–`h3`, `link`, `button` including `:hover`).
- **Root padding + full-bleed**: `styles.spacing.padding` left/right is the site gutter — pair
  it with **`settings.useRootPaddingAwareAlignments: true`** so the padding lands per block
  instead of on the outer wrapper. Then `alignfull` sections (and their background wash) reach
  the viewport edge while the content inside them keeps the gutter. Omit either and you get one
  of two bugs: full-bleed sections that stop short of the edge, or text jammed against the
  screen edge at ~390px.
- **blockGap white stripes**: the theme's `styles.spacing.blockGap` puts a top margin on every
  root sibling (`header`/`main`/`footer`) *and* on every top-level section inside the content —
  between full-bleed backgrounds it shows as a white stripe (observed live: 32px stripes above
  the footer and below the header). Don't fix it by setting `blockGap: "0px"` in theme.json —
  that collapses spacing in normal flowing content like blog posts. Instead zero the margins
  with two scoped rules in the shared partial: `.wp-site-blocks > * { margin-block-start: 0 }`
  and `.entry-content > [class*="acme-"] { margin-block-start: 0 }` (your project prefix — this
  catches every section wrapper whether or not it carries `alignfull`). Core applies these gaps
  via zero-specificity `:where()` rules, so a plain class wins (see references/css-rules.md).

Every preset becomes `--wp--preset--{feature}--{slug}` on `body` in both editor and front end —
these variables are what every block stylesheet references, and what Global Styles lets the
owner retune site-wide.

## When a value isn't in the token set

Don't hardcode it — add it to `theme.json` and re-push the child theme. That's cheap: a file
write, no queue, no browser, no batch — nothing like the cost of changing a page. The boundary
test, so `theme.json` doesn't fill with every number in the design: **would the owner ever want
to change it site-wide?**

| Value | Where |
|---|---|
| A colour, font size, spacing step, radius, shadow | `theme.json` — it's design system |
| This hero's grid is `1fr 2fr`; this card's gap is 14px | the block's own CSS — it's layout |

Gotcha: `settings.custom` keys are kebab-cased on output — `radiusLarge` becomes
`--wp--custom--radius-large`, and nested keys join with `--`. Easy to guess wrong and reference
a silently dead variable; verify the emitted name in the page CSS before using it.

## Self-hosting Google Fonts

curl the css2 API **with a Chrome user-agent** (otherwise you get legacy formats) → take the
`latin` subset variable `.woff2` per style → put in the child theme's `assets/fonts/` →
`fontFace` entries with `"src": ["file:./assets/fonts/..."]` and a range `fontWeight`
(e.g. `"400 700"`).

## Site identity

Site title/tagline: `run-wp-cli` `["option", "update", "blogname", "..."]` /
`"blogdescription"`.

## Verifying step 1

curl the front page and grep for: expected `--wp--preset--*` values, the layout widths
(`--wp--style--global--content-size` / `--wp--style--global--wide-size` — confirm they are
yours, not the parent theme's), the `@font-face` URLs. Then HEAD-request each font file (expect
200). Cheap, and catches wrong paths/missing files immediately.
