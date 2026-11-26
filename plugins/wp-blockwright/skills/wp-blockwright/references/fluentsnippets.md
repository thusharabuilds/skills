# FluentSnippets — small non-block tweaks only

Optional. **Custom blocks don't live here** (references/custom-blocks.md), and global CSS goes in
the child theme (references/css-rules.md). Use a snippet only for a small PHP hook or CSS tweak
the owner wants to see and switch off from the dashboard (a redirect, an admin tweak). Snippets
are real files (`wp-content/fluent-snippet-storage/N-slug.php`), a snippet that fatals is
auto-disabled instead of white-screening the site, and the source of truth is still a local file
in `wp-build/`. Keep the plugin updated — it runs code on every request.

## API (via execute-php)

```php
use FluentSnippets\App\Helpers\Helper; // autoloaded — never require it
$file = Helper::createSnippet(['meta' => $meta, 'code' => $code]);   // always lands as draft
Helper::updateSnippet(['meta' => $meta, 'code' => $code, 'file_name' => $file, 'reactivate' => true]);
Helper::cacheSnippetIndex('', true);
Helper::getErrorFiles();                                             // auto-disabled snippets
```

Meta: `name`, `status` (`published`), `type` (`PHP`, `css`, `js`), `run_at` (PHP: `all` or a hook;
CSS: `wp_head`; JS: `wp_footer`), `group` (name it after what it does), plus for CSS
`load_as_file: 'yes'` and `load_in_block_editor: 'yes'`.

## Gotchas (each caused a real failure)

- **Don't start `code` with `<?php`** — the API adds it and returns `invalid_code` otherwise.
- **Wrap named functions in `if (!function_exists(...))`** or use closures — the snippet is
  loaded twice when validated, and a redeclare fatal leaves it silently in draft.
- **Not idempotent**: search the index for the name first and update instead of creating; one
  create call once produced two published copies. After creating, check exactly one exists.
- `updateSnippet` needs the full meta + code every time.
- CSS/JS code must not contain `<style>`/`<script>` tags.
- After pushing PHP, check `Helper::getErrorFiles()` and read the stored file back for a marker.
