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

echo "Scadenze di crediti e debiti (caso imposte anticipate e comparativi)\n";
// Le quote entro/oltre lette dalla Nota Integrativa non comprendono le imposte anticipate (30.000):
// la quota entro va calcolata per differenza dal totale di Stato Patrimoniale.
$s = base_state();
foreach ($s['extraction']['candidates'] as &$c) {
    if ($c['code'] === 'SP_CII_ENTRO' && $c['period'] === '2024') {
        $c['value'] = 350000;
    }
}
unset($c);
$c5 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
$N5 = fn($id) => $c5['nodes'][$id]['value'] ?? null;
check('crediti entro = C.II totale − crediti oltre (include le voci non ripartite)', near($N5('CRED_ENTRO@2024'), 380000));
check('attivo riclassificato = totale attivo anche con tabella di Nota Integrativa incompleta', count(array_filter($c5['checks'], fn($c) => $c['ok'] !== true)) === 0);
check('autonomia finanziaria + indebitamento = 100%', near($N5('AUTONOMIA@2024') + $N5('INDEBITAMENTO@2024'), 100.0, 0.0001));

// Esercizio presente solo come colonna comparativa, con le righe "oltre" delle singole voci del prospetto.
$s = base_state();
$y22 = ['SP_BI'=>25000,'SP_BII'=>280000,'SP_BIII'=>10000,'SP_CI'=>140000,'SP_CII_TOT'=>360000,'SP_CII_OLTRE_5QUATER'=>15000,'SP_CII_OLTRE_1'=>0,'SP_CII_CLIENTI'=>320000,'SP_CIV'=>60000,'SP_D_ATT'=>5000,'SP_TOT_ATT'=>880000,
    'SP_PN'=>200000,'SP_B_FONDI'=>15000,'SP_C_TFR'=>55000,'SP_D_TOT'=>600000,'SP_D4_OLTRE'=>170000,'SP_D5_OLTRE'=>10000,'SP_D7_OLTRE'=>0,'SP_E_PASS'=>10000,'SP_TOT_PASS'=>880000,'SP_A'=>0,'SP_CIII'=>0];
foreach ($y22 as $k => $v) {
    $x = cand($k, 2022, $v, 'f23', 3);
    $x['origine'] = 'prospetto';
    $s['extraction']['candidates'][] = $x;
}
$c6 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
$q22 = array_values(array_filter($c6['checks'], fn($c) => in_array($c['id'], ['Q_ATT@2022', 'Q_PASS@2022'], true)));
check('esercizio comparativo: stato patrimoniale riclassificato calcolabile dalle righe "oltre" del prospetto', near($c6['nodes']['AC@2022']['value'], 550000) && near($c6['nodes']['PCNS@2022']['value'], 250000));
check('esercizio comparativo: quadrature di attivo e fonti superate', count($q22) === 2 && $q22[0]['ok'] === true && $q22[1]['ok'] === true);

// Quadratura fallita: il controllo indica la voce che coincide con la differenza.
$s = base_state();
foreach ($s['extraction']['candidates'] as &$c) {
    if ($c['code'] === 'SP_CII_TOT' && $c['period'] === '2024') {
        $c['value'] = 380000; // totale letto senza le imposte anticipate
    }
}
unset($c);
$s['extraction']['candidates'][] = cand('SP_CII_5TER', 2024, 20000, 'f24', 3);
$c7 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
$qa = array_values(array_filter($c7['checks'], fn($c) => $c['id'] === 'Q_ATT@2024'))[0];
check('quadratura fallita → indicata la possibile causa, senza modificare i valori', $qa['ok'] === false && str_contains($qa['note'], 'Imposte anticipate'));

