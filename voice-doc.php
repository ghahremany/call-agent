<?php
/**
 * انبار عمومی اسناد — برای ربات‌ها، دانشنامه، مشتریان، تنظیمات و لاگ ورود.
 *
 * دو پشت‌بند:
 *   file  → یک فایل JSON برای هر مجموعه در data/
 *   mysql → یک جدول عمومی (ngy_voice_doc) با ستون JSON
 *
 * هر ردیف یک 'id' دارد. doc_put بدون id، ردیف تازه می‌سازد.
 */
declare(strict_types=1);

if (!defined('VOICE_APP')) {
    http_response_code(403);
    exit('forbidden');
}

const DOC_COLLS = ['prov', 'kb', 'cust', 'set', 'auth', 'note', 'sec'];

function doc_coll(string $coll): string
{
    $coll = preg_replace('/[^a-z_]/', '', strtolower($coll)) ?? '';
    if (!in_array($coll, DOC_COLLS, true)) {
        throw new InvalidArgumentException('مجموعهٔ ناشناخته: ' . $coll);
    }
    return $coll;
}

/* ───────────── پشت‌بند فایل ───────────── */

function doc_file(string $coll): string
{
    return v_dir('data') . '/doc-' . doc_coll($coll) . '.json';
}

/** تراکنش اتمیک روی فایل یک مجموعه */
function doc_txn(string $coll, callable $fn)
{
    $f  = doc_file($coll);
    $fh = @fopen($f, 'c+');
    if (!$fh) {
        v_log('doc: فایل باز نشد ' . $coll);
        return null;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return null;
    }
    $rows = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($rows)) {
        $rows = [];
    }

    $res = $fn($rows);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $res;
}

/* ───────────── پشت‌بند MySQL ───────────── */

function doc_table(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $pdo = store_pdo();
    if ($pdo === null) {
        return $ok = false;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ngy_voice_doc (
                id INT AUTO_INCREMENT PRIMARY KEY,
                coll VARCHAR(16) NOT NULL,
                payload MEDIUMTEXT NOT NULL,
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_coll (coll)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return $ok = true;
    } catch (Throwable $e) {
        v_log('doc: ساخت جدول نشد — ' . $e->getMessage());
        return $ok = false;
    }
}

function doc_use_mysql(): bool
{
    return store_driver() === 'mysql';
}

/* ───────────── API عمومی ───────────── */

function doc_all(string $coll): array
{
    $coll = doc_coll($coll);

    if (doc_use_mysql()) {
        if (!doc_table()) {
            return [];
        }
        try {
            $st = store_pdo()->prepare("SELECT id, payload FROM ngy_voice_doc WHERE coll = ? ORDER BY id ASC");
            $st->execute([$coll]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $j = json_decode((string) $r['payload'], true);
                if (is_array($j)) {
                    $j['id'] = (int) $r['id'];
                    $out[] = $j;
                }
            }
            return $out;
        } catch (Throwable $e) {
            v_log('doc_all: ' . $e->getMessage());
            return [];
        }
    }

    $rows = (array) doc_txn($coll, static fn(array &$r) => $r);
    return array_values(array_filter($rows, 'is_array'));
}

function doc_get(string $coll, int $id): ?array
{
    foreach (doc_all($coll) as $r) {
        if ((int) ($r['id'] ?? 0) === $id) {
            return $r;
        }
    }
    return null;
}

/** درج یا به‌روزرسانی. خروجی: شناسهٔ ردیف. */
function doc_put(string $coll, array $row): int
{
    $coll = doc_coll($coll);
    $id   = (int) ($row['id'] ?? 0);
    unset($row['id']);

    if (doc_use_mysql()) {
        if (!doc_table()) {
            return 0;
        }
        $payload = json_encode($row, JSON_UNESCAPED_UNICODE);
        try {
            if ($id > 0) {
                $st = store_pdo()->prepare("UPDATE ngy_voice_doc SET payload = ? WHERE id = ? AND coll = ?");
                $st->execute([$payload, $id, $coll]);
                return $id;
            }
            $st = store_pdo()->prepare("INSERT INTO ngy_voice_doc (coll, payload) VALUES (?, ?)");
            $st->execute([$coll, $payload]);
            return (int) store_pdo()->lastInsertId();
        } catch (Throwable $e) {
            v_log('doc_put: ' . $e->getMessage());
            return 0;
        }
    }

    return (int) doc_txn($coll, static function (array &$rows) use ($row, $id) {
        if ($id > 0) {
            foreach ($rows as $i => $r) {
                if ((int) ($r['id'] ?? 0) === $id) {
                    $rows[$i] = array_merge($r, $row, ['id' => $id]);
                    return $id;
                }
            }
        }
        $next = 1;
        foreach ($rows as $r) {
            $next = max($next, (int) ($r['id'] ?? 0) + 1);
        }
        $rows[] = array_merge($row, ['id' => $next]);
        return $next;
    });
}

function doc_del(string $coll, int $id): bool
{
    $coll = doc_coll($coll);

    if (doc_use_mysql()) {
        if (!doc_table()) {
            return false;
        }
        try {
            $st = store_pdo()->prepare("DELETE FROM ngy_voice_doc WHERE id = ? AND coll = ?");
            $st->execute([$id, $coll]);
            return true;
        } catch (Throwable $e) {
            v_log('doc_del: ' . $e->getMessage());
            return false;
        }
    }

    return (bool) doc_txn($coll, static function (array &$rows) use ($id) {
        foreach ($rows as $i => $r) {
            if ((int) ($r['id'] ?? 0) === $id) {
                array_splice($rows, $i, 1);
                return true;
            }
        }
        return false;
    });
}

/** پالایش با تابع دلخواه */
function doc_where(string $coll, callable $fn): array
{
    return array_values(array_filter(doc_all($coll), $fn));
}

/* ───────────── تنظیمات (کلید/مقدار) ───────────── */

function doc_setting(string $key, $default = null)
{
    foreach (doc_all('set') as $r) {
        if (($r['k'] ?? '') === $key) {
            return $r['v'] ?? $default;
        }
    }
    return $default;
}

function doc_set(string $key, $val): void
{
    foreach (doc_all('set') as $r) {
        if (($r['k'] ?? '') === $key) {
            doc_put('set', ['id' => (int) $r['id'], 'k' => $key, 'v' => $val]);
            return;
        }
    }
    doc_put('set', ['k' => $key, 'v' => $val]);
}
