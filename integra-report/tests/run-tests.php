<?php
/**
 * Test del motore di calcolo, senza WordPress e senza chiamate API.
 * Esecuzione: php tests/run-tests.php
 *
 * La fixture è un bilancio fittizio ma coerente (due esercizi che quadrano) più dati di
 * Centrale Rischi, scadenziari, INPS, budget, piano di ammortamento e piano investimenti.
 * I valori attesi sono calcolati a mano.
 */

require __DIR__ . '/bootstrap.php';

$fail = 0;
$ok = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $ok;
    if ($cond) {
        $ok++;
        echo "  ✓ $label\n";
    } else {
        $fail++;
        echo "  ✗ $label" . ($detail ? " — $detail" : '') . "\n";
    }
}
function near(?float $a, float $b, float $eps = 0.005): bool
{
    return $a !== null && abs($a - $b) <= $eps;
}

function base_state(): array
{
    $state = IR_Store::default_state();
    $state['profile'] = array_merge($state['profile'], ['ragione_sociale' => 'Azienda Test S.r.l.', 'forma_giuridica' => 'S.r.l.', 'settore' => 'manifattura', 'dimensione' => 'piccola', 'finalita' => 'B']);
    $state['extraction']['candidates'] = require __DIR__ . '/fixture.php';
    $state['extraction']['info'] = [['id' => 'i1', 'field' => 'ragione_sociale', 'value' => 'AZIENDA TEST S.R.L.', 'file_id' => 'f24', 'slot' => 'A1', 'page' => 1, 'pos' => '', 'quote' => 'AZIENDA TEST S.R.L.']];
    $state['files'] = [['id' => 'f24', 'slot' => 'B1', 'name' => 'bilancio2024.pdf', 'stored' => 'x.pdf', 'ext' => 'pdf', 'size' => 1, 'uploaded_at' => time(), 'extraction' => ['status' => 'done']]];
    $state['sections_selected'] = ['S1', 'S3', 'S4', 'S5', 'S7', 'S9', 'S12', 'S13', 'S14', 'S17', 'S21'];
    return $state;
}

echo "Calcolo su bilancio coerente\n";
$state = base_state();
$res = IR_Dataset::resolve($state);
$calc = (new IR_Calculator($state, $res))->run();
$N = fn($id) => $calc['nodes'][$id]['value'] ?? null;

check('periodi annuali come stringhe', $calc['periods']['annual'] === ['2023', '2024']);
check('tutti i controlli di quadratura superati', count(array_filter($calc['checks'], fn($c) => $c['ok'] !== true)) === 0);
check('EBIT 2024 = 170.000', near($N('EBIT@2024'), 170000));
check('EBITDA 2024 = 210.000', near($N('EBITDA@2024'), 210000));
check('PFN 2024 = 200.000', near($N('PFN@2024'), 200000));
check('ROE 2024 = 40,0% (utile / PN medio)', near($N('ROE@2024'), 40.0));
check('ROI 2024 = 170.000 / 465.000', near($N('ROI@2024'), 170000 / 465000 * 100));
check('PN medio 2023 senza esercizio precedente = valore di fine esercizio, con nota', near($N('PN_MEDIO@2023'), 200000) && str_contains($calc['nodes']['PN_MEDIO@2023']['note'], 'non disponibile'));
check('DSO 2024 = 350.000 / 1.500.000 × 365', near($N('DSO@2024'), 350000 / 1500000 * 365));
check("Z'-Score 2024 ≈ 2,572 (modello manifatturiero)", near($N('ZSCORE@2024'), 2.572, 0.002) && $calc['zmodel'] === 'Z1');
check('DSCR = (230.000 − 50.000 − 20.000) / 118.000', near($N('DSCR@prosp'), 160000 / 118000));
check('Stress −30%: DSCR = (161.000 − 70.000) / 118.000', near($N('STRESS_DSCR@S2'), 91000 / 118000));
check('ZES: credito netto 214.560 € (esempio della metodologia)', near($N('ZES_NETTO@2025'), 214560));
check('CCII: segnale INPS attivo, fornitori nessun segnale', ($calc['nodes']['CCII_INPS@2025-06-30']['eval']['level'] ?? '') === 'bad' && ($calc['nodes']['CCII_FORN@2025-06-30']['eval']['level'] ?? '') === 'good');
check('Rating BBB (mediana A, −1 classe per segnale CCII)', ($calc['nodes']['RATING@2024']['display'] ?? '') === 'BBB');

