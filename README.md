*This document was LLM-generated.*

# bigdinosaur-static

A WordPress plugin that publishes [bigdinosaur.org](https://bigdinosaur.org)
as a static site. WordPress stays the editor and content store on a private
hostname; on **Generate** the plugin makes WordPress render every public page
as clean static HTML (no core CSS, scripts or class noise), saves the files,
copies the referenced media and theme assets, validates the result against a
fixed output contract and atomically swaps it into the webroot that nginx
serves. Nothing publishes except Generate (Tools → Static site) or the CLI.

**This is published as a reference, not as a general static-site plugin.** It
is built for one site and the
[bigdinosaur theme](https://github.com/code-a-saurus/BigDinoBlogTheme): the
theme name and version, the asset map, the supported block vocabulary, the
head census, the public-host list, the 404 page and the icons are all that
site's. The approach (render in place under a request header, rewrite block
output at `render_block` time, validate everything, two-slot publish) is the
reusable part; the code shows one complete, working way to do it. Read it
with that in mind.

Requires WordPress 7.1+, PHP 8.4+ (`Dom\HTMLDocument`), the `bigdinosaur`
theme 0.7.17+, PHP-FPM with `fastcgi_finish_request`, and WP-CLI for the
operator commands.

## Install

1. Copy `bigdinosaur-static/` to `wp-content/plugins/bigdinosaur-static/`
   owned by the web user. Leave `tests/` out of the webroot.
2. Create the static tree, owned by the web user, outside the WordPress root:
   `<ROOT>-builds/a`, `<ROOT>-builds/b`, `<ROOT>-builds/state`, and the
   symlink `<ROOT>` → `<basename>-builds/a` (relative target). nginx's static
   vhost uses `<ROOT>` as its root.
3. Add the constants below to `wp-config.php` (or a file it includes).
4. `wp plugin activate bigdinosaur-static`, then `wp bigdinosaur-static doctor`.

## Configuration (constants only, nothing in the database or on a settings page)

| Constant | Meaning |
|---|---|
| `BIGDINOSAUR_STATIC_SOURCE` | WordPress's own origin, `https://host`. Must equal the `home` option (or `WP_HOME`), or the plugin refuses to run |
| `BIGDINOSAUR_STATIC_ORIGIN` | The public static site's origin. Used for canonical, Open Graph, feed and sitemap URLs. Must differ from SOURCE |
| `BIGDINOSAUR_STATIC_ROOT` | Absolute path of the symlink nginx serves. Slots and state live in `<ROOT>-builds/` |
| `BIGDINOSAUR_STATIC_CF_ZONE`, `BIGDINOSAUR_STATIC_CF_TOKEN` | Optional. When both are set, every publish ends with a Cloudflare "purge everything" on that zone; a failed purge is a warning in the report, not a rollback. The token needs Zone → Cache Purge only |

Also honored: WordPress's own **Settings → Reading → "Discourage search
engines"**. On, the generated `robots.txt` disallows everything (a test
site); off, it allows everything and names `/sitemap.xml`.
`DISABLE_WP_CRON` may be set or not: Generate spawns WP-Cron itself.

### Deployment shape

| | Test site | Production |
|---|---|---|
| WordPress / `SOURCE` | `https://wp.example.lan` | `https://wp.example.com` (reachable only over a VPN) |
| Static site / `ORIGIN` | `https://static.example.lan` | `https://example.com` (CDN in front) |
| `ROOT` | `/var/www/static` | `/var/www/site/static` (slots in `/var/www/site/static-builds/{a,b,state}`) |
| Cloudflare purge | off | on, zone-scoped Cache Purge token |

The two hostnames can be served by the same nginx: one vhost runs PHP for the
editor site, the other serves `<ROOT>` as plain files with a redirect table
for WordPress-style paths and `/404.html` as the error page. Shipping a theme
change is: deploy the theme, press Generate. The validator fails the build if
the theme's head changes shape, so theme and plugin change together, and the
plugin refuses to run with a theme older than the version it was written for.

