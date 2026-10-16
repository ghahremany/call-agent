/**
 * ویجت تماس صوتی — دکمهٔ تلفن کنار لوگوی چت.
 *
 * نصب: این خط را قبل از </body> بگذار:
 *   <script src="https://<دامنه>/voice/voice-widget.js?v=3"
 *           data-kb="personal" data-title="تماس صوتی" defer></script>
 *
 * data-kb    → کدام دانشنامه (personal | ngy | clinic)
 * data-title → نامی که در صفحهٔ تماس نشان داده می‌شود
 */
(function () {
  "use strict";

  var me = document.currentScript || (function () {
    var s = document.getElementsByTagName("script");
    return s[s.length - 1];
  })();

  var TITLE = (me && me.getAttribute("data-title")) || "تماس صوتی";
  var KIND  = (me && me.getAttribute("data-kb"))    || "personal";

  var CALL_URL = me && me.src ? me.src.replace(/voice-widget\.js(\?.*)?$/, "call.html") : "/voice/call.html";
  CALL_URL += (CALL_URL.indexOf("?") < 0 ? "?" : "&")
            + "kb=" + encodeURIComponent(KIND)
            + "&title=" + encodeURIComponent(TITLE)
            + "&v=3";

  if (document.getElementById("nyCallBtn")) return;   // دوبار نصب نشود

  var css = document.createElement("style");
  css.textContent = [
    /* ── دکمهٔ تلفن: لرزش تماس ورودی ── */
    "#nyCallBtn{position:fixed;bottom:22px;right:98px;width:64px;height:64px;border-radius:50%;",
    "background:#1b6b68;color:#fff;border:0;cursor:pointer;z-index:99998;display:grid;place-items:center;",
    "box-shadow:0 10px 26px rgba(27,107,104,.45);transform-origin:50% 50%;",
    "animation:nyCallRing 3.4s ease-in-out infinite;transition:box-shadow .18s}",
    "#nyCallBtn:hover{animation-play-state:paused;box-shadow:0 14px 32px rgba(27,107,104,.6)}",
    "#nyCallBtn svg{width:30px;height:30px;fill:#fff}",
    /* موج پشت دکمه */
    "#nyCallBtn::after{content:'';position:absolute;inset:0;border-radius:50%;",
    "border:2px solid #1b6b68;animation:nyCallPulse 2.4s ease-out infinite;pointer-events:none}",
    /* لرزش: چند تکان سریع، بعد سکوت — مثل زنگ گوشی */
    "@keyframes nyCallRing{",
    "0%,58%,100%{transform:rotate(0deg) scale(1)}",
    "62%{transform:rotate(-13deg) scale(1.07)}",
    "66%{transform:rotate(12deg) scale(1.07)}",
    "70%{transform:rotate(-10deg) scale(1.05)}",
    "74%{transform:rotate(9deg) scale(1.05)}",
    "78%{transform:rotate(-6deg) scale(1.03)}",
    "82%{transform:rotate(4deg) scale(1.02)}",
    "86%{transform:rotate(0deg) scale(1)}}",
    "@keyframes nyCallPulse{0%{opacity:.7;transform:scale(1)}70%{opacity:0;transform:scale(1.5)}100%{opacity:0}}",

    /* ── پنجرهٔ تماس: تمام صفحه ── */
    "#nyCallWin{position:fixed;inset:0;z-index:100000;display:none;background:#0a0b0f}",
    "#nyCallWin.on{display:block}",
    "#nyCallBox{position:absolute;inset:0;width:100%;height:100%;overflow:hidden;background:#0a0b0f}",
    "#nyCallBox iframe{width:100%;height:100%;border:0;display:block}",

    "@media(prefers-reduced-motion:reduce){#nyCallBtn{animation:none}#nyCallBtn::after{animation:none}}"
  ].join("");
  document.head.appendChild(css);

  /* ── دکمه ── */
  var btn = document.createElement("button");
  btn.id = "nyCallBtn";
  btn.type = "button";
  btn.setAttribute("aria-label", TITLE);
  btn.title = TITLE;
  btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.6a1 1 0 0 1-.25 1z"/></svg>';

  /* ── پنجرهٔ تمام‌صفحه (بدون دکمهٔ بستن) ── */
  var win = document.createElement("div");
  win.id = "nyCallWin";
  win.innerHTML = '<div id="nyCallBox"><iframe allow="microphone; autoplay" title="' + TITLE + '"></iframe></div>';
  document.body.appendChild(btn);
  document.body.appendChild(win);

  var frame  = win.querySelector("iframe");
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
    setTimeout(function () { frame.src = "about:blank"; loaded = false; }, 200);
  }

  btn.addEventListener("click", open);
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && win.classList.contains("on")) close();
  });

  /* صفحهٔ تماس خبر می‌دهد که تمام شد */
  window.addEventListener("message", function (e) {
    if (!e || !e.data || e.data.nyCall !== "ended") return;
    var d = Number(e.data.delay || 0);
    setTimeout(close, Math.max(0, Math.min(8000, d)));
  });
})();
