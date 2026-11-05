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

## Checklist

- [ ] `docs/docs.json` has a `theme` key with `css` and `header`.
- [ ] Both paths are relative to the folder that holds `docs.json`.
- [ ] Your CSS carries dark mode, fonts and colors, and the kit's `docs.css` stays untouched.
- [ ] The header uses only `{{project}}` and `{{version}}`.
- [ ] Header links are relative to `site/`.

The rest of the builder is in [Building the docs](docs-builder.md).
