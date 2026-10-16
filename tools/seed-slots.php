<?php
/** ساخت وقت‌های آزاد از ساعت کاری و نوشتن در data/slots.json */
declare(strict_types=1);
define('VOICE_APP', 1);
require __DIR__ . '/../voice-core.php';
require __DIR__ . '/../voice-jalali.php';
require __DIR__ . '/../voice-store.php';

@unlink(__DIR__ . '/../data/slots.json');
$n = slots_generate();
echo "ساخته شد: $n وقت\n";
foreach (array_slice(slots_all(), 0, 4) as $s) {
    printf("  %s   [%s]\n", $s['slot'], $s['day']);
}
