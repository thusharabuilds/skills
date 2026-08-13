# Step 2 — Site frame: header + footer template parts

## Contents
- The template-part recipe
- Composition patterns that work
- Mobile header — rules and the dropdown-menu recipe
- Gotchas (positioning, specificity)

## The template-part recipe

1. **Create empty shells via execute-php** (the Gutenberg queue targets real post IDs):
   `wp_insert_post` with `post_type: 'wp_template_part'`, slug `header`/`footer`, then
   `wp_set_object_terms` → taxonomy `wp_theme` = **the child theme slug** (CRITICAL — template
   override resolution matches on this term) and taxonomy `wp_template_part_area` =
   `header`/`footer`. The navigation menu is its own `wp_navigation` post of
   `core/navigation-link` blocks.
2. **Compose blocks as `{name, attributes, innerBlocks}` JSON** and queue all targets (header,
   footer, nav) into ONE batch; finalize per references/novamira.md.
3. Fine styling = ONE CSS snippet (group `Site Frame`, both editor/file flags on), selectors
   scoped to custom classNames you set on the blocks (`bc-header`, `bc-footer__*`), values via
   `--wp--preset--*`.

## Composition patterns that work

- **Brand/logo**: inline SVG in a nested `core/html` fragment + `core/site-title`. SVG colors
  use `var(--wp--preset--color--...)` so Global Styles re-skins the logo too.
- **Nav**: `core/navigation` with `ref` → the `wp_navigation` post. The built-in mobile
  hamburger/overlay replaces any custom JS toggle from the original design for free, and the
  owner edits links as normal menu items.
- **Sticky header**: group attribute `style.position.type: "sticky"` (needs `appearanceTools`).
- Colors/spacing as block attributes using preset slugs (`backgroundColor: "contrast"`,
  `var:preset|spacing|50`) — the owner sees proper controls in the editor.

## Mobile header — rules

- **Verify at ~390px, ~700px, and desktop, with the nav overlay OPEN.** Desktop-only screenshots
  pass while the phone header is a wrapped mess. Use the original design's own breakpoints as
  the reference.
- Compact the header at small widths following the original design's pattern — e.g. at ≤782px
  shrink the bar CTA (`white-space: nowrap`, smaller font/padding) and hide the tagline; below
  WP's 600px nav collapse, hide the bar CTA and instead add a **CTA item to the `wp_navigation`
  menu** (own className, styled as a pill inside the overlay, `display: none` on desktop). The
  owner keeps editing it as a normal menu item.
- WP's default open-menu state (bare white full-screen takeover) looks off-brand. Restyling it
  needs **CSS only, zero markup changes** — see the dropdown recipe below.

## The dropdown-panel mobile menu recipe

A polished alternative to the full-screen overlay: a panel anchored under the sticky header
(brand background + blur, border-bottom + shadow, stacked links, CTA pill, slide-down
animation, X replacing the hamburger in its spot, 44px tap targets via
`padding: 10px; margin: -10px`).

Mechanics (all reusable):

- Panel = `position: absolute; inset: 100% 0 auto 0` on the `.is-menu-open` container. The
  sticky header is the containing block (sticky counts as positioned) — no hardcoded height.
- **WP has THREE positioned wrappers that hijack the anchoring** — neutralize each with
  `position: static`: the nav block itself, `.wp-block-navigation__responsive-dialog`, and
  `.wp-block-navigation__responsive-close`. Diagnose with `element.offsetParent`; don't guess.
- **Keep `overflow: visible` on the panel** — the close X sits above the panel edge and
  `overflow: auto` clips it into a visible-but-unclickable state. Long menus scroll via the
  inner `-container-content` wrapper instead.
- Hide the hamburger while open:
  `.your-nav:has(.is-menu-open) .wp-block-navigation__responsive-container-open { visibility: hidden }`.

## Same-page anchor links in the mobile overlay ship BROKEN by default

On WP 7.0 the nav overlay does not close when a `#anchor` menu link pointing at the same page
is tapped — and `html.has-modal-open` locks scrolling, so the tap looks completely dead. A
one-page port (this skill's flagship flow) hits this on every menu item. Ship a small JS
snippet (type `js`, `run_at: wp_footer`) with the site frame:

- On click of an in-page `#anchor` link inside
  `.wp-block-navigation__responsive-container.is-menu-open`: prevent default, close the overlay
  (click its close button), then **wait for `has-modal-open` to clear** before scrolling to the
  target — a `requestAnimationFrame` loop (~60 tries) works; scrolling earlier is swallowed by
  the scroll lock. A fixed `setTimeout` is not enough (10ms was not; the class clears on its
  own schedule).
- Leave links to other pages and desktop (no overlay) behaviour untouched.

verification.md's menu behavioral check ("tapping a link closes the menu AND scrolls") is what
catches this; this recipe is the fix.

## Gotchas (positioning, specificity)

- **Never put `backdrop-filter` (or `filter`/`transform`) on an ancestor of `core/navigation`.**
  Core's mobile overlay is `position: fixed; inset: 0`, and a filtered ancestor becomes its
  containing block — the "full-screen" menu renders inside the 60px header bar. For a
  translucent blurred header, put the background + blur on a `::before` pseudo-element
  (absolute, inset 0, z-index -1) and keep the header itself filter-free.
- **Core's layout and interactive-state styles tie or beat snippet CSS of equal specificity**
  (they print later). Any override of `.wp-block-buttons-is-layout-flex`, `.is-menu-open`
  padding, etc. needs one extra parent class (`.bc-header .bc-nav__cta`) to win on specificity,
  not order. This recurs constantly — treat it as the default when styling core blocks.
- **Behavioral verification, not just screenshots**: `document.elementFromPoint` hit-test on the
  close X (catches clipped-but-visible), X-click closes, tapping a menu link closes the menu AND
  scrolls to the anchor, other viewports unchanged.
