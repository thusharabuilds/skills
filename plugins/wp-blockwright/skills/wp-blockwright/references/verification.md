# Verification — the QA recipes that actually catch bugs

Verify after every step, not just at the end; and re-verify neighboring pages after each new
piece (a template change can regress the home page). API success ≠ content landed — every write
gets read back (marker string or md5).

## Contents
- The checklist
- Front-end markup checks (curl)
- Responsive checks
- Behavioral checks (browser)
- Editor editability check
- Screenshot gotchas

## The checklist

```
QA pass:
- [ ] curl checks on every built page (markup, leaks)
- [ ] Screenshots at ~390px, ~700px, desktop — nav overlay OPEN included
- [ ] Horizontal-overflow check at 390px
- [ ] Focus behavior: pointer vs keyboard
- [ ] Mobile menu behavior (hit-test, close, anchor scroll)
- [ ] Editor editability: every custom block's side panel populated
- [ ] Regression: re-check previously built pages
```

## Front-end markup checks (curl)

Cheap and catches real bugs — curl each page and check:

- HTTP 200; every section's key markup present (images, repeater rows, anchors).
- **Zero literal `<!-- wp:` strings** in rendered output (broken block markup).
- **No BARE `u0026`** (no preceding backslash) in rendered output or stored content — that is
  the `&`-mangling bug (references/custom-blocks.md has the check-then-repair recipe). The
  escaped form WITH its backslash inside stored attribute JSON is normal Gutenberg
  serialization — do not "fix" it; a check that greps for `u0026` alone false-positives on
  every correctly serialized page.
- Expected token values (`--wp--preset--*`) and asset URLs; HEAD-check fonts/images (200).
- When verifying stored block JSON, grep for plain strings — serialized block JSON does not
  escape slashes (`"source":"core/post-meta"` appears literally).

## Responsive checks

- Screenshot at ~390px (phone), ~700px (tablet/mid), and desktop, using the original design's
  own breakpoints as the reference. Include the mobile nav overlay in its OPEN state.
- **Horizontal overflow at 390px** — screenshots don't catch it (the page looks fine, it just
  scrolls sideways). Run in the browser:
  compare `document.documentElement.scrollWidth` vs `clientWidth`, and walk all elements for
  `getBoundingClientRect().right > clientWidth` to name the exact offender. The classic cause is
  the missing border-box reset (references/css-rules.md).

## Behavioral checks (browser)

- **Focus modality**: a real mouse click on a link must compute `outline: none`
  (`:focus-visible` false); a real Tab keypress must show the accent ring. Script-only
  `.focus()` counts as *keyboard* modality — it cannot test the pointer path.
- **Mobile menu**: `document.elementFromPoint` hit-test on the close X (catches
  clipped-but-visible-but-unclickable), X-click closes, tapping a link closes the menu AND
  scrolls to the anchor.

## Editor editability check

Mandatory for every custom block, and again after recreating field groups. Open the page in the
block editor (fresh admin-access-link — sessions expire in ~30 min), select each block via
`wp.data.dispatch('core/block-editor').selectBlock(clientId)`, and confirm the side panel shows
**populated** fields (repeater rows present, image thumbnails set, dropdowns on the right
value). Text fields rendering on the front end does NOT prove fields exist — ACF falls back to
raw meta (references/custom-blocks.md). Editor previews should show the real design (the CSS
snippets' `load_in_block_editor` flag).

## Screenshot gotchas

- Lazy-loaded/offscreen images can render as gray boxes in full-page captures even when fine.
  Confirm with `img.complete && img.naturalWidth > 0` via evaluate, or screenshot the section
  in-viewport — don't chase phantom bugs. (`naturalWidth` is density-corrected under srcset `w`
  descriptors — a small number ≠ broken image.)
- Give screenshot tools an explicit output path — bare relative filenames can land in the
  project root.
