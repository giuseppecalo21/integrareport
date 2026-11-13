<?php
/**
 * Pagine di amministrazione: applicazione (interfaccia guidata) e impostazioni.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Admin
{
    public static function menu(): void
    {
        add_menu_page('Integra Report', 'Integra Report', IR_CAP, 'integra-report', [self::class, 'render_app'], 'dashicons-chart-area', 26);
        add_submenu_page('integra-report', 'Report', 'Report', IR_CAP, 'integra-report', [self::class, 'render_app']);
        add_submenu_page('integra-report', 'Impostazioni', 'Impostazioni', 'manage_options', 'integra-report-settings', [self::class, 'render_settings']);
    }

    public static function register_settings(): void
    {
        $text = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'];
        register_setting('ir_settings', 'ir_api_key', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitize_key']]);
        register_setting('ir_settings', 'ir_model', ['type' => 'string', 'sanitize_callback' => fn($v) => isset(IR_Claude::MODELS[$v]) ? $v : 'claude-opus-5-5', 'default' => 'claude-opus-5-5']);
        foreach (['extract', 'write'] as $purpose) {
            register_setting('ir_settings', 'ir_model_' . $purpose, ['type' => 'string', 'sanitize_callback' => fn($v) => isset(IR_Claude::MODELS[$v]) ? $v : '', 'default' => '']);
        }
        register_setting('ir_settings', 'ir_model_extract_simple', ['type' => 'string', 'sanitize_callback' => fn($v) => isset(IR_Claude::MODELS[$v]) ? $v : 'inherit', 'default' => 'claude-sonnet-5-5']);
        register_setting('ir_settings', 'ir_model_write_analytic', ['type' => 'string', 'sanitize_callback' => fn($v) => isset(IR_Claude::MODELS[$v]) ? $v : 'inherit', 'default' => 'claude-sonnet-5-5']);
        register_setting('ir_settings', 'ir_cache_days', ['type' => 'integer', 'sanitize_callback' => fn($v) => max(0, min(365, (int) $v)), 'default' => 30]);
        register_setting('ir_settings', 'ir_effort_extract', ['type' => 'string', 'sanitize_callback' => fn($v) => isset(IR_Claude::EFFORTS[$v]) ? $v : 'high', 'default' => 'high']);
        register_setting('ir_settings', 'ir_effort_write', ['type' => 'string', 'sanitize_callback' => fn($v) => isset(IR_Claude::EFFORTS[$v]) ? $v : 'high', 'default' => 'high']);
        register_setting('ir_settings', 'ir_studio', $text);
        register_setting('ir_settings', 'ir_sito', $text);
        register_setting('ir_settings', 'ir_consulente', ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('ir_settings', 'ir_max_mb', ['type' => 'integer', 'sanitize_callback' => fn($v) => max(1, min(30, (int) $v)), 'default' => 20]);
        register_setting('ir_settings', 'ir_params_override', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitize_params']]);
    }

    public static function sanitize_key($v): string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return (string) get_option('ir_api_key', ''); // campo lasciato vuoto = mantieni la chiave esistente
        }
        if ($v === '-') {
            return '';
        }
        return $v;
    }

    public static function sanitize_params($v): string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }
        $d = json_decode($v, true);
        if (!is_array($d)) {
            add_settings_error('ir_params_override', 'ir_json', 'Parametri: JSON non valido, modifica non salvata.');
            return (string) get_option('ir_params_override', '');
        }
        return wp_json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_integra-report') {
            return;
        }
        wp_enqueue_style('ir-app', IR_URL . 'assets/css/app.css', [], IR_VERSION);
        wp_enqueue_script('ir-app', IR_URL . 'assets/js/app.js', [], IR_VERSION, true);
        // il file contiene caratteri accentati: forza la codifica anche se il server non la dichiara
        add_filter('script_loader_tag', static function ($tag, $handle) {
            return $handle === 'ir-app' ? str_replace('<script ', '<script charset="utf-8" ', $tag) : $tag;
        }, 10, 2);
        $u = wp_get_current_user();
        wp_localize_script('ir-app', 'IR_BOOT', [
            'rest' => esc_url_raw(rest_url('integra-report/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'catalog' => IR_Catalog::for_js(),
            'exts' => IR_Rest::EXTS,
            'maxMb' => (int) get_option('ir_max_mb', 20),
            'hasKey' => IR_Claude::api_key() !== '',
            'model' => self::model_summary(),
            'user' => $u->display_name,
            'settingsUrl' => current_user_can('manage_options') ? admin_url('admin.php?page=integra-report-settings') : '',
        ]);
    }

    /** Etichetta dei modelli in uso, per l'intestazione dell'applicazione. */
    public static function model_summary(): string
    {
        $short = static fn(string $id) => trim((string) preg_replace('/^Claude\\s+|\\s*\\(.*$/', '', IR_Claude::MODELS[$id] ?? $id));
        $e = IR_Claude::model('extract');
        $w = IR_Claude::model('write');
        $x = IR_Claude::model_for_slot('A2');
        $a = IR_Claude::model_for_section('S3');
        if ($e === $w && $x === $e && $a === $w) {
            return 'Claude ' . $short($e);
        }
        return 'estrazione ' . $short($e) . ($x !== $e ? ' (documenti semplici: ' . $short($x) . ')' : '') . ' · stesura ' . $short($w) . ($a !== $w ? ' (capitoli analitici: ' . $short($a) . ')' : '');
    }

    public static function render_app(): void
    {
        echo '<div class="wrap ir-wrap"><div id="ir-app"><p class="ir-loading">Caricamento…</p></div></div>';
    }

    public static function render_settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $hasConst = defined('INTEGRA_REPORT_ANTHROPIC_KEY') && INTEGRA_REPORT_ANTHROPIC_KEY;
        $key = (string) get_option('ir_api_key', '');
        $defaults = wp_json_encode(IR_Params::defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        <div class="wrap">
            <h1>Integra Report — Impostazioni</h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('ir_settings'); ?>
                <h2>Connessione ad Anthropic</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="ir_api_key">Chiave API</label></th><td>
                        <?php if ($hasConst) : ?>
                            <p><strong>Chiave definita in wp-config.php</strong> (costante <code>INTEGRA_REPORT_ANTHROPIC_KEY</code>): ha la precedenza su questo campo.</p>
                        <?php endif; ?>
                        <input type="password" id="ir_api_key" name="ir_api_key" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $key ? esc_attr('Chiave salvata (…' . substr($key, -4) . ') — lascia vuoto per mantenerla') : 'sk-ant-…'; ?>">
                        <p class="description">Consigliato: definire la chiave in <code>wp-config.php</code> con <code>define('INTEGRA_REPORT_ANTHROPIC_KEY', '…');</code> invece di salvarla nel database. Per cancellare la chiave salvata scrivi un trattino (-).</p>
                    </td></tr>
                    <tr><th scope="row"><label for="ir_model">Modello generale</label></th><td>
                        <select id="ir_model" name="ir_model">
                            <?php foreach (IR_Claude::MODELS as $id => $label) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected(IR_Claude::model(), $id); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th scope="row">Modello per fase</th><td>
                        <?php foreach (['extract' => 'Estrazione dati', 'write' => 'Stesura'] as $purpose => $plabel) : $cur = (string) get_option('ir_model_' . $purpose, ''); ?>
                            <label><?php echo esc_html($plabel); ?> <select name="ir_model_<?php echo esc_attr($purpose); ?>">
                                <option value="" <?php selected($cur, ''); ?>>Come il modello generale</option>
                                <?php foreach (IR_Claude::MODELS as $id => $label) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($cur, $id); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select></label> &nbsp;
                        <?php endforeach; ?>
                        <p class="description">Le due fasi possono usare modelli diversi per contenere i costi. L'estrazione legge ogni documento per intero ed è la fase in cui un errore di lettura pesa di più: se scegli un modello più economico, controlla con più attenzione i dati a bassa affidabilità e i controlli di quadratura del passo 4. Ogni dato e ogni capitolo riportano il modello che li ha prodotti.</p>
                    </td></tr>
                    <tr><th scope="row"><label for="ir_model_extract_simple">Documenti semplici</label></th><td>
                        <?php $simple = (string) get_option('ir_model_extract_simple', 'claude-sonnet-5-5'); ?>
                        <select id="ir_model_extract_simple" name="ir_model_extract_simple">
                            <option value="inherit" <?php selected(isset(IR_Claude::MODELS[$simple]), false); ?>>Come l'estrazione dati</option>
                            <?php foreach (IR_Claude::MODELS as $id => $label) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($simple, $id); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Modello per i documenti da cui si ricavano quasi solo dati anagrafici e descrittivi: <?php echo esc_html(implode(', ', array_keys(array_filter(IR_Catalog::documents(), fn($d, $k) => empty($d['virtual']) && IR_Catalog::is_simple_slot($k), ARRAY_FILTER_USE_BOTH)))); ?>. Bilanci, Centrale Rischi, scadenziari e gli altri documenti numerici usano sempre il modello di estrazione.</p>
                    </td></tr>
                    <tr><th scope="row"><label for="ir_model_write_analytic">Capitoli analitici</label></th><td>
                        <?php $an = (string) get_option('ir_model_write_analytic', 'claude-sonnet-5-5'); ?>
                        <select id="ir_model_write_analytic" name="ir_model_write_analytic">
                            <option value="inherit" <?php selected(isset(IR_Claude::MODELS[$an]), false); ?>>Come la stesura</option>
                            <?php foreach (IR_Claude::MODELS as $id => $label) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($an, $id); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Modello per i capitoli che commentano tabelle già calcolate (riclassificazioni, indici, rendiconto, Z-Score, PFN, DSCR…): il testo non può contenere numeri propri, solo riferimenti verificati dal plugin. Sommario esecutivo, capitoli qualitativi, rating (§21) e raccomandazioni (§22) usano sempre il modello di stesura.</p>
                    </td></tr>
                    <tr><th scope="row"><label for="ir_cache_days">Memoria delle estrazioni (giorni)</label></th><td>
                        <input type="number" min="0" max="365" id="ir_cache_days" name="ir_cache_days" value="<?php echo esc_attr((string) IR_Claude::cache_days()); ?>">
                        <p class="description">Se lo stesso file viene elaborato di nuovo con le stesse istruzioni (anche in un altro report), la risposta già ottenuta viene riusata senza alcun costo. "Rielabora" su un singolo file forza sempre una nuova lettura. La memoria conserva i dati estratti, nella cartella protetta dei documenti, per il numero di giorni indicato; 0 la disattiva.</p>
                    </td></tr>
                    <tr><th scope="row">Accuratezza (effort)</th><td>
                        <label>Estrazione dati <select name="ir_effort_extract"><?php foreach (IR_Claude::EFFORTS as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected(IR_Claude::effort('extract'), $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
                        &nbsp; <label>Stesura <select name="ir_effort_write"><?php foreach (IR_Claude::EFFORTS as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected(IR_Claude::effort('write'), $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
                        <p class="description">Livelli più alti aumentano accuratezza, tempi e costi. Per l'estrazione dei dati si consiglia almeno "Alta".</p>
                    </td></tr>
                    <tr><th scope="row"><label for="ir_max_mb">Dimensione massima file (MB)</label></th><td><input type="number" min="1" max="30" id="ir_max_mb" name="ir_max_mb" value="<?php echo esc_attr((string) get_option('ir_max_mb', 20)); ?>"> <p class="description">Il limite dell'API è di 32 MB per richiesta: non superare 20 MB per i PDF. Verifica anche <code>upload_max_filesize</code> di PHP.</p></td></tr>
                </table>
                <h2>Dati dello studio (copertina, piè di pagina, firma)</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="ir_studio">Nome dello studio</label></th><td><input type="text" id="ir_studio" name="ir_studio" class="regular-text" value="<?php echo esc_attr((string) get_option('ir_studio', 'Integra')); ?>"></td></tr>
                    <tr><th scope="row"><label for="ir_sito">Sito web</label></th><td><input type="text" id="ir_sito" name="ir_sito" class="regular-text" value="<?php echo esc_attr((string) get_option('ir_sito', '')); ?>"></td></tr>
                    <tr><th scope="row"><label for="ir_consulente">Firma / qualifica del consulente</label></th><td><textarea id="ir_consulente" name="ir_consulente" rows="3" class="large-text"><?php echo esc_textarea((string) get_option('ir_consulente', '')); ?></textarea></td></tr>
                </table>
                <h2>Parametri normativi, soglie e benchmark</h2>
                <p>Il motore di calcolo usa i parametri di default riportati sotto (fonte: skill di riferimento, normativa aggiornata a maggio 2026). Per aggiornarli inserisci qui <strong>solo le chiavi da modificare</strong> in formato JSON: verranno sovrapposte ai valori di default. Ogni report riporta la versione dei parametri usati. Aggiorna anche il campo <code>versione</code> con la data della verifica.</p>
                <textarea name="ir_params_override" rows="10" class="large-text code" placeholder='{"versione": "Parametri verificati il 15/01/2027", "zes": {"riparto_default": 0.55}}'><?php echo esc_textarea((string) get_option('ir_params_override', '')); ?></textarea>
                <details style="margin-top:8px"><summary>Mostra i parametri di default</summary><pre style="max-height:400px;overflow:auto;background:#fff;padding:12px;border:1px solid #ccd0d4"><?php echo esc_html($defaults); ?></pre></details>
                <?php submit_button('Salva impostazioni'); ?>
            </form>
        </div>
        <?php
    }
}
