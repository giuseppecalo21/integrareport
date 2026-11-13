<?php
/**
 * Costruzione deterministica delle tabelle del report a partire dai nodi di calcolo.
 * Ogni cella porta il riferimento al nodo (K:...) o al dato (V:...) da cui proviene,
 * così che nel visualizzatore e nel .docx ogni numero sia riconducibile alla fonte.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Tables
{
    private array $calc;
    private array $state;
    private array $nodes;
    private array $data;

    public function __construct(array $state, array $calc)
    {
        $this->state = $state;
        $this->calc = $calc;
        $this->nodes = $calc['nodes'];
        $this->data = $calc['data'];
    }

    public function build(string $id): ?array
    {
        $method = 't_' . $id;
        if (!method_exists($this, $method)) {
            return null;
        }
        $t = $this->$method();
        if (!$t || empty($t['rows'])) {
            return null;
        }
        $t['id'] = $id;
        $t['notes'] = array_values(array_unique($t['notes'] ?? []));
        return $t;
    }

    // ------------------------------------------------------------------ helper

    private function cell_node(?string $nid): array
    {
        if ($nid === null || !isset($this->nodes[$nid])) {
            return ['v' => '—', 'ref' => null, 'level' => null];
        }
        $n = $this->nodes[$nid];
        $v = $n['value'] === null ? 'n.v.' : ($n['display'] ?? IR_Format::value($n['value'], $n['unit']));
        return ['v' => $v, 'ref' => 'K:' . $nid, 'level' => $n['eval']['level'] ?? null];
    }

    private function cell_data(string $key): array
    {
        $e = $this->data[$key] ?? null;
        if (!$e) {
            return ['v' => '—', 'ref' => null, 'level' => null];
        }
        return ['v' => $e['value'] === null ? 'n.v.' : IR_Format::value($e['value'], $e['unit']), 'ref' => 'V:' . $key, 'level' => null];
    }

    private function cell_text(string $t, ?string $level = null): array
    {
        return ['v' => $t, 'ref' => null, 'level' => $level];
    }

    /** Righe "nodo per periodo". $spec = [chiave => [etichetta|null, opzioni]] */
    private function period_rows(array $spec, array $periods, array &$notes): array
    {
        $rows = [];
        foreach ($spec as $key => $opt) {
            $opt = (array) $opt;
            $isData = str_starts_with($key, 'V:');
            $cells = [];
            $any = false;
            $label = $opt[0] ?? null;
            foreach ($periods as $p) {
                if ($isData) {
                    $code = substr($key, 2);
                    $c = $this->cell_data("$code@$p");
                    $label = $label ?? (IR_Catalog::data_dictionary()[$code]['label'] ?? $code);
                } else {
                    $c = $this->cell_node("$key@$p");
                    if (isset($this->nodes["$key@$p"])) {
                        $label = $label ?? $this->nodes["$key@$p"]['label'];
                        $notes[] = $this->nodes["$key@$p"]['label'] . ': ' . $this->nodes["$key@$p"]['formula'];
                    }
                }
                if ($c['ref'] !== null) {
                    $any = true;
                }
                $cells[] = $c;
            }
            if ($any) {
                $rows[] = ['label' => $label ?? $key, 'cells' => $cells, 'total' => !empty($opt['total'])];
            }
        }
        return $rows;
    }

    private function annual(): array
    {
        return $this->calc['periods']['annual'] ?? [];
    }

    private function last(): ?string
    {
        return $this->calc['periods']['last'] ?? null;
    }

    private function periods_for(string $key): array
    {
        $p = [];
        foreach ($this->nodes as $n) {
            if ($n['key'] === $key) {
                $p[] = (string) $n['period'];
            }
        }
        sort($p, SORT_STRING);
        return $p;
    }

    private function std(string $title, array $spec, ?array $periods = null): ?array
    {
        $periods = $periods ?? $this->annual();
        if (!$periods) {
            return null;
        }
        $notes = [];
        $rows = $this->period_rows($spec, $periods, $notes);
        return ['title' => $title, 'columns' => array_merge(['Voce'], array_map([IR_Format::class, 'period_label'], $periods)), 'rows' => $rows, 'notes' => $notes];
    }

    // ------------------------------------------------------------------ tabelle

    /** Campi dell'anagrafica, nell'ordine di esposizione. */
    private const ANAGRAFICA_FIELDS = ['ragione_sociale', 'forma_giuridica', 'codice_fiscale', 'partita_iva', 'rea', 'pec', 'sede_legale', 'sedi_operative', 'data_costituzione', 'stato_attivita', 'ateco', 'capitale_sociale', 'addetti', 'organo_controllo', 'durc'];
    /** Priorità delle fonti per i dati anagrafici: la visura è il documento ufficiale e più aggiornato. */
    private const ANAGRAFICA_SOURCES = ['A1', 'B1', 'A4', 'A2'];

    /**
     * Una riga per campo. Se lo stesso campo è riportato da più documenti (visura, bilancio,
     * organigramma, atto costitutivo) vale la fonte con priorità più alta; a parità di categoria,
     * il file caricato per ultimo. Le altre versioni restano consultabili nel registro dei dati.
     */
    private function t_anagrafica(): ?array
    {
        $fields = IR_Catalog::info_fields();
        $uploaded = [];
        foreach ($this->state['files'] ?? [] as $f) {
            $uploaded[$f['id']] = (int) ($f['uploaded_at'] ?? 0);
        }
        $rank = static function (array $i) use ($uploaded): array {
            $p = array_search($i['slot'] ?? '', self::ANAGRAFICA_SOURCES, true);
            return [$p === false ? count(self::ANAGRAFICA_SOURCES) : $p, -($uploaded[$i['file_id'] ?? ''] ?? 0)];
        };
        $best = [];
        foreach ($this->state['extraction']['info'] ?? [] as $i) {
            if (!in_array($i['field'], self::ANAGRAFICA_FIELDS, true) || trim((string) $i['value']) === '') {
                continue;
            }
            if (!isset($best[$i['field']]) || $rank($i) < $rank($best[$i['field']])) {
                $best[$i['field']] = $i;
            }
        }
        $rows = [];
        foreach (self::ANAGRAFICA_FIELDS as $field) {
            if (isset($best[$field])) {
                $i = $best[$field];
                $rows[] = ['label' => $fields[$field] ?? $field, 'cells' => [['v' => $i['value'], 'ref' => 'I:' . $i['id'], 'level' => null]]];
            }
        }
        return ['title' => 'Anagrafica societaria', 'columns' => ['Elemento', 'Dato (con fonte)'], 'rows' => $rows, 'notes' => ['Dati riportati dai documenti indicati in fonte, senza rielaborazioni. Per i dati presenti in più documenti è esposto quello della fonte più autorevole (visura camerale, quindi bilancio, organigramma e atto costitutivo).']];
    }

    private function t_concentrazione(): ?array
    {
        $rows = [];
        foreach (['CLI_TOP1_PCT', 'CLI_TOP5_PCT', 'CLI_TOP10_PCT', 'FOR_TOP1_PCT', 'FOR_TOP5_PCT', 'FOR_TOP10_PCT'] as $c) {
            foreach ($this->data as $key => $e) {
                if ($e['code'] === $c) {
                    $rows[] = ['label' => $e['label'] . ' (' . IR_Format::period_label($e['period']) . ')', 'cells' => [$this->cell_data($key)]];
                }
            }
        }
        return ['title' => 'Concentrazione di clienti e fornitori', 'columns' => ['Indicatore', 'Valore'], 'rows' => $rows, 'notes' => []];
    }

    private function t_sp_finanziario(): ?array
    {
        return $this->std('Stato Patrimoniale riclassificato — schema finanziario', [
            'LI' => [], 'LD' => [], 'DI' => [], 'AC' => [null, 'total' => true], 'AI' => [], 'AT' => [null, 'total' => true],
            'V:SP_TOT_ATT' => ['Totale attivo da bilancio (controllo)'],
            'PC' => [], 'PCNS' => [], 'PN' => [], 'FONTI' => [null, 'total' => true],
            'V:SP_TOT_PASS' => ['Totale passivo da bilancio (controllo)'],
        ]);
    }

    private function t_sp_funzionale(): ?array
    {
        return $this->std('Stato Patrimoniale riclassificato — schema funzionale (sintesi)', [
            'CCN_COMM' => [], 'IMM_NETTE' => [], 'CIN' => [null, 'total' => true], 'PN' => [], 'PFN' => [],
        ]);
    }

    private function t_controlli(): ?array
    {
        $rows = [];
        foreach ($this->calc['checks'] as $c) {
            if ($c['ok'] === null) {
                $esito = $this->cell_text('Non eseguibile', 'warn');
            } else {
                $esito = $this->cell_text($c['ok'] ? 'Superato' : 'NON superato', $c['ok'] ? 'good' : 'bad');
            }
            $a = $c['a']['ref'] ? ['v' => IR_Format::value($c['a']['value'], 'eur'), 'ref' => $c['a']['ref'], 'level' => null] : $this->cell_text('—');
            $b = $c['b']['ref'] ? ['v' => IR_Format::value($c['b']['value'], 'eur'), 'ref' => $c['b']['ref'], 'level' => null] : $this->cell_text('—');
            $rows[] = ['label' => $c['label'] . ' — ' . IR_Format::period_label($c['period']), 'cells' => [$a, $b, $this->cell_text($c['diff'] === null ? '—' : IR_Format::value($c['diff'], 'eur')), $esito]];
        }
        return ['title' => 'Controlli di quadratura', 'columns' => ['Controllo', 'Valore A', 'Valore B', 'Differenza', 'Esito'], 'rows' => $rows, 'notes' => ['Tolleranza: 2 € (o 2 unità della scala del documento) per gli arrotondamenti all\'unità di euro.']];
    }

    private function t_ce_va(): ?array
    {
        return $this->std('Conto Economico riclassificato a valore aggiunto', [
            'RICAVI' => [], 'VP' => [null, 'total' => true], 'COSTI_EST' => [], 'VA' => [null, 'total' => true], 'V:CE_B9' => ['Costo del personale (B.9)'],
            'EBITDA' => [null, 'total' => true], 'AMM_ACC' => [], 'EBIT' => [null, 'total' => true], 'SALDO_FIN' => [], 'V:CE_D_TOT' => ['Rettifiche di valore di attività finanziarie (D)'],
            'EBT' => [null, 'total' => true], 'IMPOSTE' => [], 'UTILE' => [null, 'total' => true], 'EBITDA_M' => [], 'ROS' => [], 'NET_M' => [],
        ]);
    }

    private function t_indici_reddituali(): ?array
    {
        return $this->std('Indici reddituali', ['ROE' => [], 'ROI' => [], 'ROS' => [], 'ROA' => [], 'ROD' => [], 'EBITDA_M' => [], 'NET_M' => [], 'LEVA' => []]);
    }

    private function t_indici_liquidita(): ?array
    {
        return $this->std('Indici di liquidità e del circolante', ['CR' => [], 'QR' => [], 'CASH_R' => [], 'CCN' => [], 'CCN_RICAVI' => [], 'DSO' => [], 'DPO' => [], 'DIO' => [], 'CCC' => []]);
    }

    private function t_indici_solidita(): ?array
    {
        return $this->std('Indici di solidità patrimoniale', ['AUTONOMIA' => [], 'INDEBITAMENTO' => [], 'PN_TERZI' => [], 'MS_PRIMARIO' => [], 'MS_SECONDARIO' => []]);
    }

    private function t_indici_finanziari(): ?array
    {
        return $this->std('Indici della gestione finanziaria', ['PFN' => [], 'PFN_EBITDA' => [], 'PFN_PN' => [], 'LEVERAGE' => [], 'COPERTURA_OF' => [], 'OF_RICAVI' => []]);
    }

    private function t_pfn_rapporti(): ?array
    {
        return $this->std('Rapporti di sostenibilità del debito', ['PFN_EBITDA' => [], 'PFN_PN' => [], 'LEVERAGE' => [], 'COPERTURA_OF' => [], 'OF_RICAVI' => []]);
    }

    private function t_benchmark(): ?array
    {
        $p = $this->last();
        $P = IR_Params::get();
        $b = $P['benchmark'][$this->state['profile']['settore'] ?? ''] ?? null;
        if (!$p || !$b) {
            return null;
        }
        $rows = [];
        $mk = function (string $key, string $bench, callable $test) use ($p, &$rows) {
            $n = $this->nodes["$key@$p"] ?? null;
            if (!$n) {
                return;
            }
            $c = $this->cell_node("$key@$p");
            $esito = $n['value'] === null ? $this->cell_text('Non valutabile', 'warn') : (($test($n['value'])) ? $this->cell_text('In linea', 'good') : $this->cell_text('Non in linea', 'bad'));
            $rows[] = ['label' => $n['label'], 'cells' => [$c, $this->cell_text($bench), $esito]];
        };
        $mk('EBITDA_M', $b['ebitda_margin'][0] . '-' . $b['ebitda_margin'][1] . '%', fn($v) => $v >= $b['ebitda_margin'][0]);
        $mk('PFN_EBITDA', '< ' . IR_Format::num($b['pfn_ebitda_max'], 1) . 'x', fn($v) => $v < $b['pfn_ebitda_max']);
        $mk('DSO', $b['dso'][0] . '-' . $b['dso'][1] . ' gg', fn($v) => $v <= $b['dso'][1]);
        $mk('AUTONOMIA', '> ' . $b['autonomia_min'] . '%', fn($v) => $v > $b['autonomia_min']);
        return ['title' => 'Confronto con i benchmark di settore — ' . $b['label'] . ' (esercizio ' . $p . ')', 'columns' => ['Indicatore', 'Valore azienda', 'Benchmark', 'Esito'], 'rows' => $rows, 'notes' => [$P['benchmark_fonte']]];
    }

    private function t_rendiconto(): ?array
    {
        $periods = $this->periods_for('RF_A');
        return $periods ? $this->std('Rendiconto finanziario — sintesi dei flussi', ['RF_A' => [], 'RF_B' => [], 'RF_C' => [], 'FCF' => [null, 'total' => true], 'V:RF_LIQ_INI' => [], 'V:RF_LIQ_FIN' => []], $periods) : null;
    }

    private function t_zscore(): ?array
    {
        $spec = ['Z_X1' => [], 'Z_X2' => [], 'Z_X3' => [], 'Z_X4' => []];
        if (($this->calc['zmodel'] ?? 'Z2') === 'Z1') {
            $spec['Z_X5'] = [];
        }
        $spec['ZSCORE'] = [null, 'total' => true];
        $t = $this->std('Z-Score di Altman — ' . IR_Params::get()['zscore'][$this->calc['zmodel']]['nome'], $spec);
        if ($t) {
            $cells = [];
            foreach ($this->annual() as $p) {
                $n = $this->nodes["ZSCORE@$p"] ?? null;
                $cells[] = $n && $n['eval'] ? $this->cell_text($n['eval']['text'], $n['eval']['level']) : $this->cell_text('—');
            }
            $t['rows'][] = ['label' => 'Classificazione', 'cells' => $cells];
        }
        return $t;
    }

    private function t_infrannuale(): ?array
    {
        $periods = $this->calc['periods']['infra'] ?? [];
        if (!$periods) {
            return null;
        }
        if ($this->last()) {
            array_unshift($periods, $this->last());
        }
        $t = $this->std('Dati infrannuali a confronto con l\'ultimo esercizio', ['RICAVI' => [], 'EBITDA' => [], 'EBIT' => [], 'EBITDA_M' => [], 'PFN' => [], 'PN' => []], $periods);
        if ($t) {
            $t['notes'][] = 'I valori infrannuali non sono annualizzati e possono non includere scritture di assestamento (ammortamenti pro quota, ratei e risconti).';
        }
        return $t;
    }

    private function t_pfn(): ?array
    {
        $spec = ['V:SP_D1' => [], 'V:SP_D2' => []];
        if (!empty($this->state['params']['soci_onerosi'])) {
            $spec['V:SP_D3'] = [];
        }
        $spec += ['D4_TOT' => [], 'D5_TOT' => [], 'DEBFIN' => [null, 'total' => true], 'V:SP_CIV' => [], 'V:SP_CIII' => [], 'LIQFIN' => [null, 'total' => true], 'PFN' => [null, 'total' => true]];
        return $this->std('Calcolo della Posizione Finanziaria Netta', $spec);
    }

    private function t_ccc(): ?array
    {
        return $this->std('Ciclo di conversione di cassa', ['DSO' => [], 'DIO' => [], 'DPO' => [], 'CCC' => [null, 'total' => true]]);
    }

    private function t_aging(): ?array
    {
        $periods = IR_Dataset::periods_matching($this->data, '/^AGING_/', '/./');
        if (!$periods) {
            return null;
        }
        return $this->std('Analisi degli scadenziari', [
            'V:AGING_CLI_TOT' => [], 'V:AGING_CLI_SCADUTO' => [], 'V:AGING_CLI_SCADUTO_90' => [], 'CLI_SCADUTO_PCT' => [], 'CLI_SC90_PCT' => [],
            'V:AGING_FOR_TOT' => [], 'V:AGING_FOR_NON_SCADUTO' => [], 'V:AGING_FOR_SCADUTO' => [], 'V:AGING_FOR_SCADUTO_90' => [], 'FOR_SCADUTO_PCT' => [],
        ], $periods);
    }

    private function t_centrale_rischi(): ?array
    {
        $periods = IR_Dataset::periods_matching($this->data, '/^CR_/', '/^\d{4}-\d{2}$/');
        if (!$periods) {
            return null;
        }
        $periods = array_slice($periods, -6);
        $t = $this->std('Centrale Rischi — andamento (ultimi mesi disponibili)', [
            'CR_ACC_TOT' => [], 'CR_UTI_TOT' => [], 'CR_UTILIZZO' => [null, 'total' => true], 'CR_UTILIZZO_REVOCA' => [], 'CR_UTILIZZO_AUTOLIQ' => [], 'CR_UTILIZZO_SCADENZA' => [],
            'CR_SCONFINO' => [], 'CR_SOFFERENZE' => [],
        ], $periods);
        return $t;
    }

    private function t_ccii(): ?array
    {
        $rows = [];
        $notes = [];
        foreach (['CCII_RETRIB', 'CCII_FORN', 'CCII_BANCHE', 'CCII_INPS', 'CCII_INAIL', 'CCII_IVA', 'CCII_ADER'] as $key) {
            foreach ($this->nodes as $nid => $n) {
                if ($n['key'] !== $key) {
                    continue;
                }
                $rows[] = ['label' => $n['label'], 'cells' => [
                    $this->cell_text(IR_Format::period_label($n['period'])),
                    $this->cell_node($nid),
                    $this->cell_text($n['eval']['soglia'] ?? '—'),
                    $n['eval'] ? $this->cell_text($n['eval']['text'], $n['eval']['level']) : $this->cell_text('Non valutabile', 'warn'),
                ]];
            }
        }
        if (isset($this->nodes['DSCR@prosp'])) {
            $n = $this->nodes['DSCR@prosp'];
            $rows[] = ['label' => $n['label'], 'cells' => [$this->cell_text('Prospettico'), $this->cell_node('DSCR@prosp'), $this->cell_text($n['eval']['soglia'] ?? '—'), $n['eval'] ? $this->cell_text($n['eval']['text'], $n['eval']['level']) : $this->cell_text('Non valutabile', 'warn')]];
        }
        if (isset($this->nodes['CCII_SEGNALI@tot'])) {
            $rows[] = ['label' => 'Totale segnali di allarme attivi', 'cells' => [$this->cell_text(''), $this->cell_node('CCII_SEGNALI@tot'), $this->cell_text(''), $this->cell_text($this->nodes['CCII_SEGNALI@tot']['eval']['text'] ?? '', $this->nodes['CCII_SEGNALI@tot']['eval']['level'] ?? null)], 'total' => true];
        }
        $notes[] = IR_Params::get()['ccii']['fonte'];
        $missing = [];
        $have = array_map(fn($n) => $n['key'], $this->nodes);
        $map = ['CCII_RETRIB' => 'retribuzioni (art. 3, c. 4, lett. a)', 'CCII_FORN' => 'fornitori (lett. b)', 'CCII_BANCHE' => 'esposizioni bancarie (lett. c)', 'CCII_INPS' => 'INPS', 'CCII_INAIL' => 'INAIL', 'CCII_IVA' => 'IVA', 'CCII_ADER' => 'AdER'];
        foreach ($map as $k => $l) {
            if (!in_array($k, $have, true)) {
                $missing[] = $l;
            }
        }
        if ($missing) {
            $notes[] = 'Verifiche non eseguibili per assenza di documentazione specifica: ' . implode(', ', $missing) . '.';
        }
        return ['title' => 'Verifica degli indicatori della crisi', 'columns' => ['Indicatore', 'Data rif.', 'Valore', 'Soglia', 'Esito'], 'rows' => $rows, 'notes' => $notes];
    }

    private function node_input_rows(string $nid): array
    {
        $rows = [];
        $n = $this->nodes[$nid] ?? null;
        if (!$n) {
            return [];
        }
        foreach ($n['inputs'] as $i) {
            if ($i['param']) {
                $rows[] = ['label' => $i['label'] . ' (parametro)', 'cells' => [$this->cell_text($this->fmt_param($i['value']))]];
            } elseif ($i['ref']) {
                $rows[] = ['label' => $i['label'], 'cells' => [str_starts_with($i['ref'], 'V:') ? $this->cell_data(substr($i['ref'], 2)) : $this->cell_node(substr($i['ref'], 2))]];
            }
        }
        return $rows;
    }

    private function fmt_param(?float $v): string
    {
        if ($v === null) {
            return 'n.d.';
        }
        return abs($v) < 1 ? IR_Format::num($v * 100, 2) . '%' : IR_Format::num($v, 0);
    }

    private function t_dscr(): ?array
    {
        if (!isset($this->nodes['DSCR@prosp']) && !isset($this->nodes['DSCR2@prosp'])) {
            return null;
        }
        $rows = array_merge($this->node_input_rows('FLUSSI_OP@prosp'), [['label' => 'Flussi finanziari operativi a 12 mesi', 'cells' => [$this->cell_node('FLUSSI_OP@prosp')], 'total' => true]],
            $this->node_input_rows('SERV_DEBITO@prosp'), [['label' => 'Servizio del debito a 12 mesi', 'cells' => [$this->cell_node('SERV_DEBITO@prosp')], 'total' => true]],
            [['label' => 'DSCR a 12 mesi (approccio 1)', 'cells' => [$this->cell_node('DSCR@prosp')], 'total' => true]]);
        if (isset($this->nodes['DSCR2@prosp'])) {
            $rows[] = ['label' => 'DSCR a 12 mesi (approccio 2)', 'cells' => [$this->cell_node('DSCR2@prosp')], 'total' => true];
        }
        return ['title' => 'Calcolo del DSCR a 12 mesi', 'columns' => ['Voce', 'Valore'], 'rows' => $rows, 'notes' => ['DSCR = (EBITDA prospettico − imposte − variazione del CCN) / (quote capitale + interessi a 12 mesi). Approccio 1 CNDCEC.']];
    }

    private function t_stress(): ?array
    {
        $sc = IR_Params::get()['stress']['scenari'];
        $periods = [];
        foreach ($sc as $i => $s) {
            if (isset($this->nodes["STRESS_EBITDA@S$i"])) {
                $periods[] = "S$i";
            }
        }
        if (!$periods) {
            return null;
        }
        $notes = [];
        $rows = $this->period_rows(['STRESS_EBITDA' => ['EBITDA'], 'STRESS_DSCR' => ['DSCR'], 'STRESS_PFN_EBITDA' => ['PFN / EBITDA']], $periods, $notes);
        $cols = ['Voce'];
        foreach ($periods as $p) {
            $i = (int) substr($p, 1);
            $cols[] = $sc[$i]['nome'] . ' (' . ($sc[$i]['shock_ebitda'] == 0 ? 'nessuno shock' : IR_Format::num($sc[$i]['shock_ebitda'] * 100, 0) . '% EBITDA') . ')';
        }
        $notes[] = IR_Params::get()['stress']['fonte'];
        return ['title' => 'Stress test sugli indicatori di sostenibilità', 'columns' => $cols, 'rows' => $rows, 'notes' => $notes];
    }

    private function t_debito_scadenze(): ?array
    {
        $rows = [];
        foreach ($this->nodes as $nid => $n) {
            if (in_array($n['key'], ['FIN_RESIDUO', 'FIN_QUOTA_12M_PCT', 'FIN_QUOTA_1_5_PCT', 'FIN_QUOTA_OLTRE5_PCT', 'COPERTURA_SERV'], true)) {
                $rows[] = ['label' => $n['label'], 'cells' => [$this->cell_node($nid)]];
            }
        }
        foreach (['FIN_QUOTA_12M', 'FIN_QUOTA_1_5', 'FIN_QUOTA_OLTRE5', 'FIN_INTERESSI_12M', 'FIDI_ACCORDATI'] as $c) {
            foreach ($this->data as $key => $e) {
                if ($e['code'] === $c) {
                    $rows[] = ['label' => $e['label'] . ' (' . IR_Format::period_label($e['period']) . ')', 'cells' => [$this->cell_data($key)]];
                }
            }
        }
        return ['title' => 'Profilo delle scadenze del debito finanziario', 'columns' => ['Voce', 'Valore'], 'rows' => $rows, 'notes' => []];
    }

    private function t_ccn(): ?array
    {
        return $this->std('Capitale circolante netto', ['CCN' => [null, 'total' => true], 'CCN_COMM' => [], 'VAR_CCN' => [], 'CCN_RICAVI' => []]);
    }

    private function t_zes(): ?array
    {
        $rows = [];
        foreach (['ZES_REQ_MIN', 'ZES_QUOTA_IMMOBILI', 'ZES_TEORICO', 'ZES_ATTESO', 'ZES_NETTO', 'ZES_RECUPERO'] as $key) {
            foreach ($this->nodes as $nid => $n) {
                if ($n['key'] === $key) {
                    foreach ($n['inputs'] as $i) {
                        if ($i['param']) {
                            $rows[] = ['label' => '   ' . $i['label'] . ' (parametro)', 'cells' => [$this->cell_text($this->fmt_param($i['value'])), $this->cell_text('')]];
                        }
                    }
                    $rows[] = ['label' => $n['label'], 'cells' => [$this->cell_node($nid), $n['eval'] ? $this->cell_text($n['eval']['text'], $n['eval']['level']) : $this->cell_text('')], 'total' => in_array($key, ['ZES_ATTESO', 'ZES_NETTO'], true)];
                }
            }
        }
        return ['title' => 'Stima del credito d\'imposta ZES Unica', 'columns' => ['Voce', 'Valore', 'Esito'], 'rows' => $rows, 'notes' => [IR_Params::get()['zes']['fonte']]];
    }

    private function t_fiscale(): ?array
    {
        return $this->std('Carico fiscale', ['EBT' => [], 'IMPOSTE' => [], 'ETR' => [null, 'total' => true], 'FISC_IRES' => [], 'FISC_IRAP' => []]);
    }

    private function t_rating(): ?array
    {
        $p = $this->last();
        if (!$p || !isset($this->nodes["RATING@$p"])) {
            return null;
        }
        $rows = [];
        foreach ($this->nodes as $nid => $n) {
            if (str_starts_with($n['key'], 'RATING_') && $n['period'] === $p) {
                $src = $n['inputs'][0];
                $rows[] = ['label' => $src['label'], 'cells' => [$this->cell_node(substr($src['ref'], 2)), $this->cell_node($nid)]];
            }
        }
        if (isset($this->nodes['CCII_SEGNALI@tot'])) {
            $rows[] = ['label' => 'Segnali di allarme CCII attivi', 'cells' => [$this->cell_node('CCII_SEGNALI@tot'), $this->cell_text(($this->nodes['CCII_SEGNALI@tot']['value'] ?? 0) > 0 ? '−1 classe' : 'nessun effetto')]];
        }
        $rows[] = ['label' => 'Rating interno sintetico', 'cells' => [$this->cell_text(''), $this->cell_node("RATING@$p")], 'total' => true];
        return ['title' => 'Assegnazione del rating interno (esercizio ' . $p . ')', 'columns' => ['Indicatore', 'Valore', 'Classe'], 'rows' => $rows, 'notes' => [IR_Params::get()['rating']['fonte']]];
    }

    /** Tabelle disponibili per una sezione. */
    public function for_section(string $sid): array
    {
        $out = [];
        foreach (IR_Catalog::sections()[$sid]['tables'] ?? [] as $tid) {
            $t = $this->build($tid);
            if ($t) {
                $out[$tid] = $t;
            }
        }
        return $out;
    }
}
