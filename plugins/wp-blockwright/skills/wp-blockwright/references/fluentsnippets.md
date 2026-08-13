# FluentSnippets — where all custom PHP and CSS lives

## Contents
- Why FluentSnippets
- Snippet meta schema
- The programmatic API (create / publish / update)
- API gotchas (each caused a real failure)
- CSS snippets — the two checkboxes
- Organizing snippets
- Installing many snippets at once (zip recipe)

## Why FluentSnippets

Custom block PHP and CSS is hosted as FluentSnippets snippets, not in `functions.php` and not in
a bespoke plugin, because: the owner can see and manage every piece of code from the dashboard;
snippets are real files on disk (`wp-content/fluent-snippet-storage/N-slug.php`) so file tools
still work; and a snippet that throws a fatal is **auto-disabled** instead of white-screening
the site. Trade-off accepted: blocks depend on FluentSnippets staying active.

Convention: **one PHP snippet per block + one paired CSS snippet**, same group. Every snippet's
source of truth is a local file in the project's `wp-build/` tree.

## Snippet meta schema

| Field | Value |
|---|---|
| `name` (req) | Human title; seeds the filename (first 4 words) behind a unique numeric prefix — so a clashing name does NOT error; re-creating silently duplicates (see gotchas). |
| `status` (req) | `published` or `draft`. `createSnippet` always forces `draft`. |
| `type` (req) | `PHP`, `php_content`, `css`, `js`. |
| `run_at` (req) | PHP: `all` (use this for block registration — the code adds its own `init` hook), `backend`, `wp_head`, `wp_footer`, or any hook name. CSS: `wp_head`. |
| `group` | Organizational bucket — **name it after the page section** (`Hero Section`, `Site Frame`, `Blog`). |
| `tags` | Comma-separated labels (e.g. `block,css`). |
| `priority` | Hook priority, default 10. |
| `load_as_file` | `yes` → CSS/JS emitted as a cached real file (cacheable across pages). |
| `load_in_block_editor` | `yes` → CSS also injected into the Gutenberg editor. |
| `condition` | Conditional display rules; `{status:'no',...}` = always run. |

## The programmatic API

```php
use FluentSnippets\App\Helpers\Helper; // autoloaded — never require it

$file = Helper::createSnippet(['meta' => $meta, 'code' => $code]); // returns filename or WP_Error
Helper::updateSnippet(['meta' => $meta, 'code' => $code, 'file_name' => $file, 'reactivate' => true]);
Helper::cacheSnippetIndex('', true); // refresh the index

(new FluentSnippets\App\Model\Snippet())->deleteSnippet($file);
Helper::getErrorFiles(); // snippets auto-disabled after a fatal
```

Flow: `createSnippet` (lands as draft) → `updateSnippet` with `status: published` +
`reactivate: true` → `cacheSnippetIndex` → **read the stored file back and verify a distinctive
marker string exists**.

## API gotchas (each caused a real failure)

- **Do NOT start `code` with `<?php`** — the API adds the tag itself and returns WP_Error
  `invalid_code` if you include it. Strip it before pushing; keep it in the local canonical file
  (so `php -l` lints locally).
- **Wrap every named function in `if (!function_exists(...))`** (or use closures).
  FluentSnippets validates a PHP snippet by loading it twice in one request — a bare named
  function fatals with "cannot redeclare" and the snippet silently stays draft.
- CSS/JS code must not contain `<style>`/`<script>` tags.
- `updateSnippet` requires the FULL meta + code every time — there is no partial "just change
  the group" call. To change meta later: parse the stored file's `@key:` docblock into an array,
  strip the `<?php` + Internal-Doc header from the code portion, then call `updateSnippet` with
  everything.
- Check `Helper::getErrorFiles()` after pushing PHP — an auto-disabled snippet fails silently
  from the front end's point of view.
- **Snippet creation is not idempotent, and the transport can retry a call.** One
  `createSnippet` call has produced TWO published copies of a snippet (the numeric filename
  prefix makes every file unique, so no clash error fires — the result was a JS click handler
  running twice). Extend the idempotent-by-slug rule to snippets: search the snippet index for
  the name BEFORE creating — found → `updateSnippet`, not create — and after creating, verify
  exactly one file matches the name.
- Snippet type `js` (`run_at: wp_footer`) works as the schema table says — verified; used for
  behavioral fixes like the mobile anchor-nav recipe in references/site-frame.md.

## CSS snippets — turn BOTH checkboxes on

For every block/section CSS snippet set `load_as_file: 'yes'` (real cacheable stylesheet) AND
`load_in_block_editor: 'yes'` (styles render inside the editor). The editor flag is also the fix
for the WP 7.0 `autoRegister` quirk where a block's styles don't auto-load in the editor —
without it the owner edits unstyled gray boxes.

## Organizing snippets

Group by page section from the start — that's how owners think ("which snippet is the hero?").
Example layout: `Site Frame` (header/footer CSS), `Hero Section` (hero PHP + hero CSS),
`Services Section` (...), `Shared Styles` (shared partial CSS), `Blog` (archive CSS, single-post
CSS, post-meta registration PHP).

## Installing many snippets at once

One `create-upload-link` for a single `.zip` of all local files → curl PUT → one execute-php
installer that `ZipArchive`-extracts each file, strips the `<?php` line, and calls
`createSnippet` + `updateSnippet(reactivate)` per snippet, then deletes the zip. Beats N
separate upload links, and the local files stay canonical.
