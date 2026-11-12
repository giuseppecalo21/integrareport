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

echo "Testo, citazioni e controlli sui numeri\n";
$p = IR_Document::parse("## Titolo\nIl ROE è [[K:ROE@2024]], ricavi in crescita del 11,1% su 1.350.000 €.\n[[TABELLA:indici_reddituali]]\n- voce [[K:INESISTENTE@2024]]", $calc, $state, ['indici_reddituali', 'zscore']);
check('i segnaposto diventano riferimenti tracciati', ($p['blocks'][1]['inline'][1]['t'] ?? '') === 'ref');
check('i numeri scritti senza fonte vengono segnalati', count(array_filter($p['warnings'], fn($w) => str_contains($w, 'senza collegamento'))) === 2);
check('i riferimenti inesistenti vengono segnalati', count(array_filter($p['warnings'], fn($w) => str_contains($w, 'non valido'))) === 1);
check('le tabelle non collocate dal modello vengono aggiunte in coda', end($p['blocks'])['id'] === 'zscore');
check('controllo citazione: numero presente', IR_Extractor::citation_contains_number('1) ricavi delle vendite 1.500.000', 1500000));
check('controllo citazione: numero assente', !IR_Extractor::citation_contains_number('ricavi 1.530.000', 1350000));

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

echo "\n$ok superati, $fail falliti\n";
exit($fail ? 1 : 0);