## Commands (run as the web user, `--path` to the WordPress root)

```text
wp bigdinosaur-static doctor               site guard, constants, slot layout and link, lock, a build-context fetch,
                                           Cloudflare token, cron settings, loopback, last cron build, disk, versions
wp bigdinosaur-static build [--no-publish] [--notes]
                                           manifest → fetch → assets, robots, sitemap → media → validate → switch the
                                           link (unless --no-publish). --notes prints the coverage notes
wp bigdinosaur-static publish              switch the link to the inactive slot if it holds a validated, unpublished build
wp bigdinosaur-static status [--format=json]
                                           live slot and build, the other slot, last build, lock, queue, changes pending
```

**Generate** (Tools → Static site, administrators) schedules the one-off
WP-Cron event `bigdinosaur_static_build` and calls `spawn_cron()`, so the
build runs at once in a detached PHP-FPM worker; the page refreshes while it
runs and then shows the result. It refuses while a build is running and
leaves an already queued event alone. The same page shows what is live and
since when, whether WordPress has changes the static site lacks (and the
latest one), the queue, and the last build's result with any failures and
coverage notes. The Posts and Pages screens carry a one-line notice while
changes are pending.

## The staging model

WordPress is staging. Publish, edit, delete and schedule there freely; none
of it is public until Generate. A scheduled post that goes live in WordPress
appears publicly at the next Generate. The "changes since last publish"
indicator compares the time of the last change with the start of the live
build; it is never cleared, so failed builds, `--no-publish` builds and
manual slot switches never make the page claim the site is current.

## How a page is produced

A build fetches every route from `BIGDINOSAUR_STATIC_SOURCE` with the request
header `X-Bigdinosaur-Static-Render: 1` (no cookies, no redirects, TLS
verified against the system trust store). With that header WordPress itself
renders the clean document: `home_url` filtered to the destination origin,
core head and footer cruft removed, `<meta name="generator" content="Wordpress
Static">` added, Redis Object Cache's end-of-page comment switched off (its
`WP_REDIS_DISABLE_COMMENT` constant, defined for the request), only the
theme's stylesheet and scripts, `body`/`post`/menu classes reduced, each
supported block's output rewritten to the site vocabulary at `render_block`
time, then one final pass over URL attributes (root-relative navigation and
assets, `/wp-content/uploads/` → `/media/`, theme assets → `/css`, `/js`,
`/fonts`, `/img`). Text, `<code>` and `<pre>` are never edited. The header
grants nothing: anonymous output only, never previews, drafts or private
content. Without the header, WordPress serves its ordinary pages; the plugin
changes nothing about the editor site.

A render failure (unsupported block, unregistered shortcode, classic content)
answers with status 500 and an `X-Bigdinosaur-Static-Failures` header naming
each one; the fetcher refuses the page and the build report carries the list.

Supported blocks: paragraph, heading, image, gallery, quote, pullquote, code,
list, columns, embed (YouTube), more, synced patterns (`core/block`) and
registered shortcodes. Any other block type, any `is-style-*` variation, an
unregistered shortcode or classic (non-block) content **fails the build**
with the post named; nothing is dropped silently. Lightboxes are removed.
A paragraph or heading set to one of the theme's editor font-size presets
(`editor-font-sizes` in functions.php: small, excerpt, body, heading, title)
keeps it: `has-<slug>-font-size` becomes `text-<slug>`, and `site.css` gains
the matching `.text-<slug> { font-size: Npx !important }` rule, generated
from the theme's registration at build time. A preset the theme does not
register fails the build like any other `has-` class.

## Output layout

```text
/                       /page/N/            /{post}/  /{page}/
/categories/{slug}/     /tags/{slug}/       /{yyyy}/  /{yyyy}/{mm}/   (+ /page/N/ on lists)
/feed.xml  /sitemap.xml  /robots.txt  /404.html
/css/site.css?v=<hash>  /css/prism.css?v=<hash>  /js/post-list.js?v=<hash>  /js/prism.js?v=<hash>
/fonts/*.woff2  /img/mascot.png  /img/card.png  /favicon.ico  /icon-32.png  /icon-192.png  /apple-touch-icon.png
/media/YYYY/MM/*        (hardlinked from uploads when on the same filesystem, else copied)
```

