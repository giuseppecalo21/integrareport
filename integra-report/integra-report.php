<?php
/**
 * Plugin Name:       Integra Report — Consulenza PMI Sicilia
 * Description:       Interfaccia guidata per generare Report di Consulenza economico-finanziaria a partire dai documenti aziendali, con estrazione dati tramite API Anthropic, calcolo deterministico dei KPI e tracciabilità completa di ogni valore fino alla fonte.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Integra
 * Text Domain:       integra-report
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IR_VERSION', '1.1.0');
define('IR_FILE', __FILE__);
define('IR_DIR', plugin_dir_path(__FILE__));
define('IR_URL', plugin_dir_url(__FILE__));
define('IR_CAP', 'use_integra_report');

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p><strong>Integra Report</strong> richiede PHP 8.1 o superiore. Versione attuale: ' . esc_html(PHP_VERSION) . '.</p></div>';
    });
    return;
}

$ir_autoload = IR_DIR . 'vendor/autoload.php';
if (file_exists($ir_autoload)) {
    require_once $ir_autoload;
} else {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p><strong>Integra Report</strong>: dipendenze mancanti (cartella <code>vendor/</code>). Installa il pacchetto zip completo oppure esegui <code>composer install --no-dev</code> nella cartella del plugin.</p></div>';
    });
}

require_once IR_DIR . 'includes/class-ir-catalog.php';
require_once IR_DIR . 'includes/class-ir-params.php';
require_once IR_DIR . 'includes/class-ir-format.php';
require_once IR_DIR . 'includes/class-ir-store.php';
require_once IR_DIR . 'includes/class-ir-text-converter.php';
require_once IR_DIR . 'includes/class-ir-claude.php';
require_once IR_DIR . 'includes/class-ir-extractor.php';
require_once IR_DIR . 'includes/class-ir-dataset.php';
require_once IR_DIR . 'includes/class-ir-calculator.php';
require_once IR_DIR . 'includes/class-ir-tables.php';
require_once IR_DIR . 'includes/class-ir-writer.php';
require_once IR_DIR . 'includes/class-ir-document.php';
require_once IR_DIR . 'includes/class-ir-docx.php';
require_once IR_DIR . 'includes/class-ir-jobs.php';
require_once IR_DIR . 'includes/class-ir-rest.php';
require_once IR_DIR . 'includes/class-ir-admin.php';

register_activation_hook(__FILE__, ['IR_Store', 'activate']);

add_action('init', ['IR_Store', 'register_post_type']);
add_action('rest_api_init', ['IR_Rest', 'register_routes']);
add_action('admin_menu', ['IR_Admin', 'menu']);
add_action('admin_init', ['IR_Admin', 'register_settings']);
add_action('admin_enqueue_scripts', ['IR_Admin', 'enqueue']);
