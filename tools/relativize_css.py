"""Point absolute coastalfriends.org URLs inside bundled CSS at the local copies.

usage: python3 -I tools/relativize_css.py coastal-friends/assets
"""
import glob, os, re, sys

root = os.path.abspath(sys.argv[1])
for f in glob.glob(os.path.join(root, "**", "*.css"), recursive=True):
    s = open(f, encoding="utf-8").read()

    def sub(m):
        path = m.group(1)
        target = path[len("wp-content/"):] if path.startswith("wp-content/") else "includes/" + path[len("wp-includes/"):]
        return os.path.relpath(os.path.join(root, target), os.path.dirname(f))

    t = re.sub(r"https?://(?:www\.)?coastalfriends\.org/(wp-(?:content|includes)/[^\s'\")?#]+)", sub, s)
    if t != s:
        open(f, "w", encoding="utf-8").write(t)
