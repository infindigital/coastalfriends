"""Convert the crawled coastalfriends.org pages (site/<path>/index.html) into the
Coastal Friends WordPress theme.

Every URL becomes theme/snapshots/<key>.php holding that page's exact <body>
markup, and inc/page-assets.json records what its <head>/footer loaded. The
theme's router (functions.php) serves the snapshot for that URL path, whether or
not the page exists in the WordPress database.

usage: python3 -I tools/build_theme.py site coastal-friends
"""
import glob, hashlib, json, os, re, sys

SRC, THEME = sys.argv[1], sys.argv[2]
SITE = r"https?:(?:\\/|/){2}(?:www\.)?coastalfriends\.org"
# WordPress core scripts: enqueue WordPress's own copies by handle (plugins depend on them).
CORE_SCRIPTS = {"jquery-core": "jquery", "jquery-migrate": "jquery", "jquery-ui-core": "jquery-ui-core",
                "imagesloaded": "imagesloaded", "wp-hooks": "wp-hooks", "wp-i18n": "wp-i18n"}
# Tags WordPress core prints itself on any site; not copied into the theme.
CORE_INLINE = {"wp-img-auto-sizes-contain-inline-css", "wp-emoji-styles-inline-css", "global-styles-inline-css",
               "wp-custom-css", "wp-emoji-settings"}


def php_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def asset_path(path):
    """/wp-content/x -> x ; /wp-includes/x -> includes/x (the layout of assets/)."""
    if path.startswith("/wp-content/"):
        return path[len("/wp-content/"):]
    if path.startswith("/wp-includes/"):
        return "includes/" + path[len("/wp-includes/"):]
    return None


def php_url_expr(path):
    a = asset_path(path)
    if a is not None:
        return "cf_assets_url( %s )" % php_str(a)
    if path.startswith("/wp-admin/"):
        return "admin_url( %s )" % php_str(path[len("/wp-admin/"):])
    return "home_url( %s )" % php_str(path)


URL_RE = re.compile(SITE + r"((?:\\/|/)[^\"'\s<>(),&]*)?")


def rewrite_markup(html):
    """Absolute site URLs -> PHP, so the theme works on any domain/install."""
    def sub(m):
        is_json = "\\/" in m.group(0)
        path = (m.group(1) or "/").replace("\\/", "/")
        base = path.split("?")[0]
        if asset_path(base) is not None:
            query, path = path[len(base):], base
        else:
            query = ""
        expr = php_url_expr(path)
        if is_json:
            return "<?php echo cf_json_url( %s ); ?>" % expr + query.replace("/", "\\/")
        return "<?php echo esc_url( %s ); ?>" % expr + query
    return URL_RE.sub(sub, html)


def rewrite_inline_js(code):
    """Absolute URLs in inline JS/JSON -> placeholders filled at runtime (cf_fill_inline)."""
    code = re.sub(SITE + r"\\/wp-content\\/", "{{ASSETS}}", code)
    code = re.sub(SITE + r"\\/wp-includes\\/", "{{ASSETS}}includes\\/", code)
    code = re.sub(SITE + r"\\/wp-admin\\/", "{{ADMIN}}", code)
    code = re.sub(SITE + r"\\/wp-json\\/", "{{REST}}", code)
    code = re.sub(SITE + r"\\/", "{{HOME}}", code)
    code = re.sub(SITE + r"/wp-content/", "{{ASSETS_RAW}}", code)
    code = re.sub(SITE + r"/wp-includes/", "{{ASSETS_RAW}}includes/", code)
    code = re.sub(SITE + r"/", "{{HOME_RAW}}", code)
    code = re.sub(SITE, "{{HOME_NOSLASH}}", code)
    code = re.sub(r'(ElementorProFrontendConfig = \{.*?"nonce":")[0-9a-f]+"', r'\1{{PRO_NONCE}}"', code)
    return code


def matching_close(s, start, tag):
    depth = 0
    for m in re.compile(r"<(/?)%s\b" % tag).finditer(s, start):
        depth += -1 if m.group(1) else 1
        if depth == 0:
            return s.index(">", m.start()) + 1
    raise ValueError(tag)


def ver(u):
    m = re.search(r"[?&](?:#038;)?ver=([^&'\"]+)", u)
    return m.group(1) if m else None


def local_path(u):
    return asset_path(re.sub(SITE, "", u).split("?")[0])


TAG_RE = re.compile(r"<(script|style)\b([^>]*)>(.*?)</\1>|<link\b[^>]*>", re.S)


GLOBAL_CSS = {}


