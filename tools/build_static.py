"""Build a standalone static HTML copy of coastalfriends.org.

Reads the crawled pages (site/<path>/index.html) and the mirrored files
(coastal-friends/assets: plugins/ themes/ uploads/ includes/) and writes
<out>/ where every page is <path>/index.html, files keep their original
/wp-content/ and /wp-includes/ locations, and every link to the old site
is rewritten to a relative path. Nothing is loaded from coastalfriends.org.

usage: python3 -I tools/build_static.py site coastal-friends/assets static-site
"""
import glob, os, re, shutil, sys, urllib.parse

SRC, ASSETS, OUT = sys.argv[1:4]
DOMAIN = r"https?:(?:\\/|/){2}(?:www\.)?coastalfriends\.org"
URL_RE = re.compile(DOMAIN + r"((?:\\/|/)[^\"'\s<>(),]*)?")
# Tags that only make sense on a WordPress server (feeds, REST API, XML-RPC, oEmbed).
DROP_HEAD = re.compile(r'<link rel=["\'](?:alternate|https://api\.w\.org/|EditURI|shortlink)["\'][^>]*>\n?')

pages = sorted(glob.glob(os.path.join(SRC, "**", "index.html"), recursive=True))
page_paths = {"/" + os.path.relpath(os.path.dirname(p), SRC).replace(os.sep, "/").strip(".").strip("/") for p in pages}
page_paths = {p if p == "/" else p + "/" for p in page_paths}

if os.path.exists(OUT):
    shutil.rmtree(OUT)
# Files: assets/{plugins,themes,uploads} -> wp-content/, assets/includes -> wp-includes/
for name in ("plugins", "themes", "uploads"):
    shutil.copytree(os.path.join(ASSETS, name), os.path.join(OUT, "wp-content", name))
shutil.copytree(os.path.join(ASSETS, "includes"), os.path.join(OUT, "wp-includes"))
# CSS was relativised for the theme layout (wp-includes lived under assets/includes):
for css in glob.glob(os.path.join(OUT, "wp-content", "**", "*.css"), recursive=True):
    s = open(css, encoding="utf-8").read()
    if "includes/" in s:
        print("check css includes ref:", css)

missing = set()
# Attachment pages on the old site just redirected to the image file.
ATTACHMENTS = {"/coastal-friends-santwanada-sanchara-2-0-a-day-with-orphans-54/":
               "/wp-content/uploads/2024/12/Coastal-Friends-Santwanada-Sanchara-2.0-A-day-with-Orphans-54-scaled.jpeg"}


def rel(target, page_dir):
    """Relative URL from the page's folder to a site path ('/x/y')."""
    r = os.path.relpath(target.lstrip("/") or ".", page_dir or ".")
    if target.endswith("/") and r != ".":
        r += "/"
    return "./" if r == "." else r


def build(src, dst_name="index.html", root_relative=False):
    page_dir = os.path.relpath(os.path.dirname(src), SRC).replace(os.sep, "/")
    page_dir = "" if page_dir == "." else page_dir
    html = open(src, encoding="utf-8").read()
    html = DROP_HEAD.sub("", html)

    def sub(m):
        whole, raw = m.group(0), (m.group(1) or "/")
        tail = ""
        ent = re.search(r"&(?:quot|#0?39|#0?34|lt|gt);", raw)
        if ent:  # URL ended inside an HTML-escaped attribute (data-settings JSON)
            raw, tail = raw[:ent.start()], raw[ent.start():]
            whole = whole[: len(whole) - len(tail)]
        start = m.start()
        before = html[max(0, start - 40):start]
        if re.search(r'(rel="canonical" href=|rel=\'canonical\' href=)["\']$', before):
            return whole  # keep canonical URL absolute
        json = "\\/" in whole
        path = raw.replace("\\/", "/")
        path, sep, query = path.partition("?")
        path, hsep, frag = path.partition("#")
        clean = urllib.parse.unquote(path.replace("\\u2013", "–"))
        if clean in ATTACHMENTS:
            path = clean = ATTACHMENTS[clean]
        if clean.startswith(("/wp-content/", "/wp-includes/")):
            if not os.path.exists(os.path.join(OUT, clean.lstrip("/"))) and not clean.endswith("/"):
                missing.add(clean)
        elif clean.startswith(("/wp-admin/", "/wp-json/", "/xmlrpc.php", "/feed", "/comments/")):
            pass  # server endpoints: left relative, they don't exist on a static host
        else:
            if not clean.endswith("/"):
                clean += "/"
            if clean not in page_paths:
                missing.add(clean)
        target = path if path else "/"
        out = (target if root_relative else rel(target, page_dir)) + (sep + query if sep else "") + (hsep + frag if hsep else "")
        return (out.replace("/", "\\/") if json else out) + tail
    html = URL_RE.sub(sub, html)
    dst = os.path.join(OUT, page_dir, dst_name)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, "w", encoding="utf-8").write(html)


for p in pages:
    build(p)
# 404 page: served for any missing URL at any depth, so it uses root-relative links.
build(os.path.join(SRC, "404.html"), "404.html", root_relative=True)
open(os.path.join(OUT, ".htaccess"), "w").write(
    "# Apache / LiteSpeed (Hostinger): pretty folder URLs and the custom 404 page\n"
    "DirectoryIndex index.html\n"
    "ErrorDocument 404 /404.html\n"
    "Options -Indexes\n")
# Extra server-side files (contact-form handler that replaces WordPress's admin-ajax.php).
extra = os.path.join(os.path.dirname(os.path.abspath(__file__)), "static-extra")
shutil.copytree(extra, OUT, dirs_exist_ok=True)
left = sum(len(re.findall(r"coastalfriends\.org/(?!\")", open(f, encoding="utf-8").read()))
           for f in glob.glob(os.path.join(OUT, "**", "*.html"), recursive=True))
print("pages:", len(pages), "| missing targets:", len(missing))
for m in sorted(missing):
    print("  missing", m)
