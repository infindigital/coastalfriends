"""Package the theme as ONE installable zip with all images inside, small enough
for Appearance -> Themes -> Upload.

The site's media library holds every photo in up to 7 sizes. This keeps the
sizes the pages display (up to 800px wide) plus one "large" copy per photo
(max 1280px, used by the lightbox and high-DPI screens), converts them to
WebP, and rewrites every reference (page snapshots, inline JSON, CSS).
References to the dropped sizes point at the large copy.

usage: python3 -I tools/pack_theme.py coastal-friends <out-dir>   -> <out-dir>/coastal-friends/
"""
import os, re, shutil, sys
from multiprocessing import Pool
from PIL import Image

THEME, OUT = sys.argv[1], sys.argv[2]
UP = os.path.join(THEME, "assets", "uploads")
DST_THEME = os.path.join(OUT, "coastal-friends")
DST_UP = os.path.join(DST_THEME, "assets", "uploads")
SMALL_MAX_W, LARGE_MAX, Q, Q_PNG = 800, 1140, 62, 85
# Optional: image format and quality, e.g. "avif 40 70" (photo quality, PNG-source quality).
FMT = (sys.argv[3] if len(sys.argv) > 3 else "webp").lower()
if len(sys.argv) > 4:
    Q = int(sys.argv[4])
if len(sys.argv) > 5:
    Q_PNG = int(sys.argv[5])
EXT = "." + FMT
SIZE = re.compile(r"^(?P<base>.+?)(?P<edit>-e\d{10,})?(?:-(?P<w>\d+)x(?P<h>\d+))?(?P<scaled>-scaled)?\.(?P<ext>jpe?g|png|webp)$", re.I)

if os.path.exists(DST_THEME):
    shutil.rmtree(DST_THEME)
shutil.copytree(THEME, DST_THEME, ignore=lambda d, names: [n for n in names if os.path.abspath(d) == os.path.abspath(UP) and n.startswith("20")])

# Group every media file by photo; decide what to keep.
groups = {}
for root, _, files in os.walk(UP):
    rel_root = os.path.relpath(root, UP)
    if not rel_root.startswith("20"):
        continue
    for f in files:
        m = SIZE.match(f)
        # One group per photo: base name + edit suffix + extension ("1.jpeg" and "1.jpg" are different photos).
        key = os.path.join(rel_root, (m.group("base") + (m.group("edit") or "") + "." + m.group("ext").lower()) if m else f)
        groups.setdefault(key, []).append((f, m))

# Sizes used as an <img src> (or Elementor/lightbox JSON "url") keep their own
# file at their exact dimensions, so the page layout does not change.
SRC_REF = re.compile(r"""(?:\bsrc="<\?php echo esc_url\( cf_assets_url\( 'uploads/|cf_json_url\( cf_assets_url\( 'uploads/)([^']+)'""")
direct = set()
for root, _, files in os.walk(os.path.join(THEME, "snapshots")):
    for f in files:
        for ref in SRC_REF.findall(open(os.path.join(root, f), encoding="utf-8").read()):
            direct.add(ref.replace("/", os.sep))

jobs, mapping = [], {}
used = set()
for key, items in groups.items():
    d = os.path.dirname(key)
    def width(it):
        f, m = it
        if m and m.group("w"):
            return int(m.group("w"))
        try:
            return Image.open(os.path.join(UP, d, f)).size[0]
        except Exception:
            return 0
    # Large copy source: '-scaled' > original upload > biggest size.
    full = [it for it in items if it[1] and it[1].group("scaled")] or [it for it in items if it[1] and not it[1].group("w")] or sorted(items, key=width)[-1:]
    src_large = full[0][0]
    large_name = os.path.basename(key) + EXT          # e.g. 1.jpeg.webp
    assert os.path.join(d, large_name) not in used, key
    used.add(os.path.join(d, large_name))
    # An original that a page shows directly keeps its full size (it may be displayed at natural width).
    cap = None if os.path.join(d, src_large) in direct else LARGE_MAX
    jobs.append((os.path.join(d, src_large), os.path.join(d, large_name), cap))
    for f, m in items:
        if (m and m.group("w") and int(m.group("w")) <= SMALL_MAX_W) or (m and m.group("w") and os.path.join(d, f) in direct):
            new = f + EXT                               # e.g. 1-768x512.jpeg.webp
            assert os.path.join(d, new) not in used, new
            used.add(os.path.join(d, new))
            jobs.append((os.path.join(d, f), os.path.join(d, new), None))
            mapping[os.path.join(d, f)] = os.path.join(d, new)
        else:
            mapping[os.path.join(d, f)] = os.path.join(d, large_name)


def convert(job):
    src, dst, cap = job
    im = Image.open(os.path.join(UP, src))
    is_png = im.format == "PNG"
    if cap and max(im.size) > cap:
        im.thumbnail((cap, cap), Image.LANCZOS)
    if im.mode not in ("RGB", "RGBA"):
        im = im.convert("RGBA" if "A" in im.getbands() or "transparency" in im.info else "RGB")
    out = os.path.join(DST_UP, dst)
    os.makedirs(os.path.dirname(out), exist_ok=True)
    if FMT == "avif":
        im.save(out, "AVIF", quality=Q_PNG if is_png else Q, speed=6)
    else:
        im.save(out, "WEBP", quality=Q_PNG if is_png else Q, method=6)
    return os.path.getsize(out)


with Pool() as pool:
    sizes = pool.map(convert, jobs, chunksize=4)

# Rewrite references in the packaged theme's text files.
forms = {}
for old, new in mapping.items():
    old, new = old.replace(os.sep, "/"), new.replace(os.sep, "/")
    for o, n in ((old, new), (old.replace("/", "\\/"), new.replace("/", "\\/")),
                 (old.replace("\u2013", "\\u2013").replace("/", "\\/"), new.replace("\u2013", "\\u2013").replace("/", "\\/"))):
        forms[o] = n
pat = re.compile("|".join(re.escape(k) for k in sorted(forms, key=len, reverse=True)))
changed = 0
for root, _, files in os.walk(DST_THEME):
    for f in files:
        if not f.endswith((".php", ".json", ".css")):
            continue
        p = os.path.join(root, f)
        s = open(p, encoding="utf-8").read()
        t = pat.sub(lambda m: forms[m.group(0)], s)
        if t != s:
            open(p, "w", encoding="utf-8").write(t)
            changed += 1
print("photos:", len(groups), "| files:", len(jobs), "| images %.1f MB" % (sum(sizes) / 1e6), "| text files rewritten:", changed)
