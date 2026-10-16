<?php
declare(strict_types=1);
define('VOICE_APP', 1);
require __DIR__ . '/../voice-core.php';
require __DIR__ . '/../voice-store.php';
require __DIR__ . '/../voice-doc.php';

$pass = 0; $fail = 0;
function check(string $l, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ✓ %s\n", $l); }
    else { $fail++; printf("  ✗ %s\n      انتظار: %s\n      دریافت: %s\n", $l, var_export($want,true), var_export($got,true)); }
}

@unlink(__DIR__ . '/../data/doc-prov.json');

echo "\n── درج و خواندن ──\n";
$id1 = doc_put('prov', ['name' => 'Groq', 'model' => 'x', 'enabled' => 1]);
$id2 = doc_put('prov', ['name' => 'AvalAI', 'model' => 'y', 'enabled' => 1]);
check('شناسهٔ اول', $id1, 1);
check('شناسهٔ دوم', $id2, 2);
check('تعداد ردیف', count(doc_all('prov')), 2);
check('خواندن با شناسه', doc_get('prov', 2)['name'], 'AvalAI');

echo "\n── ویرایش ──\n";
doc_put('prov', ['id' => $id1, 'name' => 'Groq', 'model' => 'z', 'enabled' => 0]);
check('مدل عوض شد', doc_get('prov', 1)['model'], 'z');
check('فعال عوض شد', doc_get('prov', 1)['enabled'], 0);
check('تعداد ردیف ثابت', count(doc_all('prov')), 2);

echo "\n── حذف ──\n";
doc_del('prov', $id1);
check('حذف شد', count(doc_all('prov')), 1);
check('شناسهٔ حذف‌شده نیست', doc_get('prov', 1), null);

echo "\n── پالایش ──\n";
check('فقط فعال‌ها', count(doc_where('prov', fn($r) => ($r['enabled'] ?? 0) == 1)), 1);

echo "\n── تنظیمات ──\n";
doc_set('work_days', [0,1,2]);
doc_set('work_days', [0,1,2,3]);
check('تنظیم خوانده شد', doc_setting('work_days'), [0,1,2,3]);
check('تنظیم نبوده، پیش‌فرض', doc_setting('nope', 'پیش‌فرض'), 'پیش‌فرض');
check('تنظیم تکراری ساخته نشد', count(doc_all('set')), 1);

echo "\n── مجموعهٔ ناشناخته ──\n";
try { doc_all('hack'); check('استثنا داد', false, true); }
catch (InvalidArgumentException $e) { check('استثنا داد', true, true); }

echo "\n";
printf("نتیجه: %d موفق، %d ناموفق\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
