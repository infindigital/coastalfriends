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

## Page copies: `site/`

`site/` holds the exact rendered HTML of **every public URL** on https://coastalfriends.org (81 URLs: pages, all news posts, category/tag/author archives and all their pagination) plus the 404 page (`site/404.html`), downloaded as served. `site/<path>/index.html` is the copy of `https://coastalfriends.org/<path>/`.

## WordPress theme: `coastal-friends/`

A classic WordPress theme that reproduces **the whole site exactly**: all 81 URLs, including every page, news post, category/tag/author archive and all pagination. It works on an **empty WordPress install**: no pages, posts, Elementor or PRO Elements are needed.

How it works:
* `snapshots/*.php` hold each URL's exact `<body>` markup. `snapshot.php` wraps it in `wp_head()` / `wp_footer()`.
* `functions.php` routes by URL: when a request's path matches an original URL (e.g. `/about-us/`, `/ifthar-party-2/`, `/page/5/`), the theme serves that snapshot with a 200 status, whether or not that page or post exists in the database. Any other missing URL gets the original "page not found" design (`snapshots/not-found.php`) with a 404 status.
* `inc/page-assets.json` lists, per URL, the original title, body classes, stylesheets/scripts (in the original order), inline config, block CSS and image-lightbox script modules.
* `assets/` holds the CSS, JS, fonts and images. In the packaged zip the photos are WebP copies inside the theme. If a photo also exists in `wp-content/uploads/`, that copy is used instead.
* Contact forms: when PRO Elements isn't installed, the theme handles the forms itself (`inc/forms.php`) and emails each message with `wp_mail()` to the address set in **Appearance → Customize → Contact form** (default `info@coastalfriends.org`).
* New posts/pages and search are rendered by WordPress normally with the same header and footer.
* The snapshots are static: to change text, edit `snapshots/<page>.php`.

### Google Analytics
Appearance → Customize → **Google Analytics**: paste the GA4 Measurement ID (`G-XXXXXXXXXX`) and publish. The theme then adds the gtag.js snippet to every page. By default it doesn't count logged-in administrators (there's a checkbox to change that). Leave the field empty to turn tracking off.

### Install (one file)
`coastal-friends.zip` (≈123 MB) contains the theme **and every photo**. It's built by `tools/pack_theme.py`, which keeps the image sizes the pages display, adds one large copy per photo for the lightbox, and converts everything to WebP. The pages look the same; the photos are re-compressed.

1. WordPress → Appearance → Themes → Add New → **Upload Theme** → `coastal-friends.zip` → Install → **Activate**.
   If WordPress says the file is too large, the site's upload limit is under 123 MB (shown in Media → Add New as "Maximum upload file size"). Ask your host to raise `upload_max_filesize` and `post_max_size` to 256M.
2. Settings → Permalinks → choose **Post name** → Save.
3. Optional: Appearance → Customize → **Contact form** (recipient email) and **Google Analytics**.

### Rebuild
```bash
python3 -I tools/crawl.py tools/seed-urls.txt site                     # re-download every public page into site/
python3 -I tools/mirror.py tools/urls.txt coastal-friends/assets       # download assets listed in tools/urls.txt
python3 -I tools/relativize_css.py coastal-friends/assets              # point CSS at local copies
python3 -I tools/build_theme.py site coastal-friends                   # regenerate snapshots + manifest from site/
python3 -I tools/pack_theme.py coastal-friends dist && (cd dist && zip -qr -9 ../coastal-friends.zip coastal-friends)   # one installable zip
```