echo "Fonti discordanti: regole contabili automatiche\n";
$s = base_state();
$s['extraction']['candidates'][] = cand('SP_D7', 2023, 235000, 'f24', 3);
$r = IR_Dataset::resolve($s);
check('saldo riesposto: prevale la colonna comparativa del bilancio più recente', $r['SP_D7@2023']['status'] === 'auto' && $r['SP_D7@2023']['value'] == 235000 && $r['SP_D7@2023']['auto']['rule'] === 'riesposto' && $r['SP_D7@2023']['auto']['discarded'][0]['value'] == 230000);
check('il dato riesposto non blocca il calcolo', IR_Dataset::summary($r)['conflitti'] === 0 && IR_Dataset::summary($r)['automatici'] === 1);
$s = base_state();
$a = cand('SP_D12', 2024, 31000, 'f24', 20);
$a['origine'] = 'nota_integrativa';
$s['extraction']['candidates'][] = $a;
foreach ($s['extraction']['candidates'] as &$c) {
    if ($c['code'] === 'SP_D12' && $c['value'] == 30000) {
        $c['origine'] = 'prospetto';
    }
}
unset($c);
$r = IR_Dataset::resolve($s);
check('a parità di bilancio il prospetto prevale sulla Nota Integrativa', $r['SP_D12@2024']['status'] === 'auto' && $r['SP_D12@2024']['value'] == 30000);
$s['overrides']['SP_D12@2024'] = ['type' => 'choice', 'candidate' => $a['id'], 'by' => 't', 'at' => time()];
check('la scelta del consulente prevale sulla regola automatica', IR_Dataset::resolve($s)['SP_D12@2024']['value'] == 31000);

echo "Schema funzionale e debiti verso imprese del gruppo\n";
$s = base_state();
$s['extraction']['candidates'][] = cand('SP_D11', 2024, 100000, 'f24', 3);
$c8 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
$N8 = fn($id) => $c8['nodes'][$id]['value'] ?? null;
check('schema funzionale: CCN commerciale + immobilizzazioni + raccordo = CIN = PN + PFN', near($N8('CCN_COMM@2024') + $N8('IMM_NETTE@2024') + $N8('ALTRE_NETTE@2024'), $N8('CIN@2024')) && near($N8('FONTI_FIN@2024'), $N8('CIN@2024')));
check('DPO verso terzi e DPO complessivo esposti entrambi', near($N8('DPO@2024'), 220000 / 1050000 * 365) && near($N8('DPO_TOT@2024'), 320000 / 1050000 * 365));
check('senza indicazione del consulente il ciclo di cassa usa il DPO verso terzi', near($N8('CCC@2024'), $N8('DSO@2024') + $N8('DIO@2024') - $N8('DPO@2024')));
$s['params']['infragruppo_commerciali'] = true;
$c9 = (new IR_Calculator($s, IR_Dataset::resolve($s)))->run();
check('debiti di gruppo commerciali: inclusi in CCN commerciale e ciclo di cassa', near($c9['nodes']['CCN_COMM@2024']['value'], 150000 + 350000 - 220000 - 100000) && near($c9['nodes']['CCC@2024']['value'], $N8('DSO@2024') + $N8('DIO@2024') - $N8('DPO_TOT@2024')));
$tf = (new IR_Tables($s, $c9))->build('sp_funzionale');
check('tabella dello schema funzionale con riga di raccordo e totale fonti', in_array('Altre attività / (passività) operative nette e fondi', array_column($tf['rows'], 'label'), true) && in_array('Totale fonti di finanziamento', array_column($tf['rows'], 'label'), true));
check('una PFN negativa (cassa netta) è sempre stampata con il segno meno', IR_Format::value(-4055625.0, 'eur') === '−4.055.625,00 €');

