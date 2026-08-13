# Novamira — how every write reaches the site

## Contents
- Reaching the site (and choosing the right ability)
- execute-php patterns (transport, verification)
- Uploading files
- wp-cli
- The Gutenberg queue (composing content as block JSON)
- Self-finalizing a batch (admin access link + browser)
- Session and verification gotchas

## Reaching the site

Novamira exposes its capabilities as **abilities**, reachable either through the Novamira MCP
server or the Novamira CLI. The capabilities are identical either way — only the way in changes.
Discover what is available at runtime; the mechanics of calling an ability are self-describing,
so don't hard-code them.

What follows is what discovery will *not* tell you: which ability to reach for, in what order,
and how each one fails.

## Choosing the right ability

- **`novamira/execute-php` is the workhorse.** Arbitrary PHP with the full WP environment, ~30s
  limit. Reach for it by default for anything structural — creating posts, registering blocks,
  writing options, importing field groups.
- **Prefer execute-php over the file tools for anything WordPress already models.** The file
  tools are for real files on disk; don't use them to fake something `wp_insert_post` or the
  options API does properly.
- **Never hand-write serialized block HTML — use the Gutenberg queue.** See below. This is the
  single most important choice in this file.
- **SCF field groups: do NOT use the `scf/create-field-group` ability — it is broken** (creates
  the group with zero field objects). Use `acf_import_field_group` via execute-php instead. See
  references/custom-blocks.md.

### Novamira injects its own instructions — reconcile, don't stall

`discover-abilities` returns `novamira_instructions` carrying Novamira's own workflow: load the
`novamira-design` skill, ask the owner whether they want a page builder / Gutenberg / child
theme, prefer built-in skills like `gutenberg-edit-content`. Those questions are ones this skill
has already answered — don't re-ask the owner, and don't blend write paths mid-build: this
skill's path (queue / fast lane, code in FluentSnippets, tokens in the child theme) stays
authoritative. Novamira's verification abilities (e.g. `check-design`) are compatible and worth
using as extra checks.

## execute-php patterns

- **Always return verification data** — the created post ID, an md5 of stored content, a boolean
  for "marker string present". Never fire-and-forget.
- **Transport large strings (block markup, CSS) as base64** embedded in the PHP code. Decode with
  strict mode — `base64_decode($b64, true)` — and sanity-check the result before writing. Return
  `md5($content)` and compare against a locally computed hash. (A placeholder string once shipped
  because a template variable was never substituted; the API still reported success.)
- **Exact post content goes in a nowdoc** (`<<<'EOT'`) — no escaping of quotes/apostrophes, so
  8 KB of Gutenberg markup survives byte-exact.
- **Make content scripts idempotent by slug**: `get_page_by_path($slug)` first, update in place
  if it exists. Re-running can never duplicate posts.

## Uploading files

Create an upload link (the ability requires a `path` parameter — the destination path relative
to the WP root), then curl PUT the file to the returned URL. For many files, upload ONE zip
and run one execute-php installer that `ZipArchive`-extracts and processes each file, then deletes
the zip. Upload tokens are long base64 strings — script the create-link + curl in one step;
hand-copying a token has corrupted it before (→ 401).

## wp-cli

The wp-cli ability needs `args` as an **array** (e.g. `["option", "update", "blogname", "Acme"]`),
not a command string.

## The Gutenberg queue — composing content as block JSON

Never hand-write serialized block HTML. Compose every post, page, template, template part, and
navigation menu as `{name, attributes, innerBlocks}` JSON and let the queue serialize it through
the real editor:

1. Create empty shells first via execute-php (the queue targets real post IDs) — `wp_insert_post`
   the page/template/part, set required terms (see the per-step reference files).
2. Add a pending change per target with `{target_id, block_spec: [...]}` — the first call
   auto-creates a draft batch; queue all related targets into ONE batch.
3. Enable batch finalization.
4. Finalize (needs a wp-admin browser session — see below), then poll the returned `poll_url`
   with curl until `status: "finalized"`.

