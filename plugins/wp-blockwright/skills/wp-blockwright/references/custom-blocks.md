# Step 3 — Page sections as custom blocks + page assembly

## Contents
- Choosing the block type per section
- The block-authoring workflow
- PHP-only block recipe (WP 7.0 autoRegister)
- SCF block recipe
- SCF field groups — use acf_import_field_group, NOT the SCF ability
- Assembling the page from block JSON
- The `&`-mangling repair recipe
- Layout and design patterns
- Page setup details

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

## The block-authoring workflow

1. **Author locally first**: one `.php` + one `.css` per block in `wp-build/blocks/` (source of
   truth), plus ONE shared partial CSS for cross-block pieces (eyebrows, buttons) so blocks
   don't duplicate rules. `php -l` locally (local files keep the `<?php` tag; strip it when
   pushing — see references/fluentsnippets.md).
2. **Ship as a zip** → one execute-php installer creates + publishes each snippet (recipe in
   references/fluentsnippets.md). Groups named after page sections.
3. Verify: each block registered (`WP_Block_Type_Registry`), no entries in
   `Helper::getErrorFiles()`, marker strings present in stored files.

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

- **Owner editing is content-only, by design (owner decision 2026-08-13).** The side panel
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
  `.wp-block-{ns}-{name}` class your CSS scopes to. Escape everything.
- Side-panel controls are auto-generated from the attributes — no JS, no build step. **The
  attribute names ARE the panel labels** (`stat1_label` shows verbatim to the owner) — name
  attributes as the owner should read them.
- **Real page content never goes in attribute defaults.** Give content attributes empty or
  generic defaults and pass the page's real values as block attributes at assembly time. A
  value equal to its default is NOT serialized into the page — the content then lives
  invisibly in the snippet, and editing the snippet's defaults silently rewrites every page
  that relied on them (observed live).
- Editor styling: the paired CSS snippet's `load_in_block_editor: 'yes'` flag covers the
  autoRegister editor-CSS quirk.

## SCF block recipe

```php
add_action('acf/init', function () {
  if (!function_exists('acf_register_block_type')) return;
  acf_register_block_type([
    'name' => 'hero', 'title' => 'Hero',
    'render_callback' => 'bc_render_hero',   // echoes; wrap in function_exists guard
    'mode' => 'preview',
    'acf_block_version' => 3,
    'supports' => ['anchor' => true, 'align' => ['full'], 'mode' => true],
  ]);
});
```

- **Always register with `'acf_block_version' => 3`.** That is SCF's current block engine
  (v1 is officially deprecated): the block toolbar gets a pencil (and the sidebar an
  "Open Expanded Editor" button) that opens the block's fields in a wide modal — long text
  is edited there, not in the narrow side panel. Legacy v1/v2 blocks never show their edit
  toggle in WP 7.0's always-iframed editor — a real build shipped v1 blocks and the owner
  was left editing full paragraphs in the cramped sidebar. Saved data is unaffected: v3
  reads the same flattened `data` format this skill composes. Known quirk: merely opening
  the expanded editor migrates stored data to v3's field-key format and marks the post
  dirty with zero user edits — expected and safe, but don't auto-save a page you didn't
  mean to change.
- **Never set `'mode' => false` in supports.** Content-only editing means no styling
  controls — it does not mean locking the block to preview.

Icon/asset choices: expose a select field whose values map to an inline-SVG library inside the
render callback — the owner picks "Shield (assurance)" from a dropdown, never touches SVG.

## SCF field groups — use acf_import_field_group, NOT the SCF ability

**The `scf/create-field-group` ability creates the group but ZERO field objects.** The
symptom is sneaky: text fields still render (ACF falls back to raw meta) but image fields return
a bare ID and repeaters return the row *count*, so `foreach` renders nothing. Diagnose with
`acf_get_field('field_key')` → NOT FOUND.

Instead, via execute-php: `acf_import_field_group($full_definition)` — creates group + fields +
sub-fields in one call. Delete any empty duplicate group shells afterward (the same key twice is
bad).

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

## Page setup details

- Front page: `update_option('show_on_front', 'page')` + `page_on_front` = the page ID.
- If the theme prints page titles and the design has none: set the theme's no-title block
  template via `_wp_page_template` post meta (e.g. TT5's `page-no-title`).
