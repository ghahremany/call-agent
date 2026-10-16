<?php
/**
 * ربات‌ها (درگاه‌های مدل زبانی) و دانشنامه.
 *
 * ربات‌ها در انبار اسناد می‌مانند، پس از پنل قابل افزودن و ویرایش‌اند.
 * اگر یکی خطا بدهد، بعدی امتحان می‌شود (failover).
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

/* ───────────── ربات‌ها ───────────── */

/** ربات‌های فعال، به ترتیب اولویت */
function prov_list(bool $onlyEnabled = true): array
{
    $rows = doc_all('prov');
    if ($onlyEnabled) {
        $rows = array_filter($rows, static fn($r) => !empty($r['enabled']));
    }
    usort($rows, static fn($a, $b) => ((int) ($a['prio'] ?? 100)) <=> ((int) ($b['prio'] ?? 100)));
    return array_values($rows);
}

/**
 * اگر جدول ربات‌ها خالی است، از تنظیمات پر می‌شود.
 * این کار فقط یک بار انجام می‌شود.
 */
function prov_seed(): void
{
    if (doc_all('prov')) {
        return;
    }
    $prio = 10;
    foreach ([
        ['name' => 'Groq', 'base' => (string) vcfg('groq_base'), 'model' => (string) vcfg('groq_model'), 'token' => (string) vcfg('groq_key')],
        ['name' => 'AvalAI', 'base' => (string) vcfg('avalai_base'), 'model' => (string) vcfg('avalai_model'), 'token' => (string) vcfg('avalai_key')],
    ] as $p) {
        if ($p['token'] === '') {
            continue;
        }
        doc_put('prov', $p + [
            'enabled' => 1, 'prio' => $prio, 'ok_count' => 0, 'fail_count' => 0, 'last_err' => '',
        ]);
        $prio += 10;
    }
}

function prov_mark(int $id, bool $ok, string $err = ''): void
{
    $p = doc_get('prov', $id);
    if (!$p) {
        return;
    }
    if ($ok) {
        $p['ok_count']   = (int) ($p['ok_count'] ?? 0) + 1;
        $p['last_err']   = '';
    } else {
        $p['fail_count'] = (int) ($p['fail_count'] ?? 0) + 1;
        $p['last_err']   = v_cut((string) preg_replace('/\s+/', ' ', $err), 200);
    }
    doc_put('prov', $p);
}

/** آزمون یک ربات با یک پیام کوتاه */
function prov_test(array $p): array
{
    $r = v_chat(
        (string) $p['base'],
        (string) $p['token'],
        (string) $p['model'],
        [['role' => 'user', 'content' => 'فقط بنویس: سلام']],
        false
    );
    return ['ok' => $r['code'] === 200 && $r['text'] !== '', 'code' => $r['code'], 'text' => $r['text']];
}

/* ───────────── دانشنامه (چندگانه) ───────────── */

/**
 * هر دانشنامه یک «نوع» دارد: personal برای سایت شخصی، ngy برای نگهبان‌یار…
 * ویجت هر سایت نوع خودش را می‌فرستد، پس یک نصب، چند دستیار می‌دهد.
 */
const KB_KINDS = [
    'personal' => 'دستیار شخصی (سایت خودم)',
    'ngy'      => 'پشتیبان نگهبان‌یار',
    'clinic'   => 'منشی کلینیک',
];

function kb_kind(string $k): string
{
    $k = preg_replace('/[^a-z_]/', '', strtolower($k)) ?? '';
    return isset(KB_KINDS[$k]) ? $k : 'personal';
}

/** آخرین نسخهٔ یک نوع، با پیش‌فرض‌ها */
function kb_doc(string $kind = 'personal'): array
{
    $kind = kb_kind($kind);
    $rows = array_values(array_filter(doc_all('kb'), static fn($r) => ($r['kind'] ?? 'personal') === $kind));
    usort($rows, static fn($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));

    $d = $rows[0] ?? [];
    return [
        'id'       => (int) ($d['id'] ?? 0),
        'kind'     => $kind,
        'content'  => (string) ($d['content'] ?? ''),
        'title'    => (string) ($d['title'] ?? vcfg('clinic')),
        'greeting' => (string) ($d['greeting'] ?? vcfg('greeting')),
        'mode'     => (string) ($d['mode'] ?? 'contact'),   // contact | booking
        'note'     => (string) ($d['note'] ?? ''),
        'at'       => (string) ($d['at'] ?? ''),
    ];
}

