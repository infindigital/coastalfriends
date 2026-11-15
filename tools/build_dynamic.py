"""Make the theme's post lists and post/archive pages live WordPress content.

Run after tools/build_theme.py:
    python3 -I tools/build_dynamic.py site coastal-friends tools/data

* Post grids on the static pages (Home, Gallery, Event, home-1, SufyHome) are
  replaced by PHP that queries real posts and prints each card with the
  grid's ORIGINAL card markup (inc/post-widgets.json). "Load more" and
  page numbers work.
* single.php and archive.php are generated from the original Elementor single
  post (#770) and archive (#714) designs, with the title, featured image,
  content and post grids filled in by WordPress.
* inc/import/posts.json holds the 36 original posts (content exactly as the
  site rendered it, featured image, date, author, categories, tags) for the
  importer that runs when the theme is activated (inc/importer.php).
* Paginated copies (/page/N/, /gallery/N/) are dropped: the router serves
  the page itself with the right page number.
"""
import glob, html, json, os, re, sys

SITE, THEME, DATA = sys.argv[1], sys.argv[2], sys.argv[3]
HERE = os.path.dirname(os.path.abspath(__file__))

# Reuse build_theme.py's helpers (everything before its main build code).
src = open(os.path.join(HERE, "build_theme.py"), encoding="utf-8").read()
ns = {"__file__": os.path.join(HERE, "build_theme.py")}
_argv = sys.argv
sys.argv = ["build_theme.py", SITE, THEME]
exec(compile(src[: src.index("\npages = {key_of(f)")], "build_theme.py", "exec"), ns)
sys.argv = _argv
rewrite_markup, matching_close, parse, snap_file = ns["rewrite_markup"], ns["matching_close"], ns["parse"], ns["snap_file"]

POSTS = json.load(open(os.path.join(DATA, "posts.json"), encoding="utf-8"))
CATS = json.load(open(os.path.join(DATA, "categories.json"), encoding="utf-8"))
TAGS = json.load(open(os.path.join(DATA, "tags.json"), encoding="utf-8"))
USERS = json.load(open(os.path.join(DATA, "users.json"), encoding="utf-8"))
BY_ID = {p["id"]: p for p in POSTS}
CAT_BY_ID = {c["id"]: c for c in CATS}
TAG_BY_ID = {t["id"]: t for t in TAGS}
USER_BY_ID = {u["id"]: u for u in USERS}
DOMAIN = re.compile(r"https?:(?:\\/|/){2}(?:www\.)?coastalfriends\.org")

# Post grids: widget id -> query. per_page/terms match what the original site showed.
WIDGETS = {
    "394a684a": {"query": "all", "per_page": 3, "paged_key": ""},             # Home: latest posts, Load more
    "1a7ee9c": {"query": "category", "term": "gallery", "per_page": 21, "paged_key": "gallery"},
    "838f6f6": {"query": "category", "term": "event", "per_page": 6, "paged_key": "events"},
    "12f013f6": {"query": "all", "per_page": 2},                                # home-1
    "577b35f2": {"query": "all", "per_page": 2},                                # SufyHome
    "51353b09": {"query": "main"},                                              # archive grid (category/tag/author)
    "6b7e2b0e": {"query": "all", "per_page": 3, "order": "ASC"},                # archive sidebar "Recent posts": the 3 OLDEST, as on the original
}
DYNAMIC_PAGES = ["", "gallery", "events", "home-1", "sufyhome"]


def to_placeholders(s):
    """Old-site URLs -> {{CF_ASSETS}} / {{CF_HOME}} (filled in at render time)."""
    s = re.sub(DOMAIN.pattern + r"(\\?/)wp-content\1", lambda m: "{{CF_ASSETS}}", s)
    s = re.sub(DOMAIN.pattern + r"(\\?/)", lambda m: "{{CF_HOME}}", s)
    return s


def widget_span(s, wid):
    i = s.index('elementor-element-%s ' % wid)
    start = s.rindex("<div", 0, i)
    return start, matching_close(s, start, "div")