def parse(fname):
    s = open(fname, encoding="utf-8").read()
    head = s[: s.index("</head>")]
    body = re.search(r'<body class="([^"]*)">', s)
    fstart = s.index('<footer data-elementor-type="footer"')
    fend = matching_close(s, fstart, "footer")
    tail = s[fend:]

    styles, scripts, before, head_extra, footer_extra = [], [], {}, [], []
    for m in TAG_RE.finditer(head):
        tag = m.group(0)
        if m.group(1) == "style" and 'id="global-styles-inline-css"' in (m.group(2) or ""):
            css = re.sub(r"\n/\*# sourceURL=[^*]*\*/\n?$", "", m.group(3).strip("\n"))
            h = hashlib.md5(css.encode()).hexdigest()[:10]
            GLOBAL_CSS[h] = css
            styles.append(["cf-global-styles", None, h, "all"])
            continue
        st = re.match(r"<link rel='stylesheet' id='([^']+)-css' href='([^']+)' media='([^']+)' />", tag)
        if st:
            styles.append([st.group(1), local_path(st.group(2)), ver(st.group(2)), st.group(3)])
        elif 'rel="modulepreload"' in tag:
            head_extra.append(rewrite_inline_js(tag))
        elif m.group(1) == "script" and 'src="' in m.group(2):
            h = re.search(r'id="([^"]+)-js"', m.group(2)).group(1)
            if h in CORE_SCRIPTS and CORE_SCRIPTS[h] not in [x[0] for x in scripts]:
                scripts.append([CORE_SCRIPTS[h], None, None])
    for m in TAG_RE.finditer(tail):
        kind, attrs, inner = m.group(1), m.group(2) or "", m.group(3) or ""
        idm = re.search(r'id="([^"]+)"', attrs)
        tid = idm.group(1) if idm else ""
        if kind == "script" and re.search(r'\bsrc="', attrs) and 'type="module"' not in attrs:
            h = tid[:-3]
            if h in CORE_SCRIPTS:
                if CORE_SCRIPTS[h] not in [x[0] for x in scripts]:
                    scripts.append([CORE_SCRIPTS[h], None, None])
            else:
                u = re.search(r'src="([^"]+)"', attrs).group(1)
                scripts.append([h, local_path(u), ver(u)])
        elif kind == "script" and tid.endswith("-js-before"):
            h = tid[: -len("-js-before")]
            if h not in CORE_SCRIPTS:
                before[h] = rewrite_inline_js(re.sub(r"\n//# sourceURL=[^\n]*\n?$", "", inner.strip("\n")))
        elif kind == "script" and tid.endswith("-js-after") and tid[: -len("-js-after")] in CORE_SCRIPTS:
            continue
        elif tid in CORE_INLINE or 'type="speculationrules"' in attrs or "lazyloadRunObserver" in inner \
                or "wp-emoji-settings" in inner or not m.group(0).strip():
            continue
        else:  # importmap, script-module data/scripts, core-block-supports CSS, ...
            footer_extra.append(rewrite_inline_js(m.group(0)))
    title = re.search(r"<title>(.*?)</title>", head, re.S).group(1)
    return {
        "body": s[body.end():fend],
        "assets": {"title": title, "styles": styles, "scripts": scripts, "inline_before": before,
                   "body_class": body.group(1).split(), "head_extra": head_extra, "footer_extra": footer_extra},
        "head": head, "tail": tail,
    }


def menu_dynamic(header):
    """Header for non-snapshot views: compute the menu's current item in PHP."""
    for pat in [r" current-menu-item", r" page_item page-item-\d+", r" current_page_item", r' aria-current="page"',
                r" elementor-item-active", r" current_page_parent", r" current-menu-parent", r" current-menu-ancestor"]:
        header = re.sub(pat, "", header)

    def li(m):
        cls, expr, rest = m.groups()
        arg = re.search(r"home_url\( ('.*?') \)", expr)
        if not arg:
            return m.group(0)
        p = arg.group(1)
        return ('<li class="%s<?php cf_menu_item_class( %s ); ?>"><a href="<?php echo esc_url( %s ); ?>"<?php cf_aria_current( %s ); ?> class="elementor-item<?php cf_menu_link_class( %s ); ?>"%s'
                % (cls, p, expr, p, p, rest))
    return re.sub(r'<li class="(menu-item[^"]*)"><a href="<\?php echo esc_url\( (.*?) \); \?>" class="elementor-item"((?: tabindex="-1")?>)', li, header)


def key_of(fname):
    rel = os.path.relpath(os.path.dirname(fname), SRC)
    return "" if rel == "." else rel.replace(os.sep, "/")


def snap_file(key):
    if key == "__404":
        return "not-found"
    return "front" if key == "" else key.replace("/", "__")


pages = {key_of(f): parse(f) for f in sorted(glob.glob(os.path.join(SRC, "**", "index.html"), recursive=True))}
# The site's own "page not found" design; served by the theme for any 404.
if os.path.exists(os.path.join(SRC, "404.html")):
    pages["__404"] = parse(os.path.join(SRC, "404.html"))
home = pages[""]

