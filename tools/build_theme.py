"""Convert the downloaded coastalfriends.org HTML snapshots into a classic WordPress theme.

usage: python3 -I build_theme.py <dir-with-html> <theme-dir>
"""
import json, os, re, sys

SRC, THEME = sys.argv[1], sys.argv[2]
PAGES = {  # key -> (snapshot file, template file)
    "home": ("index.html", "front-page.php"),
    "about-us": ("about-us.html", "page-about-us.php"),
    "contact-us": ("contact-us.html", "page-contact-us.php"),
    "events": ("events.html", "page-events.php"),
    "gallery": ("gallery.html", "page-gallery.php"),
    "our-team": ("our-team.html", "page-our-team.php"),
    "sufyhome": ("sufyhome.html", "page-sufyhome.php"),
    "home-1": ("home-1.html", "page-home-1.php"),
}
# WordPress core scripts: enqueue WP's own copies by handle instead of bundling them.
CORE_SCRIPTS = {"jquery-core": "jquery", "jquery-migrate": "jquery", "jquery-ui-core": "jquery-ui-core",
                "imagesloaded": "imagesloaded", "wp-hooks": "wp-hooks", "wp-i18n": "wp-i18n"}
SITE = r"https?:(?:\\/|/){2}(?:www\.)?coastalfriends\.org"


def php_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def asset_path(path):
    """/wp-content/x -> x ; /wp-includes/x -> includes/x (matches the mirrored assets/ layout)."""
    if path.startswith("/wp-content/"):
        return path[len("/wp-content/"):]
    if path.startswith("/wp-includes/"):
        return "includes/" + path[len("/wp-includes/"):]
    return None


URL_RE = re.compile(SITE + r"((?:\\/|/)[^\"'\s<>(),&]*)?")


def php_url_expr(path):
    a = asset_path(path)
    if a is not None:
        return "cf_assets_url( %s )" % php_str(a)
    if path.startswith("/wp-admin/"):
        return "admin_url( %s )" % php_str(path[len("/wp-admin/"):])
    return "home_url( %s )" % php_str(path)


def rewrite_markup(html):
    """Replace absolute site URLs with PHP so the theme works on any domain/install."""
    def sub(m):
        raw = m.group(1) or "/"
        is_json = "\\/" in m.group(0)
        path = raw.replace("\\/", "/")
        if path.split("?")[0] != path and asset_path(path.split("?")[0]):
            query = path[len(path.split("?")[0]):]
            expr = php_url_expr(path.split("?")[0])
        else:
            query, expr = "", php_url_expr(path)
        if is_json:
            return "<?php echo cf_json_url( %s ); ?>" % expr + query.replace("/", "\\/")
        return "<?php echo esc_url( %s ); ?>" % expr + query
    return URL_RE.sub(sub, html)


def rewrite_inline_js(code):
    code = re.sub(SITE + r"\\/wp-content\\/", "{{ASSETS}}", code)
    code = re.sub(SITE + r"\\/wp-admin\\/", "{{ADMIN}}", code)
    code = re.sub(SITE + r"\\/wp-json\\/", "{{REST}}", code)
    code = re.sub(SITE + r"\\/", "{{HOME}}", code)
    code = re.sub(SITE, "{{HOME_NOSLASH}}", code)
    code = re.sub(r'(ElementorProFrontendConfig = \{.*?"nonce":")[0-9a-f]+"', r'\1{{PRO_NONCE}}"', code)
    return code


def matching_close(s, start, tag):
    depth, i = 0, start
    pat = re.compile(r"<(/?)%s\b" % tag)
    for m in pat.finditer(s, start):
        depth += -1 if m.group(1) else 1
        if depth == 0:
            return s.index(">", m.start()) + 1
    raise ValueError(tag)


def parse(fname):
    s = open(os.path.join(SRC, fname), encoding="utf-8").read()
    head = s[: s.index("</head>")]
    body_tag = re.search(r'<body class="([^"]*)">', s)
    hstart = s.index('<header data-elementor-type="header"')
    hend = matching_close(s, hstart, "header")
    fstart = s.index('<footer data-elementor-type="footer"')
    fend = matching_close(s, fstart, "footer")
    tail = s[fend:]

    styles = [[h, asset_path(re.sub(SITE, "", u).split("?")[0]), (re.search(r"ver=([^&']+)", u) or [None, None])[1], media]
              for h, u, media in re.findall(r"<link rel='stylesheet' id='([^']+)-css' href='([^']+)' media='([^']+)' />", head)]
    scripts = []
    for h, u in re.findall(r'<script id="([^"]+)-js" src="([^"]+)"', head + tail):
        if h in CORE_SCRIPTS:
            if CORE_SCRIPTS[h] not in [x[0] for x in scripts]:
                scripts.append([CORE_SCRIPTS[h], None, None])
        else:
            scripts.append([h, asset_path(re.sub(SITE, "", u).split("?")[0]), (re.search(r"ver=([^&]+)", u) or [None, None])[1]])
    inline_before = {}
    for h, code in re.findall(r'<script id="([^"]+)-js-before">\n(.*?)\n//# sourceURL=[^\n]*\n</script>', tail, re.S):
        if h not in CORE_SCRIPTS:
            inline_before[h] = rewrite_inline_js(code)
    classes = [c for c in body_tag.group(1).split()
               if c.startswith(("elementor", "hello-elementor", "e--")) or c == "page-template-elementor_header_footer"]
    return {
        "head": head, "between": s[body_tag.end():hstart], "header": s[hstart:hend],
        "content": s[hend:fstart], "footer": s[fstart:fend], "tail": tail,
        "assets": {"styles": styles, "scripts": scripts, "inline_before": inline_before, "body_class": classes},
    }


