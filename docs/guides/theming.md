---
title: "Theming your docs"
description: "Point the docs builder at your own CSS and header so the pages read in your colors."
---

# Theming your docs

A squire swaps the livery without touching the armor: this page shows how to point the docs builder at your own CSS and header. Read [Building the docs](docs-builder.md) first if you have not met the builder, then add the two files below.

> [!NOTE]
> The `theme` key is on a branch and not in a release yet. It is coming in the next release.

## The theme key

Add a `theme` key to `docs/docs.json` with two paths, `css` and `header`. Both paths are relative to the folder that holds `docs.json`.

```json
{
    "theme": {
        "css": "theme/docs.css",
        "header": "theme/header.html"
    }
}
```

## What the builder does

The builder copies your CSS file to `site/docs/theme/`. Dark mode, fonts and colors live in that theme CSS, and the kit's `docs.css` is never edited.

## Placeholders and links

The header file takes exactly two placeholders, `{{project}}` and `{{version}}`. Any other placeholder fails the build with exit 2. Write header links relative to `site/`, not relative to `docs/`.

The front page can carry the same version. Put `data-docs-version` on the element that holds it, in any top-level `site/*.html` page, and leave only the version text inside: `<span class="brand__version" data-docs-version>0.6.0</span>`. A plain build writes the docs stamp there, and `--check` reports the page stale after a release until you rebuild. Words around the number (Rev, Sheet, v) stay outside the element.

## Checklist

- [ ] `docs/docs.json` has a `theme` key with `css` and `header`.
- [ ] Both paths are relative to the folder that holds `docs.json`.
- [ ] Your CSS carries dark mode, fonts and colors, and the kit's `docs.css` stays untouched.
- [ ] The header uses only `{{project}}` and `{{version}}`.
- [ ] Header links are relative to `site/`.
- [ ] The front page's version chip carries `data-docs-version`, so it matches the docs.

## A worked example

These pages are one. Squire's `docs/docs.json` names `theme/squire.css` and `_header.html`: the header is the front page's masthead, and the CSS maps the front page's canvas, leather and gold onto the kit's `--dk-*` tokens, then adds the luggage tags, the stitched seams and a derived dark set. Read those two files in the repository for a theme that passes the accessibility gate in light and dark.

The rest of the builder is in [Building the docs](docs-builder.md).
