# Coastal Friends

Exact rendered HTML of the published pages on https://coastalfriends.org, downloaded as served on 2026-11-09.
CSS, JS and images still load from coastalfriends.org via absolute URLs.

| File | Source URL |
|---|---|
| index.html | https://coastalfriends.org/ (Home) |
| about-us.html | https://coastalfriends.org/about-us/ |
| contact-us.html | https://coastalfriends.org/contact-us/ |
| events.html | https://coastalfriends.org/events/ |
| gallery.html | https://coastalfriends.org/gallery/ |
| our-team.html | https://coastalfriends.org/our-team/ |
| sufyhome.html | https://coastalfriends.org/sufyhome/ |
| home-1.html | https://coastalfriends.org/home-1/ |

## WordPress theme: `coastal-friends/`

A classic WordPress theme that reproduces the pages above exactly. In a side-by-side render of all 8 pages at 1366px it matched the live site pixel for pixel. It doesn't need Hello Elementor, and the page designs don't need Elementor to display.

| Template | Used for |
|---|---|
| `front-page.php` | Home (static front page) |
| `page-about-us.php`, `page-contact-us.php`, `page-events.php`, `page-gallery.php`, `page-our-team.php`, `page-sufyhome.php`, `page-home-1.php` | Pages with those slugs |
| `singular.php` | News posts and any other page (renders the post content) |
| `index.php`, `404.php`, `comments.php` | Archives/search, not found, comments |
| `header.php`, `footer.php` | Shared header (Elementor template #505) and footer (#526); the menu highlights the current page |

* `assets/` holds every CSS, JS, font and image the pages use (mirrors `wp-content/`). WordPress core scripts (jQuery, imagesloaded, wp-i18n…) come from WordPress itself.
* `inc/page-assets.json` lists, per page, the stylesheets/scripts to enqueue in the original order. `functions.php` enqueues them under Elementor's own handle names. If the Elementor plugins stay active, the theme's copies replace theirs, so nothing loads twice.
* Site links use `home_url()` and asset links use the theme URL, so the theme works on any domain or staging URL.
* The pages are static: text and the "latest posts" grids are frozen as they were on 2026-11-09. Edit the template files to change them.
* The contact forms still post to Pro Elements. Keep **Elementor + Pro Elements active** if the forms must send.

### Install
1. Back up the site.
2. Appearance → Themes → Add New → Upload, choose `coastal-friends.zip` (≈55 MB). If the host's upload limit is lower, upload the `coastal-friends/` folder to `wp-content/themes/` over FTP / File Manager.
3. Activate **Coastal Friends**. Settings → Reading must keep "Home" as the static front page.

### Rebuild
```bash
python3 -I tools/mirror.py tools/urls.txt coastal-friends/assets       # download assets listed in tools/urls.txt
python3 -I tools/relativize_css.py coastal-friends/assets         # point CSS at local copies
python3 -I tools/build_theme.py . coastal-friends                 # regenerate templates + manifest from the *.html snapshots
```
