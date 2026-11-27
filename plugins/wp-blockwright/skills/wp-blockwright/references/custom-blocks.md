# Step 3 — Page sections as custom blocks + page assembly

## Contents
- Choosing the block type per section
- Where block code lives: the site plugin
- Shared CSS for several blocks
- The block-authoring workflow
- Escape every field at the point of output
- PHP-only block recipe (WP 7.0 autoRegister)
- SCF block recipe (block.json, v3, inline editing)
- SCF field groups — local JSON in the plugin
- Assembling the page from block JSON
- `&` and special characters (old bug, now fixed)
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
├── acme-blocks.php          # header + the registrations below
├── AGENTS.md                # what's here, how to deploy, field-key rule
├── assets/common.css        # pieces several blocks share (see "Shared CSS")
├── fields/                  # site-wide SCF local JSON: post types, taxonomies,
│                            #   options-page and CPT field groups
├── inc/                     # options page, bindings source, CPT helpers (PHP)
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
foreach (glob(__DIR__ . '/inc/*.php') as $file) require $file;
add_action('init', function () { // shared handles first: block.json can only name registered handles
  wp_register_style('acme-blocks-common', plugins_url('assets/common.css', __FILE__), [], '0.1.0');
  wp_style_add_data('acme-blocks-common', 'path', __DIR__ . '/assets/common.css'); // lets WP inline it
}, 4);
add_action('init', function () {
  foreach (glob(__DIR__ . '/blocks/*/block.json') as $file) register_block_type(dirname($file));
}, 5);
add_filter('acf/settings/load_json', function ($paths) {
  $paths[] = __DIR__ . '/fields'; // site-wide definitions that belong to no single block
  foreach (glob(__DIR__ . '/blocks/*/fields', GLOB_ONLYDIR) as $dir) $paths[] = $dir;
  return $paths;
});
```
`Update URI: false` stops WordPress.org ever offering an "update" from a same-named plugin. Use
`Requires Plugins: advanced-custom-fields` instead if the site runs ACF. There is deliberately no
`save_json` path: the JSON files are read-only definitions that ship with the code, and SCF shows
them greyed out with no edit button, so nobody changes the content model from the dashboard by
accident (a field the code doesn't know about would just be ignored).

## Shared CSS for several blocks

Button variants, list rows, photo frames, a status dot — anything two or more blocks draw — goes
in one `assets/common.css`, registered as a handle before the blocks (main file above) and listed
**first** in each block's `style`: `"style": ["acme-blocks-common", "file:./style.css"]`. WordPress
loads it once per page, only when one of the blocks is present, and inside the editor iframe too.
Copying the same rules into each block's `style.css` drifts the moment one copy is edited.

What does NOT go here: anything a core block or another page could also need. Buttons, links and
headings take their look from theme.json elements; corner shapes, borders and shadows from
theme.json custom values (references/css-rules.md, "Global components"). The shared file holds
only what is specific to the plugin's blocks, built from theme tokens.

Two rules every site's `common.css` starts with:
```css
/* Sections butt together: beats core's 24px block gap between top-level blocks
   (:root :where(.is-layout-constrained) > * prints after us at equal specificity). */
