# Coastal Friends

`site/` holds the exact rendered HTML of **every public URL** on https://coastalfriends.org (69 URLs: pages, all news posts, category/tag/author archives and their pagination), downloaded as served. `site/<path>/index.html` is the copy of `https://coastalfriends.org/<path>/`.

## WordPress theme: `coastal-friends/`

A classic WordPress theme that reproduces **every page of the site exactly**: all 69 URLs, including every news post, category, tag and author archive, and their page 2/3/4.

How it works:
* `snapshots/*.php` hold each URL's exact `<body>` markup. `snapshot.php` wraps it in `wp_head()` / `wp_footer()`.
* `functions.php` has a router: when a request's path matches an original URL (e.g. `/about-us/`, `/ifthar-party-2/`, `/category/gallery/page/2/`), the theme serves that snapshot with a 200 status. This happens **whether or not that page or post exists in the WordPress database**, so the site shows completely even on a fresh WordPress install. WordPress's redirects/404s are disabled for those URLs.
* `inc/page-assets.json` lists, per URL, the original title, body classes, stylesheets/scripts (enqueued in the original order under Elementor's own handle names), inline config and extra tags (block CSS, lightbox script modules).
* `assets/` holds every CSS, JS, font and image the pages use (mirrors `wp-content/`). Media-library images are taken from the site's own `wp-content/uploads/` when the file is there, and from the theme's copy otherwise.
* Anything new (new posts/pages, search, 404) is rendered by WordPress normally with the same header and footer (`header.php`, `footer.php`, `singular.php`, `index.php`, `404.php`).
* The snapshots are static: to change their text, edit `snapshots/<page>.php`. New posts won't appear in the old "latest posts" grids.
* The contact forms still post to Pro Elements. Keep **Elementor + Pro Elements active** if the forms must send.

### Google Analytics
Appearance → Customize → **Google Analytics**: paste the GA4 Measurement ID (`G-XXXXXXXXXX`) and publish. The theme then adds the gtag.js snippet to every page. By default it doesn't count logged-in administrators (there's a checkbox to change that). Leave the field empty to turn tracking off.

### Install
Two packages are built from this folder:
* `coastal-friends-lite.zip`: without the media-library images (`assets/uploads/20xx`). Use it on **coastalfriends.org** or a migrated copy, where the images are already in the Media Library.
* `coastal-friends-full.zip`: everything bundled, for a brand-new site. It is large, so upload it with the hosting File Manager / FTP: extract into `wp-content/themes/`.

Then: Appearance → Themes → activate **Coastal Friends**. Back up the site first. If old URLs show "page not found", go to Settings → Permalinks and pick **Post name** (`/%postname%/`), then Save.

### Rebuild
```bash
python3 -I tools/crawl.py tools/seed-urls.txt site                     # re-download every public page into site/
python3 -I tools/mirror.py tools/urls.txt coastal-friends/assets       # download assets listed in tools/urls.txt
python3 -I tools/relativize_css.py coastal-friends/assets         # point CSS at local copies
python3 -I tools/build_theme.py site coastal-friends                 # regenerate snapshots + manifest from site/
```
