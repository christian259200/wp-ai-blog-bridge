# Changelog

## 2.2.0

Three defects found by auditing 38 posts published with this plugin on a live
site, plus the licence file.

### Fixed: the H1 promotion never ran

`ABB_Frontend::promote_title` closed its pattern with a literal `</h>`, which
is not valid HTML and therefore matched nothing. The feature was on by default
and did nothing at all, silently, in every build up to 2.1.2.

Two things were wrong. The closing tag now back-references the heading level
captured at the start, `<\/h\1>`, and the file itself carried literal control
bytes where `\b` and `\1` belonged, written by a shell that interpreted the
escapes. Both are gone.

If you run this plugin and assumed your posts had an H1, check one. They
probably do not.

### Fixed: the stylesheet hid the only H1 on the page

`assets/abb-frontend.css` hid the theme's breadcrumb banner on single posts to
clean up the layout. That banner is where this theme puts the H1, so
`display: none` removed the only H1 from what search engines count. Every post
ended up with no usable H1 and a visible title that was only an H2.

The banner now stays, stripped of its background and breadcrumbs, and its H1 is
restyled as the article title. The theme's duplicate `.entry-title` is hidden
instead, so there is exactly one title and it is the H1.

### Fixed: two BlogPosting nodes on the same URL

Yoast, Rank Math and SEOPress each publish their own Article node. This plugin
published a second one alongside it. That is not extra coverage: each node
carries its own author, dates and images, and when they disagree a search
engine picks one without telling you which.

New setting under Settings, AI Blog Bridge:

| Mode | Behaviour |
| --- | --- |
| `auto` (default) | Skip the BlogPosting node when an SEO plugin is active |
| `always` | Publish it regardless |
| `never` | Never publish it |

The FAQPage node is always published, because no SEO plugin builds it from this
payload. The request payload can override the setting per post with
`schema_blogposting`, and the older `schema_skip_blogposting` flag still works.

**Upgrade note:** if you have been running this alongside an SEO plugin, your
published posts already carry two Article nodes. Re-publishing them through the
client rewrites the schema with the new default.

### Added: `--strict` on the publishing client

A dropped internal link produced a warning and a successful publish. In a batch
the warning scrolls past and the post ships with fewer links than intended.
This happened to two posts in a batch of seven before the flag existed.

```bash
python publish.py posts/ --strict
```

Exits non-zero when a post publishes with warnings or audit errors, so a
pipeline stops instead of finishing green.

### Changed: licence file

`LICENSE` held a 27 line summary instead of the licence. GitHub could not
identify it and displayed the repository as having no licence, which leaves
anyone reading it unsure what they are allowed to do. It now contains the full
GPL-2.0 text as published by the FSF.
