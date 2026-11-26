# Step 3 — Page sections as custom blocks + page assembly

## Contents
- Choosing the block type per section
- Where block code lives: the site plugin
- The block-authoring workflow
- Escape every field at the point of output
- PHP-only block recipe (WP 7.0 autoRegister)
- SCF block recipe (block.json, v3, inline editing)
- SCF field groups — local JSON in the plugin
- Assembling the page from block JSON
- The `&`-mangling repair recipe
- Layout and design patterns
- Placing the owner's form
- Page setup details
- WP 7.1+ options to try (not yet tested)

## Choosing the block type per section

Decide per section, and record the mapping in the build log before building:

| Section needs | Build as |
|---|---|
| Plain strings / numbers / booleans / a dropdown | **PHP-only block** (`autoRegister`) |
| Image picker, WYSIWYG prose, repeater rows | **SCF block** |
| Markup nobody will ever edit | plain static HTML inside a core block |
| Content the owner edits as ordinary text | core blocks directly |

PHP-only block limits (first-iteration feature): auto-generated side-panel controls only for
`string`/`number`/`integer`/`boolean`/`enum`; no media picker, no rich text, no repeater, no
inner blocks (renders via ServerSideRender); editor preview is a server round-trip. That's
exactly why rich sections route to SCF.

## Where block code lives: the site plugin

Custom blocks live in **one small plugin per site**, `<site>-blocks` (e.g. `acme-blocks`) — not
FluentSnippets, not the child theme.

- Why a plugin, not the theme: these blocks render with PHP; if their code disappears, every
  section using them goes blank. A theme switch would do exactly that. A plugin survives theme
  changes and can be reused on the next client site.
- Why not FluentSnippets: block.json gives per-block CSS loaded only where the block is used and
  inside the editor iframe; snippet CSS loads everywhere and the legacy `acf_register_block_type`
  style options can miss the iframed editor. Same code either way, so no extra risk.
- FluentSnippets stays for small non-block tweaks only (references/fluentsnippets.md).

Layout (tested):
```
acme-blocks/
├── acme-blocks.php          # header + the two loops below
├── AGENTS.md                # what's here, how to deploy, field-key rule
└── blocks/
    └── hero/                # one folder per block, named after the section
        ├── block.json
        ├── render.php
        ├── style.css
        └── fields/group_acme_hero.json   # SCF field group (local JSON)
```
Main file:
```php
/**
 * Plugin Name:      Acme Blocks
 * Description:      Custom page sections for Acme. One folder per block in /blocks.
 * Version:          0.1.0
 * Requires at least: 7.0
 * Requires Plugins: secure-custom-fields
 * Update URI:       false
 * Text Domain:      acme-blocks
 */
defined('ABSPATH') || exit;
add_action('init', function () {
  foreach (glob(__DIR__ . '/blocks/*/block.json') as $file) register_block_type(dirname($file));
}, 5);
add_filter('acf/settings/load_json', function ($paths) {
  foreach (glob(__DIR__ . '/blocks/*/fields', GLOB_ONLYDIR) as $dir) $paths[] = $dir;
  return $paths;
});
```
`Update URI: false` stops WordPress.org ever offering an "update" from a same-named plugin. Use
`Requires Plugins: advanced-custom-fields` instead if the site runs ACF.

## The block-authoring workflow

1. **Author locally first** in `wp-build/plugins/<site>-blocks/` (source of truth, in Git).
   `php -l` every PHP file.
2. **Deploy as a zip** (references/novamira.md): first install extracts it to
   `wp-content/plugins/`, updates copy over the files; then `activate_plugin()`.
3. Verify: each block registered (`WP_Block_Type_Registry::get_instance()->is_registered()`), the
   block's CSS present on a page that uses it and **absent** on one that doesn't, marker strings
   present in the deployed files. Bump the plugin and block.json `version` on every change.

## Escape every field at the point of output

Everything a render callback reads — `get_field()`, `get_sub_field()`, block attributes —
arrives unescaped; ACF/SCF never escapes `get_field()` for you. A render callback runs on
every page view, so a single unescaped field is a standing site-wide XSS. This exact pattern
(a field or block attribute echoed raw) is behind real vulnerabilities in major block
plugins. Escape at the echo, matched to where the value lands:

- Text between tags → `esc_html()`. Inside an HTML attribute → `esc_attr()`. An
  `href`/`src` → `esc_url()`. Numbers → cast `(int)`.
- `wp_kses_post()` ONLY for a field deliberately meant to hold rich text — nothing else
  gets it.
- Icons and other inline SVG: the owner picks a *name* from a select field; the SVG lives
  in a fixed PHP array behind an `isset()` check. Raw SVG never comes from a field.
- Images: pass the attachment ID to `wp_get_attachment_image()` — it escapes its own output.

## PHP-only block recipe (WP 7.0 autoRegister)

```php
add_action('init', function () {
  register_block_type('bc/section-heading', [
    'title'      => 'Section Heading',
    'attributes' => [
      'eyebrow' => ['type' => 'string', 'default' => ''],
      'heading' => ['type' => 'string', 'default' => ''],
      'level'   => ['type' => 'integer', 'default' => 2],  // give heading blocks a level!
      'anchor'  => ['type' => 'string', 'default' => ''],
    ],
    'supports'        => ['autoRegister' => true, 'anchor' => true],
    'render_callback' => 'bc_render_section_heading',
  ]);
});
```

- **Owner editing is content-only, by design — a deliberate v1 limitation.** The side panel
  exposes exactly the block's attributes — no colour, spacing, or typography panels — so keep
  `supports` to `autoRegister` + `anchor`. The block's look lives in its token-based CSS;
  restyling happens through `theme.json` / Global Styles, never per-block controls.
  (Owner-facing styling controls are deferred material for a future version.)
- **Fast-lane blocks additionally declare `'align' => ['full']` and paint their own
  background in CSS** (references/novamira.md): with no `core/group` wrapper to carry
  full-width and the background wash, a block missing them renders white-on-white. The align
  entry is a structural rendering requirement, not an owner styling control.
- The render callback **returns** markup, wrapped via
  `get_block_wrapper_attributes(['class' => ..., 'id' => $anchor])` — WP adds the
  `.wp-block-{ns}-{name}` class your CSS scopes to. Escape everything (rules above).
- Side-panel controls are auto-generated from the attributes — no JS, no build step. **The
  attribute names ARE the panel labels** (`stat1_label` shows verbatim to the owner) — name
  attributes as the owner should read them.
- **Real page content never goes in attribute defaults.** Give content attributes empty or
  generic defaults and pass the page's real values as block attributes at assembly time. A
  value equal to its default is NOT serialized into the page — the content then lives
  invisibly in the block code, and editing the code's defaults silently rewrites every page
  that relied on them (observed live).
- Editor styling: register the block's stylesheet (`wp_register_style`) and pass its handle as
  `'style'` in the `register_block_type` args, then confirm it loads inside the editor iframe —
  WP 7.0 had a quirk where autoRegister blocks missed their editor CSS.
- Code lives in the site plugin (e.g. `blocks/section-heading/register.php` required from the
  main file), same as SCF blocks.
- **WP 7.0+: mark content attributes `"role": "content"`** (not yet tested here). Without it,
  the block's text can't be edited when it sits inside a pattern or template part that uses
  content-only editing, and the block can drop out of List View.

## SCF block recipe (block.json, v3, inline editing)

`blocks/hero/block.json`:
```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "acme/hero",
  "title": "Hero",
  "description": "Hero section. Click any text and type. Click the photo or button to change them.",
  "category": "design",
  "icon": "cover-image",
  "version": "0.1.0",
  "textdomain": "acme-blocks",
  "supports": { "anchor": true, "align": ["full"], "html": false },
  "style": "file:./style.css",
  "acf": { "blockVersion": 3, "mode": "preview", "renderTemplate": "render.php" }
}
```
This is the ACF-recommended way since 6.0; `acf_register_block_type()` is legacy. The
`description` is what the owner sees in the inserter — tell them how to edit it.