def make_template(article, post):
    t = article
    t = re.sub(r'(<article class="elementor-post elementor-grid-item )[^"]*"', r'\1{{POST_CLASSES}}"', t, count=1)
    t = re.sub(r'<a class="elementor-post__thumbnail__link".*?</a>', "{{THUMB_BLOCK}}", t, count=1, flags=re.S)
    t = t.replace(post["link"], "{{PERMALINK}}")
    t = re.sub(r'(<div class="elementor-post__badge">)[^<]*(</div>)', r"\1{{BADGE}}\2", t)
    title = post["title"]["rendered"]
    m = re.search(r'(<h\d class="elementor-post__title">\s*<a href="\{\{PERMALINK\}\}" >\s*)(.*?)(\s*</a>)', t, re.S)
    assert m and html.unescape(m.group(2).strip()) == html.unescape(title), (m and m.group(2), title)
    t = t[: m.start(2)] + "{{TITLE}}" + t[m.end(2):]
    words = 0
    m = re.search(r'(<div class="elementor-post__excerpt">\s*<p>)(.*?)(</p>)', t, re.S)
    if m:
        words = len(m.group(2).split())
        t = t[: m.start(2)] + "{{EXCERPT}}" + t[m.end(2):]
    t = re.sub(r'(<span class="elementor-post-date">\s*).*?(\s*</span>)', r"\1{{DATE}}\2", t, flags=re.S)
    t = re.sub(r'(<span class="elementor-post-avatar">\s*).*?(\s*</span>)', r"\1{{COMMENTS}}\2", t, flags=re.S)
    thumb = re.search(r'<a class="elementor-post__thumbnail__link".*?</a>', article, re.S)
    thumb_block = ""
    if thumb:
        thumb_block = thumb.group(0).replace(post["link"], "{{PERMALINK}}")
        thumb_block = re.sub(r"<img [^>]*>", "{{THUMB}}", thumb_block, count=1)
    assert "coastalfriends.org" not in t and "coastalfriends.org" not in thumb_block, t[:500]
    return t, thumb_block, words


def card_images():
    """Original card <img> per post id (from the home grid's pages)."""
    out = {}
    for f in sorted(glob.glob(os.path.join(SITE, "**", "index.html"), recursive=True)):
        s = open(f, encoding="utf-8").read()
        for m in re.finditer(r'<article class="elementor-post elementor-grid-item post-(\d+) .*?</article>', s, re.S):
            img = re.search(r'<div class="elementor-post__thumbnail"><img [^>]*></div>', m.group(0))
            if img and int(m.group(1)) not in out:
                out[int(m.group(1))] = re.search(r"<img [^>]*>", img.group(0)).group(0)
    return out


def transform_widgets(s, widgets_cfg, record):
    """Replace each known posts widget's cards/anchor/pagination with markers."""
    for wid in WIDGETS:
        if 'elementor-element-%s ' % wid not in s:
            continue
        ws, we = widget_span(s, wid)
        w = s[ws:we]
        settings = html.unescape((re.search(r'data-settings="([^"]*)"', w) or [None, "{}"])[1])
        ptype = json.loads(settings).get("pagination_type", "") if settings.startswith("{") else ""
        cs = w.index('<div class="elementor-posts-container')
        ce = matching_close(w, cs, "div")
        open_end = w.index(">", cs) + 1
        inner = w[open_end: ce - len("</div>")]
        if record and wid not in widgets_cfg:
            arts = re.findall(r'<article class="elementor-post .*?</article>', inner, re.S)
            first = arts[0]
            pid = int(re.search(r"post-(\d+) ", first).group(1))
            tpl, thumb_block, words = make_template(first, BY_ID[pid])
            widgets_cfg[wid] = dict(WIDGETS[wid], template=tpl, thumb_block=thumb_block, excerpt_words=words,
                                    pagination_type=ptype,
                                    anchor="e-load-more-anchor" in w, nav='<nav class="elementor-pagination"' in w,
                                    skin=re.search(r'data-widget_type="([^"]+)"', w).group(1))
        w = w[:open_end] + "\n@@CFW:%s@@\n\t\t\t\t" % wid + w[ce - len("</div>"):]
        w = re.sub(r'<div class="e-load-more-anchor"[^>]*></div>', "@@CFA:%s@@" % wid, w)
        w = re.sub(r'<nav class="elementor-pagination".*?</nav>', "@@CFN:%s@@" % wid, w, flags=re.S)
        if "@@CFA:%s@@" % wid not in w and "load_more" in ptype:
            w = w.replace("@@CFW:%s@@\n\t\t\t\t</div>" % wid, "@@CFW:%s@@\n\t\t\t\t</div>@@CFA:%s@@" % (wid, wid))
        s = s[:ws] + "@@CFIF:%s@@" % wid + w + "@@CFEND@@" + s[we:]
    return s


