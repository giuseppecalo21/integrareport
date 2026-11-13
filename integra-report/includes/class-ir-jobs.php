<?php
/**
 * Lavori lunghi (estrazione documenti, stesura sezioni) eseguiti in background:
 * il browser avvia il lavoro, il server lo esegue tramite una richiesta di loopback non bloccante
 * e il browser interroga lo stato. Se il loopback non parte (alcuni hosting lo bloccano), il
 * browser esegue il lavoro direttamente. I lavori sono idempotenti e riprendibili: elaborano solo
 * gli elementi non ancora completati. Durante le chiamate lunghe il lavoro aggiorna un segnale di
 * vita (heartbeat): se il processo PHP viene interrotto dal server, dopo STALE secondi il lavoro
 * risulta fermo e può essere ripreso dal punto in cui si era arrestato, senza ripagare il già fatto.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Jobs
{
    /** Secondi senza segnale di vita oltre i quali un lavoro "in corso" è considerato fermo. */
    public const STALE = 300;

    public static function start(int $id, string $type, array $items, bool $fresh = false): array
    {
        $job = IR_Store::job($id);
        if ($job && in_array($job['status'], ['queued', 'running'], true) && (time() - (int) ($job['heartbeat'] ?? $job['created'])) < 2 * self::STALE) {
            throw new RuntimeException('È già in corso un\'elaborazione per questo report.');
        }
        $job = [
            'type' => $type,
            'status' => 'queued',
            'token' => wp_generate_password(32, false),
            'items' => $items,
            'fresh' => $fresh,
            'done' => [],
            'current' => null,
            'errors' => [],
            'created' => time(),
            'heartbeat' => time(),
            'user' => get_current_user_id(),
            'usage' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
        ];
        IR_Store::save_job($id, $job);

        wp_remote_post(rest_url('integra-report/v1/run'), [
            'blocking' => false,
            'timeout' => 0.5,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => ['report' => $id, 'token' => $job['token']],
        ]);
        return self::public_job($job);
    }

    public static function public_job(?array $job): ?array
    {
        if (!$job) {
            return null;
        }
        unset($job['token']);
        return $job;
    }

    /** Esegue il lavoro (chiamato dal loopback o, in fallback, dal browser). */
    public static function run(int $id, string $token, bool $inline = false): array
    {
        $job = IR_Store::job($id);
        if (!$job || !hash_equals((string) $job['token'], $token)) {
            throw new RuntimeException('Token di esecuzione non valido.');
        }
        if ($job['status'] === 'running' && (time() - (int) $job['heartbeat']) < self::STALE) {
            return self::public_job($job);
        }
        if (!in_array($job['status'], ['queued', 'running'], true)) {
            return self::public_job($job);
        }

        // lock atomico (add_option fallisce se la chiave esiste già)
        $lock = 'ir_job_lock_' . $id;
        $existing = get_option($lock);
        if ($existing && (time() - (int) $existing) > self::STALE) {
            delete_option($lock);
        }
        if (!add_option($lock, time(), '', 'no')) {
            return self::public_job($job);
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if ($job['user']) {
            wp_set_current_user((int) $job['user']);
        }

        $job['status'] = 'running';
        $job['heartbeat'] = time();
        IR_Store::save_job($id, $job);

        // segnale di vita durante lo streaming: senza, una singola chiamata lunga farebbe sembrare
        // fermo un lavoro attivo. Non sovrascrive mai uno stato diverso da "running" (es. annullato).
        IR_Claude::$onTick = static function () use ($id, $lock) {
            $fresh = IR_Store::job($id);
            if ($fresh && ($fresh['status'] ?? '') === 'running') {
                $fresh['heartbeat'] = time();
                IR_Store::save_job($id, $fresh);
                update_option($lock, time(), false);
            }
        };

        try {
            foreach ($job['items'] as $item) {
                if (in_array($item, $job['done'], true)) {
                    continue;
                }
                $job['current'] = $item;
                $job['heartbeat'] = time();
                IR_Store::save_job($id, $job);
                update_option($lock, time(), false);

                try {
                    $usage = $job['type'] === 'extract' ? self::do_extract($id, $item, !empty($job['fresh'])) : self::do_write($id, $item);
                    foreach ($usage as $k => $v) {
                        $job['usage'][$k] = ($job['usage'][$k] ?? 0) + $v;
                    }
                } catch (Throwable $e) {
                    $job['errors'][$item] = $e->getMessage();
                    self::mark_error($id, $job['type'], $item, $e->getMessage());
                }
                $fresh = IR_Store::job($id);
                if ($fresh && ($fresh['status'] ?? '') === 'cancelled') {
                    $job['status'] = 'cancelled';
                    break;
                }
                $job['done'][] = $item;
                IR_Store::save_job($id, $job);
            }
            if ($job['status'] !== 'cancelled') {
                $job['status'] = $job['errors'] ? 'done_with_errors' : 'done';
            }
            if ($job['type'] === 'write') {
                IR_Store::mutate($id, function ($s) {
                    $s['report']['generated_at'] = time();
                    IR_Store::log($s, 'Report generato.');
                    return $s;
                });
            }
        } finally {
            IR_Claude::$onTick = null;
            $job['current'] = null;
            $job['finished'] = time();
            IR_Store::save_job($id, $job);
            delete_option($lock);
        }
        return self::public_job($job);
    }

    private static function do_extract(int $id, string $file_id, bool $fresh = false): array
    {
        $state = IR_Store::load($id);
        $file = IR_Store::find_file($state, $file_id);
        if (!$file) {
            return [];
        }
        $res = IR_Extractor::extract_file($id, $state, $file, $fresh);
        IR_Store::mutate($id, function ($s) use ($file_id, $res) {
            // sostituisce i risultati precedenti dello stesso file
            $s['extraction']['candidates'] = array_values(array_filter($s['extraction']['candidates'], fn($c) => $c['file_id'] !== $file_id));
            $s['extraction']['info'] = array_values(array_filter($s['extraction']['info'], fn($c) => $c['file_id'] !== $file_id));
            $s['extraction']['warnings'] = array_values(array_filter($s['extraction']['warnings'], fn($c) => $c['file_id'] !== $file_id));
            $s['extraction']['candidates'] = array_merge($s['extraction']['candidates'], $res['candidates']);
            $s['extraction']['info'] = array_merge($s['extraction']['info'], $res['info']);
            $s['extraction']['warnings'] = array_merge($s['extraction']['warnings'], $res['warnings']);
            $s['extraction']['docs'][$file_id] = $res['doc'];
            foreach ($s['files'] as &$f) {
                if ($f['id'] === $file_id) {
                    $f['extraction'] = ['status' => 'done', 'at' => time(), 'count' => count($res['candidates']), 'info' => count($res['info']), 'model' => $res['doc']['model'], 'cached' => !empty($res['doc']['cached'])];
                }
            }
            unset($f);
            $s['kpi'] = null; // i KPI vanno ricalcolati
            $cached = !empty($res['doc']['cached']);
            IR_Claude::log_spend($s, 'extract', $res['doc']['model'], $cached ? $res['doc']['saved'] : $res['doc']['usage'], $cached);
            IR_Store::log($s, 'Estrazione completata: ' . count($res['candidates']) . ' valori dal file ' . $file_id . ($cached ? ' (risposta ripresa dalla memoria, nessun costo)' : '') . '.');
            return $s;
        });
        return $res['doc']['usage'];
    }

    private static function do_write(int $id, string $sid): array
    {
        $state = IR_Store::load($id);
        if (!$state['kpi']) {
            throw new RuntimeException('KPI non calcolati.');
        }
        // principio della metodologia: niente sezioni "vuote". Una sezione di calcolo senza
        // alcuna tabella calcolabile viene esclusa e riportata nella checklist con il motivo.
        if ($sid !== 'S0') {
            $sec = IR_Catalog::sections()[$sid];
            $tables = (new IR_Tables($state, $state['kpi']))->for_section($sid);
            if ($sec['kind'] === 'analitica' && !$tables && $sid !== 'S22') {
                IR_Store::mutate($id, function ($s) use ($sid) {
                    $s['report']['sections'][$sid] = ['status' => 'skipped', 'reason' => 'nessun indicatore calcolabile con i documenti forniti', 'at' => time()];
                    return $s;
                });
                return [];
            }
        }
        $res = IR_Writer::write_section($id, $state, $state['kpi'], $sid);
        IR_Store::mutate($id, function ($s) use ($sid, $res) {
            $s['report']['sections'][$sid] = $res;
            IR_Claude::log_spend($s, 'write', $res['model'], $res['usage']);
            return $s;
        });
        return $res['usage'];
    }

    private static function mark_error(int $id, string $type, string $item, string $msg): void
    {
        IR_Store::mutate($id, function ($s) use ($type, $item, $msg) {
            if ($type === 'extract') {
                foreach ($s['files'] as &$f) {
                    if ($f['id'] === $item) {
                        $f['extraction'] = ['status' => 'error', 'at' => time(), 'error' => $msg];
                    }
                }
                unset($f);
            } else {
                if (($s['report']['sections'][$item]['status'] ?? '') === 'ok') {
                    // rigenerazione fallita di un capitolo già scritto: si conserva la versione valida
                    $s['report']['sections'][$item]['rewrite_error'] = ['msg' => $msg, 'at' => time()];
                } else {
                    $s['report']['sections'][$item] = ['status' => 'error', 'error' => $msg, 'at' => time()];
                }
            }
            IR_Store::log($s, "Errore su {$item}: {$msg}");
            return $s;
        });
    }
}