- **Always `"blockVersion": 3`.** That's SCF's current block engine (v1 is deprecated): a toolbar
  pencil and an "Open Expanded Editor" sidebar button open the fields in a wide modal. Legacy
  v1/v2 blocks never showed their edit toggle in WP 7.0's always-iframed editor. Known quirk:
  opening the expanded editor migrates stored data to field-key format and marks the post dirty
  with zero edits — expected and safe.
- **Inline editing (SCF 6.9+, free — the biggest owner win).** In `render.php`:
  - Text / Textarea → typed straight into the preview: add
    `acf_inline_text_editing_attrs('heading')` to the element holding the text.
  - Image, Link, WYSIWYG, Repeater → click opens a small toolbar editor: add
    `acf_inline_toolbar_editing_attrs(['button'])` to the element. A repeater edits as a whole,
    not per row.
  - Guard both with `function_exists()` so the block still renders if SCF is older.
  - In preview (`$is_preview`), render an empty-state placeholder for an empty link/image so the
    owner has something to click.
- **Use SCF's own field types as they are** — a button is a Link field, a list is a Repeater, a
  photo is an Image field (return `id`, render with `wp_get_attachment_image()`). Don't split
  fields into Text just to make them typeable in place; the toolbar pop-up is fine for the rest.
- `render.php` gets `$block`, `$content`, `$is_preview`, `$post_id`. Wrap output with
  `get_block_wrapper_attributes()` and set `id` from `$block['anchor']`. Escape every field.

Icon/asset choices: expose a select field whose values map to an inline-SVG library inside the
render callback — the owner picks "Shield (assurance)" from a dropdown, never touches SVG.

## SCF field groups — local JSON in the plugin

Keep each block's field group as a JSON file in `blocks/<name>/fields/` and point SCF at those
folders with the `acf/settings/load_json` filter (main file above). Tested: SCF loads the group
from the file (nothing in the database), repeater sub-fields included, and the group ships with
the code. Location rule: `[{ "param": "block", "operator": "==", "value": "acme/hero" }]`.
Sub-fields carry `"parent_repeater": "<repeater key>"`.

**Never use the `scf/create-field-group` ability** — it creates the group with ZERO field objects
(text still renders from raw meta, but images return a bare ID and repeaters a row count).
Diagnose with `acf_get_field('field_key')` → NOT FOUND.

If you must create a group in the database instead (options pages, CPT fields from execute-php):
`acf_import_field_group($full_definition)` — then **check every sub-field key with
`acf_get_field()`, not just the top-level ones.** A real import created a repeater but silently
skipped its sub-field; the repeater rendered empty with an "Undefined array key _name" warning.
Fix: `acf_update_field([... 'parent' => <repeater post ID>, 'parent_repeater' => <repeater key>])`.

**Field keys are the contract.** Page block data references field *keys*
(`_field_name: field_key`), so groups can be recreated safely as long as the keys are reused —
and must never change once pages store data.

## Assembling the page from block JSON

Compose the page as `{name, attributes, innerBlocks}` via the Gutenberg queue (recipe in
references/novamira.md). ACF block data uses the **flattened meta format** inside
`attributes.data`:

```
"data": {
  "heading": "Plan with confidence",  "_heading": "field_abc123",
  "image": 42,                        "_image": "field_def456",   // attachment ID
  "items": 3,                                                      // repeater = row count
  "items_0_label": "CPA, CA",         "_items_0_label": "field_ghi789",
  ...
}
```

Every field: `field_name: value` plus `_field_name: field_key`. Repeaters: `items: <count>` +
one flattened row entry per sub-field. Image fields take the attachment ID.

## The `&`-mangling check (repair ONLY if it fires)

Current Novamira (1.11.3+, verified) serializes `&` correctly on both write paths — an escaped
`u0026` WITH its backslash inside stored attribute JSON is normal Gutenberg serialization, not
the bug. After finalizing any batch containing `&`/special characters, check the stored content
for the broken forms: a BARE `u0026` (backslash lost) or a `\&`. Only if one appears:

1. `str_replace('\\&', '&', $content)` — a plain `&` is valid inside JSON strings.
2. `wp_update_post` with `wp_slash($content)`.
3. Verify with `parse_blocks()` that attributes decode and key fields survived. The same
   backslash-stripping can mangle `<p>`/`\n` in WYSIWYG data — repair with a
   `parse_blocks` → fix → `serialize_blocks` roundtrip.