Block specs must be exactly `{name, attributes, innerBlocks}` — stray keys happen to be ignored
today, but don't rely on it. Tiny raw-HTML fragments (an inline SVG logo, an eyebrow paragraph
reusing a shared class) are fine inside a nested `core/html` block.

### A pending change REPLACES the target's whole content. There is no insert.

The only operation is `replace-content`, and it is marked destructive. Queueing a change writes
your `block_spec` over everything that was on that page. All the worked examples in this skill
target a shell created empty seconds earlier, so the danger never shows up in them — but it is
waiting for the first agent asked to add a section to a page that already has content.

**To add to, or edit part of, an existing page: read, splice, write the whole thing back.**

1. `gutenberg-get-content` on the target. It is read-only and returns a parsed block tree in the
   same `{name, attributes, innerBlocks}` shape `block_spec` takes. Pass `include_raw_content:
   true` to also get the exact saved markup, and raise `max_depth` above its default of 4 if the
   page nests deeper — otherwise the tree comes back **silently truncated** and you will write
   back a page with content missing.
2. Splice your new block into that tree where you want it.
3. Queue the **whole tree** back as one change.

Never queue just the new section. Guards before you splice:

- If `gutenberg-get-content` returns a `pending_gutenberg_change`, an unfinalized batch is already
  queued for that target. Inspect or finalize it first — what you read back is the live content,
  not the queued content, so re-queueing on top would throw the queued work away.
- **Force a revision first.** A read-splice-write round-trip is the same as opening the page in
  the editor and re-saving it: pre-existing markup can be normalized on the way through. A
  revision gives the owner a one-click undo inside WordPress that doesn't depend on a host backup.
  (`wp_save_post_revision()` returns null when the latest revision already matches the post —
  verify by `wp_get_post_revisions()` count, not by the return value.)
- **Prove the read is complete before writing it back.** Truncation is silent, and counting
  top-level blocks does NOT detect it — a real page kept its 5 top-level blocks while losing 17
  of 32 nested ones. Two checks that work: walk the tree for any node with
  `inner_block_count > 0` but missing/empty `innerBlocks` (that node was truncated — re-read
  deeper), and/or compare a RECURSIVE total block count between what you read and what you're
  about to queue. Belt-and-braces for a page you can't afford to damage: pull the raw
  `post_content` (base64 via execute-php, md5-verified) and confirm every distinctive string on
  the page survives into your spliced tree.
- **Filter parse artifacts before re-queueing.** The returned tree interleaves `core/freeform`
  whitespace nodes (the `\n\n` between serialized blocks) between real sections. Drop freeform
  nodes with trivial `inner_html_length`, or you'll queue classic-editor junk blocks back in.
- **Absent attributes are not lost data.** Reads omit attributes equal to their defaults — a
  block can come back with 4 of its 7 attributes and be intact. Don't "repair" what isn't broken.

That round-trip is also why the `&`-mangling check below applies to the **whole page**, not just
the part you added — everything passes through the finalizer, not only your new blocks.

### The queue refuses a page made entirely of raw HTML

`allow_raw_html` defaults to false, and with it false the queue **rejects** content whose
top-level blocks are all `core/html` or classic. This sits right in the path of porting a static
HTML site: pasting the markup into a `core/html` block and moving on will fail the batch.

Compose with registered blocks — core ones, or the custom blocks this skill builds. The
`allow_raw_html` escape hatch exists but defeats the point: a wall of raw HTML is not something
the owner can edit, which is the entire reason for this skill.

### `gutenberg-write-content` is not a shortcut for our blocks

There is a direct-write ability that skips the queue, the browser session and finalization
entirely. It accepts **only** Novamira's own `novamira/*` dynamic-only blocks (`save: null`, so
there is no static markup to generate) and refuses everything else. Blocks this skill builds are
registered from a snippet under our own namespace, so they are refused. Don't spend a cycle
discovering this.

