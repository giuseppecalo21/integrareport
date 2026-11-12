<?php
/**
 * Disinstallazione: rimuove impostazioni e capability.
 * I report e i documenti caricati NON vengono cancellati automaticamente, per evitare perdite
 * accidentali di dati dei clienti: eliminarli dall'interfaccia prima di disinstallare, se necessario.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
foreach (['ir_api_key', 'ir_model', 'ir_effort_extract', 'ir_effort_write', 'ir_studio', 'ir_sito', 'ir_consulente', 'ir_max_mb', 'ir_params_override'] as $o) {
    delete_option($o);
}
foreach (['administrator', 'editor'] as $r) {
    $role = get_role($r);
    if ($role) {
        $role->remove_cap('use_integra_report');
    }
}