## Layout and design patterns

- **Full-bleed section background**: a `core/group` with `align: "full"`, a className for the
  wash, and constrained layout — the custom block sits inside; the group carries the anchor.
- A hero that must match exact container widths can be `alignfull` and manage its own containers;
  ordinary sections stay content-width and let the theme constrain them.
- **Cross-section anchors**: before finishing, walk the nav and confirm every `#target` exists.

## Placing the owner's form

Contact, newsletter, booking and quote forms are **never hand-built** (SKILL.md standing rule).
The owner creates the form in their form plugin and gives you a **shortcode** or the plugin's
**block name**. You place it and style it. Use what they gave you verbatim — never guess a
shortcode or a form ID.

**Default: the form is a sibling core block inside the section's group.**

```
{"name": "core/group", "attributes": {"align": "full", "className": "acme-contact"},
 "innerBlocks": [
   {"name": "acme/section-heading", "attributes": {...}},
   {"name": "core/shortcode", "attributes": {"text": "[the-owners-shortcode]"}}
 ]}
```

`core/shortcode` is the universal path — every form plugin ships one. If the owner names the
plugin's own block instead, use that; it gives them a form picker in the editor. Either way the
form stays a **separate block** the owner can swap, re-point or delete without touching your
section block.

**Fallback — only when the form must render INSIDE a custom block** (e.g. a two-column block
whose other half is block fields). Give the block a `form_shortcode` string field and render it
with `do_shortcode()`:

```php
$shortcode = trim( $attributes['form_shortcode'] ?? '' );
if ( $shortcode !== '' && preg_match( '/^\[[a-z0-9_-]+[^\]]*\]$/i', $shortcode ) ) {
    echo do_shortcode( $shortcode );   // deliberately NOT escaped — see below
}
```

This is the **one exception** to "escape every field at the point of output" above: escaping it
would print the shortcode as literal text on the page. That is exactly why the `preg_match`
guard is not optional — it holds the field to a single shortcode call and nothing else. Prefer
the sibling-block default whenever the layout allows it.

**Styling.** Wrap the form in your prefixed class and target the plugin's own classes from the
child theme's `style.css`, using theme tokens (references/css-rules.md):

```css
.acme-contact input[type="text"],
.acme-contact input[type="email"],
.acme-contact textarea {
  font-family: var(--wp--preset--font-family--body);
  color: var(--wp--preset--color--ink);
}
```

Never rename, strip or restructure the plugin's markup or classes — its validation and submit
JS depend on them. Style from the outside only. Check the rendered form at all three viewports
like any other section; plugin defaults rarely match the design's spacing.

**If the shortcode has not arrived** by the time you reach that section: build the rest of the
section and put a `core/paragraph` in the form's slot that cannot be mistaken for a form —
e.g. "FORM PLACEHOLDER — replace with the contact form shortcode." Record it in your report.
Never render input fields as a stand-in: a convincing fake form is the exact failure this rule
exists to prevent.

## Page setup details

- Front page: `update_option('show_on_front', 'page')` + `page_on_front` = the page ID.
- If the theme prints page titles and the design has none: set the theme's no-title block
  template via `_wp_page_template` post meta (e.g. TT5's `page-no-title`).

## WP 7.1+ options to try (not yet tested)

Release-note features that may simplify builds. Test each on staging before relying on it.

- **Pattern Overrides with custom blocks** (7.0): a synced pattern whose text changes per page
  (e.g. a CTA band reused on several pages). Custom blocks opt in via the
  `block_bindings_supported_attributes` filter. Test with one SCF and one PHP-only block.
- **SVG Icon API** (7.1): `wp_register_icon_collection()` / `wp_register_icon()` /
  `wp_get_icon()` — register the design's icons once; the core Icon block and render callbacks
  can both use them. Would replace the fixed SVG array in render callbacks.
- **PHP-only blocks get the current post ID in the editor preview** (7.1).
- **Core PHP-only blocks still can't edit in the canvas** or offer media/link/rich-text controls
  (explored for 7.1, not shipped, not on the 7.2 roadmap) — that's why rich sections stay SCF.