## The `serialize_blocks()` fast lane — no queue, no browser (custom blocks ONLY)

The one condition, and it is the whole thing: **every block being written draws itself with PHP
and stores only its settings** — i.e. this skill's own custom blocks. Those serialize to a
single self-closing comment (`<!-- wp:acme/hero {"heading":"..."} /-->`), which core's own
`serialize_blocks()` can produce without an editor. **One core block in the tree and you're back
to the queue** — core blocks store finished HTML that only editor JS can generate.

When the condition holds, skip the queue, browser session, finalization and the 30-minute
session expiry entirely:

1. Compose each block as `['blockName' => 'acme/hero', 'attrs' => [...], 'innerBlocks' => [],
   'innerHTML' => '', 'innerContent' => []]`. The `innerHTML` and `innerContent` keys are
   REQUIRED even when empty — `serialize_blocks()` expects them.
2. `wp_insert_post` / `wp_update_post` via execute-php with
   `wp_slash(serialize_blocks($blocks))` — skip `wp_slash()` and backslash escapes get stripped.
3. Verify as always: read back, `parse_blocks()` round-trip, md5.

This does not violate the no-hand-written-HTML rule: core does the serializing, including its
own escaping of `&`, quotes and `--` inside comment delimiters. The `&`-mangling bug lives in
the finalizer and cannot occur on this path (verified — editor opens the result with zero
invalid blocks).

- **Appending to an existing page is the sweet spot**: concatenate `serialize_block($new)` onto
  the raw content. Nothing existing is parsed or re-saved — no truncation risk, no
  normalization. **Inserting BETWEEN existing sections still touches existing content — use the
  queue with the full splice guards above.**
- **The block must carry its own presentation.** The queue recipes put full-width and the
  background wash on a `core/group` wrapper — a group cannot ride the fast lane. A fast-lane
  block needs `align: full` support and a default background in its own CSS, or it renders
  white-on-white (screenshot-verified). Supports recipe: references/custom-blocks.md.

## Self-finalizing a batch (no human needed)

The finalizer runs in a wp-admin browser tab. The agent can open one itself:

1. Create an admin access link → returns an exchange URL + token + nonce.
2. `curl -X POST` the exchange URL with headers `X-Novamira-Admin-Access-Token` and
   `X-Novamira-Admin-Access-Nonce` → returns a one-time `login_url`.
3. Open `login_url` in the browser-automation tool, then navigate to
   `wp-admin/admin.php?page=novamira-gutenberg-finalize`.
4. **Open the finalize tab BEFORE starting the poll loop** — batches finalize in under a minute
   that way. Poll `poll_url` with curl until `"finalized"`. Poll responses can embed control
   characters in batch labels and break strict JSON parsers — parse tolerantly (grep for the
   status string) or use the `gutenberg-get-pending-batch` ability for status instead. The
   runtime token survives across batches; only the tab needs to stay open.
5. **A pending batch that just sits there usually means the tab's auto-pickup went stale —
   reload the finalize tab once.** Observed live: one batch stayed pending through repeated
   polls until a single tab reload, then finalized in seconds. It is not a queue failure —
   don't re-queue or rebuild; the queue and the runtime token are unaffected.

## Session and verification gotchas

- wp-admin sessions from access links expire in ~30 minutes. If wp-admin redirects to login,
  don't debug — create a fresh access link and log in again. **The queue survives the expiry**:
  queued items are not lost; re-login, reopen the finalize page, and finalization proceeds.
- Serialized block JSON does **not** escape slashes: `"source":"core/post-meta"` appears
  literally in post content. When grepping stored content to verify, search for the plain
  string, not an escaped `\/` variant.
- After finalizing a batch whose data contains `&` or other special characters, re-parse and
  check the stored content for a BARE `u0026` (no preceding backslash). The escaped form
  `\u0026` inside stored attribute JSON is normal serialization, not the bug. Repair only if
  the bare form appears — see references/custom-blocks.md.