def markers_to_php(s):
    s = re.sub(r"@@CFW:(\w+)@@", r"<?php cf_posts_widget( '\1' ); ?>", s)
    s = re.sub(r"@@CFA:(\w+)@@", r"<?php cf_posts_widget_anchor( '\1' ); ?>", s)
    s = re.sub(r"@@CFN:(\w+)@@", r"<?php cf_posts_widget_nav( '\1' ); ?>", s)
    s = re.sub(r"@@CFIF:(\w+)@@", r"<?php if ( cf_widget_has_posts( '\1' ) ) : ?>", s)
    s = s.replace("@@CFEND@@", "<?php endif; ?>")
    return s


manifest_path = os.path.join(THEME, "inc", "page-assets.json")
manifest = json.load(open(manifest_path, encoding="utf-8"))
widgets_cfg = {}

# 1) Static pages with post grids: live grids.
for key in DYNAMIC_PAGES:
    f = os.path.join(SITE, key, "index.html") if key else os.path.join(SITE, "index.html")
    p = parse(f)
    body = transform_widgets(p["body"], widgets_cfg, record=True)
    out = os.path.join(THEME, "snapshots", snap_file(key) + ".php")
    head = open(out, encoding="utf-8").read().split("?>\n", 1)[0] + "?>\n"
    open(out, "w", encoding="utf-8").write(head + markers_to_php(rewrite_markup(body)).strip("\n") + "\n")

# 2) Paginated copies are served by the router from their page; keep their title pattern.
paged_title = {}
for key in list(manifest["pages"]):
    m = re.match(r"^(page|gallery|events)/(\d+)$", key)
    if not m:
        continue
    base_key = "" if m.group(1) == "page" else m.group(1)
    paged_title.setdefault(base_key, manifest["pages"][key]["title"].replace("Page " + m.group(2), "Page %d"))
    f = os.path.join(THEME, "snapshots", manifest["pages"][key]["file"] + ".php")
    if os.path.exists(f):
        os.remove(f)
    del manifest["pages"][key]
manifest["paged_title"] = paged_title
manifest["paged_keys"] = sorted({v["paged_key"] for v in WIDGETS.values() if "paged_key" in v})

# 3) Posts and archives are live WordPress content; their copies are only a fallback (before import).
post_slugs = {p["slug"] for p in POSTS}
manifest["fallback"] = sorted(k for k in manifest["pages"] if k in post_slugs or re.match(r"^(category|tag|author)/", k))


def shell(body, title_php=None):
    return ("<?php\n/**\n * Generated by tools/build_dynamic.py from the original Elementor design.\n *\n"
            " * @package Coastal_Friends\n */\n\n%s?><!DOCTYPE html>\n<html <?php language_attributes(); ?>>\n<head>\n"
            "\t<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            "\t<link rel=\"profile\" href=\"https://gmpg.org/xfn/11\">\n<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n"
            "<?php wp_body_open(); ?>\n%s\n<?php wp_footer(); ?>\n</body>\n</html>\n") % (title_php or "", body)


# 4) single.php from a post copy (Elementor single template #770).
sp = parse(os.path.join(SITE, "ifthar-party-2", "index.html"))
b = sp["body"]
b = re.sub(r'(<div data-elementor-type="single-post" data-elementor-id="770" class="elementor elementor-770 elementor-location-single )[^"]*"',
           r'\1@@POSTCLASS@@"', b, count=1)
for wid, marker in (("2fe3b785", "@@TITLE@@"), ("22d16d1", "@@FEATURED@@"), ("3f156081", "@@CONTENT@@")):
    ws, we = widget_span(b, wid)
    w = b[ws:we]
    cs = w.index('<div class="elementor-widget-container">')
    ce = matching_close(w, cs, "div")
    inner_start = cs + len('<div class="elementor-widget-container">')
    if marker == "@@TITLE@@":
        w = re.sub(r'(<h1 class="elementor-heading-title elementor-size-default">).*?(</h1>)', r"\1@@TITLE@@\2", w, count=1, flags=re.S)
    else:
        w = w[:inner_start] + "\n" + marker + "\n" + w[ce - len("</div>"):]
    b = b[:ws] + w + b[we:]
single = rewrite_markup(b)
single = single.replace("@@POSTCLASS@@", "<?php echo esc_attr( implode( ' ', get_post_class() ) ); ?>")
single = single.replace("@@TITLE@@", "<?php the_title(); ?>").replace("@@FEATURED@@", "<?php cf_single_featured_image(); ?>")
single = single.replace("@@CONTENT@@", "<?php the_content(); ?>")
assert "@@" not in single
open(os.path.join(THEME, "single.php"), "w", encoding="utf-8").write(shell(single, "the_post();\n"))

