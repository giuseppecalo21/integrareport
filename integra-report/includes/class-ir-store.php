<?php
/**
 * Persistenza: ogni report è un post privato di tipo "ir_report" con lo stato in post meta (JSON).
 * I file caricati sono salvati in wp-content/uploads/integra-report/{id}/ con nomi casuali
 * e accesso diretto bloccato; vengono serviti solo tramite endpoint REST autenticato.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Store
{
    public const PT = 'ir_report';
    private const META_STATE = '_ir_state';
    private const META_JOB = '_ir_job';

    public static function activate(): void
    {
        foreach (['administrator', 'editor'] as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap(IR_CAP);
            }
        }
        self::base_dir();
        self::register_post_type();
    }

    public static function register_post_type(): void
    {
        register_post_type(self::PT, [
            'label' => 'Report di consulenza',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'author'],
            'capability_type' => 'post',
        ]);
    }

    public static function base_dir(): string
    {
        $up = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']) . 'integra-report';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    public static function report_dir(int $id): string
    {
        $dir = self::base_dir() . '/' . $id;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    public static function default_state(): array
    {
        return [
            'version' => 1,
            'step' => 1,
            'profile' => [
                'ragione_sociale' => '', 'forma_giuridica' => 'S.r.l.', 'partita_iva' => '', 'ateco' => '',
                'settore' => 'manifattura', 'sede_legale' => '', 'sede_operativa' => '', 'provincia' => 'Palermo',
                'dimensione' => 'piccola', 'finalita' => 'A', 'finalita_altro' => '', 'esercizi' => '', 'note' => '',
            ],
            'files' => [],
            'interview' => ['text' => '', 'date' => ''],
            'sections_selected' => [],
            'extraction' => ['candidates' => [], 'info' => [], 'warnings' => [], 'docs' => []],
            'overrides' => [],
            'params' => ['zscore_model' => 'auto', 'soci_onerosi' => false, 'infragruppo_commerciali' => false, 'riparto_zes' => null],
            'kpi' => null,
            'report' => null,
            'log' => [],
        ];
    }

    public static function create(string $title): int
    {
        $id = wp_insert_post([
            'post_type' => self::PT,
            'post_status' => 'private',
            'post_title' => $title !== '' ? $title : 'Nuovo report',
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $state = self::default_state();
        $state['profile']['ragione_sociale'] = $title;
        self::save($id, $state);
        return (int) $id;
    }

    public static function exists(int $id): bool
    {
        $p = get_post($id);
        return $p && $p->post_type === self::PT;
    }

    public static function load(int $id): array
    {
        wp_cache_delete($id, 'post_meta');
        $raw = get_post_meta($id, self::META_STATE, true);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = self::default_state();
        }
        return array_replace(self::default_state(), $state);
    }

    public static function save(int $id, array $state): void
    {
        $json = wp_json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        update_post_meta($id, self::META_STATE, wp_slash($json));
        $title = trim((string) ($state['profile']['ragione_sociale'] ?? ''));
        if ($title !== '' && get_the_title($id) !== $title) {
            wp_update_post(['ID' => $id, 'post_title' => $title]);
        }
    }

    /** Aggiorna lo stato con una funzione, rileggendolo subito prima (riduce le sovrascritture concorrenti). */
    public static function mutate(int $id, callable $fn): array
    {
        $state = self::load($id);
        $state = $fn($state);
        self::save($id, $state);
        return $state;
    }

    public static function log(array &$state, string $msg): void
    {
        $u = wp_get_current_user();
        $state['log'][] = ['at' => time(), 'user' => $u && $u->exists() ? $u->display_name : 'sistema', 'msg' => $msg];
        if (count($state['log']) > 300) {
            $state['log'] = array_slice($state['log'], -300);
        }
    }

    public static function job(int $id): ?array
    {
        wp_cache_delete($id, 'post_meta');
        $raw = get_post_meta($id, self::META_JOB, true);
        $job = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($job) ? $job : null;
    }

    public static function save_job(int $id, ?array $job): void
    {
        if ($job === null) {
            delete_post_meta($id, self::META_JOB);
            return;
        }
        update_post_meta($id, self::META_JOB, wp_slash(wp_json_encode($job, JSON_UNESCAPED_UNICODE)));
    }

    public static function list_reports(): array
    {
        $q = new WP_Query([
            'post_type' => self::PT,
            'post_status' => 'private',
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        $out = [];
        foreach ($q->posts as $p) {
            $state = self::load($p->ID);
            $author = get_userdata((int) $p->post_author);
            $out[] = [
                'id' => $p->ID,
                'title' => $p->post_title,
                'modified' => mysql2date('U', $p->post_modified_gmt . ' +0000'),
                'author' => $author ? $author->display_name : '',
                'step' => $state['step'] ?? 1,
                'finalita' => $state['profile']['finalita'] ?? '',
                'generated' => !empty($state['report']['generated_at']),
            ];
        }
        return $out;
    }

    public static function delete(int $id): void
    {
        $dir = self::base_dir() . '/' . $id;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($dir . '/.*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
        wp_delete_post($id, true);
    }

    public static function file_path(int $id, array $file): string
    {
        return self::report_dir($id) . '/' . basename((string) $file['stored']);
    }

    public static function find_file(array $state, string $file_id): ?array
    {
        foreach ($state['files'] as $f) {
            if ($f['id'] === $file_id) {
                return $f;
            }
        }
        return null;
    }

    /** Documenti disponibili = slot con almeno un file + intervista compilata. */
    public static function available_slots(array $state): array
    {
        $slots = [];
        foreach ($state['files'] as $f) {
            $slots[$f['slot']] = true;
        }
        if (trim((string) ($state['interview']['text'] ?? '')) !== '') {
            $slots['INT'] = true;
        }
        return array_keys($slots);
    }
}
