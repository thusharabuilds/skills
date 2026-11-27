# Step 5 — Content + dynamic pages (posts, custom fields, archive, single)

Only run this step if the site actually has blog/CPT content. Sub-order: content first
(posts/CPTs), then the archive template, then the single template.

## Contents
- 5a. Creating posts from source content
- 5b. Custom fields: register meta + Block Bindings
- 5c. Blog list page: template file + Query Loop
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

1. **Register the meta in PHP that runs on every request** (a PHP file in the site plugin, e.g. `inc/meta.php`):

```php
add_action('init', function () {
  register_post_meta('post', 'acme_author', [
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
    "className": "acme-single__author",
    "metadata": { "bindings": { "content": {
      "source": "core/post-meta", "args": { "key": "acme_author" } } } } } }
```

   Leave the bound content empty so a broken binding is *visible* during verification instead
   of masked by fallback text.

Bindable (grows each release): image `id/url/title/alt/caption`, heading/paragraph `content`,
button `url/text/linkTarget/rel`, post-date `datetime`; WP 7.1 adds list items and nested blocks
(not yet tested here). Meta must
be `single: true`, not underscore-prefixed; repeaters aren't natively bindable.

**Byline pattern**: author credentials ("Jane Doe, CPA") belong to the *article* — store them in
post meta and bind, don't invent WP user accounts per byline.

## 5c. Blog list page: template file + Query Loop

Templates are **files in the child theme**, built the same way as the header and footer
(references/site-frame.md): a one-line template that delegates to a hidden pattern holding the
blocks. No queue, no browser, no finalize — edit the file, deploy the zip.

- **Which file:** WordPress uses `home.html` for the posts page if it exists, else `index.html`.
  Ipsum ships `templates/index.html` → `patterns/hidden-index.php` (plus archive, search and
  sidebar variants), and the child-theme generator has already copied them in your tokens — so
  usually you **restyle that pattern** rather than build from zero. Add `home.html` → a new
  `patterns/hidden-home.php` only if the blog page must differ from the fallback index.
- Mark generated files you hand-edit as hand-built in the generator (its `HAND_BUILT` set), or the
  next run overwrites them.
- Via execute-php: create the Blog page + `update_option('page_for_posts', $id)` (with
  `show_on_front = page`), and add a Blog link to the Main menu `wp_navigation` post.
- Before re-deploying a template, check for a database copy (`wp_template` post with that slug
  for your theme) — if the owner edited it in the Site Editor, that copy wins over your file.

The whole page is native blocks — `core/query` + post blocks + a few rules in the child theme's
`style.css`. The owner edits columns, per-page, excerpt length and ordering in the side panels.

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

Same file recipe: Ipsum's `templates/single.html` → `patterns/hidden-single.php`. Restyle or
rebuild that pattern, 100% from native blocks:

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
- Content typography lives in the child theme's `style.css` (or theme.json element styles), targeting the native blocks the posts are made of
  (`h2`, `ul`, `li::marker`, `blockquote`, `figure.wp-block-image`, `.wp-element-caption`,
  `.wp-block-separator`, `.lead`). The separator needs `opacity: 1` to beat core's
  `has-alpha-channel-opacity` rule.

## CPTs and custom taxonomies

Define CPTs, taxonomies and their field groups as **SCF local JSON in the site plugin's root
`fields/`** (`post_type_acme_service.json`, `taxonomy_acme_service_type.json`,
`group_acme_service.json`) — not in the dashboard. SCF registers them from the files, so the
content model ships with the plugin (copy it to the live site and the post type is there, already
set up), stays next to the blocks that read it, and can't be changed by accident (SCF lists it
greyed out, with no edit button). Set `show_in_rest` — required for bindings, Query Loop and the
editor. Easiest way to get a valid file: build it once in SCF on a local site, export the JSON,
move it into `fields/`, delete the database copy.

**Which listing to build:**
- **A plain list or grid of entries** → Query Loop with the post type set (inherit off), same
  recipe as the blog list. The owner tunes columns and order in the side panels.
- **Anything more** — tabs per category, grouped sections with counts, a featured item per
  group, extra per-item links — → a custom block that queries the entries in PHP
  (`get_posts`/`get_terms`) and lays them out. The owner still edits entries as entries; the
  block's own fields hold only its headings and labels. Query Loop can only repeat one card.
- Inside a Query Loop, for card logic core can't express, a dynamic PHP block reading
  `$block->context['postId']` (`usesContext: ['postId']`) — never an SCF/instance-field block
  inside a loop.

- **"Related items" on a single CPT page**: WP 7.1's Query Loop has an "exclude current post"
  option (not yet tested here) — use it instead of custom query code.

## Site-wide values: SCF options page + Block Bindings

Values that repeat across pages (contact email, phone, address, socials) go on ONE SCF
options page — the owner edits them in one place, every page updates. Never hard-code the
same string into several blocks.

1. **Register the page** (PHP in the site plugin — must run on every request):

```php
add_action('acf/init', function () {
  if (!function_exists('acf_add_options_page')) return;
  acf_add_options_page([
    'page_title' => 'Site Info', 'menu_slug' => 'site-info', 'position' => 61,
    'capability' => 'manage_options',
  ]);
});
```

   Set `'capability' => 'manage_options'`: these values show on every page of the site, so
   only admins should change them. The default (`edit_posts`) would let any Author-level
   account rewrite the site-wide phone number.

2. **Fields**: a local JSON field group in the plugin's `fields/` (references/custom-blocks.md),
   with the `location` rule targeting `options_page == site-info`. Populate via execute-php:
   `update_field('field_key', $value, 'option')`.
3. **Display in core blocks**: `core/post-meta` reads only post meta, so the same file
   registers a tiny custom bindings source:

```php
add_action('init', function () {
  register_block_bindings_source('acme/site-info', [
    'label' => 'Site info',
    'get_value_callback' => function ($args) {
      if (!function_exists('get_field')) return '';
      return (string) get_field($args['key'] ?? '', 'option');
    },
  ]);
});
```

   Then bind like any other source:
   `"metadata": {"bindings": {"content": {"source": "acme/site-info", "args": {"key": "contact_email"}}}}`.
   Inside a custom block's PHP render callback, skip the binding and read
   `get_field('contact_email', 'option')` directly.
4. Verify: change a value on the options page, reload a front-end page that binds it — the
   new value must appear (and the binding renders empty, visibly, if the key is wrong).

**Where bindings stop.** A binding replaces a block's whole text with one plain value: no line
breaks (a multi-line address, a week of opening hours) and no two links in one paragraph (phone ·
email). Expose ready-made single values from the source (`phone_url`, `address` joined with
commas, a `hours` summary) where one line is enough. Where the design needs lines or several
links — typically the footer's contact columns — use a small custom block that reads the options
page in PHP (references/site-frame.md). Never type the values into a pattern as a fallback: the
owner changes them on the options page and the footer silently keeps the old ones.