# Clean out previous generated files.
for old in glob.glob(os.path.join(THEME, "snapshots", "*.php")) + glob.glob(os.path.join(THEME, "page-*.php")) \
        + [os.path.join(THEME, "front-page.php")]:
    if os.path.exists(old):
        os.remove(old)
os.makedirs(os.path.join(THEME, "snapshots"), exist_ok=True)
os.makedirs(os.path.join(THEME, "template-parts"), exist_ok=True)
os.makedirs(os.path.join(THEME, "inc"), exist_ok=True)

for key, p in pages.items():
    url = "https://coastalfriends.org/" + ("<any missing URL> (404 page)" if key == "__404" else (key + "/" if key else ""))
    open(os.path.join(THEME, "snapshots", snap_file(key) + ".php"), "w", encoding="utf-8").write(
        "<?php\n/**\n * Snapshot of %s - exact <body> markup. Served by cf_router (functions.php).\n *\n"
        " * @package Coastal_Friends\n */\n\ndefined( 'ABSPATH' ) || exit;\n?>\n" % url
        + rewrite_markup(p["body"]).strip("\n") + "\n")


def common(lists):
    return [x for x in lists[0] if all(x in l for l in lists[1:])]


base_styles = [s for s in common([p["assets"]["styles"] for k, p in pages.items() if k != "__404"])
               if not re.match(r"elementor-post-(?!234|505|526)", s[0])]
base = {"title": "", "styles": base_styles, "scripts": common([p["assets"]["scripts"] for p in pages.values()]),
        "inline_before": home["assets"]["inline_before"], "body_class": ["elementor-default", "elementor-kit-234"],
        "head_extra": [], "footer_extra": []}
os.makedirs(os.path.join(THEME, "inc", "global-styles"), exist_ok=True)
for old in glob.glob(os.path.join(THEME, "inc", "global-styles", "*.css")):
    os.remove(old)
for h, css in GLOBAL_CSS.items():
    open(os.path.join(THEME, "inc", "global-styles", h + ".css"), "w", encoding="utf-8").write(css + "\n")
base["styles"] = [x for x in base["styles"] if x[0] != "cf-global-styles"]
manifest = {"base": base, "pages": {k: dict(p["assets"], file=snap_file(k)) for k, p in pages.items()}}
json.dump(manifest, open(os.path.join(THEME, "inc", "page-assets.json"), "w"), indent=1)

# Elementor's lazy-load helpers (the plugin prints them itself when active).
lazy_css = re.search(r"\t\t\t<style>\n\t\t\t\t\.e-con\.e-parent.*?</style>", home["head"], re.S).group(0)
lazy_js = re.search(r"\t\t\t<script>\n\t\t\t\t\( \(\) => \{\n\t\t\t\t\tconst lazyloadRunObserver.*?</script>", home["tail"], re.S).group(0)
custom_css = re.search(r'<style id="wp-custom-css">(.*?)</style>', home["head"], re.S).group(1).strip()
open(os.path.join(THEME, "template-parts", "lazyload-style.php"), "w").write(lazy_css.strip() + "\n")
open(os.path.join(THEME, "template-parts", "lazyload-script.php"), "w").write(lazy_js.strip() + "\n")

# header.php / footer.php for normal WordPress views (new posts, search, 404 ...).
s = open(os.path.join(SRC, "index.html"), encoding="utf-8").read()
hstart = s.index('<header data-elementor-type="header"')
hdr = menu_dynamic(rewrite_markup(s[hstart:matching_close(s, hstart, "header")]))
fstart = s.index('<footer data-elementor-type="footer"')
ftr = rewrite_markup(s[fstart:matching_close(s, fstart, "footer")])
open(os.path.join(THEME, "header.php"), "w").write("""<?php
/**
 * Site header for normal WordPress views (Elementor header template #505).
 *
 * @package Coastal_Friends
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'coastal-friends' ); ?></a>

""" + hdr + "\n")
open(os.path.join(THEME, "footer.php"), "w").write("""<?php
/**
 * Site footer for normal WordPress views (Elementor footer template #526).
 *
 * @package Coastal_Friends
 */
?>
""" + ftr + "\n\n<?php wp_footer(); ?>\n\n</body>\n</html>\n")

open(os.path.join(THEME, "style.css"), "w").write("""/*
Theme Name: Coastal Friends
Theme URI: https://coastalfriends.org/
Author: Infin Digital
Description: Exact copy of the Coastal Friends Mangalore website (all pages, news posts, categories, tags and archives). Every URL of the original site is served from a snapshot with all CSS, JS, fonts and images bundled, so the site looks identical on any WordPress install. New posts and pages use the same header and footer.
Version: 2.1.0
Requires at least: 6.5
Requires PHP: 7.4
License: GNU General Public License v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: coastal-friends
*/

/* Additional CSS carried over from the Customizer (wp-custom-css). */
""" + custom_css + "\n")
print("snapshots:", len(pages))
