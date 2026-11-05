---
title: "Theming your docs | Squire docs"
description: "Point the docs builder at your own CSS and header so the pages read in your colors."
url: https://squire.apps.wayne.edu/docs/guides/theming.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Theming your docs[#](https://squire.apps.wayne.edu/docs/guides/theming.html#theming-your-docs)

A squire swaps the livery without touching the armor: this page shows how to point the docs builder at your own CSS and header. Read [Building the docs](https://squire.apps.wayne.edu/docs/guides/docs-builder.html) first if you have not met the builder, then add the two files below.

Note

The `theme` key is on a branch and not in a release yet. It is coming in the next release.

## The theme key[#](https://squire.apps.wayne.edu/docs/guides/theming.html#the-theme-key)

Add a `theme` key to `docs/docs.json` with two paths, `css` and `header`. Both paths are relative to the folder that holds `docs.json`.

```json
{
    "theme": {
        "css": "theme/docs.css",
        "header": "theme/header.html"
    }
}
```

## What the builder does[#](https://squire.apps.wayne.edu/docs/guides/theming.html#what-the-builder-does)

The builder copies your CSS file to `site/docs/theme/`. Dark mode, fonts and colors live in that theme CSS, and the kit's `docs.css` is never edited.

## Placeholders and links[#](https://squire.apps.wayne.edu/docs/guides/theming.html#placeholders-and-links)

The header file takes exactly two placeholders, `{{project}}` and `{{version}}`. Any other placeholder fails the build with exit 2. Write header links relative to `site/`, not relative to `docs/`.

## Checklist[#](https://squire.apps.wayne.edu/docs/guides/theming.html#checklist)

- [ ] `docs/docs.json` has a `theme` key with `css` and `header`.
- [ ] Both paths are relative to the folder that holds `docs.json`.
- [ ] Your CSS carries dark mode, fonts and colors, and the kit's `docs.css` stays untouched.
- [ ] The header uses only `{{project}}` and `{{version}}`.
- [ ] Header links are relative to `site/`.

## A worked example[#](https://squire.apps.wayne.edu/docs/guides/theming.html#a-worked-example)

These pages are one. Squire's `docs/docs.json` names `theme/squire.css` and `_header.html`: the header is the front page's masthead, and the CSS maps the front page's canvas, leather and gold onto the kit's `--dk-*` tokens, then adds the luggage tags, the stitched seams and a derived dark set. Read those two files in the repository for a theme that passes the accessibility gate in light and dark.

The rest of the builder is in [Building the docs](https://squire.apps.wayne.edu/docs/guides/docs-builder.html).