nginx redirects the WordPress-style paths (`/category/`, `/tag/`, `/feed/`,
`/wp-sitemap*`, `/wp-content/uploads/`, `/author/`) and serves `/404.html`
for everything else. `/author/` is not generated (single author), `?p=N` is
not supported, and reader comments are out of scope.

## Validation

Every HTML file of a candidate must pass: zero `<style>`, only the two
allowed stylesheets, exactly the theme's two inline scheme scripts plus
`post-list.js` on lists and Prism on `code`-tagged posts, no `wp-`/`has-`/
`is-`/`size-`/`columns-`/`postid-` class or `data-wp-*` attribute, every
class in the compiled stylesheet's set, no attribute URL on the source
origin or a WordPress path, no HTML comment (after `</html>` included), the
expected head element census per page type with the generator meta reading
`Wordpress Static`, every root-relative URL resolving to a file in the
candidate, feed and sitemap parsing as XML, feed GUIDs equal to the source
feed's. One failure means the link does not move. The head census is tied to
the theme's exact head, so theme and plugin change together.

## Source layout

```text
bigdinosaur-static.php   entry: constants, requires, hooks
src/Config.php           wp-config constants and the site guard
src/Urls.php             Urls (URL mapping), Tidy (tag whitespace)
src/Assets.php           theme/plugin assets, CSS class/path mapping, hashes
src/Blocks.php           block output in the site vocabulary
src/Render.php           build-context rendering hooks and the final buffer pass
src/Feed.php             the RSS2 feed under build context
src/Manifest.php         routes and sitemap
src/Validator.php        the output contract
src/Slots.php            Slots (two-slot layout), SlotWriter
src/Build.php            Build (engine), Fetcher, Worker (WP-Cron runner)
src/Purge.php            Cloudflare purge
src/Changes.php          "changes since last publish"
src/Admin.php            Tools → Static site (admin only)
src/Cli.php              WP-CLI commands (WP-CLI only)
assets/                  blocks.css, 404.html, icons
tests/                   wp eval-file tests, never deployed
```

## State and reports

`<ROOT>-builds/state/`: `lock` (flock; released by the kernel if the process
dies), `report.json` (current or last build, rewritten as it progresses),
`slot-a.json`/`slot-b.json` (the build each slot holds), `build.log` (one
line per build or publish). A report has `id`, `slot`, `result`
(`running|validated|published|failed`), `started`/`started_ts`, `finished`,
`duration`, `runner` (`via` cli/cron, SAPI, pid), `pages` (path → bytes),
`assets` and `media` (path → sha256), `failures`, `warnings` (a failed
Cloudflare purge lands here), `notes` (coverage: external media not in the
local uploads, internal links with no route).

Before clearing the inactive slot, the engine replaces its slot report with
the new build's `running` record under the build lock. An interrupted build
therefore cannot inherit an earlier candidate's permission to publish.
Admin and CLI (including JSON) share the same status snapshot: live report,
last change, pending flag, queued event and worker error as well as slot state.

## Going back, regenerating, uninstalling

The static site is disposable; there are no retained releases or backups.
Two slots: a build goes into the slot the link does not point at, then the
link switches, so the previous version stays in the other slot. To go back
one build, switch the link by hand (atomic, no nginx reload; do not do it
while a build runs):

```sh
cd <dir of ROOT> && sudo -u www-data ln -s <basename>-builds/b ROOT.tmp && sudo -u www-data mv -T ROOT.tmp ROOT
```

Then purge the CDN by hand. To fix content, fix WordPress and Generate. A
failed build leaves the link alone and its output in the inactive slot for
inspection.

