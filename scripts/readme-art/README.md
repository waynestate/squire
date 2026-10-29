# Squire README art kit

Cuts the README images under `docs/readme/` from this repo's own front page
(`site/`), brand files and README. Nothing is drawn freehand: the hero and the
gallery are screenshots of the real page, the cast is the marks resized, the
badges read their facts from `composer.json` and the README's exit-code
paragraph, and the packing list is the site's own list, tag by tag.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL, aborts any
request that tries to leave the machine, and is deterministic: same inputs,
same outputs.

## What it makes

- `docs/readme/hero.jpg`: the hero (title, install command, satchel and the
  four tags on their rail), with the site header and the three copy lines that
  name the platform hidden.
- `docs/readme/packing-list.svg`: the packing list. A leather strap hangs one
  luggage tag per item of the site's list (`build.sh`,
  `deployments/001-first-release.sh`, `scripts/release.sh`, `health.php` and
  the parity kit), each stamped as it lands; then the satchel's own tag appears.
  Every word is read from `site/index.html` and the build fails if the list
  changes shape. Under `prefers-reduced-motion` the bag is simply full.
- `docs/readme/gallery.jpg`: two cuts of the page (the riding order and the
  S-rule patches, minus any patch that names the platform).
- `docs/readme/board.jpg`: the brand board, re-encoded.
- `docs/readme/cast/*.png`: one 96x96 mark per stack part, WayneUI on its
  green tile first.
- `docs/readme/badges/*.svg`: `php`, `licence` and `exit codes`, made locally
  by `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may name an
internal host or platform; the script prints each size and exits 1 if either
rule breaks.

If a tag ever goes missing, check the bag twice. A squire never blames the knight.