function kb_get(string $kind = 'personal'): string
{
    return kb_doc($kind)['content'];
}

function kb_save(string $kind, string $content, string $note = '', array $extra = []): int
{
    return doc_put('kb', array_merge([
        'kind'    => kb_kind($kind),
        'content' => $content,
        'note'    => $note !== '' ? $note : 'ویرایش دستی',
        'at'      => date('c'),
    ], $extra));
}

/** انواعی که واقعاً دانشنامه دارند */
function kb_kinds_used(): array
{
    $out = [];
    foreach (doc_all('kb') as $r) {
        $k = (string) ($r['kind'] ?? 'personal');
        $out[$k] = KB_KINDS[$k] ?? $k;
    }
    return $out;
}

/** تاریخچهٔ نسخه‌های یک نوع */
function kb_history(string $kind = 'personal', int $limit = 20): array
{
    $kind = kb_kind($kind);
    $rows = array_values(array_filter(doc_all('kb'), static fn($r) => ($r['kind'] ?? 'personal') === $kind));
    usort($rows, static fn($a, $b) => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)));
    return array_map(static fn($r) => [
        'id'   => (int) $r['id'],
        'note' => (string) ($r['note'] ?? ''),
        'at'   => (string) ($r['at'] ?? ''),
        'len'  => strlen((string) ($r['content'] ?? '')),
    ], array_slice($rows, 0, $limit));
}

/* ───────────── پرامپت سیستمی ───────────── */

/** پرامپت پایه + دانشنامه + فهرست وقت‌های آزاد */
function v_llm_system(array $slots, string $kind = 'personal'): string
{
    $list = $slots
        ? implode("\n", array_map(static fn($s) => '- ' . $s, $slots))
        : '- NONE CACHED';

    $sys = str_replace('{slots}', $list, (string) vcfg('llm_system'));

    // دانشنامه به‌عنوان «مرجع» جای‌گذاری می‌شود، نه دستور — وگرنه مدل
    // به‌جای JSON، محاوره‌ای جواب می‌دهد.
    return str_replace('{kb}', trim(kb_get($kind)), $sys);
}

/* ───────────── فهم جملهٔ کاربر ───────────── */

/**
 * خروجی: ['transcript'=>, 'intent'=>, 'name'=>, 'phone'=>, 'requested_time'=>,
 *          'requested_slot'=>, 'reply'=>, '_provider'=>]
 * اگر همهٔ ربات‌ها خطا بدهند، آرایهٔ خالی برمی‌گردد.
 */
function v_llm_understand(array $slots, array $history, string $heard, string $kind = 'personal'): array
{
    $messages = [['role' => 'system', 'content' => v_llm_system($slots, $kind)]];
    foreach ($history as $m) {
        if (isset($m['role'], $m['content'])) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => "Caller transcript:\n" . $heard];

    prov_seed();

    foreach (prov_list() as $p) {
        $id    = (int) ($p['id'] ?? 0);
        $token = (string) ($p['token'] ?? '');
        $base  = (string) ($p['base'] ?? '');
        $model = (string) ($p['model'] ?? '');
        if ($token === '' || $base === '' || $model === '') {
            continue;
        }

        $res = v_chat($base, $token, $model, $messages, true);
        if ($res['code'] !== 200 || $res['text'] === '') {
            prov_mark($id, false, 'HTTP ' . $res['code']);
            continue;
        }
        $parsed = v_json_loose($res['text']);

        // اگر مدل به‌جای JSON حرف زد، یک بار با تذکر سخت‌گیرانه دوباره بپرس
        if ($parsed === null) {
            v_log('LLM(' . ($p['name'] ?? '?') . ') بار اول JSON نداد — تلاش دوباره');
            $retry = $messages;
            $retry[] = ['role' => 'assistant', 'content' => v_cut($res['text'], 300)];
            $retry[] = ['role' => 'user', 'content' => 'That was not valid JSON. Reply with ONE JSON object only, no other text.'];
            $res2 = v_chat($base, $token, $model, $retry, true);
            if ($res2['code'] === 200 && $res2['text'] !== '') {
                $parsed = v_json_loose($res2['text']);
            }
        }

        if ($parsed === null) {
            prov_mark($id, false, 'خروجی JSON نبود');
            v_log('LLM(' . ($p['name'] ?? '?') . ') خروجی JSON نبود: ' . v_cut($res['text'], 200));
            continue;
        }
        prov_mark($id, true);
        $parsed['_provider'] = (string) ($p['name'] ?? '?');
        return $parsed;
    }
    return [];
}
