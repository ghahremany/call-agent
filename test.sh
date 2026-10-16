#!/usr/bin/env bash
# اجرای همهٔ آزمون‌ها — بدون شبکه، بدون کلید
set -e
cd "$(dirname "$0")"

echo "══ نحو فایل‌های PHP ══"
for f in api.php panel.php voice-core.php voice-nlu.php voice-brain.php voice-store.php voice-config.php; do
  php -l "$f" | sed 's/^/  /'
done

echo
echo "══ آزمون فهم فارسی ══"
php tests/nlu-test.php

echo "══ آزمون ماشین حالت گفتگو ══"
php tests/brain-test.php
