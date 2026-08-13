# CSS rules — read before writing any stylesheet

## Contents
- Where CSS lives — the three homes
- Port the resets first
- Always use theme tokens
- Winning against core's CSS (specificity patterns)
- Misc gotchas

## Where CSS lives — the three homes

| Home | What goes there |
|---|---|
| `theme.json` → `styles` | element styles — body background, headings, links, buttons, `:hover`. Data, not a stylesheet; the owner can retune it in Global Styles. |
| FluentSnippets CSS snippets | everything else — global resets, header/footer styling, one `.css` per block, shared partials |
| Child theme `style.css` | nothing but the theme header naming the parent |

Block PHP and its CSS ship as a pair in the same snippet group, named after the page section;
the CSS snippet needs `load_in_block_editor: 'yes'` (references/fluentsnippets.md).

## Port the resets first

WordPress ships **none** of the global primitives a static site's CSS assumes. Each missing one
surfaces later as a mystery bug reported by the owner. Port these before building any section:

**1. Box-sizing** — WP does NOT reset to `border-box`. Any ported `width: 100% + padding` combo
silently overflows (visible only on small screens, where there's no max-width slack to absorb
it; the tell is horizontal scroll that pinch-zoom "fixes"). Add a reset **scoped to your block
prefix** (never a global `*` reset — leave core/plugin styles alone), at low specificity so
explicit rules still win:

```css
[class*="bc-"], [class*="bc-"]::before, [class*="bc-"]::after, [class*="bc-"] * {
  box-sizing: border-box;
}
```

Substitute YOUR project's prefix — this selector must use the same prefix as your class names
(the one-prefix standing rule in SKILL.md) or it matches nothing, silently.

**2. Focus outlines** — neither core, themes, nor Global Styles ship any `:focus` styles, so the
browser's default ring shows on tap/click (a tapped link keeps focus on mobile). Port
keyboard-only focus, on brand tokens:

```css
:focus:not(:focus-visible) { outline: none; }         /* silence for pointer input */
:focus-visible {                                       /* keep for keyboard users */
  outline: 2px solid var(--wp--preset--color--accent);
  outline-offset: 3px;
  border-radius: 3px;
}
```

**3.** Any base `a` / `ul` resets the original design's components assume.

## Always use theme tokens

Every value in every snippet stylesheet references `--wp--preset--*` (or `--wp--custom--*`)
variables — colors, spacing, font sizes, radius, shadows. Never hardcode. Global Styles then
re-skins custom blocks along with everything else. Scope every selector to the block's wrapper
class (`.wp-block-{ns}-{name}` or your custom classNames) — snippet CSS is not auto-scoped.

## Winning against core's CSS (specificity patterns)

Core's stylesheets print **after** snippet CSS, so equal specificity loses. Three recurring
cases:

1. **Layout classes**: `.bc-nav__cta { display: none }` loses to
   `.wp-block-buttons-is-layout-flex { display: flex }`. Prefix a parent class
   (`.bc-header .bc-nav__cta`) — win on specificity, not order.
2. **Interactive-state styles** (e.g. `.is-menu-open` padding): same fix. Standing rule: *any*
   override of a core interactive-state style needs a parent-class prefix.
3. **The opposite case — `:where()` styles**: core's blockGap margins use zero-specificity
   `:where()` selectors, so a plain single class beats them (e.g.
   `.bc-post-card__body > * { margin: 0 }` + your own flex `gap`). No prefix war needed there.
4. **Constrained-layout force-centering**: core's `.is-layout-constrained > :where(…)` rule
   sets `margin-left/right: auto !important` on children without an align class, so any child
   carrying its own `max-width` is silently centred — and no specificity trick beats an
   `!important`. Override with a page-scoped `!important` of your own on the element's side
   margins (observed live: a max-width element was centred against the design until
   overridden).

Diagnose with computed styles / matched rules, not guesswork.

## Misc gotchas

- `core/separator` needs `opacity: 1` in your CSS to beat core's `has-alpha-channel-opacity`.
- CSS snippet code must not contain `<style>` tags (see references/fluentsnippets.md).
- One CSS snippet per block/section + one shared partial (eyebrow, buttons) is the right
  granularity — no duplicated rules, still legible per-section in the dashboard.
