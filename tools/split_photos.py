"""Split a packed theme (tools/pack_theme.py output) into a small theme zip plus
photo zips no larger than MAX_MB each, installed from Appearance -> Coastal
Friends Photos (inc/photo-import.php).

usage: python3 -I tools/split_photos.py <dist>/coastal-friends <out-dir> [MAX_MB]
writes <out-dir>/coastal-friends.zip and <out-dir>/coastal-friends-photos-N.zip
"""
import json, os, re, shutil, sys, zipfile

SRC, OUT = sys.argv[1], sys.argv[2]
MAX = float(sys.argv[3] if len(sys.argv) > 3 else 15) * 1e6
UP = os.path.join(SRC, "assets", "uploads")
os.makedirs(OUT, exist_ok=True)

# Images used by the bundled CSS stay inside the theme (CSS can't look in wp-content/uploads).
keep = set()
for root, _, files in os.walk(os.path.join(SRC, "assets")):
    for f in files:
        if f.endswith(".css"):
            css = open(os.path.join(root, f), encoding="utf-8", errors="replace").read()
            for u in re.findall(r"url\(\s*['\"]?([^'\")]+)", css):
                p = os.path.normpath(os.path.join(root, u.split("?")[0].split("#")[0]))
                if os.path.exists(p):
                    keep.add(os.path.relpath(p, UP))

photos = []
for root, _, files in os.walk(UP):
    rel_root = os.path.relpath(root, UP)
    if not rel_root.startswith("20"):
        continue
    for f in files:
        rel = os.path.join(rel_root, f).replace(os.sep, "/")
        if rel not in keep:
            photos.append(rel)
photos.sort()

# Theme copy without the photos, plus the manifest of photos to install.
theme = os.path.join(OUT, "coastal-friends")
if os.path.exists(theme):
    shutil.rmtree(theme)
shutil.copytree(SRC, theme)
for rel in photos:
    os.remove(os.path.join(theme, "assets", "uploads", rel))
json.dump({rel: os.path.getsize(os.path.join(UP, rel)) for rel in photos},
          open(os.path.join(theme, "inc", "photos.json"), "w"), separators=(",", ":"))

def zipdir(path, zpath):
    with zipfile.ZipFile(zpath, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for root, dirs, files in os.walk(path):
            dirs.sort()
            for f in sorted(files):
                full = os.path.join(root, f)
                z.write(full, os.path.relpath(full, os.path.dirname(path)))

for old in [f for f in os.listdir(OUT) if f.endswith(".zip")]:
    os.remove(os.path.join(OUT, old))
zipdir(theme, os.path.join(OUT, "coastal-friends.zip"))

# Photo zips: paths like 2024/12/x.jpeg.webp, each zip <= MAX bytes of photos.
parts, cur, size = [], [], 0
for rel in photos:
    s = os.path.getsize(os.path.join(UP, rel))
    if cur and size + s > MAX:
        parts.append(cur); cur, size = [], 0
    cur.append(rel); size += s
if cur:
    parts.append(cur)
for i, part in enumerate(parts, 1):
    with zipfile.ZipFile(os.path.join(OUT, "coastal-friends-photos-%d.zip" % i), "w", zipfile.ZIP_STORED) as z:
        for rel in part:
            z.write(os.path.join(UP, rel), rel)
print("theme zip %.1f MB | photos %d in %d zips | kept in theme for CSS: %d" % (
    os.path.getsize(os.path.join(OUT, "coastal-friends.zip")) / 1e6, len(photos), len(parts), len(keep)))
