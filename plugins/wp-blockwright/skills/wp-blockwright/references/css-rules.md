# CSS rules — read before writing any stylesheet

## Contents
- Where CSS lives — the homes
- Port the resets first
- Tokens only, and say why
- Global components — one source for core and custom blocks
- Block styles (button variants etc.)
- Decorations are CSS backgrounds
- Winning against core's CSS (specificity patterns)
- Misc gotchas

## Where CSS lives — the homes

| Home | What goes there |
|---|---|
| `theme.json` → `styles` | Element and core-block styles: colours, type, buttons, links, `:hover`/`:focus`/`:active`. Data, not a stylesheet — the owner retunes it in Global Styles. Prefer this whenever it can express the rule. |
| Child theme `style.css` | Global CSS theme.json can't express: resets, link underline offset, button lift/transition, block-style variants, header/footer/overlay styling, decorations, overrides of parent CSS. Enqueued from `functions.php` and inlined. |
| Blocks plugin `assets/common.css` | Pieces two or more custom blocks share (variants, rows, frames), built from tokens. Registered handle, listed first in each block's `style` (references/custom-blocks.md). |
| Blocks plugin `blocks/<name>/style.css` | One per custom block, loaded via block.json `"style"` — only on pages that use the block, and inside the editor iframe. |
| FluentSnippets CSS | Only small one-off tweaks the owner wants to see in the dashboard. Not for blocks — snippet CSS loads on every page and can't tell whether a block is present. |

## Port the resets first

WordPress ships **none** of the global primitives a static site's CSS assumes. Port these before
building any section:

**1. Box-sizing**, scoped to your prefix (never a global `*` reset):
```css
[class*="acme-"], [class*="acme-"]::before, [class*="acme-"]::after, [class*="acme-"] * {
  box-sizing: border-box;
}
```
Use YOUR prefix — the same one as your class names — or it matches nothing, silently.

**2. Focus outlines** — keyboard-only, on brand tokens (or set `:focus` on `elements.button` /
`elements.link` in theme.json):
```css
:focus:not(:focus-visible) { outline: none; }
:focus-visible { outline: 2px solid var(--wp--preset--color--ink); outline-offset: 3px; }
```

**3.** Any base `a` / `ul` resets the original design's components assume.

## Tokens only, and say why

- Every value references a token: `var(--wp--preset--color--*)`, `--wp--preset--spacing--*`,
  `--wp--preset--font-size--*`, `--wp--custom--*`. No hex codes, no raw font sizes. Global Styles
  then re-skins custom blocks along with everything else. (Real miss: test heroes hard-coded a pink
  hex and didn't follow the palette.) Layout numbers that aren't design-system values (a grid's
  `1fr 2fr`, a 56px decoration height) are fine.
- **Every rule gets a short comment saying why** it exists — "theme.json can't animate", "core only
  collapses below 600px", "beats the !important preset class". The next agent (or you after a
  parent update) needs to know which rules are still needed.
- Scope every selector to the block wrapper (`.wp-block-{ns}-{name}`) or your own classNames.
- **Illustrations and logos keep their own colours.** The token rule is for the site's design —
  text, grounds, buttons, borders. A drawing with fifteen shades would otherwise need fifteen
  presets nobody should ever pick, or get flattened into the palette and look wrong.

## Global components — one source for core and custom blocks

Pieces the whole site shares should look the same whether a core block or a custom block draws
them, and change in one place. Decide where each lives with one question: *could a core block or
another page ever need this look?*

1. **Buttons, links, headings, captions, quotes → theme.json `styles.elements`.** WordPress prints
   these on every page and in the editor; buttons on the class `.wp-element-button`. Custom
   blocks add `wp-element-button` to their buttons and use plain `h2`/`a` tags — no restyling.
   (WordPress added the class precisely so third-party blocks can match the theme.)
2. **Other shared looks (corner shapes, borders, frames, card padding) → theme.json
   `settings.custom`** (shadows → shadow presets). They become `--wp--custom--*` variables that
   core blocks (`var:custom|…` in theme.json) and custom blocks' CSS both use.
3. **Variants and pieces only the custom blocks use → the plugin's `assets/common.css`**, built
   from the values above. Core's block style variations (`is-style-outline`) are scoped to the
   core block's wrapper and don't reach a custom block's button, so a "dark" or "large" variant
   for custom blocks is a small class there.

## Block styles (button variants etc.)

For **core** blocks. Register in the child `functions.php`, style in the child `style.css`:
```php
register_block_style('core/button', ['name' => 'ghost', 'label' => __('Ghost', 'acme')]);
```
Use CSS (not theme.json `variations`) when the variant needs things theme.json can't carry —
per-corner radius, inset box-shadow outlines. Plain class selectors
(`.wp-block-button.is-style-ghost .wp-block-button__link`) beat theme.json's zero-specificity
`:where()` rules. Document each variant in DESIGN.md.

## Decorations are CSS backgrounds

Purely decorative art (border bands, medallions, motifs) goes in the child theme
`assets/images/*.svg` and shows as a `::before`/`::after` background on the section — not an
image block. The owner never edits it, screen readers skip it, and it can't be deleted by accident.
Relative `url(assets/images/…)` works: WP rewrites URLs when it inlines the child style.css. Use
`isolation: isolate` on the section and `z-index: -1` on the pseudo-element to keep it behind the
content; `pointer-events: none`. A custom block that needs the same decoration keeps its own copy
in its block folder (references/custom-blocks.md).

## Winning against core's CSS

Core's stylesheets often print after yours, so equal specificity loses:

1. **Layout classes** (`.wp-block-buttons-is-layout-flex { display: flex }`) and
   **interactive-state styles** (`.is-menu-open`): add a parent class (`.acme-header .x`) — win on
   specificity, not order.
2. **`:where()` styles** (blockGap margins): zero specificity, a plain single class beats them.
3. **Constrained-layout force-centering**: `.is-layout-constrained > :where(…)` sets
   `margin-left/right: auto !important` on children without an align class — only a page-scoped
   `!important` of your own beats it.
4. **Preset classes are `!important`** (`.has-lg-font-size`, `.has-ink-color`). Overriding one at a
   breakpoint needs `!important` too — comment why.
5. **Parent block styles with inline CSS** (Ipsum registers some with its own slugs in inline CSS
   that can print after your file): add `:root` in front of the selector to win regardless of order.

Diagnose with computed styles / matched rules, not guesswork.

## Misc gotchas

- `core/separator` needs `opacity: 1` to beat core's `has-alpha-channel-opacity`.
- Respect `prefers-reduced-motion: reduce` for any lift/transition you add.
- CSS snippet code (if any) must not contain `<style>` tags.
