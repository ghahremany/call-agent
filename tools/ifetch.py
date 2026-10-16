#!/usr/bin/env python3
"""
درخواست به InfinityFree با حل چالش ضدربات (aes.js).

  python3 ifetch.py GET  https://example.com/panel.php
  python3 ifetch.py POST https://example.com/api.php sessionId=call-1 event=start
  python3 ifetch.py POST https://example.com/api.php sessionId=call-1 audio=@/tmp/a.webm
"""
import re, subprocess, sys, urllib.request, urllib.parse, http.cookiejar

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36"


def solve(html: str) -> str | None:
    """سه بلوک toNumbers را می‌گیرد و کوکی __test را می‌سازد."""
    blocks = re.findall(r'toNumbers\("([0-9a-fA-F]+)"\)', html)
    if len(blocks) < 3:
        return None
    a, b, c = blocks[0], blocks[1], blocks[2]
    out = subprocess.run(
        ["openssl", "enc", "-aes-128-cbc", "-d", "-K", a, "-iv", b, "-nopad"],
        input=bytes.fromhex(c), capture_output=True,
    )
    if out.returncode != 0:
        return None
    return out.stdout.hex()


def main() -> int:
    method, url = sys.argv[1].upper(), sys.argv[2]
    fields = sys.argv[3:]

    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    # ۱) صفحه را بگیر تا چالش (اگر هست) حل شود
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        body = op.open(req, timeout=30).read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")

    if "slowAES" in body or "toNumbers" in body:
        cookie = solve(body)
        if cookie:
            jar.set_cookie(http.cookiejar.Cookie(
                0, "__test", cookie, None, False, url.split("/")[2], False, False,
                "/", True, False, None, False, None, None, {},
            ))
            sys.stderr.write("[چالش حل شد]\n")

    # ۲) درخواست اصلی
    if method == "GET":
        req = urllib.request.Request(url, headers={"User-Agent": UA})
    else:
        parts, files = [], {}
        for f in fields:
            k, _, v = f.partition("=")
            if v.startswith("@"):
                path = v[1:]
                files[k] = path
            else:
                parts.append((k, v))
        data, ctype = _multipart(parts, files)
        req = urllib.request.Request(
            url, data=data, headers={"User-Agent": UA, "Content-Type": ctype}
        )

    try:
        r = op.open(req, timeout=120)
        raw, code, hdrs = r.read(), r.status, r.headers
    except urllib.error.HTTPError as e:
        raw, code, hdrs = e.read(), e.code, e.headers

    sys.stderr.write(f"[HTTP {code}] {hdrs.get('Content-Type','')}\n")
    for k in ("X-Voice-Heard", "X-Voice-Say", "X-Call-Ended"):
        if hdrs.get(k):
            sys.stderr.write(f"[{k}] {urllib.parse.unquote(hdrs[k])}\n")

    if (hdrs.get("Content-Type") or "").startswith("audio/"):
        out = "/tmp/voice-reply.mp3"
        open(out, "wb").write(raw)
        print(f"صدا ذخیره شد: {out}  ({len(raw)} بایت)")
    else:
        print(raw.decode("utf-8", "replace")[:3000])
    return 0


def _multipart(parts, files):
    b = "----VoiceTest" + "ab12cd34"
    out = b""
    for k, v in parts:
        out += f"--{b}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode()
    for k, path in files.items():
        data = open(path, "rb").read()
        fn = path.rsplit("/", 1)[-1]
        out += (f"--{b}\r\nContent-Disposition: form-data; name=\"{k}\"; "
                f"filename=\"{fn}\"\r\nContent-Type: audio/webm\r\n\r\n").encode() + data + b"\r\n"
    out += f"--{b}--\r\n".encode()
    return out, f"multipart/form-data; boundary={b}"


if __name__ == "__main__":
    sys.exit(main())