echo "Informazioni qualitative e pulizia del testo\n";
check('etichetta del campo rimossa dal valore estratto', IR_Extractor::clean_info_value('altro', 'Attività svolta: vendita di gas mediante condutture') === 'vendita di gas mediante condutture');
check('etichetta "Numero medio dipendenti 2024:" rimossa', IR_Extractor::clean_info_value('addetti', 'Numero medio dipendenti 2024: 14 (1 dirigente, 13 impiegati)') === '14 (1 dirigente, 13 impiegati)');
check('un valore con i due punti ma senza etichetta resta invariato', IR_Extractor::clean_info_value('sede_legale', 'Catania (CT): via Etnea 1') === 'Catania (CT): via Etnea 1');
$s = base_state();
$s['extraction']['candidates'][] = cand('SP_PN', 2022, 1, 'f22', 3);
$s['extraction']['info'] = [
    ['id' => 'a22', 'field' => 'addetti', 'value' => '15 dipendenti nel 2022', 'file_id' => 'f22', 'slot' => 'B1', 'page' => 9, 'pos' => '', 'quote' => 'Numero medio dipendenti 15'],
    ['id' => 'a24', 'field' => 'addetti', 'value' => '14 nel 2024, di cui 1 dirigente e 13 impiegati', 'file_id' => 'f24', 'slot' => 'B1', 'page' => 9, 'pos' => '', 'quote' => 'Numero medio 14'],
    ['id' => 'soc', 'field' => 'soci', 'value' => 'Catania Rete Gas S.p.A., socio unico al 100%', 'file_id' => 'f24', 'slot' => 'A1', 'page' => 2, 'pos' => '', 'quote' => 'socio unico'],
];
$ta = (new IR_Tables($s, $calc))->build('anagrafica');
check('tabella anagrafica: per gli addetti solo il dato più recente', count($ta['rows']) === 1 && $ta['rows'][0]['cells'][0]['ref'] === 'I:a24');
$p = IR_Document::parse("## 3.1 Compagine\n*\"La società è partecipata al 100% da un socio unico.\"*[[F:soc]] Il margine è del 12,5%.\n---\n```\nIl capitale è [[I:a24]].", $calc, $s, []);
$flat = json_encode($p['blocks'], JSON_UNESCAPED_UNICODE);
check('residui Markdown (asterischi, separatori, blocchi di codice) eliminati dal testo', !str_contains($flat, '*') && !str_contains($flat, '---') && !str_contains($flat, '`'));
check('il marcatore di fonte non è visibile ma resta tracciato', count(array_filter($p['blocks'][1]['inline'], fn($r) => $r['t'] === 'src' && $r['ref'] === 'I:soc')) === 1 && !str_contains($flat, '[[F:'));
check('un numero ripreso da un fatto documentato non è segnalato; uno senza fonte sì', count($p['warnings']) === 1 && str_contains($p['warnings'][0], '12,5'));

echo "Modello del documento ed esportazione Word\n";
$state['kpi'] = $calc;
$state['kpi']['approved_by'] = 'operatore_wp';
$state['kpi']['approved_at'] = time();
$state['profile']['finalita'] = 'C'; // richiede anche S18 e S22, non presenti
$state['sections_selected'] = ['S1', 'S3', 'S5', 'S8', 'S9', 'S20'];
$state['extraction']['docs']['f24'] = ['file_id' => 'f24', 'tipo' => "bilancio d'esercizio al 31/12/2024 (Stato Patrimoniale, Conto Economico, Rendiconto Finanziario e Nota Integrativa) - scala unità", 'match' => false, 'periodi' => ['2024'], 'scala' => 'unita', 'note' => ''];
$num = IR_Document::numbering($state);
check('numerazione progressiva: il capitolo senza indicatori calcolabili non lascia buchi', $num === ['S1' => 1, 'S3' => 2, 'S5' => 3, 'S9' => 4, 'S20' => 5]);
$tb = new IR_Tables($state, $calc);
$secs = [];
foreach (['S0', 'S1', 'S3', 'S5', 'S9', 'S20'] as $sid) {
    $tables = $sid === 'S0' ? [] : $tb->for_section($sid);
    $n = IR_Catalog::sections()[$sid]['n'] ?? 0;
    $pp = IR_Document::parse("## {$n}.1 Commento\nValore [[K:EBITDA@2024]] e dato [[V:CE_A1@2024]].⟦C:0⟧ Norma citata.⟦C:1⟧ Quota 77,7%.", $calc, $state, array_keys($tables));
    $secs[$sid] = ['status' => 'ok', 'title' => "§$n. vecchio titolo", 'blocks' => $pp['blocks'], 'warnings' => $pp['warnings'], 'tables' => $tables, 'citations' => [
        ['src' => ['kind' => 'file', 'file_id' => 'f24'], 'title' => '', 'cited_text' => 'passo del bilancio', 'page_start' => 3, 'page_end' => 3],
        ['src' => ['kind' => 'norm'], 'title' => 'Riferimento normativo interno (aggiornato a maggio 2026)', 'cited_text' => '### DISCLAIMER OBBLIGATORIO NEL REPORT Quando il report contiene stime', 'page_start' => null, 'page_end' => null],
    ]];
}
$secs['S8'] = ['status' => 'skipped', 'reason' => 'nessun indicatore calcolabile con i documenti forniti', 'at' => time()];
$state['report'] = ['sections' => $secs, 'generated_at' => time()];
$model = IR_Document::build($state);
check('titoli dei capitoli rinumerati in sequenza', array_column($model['sections'], 'title') === ['Sommario esecutivo', '1. Profilo Aziendale e Governance', '2. Riclassificazione di Bilancio (SP Finanziario + Funzionale)', '3. Analisi per Indici', '4. Posizione Finanziaria Netta e Sostenibilità del Debito', '5. Opportunità di Finanza Agevolata Regionale (Sicilia)']);
check('intestazioni dei paragrafi riallineate al nuovo numero', $model['sections'][5]['blocks'][0]['inline'][0]['v'] === '5.1 Commento');
check('finalità in copertina adeguata quando mancano analisi obbligatorie', $model['meta']['perimetro_parziale'] && str_contains($model['meta']['finalita'], 'perimetro parziale') && str_contains(json_encode($model['premessa'], JSON_UNESCAPED_UNICODE), 'Adeguatezza degli Assetti'));

