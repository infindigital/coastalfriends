import os, re, sys, subprocess, urllib.parse
SP = sys.argv[1]; OUT = sys.argv[2]   # url list, output assets dir
BASE = "https://coastalfriends.org/"
UA = "Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0 Safari/537.36"
def local(url):
    p = urllib.parse.urlparse(url).path
    p = p.replace("/wp-content/", "/", 1) if p.startswith("/wp-content/") else p.replace("/wp-includes/", "/includes/", 1)
    return os.path.join(OUT, urllib.parse.unquote(p.lstrip("/")))
seen=set(); failed=[]
def get(url):
    url = url.split("#")[0].split("?")[0]
    if url in seen or "coastalfriends.org/wp-" not in url: return
    if not re.search(r"/wp-(content|includes)/", url): return
    if url.endswith("/"): return
    seen.add(url); dst = local(url)
    if not os.path.exists(dst):
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        r = subprocess.run(["curl","-sSfL","-A",UA,"-o",dst,url],capture_output=True)
        if r.returncode: failed.append(url); 
        if r.returncode:
            if os.path.exists(dst): os.remove(dst)
            return
    if dst.endswith(".css"):
        css = open(dst,encoding="utf-8",errors="replace").read()
        for ref in re.findall(r"url\(\s*['\"]?([^'\")]+)['\"]?\s*\)", css) + re.findall(r"@import\s+['\"]([^'\"]+)", css):
            if ref.startswith("data:"): continue
            get(urllib.parse.urljoin(url, ref))
for u in open(SP).read().split():
    get(u)
print("downloaded", len(seen)-len(failed), "failed", len(failed))
for f in failed: print("FAIL", f)
