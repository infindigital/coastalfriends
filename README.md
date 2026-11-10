# Coastal Friends

## Static website: `static-site/`

`static-site/` is a complete, standalone HTML copy of https://coastalfriends.org. It doesn't need WordPress, a database or the old site.

* **81 pages**: every page, every news post, category/tag/author archives and all their pagination (`/page/2/` … `/page/13/`, `/gallery/2/`, `/gallery/3/` …), plus `404.html`.
* Each page is `<path>/index.html`, so the URLs stay exactly the same (`/about-us/`, `/ifthar-party-2/`, `/category/gallery/page/2/` …).
* All CSS, JavaScript, fonts and images are inside the folder at their original locations (`wp-content/…`, `wp-includes/…`). Every link is relative, and nothing is loaded from the old site.
* The contact forms work: `wp-admin/admin-ajax.php` is a small PHP script that emails each submission to `info@coastalfriends.org` (change `$cf_to` at the top of that file). It needs PHP `mail()`, which Hostinger provides.
* `.htaccess` sets `index.html` as the folder index and serves `404.html` for missing pages.

### Upload to Hostinger
1. Hostinger hPanel → **File Manager** → open `public_html` and delete the old WordPress files (keep a backup).
2. Upload `coastal-friends-static-1-site.zip`, `coastal-friends-static-2-images.zip` and `coastal-friends-static-3-images.zip` into `public_html` and **Extract** each one there. They fill the same folders.
3. Open https://coastalfriends.org/ to check it. Send a test message from the Contact Us page.

### Rebuild
```bash
python3 -I tools/build_static.py site coastal-friends/assets static-site
```

`site/` holds the exact rendered HTML of **every public URL** on https://coastalfriends.org (69 URLs: pages, all news posts, category/tag/author archives and their pagination), downloaded as served. `site/<path>/index.html` is the copy of `https://coastalfriends.org/<path>/`.

## WordPress theme: `coastal-friends/` (earlier version, not needed for the static site)

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
