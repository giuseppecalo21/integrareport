<?php
/**
 * API REST interne (namespace integra-report/v1), accessibili agli utenti con la capability
 * "use_integra_report" tramite autenticazione cookie + nonce di WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Rest
{
    private const NS = 'integra-report/v1';
    public const EXTS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'xlsx', 'docx', 'csv', 'txt', 'xml', 'xbrl'];

    public static function can(): bool
    {
        return current_user_can(IR_CAP);
    }

    public static function register_routes(): void
    {
        $id = '(?P<id>\d+)';
        $r = static function (string $route, string $methods, callable $cb, bool $public = false) {
            register_rest_route(self::NS, $route, [
                'methods' => $methods,
                'callback' => static function (WP_REST_Request $req) use ($cb) {
                    try {
                        return rest_ensure_response($cb($req));
                    } catch (Throwable $e) {
                        return new WP_Error('ir_error', $e->getMessage(), ['status' => 400]);
                    }
                },
                'permission_callback' => $public ? '__return_true' : [self::class, 'can'],
            ]);
        };

        $r('/reports', 'GET', fn() => IR_Store::list_reports());
        $r('/reports', 'POST', [self::class, 'create']);
        $r("/reports/$id", 'GET', [self::class, 'get']);
        $r("/reports/$id", 'POST', [self::class, 'update']);
        $r("/reports/$id", 'DELETE', [self::class, 'delete']);
        $r("/reports/$id/files", 'POST', [self::class, 'upload']);
        $r("/reports/$id/files/(?P<fid>[a-z0-9]+)", 'DELETE', [self::class, 'delete_file']);
        $r("/reports/$id/files/(?P<fid>[a-z0-9]+)", 'GET', [self::class, 'serve_file']);
        $r("/reports/$id/extract", 'POST', [self::class, 'extract']);
        $r("/reports/$id/write", 'POST', [self::class, 'write']);
        $r("/reports/$id/run-inline", 'POST', [self::class, 'run_inline']);
        $r("/reports/$id/cancel", 'POST', [self::class, 'cancel']);
        $r("/reports/$id/dataset", 'GET', [self::class, 'dataset']);
        $r("/reports/$id/override", 'POST', [self::class, 'override']);
        $r("/reports/$id/compute", 'POST', [self::class, 'compute']);
        $r("/reports/$id/approve", 'POST', [self::class, 'approve']);
        $r("/reports/$id/document", 'GET', [self::class, 'document']);
        $r("/reports/$id/docx", 'GET', [self::class, 'docx']);
        $r('/run', 'POST', [self::class, 'run'], true);
    }

    private static function id(WP_REST_Request $req): int
    {
        $id = (int) $req['id'];
        if (!IR_Store::exists($id)) {
            throw new RuntimeException('Report non trovato.');
        }
        return $id;
    }

    private static function user(): string
    {
        $u = wp_get_current_user();
        return $u && $u->exists() ? $u->display_name : 'utente';
    }

    public static function create(WP_REST_Request $req): array
    {
        $title = sanitize_text_field((string) $req->get_param('title'));
        $id = IR_Store::create($title);
        return ['id' => $id];
    }

    public static function payload(int $id, ?array $state = null): array
    {
        $state = $state ?? IR_Store::load($id);
        $avail = IR_Store::available_slots($state);
        $resolved = IR_Dataset::resolve($state);
        $out = $state;
        unset($out['kpi']['data']);
        if (isset($out['kpi']['nodes'])) {
            // il cruscotto lato client usa i nodi; i dati completi si leggono da /dataset
            $out['kpi']['nodes_count'] = count($out['kpi']['nodes']);
        }
        if (!empty($out['report']['sections'])) {
            foreach ($out['report']['sections'] as $sid => $sec) {
                $out['report']['sections'][$sid] = array_intersect_key($sec, array_flip(['status', 'title', 'warnings', 'error', 'reason', 'at', 'usage', 'model', 'rewrite_error']));
            }
        }
        $out['id'] = $id;
        $out['derived'] = [
            'available' => $avail,
            'enabled' => IR_Catalog::enabled_sections($avail),
            'dataset' => IR_Dataset::summary($resolved),
            'periods' => IR_Dataset::annual_periods($resolved),
            'cost' => IR_Claude::report_cost($state),
        ];
        $out['job'] = IR_Jobs::public_job(IR_Store::job($id));
        return $out;
    }

    public static function get(WP_REST_Request $req): array
    {
        return self::payload(self::id($req));
    }

    public static function update(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $body = $req->get_json_params() ?: [];
        $state = IR_Store::mutate($id, function ($s) use ($body) {
            if (isset($body['profile']) && is_array($body['profile'])) {
                foreach ($s['profile'] as $k => $v) {
                    if (array_key_exists($k, $body['profile'])) {
                        $s['profile'][$k] = sanitize_textarea_field((string) $body['profile'][$k]);
                    }
                }
            }
            if (isset($body['interview']) && is_array($body['interview'])) {
                $s['interview']['text'] = sanitize_textarea_field((string) ($body['interview']['text'] ?? ''));
                $s['interview']['date'] = sanitize_text_field((string) ($body['interview']['date'] ?? ''));
            }
            if (isset($body['sections_selected']) && is_array($body['sections_selected'])) {
                $valid = array_keys(IR_Catalog::sections());
                $s['sections_selected'] = array_values(array_intersect($valid, array_map('strval', $body['sections_selected'])));
            }
            if (isset($body['params']) && is_array($body['params'])) {
                $p = $body['params'];
                if (isset($p['zscore_model']) && in_array($p['zscore_model'], ['auto', 'Z1', 'Z2'], true)) {
                    $s['params']['zscore_model'] = $p['zscore_model'];
                }
                if (array_key_exists('soci_onerosi', $p)) {
                    $s['params']['soci_onerosi'] = (bool) $p['soci_onerosi'];
                }
                if (array_key_exists('riparto_zes', $p)) {
                    $s['params']['riparto_zes'] = ($p['riparto_zes'] === null || $p['riparto_zes'] === '') ? null : max(0, min(100, (float) $p['riparto_zes']));
                }
                $s['kpi'] = null;
            }
            if (isset($body['step'])) {
                $s['step'] = max(1, min(5, (int) $body['step']));
            }
            return $s;
        });
        return self::payload($id, $state);
    }

    public static function delete(WP_REST_Request $req): array
    {
        IR_Store::delete(self::id($req));
        return ['deleted' => true];
    }

    public static function upload(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $slot = sanitize_text_field((string) $req->get_param('slot'));
        $docs = IR_Catalog::documents();
        if (!isset($docs[$slot]) || !empty($docs[$slot]['virtual'])) {
            throw new RuntimeException('Categoria di documento non valida.');
        }
        $files = $req->get_file_params();
        $f = $files['file'] ?? null;
        if (!$f || !empty($f['error']) || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Caricamento non riuscito.');
        }
        $name = sanitize_file_name($f['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTS, true)) {
            throw new RuntimeException('Formato non supportato (.' . $ext . '). Formati ammessi: ' . implode(', ', self::EXTS) . '. I file .xls vanno salvati come .xlsx.');
        }
        $max = max(1, (int) get_option('ir_max_mb', 20)) * 1024 * 1024;
        if ((int) $f['size'] > $max) {
            throw new RuntimeException('File troppo grande (limite ' . (int) get_option('ir_max_mb', 20) . ' MB).');
        }
        if ($ext === 'pdf') {
            $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw new RuntimeException('Il file non sembra un PDF valido.');
            }
        }
        $fid = strtolower(wp_generate_password(12, false));
        $stored = $fid . '-' . wp_generate_password(16, false) . '.' . $ext;
        $dest = IR_Store::report_dir($id) . '/' . $stored;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new RuntimeException('Impossibile salvare il file sul server.');
        }
        $state = IR_Store::mutate($id, function ($s) use ($fid, $slot, $name, $stored, $f, $ext) {
            $s['files'][] = [
                'id' => $fid, 'slot' => $slot, 'name' => $name, 'stored' => $stored, 'size' => (int) $f['size'], 'ext' => $ext,
                'uploaded_at' => time(), 'uploaded_by' => self::user(), 'extraction' => ['status' => 'pending'],
            ];
            IR_Store::log($s, "Caricato {$slot}: {$name}");
            return $s;
        });
        return self::payload($id, $state);
    }

    public static function delete_file(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $fid = (string) $req['fid'];
        $state = IR_Store::mutate($id, function ($s) use ($fid, $id) {
            foreach ($s['files'] as $i => $f) {
                if ($f['id'] === $fid) {
                    @unlink(IR_Store::file_path($id, $f));
                    unset($s['files'][$i]);
                    IR_Store::log($s, "Rimosso {$f['slot']}: {$f['name']}");
                }
            }
            $s['files'] = array_values($s['files']);
            foreach (['candidates', 'info', 'warnings'] as $k) {
                $s['extraction'][$k] = array_values(array_filter($s['extraction'][$k], fn($c) => $c['file_id'] !== $fid));
            }
            unset($s['extraction']['docs'][$fid]);
            $s['kpi'] = null;
            return $s;
        });
        return self::payload($id, $state);
    }

    public static function serve_file(WP_REST_Request $req)
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        $f = IR_Store::find_file($state, (string) $req['fid']);
        if (!$f) {
            throw new RuntimeException('File non trovato.');
        }
        $path = IR_Store::file_path($id, $f);
        $mime = wp_check_filetype($f['name'])['type'] ?: 'application/octet-stream';
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode($f['name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public static function extract(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        $only = $req->get_param('files');
        $items = [];
        foreach ($state['files'] as $f) {
            if (is_array($only) && $only) {
                if (in_array($f['id'], $only, true)) {
                    $items[] = $f['id'];
                }
            } elseif (($f['extraction']['status'] ?? 'pending') !== 'done') {
                $items[] = $f['id'];
            }
        }
        if (!$items) {
            throw new RuntimeException('Nessun documento da elaborare.');
        }
        // la rilettura di file scelti espressamente non usa la memoria delle risposte
        return ['job' => IR_Jobs::start($id, 'extract', $items, is_array($only) && $only)];
    }

    public static function write(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        if (empty($state['kpi']['approved_at'])) {
            throw new RuntimeException('Il cruscotto KPI deve essere approvato prima della stesura.');
        }
        if (!$state['sections_selected']) {
            throw new RuntimeException('Nessuna sezione selezionata.');
        }
        $only = $req->get_param('sections');
        $order = array_values(array_filter(array_keys(IR_Catalog::sections()), fn($s) => in_array($s, $state['sections_selected'], true)));
        // i capitoli che allegano la visura vanno scritti di seguito: la cache dell'API dura pochi minuti
        $first = array_values(array_filter($order, static fn($s) => in_array('A1', IR_Catalog::sections()[$s]['attach'] ?? [], true)));
        $order = array_merge($first, array_values(array_diff($order, $first)));
        $items = array_merge($order, ['S0']); // il sommario per ultimo
        if (is_array($only) && $only) {
            $items = array_values(array_intersect($items, $only));
        } elseif ($req->get_param('resume')) {
            // ripresa: solo i capitoli in errore o mai scritti; quelli già pronti non si toccano
            $items = array_values(array_intersect($items, array_column(IR_Document::pending($state), 'id')));
            if (!$items) {
                throw new RuntimeException('Tutti i capitoli risultano già generati: non c\'è nulla da completare.');
            }
        } else {
            IR_Store::mutate($id, function ($s) {
                $s['report'] = ['sections' => [], 'started_at' => time()];
                return $s;
            });
        }
        return ['job' => IR_Jobs::start($id, 'write', $items)];
    }

    public static function run_inline(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $job = IR_Store::job($id);
        if (!$job) {
            throw new RuntimeException('Nessun lavoro da eseguire.');
        }
        IR_Jobs::run($id, (string) $job['token'], true);
        return self::payload($id);
    }

    public static function run(WP_REST_Request $req): array
    {
        $id = (int) $req->get_param('report');
        $token = (string) $req->get_param('token');
        if (!$id || !IR_Store::exists($id) || $token === '') {
            throw new RuntimeException('Richiesta non valida.');
        }
        IR_Jobs::run($id, $token);
        return ['ok' => true];
    }

    public static function cancel(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $job = IR_Store::job($id);
        if ($job && in_array($job['status'], ['queued', 'running'], true)) {
            $job['status'] = 'cancelled';
            IR_Store::save_job($id, $job);
            delete_option('ir_job_lock_' . $id);
        }
        return self::payload($id);
    }

    public static function dataset(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        $files = [];
        foreach ($state['files'] as $f) {
            $files[$f['id']] = ['name' => $f['name'], 'slot' => $f['slot'], 'ext' => $f['ext']];
        }
        return [
            'entries' => array_values(IR_Dataset::resolve($state)),
            'info' => $state['extraction']['info'],
            'warnings' => $state['extraction']['warnings'],
            'docs' => $state['extraction']['docs'],
            'files' => $files,
        ];
    }

    public static function override(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $b = $req->get_json_params() ?: [];
        $key = (string) ($b['key'] ?? '');
        $type = (string) ($b['type'] ?? '');
        if (!preg_match('/^[A-Z0-9_]+@\d{4}(-\d{2}(-\d{2})?)?$/', $key) || !isset(IR_Catalog::data_dictionary()[explode('@', $key)[0]])) {
            throw new RuntimeException('Chiave del dato non valida.');
        }
        $note = sanitize_textarea_field((string) ($b['note'] ?? ''));
        $ov = ['type' => $type, 'by' => self::user(), 'at' => time()];
        switch ($type) {
            case 'choice':
                $ov['candidate'] = sanitize_text_field((string) ($b['candidate'] ?? ''));
                $ov['note'] = $note;
                break;
            case 'manual':
                if (!isset($b['value']) || !is_numeric($b['value'])) {
                    throw new RuntimeException('Valore numerico non valido.');
                }
                if (mb_strlen(trim($note)) < 5) {
                    throw new RuntimeException('Per un inserimento o una correzione manuale è obbligatoria una nota che indichi la fonte o il motivo.');
                }
                $ov['value'] = (float) $b['value'];
                $ov['note'] = $note;
                break;
            case 'confirm':
            case 'exclude':
                $ov['note'] = $note;
                break;
            case 'reset':
                break;
            default:
                throw new RuntimeException('Tipo di intervento non valido.');
        }
        $state = IR_Store::mutate($id, function ($s) use ($key, $ov, $type) {
            if ($type === 'reset') {
                unset($s['overrides'][$key]);
            } else {
                $s['overrides'][$key] = $ov;
            }
            $s['kpi'] = null;
            if (!empty($s['report']['generated_at'])) {
                $s['report']['stale'] = true;
            }
            IR_Store::log($s, "Intervento \"{$type}\" sul dato {$key}" . (!empty($ov['note']) ? ': ' . $ov['note'] : ''));
            return $s;
        });
        return self::payload($id, $state);
    }

    public static function compute(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        $resolved = IR_Dataset::resolve($state);
        $sum = IR_Dataset::summary($resolved);
        if ($sum['conflitti'] > 0) {
            throw new RuntimeException("Ci sono {$sum['conflitti']} dati con fonti discordanti: risolvili nella tabella dei dati prima di calcolare.");
        }
        if (!$resolved) {
            throw new RuntimeException('Nessun dato estratto: esegui prima l\'estrazione.');
        }
        $calc = (new IR_Calculator($state, $resolved))->run();
        $state = IR_Store::mutate($id, function ($s) use ($calc) {
            $s['kpi'] = $calc;
            if (!empty($s['report']['generated_at'])) {
                $s['report']['stale'] = true;
            }
            IR_Store::log($s, 'KPI calcolati (' . count($calc['nodes']) . ' indicatori, ' . count($calc['checks']) . ' controlli).');
            return $s;
        });
        $out = self::payload($id, $state);
        $out['kpi_full'] = ['nodes' => $calc['nodes'], 'checks' => $calc['checks'], 'periods' => $calc['periods'], 'zmodel' => $calc['zmodel']];
        return $out;
    }

    public static function approve(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $state = IR_Store::mutate($id, function ($s) {
            if (!$s['kpi']) {
                throw new RuntimeException('Calcola i KPI prima di approvarli.');
            }
            $s['kpi']['approved_at'] = time();
            $s['kpi']['approved_by'] = self::user();
            $s['step'] = 5;
            IR_Store::log($s, 'Cruscotto KPI approvato.');
            return $s;
        });
        return self::payload($id, $state);
    }

    /** Modello del documento + nodi e dati per il pannello di tracciabilità. */
    public static function document(WP_REST_Request $req): array
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        $files = [];
        foreach ($state['files'] as $f) {
            $files[$f['id']] = ['name' => $f['name'], 'slot' => $f['slot'], 'ext' => $f['ext'], 'slot_name' => IR_Catalog::documents()[$f['slot']]['name'] ?? ''];
        }
        return [
            'model' => $state['report'] ? IR_Document::build($state) : null,
            'stale' => !empty($state['report']['stale']),
            'nodes' => $state['kpi']['nodes'] ?? [],
            'checks' => $state['kpi']['checks'] ?? [],
            'periods' => $state['kpi']['periods'] ?? [],
            'data' => $state['kpi']['data'] ?? [],
            'info' => $state['extraction']['info'],
            'files' => $files,
            'approved' => ['by' => $state['kpi']['approved_by'] ?? null, 'at' => $state['kpi']['approved_at'] ?? null],
        ];
    }

    public static function docx(WP_REST_Request $req)
    {
        $id = self::id($req);
        $state = IR_Store::load($id);
        if (empty($state['report']['sections'])) {
            throw new RuntimeException('Report non ancora generato.');
        }
        $b = new IR_Docx($state);
        $path = $b->build();
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $b->filename() . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }
}
