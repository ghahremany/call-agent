#!/usr/bin/env python3
"""تست زندهٔ یک تماس کامل: صدا را با ElevenLabs می‌سازد و به api.php می‌فرستد."""
import json, subprocess, sys, urllib.request, urllib.parse, http.cookiejar, os

EL   = os.environ["EL_KEY"]
BASE = "https://ghahremani.xo.je/voice/api.php"
SID  = sys.argv[1] if len(sys.argv) > 1 else "call-live-99"
UA   = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36"

def tts(text, path):
    req = urllib.request.Request(
        "https://api.elevenlabs.io/v1/text-to-speech/EXAVITQu4vr4xnSDxMaL?output_format=mp3_22050_32",
        data=json.dumps({"text": text, "model_id": "eleven_v3", "language_code": "fa"}).encode(),
        headers={"xi-api-key": EL, "Content-Type": "application/json"})
    open(path, "wb").write(urllib.request.urlopen(req, timeout=60).read())

def solve(html):
    import re
    b = re.findall(r'toNumbers\("([0-9a-fA-F]+)"\)', html)
    if len(b) < 3: return None
    out = subprocess.run(["openssl","enc","-aes-128-cbc","-d","-K",b[0],"-iv",b[1],"-nopad"],
                         input=bytes.fromhex(b[2]), capture_output=True)
    return out.stdout.hex() if out.returncode == 0 else None

jar = http.cookiejar.CookieJar()
op  = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def post(fields, files=None):
    boundary = "----LiveCall"
    body = b""
    for k, v in fields.items():
        body += f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode()
    for k, p in (files or {}).items():
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"; "
                 f"filename=\"audio.mp3\"\r\nContent-Type: audio/mpeg\r\n\r\n").encode()
        body += open(p, "rb").read() + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    req = urllib.request.Request(BASE, data=body,
        headers={"User-Agent": UA, "Content-Type": f"multipart/form-data; boundary={boundary}"})
    try:
        r = op.open(req, timeout=120); return r.read(), r.status, r.headers
    except urllib.error.HTTPError as e:
        return e.read(), e.code, e.headers

# حل چالش یک‌بار
try:
    html = op.open(urllib.request.Request(BASE.replace("api.php","call.html"),
                   headers={"User-Agent": UA}), timeout=30).read().decode("utf-8","replace")
    c = solve(html)
    if c:
        jar.set_cookie(http.cookiejar.Cookie(0,"__test",c,None,False,"ghahremani.xo.je",False,False,"/",True,False,None,False,None,None,{}))
        print("[چالش حل شد]")
except Exception as e:
    print("[چالش:", e, "]")

print("\n═══ ۱) شروع تماس ═══")
data, code, h = post({"sessionId": SID, "event": "start"})
print(f"  HTTP {code}  {h.get('Content-Type')}  {len(data)} بایت")

turns = [
    "میخوام وقت رزرو کنم",
    "بله",
    "اسمم مریم رضایی و شمارم صفر نهصد و دوازده سیصد و چهل و پنج ششصد و هفتاد و هشت نه",
]
for i, text in enumerate(turns, 2):
    print(f"\n═══ {i}) تماس‌گیرنده: «{text}» ═══")
    tts(text, "/tmp/caller.mp3")
    data, code, h = post({"sessionId": SID}, {"audio": "/tmp/caller.mp3"})
    ct = h.get("Content-Type") or ""
    if ct.startswith("audio/"):
        open(f"/tmp/reply-{i}.mp3","wb").write(data)
        print(f"  شنید  : {urllib.parse.unquote(h.get('X-Voice-Heard') or '-')}")
        print(f"  گفت   : {urllib.parse.unquote(h.get('X-Voice-Say') or '-')}")
        print(f"  پایان : {h.get('X-Call-Ended')}   صدا: {len(data)} بایت → /tmp/reply-{i}.mp3")
    else:
        print(f"  HTTP {code}: {data.decode('utf-8','replace')[:300]}")
