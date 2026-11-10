"""Crawl every public HTML URL of coastalfriends.org into <out>/<path>/index.html."""
import os, re, subprocess, sys, urllib.parse
seeds, out = sys.argv[1], sys.argv[2]
UA = "Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0 Safari/537.36"
SKIP = re.compile(r"^/(wp-|feed|comments/feed|xmlrpc)|/feed/?$|/embed/?$|\.[a-z0-9]{2,5}$", re.I)
def norm(u):
    p = urllib.parse.urlparse(u.replace("&#038;", "&").replace("&amp;", "&"))
    if p.netloc not in ("coastalfriends.org", "www.coastalfriends.org"): return None
    path = p.path or "/"
    if SKIP.search(path) or p.query: return None
    return path if path.endswith("/") else path + "/"
queue = [norm(u) for u in open(seeds).read().split()]
seen, status = set(), {}
while queue:
    path = queue.pop(0)
    if not path or path in seen: continue
    seen.add(path)
    dst = os.path.join(out, path.strip("/"), "index.html") if path != "/" else os.path.join(out, "index.html")
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    r = subprocess.run(["curl", "-sS", "-L", "-A", UA, "-o", dst, "-w", "%{http_code} %{url_effective}", "https://coastalfriends.org" + path], capture_output=True, text=True)
    code, final = r.stdout.split(" ", 1)
    status[path] = code
    if code != "200" or norm(final) != path:
        os.remove(dst); print("skip", code, path, "->", final); continue
    html = open(dst, encoding="utf-8").read()
    for href in re.findall(r'href="([^"]+)"', html):
        n = norm(href)
        if n and n not in seen: queue.append(n)
print("saved", sum(1 for c in status.values() if c == "200"), "of", len(status))