# 5) archive.php from a category copy (Elementor archive template #714).
ap = parse(os.path.join(SITE, "category", "gallery", "index.html"))
b = transform_widgets(ap["body"], widgets_cfg, record=True)
b = re.sub(r'(<h1 class="elementor-heading-title elementor-size-default">)Category: Gallery(</h1>)', r"\1@@ATITLE@@\2", b, count=1)
archive = markers_to_php(rewrite_markup(b)).replace("@@ATITLE@@", "<?php echo wp_kses_post( get_the_archive_title() ); ?>")
assert "@@" not in archive
open(os.path.join(THEME, "archive.php"), "w", encoding="utf-8").write(shell(archive))

# Asset sets + Elementor body classes for live single posts and archives.
def eclasses(classes):
    return [c for c in classes if c.startswith(("elementor", "hello-elementor", "e--"))]
single_set = dict(sp["assets"], head_extra=[], footer_extra=[], title="")
single_set["body_class"] = eclasses(sp["assets"]["body_class"])
archive_set = dict(ap["assets"], title="")
archive_set["body_class"] = eclasses(ap["assets"]["body_class"])
manifest["dynamic"] = {"single": single_set, "archive": archive_set}
json.dump(manifest, open(manifest_path, "w"), indent=1)
json.dump(widgets_cfg, open(os.path.join(THEME, "inc", "post-widgets.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)

# 6) Import data: the 36 original posts.
cards = card_images()
os.makedirs(os.path.join(THEME, "inc", "import"), exist_ok=True)
posts_out = []
for p in sorted(POSTS, key=lambda x: x["date_gmt"]):
    f = os.path.join(SITE, p["slug"], "index.html")
    s = open(f, encoding="utf-8").read()
    ws, we = widget_span(s, "3f156081")
    w = s[ws:we]
    cs = w.index('<div class="elementor-widget-container">')
    content = w[cs + len('<div class="elementor-widget-container">'): matching_close(w, cs, "div") - len("</div>")].strip()
    ws, we = widget_span(s, "22d16d1")
    m = re.search(r"<img [^>]*>", s[ws:we])
    single_img = m.group(0) if m else ""
    fm = p["featured"]
    if not fm.get("source_url") and cards.get(p["id"]):
        img = cards[p["id"]]
        fm = {"source_url": re.search(r'src="([^"]+)"', img).group(1), "alt_text": "",
              "width": int(re.search(r'width="(\d+)"', img).group(1)), "height": int(re.search(r'height="(\d+)"', img).group(1)),
              "mime_type": None}
    excerpt = html.unescape(re.sub(r"<[^>]+>", "", p["excerpt"]["rendered"])).strip()
    excerpt = re.sub(r"\s*\[(?:&hellip;|…)\]\s*$", "", excerpt)
    posts_out.append({
        "src_id": p["id"], "slug": p["slug"], "title": html.unescape(p["title"]["rendered"]),
        "date": p["date"], "date_gmt": p["date_gmt"], "modified": p["modified"], "modified_gmt": p["modified_gmt"],
        "content": "<!-- wp:html -->\n" + to_placeholders(content) + "\n<!-- /wp:html -->",
        "excerpt": excerpt, "comment_status": p["comment_status"],
        "categories": [CAT_BY_ID[c]["slug"] for c in p["categories"] if c in CAT_BY_ID],
        "tags": [TAG_BY_ID[t]["slug"] for t in p["tags"] if t in TAG_BY_ID],
        "author": USER_BY_ID.get(p["author"], {}).get("slug", ""),
        "featured": ({"file": to_placeholders(fm["source_url"]).replace("{{CF_ASSETS}}", ""), "alt": fm.get("alt_text") or "",
                      "width": fm.get("width"), "height": fm.get("height"), "mime": fm.get("mime_type")} if fm.get("source_url") else None),
        "card_img": to_placeholders(cards.get(p["id"], "")),
        "single_img": to_placeholders(single_img),
    })
terms = {"categories": [{"slug": c["slug"], "name": html.unescape(c["name"]), "description": c.get("description", "")} for c in CATS],
         "tags": [{"slug": t["slug"], "name": html.unescape(t["name"]), "description": t.get("description", "")} for t in TAGS]}
json.dump({"terms": terms, "posts": posts_out}, open(os.path.join(THEME, "inc", "import", "posts.json"), "w", encoding="utf-8"),
          ensure_ascii=False, indent=1)
print("live grids:", sorted(widgets_cfg), "| paged titles:", paged_title, "| fallback copies:", len(manifest["fallback"]),
      "| import posts:", len(posts_out), "with featured", sum(1 for x in posts_out if x["featured"]))