echo "Errori e casi limite\n";
$s = base_state();
foreach ($s['extraction']['candidates'] as &$c) {
    if ($c['code'] === 'CE_B7' && $c['period'] === '2024') {
        $c['value'] = 310000;
    }
}
unset($c);
$c2 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
check('un valore letto male fa fallire la quadratura dei costi esterni', count(array_filter($c2['checks'], fn($c) => $c['ok'] === false && str_starts_with($c['id'], 'Q_CE_EST'))) === 1);

$s = base_state();
$x = $s['extraction']['candidates'][0];
$x['id'] = 'cX';
$x['value'] += 1000;
$s['extraction']['candidates'][] = $x;
$r = IR_Dataset::resolve($s);
check('fonti discordanti → conflitto', IR_Dataset::summary($r)['conflitti'] === 1);
$c3 = (new IR_Calculator($s, $r))->run();
check('un conflitto rende non valutabili gli indicatori che ne dipendono', $c3['nodes']['AI@2024']['value'] === null && str_contains($c3['nodes']['AI@2024']['missing'][0], 'conflitto'));
$s['overrides'][$x['code'] . '@' . $x['period']] = ['type' => 'choice', 'candidate' => 'cX', 'by' => 't', 'at' => time()];
check('la scelta del consulente risolve il conflitto', IR_Dataset::summary(IR_Dataset::resolve($s))['conflitti'] === 0);

$s = base_state();
$s['extraction']['candidates'] = array_values(array_filter($s['extraction']['candidates'], fn($c) => $c['code'] !== 'SP_D7'));
$c4 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
check('dato mancante → non valutabile con motivo, nessuna stima', $c4['nodes']['DPO@2024']['value'] === null && str_contains($c4['nodes']['DPO@2024']['missing'][0], 'non presente'));

$s = base_state();
$s['overrides']['SP_D7@2024'] = ['type' => 'manual', 'value' => 225000, 'note' => 'NI pag. 12', 'by' => 't', 'at' => time()];
$r = IR_Dataset::resolve($s);
check('correzione manuale applicata e tracciata', $r['SP_D7@2024']['value'] == 225000 && $r['SP_D7@2024']['status'] === 'manual');

$s = base_state();
foreach ($s['extraction']['candidates'] as &$c) {
    if ($c['code'] === 'SP_CIV' && $c['period'] === '2024') {
        $c['value'] += 300000; // PFN di fine esercizio negativa, PFN media ancora positiva
    }
}
unset($c);
$c5 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
check('cassa netta (PFN negativa): effetto leva non applicabile, con motivo', $c5['nodes']['PFN@2024']['value'] < 0 && $c5['nodes']['ROD@2024']['value'] !== null && $c5['nodes']['LEVA@2024']['value'] === null && str_contains((string) $c5['nodes']['LEVA@2024']['nv_reason'], 'cassa netta'));
check('con PFN positiva l\'effetto leva resta calcolato', $N('PFN@2024') > 0 && $N('LEVA@2024') !== null);

$en = IR_Catalog::enabled_sections(['A1', 'B1']);
check('§12 non selezionabile con il solo bilancio: nessuna verifica CCII sarebbe eseguibile', !$en['S12']['enabled'] && $en['S3']['enabled']);
check('§12 disponibile se c\'è almeno un documento che alimenta una verifica', IR_Catalog::enabled_sections(['B1', 'D3'])['S12']['enabled'] && IR_Catalog::enabled_sections(['B1', 'C1'])['S12']['enabled']);