$read = function (string $kind) use ($state): array {
    $path = (new IR_Docx($state, $kind))->build();
    $zip = new ZipArchive();
    $zip->open($path);
    $ok = true;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($zip->getFromIndex($i))) {
            $ok = false;
        }
    }
    $xml = $zip->getFromName('word/document.xml') . $zip->getFromName('word/header1.xml') . $zip->getFromName('docProps/core.xml');
    $zip->close();
    @unlink($path);
    return [$ok, $xml, html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1)];
};
[$okC, $xmlC, $txtC] = $read('cliente');
[$okA, $xmlA, $txtA] = $read('audit');
check('report cliente e fascicolo di audit: XML ben formato', $okC && $okA);
$leaks = array_filter(['bilancio2024.pdf', 'operatore_wp', 'Tester', 'non corrisponde pienamente', 'Riferimento normativo interno', 'DISCLAIMER OBBLIGATORIO', 'Checklist delle operazioni', 'Appendice C', 'formule_kpi', 'skill report-consulenza', 'vecchio titolo', '§'], fn($t) => str_contains($txtC, $t));
check('report cliente: nessun nome di file, utente, avviso di lavorazione o materiale interno', !$leaks, implode(', ', $leaks));
check('report cliente: nessun collegamento interno né marcatore di citazione', !str_contains($xmlC, 'w:anchor=') && !preg_match('/\[\d+\]/', $txtC) && !str_contains($xmlC, 'w:highlight'));
check('report cliente: documenti elencati in modo formale', str_contains($txtC, "Bilancio d'esercizio al 31/12/2024 (Stato Patrimoniale, Conto Economico, Rendiconto Finanziario e Nota Integrativa)") && !str_contains($txtC, 'scala unità'));
check('report cliente: appendice contabile e numerazione senza buchi', str_contains($txtC, 'Appendice — Prospetti contabili riclassificati e indici') && str_contains($txtC, '5. Opportunità di Finanza Agevolata') && !str_contains($txtC, '20.1'));
check('fascicolo di audit: checklist, controlli, tracciabilità e solo citazioni di documenti del cliente', str_contains($txtA, 'Checklist delle operazioni') && str_contains($txtA, 'bilancio2024.pdf') && str_contains($txtA, 'Controlli di quadratura') && str_contains($txtA, 'passo del bilancio') && !str_contains($txtA, 'DISCLAIMER OBBLIGATORIO'));
preg_match_all('/w:anchor="([^"]+)"/', $xmlA, $a);
preg_match_all('/w:name="([^"]+)"/', $xmlA, $b);
check('fascicolo di audit: ogni collegamento interno ha la sua voce di tracciabilità', !array_diff(array_unique($a[1]), $b[1]) && count($a[1]) > 50);
check('quadro normativo di lavoro privo di istruzioni operative e disclaimer', !str_contains(IR_Context::norm_reference(), 'DISCLAIMER') && !str_contains(IR_Context::norm_reference(), 'Quando produci') && str_contains(IR_Context::norm_reference(), 'ZES UNICA'));
$dims = IR_Context::dimension_refs($calc);
check('dati dimensionali (ricavi, attivo, patrimonio netto) disponibili per ogni capitolo', in_array('K:RICAVI@2024', $dims, true) && in_array('V:SP_TOT_ATT@2024', $dims, true) && in_array('K:PN@2024', $dims, true));

