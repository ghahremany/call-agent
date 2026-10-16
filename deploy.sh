#!/usr/bin/env bash
# آپلود روی InfinityFree با FTP.
#
#   FTP_HOST=... FTP_USER=... FTP_PASS=... ./deploy.sh /htdocs/voice
#
# پیش‌فرض: مقصد /htdocs/voice
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
DEST="${1:-/htdocs/voice}"

: "${FTP_HOST:?FTP_HOST لازم است}"
: "${FTP_USER:?FTP_USER لازم است}"
: "${FTP_PASS:?FTP_PASS لازم است}"

BASE="ftp://${FTP_HOST}"
# همهٔ php ها به‌جز فایل‌های حساس، به‌علاوهٔ فایل‌های ثابت.
# فهرست دستی نگه‌داشتن باعث شد voice-sec.php جا بماند و پنل ۵۰۰ بدهد.
FILES=()
for f in *.php; do
  case "$f" in
    voice-secrets.php|voice-panel-auth.php|voice-secrets.example.php) continue ;;
  esac
  FILES+=("$f")
done
for f in call.html demo.html voice-widget.js .htaccess; do
  [ -f "$f" ] && FILES+=("$f")
done
# توجه: voice-secrets.php عمداً اینجا نیست — دستی و یک‌بار آپلود می‌شود.

echo "→ ساخت پوشه‌ها"
for d in "" sessions cache logs data backups; do
  curl -sS --ftp-create-dirs -u "${FTP_USER}:${FTP_PASS}" -T /dev/null "${BASE}${DEST}/${d}/.keep" >/dev/null 2>&1 || true
done

echo "→ آپلود فایل‌ها"
for f in "${FILES[@]}"; do
  curl -sS --ftp-create-dirs -u "${FTP_USER}:${FTP_PASS}" -T "${DIR}/${f}" "${BASE}${DEST}/${f}"
  echo "   ${f}"
done

for d in sessions cache logs data backups; do
  curl -sS --ftp-create-dirs -u "${FTP_USER}:${FTP_PASS}" -T "${DIR}/${d}/.htaccess" "${BASE}${DEST}/${d}/.htaccess"
  echo "   ${d}/.htaccess"
done

echo "✓ تمام. حالا: https://<domain>${DEST#/htdocs}/panel.php"