echo "Testo, citazioni e controlli sui numeri\n";
$p = IR_Document::parse("## Titolo\nIl ROE è [[K:ROE@2024]], ricavi in crescita del 11,1% su 1.350.000 €.\n[[TABELLA:indici_reddituali]]\n- voce [[K:INESISTENTE@2024]]", $calc, $state, ['indici_reddituali', 'zscore']);
check('i segnaposto diventano riferimenti tracciati', ($p['blocks'][1]['inline'][1]['t'] ?? '') === 'ref');
check('i numeri scritti senza fonte vengono segnalati', count(array_filter($p['warnings'], fn($w) => str_contains($w, 'senza collegamento'))) === 2);
check('i riferimenti inesistenti vengono segnalati', count(array_filter($p['warnings'], fn($w) => str_contains($w, 'non valido'))) === 1);
check('le tabelle non collocate dal modello vengono aggiunte in coda', end($p['blocks'])['id'] === 'zscore');
check('controllo citazione: numero presente', IR_Extractor::citation_contains_number('1) ricavi delle vendite 1.500.000', 1500000));
check('controllo citazione: numero assente', !IR_Extractor::citation_contains_number('ricavi 1.530.000', 1350000));

$flag = fn(string $t) => count(array_filter(IR_Document::parse($t, $calc, $state, [])['warnings'], fn($w) => str_contains($w, 'senza collegamento')));
check('estremi di norme e atti non sono segnalati come numeri senza fonte', $flag('Atto del notaio, rep. n. 616.973, ai sensi della Legge 8 novembre 1991 n. 1.381 e del provvedimento n. 1.234/2019; iscrizione n. 12.345.') === 0);
check('importi, percentuali e quantità restano segnalati anche vicino a un riferimento normativo', $flag('Ai sensi dell\'art. 2086 il capitale è di L. 7.000.000, i ricavi 1.350.000 €, il margine 11,1% e le azioni sono n. 1.200.') === 4);
check('un importo in euro non è mai scambiato per un estremo normativo', $flag('rep. n. 616.973 euro') === 1);

echo "Anagrafica: una riga per campo, dalla fonte più autorevole\n";
$s = base_state();
$s['files'][] = ['id' => 'fA1', 'slot' => 'A1', 'name' => 'visura.pdf', 'stored' => 'v.pdf', 'ext' => 'pdf', 'size' => 1, 'uploaded_at' => time(), 'extraction' => ['status' => 'done']];
$mk = fn($id, $field, $value, $slot, $file) => ['id' => $id, 'field' => $field, 'value' => $value, 'file_id' => $file, 'slot' => $slot, 'page' => 1, 'pos' => '', 'quote' => $value];
$s['extraction']['info'] = [
    $mk('a2r', 'ragione_sociale', 'CONSOLIDA CONSORZIO S.C.R.L.', 'A2', 'fA2'),
    $mk('a2c', 'capitale_sociale', 'L. 7.000.000', 'A2', 'fA2'),
    $mk('b1r', 'ragione_sociale', 'CONSORZIO CONSOLIDA SOCIETA\' COOPERATIVA SOCIALE', 'B1', 'f24'),
    $mk('b1c', 'capitale_sociale', 'Euro 164.100', 'B1', 'f24'),
    $mk('a4r', 'ragione_sociale', 'Consorzio Consolida', 'A4', 'fA4'),
    $mk('a1r', 'ragione_sociale', 'CONSORZIO CONSOLIDA - SOCIETA\' COOPERATIVA SOCIALE', 'A1', 'fA1'),
    $mk('a1s', 'sede_legale', 'Lecco (LC), Via Belvedere 19', 'A1', 'fA1'),
    $mk('b1s', 'sede_legale', 'Via Belvedere 19, 23900 Lecco (LC)', 'B1', 'f24'),
    $mk('a2o', 'oggetto_sociale', 'non anagrafico', 'A2', 'fA2'),
];
$t = (new IR_Tables($s, $calc))->build('anagrafica');
$labels = array_column($t['rows'], 'label');
$byLabel = array_combine($labels, array_map(fn($r) => $r['cells'][0]['ref'], $t['rows']));
check('nessuna riga ripetuta', count($labels) === count(array_unique($labels)) && count($labels) === 3);
check('la visura prevale su bilancio, organigramma e atto costitutivo', $byLabel['Ragione sociale / denominazione'] === 'I:a1r' && $byLabel['Sede legale'] === 'I:a1s');
check('in assenza della visura vale il bilancio, non l\'atto costitutivo', $byLabel['Capitale sociale (deliberato/sottoscritto/versato)'] === 'I:b1c');

