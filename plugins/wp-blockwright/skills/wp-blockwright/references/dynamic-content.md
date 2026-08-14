# Step 5 — Content + dynamic pages (posts, custom fields, archive, single)

Only run this step if the site actually has blog/CPT content. Sub-order: content first
(posts/CPTs), then the archive template, then the single template.

## Contents
- 5a. Creating posts from source content
- 5b. Custom fields: register meta + Block Bindings
- 5c. Archive page: the `home` template + Query Loop
- 5d. Single post template
- CPTs and custom taxonomies
- Site-wide values: SCF options page + Block Bindings

## 5a. Creating posts from source content

Converting source articles (markdown/HTML) into Gutenberg posts:

- **Convert HTML → block markup locally**: every paragraph/heading/list needs its block comment
  and classes (`wp-block-heading`, `wp-block-list`, per-`li` `wp:list-item`). Inline `<figure>`
  images become proper `wp:image` blocks (attachment ID, `wp-image-N` class, media-library URL,
  figcaption kept). Keep meaningful classNames (e.g. a `lead` class on the opening paragraph).
  Strip editorial HTML comments (image-placement notes are instructions, not content).
- **Transport is the main risk** — one dropped character breaks block validation. Embed the
  markup in PHP as a nowdoc (`<<<'EOT'`) and have the script `return md5($content)`; compare
  against the locally computed hash.
- **Idempotent by slug**: `get_page_by_path($slug, OBJECT, 'post')` first; update in place.
- Set featured images AND write alt text to `_wp_attachment_image_alt`. Create real category
  terms (leave nothing in "Uncategorized"). Stagger publish dates if the source implies a
  timeline.
- Verify: every post URL returns 200, title + images present, **zero literal `<!-- wp:` in
  rendered output** (the tell for broken block markup).
- Record state for the template steps: `show_on_front`, `page_for_posts`, `posts_per_page`,
  permalink structure.

## 5b. Custom fields: register meta + Block Bindings

Native blocks CAN display custom-field data — the Block Bindings API (WP 6.5+), no custom block
and no PHP rendering:

1. **Register the meta in PHP that runs on every request** (a FluentSnippets PHP snippet):

```php
add_action('init', function () {
  register_post_meta('post', 'bc_author', [
    'type' => 'string', 'label' => 'Author byline', 'single' => true,
    'show_in_rest' => true, 'default' => '',
    'sanitize_callback' => 'sanitize_text_field',
  ]);
});
```

   `show_in_rest: true` is REQUIRED or the binding renders nothing; `label` surfaces the field
   in the editor's bindings UI.
2. Populate with `update_post_meta()` via execute-php.
3. Bind a plain core block:

```json
{ "name": "core/paragraph", "attributes": {
    "className": "bc-single__author",
    "metadata": { "bindings": { "content": {
      "source": "core/post-meta", "args": { "key": "bc_author" } } } } } }
```

   Leave the bound content empty so a broken binding is *visible* during verification instead
   of masked by fallback text.

Bindable (6.9-era; the list grows each release): image `id/url/title/alt/caption`,
heading/paragraph `content`, button `url/text/linkTarget/rel`, post-date `datetime`. Meta must
be `single: true`, not underscore-prefixed; repeaters aren't natively bindable.

**Byline pattern**: author credentials ("Jane Doe, CPA") belong to the *article* — store them in
post meta and bind, don't invent WP user accounts per byline.

## 5c. Archive page: the `home` template + Query Loop

The whole archive is native blocks — `core/query` + post blocks + one CSS snippet. The owner
edits columns, per-page, excerpt length, and ordering through normal side panels.

Shells via execute-php: create the Blog page + `update_option('page_for_posts', $id)`; then the
template shell — `wp_insert_post` with `post_type: 'wp_template'`, `post_name: 'home'` +
`wp_set_object_terms($id, '<child-theme-slug>', 'wp_theme')` (same wp_theme rule as template
parts). The `home` template renders the posts page when `show_on_front = page`. Compose via the
Gutenberg queue; add the nav link to the `wp_navigation` post in the same batch.

Query Loop decisions that matter:

- `core/query` with `query.inherit: true` — the posts page uses the main query, so WP owns
  pagination/per-page and `core/query-pagination` works. Pagination renders NOTHING while all
  posts fit on one page — that's correct, not a bug.