Uninstall: deactivate (clears the cron event), delete the plugin, remove the
static vhost. Content is untouched. The static tree and two options
(`bigdinosaur_static_changed`, `bigdinosaur_static_worker_error`) remain
unless removed by hand.

## Tests

`tests/*.php` run with `wp eval-file` as the web user from a staging copy
outside the webroot: block fixtures through the render filters, the URL
mapper, the validator against live output with seeded defects, the slot layer
in a scratch directory, the change indicator with in-memory posts, robots and
purge configuration. They write only scratch directories and restore any
option they touch. Their fixtures assume a small test database; run them
against a test site, not production.

`php tests/build-lifecycle.php` also runs without WordPress. It exercises the
real build/publish code in isolated child processes with stubbed WordPress
calls: interruptions after clearing a slot and during fetch, rejection of
stale validation, successful publication, and complete JSON status. It uses
scratch files only and makes no network requests.

## Assets

The published CSS and JavaScript are assembled from the theme's files and
the plugin's own, with no minification or other transformation of the text:

- `/css/site.css` is `assets/blocks.css`, then the theme's `style.css` with
  its theme metadata header removed, its font URLs pointed at `/fonts/` and
  its block class names mapped to the site vocabulary, then the font-size
  preset rules described above.
- `/css/prism.css`, `/js/prism.js` and `/js/post-list.js` are the theme's
  files, copied byte for byte.

`Assets::bytes()` refuses to publish any of these (prism.js excepted) if it
contains `wp-`, `wp_` or `WordPress` in any case, because such a string means
a WordPress reference survived the mapping. The check reads the whole file,
so the rule applies to selectors, paths and comments alike in
`assets/blocks.css`, the theme's `style.css` and `post-list.js`.

Whitespace tidying runs once per final HTML document or feed item, after URL
mapping, and never touches CSS or JavaScript.

`assets/blocks.css` reproduces the core block rules this site's content
relies on, translated to the site vocabulary (WordPress core is
GPL-2.0-or-later, as is this plugin):

- It precedes the theme CSS so the cascade matches WordPress. Lazy images
  with `sizes="auto"` reserve an intrinsic size until loaded.
- Both image shapes are supported: `div.image > figure.align-*` and
  `figure.image.align-*`. Captions use core's table-caption layout.
- Galleries use flex layout and a 0.5em gap, with white captions over images.
  Counts above two apply from 600px; narrower screens use two columns.
  The block mapper turns the default count into numeric `data-columns="3"`.
- `.code code` keeps `break-spaces` with the specificity of core's code rule.
  On code-tagged posts, Prism's later `pre[class*="language-"]` rule ties
  and wins, so long code lines scroll rather than wrap.
- Columns use a 2em gap and stack below 782px. Embed aspect wrappers reserve
  their ratio and position the iframe inside it. Theme CSS retunes drop caps,
  quotes and pullquotes after these base rules.

## Limitations

- Built for the `bigdinosaur` theme and its content; not a general exporter.
  Making it one would mean a theme-contract layer (filters for the asset map,
  class map, head census and public hosts), theme-provided `blocks.css`, 404
  page and icons, and dropping the theme version gate.
- A link to a page that has no route in the build is a coverage note, not a
  failure (site rule: public-site links are always made root-relative).
- A `bigdinosaur.org/wp-content/uploads/` image whose file is not in the
  local uploads tree stays external and is noted. On bigdinosaur.org every
  file exists, so these notes are expected to be empty.
- A new upload marks "changes pending" (core rewrites attachment metadata
  while generating sizes); harmless.
- The build needs the source hostname to resolve and its certificate to be
  trusted from the host itself; a DNS blip fails the build safely, press
  Generate again.

## License

GPL-2.0-or-later; see `LICENSE`. `assets/blocks.css` is a translation of
WordPress core's block stylesheets (themselves GPL-2.0-or-later) into this
site's class vocabulary. The icons and 404 page are the site's own. No
third-party code is vendored; the PrismJS files the build copies belong to the
theme and carry their full MIT notice, so the published site needs no separate
license file for them.