echo "Esportazione Word\n";
$state['kpi'] = $calc;
$tb = new IR_Tables($state, $calc);
$secs = [];
foreach (array_merge(['S0'], $state['sections_selected']) as $sid) {
    $tables = $sid === 'S0' ? [] : $tb->for_section($sid);
    $pp = IR_Document::parse("## Commento\nValore [[K:EBITDA@2024]] e dato [[V:CE_A1@2024]].⟦C:0⟧", $calc, $state, array_keys($tables));
    $secs[$sid] = ['status' => 'ok', 'title' => $sid, 'blocks' => $pp['blocks'], 'warnings' => [], 'tables' => $tables, 'citations' => [['src' => ['kind' => 'file', 'file_id' => 'f24'], 'title' => '', 'cited_text' => 'x', 'page_start' => 3, 'page_end' => 3]]];
}
$state['report'] = ['sections' => $secs, 'generated_at' => time()];
$path = (new IR_Docx($state))->build();
$zip = new ZipArchive();
$zip->open($path);
$wellFormed = true;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    $dom = new DOMDocument();
    if (!@$dom->loadXML($zip->getFromIndex($i))) {
        $wellFormed = false;
    }
}
$xml = $zip->getFromName('word/document.xml');
$zip->close();
@unlink($path);
preg_match_all('/w:anchor="([^"]+)"/', $xml, $a);
preg_match_all('/w:name="([^"]+)"/', $xml, $b);
check('tutte le parti XML del .docx sono ben formate', $wellFormed);
check('ogni collegamento interno ha la sua voce di tracciabilità', !array_diff(array_unique($a[1]), $b[1]) && count($a[1]) > 50);

echo "Costi: modello per documento, memoria delle estrazioni, stima di spesa\n";
check('documenti semplici (visura, statuto, organigramma) → modello economico', IR_Catalog::is_simple_slot('A1') && IR_Catalog::is_simple_slot('A2') && IR_Catalog::is_simple_slot('A4') && IR_Claude::model_for_slot('A2') === 'claude-sonnet-5-5');
check('documenti numerici (bilancio, Centrale Rischi, scadenziari) → modello di estrazione', !IR_Catalog::is_simple_slot('B1') && !IR_Catalog::is_simple_slot('C1') && !IR_Catalog::is_simple_slot('D5') && IR_Claude::model_for_slot('B1') === 'claude-opus-5-5');
$GLOBALS['opts']['ir_model_extract_simple'] = 'inherit';
check('"come l\'estrazione" disattiva la distinzione', IR_Claude::model_for_slot('A2') === IR_Claude::model('extract'));
unset($GLOBALS['opts']['ir_model_extract_simple']);

