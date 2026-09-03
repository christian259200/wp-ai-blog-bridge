# AI Blog Bridge

A WordPress plugin that receives blog posts over the REST API and publishes them
as proper Gutenberg content: headings with anchors, tables, key takeaways, a
table of contents, embedded video, internal links with tooltips, SEO meta,
JSON-LD schema, and an on-page audit that runs on every publish.

Write wherever you want, with whatever tool you want. Send it here. Get a
finished post.

## Why

Publishing AI-written content to WordPress usually goes one of two ways: you
paste into the editor and lose an hour on formatting, or you push raw HTML
through the REST API and end up with a wall of paragraphs, no schema, no
internal links, and no idea whether any of it is good.

This sits in between. You send structure, it renders structure, and it tells you
what is wrong before you find out from Search Console three months later.

## What it does

**Content**

- Markdown to Gutenberg blocks: headings with anchors, lists, tables, images, quotes
- YouTube URLs become privacy-friendly `youtube-nocookie` embeds, lazy loaded
- Key takeaways box, automatic table of contents, sources section
- FAQ section plus `FAQPage` JSON-LD
- `BlogPosting` schema, canonical, Open Graph and Twitter Card tags
- Internal links injected into the body by anchor text, with `title` tooltips
- Author byline, freshness stamp, cornerstone flag

**Quality control**

An audit runs on every publish and returns errors, warnings and notes:

| Check | Threshold |
| --- | --- |
| Meta title | 30-70 chars, contains the focus keyword |
| Meta description | 120-160 chars, contains the focus keyword |
| First paragraph | contains the focus keyword |
| Headings | exactly one H1, 3-10 H2, keyword whole in one of them |
| Length | 300 words minimum |
| Internal links | 3-10, varied anchor text |
| Citations | at least one outbound link |
| Images | alt text required, title recommended |
| Structure | a list or a table, a question heading or an FAQ |
| Semantic cues | at least one, so language models know what to quote |
| URL | under 100 chars, at most two directories |

Keyword matching folds diacritics, so `que es serigrafia` matches
`qué es serigrafía`. That is not cosmetic: without it every accented language
fails every keyword check.

**SEO plugin support**

Writes meta for Rank Math, Yoast and SEOPress, and detects which one is active.

## Install

1. Download the zip from [Releases](../../releases), or build it with
   `python build-zip.py`
2. Upload at `Plugins → Add New → Upload Plugin`
3. Activate
4. Open `Settings → AI Blog Bridge` and copy the API token

## Publish

```bash
cd client
cp .env.example .env        # fill in three lines
pip install -r requirements.txt
python publish.py post.md
```

Authentication works two ways: the plugin's API token in an `X-ABB-Token`
header, or a standard WordPress Application Password over HTTP Basic. Prefer the
Application Password unless a security plugin has disabled them.

The endpoints require an authenticated account with `publish_posts`. Nothing
here is open to the public.

### Post format

Markdown with YAML frontmatter. Everything except title, slug and content is
optional.

```yaml
---
title: "What is DTF printing and when it beats screen printing"
slug: what-is-dtf-printing
status: publish
description: "What is DTF printing, how the transfer works, which fabrics it suits and how it compares with screen printing."
focus_keyword: "what is dtf printing"
categories: [Blog]
tags: [dtf, apparel]
toc: true
key_takeaways:
  - "DTF prints onto film and transfers with heat, so fabric type stops mattering."
internal_links:
  - anchor: "screen printing"
    url: https://example.com/screen-printing/
    title: "Read the full screen printing guide"
faq:
  - question: "What does DTF stand for?"
    answer: "Direct to film."
---

What is DTF printing: a technique that prints a design onto a transparent film
and transfers it to the garment with heat and pressure.
```

Full field reference in [`examples/`](examples/).

### Useful commands

```bash
python publish.py post.md --dry-run     # inspect the payload, send nothing
python publish.py posts/ --glob "*.md"  # a folder
python publish.py --ping                # check the connection
python publish.py --audit 123           # re-audit a published post
python publish.py --stale 90            # list posts not updated in 90 days
```

**Publishing a batch:** one at a time, with a pause. Shared hosting throttles
bursts and answers with an HTML error page instead of JSON.

```bash
for f in posts/*.md; do python publish.py "$f"; sleep 20; done
```

## About the CSS, read this before installing on a client site

`assets/abb-frontend.css` does two different jobs, and they are not equally
portable.

**Portable.** Styling for the blocks the plugin creates: the takeaways box, the
table of contents, tables, video embeds, the byline, the sources list. These are
`abb-*` classes and work on any theme.

**Not portable.** Layout fixes written for the **XStore** theme: hiding the
sidebar on posts, forcing the archive into a card grid, removing the share row
and the breadcrumb banner. Roughly half the file. On another theme those rules
match nothing, which is harmless, but you will not get the same layout.

If you use a different theme, keep the `abb-*` sections and replace the theme
ones. Pull requests that generalise this are very welcome.

## Optional: meta title and description generator

A metabox in the post editor generates a meta title and description with OpenAI.
It stays off unless you configure a key.

Put the key in `wp-config.php`, not in the database:

```php
define( 'ABB_OPENAI_KEY', 'sk-...' );
```

The rest of the plugin does not need it. Content generation happens on your own
machine, not on the server.

## Security

- REST endpoints require an authenticated user with `publish_posts`
- The shared token is compared with `hash_equals`, which resists timing attacks
- No credential is stored in the plugin's code
- The OpenAI key is read from `wp-config.php` first, the database only as fallback
- Uninstalling removes the plugin's own options and log table

If you find a security problem, open an issue.

## Building the zip

```bash
python build-zip.py
```

**Do not use PowerShell's `Compress-Archive`.** It writes archive paths with
backslashes and WordPress rejects the result with "The plugin file does not
exist." The build script asserts forward slashes and refuses to ship a `.env`.

The folder inside the zip must match the installed slug. Packaging under a
different name makes WordPress install a second copy beside the running one, and
two active copies redeclaring the same classes take the site down.

## Requirements

- WordPress 6.0+, PHP 7.4+
- Python 3.9+ with `requests` and `pyyaml`, for the publishing client

## Companion skill

[claude-wp-blog-skill](https://github.com/christian259200/claude-wp-blog-skill)
is a Claude Code skill that writes the posts this plugin publishes: keyword
selection that avoids cannibalisation, video research, and a local audit that
mirrors this one. It also works standalone, without this plugin.

## Licence

GPL-2.0-or-later, as WordPress requires.