:root .acme-section { margin-block: 0; }
```
plus the scoped box-sizing reset (references/css-rules.md). Each section block carries the
`acme-section` class and brings its own `padding-block`, so spacing between sections is decided
by the sections, not by core.

## The block-authoring workflow

1. **Author locally first** in `wp-build/plugins/<site>-blocks/` (source of truth, in Git).
   `php -l` every PHP file.
2. **Deploy** (references/novamira.md, "Local or hosted"): on a local site copy the folder into
   `wp-content/plugins/`; on a hosted site ship it as a zip. Then `activate_plugin()` once.
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
  register_block_type('acme/section-heading', [
    'title'      => 'Section Heading',
    'attributes' => [
      'eyebrow' => ['type' => 'string', 'default' => ''],
      'heading' => ['type' => 'string', 'default' => ''],
      'level'   => ['type' => 'integer', 'default' => 2],  // give heading blocks a level!
      'anchor'  => ['type' => 'string', 'default' => ''],
    ],
    'supports'        => ['autoRegister' => true, 'anchor' => true],
    'render_callback' => 'acme_render_section_heading',
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
  "style": ["acme-blocks-common", "file:./style.css"],
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
  - **Only in the editor.** Both helpers print their attributes everywhere, so unguarded they
    ship `data-acf-inline-contenteditable` and "Type to edit..." placeholders to every visitor.
    Wrap them once at the top of `render.php` and use the wrappers:
    ```php
    $txt = fn($field) => $is_preview && function_exists('acf_inline_text_editing_attrs')
      ? acf_inline_text_editing_attrs($field) : '';
    $bar = fn($fields) => $is_preview && function_exists('acf_inline_toolbar_editing_attrs')
      ? acf_inline_toolbar_editing_attrs($fields) : '';
    ```
    Then `<h1 class="acme-hero__title" <?php echo $txt('heading'); ?>>`. Verify with curl: zero
    `data-acf-inline` strings on the front end.
  - In preview (`$is_preview`), render an empty-state placeholder for an empty link/image so the
    owner has something to click.
- **Hide with a class, not the `hidden` attribute.** SCF's editor preview drops boolean HTML
  attributes, so tabs or panels hidden with `hidden` all show at once in the editor. Add a class
  (`is-hidden`) with `display: none` alongside it; front-end scripts toggle both.
- **Use SCF's own field types as they are** — a button is a Link field, a list is a Repeater, a
  photo is an Image field (return `id`, render with `wp_get_attachment_image()`). Don't split
  fields into Text just to make them typeable in place; the toolbar pop-up is fine for the rest.
- `render.php` gets `$block`, `$content`, `$is_preview`, `$post_id`. Wrap output with
  `get_block_wrapper_attributes()` and set `id` from `$block['anchor']`. Escape every field.

Icon/asset choices: expose a select field whose values map to an inline-SVG library inside the
render callback — the owner picks "Shield (assurance)" from a dropdown, never touches SVG.

**Buttons in custom blocks** get the class `wp-element-button` (plus a variant class if needed),
so they take the theme's button look from theme.json `styles.elements.button` — the same rules
the core Button block uses (references/css-rules.md). Don't restyle the base button per block.

**Decorations the theme also uses** (a border band, a motif): the block keeps its own copy of the
file in its folder, with a comment naming the master copy in the theme. Pointing at the theme's
file would make the block depend on that theme; a tiny duplicated SVG is the cheaper trade.

## SCF field groups — local JSON in the plugin

Keep each block's field group as a JSON file in `blocks/<name>/fields/` and point SCF at those
folders with the `acf/settings/load_json` filter (main file above). Tested: SCF loads the group
from the file (nothing in the database), repeater sub-fields included, and the group ships with
the code. Location rule: `[{ "param": "block", "operator": "==", "value": "acme/hero" }]`.
Sub-fields carry `"parent_repeater": "<repeater key>"`.

Site-wide definitions go in the plugin's root `fields/`: custom post types (`post_type_*.json`),
taxonomies (`taxonomy_*.json`) and the field groups for them and for the options page (tested —
SCF local JSON loads all of these). Options pages themselves can't be local JSON in SCF; register
them in PHP (references/dynamic-content.md). Write fields with fixed, readable keys
(`field_acme_service_price`) — block data and post meta point at keys.

**Never use the `scf/create-field-group` ability** — it creates the group with ZERO field objects
(text still renders from raw meta, but images return a bare ID and repeaters a row count).
Diagnose with `acf_get_field('field_key')` → NOT FOUND.

If you must create a group in the database instead (a site that can't take plugin files):
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

**Don't hand-write this format — use `scripts/write-blocks.php`.** Give it each block's name and
plain values (strings, attachment IDs, link arrays, repeater rows as lists); it reads the real
field keys from the block's field group, flattens everything, and — for a page made only of the
plugin's blocks — writes it through the fast lane with `wp_slash()`, then checks the stored copy
and your marker strings. It stops before writing if a value names a field that doesn't exist (a
typo would otherwise drop that content silently). There is no official SCF/ACF helper for this;
hand-flattening is where wrong keys creep in, and a wrong key shows as an empty field in the
editor. For pages that mix in core blocks, use only its flattening part to build each block's
`data` for the queue.

## `&` and special characters (old bug, now fixed)

Older Novamira versions lost backslashes when finalizing, turning `\u0026` into a bare `u0026`
or `\&`. Fixed since 1.11.3. If a page ever shows a bare `u0026`, repair with a `parse_blocks` →
fix → `serialize_blocks` round-trip and `wp_update_post` with `wp_slash()`. The escaped form
`\u0026` (with its backslash) is normal — don't "fix" it.

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
- If the theme prints page titles and the design has none: set the no-title template via
  `_wp_page_template` post meta (`page-no-title` in both Ipsum and Twenty Twenty-Five).

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