$content = [['type' => 'document', 'source' => ['data' => base64_encode('PDF-A')]], ['type' => 'text', 'text' => 'istruzioni']];
$k1 = IR_Claude::cache_key('claude-opus-5-5', 'high', 'sys', $content, ['a' => 1]);
$content2 = $content;
$content2[0]['source']['data'] = base64_encode('PDF-B');
check('impronta: cambia con il file, con il modello e con le istruzioni', $k1 !== IR_Claude::cache_key('claude-opus-5-5', 'high', 'sys', $content2, ['a' => 1]) && $k1 !== IR_Claude::cache_key('claude-sonnet-5-5', 'high', 'sys', $content, ['a' => 1]) && $k1 !== IR_Claude::cache_key('claude-opus-5-5', 'high', 'sys2', $content, ['a' => 1]) && $k1 === IR_Claude::cache_key('claude-opus-5-5', 'high', 'sys', $content, ['a' => 1]));
$kt = 'test' . bin2hex(random_bytes(8));
check('memoria: risposta assente prima del salvataggio', IR_Claude::cache_get($kt) === null);
IR_Claude::cache_put($kt, ['text' => '{}', 'blocks' => [], 'usage' => ['input' => 10, 'output' => 5, 'cache_read' => 0, 'cache_write' => 0], 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn']);
check('memoria: risposta riletta identica', (IR_Claude::cache_get($kt)['usage']['input'] ?? null) === 10);
$GLOBALS['opts']['ir_cache_days'] = 0;
check('memoria disattivabile dalle impostazioni', IR_Claude::cache_get($kt) === null);
unset($GLOBALS['opts']['ir_cache_days']);
@unlink(IR_Store::base_dir() . '/cache/' . $kt . '.json');

check('costo: 1M ingresso + 1M uscita su Opus = 24 $', near(IR_Claude::cost(['input' => 1000000, 'output' => 1000000], 'claude-opus-5-5'), 24.0));
check('costo: lettura e scrittura in cache su Sonnet', near(IR_Claude::cost(['cache_read' => 1000000, 'cache_write' => 1000000], 'claude-sonnet-5-5'), 0.10 + 2.5));
check('costo: modello senza prezzo noto → non stimato', IR_Claude::cost(['input' => 1], 'modello-ignoto') === null);
$sp = base_state();
IR_Claude::log_spend($sp, 'extract', 'claude-opus-5-5', ['input' => 500000, 'output' => 50000]);
IR_Claude::log_spend($sp, 'extract', 'claude-opus-5-5', ['input' => 500000, 'output' => 50000]);
IR_Claude::log_spend($sp, 'write', 'claude-sonnet-5-5', ['input' => 100000, 'output' => 100000]);
IR_Claude::log_spend($sp, 'extract', 'claude-opus-5-5', ['input' => 250000, 'output' => 0], true);
IR_Claude::log_spend($sp, 'write', 'modello-ignoto', ['input' => 1, 'output' => 1]);
$rc = IR_Claude::report_cost($sp);
check('registro di spesa: somma cumulativa per fase, rielaborazioni comprese', near($rc['extract'], 6.0) && near($rc['write'], 1.2) && near($rc['usd'], 7.2) && $rc['calls'] === 4);
check('registro di spesa: risparmio della memoria e modelli senza prezzo tenuti distinti', near($rc['saved_usd'], 1.0) && $rc['saved_calls'] === 1 && $rc['unknown'] === ['modello-ignoto']);

echo "Documento: checklist, appendici, capitoli non generati\n";
$model = IR_Document::build($state);
$ap = $model['appendices'];
check('il modello condiviso contiene le tre appendici', count($ap['calc']['entries']) > 10 && count($ap['data']['entries']) > 10 && count($ap['cites']['entries']) === count($model['sections']));
check('le citazioni hanno numerazione unica e progressiva', array_column($ap['cites']['entries'], 'n') === range(1, count($model['sections'])) && $model['sections'][2]['cite_base'] === 2);
check('ogni riferimento del testo ha la sua voce in appendice', !array_diff(IR_Document::used_refs($model), array_merge(array_column($ap['calc']['entries'], 'ref'), array_column($ap['data']['entries'], 'ref'))));
check('report completo: nessun capitolo in sospeso', $model['pending'] === []);

$bad = $state;
$bad['report']['sections']['S4'] = ['status' => 'error', 'error' => 'Servizio sovraccarico (529)', 'at' => time()];
unset($bad['report']['sections']['S5'], $bad['report']['sections']['S0']);
$bad['report']['sections']['S12'] = ['status' => 'skipped', 'reason' => 'nessun indicatore calcolabile', 'at' => time()];
$mb = IR_Document::build($bad);
$txt = json_encode($mb['checklist'], JSON_UNESCAPED_UNICODE);
check('capitolo in errore: escluso dal testo ma riportato nella checklist con il motivo', !in_array('S4', array_column($mb['sections'], 'id'), true) && str_contains($txt, 'Errore di generazione: Servizio sovraccarico (529)'));
check('capitolo mai scritto (lavoro interrotto) e sommario mancante: riportati nella checklist', str_contains($txt, '§5 Analisi per Indici — Stesura non eseguita') && str_contains($txt, 'Sommario esecutivo — Stesura non eseguita'));
check('i capitoli da completare sono esattamente quelli in errore o mancanti', array_column($mb['pending'], 'id') === ['S0', 'S4', 'S5']);
check('un capitolo escluso per mancanza di dati non è un errore', !in_array('S12', array_column($mb['pending'], 'id'), true) && str_contains($txt, 'sezione esclusa per non presentare'));
file_put_contents(sys_get_temp_dir() . '/ir-test-model.json', json_encode(['ok' => $model, 'bad' => $mb], JSON_UNESCAPED_UNICODE));

echo "\n$ok superati, $fail falliti\n";
exit($fail ? 1 : 0);
