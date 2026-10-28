# SQUIRE front page

A static page: `index.html`, `assets/squire.css`, `assets/squire.js`. No build step. Serve the `site/` folder from
anywhere (Citadel, any web server, or `python -m http.server` inside `site/` for a look).

- type: **Arvo** (SIL OFL 1.1, self-hosted in `assets/fonts/`, licence in `OFL-arvo.txt`) for display; Lato from WayneUI
  for body text; the system monospace for commands.
- base: WayneUI 2.0.8, copied from its static path into `assets/wayne-ui/`, then re-dressed in canvas and leather.
- world: the squire's kit bag. Canvas and waxed leather, stitched seams, luggage tags. Green and gold are the family tie.
- magic: the first time the packing list scrolls into view, the bag empties and packs itself for deploy day. Each tag
  flies into the satchel as `squire init` prints its line, gets stamped PACKED, and the bag gets its own luggage label
  when the buckle fastens. The buttons replay it and try `--check`, an edit, and `--force`.
- marks: `brand/` is SQUIRE's (generated with ChatGPT, never redrawn). `assets/marks/` holds the other stack parts'
  marks, copied from WELD's site; WayneUI uses its own reversed mark on green.
- reduced motion: the page is the packed bag, every word present at first paint; the buttons still work, instantly.

Screenshots are in `screenshots/`.

A squire carries the kit. The page just shows off the bag.

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
`the stack` folder. Dev is picked automatically on `localhost` or `127.0.0.1`; `?env=dev` or `?env=production` forces
one (remembered until `?env=auto`), `<meta name="stack-env">` sets the page default, and `?devBase=http://127.0.0.1:<port>`
points at a server on another port. WayneUI links stay on production.