echo "Contesto globale e stesura dei capitoli\n";
IR_Claude::$reply = json_encode([
    'qualifica_dimensionale' => ['esito' => 'non_pmi', 'motivazione' => 'Il capitale è interamente detenuto, tramite il socio unico, da un ente pubblico (art. 3, par. 4, Racc. 2003/361/CE).', 'strumenti_preclusi' => ['Garanzia MCC'], 'strumenti_accessibili' => ['ZES Unica'], 'verifiche_necessarie' => []],
    'controllo_pubblico' => ['presente' => 'si', 'descrizione' => 'Socio unico controllato da un Comune.'],
    'rapporti_di_gruppo' => ['rilevanti' => true, 'descrizione' => 'Debiti verso la controllante.', 'avvertenze_sugli_indici' => ['Il DPO verso terzi non comprende i debiti verso la controllante.']],
    'settore' => ['descrizione' => 'Vendita di gas.', 'dinamiche_fisiologiche' => 'Fatturazione su cicli di lettura.', 'benchmark_rappresentativo' => 'no', 'motivazione_benchmark' => 'Il commercio al dettaglio incassa a vista.'],
    'avvertenze_generali' => [],
], JSON_UNESCAPED_UNICODE);
$ctx = IR_Context::generate($state, $calc);
$state['report']['context'] = $ctx;
check('scheda di inquadramento generata con output strutturato', $ctx['status'] === 'ok' && IR_Claude::$calls[0]['schema'] !== null && str_contains(IR_Claude::$calls[0]['content'][0]['text'], 'Ricavi delle vendite'));
IR_Claude::$calls = [];
IR_Claude::$reply = "## 5.1 Profilo di ammissibilità\nTesto.";
$w = IR_Writer::write_section(1, $state, $calc, 'S20');
$sent = IR_Claude::$calls[0]['content'];
$prompt = end($sent)['text'];
check('finanza agevolata: ricavi e totale attivo arrivano al capitolo', str_contains($prompt, '[[K:RICAVI@2024]]') && str_contains($prompt, '[[V:SP_TOT_ATT@2024]]'));
check('finanza agevolata: la qualifica "non PMI" è vincolante per il capitolo', str_contains($prompt, 'NON rientra nella definizione di PMI') && str_contains($prompt, 'Garanzia MCC'));
check('il quadro normativo interno non è più un documento citabile', count(array_filter($sent, fn($b) => $b['type'] === 'document' && str_contains($b['title'] ?? '', 'normativo'))) === 0 && str_contains($prompt, 'ZES UNICA') && !str_contains($prompt, 'DISCLAIMER OBBLIGATORIO'));
check('titolo e scaletta con la numerazione definitiva', $w['title'] === '5. Opportunità di Finanza Agevolata Regionale (Sicilia)' && str_contains($prompt, '- 5.1 Profilo di ammissibilità') && !str_contains($prompt, '20.1'));
check('i fatti sono forniti come appunti da riformulare, con marcatore di fonte', str_contains($prompt, 'fatto da riformulare') || str_contains($prompt, 'identificativo [[I:i1]]'));
$mb = IR_Document::build($state);
$bench = array_values(array_filter($mb['sections'], fn($x) => $x['id'] === 'S5'))[0]['tables']['benchmark'] ?? null;
check('benchmark non rappresentativo: avvertenza sotto la tabella', $bench && str_contains(implode(' ', $bench['notes']), 'non è pienamente rappresentativo'));

echo "\n$ok superati, $fail falliti\n";
exit($fail ? 1 : 0);