- Grid via `core/post-template` `layout: {type: "grid", minimumColumnWidth: "19rem"}` —
  responsive with zero media queries. Prefer `minimumColumnWidth` over `columnCount` (which
  doesn't collapse on mobile).
- Card = a `core/group` with a className *inside* the post template (the `<li>` stays
  unstyled) → `core/post-featured-image {isLink, aspectRatio: "3/2"}`, a flex meta row
  (`core/post-terms {term: "category"}` + `core/post-date`), `core/post-title {isLink,
  level: 2}`, `core/post-excerpt {moreText, excerptLength}`.
- Archive h1 = plain `core/heading` `level: 1`.
- If the design has no archive page to copy, derive it from the design system — reuse an
  existing card language and the exact tokens.

## 5d. Single post template

Same shell recipe with `post_name: 'single'` (+ the `wp_theme` term). Compose 100% from native
blocks:

- **The editorial-layout trick**: the template's main group gets
  `layout: {type: "constrained", contentSize: "720px", wideSize: "1040px"}` — a per-group
  override of the theme's sizes. Text reads at 720px; the `alignwide` featured image breaks out
  to 1040px.
- Head: `core/post-terms` (category pill), `core/post-title {level: 1}` (default is h2!), a flex
  meta row = bound byline paragraph (5b) + `core/post-date` (separator via CSS
  `time::before {content: "· "}`).
- `core/post-featured-image {align: "wide", aspectRatio: "16/9"}`; then `core/post-content` bare
  — no layout attribute; the parent group constrains it.
- Prev/next: a flex group with two `core/post-navigation-link` blocks
  (`{type: "previous"/"next", showTitle: true, arrow: "arrow"}`) — WP renders nothing at the
  ends of the timeline, correct with zero handling.
- Content typography lives in one CSS snippet targeting the native blocks the posts are made of
  (`h2`, `ul`, `li::marker`, `blockquote`, `figure.wp-block-image`, `.wp-element-caption`,
  `.wp-block-separator`, `.lead`). The separator needs `opacity: 1` to beat core's
  `has-alpha-channel-opacity` rule.

## CPTs and custom taxonomies

Register CPTs + their fields via SCF from the dashboard (or `execute-php`), with `show_in_rest`
and the CPT public/queryable — required for bindings and Query Loop targeting. A CPT grid is the
same Query Loop recipe with the query's post type set (inherit off). For bespoke card logic core
can't express, a dynamic PHP block reading `$block->context['postId']` (`usesContext:
['postId']`) is the escape hatch — never an SCF/instance-field block inside a loop.

## Site-wide values: SCF options page + Block Bindings

Values that repeat across pages (contact email, phone, address, socials) go on ONE SCF
options page — the owner edits them in one place, every page updates. Never hard-code the
same string into several blocks.

1. **Register the page** (a FluentSnippets PHP snippet — must run on every request):

```php
add_action('acf/init', function () {
  if (!function_exists('acf_add_options_page')) return;
  acf_add_options_page([
    'page_title' => 'Site Info', 'menu_slug' => 'site-info', 'position' => 61,
  ]);
});
```

2. **Fields**: same `acf_import_field_group` recipe as always (references/custom-blocks.md),
   with the `location` rule targeting `options_page == site-info`. Populate via execute-php:
   `update_field('field_key', $value, 'option')`.
3. **Display in core blocks**: `core/post-meta` reads only post meta, so the same snippet
   registers a tiny custom bindings source:

```php
add_action('init', function () {
  register_block_bindings_source('bc/site-info', [
    'label' => 'Site info',
    'get_value_callback' => function ($args) {
      if (!function_exists('get_field')) return '';
      return (string) get_field($args['key'] ?? '', 'option');
    },
  ]);
});
```

   Then bind like any other source:
   `"metadata": {"bindings": {"content": {"source": "bc/site-info", "args": {"key": "contact_email"}}}}`.
   Inside a custom block's PHP render callback, skip the binding and read
   `get_field('contact_email', 'option')` directly.
4. Verify: change a value on the options page, reload a front-end page that binds it — the
   new value must appear (and the binding renders empty, visibly, if the key is wrong).
