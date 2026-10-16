/**
 * ویجت تماس صوتی — دکمهٔ تلفن کنار لوگوی قرمز چت.
 *
 * نصب: این خط را قبل از </body> بگذار:
 *   <script src="https://<دامنه>/voice/voice-widget.js" defer></script>
 *
 * آدرس صفحهٔ تماس خودکار از محل همین فایل پیدا می‌شود، پس اگر پوشه را
 * جابه‌جا کردی، فقط آدرس اسکریپت را عوض کن.
 */
(function () {
  "use strict";

  // مسیر call.html از محل همین اسکریپت
  var me = document.currentScript || (function () {
    var s = document.getElementsByTagName("script");
    return s[s.length - 1];
  })();
  var CALL_URL = me && me.src ? me.src.replace(/voice-widget\.js(\?.*)?$/, "call.html") : "/voice/call.html";

  var TITLE = (me && me.getAttribute("data-title")) || "تماس صوتی";
  // کدام دانشنامه؟ هر سایت نوع خودش را می‌فرستد
  var KIND  = (me && me.getAttribute("data-kb"))    || "personal";
  CALL_URL += (CALL_URL.indexOf("?") < 0 ? "?" : "&")
            + "kb=" + encodeURIComponent(KIND)
            + "&title=" + encodeURIComponent(TITLE);

  if (document.getElementById("nyCallBtn")) return;   // دوبار نصب نشود

  var css = document.createElement("style");
  css.textContent = [
    "#nyCallBtn{position:fixed;bottom:22px;right:98px;width:64px;height:64px;border-radius:50%;",
    "background:#1b6b68;color:#fff;border:0;cursor:pointer;z-index:99998;display:grid;place-items:center;",
    "box-shadow:0 10px 26px rgba(27,107,104,.45);transition:opacity .22s ease,transform .22s ease,box-shadow .18s}",
    "#nyCallBtn:hover{transform:translateY(-2px) scale(1.04);box-shadow:0 14px 32px rgba(27,107,104,.55)}",
    "#nyCallBtn svg{width:30px;height:30px;fill:#fff}",
    "#nyCallBtn::after{content:'';position:absolute;inset:0;border-radius:50%;border:2px solid #1b6b68;",
    "animation:nyCallPulse 2.4s ease-out infinite;pointer-events:none}",
    "@keyframes nyCallPulse{0%{opacity:.7;transform:scale(1)}70%{opacity:0;transform:scale(1.5)}100%{opacity:0}}",
    "#nyCallWin{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;",
    "background:rgba(8,22,26,.72);backdrop-filter:blur(6px);padding:16px}",
    "#nyCallWin.on{display:flex}",
    "#nyCallBox{position:relative;width:100%;max-width:400px;height:100%;max-height:760px;border-radius:34px;",
    "overflow:hidden;background:#0d1f22;box-shadow:0 30px 90px rgba(0,0,0,.6);border:1px solid rgba(255,255,255,.1)}",
    "#nyCallBox iframe{width:100%;height:100%;border:0;display:block}",
    "#nyCallX{position:absolute;top:58px;left:14px;width:32px;height:32px;border-radius:50%;border:0;cursor:pointer;",
    "background:rgba(255,255,255,.14);color:#fff;font-size:17px;line-height:1;z-index:2}",
    "#nyCallX:hover{background:rgba(255,255,255,.26)}",
    "@media(max-width:520px){#nyCallWin{padding:0}#nyCallBox{max-width:none;max-height:none;border-radius:0;border:0}}",
    "@media(prefers-reduced-motion:reduce){#nyCallBtn::after{animation:none}}"
  ].join("");
  document.head.appendChild(css);

  var btn = document.createElement("button");
  btn.id = "nyCallBtn";
  btn.type = "button";
  btn.setAttribute("aria-label", TITLE);
  btn.title = TITLE;
  btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.6a1 1 0 0 1-.25 1z"/></svg>';

  var win = document.createElement("div");
  win.id = "nyCallWin";
  win.innerHTML =
    '<div id="nyCallBox">' +
      '<button id="nyCallX" type="button" aria-label="بستن">✕</button>' +
      '<iframe allow="microphone; autoplay" title="' + TITLE + '"></iframe>' +
    '</div>';
  document.body.appendChild(btn);
  document.body.appendChild(win);

  var frame = win.querySelector("iframe");
  var loaded = false;

  function open() {
    if (!loaded) { frame.src = CALL_URL; loaded = true; }
    win.classList.add("on");
    document.documentElement.style.overflow = "hidden";
  }
  function close() {
    win.classList.remove("on");
    document.documentElement.style.overflow = "";
    // با برداشتن src، میکروفون و صدا آزاد می‌شوند
    setTimeout(function () { frame.src = "about:blank"; loaded = false; }, 250);
  }

  btn.addEventListener("click", open);
  win.querySelector("#nyCallX").addEventListener("click", close);
  win.addEventListener("click", function (e) { if (e.target === win) close(); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });

  // وقتی منشی خداحافظی کرد، خودش خبر می‌دهد
  window.addEventListener("message", function (e) {
    if (e && e.data && e.data.nyCall === "ended") setTimeout(close, 2600);
  });
})();
