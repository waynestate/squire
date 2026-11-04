---
title: "The agent-ready kit"
description: "The Markdown twins, llms.txt, llms-full.txt, robots.txt and sitemap.xml every stack repo carries, how to sync the kit in with --sync-to, and how to keep it out of library package downloads."
---

# The agent-ready kit

Every stack repo carries the same bytes so that agents and crawlers find the same material as people do. This page lists what the kit ships, how a site takes a new version, and the export-ignore lines a library package adds. Next, see [The docs builder](docs-builder.md) for the pages the kit styles.

## What the kit carries

Every stack repo carries the same bytes: a Markdown twin beside every page in the site folder, plus `llms.txt`, `llms-full.txt`, `robots.txt` and `sitemap.xml`, styled by the kit's `agent-ready.css` and `agent-ready.js` in the site's `assets/` folder. Squire owns the kit.

## Taking a new version of the kit

A site takes a new version from a Squire checkout with `php bin/agent-ready.php --sync-to=<repo>`, which copies the kit and the tool itself. The site then regenerates its files with `php bin/agent-ready.php` and commits them, and runs `--check` in CI to stay honest.

<!-- docs-test: skip needs a real project -->
```bash
php bin/agent-ready.php --sync-to=<repo>   # copy the kit and the tool into the repo
php bin/agent-ready.php                    # regenerate the files
php bin/agent-ready.php --check            # verify in CI; fail on drift
```

## Keeping the kit out of library packages

A library package that carries the kit should keep it out of Composer downloads: add to its `.gitattributes`

```
/bin/agent-ready.php export-ignore
/site export-ignore
```

so a `composer require` of the package never ships the kit. A squire packs what the knight will carry, and only that.