def menu_dynamic(header):
    """Strip the snapshot's hard-coded 'current page' state; compute it in PHP instead."""
    for pat in [r" current-menu-item", r" page_item page-item-\d+", r" current_page_item", r' aria-current="page"', r" elementor-item-active"]:
        header = re.sub(pat, "", header)

    def li(m):
        cls, expr, rest = m.group(1), m.group(2), m.group(3)
        arg = re.search(r"home_url\( ('.*?') \)", expr)
        if not arg:
            return m.group(0)
        p = arg.group(1)
        return ('<li class="%s<?php cf_menu_item_class( %s ); ?>"><a href="<?php echo esc_url( %s ); ?>"<?php cf_aria_current( %s ); ?> class="elementor-item<?php cf_menu_link_class( %s ); ?>"%s'
                % (cls, p, expr, p, p, rest))
    return re.sub(r'<li class="(menu-item[^"]*)"><a href="<\?php echo esc_url\( (.*?) \); \?>" class="elementor-item"((?: tabindex="-1")?>)', li, header)


pages = {k: parse(f) for k, (f, _) in PAGES.items()}
home = pages["home"]

# Sanity: header/footer must be shared across pages once menu state is normalised.
hdr = menu_dynamic(rewrite_markup(home["header"]))
for k, p in pages.items():
    assert menu_dynamic(rewrite_markup(p["header"])) == hdr, "header differs on " + k
    assert p["footer"] == home["footer"], "footer differs on " + k

# Assets manifest ------------------------------------------------------------
def common(lists):
    first = lists[0]
    return [x for x in first if all(x in l for l in lists[1:])]

base = {
    "styles": [s for s in common([p["assets"]["styles"] for p in pages.values()]) if not re.match(r"elementor-post-(?!234|505|526)", s[0])],
    "scripts": common([p["assets"]["scripts"] for p in pages.values()]),
    "inline_before": home["assets"]["inline_before"],
    "body_class": ["elementor-default", "elementor-kit-234"],
}
manifest = {"base": base, "pages": {k: p["assets"] for k, p in pages.items()}}
os.makedirs(os.path.join(THEME, "inc"), exist_ok=True)
json.dump(manifest, open(os.path.join(THEME, "inc", "page-assets.json"), "w"), indent=1)

# Elementor's lazy-load snippets (printed by the plugin itself when it is active).
lazy_css = re.search(r"\t\t\t<style>\n\t\t\t\t\.e-con\.e-parent.*?</style>", home["head"], re.S).group(0)
lazy_js = re.search(r"\t\t\t<script>\n\t\t\t\t\( \(\) => \{\n\t\t\t\t\tconst lazyloadRunObserver.*?</script>", home["tail"], re.S).group(0)
custom_css = re.search(r'<style id="wp-custom-css">(.*?)</style>', home["head"], re.S).group(1).strip()
os.makedirs(os.path.join(THEME, "template-parts"), exist_ok=True)
open(os.path.join(THEME, "template-parts", "lazyload-style.php"), "w").write(lazy_css.strip() + "\n")
open(os.path.join(THEME, "template-parts", "lazyload-script.php"), "w").write(lazy_js.strip() + "\n")

# header.php / footer.php ---------------------------------------------------
skip = home["between"].strip()
assert skip == '<a class="skip-link screen-reader-text" href="#content">Skip to content</a>', skip
open(os.path.join(THEME, "header.php"), "w").write(
    """<?php
/**
 * Site header (converted from the Elementor header template #505).
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
open(os.path.join(THEME, "footer.php"), "w").write(
    """<?php
/**
 * Site footer (converted from the Elementor footer template #526).
 *
 * @package Coastal_Friends
 */
?>
""" + rewrite_markup(home["footer"]) + "\n\n<?php wp_footer(); ?>\n\n</body>\n</html>\n")

# Page templates ---------------------------------------------------------------
for k, (fname, tpl) in PAGES.items():
    p = pages[k]
    title = re.search(r"<title>(.*?)</title>", p["head"]).group(1)
    open(os.path.join(THEME, tpl), "w").write(
        """<?php
/**
 * Static template for the "%s" page, generated from %s.
 *
 * @package Coastal_Friends
 */

get_header();
?>
%s
<?php
get_footer();
""" % (title.replace("&#8211;", "-"), "https://coastalfriends.org/" + ("" if k == "home" else k + "/"),
       rewrite_markup(p["content"]).strip()))

# style.css ------------------------------------------------------------------
open(os.path.join(THEME, "style.css"), "w").write("""/*
Theme Name: Coastal Friends
Theme URI: https://coastalfriends.org/
Author: Infin Digital
Description: Classic theme for Coastal Friends Mangalore, generated from the live Elementor site (Hello Elementor + Elementor + Pro Elements). Each page's design is a static template with all CSS, JS, fonts and images bundled in assets/.
Version: 1.0.0
Requires at least: 6.5
Requires PHP: 7.4
License: GNU General Public License v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: coastal-friends
*/

/* Additional CSS carried over from the Customizer (wp-custom-css). */
""" + custom_css + "\n")
print("ok", {k: (len(p["assets"]["styles"]), len(p["assets"]["scripts"])) for k, p in pages.items()})
