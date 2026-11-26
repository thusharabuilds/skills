# Step 2 — Site frame: header, footer, mobile menu

## Contents
- Where the frame lives (theme files, one-line delegates)
- Logo, menu and shared pieces
- The header
- The mobile menu: navigation overlay part
- The footer
- Mobile header rules
- Same-page anchor links in the overlay
- Gotchas (positioning, specificity, edits that stop theme updates)

## Where the frame lives

Build the header, footer and mobile menu as **files in the child theme**, the way Ipsum does:

- `parts/header.html` → one line: `<!-- wp:pattern {"slug":"acme/header-default"} /-->`
- `patterns/header-default.php` → the real block markup, with a full docblock:
  `Title`, `Slug`, `Categories`, `Block Types: core/template-part/header`, `Viewport width`, and a
  **Description** that says in plain words what's in it. Agents and owners pick patterns by that
  text — a bare "Header" tells nobody anything.
- Same for `footer` (and any variants, e.g. Ipsum's `footer-wide` delegating to the same pattern)
  and the `navigation-overlay` part.

Why files, not queued template parts: no queue, no browser, no finalize; version-controlled;
re-deployable. Give key groups a `metadata.name` (`"Header Row"`, `"Brand"`, `"Menu and Booking"`)
so List View reads like the design. Put visible strings through `esc_html__()` /
`esc_html_x()` and URLs through `esc_url( home_url( '/#book' ) )`.

Write the block markup carefully once (valid comment JSON + matching HTML classes) and **don't
reformat it later** — the editor compares markup exactly, and a "tidy-up" can make blocks invalid.
Check each pattern opens in the Site Editor without "This block contains unexpected content".

**Once the owner edits a part in the Site Editor, WordPress saves a copy in the database and that
copy wins** — later theme-file changes to that part no longer show. Before re-deploying a part,
check for a `wp_template_part` post with that slug for your theme; if it exists, tell the owner
(they can "Reset" it in the Site Editor) rather than silently deleting their edits.

## Logo, menu and shared pieces

- **Logo → Site Logo block** (`core/site-logo`) with the logo uploaded as a media item and set as
  `site_logo` / `custom_logo`. The owner changes it once; header, overlay and footer all update.
  **An SVG logo needs size metadata**: insert it with `wp_insert_attachment` (uploads normally
  refuse SVG) and then `wp_update_attachment_metadata($id, ['width'=>200,'height'=>200,'file'=>...])`
  — without it the block ignores its `width` and shows the logo huge.
- **One menu.** Navigation blocks with no `ref` fall back to the newest `wp_navigation` post. Create
  ONE "Main menu" `wp_navigation` post on deploy (only if missing) and leave `ref` off in both the
  header and overlay navs — the owner edits one menu and both update.
- Colours/spacing as block attributes using preset slugs (`backgroundColor: "dark"`,
  `var:preset|spacing|50`), so the owner sees real controls.
- Link colours on dark grounds: set `style.elements.link.color` (and `:hover`) on the group.

## The header

Typical shape: full-width group (own className, e.g. `acme-header`) → wide flex row → Brand group
(Site Logo + Site Title) and a right group (Navigation + a Buttons block). The booking/CTA button is
a normal Button block — no menu-item hacks.

- Sticky header: group attribute `style.position.type: "sticky"` (needs `appearanceTools`).
- Remove what the owner doesn't want from the parent's templates in the generator (Ipsum pins a
  floating search-bar part into every template — strip that line, keep the part available).

## The mobile menu: navigation overlay part (WP 7.0+)

Build the open mobile menu as its own template part, from blocks, instead of restyling core's
default takeover with CSS:

- Header nav attributes: `"overlay":"navigation-overlay","overlayMenu":"mobile","icon":"menu"`.
- `parts/navigation-overlay.html` → delegate to `patterns/navigation-overlay.php` (`Block Types:
  core/template-part/navigation-overlay`).
- Inside: a full-height group on the brand ground, an action bar with the Site Logo and a
  **Navigation Overlay Close** block (`core/navigation-overlay-close`), the menu as a vertical
  Navigation (`overlayMenu: "never"`, big font size), and the CTA as a normal Button.

The old CSS "dropdown panel" recipe (neutralising three positioned wrappers, overflow clipping) is
no longer needed when the overlay part is available.

**Wider collapse breakpoint.** Core switches to the menu button only below 600px. If the design
collapses wider (e.g. 1080px), show the button and hide the inline list in between, scoped to
your header:
```css
@media (min-width: 600px) and (max-width: 1079px) {
  .acme-header .wp-block-navigation__responsive-container-open:not(.always-shown) { display: flex; }
  .acme-header .wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open) { display: none; }
}
```
Tested with the overlay part.

## The footer

A pattern like the header. Decorations (borders, medallions, patterns) are **CSS backgrounds** on
the footer's `::before`/`::after`, files in the child theme `assets/images/`, not image blocks — see
references/css-rules.md. Leave room for them with padding (e.g. top padding
`calc(56px + var(--wp--preset--spacing--70))` for a 56px border band).

Global values in the footer (phone, address, hours) are candidates for an options page + bindings
(references/dynamic-content.md) if they repeat elsewhere.

## Mobile header rules

- **Verify at ~390px, ~700px, and desktop, with the overlay OPEN.**
- Keep logo, name, menu button and CTA on one row on phones: `white-space: nowrap` on the title and
  button; `flex-shrink: 0` on the logo (flex squashes it otherwise); smaller title and tighter gaps
  at ≤420px. Preset font-size classes are `!important`, so a size override needs `!important` too
  (comment why).

## Same-page anchor links in the mobile overlay

On WP 7.0 the overlay did not close when a same-page `#anchor` link was tapped, and
`html.has-modal-open` locked scrolling, so the tap looked dead. Re-test on your version with the
overlay part; if it still happens, ship a small JS fix (in the theme or plugin):

- On click of an in-page `#anchor` link inside an open overlay: prevent default, click its close
  button, then **wait for `has-modal-open` to clear** (a `requestAnimationFrame` loop, ~60 tries)
  before scrolling — a fixed `setTimeout` is not enough.
- Leave links to other pages and desktop behaviour untouched.

## Gotchas

- **Never put `backdrop-filter` (or `filter`/`transform`) on an ancestor of `core/navigation`.**
  The overlay is `position: fixed`, and a filtered ancestor becomes its containing block — the
  "full-screen" menu renders inside the header bar. Put blur on a `::before` instead.
- **Core's layout and interactive-state styles tie or beat your CSS at equal specificity.** Add one
  parent class (`.acme-header .wp-block-…`) to win on specificity, not order.
- **Behavioral verification, not just screenshots**: open the overlay, `document.elementFromPoint`
  hit-test the close button, close it, tap a menu link (closes AND scrolls), other viewports unchanged.
