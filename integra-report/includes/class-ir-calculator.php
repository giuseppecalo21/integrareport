<?php
/**
 * Motore di calcolo deterministico dei KPI (formule da formule_kpi.md).
 *
 * Ogni risultato è un "nodo" con: formula simbolica, valori in ingresso (ciascuno con riferimento
 * al dato estratto "V:CODICE@PERIODO" o a un altro nodo "K:CHIAVE@PERIODO"), risultato,
 * eventuale valutazione rispetto alla soglia e fonte della soglia. Se manca anche un solo
 * ingresso, il nodo è "non valutabile" ed elenca cosa manca: nessun valore viene stimato.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Calculator
{
    private array $data;
    private array $state;
    private array $P;
    private array $rp;
    public array $nodes = [];
    public array $checks = [];
    private array $annual = [];

    public function __construct(array $state, array $resolved)
    {
        $this->state = $state;
        $this->data = $resolved;
        $this->P = IR_Params::get();
        $this->rp = $state['params'] ?? [];
    }

    // ------------------------------------------------------------------ primitive

    private function d(string $code, string $p): array
    {
        $key = "$code@$p";
        $e = $this->data[$key] ?? null;
        $label = IR_Catalog::data_dictionary()[$code]['label'] ?? $code;
        if (!$e) {
            return ['ref' => "V:$key", 'label' => "$label (" . IR_Format::period_label($p) . ')', 'value' => null, 'why' => 'dato non presente nei documenti estratti'];
        }
        if ($e['status'] === 'conflict') {
            return ['ref' => "V:$key", 'label' => "$label (" . IR_Format::period_label($p) . ')', 'value' => null, 'why' => 'conflitto tra fonti non risolto'];
        }
        if ($e['status'] === 'excluded') {
            return ['ref' => "V:$key", 'label' => "$label (" . IR_Format::period_label($p) . ')', 'value' => null, 'why' => 'dato escluso dal consulente'];
        }
        return ['ref' => "V:$key", 'label' => "$label (" . IR_Format::period_label($p) . ')', 'value' => $e['value'], 'why' => null];
    }

    private function k(string $key, string $p): array
    {
        $id = "$key@$p";
        $n = $this->nodes[$id] ?? null;
        if (!$n) {
            return ['ref' => "K:$id", 'label' => "$key (" . IR_Format::period_label($p) . ')', 'value' => null, 'why' => 'indicatore non calcolabile'];
        }
        return ['ref' => "K:$id", 'label' => $n['label'] . ' (' . IR_Format::period_label($p) . ')', 'value' => $n['value'], 'why' => $n['value'] === null ? 'indicatore non valutabile' : null];
    }

    private function has(string $code, string $p): bool
    {
        $e = $this->data["$code@$p"] ?? null;
        return $e && $e['value'] !== null;
    }

    /** Parametro di modello, tracciato come ingresso. */
    private function param(string $label, float $value, string $fonte): array
    {
        return ['ref' => null, 'label' => $label, 'value' => $value, 'why' => null, 'param' => $fonte];
    }

    /**
     * Definisce un nodo.
     * @param array<string,array> $in simbolo => ingresso
     * @param callable $fn riceve array simbolo => valore; restituisce float|null oppure ['nv' => motivo]
     */
    private function n(string $key, string $p, string $label, string $unit, string $formula, array $in, callable $fn, array $opt = []): ?float
    {
        $id = "$key@$p";
        $missing = [];
        $vals = [];
        $inputs = [];
        foreach ($in as $sym => $i) {
            $inputs[] = ['sym' => $sym, 'ref' => $i['ref'], 'label' => $i['label'], 'value' => $i['value'], 'param' => $i['param'] ?? null];
            if ($i['value'] === null) {
                $missing[] = "$sym — {$i['label']}: {$i['why']}";
            }
            $vals[$sym] = $i['value'];
        }
        $value = null;
        $note = $opt['note'] ?? '';
        $nv = null;
        if (!$missing) {
            $r = $fn($vals);
            if (is_array($r)) {
                $nv = $r['nv'] ?? 'non significativo';
            } elseif ($r !== null && is_finite((float) $r)) {
                $value = (float) $r;
            } else {
                $nv = 'risultato non determinabile (denominatore nullo)';
            }
        }
        $node = [
            'id' => $id,
            'key' => $key,
            'period' => $p,
            'label' => $label,
            'unit' => $unit,
            'formula' => $formula,
            'inputs' => $inputs,
            'value' => $value,
            'status' => $value !== null ? 'ok' : 'nv',
            'missing' => $missing,
            'nv_reason' => $nv,
            'note' => $note,
            'eval' => null,
            'display' => null,
        ];
        if ($value !== null && isset($opt['soglia'])) {
            $node['eval'] = $this->evaluate($value, $opt['soglia']);
        }
        if ($value !== null && isset($opt['eval'])) {
            $node['eval'] = ($opt['eval'])($value, $vals);
        }
        if ($value !== null && isset($opt['display'])) {
            $node['display'] = ($opt['display'])($value);
        }
        $this->nodes[$id] = $node;
        return $value;
    }

    private function evaluate(float $v, string $sogliaKey): ?array
    {
        $s = $this->P['soglie'][$sogliaKey] ?? null;
        if (!$s) {
            return null;
        }
        if ($s['dir'] === 'high') {
            $level = $v >= $s['good'] ? 'good' : ($v < $s['bad'] ? 'bad' : 'warn');
            $txt = "Verde se ≥ {$this->fmt($s['good'])}; rosso se < {$this->fmt($s['bad'])}";
        } else {
            $level = $v <= $s['good'] ? 'good' : ($v > $s['bad'] ? 'bad' : 'warn');
            $txt = "Verde se ≤ {$this->fmt($s['good'])}; rosso se > {$this->fmt($s['bad'])}";
        }
        return ['level' => $level, 'text' => self::level_label($level), 'soglia' => $txt, 'fonte' => $s['fonte']];
    }

    public static function level_label(string $level): string
    {
        return ['good' => 'Adeguato', 'warn' => 'Attenzione', 'bad' => 'Critico', 'info' => '—'][$level] ?? '—';
    }

    private function fmt(float $v): string
    {
        return IR_Format::num($v, fmod($v, 1.0) == 0.0 ? 0 : 2);
    }

    private function check(string $id, string $label, string $p, array $a, array $b, string $note = ''): void
    {
        if ($a['value'] === null || $b['value'] === null) {
            $this->checks[] = ['id' => "$id@$p", 'label' => $label, 'period' => $p, 'a' => $a, 'b' => $b, 'diff' => null, 'ok' => null, 'note' => 'Controllo non eseguibile: dati mancanti.'];
            return;
        }
        $tol = 2.0 * max($this->scale_of($a['ref']), $this->scale_of($b['ref']));
        $diff = $a['value'] - $b['value'];
        $this->checks[] = ['id' => "$id@$p", 'label' => $label, 'period' => $p, 'a' => $a, 'b' => $b, 'diff' => $diff, 'ok' => abs($diff) <= $tol, 'tol' => $tol, 'note' => $note];
    }

    private function scale_of(?string $ref): float
    {
        if ($ref && str_starts_with($ref, 'V:')) {
            $e = $this->data[substr($ref, 2)] ?? null;
            return (float) ($e['source']['scale'] ?? 1);
        }
        return 1.0;
    }

    private function prev(string $p): ?string
    {
        $y = (string) ((int) $p - 1);
        return in_array($y, $this->annual, true) ? $y : null;
    }

    private function sum_formula(array $syms): string
    {
        return implode(' + ', $syms);
    }

    // ------------------------------------------------------------------ esecuzione

    public function run(): array
    {
        $this->annual = IR_Dataset::annual_periods($this->data);
        $infra = IR_Dataset::periods_matching($this->data, '/^(SP|CE)_/', '/^\d{4}-\d{2}-\d{2}$/');

        foreach (array_merge($this->annual, $infra) as $p) {
            $this->core($p);
        }
        foreach ($this->annual as $p) {
            $this->averaged($p);
            $this->zscore($p);
            $this->rendiconto($p);
            $this->fiscale($p);
        }
        $this->centrale_rischi();
        $this->aging();
        $this->ccii();
        $this->dscr_and_stress();
        $this->debito();
        $this->zes();
        $this->rating();

        return [
            'computed_at' => time(),
            'periods' => ['annual' => $this->annual, 'infra' => $infra, 'last' => $this->annual ? end($this->annual) : null],
            'nodes' => $this->nodes,
            'checks' => $this->checks,
            'data' => $this->data,
            'zmodel' => $this->zmodel(),
            'params_version' => $this->P['versione'],
            'settore' => $this->state['profile']['settore'] ?? '',
        ];
    }

    private function core(string $p): void
    {
        $d = fn($c) => $this->d($c, $p);
        $k = fn($c) => $this->k($c, $p);

        // --- derivazioni di supporto (usate solo se il dato diretto manca)
        if ($this->has('SP_CII_ENTRO', $p) || !$this->has('SP_CII_TOT', $p)) {
            $this->n('CRED_ENTRO', $p, 'Crediti esigibili entro l\'esercizio successivo', 'eur', 'C.II entro (dato di bilancio)', ['C.II entro' => $d('SP_CII_ENTRO')], fn($v) => $v['C.II entro']);
        } else {
            $this->n('CRED_ENTRO', $p, 'Crediti esigibili entro l\'esercizio successivo', 'eur', 'C.II totale − C.II oltre', ['C.II totale' => $d('SP_CII_TOT'), 'C.II oltre' => $d('SP_CII_OLTRE')], fn($v) => $v['C.II totale'] - $v['C.II oltre']);
        }
        if ($this->has('SP_CII_OLTRE', $p) || !$this->has('SP_CII_TOT', $p)) {
            $this->n('CRED_OLTRE', $p, 'Crediti esigibili oltre l\'esercizio successivo', 'eur', 'C.II oltre (dato di bilancio)', ['C.II oltre' => $d('SP_CII_OLTRE')], fn($v) => $v['C.II oltre']);
        } else {
            $this->n('CRED_OLTRE', $p, 'Crediti esigibili oltre l\'esercizio successivo', 'eur', 'C.II totale − C.II entro', ['C.II totale' => $d('SP_CII_TOT'), 'C.II entro' => $d('SP_CII_ENTRO')], fn($v) => $v['C.II totale'] - $v['C.II entro']);
        }
        if ($this->has('SP_D_ENTRO', $p) || !$this->has('SP_D_TOT', $p)) {
            $this->n('DEB_ENTRO', $p, 'Debiti esigibili entro l\'esercizio successivo', 'eur', 'D entro (dato di bilancio)', ['D entro' => $d('SP_D_ENTRO')], fn($v) => $v['D entro']);
        } else {
            $this->n('DEB_ENTRO', $p, 'Debiti esigibili entro l\'esercizio successivo', 'eur', 'D totale − D oltre', ['D totale' => $d('SP_D_TOT'), 'D oltre' => $d('SP_D_OLTRE')], fn($v) => $v['D totale'] - $v['D oltre']);
        }
        if ($this->has('SP_D_OLTRE', $p) || !$this->has('SP_D_TOT', $p)) {
            $this->n('DEB_OLTRE', $p, 'Debiti esigibili oltre l\'esercizio successivo', 'eur', 'D oltre (dato di bilancio)', ['D oltre' => $d('SP_D_OLTRE')], fn($v) => $v['D oltre']);
        } else {
            $this->n('DEB_OLTRE', $p, 'Debiti esigibili oltre l\'esercizio successivo', 'eur', 'D totale − D entro', ['D totale' => $d('SP_D_TOT'), 'D entro' => $d('SP_D_ENTRO')], fn($v) => $v['D totale'] - $v['D entro']);
        }
        foreach (['D4' => 'Debiti verso banche', 'D5' => 'Debiti verso altri finanziatori'] as $c => $lbl) {
            if ($this->has("SP_$c", $p)) {
                $this->n("{$c}_TOT", $p, "$lbl (totale)", 'eur', "$c (dato di bilancio)", [$c => $d("SP_$c")], fn($v) => $v[$c]);
            } else {
                $this->n("{$c}_TOT", $p, "$lbl (totale)", 'eur', "$c entro + $c oltre", ["$c entro" => $d("SP_{$c}_ENTRO"), "$c oltre" => $d("SP_{$c}_OLTRE")], fn($v) => $v["$c entro"] + $v["$c oltre"]);
            }
        }

        // --- SP riclassificato finanziario
        $this->n('LI', $p, 'Liquidità immediate', 'eur', 'C.IV Disponibilità liquide', ['C.IV' => $d('SP_CIV')], fn($v) => $v['C.IV']);
        $this->n('LD', $p, 'Liquidità differite', 'eur', 'Crediti entro 12 mesi + C.III + D Ratei e risconti attivi + A Crediti verso soci',
            ['Crediti entro' => $k('CRED_ENTRO'), 'C.III' => $d('SP_CIII'), 'D attivo' => $d('SP_D_ATT'), 'A' => $d('SP_A')],
            fn($v) => $v['Crediti entro'] + $v['C.III'] + $v['D attivo'] + $v['A'],
            ['note' => 'Ratei e risconti attivi e crediti verso soci sono classificati a breve termine per convenzione del modello; C.III considerate prontamente liquidabili.']);
        $this->n('DI', $p, 'Disponibilità (rimanenze)', 'eur', 'C.I Rimanenze', ['C.I' => $d('SP_CI')], fn($v) => $v['C.I']);
        $this->n('AC', $p, 'Attivo corrente', 'eur', 'LI + LD + DI', ['LI' => $k('LI'), 'LD' => $k('LD'), 'DI' => $k('DI')], fn($v) => $v['LI'] + $v['LD'] + $v['DI']);
        $this->n('AI', $p, 'Attivo immobilizzato', 'eur', 'B.I + B.II + B.III + Crediti oltre 12 mesi',
            ['B.I' => $d('SP_BI'), 'B.II' => $d('SP_BII'), 'B.III' => $d('SP_BIII'), 'Crediti oltre' => $k('CRED_OLTRE')],
            fn($v) => $v['B.I'] + $v['B.II'] + $v['B.III'] + $v['Crediti oltre']);
        $this->n('AT', $p, 'Attivo totale riclassificato', 'eur', 'AC + AI', ['AC' => $k('AC'), 'AI' => $k('AI')], fn($v) => $v['AC'] + $v['AI']);
        $this->n('PC', $p, 'Passivo corrente', 'eur', 'Debiti entro 12 mesi + E Ratei e risconti passivi', ['Debiti entro' => $k('DEB_ENTRO'), 'E' => $d('SP_E_PASS')], fn($v) => $v['Debiti entro'] + $v['E'],
            ['note' => 'Ratei e risconti passivi classificati a breve termine per convenzione del modello.']);
        $this->n('PCNS', $p, 'Passivo consolidato', 'eur', 'Debiti oltre 12 mesi + C TFR + B Fondi rischi e oneri',
            ['Debiti oltre' => $k('DEB_OLTRE'), 'C TFR' => $d('SP_C_TFR'), 'B Fondi' => $d('SP_B_FONDI')],
            fn($v) => $v['Debiti oltre'] + $v['C TFR'] + $v['B Fondi'],
            ['note' => 'Fondi rischi e TFR classificati a medio-lungo termine per convenzione del modello.']);
        $this->n('PN', $p, 'Patrimonio netto', 'eur', 'A Patrimonio netto', ['A' => $d('SP_PN')], fn($v) => $v['A']);
        $this->n('FONTI', $p, 'Totale fonti riclassificate', 'eur', 'PC + PCns + PN', ['PC' => $k('PC'), 'PCns' => $k('PCNS'), 'PN' => $k('PN')], fn($v) => $v['PC'] + $v['PCns'] + $v['PN']);

        $this->check('Q_ATT', 'Quadratura attivo: attivo riclassificato = totale attivo di bilancio', $p, $k('AT'), $d('SP_TOT_ATT'));
        $this->check('Q_PASS', 'Quadratura fonti: fonti riclassificate = totale passivo di bilancio', $p, $k('FONTI'), $d('SP_TOT_PASS'));
        $this->check('Q_SP', 'Totale attivo = totale passivo', $p, $d('SP_TOT_ATT'), $d('SP_TOT_PASS'));

        // --- PFN
        $soci = !empty($this->rp['soci_onerosi']);
        $inDeb = ['D.1' => $d('SP_D1'), 'D.2' => $d('SP_D2'), 'D.4' => $k('D4_TOT'), 'D.5' => $k('D5_TOT')];
        if ($soci) {
            $inDeb['D.3'] = $d('SP_D3');
        }
        $this->n('DEBFIN', $p, 'Debiti finanziari lordi', 'eur', $this->sum_formula(array_keys($inDeb)), $inDeb, fn($v) => array_sum($v),
            ['note' => $soci ? 'I finanziamenti dei soci (D.3) sono inclusi perché indicati come onerosi dal consulente.' : 'I finanziamenti dei soci (D.3) sono esclusi: non indicati come onerosi dal consulente. Leasing non inclusi (non presenti nello schema CEE).']);
        $this->n('LIQFIN', $p, 'Liquidità e attività finanziarie a breve', 'eur', 'C.IV + C.III', ['C.IV' => $d('SP_CIV'), 'C.III' => $d('SP_CIII')], fn($v) => $v['C.IV'] + $v['C.III']);
        $this->n('PFN', $p, 'Posizione finanziaria netta', 'eur', 'Debiti finanziari lordi − Liquidità e attività finanziarie a breve', ['Debiti finanziari' => $k('DEBFIN'), 'Liquidità' => $k('LIQFIN')], fn($v) => $v['Debiti finanziari'] - $v['Liquidità'],
            ['note' => 'Valore positivo = indebitamento netto; negativo = cassa netta.']);
        $this->n('CIN', $p, 'Capitale investito netto', 'eur', 'PN + PFN', ['PN' => $k('PN'), 'PFN' => $k('PFN')], fn($v) => $v['PN'] + $v['PFN']);
        $this->n('CCN_COMM', $p, 'Capitale circolante commerciale', 'eur', 'C.I Rimanenze + C.II.1 Crediti verso clienti − D.7 Debiti verso fornitori',
            ['C.I' => $d('SP_CI'), 'C.II.1' => $d('SP_CII_CLIENTI'), 'D.7' => $d('SP_D7')], fn($v) => $v['C.I'] + $v['C.II.1'] - $v['D.7']);
        $this->n('IMM_NETTE', $p, 'Immobilizzazioni nette', 'eur', 'B.I + B.II + B.III', ['B.I' => $d('SP_BI'), 'B.II' => $d('SP_BII'), 'B.III' => $d('SP_BIII')], fn($v) => $v['B.I'] + $v['B.II'] + $v['B.III']);

        // --- CE a valore aggiunto
        $this->n('RICAVI', $p, 'Ricavi delle vendite e delle prestazioni', 'eur', 'A.1', ['A.1' => $d('CE_A1')], fn($v) => $v['A.1']);
        $this->n('VP', $p, 'Valore della produzione', 'eur', 'A Totale valore della produzione', ['A' => $d('CE_A_TOT')], fn($v) => $v['A'],
            ['note' => 'Include A.5 Altri ricavi e proventi nella loro interezza: eventuali componenti non ricorrenti vanno valutate nel commento.']);
        $this->n('EBIT', $p, 'EBIT (Margine operativo netto)', 'eur', 'A Totale valore della produzione − B Totale costi della produzione', ['A' => $d('CE_A_TOT'), 'B' => $d('CE_B_TOT')], fn($v) => $v['A'] - $v['B']);
        $this->n('EBITDA', $p, 'EBITDA (Margine operativo lordo)', 'eur', 'EBIT + B.10 Ammortamenti e svalutazioni + B.12 + B.13 Accantonamenti',
            ['EBIT' => $k('EBIT'), 'B.10' => $d('CE_B10'), 'B.12' => $d('CE_B12'), 'B.13' => $d('CE_B13')], fn($v) => $v['EBIT'] + $v['B.10'] + $v['B.12'] + $v['B.13']);
        $this->n('VA', $p, 'Valore aggiunto', 'eur', 'EBITDA + B.9 Costo del personale', ['EBITDA' => $k('EBITDA'), 'B.9' => $d('CE_B9')], fn($v) => $v['EBITDA'] + $v['B.9']);
        $this->n('COSTI_EST', $p, 'Costi esterni', 'eur', 'Valore della produzione − Valore aggiunto', ['VP' => $k('VP'), 'VA' => $k('VA')], fn($v) => $v['VP'] - $v['VA'],
            ['note' => 'Corrisponde a B.6 + B.7 + B.8 + B.11 + B.14 (verificato nei controlli di quadratura).']);
        $this->n('COSTI_EST_SOMMA', $p, 'Costi esterni (somma delle voci)', 'eur', 'B.6 + B.7 + B.8 + B.11 + B.14',
            ['B.6' => $d('CE_B6'), 'B.7' => $d('CE_B7'), 'B.8' => $d('CE_B8'), 'B.11' => $d('CE_B11'), 'B.14' => $d('CE_B14')], fn($v) => array_sum($v));
        $this->check('Q_CE_EST', 'Costi esterni: (VP − VA) = B.6 + B.7 + B.8 + B.11 + B.14', $p, $k('COSTI_EST'), $k('COSTI_EST_SOMMA'));
        $this->n('AMM_ACC', $p, 'Ammortamenti, svalutazioni e accantonamenti', 'eur', 'B.10 + B.12 + B.13', ['B.10' => $d('CE_B10'), 'B.12' => $d('CE_B12'), 'B.13' => $d('CE_B13')], fn($v) => array_sum($v));
        $this->n('SALDO_FIN', $p, 'Saldo della gestione finanziaria', 'eur', 'C.15 + C.16 − C.17 + C.17-bis',
            ['C.15' => $d('CE_C15'), 'C.16' => $d('CE_C16'), 'C.17' => $d('CE_C17'), 'C.17-bis' => $d('CE_C17BIS')], fn($v) => $v['C.15'] + $v['C.16'] - $v['C.17'] + $v['C.17-bis']);
        $this->check('Q_CE_FIN', 'Saldo finanziario calcolato = C Totale proventi e oneri finanziari', $p, $k('SALDO_FIN'), $d('CE_C_TOT'));
        $this->n('OF_NETTI', $p, 'Oneri finanziari netti', 'eur', 'C.17 Interessi e altri oneri finanziari − C.16 Altri proventi finanziari', ['C.17' => $d('CE_C17'), 'C.16' => $d('CE_C16')], fn($v) => $v['C.17'] - $v['C.16']);
        $this->n('EBT_CALC', $p, 'Risultato ante imposte (ricostruito)', 'eur', 'EBIT + Saldo finanziario + D Rettifiche di valore', ['EBIT' => $k('EBIT'), 'Saldo fin.' => $k('SALDO_FIN'), 'D' => $d('CE_D_TOT')], fn($v) => $v['EBIT'] + $v['Saldo fin.'] + $v['D']);
        $this->check('Q_CE_EBT', 'Risultato ante imposte ricostruito = risultato prima delle imposte di bilancio', $p, $k('EBT_CALC'), $d('CE_EBT'));
        $this->n('EBT', $p, 'Risultato prima delle imposte', 'eur', 'Risultato prima delle imposte (dato di bilancio)', ['EBT' => $d('CE_EBT')], fn($v) => $v['EBT']);
        $this->n('IMPOSTE', $p, 'Imposte sul reddito', 'eur', 'Voce 20', ['20' => $d('CE_20')], fn($v) => $v['20']);
        $this->n('UTILE', $p, 'Utile (perdita) dell\'esercizio', 'eur', 'Voce 21', ['21' => $d('CE_21')], fn($v) => $v['21']);
        $this->n('UTILE_CALC', $p, 'Utile ricostruito', 'eur', 'Risultato prima delle imposte − Imposte (voce 20)', ['EBT' => $d('CE_EBT'), '20' => $d('CE_20')], fn($v) => $v['EBT'] - $v['20']);
        $this->check('Q_CE_UTILE', 'Risultato prima delle imposte − imposte = utile (perdita) di CE', $p, $k('UTILE_CALC'), $d('CE_21'));
        $this->check('Q_UTILE_SP', 'Utile di Conto Economico = utile dello Stato Patrimoniale (A.IX)', $p, $d('CE_21'), $d('SP_PN_IX'));

        $this->n('EBITDA_M', $p, 'EBITDA margin', 'pct', 'EBITDA / Ricavi × 100', ['EBITDA' => $k('EBITDA'), 'Ricavi' => $k('RICAVI')], fn($v) => $v['Ricavi'] != 0 ? $v['EBITDA'] / $v['Ricavi'] * 100 : null,
            ['eval' => fn($x) => $this->bench_eval_margin($x)]);
        $this->n('ROS', $p, 'ROS — EBIT margin', 'pct', 'EBIT / Ricavi × 100', ['EBIT' => $k('EBIT'), 'Ricavi' => $k('RICAVI')], fn($v) => $v['Ricavi'] != 0 ? $v['EBIT'] / $v['Ricavi'] * 100 : null, ['soglia' => 'ROS']);
        $this->n('NET_M', $p, 'Net profit margin', 'pct', 'Utile / Ricavi × 100', ['Utile' => $k('UTILE'), 'Ricavi' => $k('RICAVI')], fn($v) => $v['Ricavi'] != 0 ? $v['Utile'] / $v['Ricavi'] * 100 : null, ['soglia' => 'NET_MARGIN']);

        // --- liquidità e circolante
        $this->n('CR', $p, 'Current ratio', 'ratio', 'Attivo corrente / Passivo corrente', ['AC' => $k('AC'), 'PC' => $k('PC')], fn($v) => $v['PC'] != 0 ? $v['AC'] / $v['PC'] : null, ['soglia' => 'CR']);
        $this->n('QR', $p, 'Quick ratio (acid test)', 'ratio', '(LI + LD) / Passivo corrente', ['LI' => $k('LI'), 'LD' => $k('LD'), 'PC' => $k('PC')], fn($v) => $v['PC'] != 0 ? ($v['LI'] + $v['LD']) / $v['PC'] : null, ['soglia' => 'QR']);
        $this->n('CASH_R', $p, 'Cash ratio', 'ratio', 'LI / Passivo corrente', ['LI' => $k('LI'), 'PC' => $k('PC')], fn($v) => $v['PC'] != 0 ? $v['LI'] / $v['PC'] : null, ['soglia' => 'CASH_RATIO']);
        $this->n('CCN', $p, 'Capitale circolante netto (finanziario)', 'eur', 'Attivo corrente − Passivo corrente', ['AC' => $k('AC'), 'PC' => $k('PC')], fn($v) => $v['AC'] - $v['PC'],
            ['eval' => fn($x) => ['level' => $x >= 0 ? 'good' : 'bad', 'text' => $x >= 0 ? 'Positivo' : 'Negativo', 'soglia' => 'Positivo = equilibrio finanziario di breve', 'fonte' => 'formule_kpi.md §6']]);
        $this->n('CCN_RICAVI', $p, 'CCN / Ricavi', 'pct', 'CCN / Ricavi × 100', ['CCN' => $k('CCN'), 'Ricavi' => $k('RICAVI')], fn($v) => $v['Ricavi'] != 0 ? $v['CCN'] / $v['Ricavi'] * 100 : null);
        $this->n('DSO', $p, 'DSO — giorni medi di incasso', 'gg', 'C.II.1 Crediti verso clienti / Ricavi × 365', ['C.II.1' => $d('SP_CII_CLIENTI'), 'Ricavi' => $k('RICAVI')], fn($v) => $v['Ricavi'] > 0 ? $v['C.II.1'] / $v['Ricavi'] * 365 : ['nv' => 'ricavi non positivi'],
            ['soglia' => 'DSO', 'note' => 'I crediti includono l\'IVA mentre i ricavi ne sono al netto: il valore tende a sovrastimare i giorni effettivi.']);
        $this->n('ACQUISTI', $p, 'Acquisti di beni e servizi', 'eur', 'B.6 + B.7 + B.8', ['B.6' => $d('CE_B6'), 'B.7' => $d('CE_B7'), 'B.8' => $d('CE_B8')], fn($v) => array_sum($v));
        $this->n('DPO', $p, 'DPO — giorni medi di pagamento', 'gg', 'D.7 Debiti verso fornitori / Acquisti × 365', ['D.7' => $d('SP_D7'), 'Acquisti' => $k('ACQUISTI')], fn($v) => $v['Acquisti'] > 0 ? $v['D.7'] / $v['Acquisti'] * 365 : ['nv' => 'acquisti non positivi'],
            ['note' => 'Riferimento fisiologico in Italia: 60-90 giorni (formule_kpi.md §6).']);
        $this->n('CONSUMI', $p, 'Consumi di materie e merci', 'eur', 'B.6 + B.11', ['B.6' => $d('CE_B6'), 'B.11' => $d('CE_B11')], fn($v) => $v['B.6'] + $v['B.11']);
        $this->n('DIO', $p, 'DIO — giorni medi di giacenza', 'gg', 'C.I Rimanenze / Consumi × 365', ['C.I' => $d('SP_CI'), 'Consumi' => $k('CONSUMI')], fn($v) => $v['Consumi'] > 0 ? $v['C.I'] / $v['Consumi'] * 365 : ['nv' => 'consumi non positivi'],
            ['note' => 'Indicatore dipendente dal settore: nessuna soglia generale.']);
        $this->n('CCC', $p, 'Cash conversion cycle', 'gg', 'DSO + DIO − DPO', ['DSO' => $k('DSO'), 'DIO' => $k('DIO'), 'DPO' => $k('DPO')], fn($v) => $v['DSO'] + $v['DIO'] - $v['DPO'], ['soglia' => 'CCC']);

        // --- solidità
        $this->n('AUTONOMIA', $p, 'Indice di autonomia finanziaria', 'pct', 'PN / Attivo totale × 100', ['PN' => $k('PN'), 'AT' => $k('AT')], fn($v) => $v['AT'] != 0 ? $v['PN'] / $v['AT'] * 100 : null, ['soglia' => 'AUTONOMIA']);
        $this->n('INDEBITAMENTO', $p, 'Indice di indebitamento', 'pct', '(PC + PCns) / Attivo totale × 100', ['PC' => $k('PC'), 'PCns' => $k('PCNS'), 'AT' => $k('AT')], fn($v) => $v['AT'] != 0 ? ($v['PC'] + $v['PCns']) / $v['AT'] * 100 : null, ['soglia' => 'INDEBITAMENTO']);
        $this->n('PN_TERZI', $p, 'PN / Mezzi di terzi', 'pct', 'PN / (PC + PCns) × 100', ['PN' => $k('PN'), 'PC' => $k('PC'), 'PCns' => $k('PCNS')], fn($v) => ($v['PC'] + $v['PCns']) != 0 ? $v['PN'] / ($v['PC'] + $v['PCns']) * 100 : null, ['soglia' => 'PN_TERZI']);
        $this->n('MS_PRIMARIO', $p, 'Quoziente primario di struttura', 'ratio', 'PN / Attivo immobilizzato', ['PN' => $k('PN'), 'AI' => $k('AI')], fn($v) => $v['AI'] != 0 ? $v['PN'] / $v['AI'] : null, ['soglia' => 'MS_PRIMARIO']);
        $this->n('MS_SECONDARIO', $p, 'Quoziente secondario di struttura', 'ratio', '(PN + PCns) / Attivo immobilizzato', ['PN' => $k('PN'), 'PCns' => $k('PCNS'), 'AI' => $k('AI')], fn($v) => $v['AI'] != 0 ? ($v['PN'] + $v['PCns']) / $v['AI'] : null, ['soglia' => 'MS_SECONDARIO']);

        // --- gestione finanziaria
        $this->n('PFN_EBITDA', $p, 'PFN / EBITDA', 'x', 'PFN / EBITDA', ['PFN' => $k('PFN'), 'EBITDA' => $k('EBITDA')], fn($v) => $v['EBITDA'] > 0 ? $v['PFN'] / $v['EBITDA'] : ['nv' => 'EBITDA non positivo: rapporto non significativo'], ['soglia' => 'PFN_EBITDA']);
        $this->n('PFN_PN', $p, 'PFN / PN', 'x', 'PFN / Patrimonio netto', ['PFN' => $k('PFN'), 'PN' => $k('PN')], fn($v) => $v['PN'] > 0 ? $v['PFN'] / $v['PN'] : ['nv' => 'patrimonio netto non positivo: rapporto non significativo'], ['soglia' => 'PFN_PN']);
        $this->n('LEVERAGE', $p, 'Leverage', 'x', '(PN + PFN) / PN', ['PN' => $k('PN'), 'PFN' => $k('PFN')], fn($v) => $v['PN'] > 0 ? ($v['PN'] + $v['PFN']) / $v['PN'] : ['nv' => 'patrimonio netto non positivo: rapporto non significativo'], ['soglia' => 'LEVERAGE']);
        $this->n('COPERTURA_OF', $p, 'Copertura degli oneri finanziari', 'x', 'EBITDA / Oneri finanziari netti', ['EBITDA' => $k('EBITDA'), 'OF netti' => $k('OF_NETTI')], fn($v) => $v['OF netti'] > 0 ? $v['EBITDA'] / $v['OF netti'] : ['nv' => 'oneri finanziari netti non positivi: rapporto non significativo'], ['soglia' => 'COPERTURA_OF']);
        $this->n('OF_RICAVI', $p, 'Oneri finanziari netti / Ricavi', 'pct', 'Oneri finanziari netti / Ricavi × 100', ['OF netti' => $k('OF_NETTI'), 'Ricavi' => $k('RICAVI')], fn($v) => $v['Ricavi'] != 0 ? $v['OF netti'] / $v['Ricavi'] * 100 : null, ['soglia' => 'OF_RICAVI']);
    }

    private function avg_node(string $key, string $base, string $p, string $label): void
    {
        $prev = $this->prev($p);
        if ($prev !== null) {
            $this->n($key, $p, $label, 'eur', "($base $p + $base $prev) / 2", ["$base $p" => $this->k($base, $p), "$base $prev" => $this->k($base, $prev)],
                fn($v) => ($v["$base $p"] + $v["$base $prev"]) / 2);
        } else {
            $this->n($key, $p, $label, 'eur', "$base di fine esercizio $p", [$base => $this->k($base, $p)], fn($v) => $v[$base],
                ['note' => 'Esercizio precedente non disponibile: utilizzato il valore di fine esercizio anziché la media.']);
        }
    }

    private function averaged(string $p): void
    {
        $this->avg_node('PN_MEDIO', 'PN', $p, 'Patrimonio netto medio');
        $this->avg_node('CIN_MEDIO', 'CIN', $p, 'Capitale investito netto medio');
        $this->avg_node('AT_MEDIO', 'AT', $p, 'Attivo totale medio');
        $this->avg_node('PFN_MEDIA', 'PFN', $p, 'PFN media');
        $k = fn($c) => $this->k($c, $p);
        $this->n('ROE', $p, 'ROE — Return on equity', 'pct', 'Utile / PN medio × 100', ['Utile' => $k('UTILE'), 'PN medio' => $k('PN_MEDIO')], fn($v) => $v['PN medio'] > 0 ? $v['Utile'] / $v['PN medio'] * 100 : ['nv' => 'patrimonio netto medio non positivo'], ['soglia' => 'ROE']);
        $this->n('ROI', $p, 'ROI — Return on investment', 'pct', 'EBIT / CIN medio × 100', ['EBIT' => $k('EBIT'), 'CIN medio' => $k('CIN_MEDIO')], fn($v) => $v['CIN medio'] > 0 ? $v['EBIT'] / $v['CIN medio'] * 100 : ['nv' => 'capitale investito medio non positivo'], ['soglia' => 'ROI']);
        $this->n('ROA', $p, 'ROA — Return on assets', 'pct', 'EBIT / Attivo totale medio × 100', ['EBIT' => $k('EBIT'), 'AT medio' => $k('AT_MEDIO')], fn($v) => $v['AT medio'] > 0 ? $v['EBIT'] / $v['AT medio'] * 100 : null, ['soglia' => 'ROA']);
        $this->n('ROD', $p, 'ROD — Costo medio del debito', 'pct', 'Oneri finanziari netti / PFN media × 100', ['OF netti' => $k('OF_NETTI'), 'PFN media' => $k('PFN_MEDIA')], fn($v) => $v['PFN media'] > 0 ? $v['OF netti'] / $v['PFN media'] * 100 : ['nv' => 'PFN media non positiva (cassa netta): indicatore non significativo'],
            ['note' => 'Confronto con il ROI: se ROD > ROI la leva finanziaria è sfavorevole.']);
        $this->n('LEVA', $p, 'Effetto leva finanziaria', 'pct', '(ROI − ROD) × PFN / PN', ['ROI' => $k('ROI'), 'ROD' => $k('ROD'), 'PFN' => $k('PFN'), 'PN' => $k('PN')], fn($v) => $v['PN'] > 0 ? ($v['ROI'] - $v['ROD']) * $v['PFN'] / $v['PN'] : ['nv' => 'patrimonio netto non positivo'],
            ['eval' => fn($x) => ['level' => $x >= 0 ? 'good' : 'bad', 'text' => $x >= 0 ? 'Leva favorevole' : 'Leva sfavorevole', 'soglia' => 'Positivo = leva favorevole', 'fonte' => 'formule_kpi.md §5']]);

        $prev = $this->prev($p);
        if ($prev !== null) {
            $this->n('VAR_CCN', $p, 'Variazione del CCN', 'eur', "CCN $p − CCN $prev", ["CCN $p" => $this->k('CCN', $p), "CCN $prev" => $this->k('CCN', $prev)], fn($v) => $v["CCN $p"] - $v["CCN $prev"]);
            $this->n('VAR_RICAVI', $p, 'Variazione dei ricavi', 'pct', "(Ricavi $p − Ricavi $prev) / Ricavi $prev × 100", ["Ricavi $p" => $this->k('RICAVI', $p), "Ricavi $prev" => $this->k('RICAVI', $prev)],
                fn($v) => $v["Ricavi $prev"] != 0 ? ($v["Ricavi $p"] - $v["Ricavi $prev"]) / abs($v["Ricavi $prev"]) * 100 : null);
            $this->n('VAR_EBITDA', $p, 'Variazione dell\'EBITDA', 'pct', "(EBITDA $p − EBITDA $prev) / |EBITDA $prev| × 100", ["EBITDA $p" => $this->k('EBITDA', $p), "EBITDA $prev" => $this->k('EBITDA', $prev)],
                fn($v) => $v["EBITDA $prev"] != 0 ? ($v["EBITDA $p"] - $v["EBITDA $prev"]) / abs($v["EBITDA $prev"]) * 100 : null);
        }
    }

    private function bench(): ?array
    {
        $s = $this->state['profile']['settore'] ?? '';
        return $this->P['benchmark'][$s] ?? null;
    }

    private function bench_eval_margin(float $x): ?array
    {
        $b = $this->bench();
        if (!$b) {
            return null;
        }
        [$lo, $hi] = $b['ebitda_margin'];
        $level = $x >= $lo ? 'good' : ($x >= $lo / 2 ? 'warn' : 'bad');
        return ['level' => $level, 'text' => $x > $hi ? 'Sopra il range di settore' : ($x >= $lo ? 'Nel range di settore' : 'Sotto il range di settore'), 'soglia' => "Range atteso {$b['label']}: {$lo}-{$hi}%", 'fonte' => $this->P['benchmark_fonte']];
    }

    public function zmodel(): string
    {
        $m = $this->rp['zscore_model'] ?? 'auto';
        if ($m === 'Z1' || $m === 'Z2') {
            return $m;
        }
        $b = $this->bench();
        return ($b && !empty($b['manifatturiero'])) ? 'Z1' : 'Z2';
    }

    private function zscore(string $p): void
    {
        $k = fn($c) => $this->k($c, $p);
        $d = fn($c) => $this->d($c, $p);
        $this->n('RIS_UTILI', $p, 'Utili non distribuiti (riserve di utili)', 'eur', 'A.IV Riserva legale + A.V Riserve statutarie + A.VI Altre riserve + A.VIII Utili (perdite) portati a nuovo',
            ['A.IV' => $d('SP_PN_IV'), 'A.V' => $d('SP_PN_V'), 'A.VI' => $d('SP_PN_VI'), 'A.VIII' => $d('SP_PN_VIII')], fn($v) => array_sum($v),
            ['note' => 'La voce A.VI può comprendere riserve di capitale (es. versamenti in conto capitale): verificarne la composizione in Nota Integrativa.']);
        $this->n('DEB_TOT', $p, 'Debito totale (mezzi di terzi)', 'eur', 'PC + PCns', ['PC' => $k('PC'), 'PCns' => $k('PCNS')], fn($v) => $v['PC'] + $v['PCns']);
        $this->n('Z_X1', $p, 'X1 = CCN / Attivo totale', 'ratio', 'CCN / AT', ['CCN' => $k('CCN'), 'AT' => $k('AT')], fn($v) => $v['AT'] != 0 ? $v['CCN'] / $v['AT'] : null);
        $this->n('Z_X2', $p, 'X2 = Utili non distribuiti / Attivo totale', 'ratio', 'Riserve di utili / AT', ['Riserve utili' => $k('RIS_UTILI'), 'AT' => $k('AT')], fn($v) => $v['AT'] != 0 ? $v['Riserve utili'] / $v['AT'] : null);
        $this->n('Z_X3', $p, 'X3 = EBIT / Attivo totale', 'ratio', 'EBIT / AT', ['EBIT' => $k('EBIT'), 'AT' => $k('AT')], fn($v) => $v['AT'] != 0 ? $v['EBIT'] / $v['AT'] : null);
        $this->n('Z_X4', $p, 'X4 = PN contabile / Debito totale', 'ratio', 'PN / (PC + PCns)', ['PN' => $k('PN'), 'Debito totale' => $k('DEB_TOT')], fn($v) => $v['Debito totale'] != 0 ? $v['PN'] / $v['Debito totale'] : null);
        $this->n('Z_X5', $p, 'X5 = Ricavi / Attivo totale', 'ratio', 'Ricavi / AT', ['Ricavi' => $k('RICAVI'), 'AT' => $k('AT')], fn($v) => $v['AT'] != 0 ? $v['Ricavi'] / $v['AT'] : null);

        $model = $this->zmodel();
        $zp = $this->P['zscore'][$model];
        $in = [];
        $terms = [];
        foreach ($zp['coeff'] as $x => $c) {
            $in[$x] = $k("Z_$x");
            $terms[] = IR_Format::num($c, 3) . '·' . $x;
        }
        $this->n('ZSCORE', $p, $zp['nome'], 'z', 'Z = ' . implode(' + ', $terms), $in,
            function ($v) use ($zp) {
                $z = 0.0;
                foreach ($zp['coeff'] as $x => $c) {
                    $z += $c * $v[$x];
                }
                return $z;
            },
            ['eval' => function ($z) use ($zp) {
                $level = $z > $zp['safe'] ? 'good' : ($z < $zp['distress'] ? 'bad' : 'warn');
                $txt = ['good' => 'Zona sicura (safe)', 'warn' => 'Zona grigia (grey)', 'bad' => 'Zona di rischio (distress)'][$level];
                return ['level' => $level, 'text' => $txt, 'soglia' => 'Safe > ' . IR_Format::num($zp['safe'], 2) . '; distress < ' . IR_Format::num($zp['distress'], 2), 'fonte' => $this->P['zscore']['fonte']];
            }, 'note' => 'Strumento di screening statistico: va interpretato come indicatore complementare, non sostitutivo di un\'analisi qualitativa.']);
    }

    private function rendiconto(string $p): void
    {
        $d = fn($c) => $this->d($c, $p);
        if (!$this->has('RF_A', $p)) {
            return;
        }
        $this->n('RF_A', $p, 'Flusso finanziario della gestione operativa', 'eur', 'A (dato del rendiconto)', ['A' => $d('RF_A')], fn($v) => $v['A']);
        $this->n('RF_B', $p, 'Flusso finanziario dell\'attività di investimento', 'eur', 'B (dato del rendiconto)', ['B' => $d('RF_B')], fn($v) => $v['B']);
        $this->n('RF_C', $p, 'Flusso finanziario dell\'attività di finanziamento', 'eur', 'C (dato del rendiconto)', ['C' => $d('RF_C')], fn($v) => $v['C']);
        $this->n('FCF', $p, 'Free cash flow operativo (dopo investimenti)', 'eur', 'Flusso A + Flusso B', ['A' => $d('RF_A'), 'B' => $d('RF_B')], fn($v) => $v['A'] + $v['B']);
        $this->n('RF_SOMMA', $p, 'Somma dei flussi A + B + C', 'eur', 'A + B + C', ['A' => $d('RF_A'), 'B' => $d('RF_B'), 'C' => $d('RF_C')], fn($v) => array_sum($v));
        $this->check('Q_RF', 'Rendiconto: A + B + C = variazione delle disponibilità liquide', $p, $this->k('RF_SOMMA', $p), $d('RF_DELTA'));
        $this->check('Q_RF_LIQ', 'Rendiconto: disponibilità liquide finali = C.IV di Stato Patrimoniale', $p, $d('RF_LIQ_FIN'), $d('SP_CIV'));
    }

    private function fiscale(string $p): void
    {
        $k = fn($c) => $this->k($c, $p);
        $this->n('ETR', $p, 'Aliquota fiscale effettiva (ETR)', 'pct', 'Imposte (voce 20) / Risultato prima delle imposte × 100', ['Imposte' => $k('IMPOSTE'), 'EBT' => $k('EBT')],
            fn($v) => $v['EBT'] > 0 ? $v['Imposte'] / $v['EBT'] * 100 : ['nv' => 'risultato ante imposte non positivo: aliquota non significativa'],
            ['eval' => fn($x) => ['level' => ($x > 35 || $x < 20) ? 'warn' : 'good', 'text' => $x > 35 ? 'Superiore al 35%: da approfondire' : ($x < 20 ? 'Inferiore al 20%: da approfondire' : 'In linea con le aliquote nominali'), 'soglia' => 'Range di normalità 20-35% (24% IRES + IRAP)', 'fonte' => 'formule_kpi.md §13']]);
        foreach (['FISC_IRES' => 'IRES dovuta', 'FISC_IRAP' => 'IRAP dovuta'] as $c => $l) {
            if ($this->has($c, $p)) {
                $this->n($c, $p, $l, 'eur', "$l (dichiarazione)", [$l => $this->d($c, $p)], fn($v) => $v[$l]);
            }
        }
    }

    private function latest(string $code, string $regex = '/./'): ?string
    {
        $best = null;
        foreach ($this->data as $e) {
            if ($e['code'] === $code && $e['value'] !== null && preg_match($regex, $e['period'])) {
                if ($best === null || strcmp($e['period'], $best) > 0) {
                    $best = $e['period'];
                }
            }
        }
        return $best;
    }

    private function centrale_rischi(): void
    {
        foreach (IR_Dataset::periods_matching($this->data, '/^CR_/', '/^\d{4}-\d{2}$/') as $p) {
            $d = fn($c) => $this->d($c, $p);
            $this->n('CR_UTILIZZO', $p, 'Utilizzo / accordato — totale', 'pct', 'Utilizzato totale / Accordato operativo totale × 100', ['Utilizzato' => $d('CR_UTI_TOT'), 'Accordato' => $d('CR_ACC_TOT')], fn($v) => $v['Accordato'] > 0 ? $v['Utilizzato'] / $v['Accordato'] * 100 : ['nv' => 'accordato nullo'], ['soglia' => 'UTILIZZO_FIDI']);
            foreach (['REVOCA' => 'rischi a revoca', 'AUTOLIQ' => 'rischi autoliquidanti', 'SCADENZA' => 'rischi a scadenza'] as $c => $l) {
                if ($this->has("CR_ACC_$c", $p)) {
                    $this->n("CR_UTILIZZO_$c", $p, "Utilizzo / accordato — $l", 'pct', "Utilizzato / Accordato operativo ($l) × 100", ['Utilizzato' => $d("CR_UTI_$c"), 'Accordato' => $d("CR_ACC_$c")], fn($v) => $v['Accordato'] > 0 ? $v['Utilizzato'] / $v['Accordato'] * 100 : ['nv' => 'accordato nullo'], ['soglia' => 'UTILIZZO_FIDI']);
                }
            }
            foreach (['CR_SCONFINO' => 'Sconfinamento', 'CR_SOFFERENZE' => 'Sofferenze', 'CR_ACC_TOT' => 'Accordato operativo', 'CR_UTI_TOT' => 'Utilizzato'] as $c => $l) {
                if ($this->has($c, $p)) {
                    $this->n($c, $p, $l, 'eur', "$l (Centrale Rischi)", [$l => $d($c)], fn($v) => $v[$l],
                        in_array($c, ['CR_SCONFINO', 'CR_SOFFERENZE'], true) ? ['eval' => fn($x) => ['level' => $x > 0 ? 'bad' : 'good', 'text' => $x > 0 ? 'Presente' : 'Assente', 'soglia' => 'Qualsiasi importo positivo è un segnale di tensione', 'fonte' => 'Prassi di analisi andamentale']] : []);
                }
            }
        }
    }

    private function aging(): void
    {
        foreach (IR_Dataset::periods_matching($this->data, '/^AGING_/', '/./') as $p) {
            $d = fn($c) => $this->d($c, $p);
            if ($this->has('AGING_CLI_TOT', $p)) {
                $this->n('CLI_SCADUTO_PCT', $p, 'Crediti scaduti / crediti aperti', 'pct', 'Scaduto clienti / Totale crediti aperti × 100', ['Scaduto' => $d('AGING_CLI_SCADUTO'), 'Totale' => $d('AGING_CLI_TOT')], fn($v) => $v['Totale'] > 0 ? $v['Scaduto'] / $v['Totale'] * 100 : null);
                $this->n('CLI_SC90_PCT', $p, 'Crediti scaduti oltre 90 gg / crediti aperti', 'pct', 'Scaduto clienti > 90 gg / Totale crediti aperti × 100', ['Scaduto >90' => $d('AGING_CLI_SCADUTO_90'), 'Totale' => $d('AGING_CLI_TOT')], fn($v) => $v['Totale'] > 0 ? $v['Scaduto >90'] / $v['Totale'] * 100 : null);
            }
            if ($this->has('AGING_FOR_TOT', $p)) {
                $this->n('FOR_SCADUTO_PCT', $p, 'Debiti verso fornitori scaduti / debiti aperti', 'pct', 'Scaduto fornitori / Totale debiti aperti × 100', ['Scaduto' => $d('AGING_FOR_SCADUTO'), 'Totale' => $d('AGING_FOR_TOT')], fn($v) => $v['Totale'] > 0 ? $v['Scaduto'] / $v['Totale'] * 100 : null);
            }
        }
    }

    private function forma_class(): string
    {
        $f = $this->state['profile']['forma_giuridica'] ?? '';
        if ($f === 'Ditta individuale') {
            return 'individuale';
        }
        if (in_array($f, ['S.n.c.', 'S.a.s.'], true)) {
            return 'persone';
        }
        return 'capitali';
    }

    private function ccii(): void
    {
        $c = $this->P['ccii'];
        $fonte = $c['fonte'];
        $sig = fn(bool $on, string $soglia) => ['level' => $on ? 'bad' : 'good', 'text' => $on ? 'Segnale attivo' : 'Nessun segnale', 'soglia' => $soglia, 'fonte' => $fonte];
        $list = [];

        $pr = $this->latest('CCII_RETRIB_SCADUTE_30');
        $pm = $this->latest('CCII_MONTE_SALARI_MENSILE');
        if ($pr && $pm) {
            $this->n('CCII_RETRIB', $pr, 'Debiti per retribuzioni scaduti da almeno 30 gg / monte salari mensile', 'pct', 'Retribuzioni scadute ≥ 30 gg / Monte salari mensile × 100',
                ['Retribuzioni scadute' => $this->d('CCII_RETRIB_SCADUTE_30', $pr), 'Monte salari' => $this->d('CCII_MONTE_SALARI_MENSILE', $pm)],
                fn($v) => $v['Monte salari'] > 0 ? $v['Retribuzioni scadute'] / $v['Monte salari'] * 100 : null,
                ['eval' => fn($x) => $sig($x > $c['retribuzioni_quota'] * 100, 'Segnale se oltre il ' . ($c['retribuzioni_quota'] * 100) . '% (art. 3, c. 4, lett. a)'), 'note' => $pr !== $pm ? "Dati riferiti a date diverse ($pr e $pm)." : '']);
            $list[] = "CCII_RETRIB@$pr";
        }
        $pf = $this->latest('AGING_FOR_SCADUTO_90');
        if ($pf) {
            $this->n('CCII_FORN', $pf, 'Debiti verso fornitori scaduti da almeno 90 gg rispetto ai debiti non scaduti', 'eur', 'Scaduto fornitori ≥ 90 gg − Debiti verso fornitori non scaduti',
                ['Scaduto ≥90' => $this->d('AGING_FOR_SCADUTO_90', $pf), 'Non scaduto' => $this->d('AGING_FOR_NON_SCADUTO', $pf)],
                fn($v) => $v['Scaduto ≥90'] - $v['Non scaduto'],
                ['eval' => fn($x) => $sig($x > 0, 'Segnale se lo scaduto ≥ 90 gg supera i debiti non scaduti (art. 3, c. 4, lett. b)'), 'note' => 'Valore positivo = scaduto superiore al non scaduto.']);
            $list[] = "CCII_FORN@$pf";
        }
        $pb = $this->latest('CCII_BANCHE_SCADUTE_60');
        $pbt = $this->latest('CCII_BANCHE_TOT');
        if ($pb && $pbt) {
            $this->n('CCII_BANCHE', $pb, 'Esposizioni bancarie scadute/sconfinanti da oltre 60 gg / totale esposizioni', 'pct', 'Esposizioni scadute > 60 gg / Totale esposizioni × 100',
                ['Scadute >60' => $this->d('CCII_BANCHE_SCADUTE_60', $pb), 'Totale' => $this->d('CCII_BANCHE_TOT', $pbt)],
                fn($v) => $v['Totale'] > 0 ? $v['Scadute >60'] / $v['Totale'] * 100 : null,
                ['eval' => fn($x) => $sig($x >= $c['banche_quota'] * 100, 'Segnale se almeno il ' . ($c['banche_quota'] * 100) . '% del totale (art. 3, c. 4, lett. c)')]);
            $list[] = "CCII_BANCHE@$pb";
        }
        $pi = $this->latest('CCII_INPS_SCADUTO_90');
        $pid = $this->latest('CCII_INPS_DOVUTI_ANNO_PREC');
        if ($pi && $pid) {
            $this->n('CCII_INPS', $pi, 'Contributi INPS non versati da oltre 90 gg', 'eur', 'Contributi scaduti > 90 gg (confronto con ' . ($c['inps_quota'] * 100) . '% dei contributi dell\'anno precedente e con ' . IR_Format::num($c['inps_minimo'], 0) . ' €)',
                ['Scaduto INPS' => $this->d('CCII_INPS_SCADUTO_90', $pi), 'Contributi anno prec.' => $this->d('CCII_INPS_DOVUTI_ANNO_PREC', $pid), 'Quota' => $this->param('Quota soglia', $c['inps_quota'], $fonte), 'Minimo' => $this->param('Soglia assoluta (€)', $c['inps_minimo'], $fonte)],
                fn($v) => $v['Scaduto INPS'],
                ['eval' => function ($x) use ($sig, $c, $pid) {
                    $base = $this->data["CCII_INPS_DOVUTI_ANNO_PREC@$pid"]['value'] ?? 0;
                    return $sig($x > $c['inps_quota'] * $base && $x > $c['inps_minimo'], 'Segnale se superiore al ' . ($c['inps_quota'] * 100) . '% dei contributi dell\'anno precedente e a ' . IR_Format::num($c['inps_minimo'], 0) . ' € (art. 25-novies, c. 1, lett. a — imprese con dipendenti)');
                }]);
            $list[] = "CCII_INPS@$pi";
        }
        $pn = $this->latest('CCII_INAIL_SCADUTO_90');
        if ($pn) {
            $this->n('CCII_INAIL', $pn, 'Premi INAIL scaduti da oltre 90 gg', 'eur', 'Premi INAIL scaduti > 90 gg', ['Scaduto INAIL' => $this->d('CCII_INAIL_SCADUTO_90', $pn)], fn($v) => $v['Scaduto INAIL'],
                ['eval' => fn($x) => $sig($x > $c['inail_minimo'], 'Segnale se superiore a ' . IR_Format::num($c['inail_minimo'], 0) . ' € (art. 25-novies, c. 1, lett. b)')]);
            $list[] = "CCII_INAIL@$pn";
        }
        $pv = $this->latest('CCII_IVA_SCADUTA');
        if ($pv) {
            $pva = $this->latest('FISC_VOLUME_AFFARI');
            $in = ['IVA scaduta' => $this->d('CCII_IVA_SCADUTA', $pv)];
            if ($pva) {
                $in['Volume d\'affari'] = $this->d('FISC_VOLUME_AFFARI', $pva);
            }
            $this->n('CCII_IVA', $pv, 'Debito IVA scaduto e non versato', 'eur', 'Debito IVA da LIPE (confronto con ' . IR_Format::num($c['iva_minimo'], 0) . ' € e ' . ($c['iva_quota_volume_affari'] * 100) . '% del volume d\'affari; soglia assoluta ' . IR_Format::num($c['iva_soglia_assoluta'], 0) . ' €)', $in, fn($v) => $v['IVA scaduta'],
                ['eval' => function ($x) use ($sig, $c, $pva) {
                    $va = $pva ? ($this->data["FISC_VOLUME_AFFARI@$pva"]['value'] ?? null) : null;
                    $on = $x > $c['iva_soglia_assoluta'] || ($va !== null && $x > $c['iva_minimo'] && $x >= $c['iva_quota_volume_affari'] * $va);
                    $s = $sig($on, 'Segnale se > ' . IR_Format::num($c['iva_minimo'], 0) . ' € e ≥ ' . ($c['iva_quota_volume_affari'] * 100) . '% del volume d\'affari, o comunque > ' . IR_Format::num($c['iva_soglia_assoluta'], 0) . ' € (art. 25-novies, c. 1, lett. c)');
                    if ($va === null && !$on) {
                        $s['level'] = 'warn';
                        $s['text'] = 'Verifica incompleta: volume d\'affari non disponibile';
                    }
                    return $s;
                }]);
            $list[] = "CCII_IVA@$pv";
        }
        $pa = $this->latest('CCII_ADER_SCADUTO_90');
        if ($pa) {
            $cls = $this->forma_class();
            $soglia = (float) $c['ader_soglie'][$cls];
            $this->n('CCII_ADER', $pa, 'Debiti affidati ad AdER scaduti da oltre 90 gg', 'eur', 'Crediti affidati all\'agente della riscossione scaduti > 90 gg', ['Scaduto AdER' => $this->d('CCII_ADER_SCADUTO_90', $pa), 'Soglia' => $this->param("Soglia per $cls", $soglia, $fonte)], fn($v) => $v['Scaduto AdER'],
                ['eval' => fn($x) => $sig($x > $soglia, 'Segnale se superiore a ' . IR_Format::num($soglia, 0) . ' € (art. 25-novies, c. 1, lett. d — ' . $cls . ')')]);
            $list[] = "CCII_ADER@$pa";
        }

        $active = 0;
        $in = [];
        foreach ($list as $id) {
            $n = $this->nodes[$id];
            if (($n['eval']['level'] ?? '') === 'bad') {
                $active++;
            }
            $in[$n['key']] = ['ref' => "K:$id", 'label' => $n['label'], 'value' => $n['value'], 'why' => null];
        }
        $last = $this->annual ? end($this->annual) : 'n.d.';
        if ($list) {
            $this->n('CCII_SEGNALI', 'tot', 'Segnali di allarme CCII attivi', 'num', 'Conteggio delle verifiche con esito "segnale attivo"', $in, fn() => (float) $active,
                ['eval' => fn($x) => ['level' => $x > 0 ? 'bad' : 'good', 'text' => $x > 0 ? 'Segnali attivi' : 'Nessun segnale attivo sulle verifiche eseguibili', 'soglia' => 'Qualsiasi segnale attivo', 'fonte' => $fonte],
                 'note' => 'Il conteggio riguarda solo le verifiche per cui erano disponibili i documenti necessari (' . count($list) . ' verifiche).']);
        }
    }

    private function dscr_and_stress(): void
    {
        $pp = $this->latest('PROSP_EBITDA');
        $pq = $this->latest('FIN_QUOTA_12M');
        $pi = $this->latest('FIN_INTERESSI_12M');
        if ($pq && $pi) {
            $this->n('SERV_DEBITO', 'prosp', 'Servizio del debito a 12 mesi', 'eur', 'Quote capitale in scadenza a 12 mesi + Interessi a 12 mesi',
                ['Quote capitale' => $this->d('FIN_QUOTA_12M', $pq), 'Interessi' => $this->d('FIN_INTERESSI_12M', $pi)], fn($v) => $v['Quote capitale'] + $v['Interessi']);
        }
        if ($pp) {
            $pt = $this->latest('PROSP_IMPOSTE') ?? $pp;
            $pc = $this->latest('PROSP_DELTA_CCN') ?? $pp;
            $this->n('FLUSSI_OP', 'prosp', 'Flussi finanziari operativi a 12 mesi', 'eur', 'EBITDA prospettico − Imposte stimate − Variazione del CCN prevista',
                ['EBITDA prosp.' => $this->d('PROSP_EBITDA', $pp), 'Imposte' => $this->d('PROSP_IMPOSTE', $pt), 'Var. CCN' => $this->d('PROSP_DELTA_CCN', $pc)],
                fn($v) => $v['EBITDA prosp.'] - $v['Imposte'] - $v['Var. CCN']);
            $this->n('DSCR', 'prosp', 'DSCR a 12 mesi prospettico', 'ratio', 'Flussi finanziari operativi a 12 mesi / Servizio del debito a 12 mesi',
                ['Flussi operativi' => $this->k('FLUSSI_OP', 'prosp'), 'Servizio debito' => $this->k('SERV_DEBITO', 'prosp')],
                fn($v) => $v['Servizio debito'] > 0 ? $v['Flussi operativi'] / $v['Servizio debito'] : ['nv' => 'servizio del debito nullo'],
                ['soglia' => 'DSCR', 'note' => 'Approccio 1 CNDCEC. Soglia di prassi del modello: ≥ 1,1 adeguato; tra 1,0 e 1,1 attenzione; < 1,0 segnale di allarme.']);
        }
        $pcash = $this->latest('PROSP_CASSA_INIZIALE');
        $pflow = $this->latest('PROSP_FLUSSI_OPERATIVI_12M');
        if ($pcash && $pflow) {
            $this->n('DSCR2', 'prosp', 'DSCR a 12 mesi — approccio 2', 'ratio', '(Cassa iniziale + Flussi operativi netti previsti) / Servizio del debito',
                ['Cassa iniziale' => $this->d('PROSP_CASSA_INIZIALE', $pcash), 'Flussi netti' => $this->d('PROSP_FLUSSI_OPERATIVI_12M', $pflow), 'Servizio debito' => $this->k('SERV_DEBITO', 'prosp')],
                fn($v) => $v['Servizio debito'] > 0 ? ($v['Cassa iniziale'] + $v['Flussi netti']) / $v['Servizio debito'] : null,
                ['eval' => fn($x) => ['level' => $x >= 1 ? 'good' : 'bad', 'text' => $x >= 1 ? 'Copertura sufficiente' : 'Segnale di allarme', 'soglia' => 'Critico se < 1,0', 'fonte' => 'formule_kpi.md §9 (approccio 2)']]);
        }

        // stress test
        if ($pp) {
            $last = $this->annual ? end($this->annual) : null;
            foreach ($this->P['stress']['scenari'] as $i => $s) {
                $sid = 'S' . $i;
                $shock = (float) $s['shock_ebitda'];
                $this->n('STRESS_EBITDA', $sid, "EBITDA — scenario {$s['nome']}", 'eur', 'EBITDA prospettico × (1 + shock)',
                    ['EBITDA prosp.' => $this->d('PROSP_EBITDA', $pp), 'Shock' => $this->param('Shock sull\'EBITDA', $shock, $this->P['stress']['fonte'])], fn($v) => $v['EBITDA prosp.'] * (1 + $v['Shock']));
                $pt = $this->latest('PROSP_IMPOSTE') ?? $pp;
                $pc = $this->latest('PROSP_DELTA_CCN') ?? $pp;
                $this->n('STRESS_DSCR', $sid, "DSCR — scenario {$s['nome']}", 'ratio', '(EBITDA scenario − Imposte stimate − Var. CCN) / Servizio del debito',
                    ['EBITDA scenario' => $this->k('STRESS_EBITDA', $sid), 'Imposte' => $this->d('PROSP_IMPOSTE', $pt), 'Var. CCN' => $this->d('PROSP_DELTA_CCN', $pc), 'Servizio debito' => $this->k('SERV_DEBITO', 'prosp')],
                    fn($v) => $v['Servizio debito'] > 0 ? ($v['EBITDA scenario'] - $v['Imposte'] - $v['Var. CCN']) / $v['Servizio debito'] : null,
                    ['soglia' => 'DSCR', 'note' => 'Imposte e variazione del CCN mantenute invariate rispetto al piano (ipotesi semplificativa e prudenziale).']);
                if ($last) {
                    $this->n('STRESS_PFN_EBITDA', $sid, "PFN / EBITDA — scenario {$s['nome']}", 'x', "PFN $last / EBITDA scenario",
                        ['PFN' => $this->k('PFN', $last), 'EBITDA scenario' => $this->k('STRESS_EBITDA', $sid)],
                        fn($v) => $v['EBITDA scenario'] > 0 ? $v['PFN'] / $v['EBITDA scenario'] : ['nv' => 'EBITDA di scenario non positivo'], ['soglia' => 'PFN_EBITDA']);
                }
            }
        }
    }

    private function debito(): void
    {
        $pr = $this->latest('FIN_RESIDUO_TOT');
        if (!$pr) {
            return;
        }
        $this->n('FIN_RESIDUO', $pr, 'Debito residuo dei finanziamenti', 'eur', 'Debito residuo (piani di ammortamento)', ['Residuo' => $this->d('FIN_RESIDUO_TOT', $pr)], fn($v) => $v['Residuo']);
        foreach (['FIN_QUOTA_12M' => 'entro 12 mesi', 'FIN_QUOTA_1_5' => 'tra 1 e 5 anni', 'FIN_QUOTA_OLTRE5' => 'oltre 5 anni'] as $c => $l) {
            $pc = $this->latest($c);
            if ($pc) {
                $this->n("{$c}_PCT", $pr, "Quota capitale $l / debito residuo", 'pct', "Quote capitale $l / Debito residuo × 100", ['Quote' => $this->d($c, $pc), 'Residuo' => $this->d('FIN_RESIDUO_TOT', $pr)], fn($v) => $v['Residuo'] > 0 ? $v['Quote'] / $v['Residuo'] * 100 : null);
            }
        }
        $last = $this->annual ? end($this->annual) : null;
        if ($last && isset($this->nodes['SERV_DEBITO@prosp'])) {
            $this->n('COPERTURA_SERV', $last, 'Copertura del servizio del debito con l\'EBITDA storico', 'x', "EBITDA $last / Servizio del debito a 12 mesi",
                ['EBITDA' => $this->k('EBITDA', $last), 'Servizio debito' => $this->k('SERV_DEBITO', 'prosp')], fn($v) => $v['Servizio debito'] > 0 ? $v['EBITDA'] / $v['Servizio debito'] : null,
                ['eval' => fn($x) => ['level' => $x >= 1.1 ? 'good' : ($x >= 1 ? 'warn' : 'bad'), 'text' => $x >= 1.1 ? 'Copertura adeguata' : ($x >= 1 ? 'Copertura al limite' : 'Copertura insufficiente'), 'soglia' => 'Adeguata se ≥ 1,1', 'fonte' => 'Soglia di prassi del modello (analoga al DSCR)']]);
        }
    }

    private function zes(): void
    {
        $pi = $this->latest('INV_TOTALE');
        if (!$pi) {
            return;
        }
        $z = $this->P['zes'];
        $dim = $this->state['profile']['dimensione'] ?? 'piccola';
        $aliq = (float) ($z['aliquote'][$dim] ?? 0.4);
        $rip = isset($this->rp['riparto_zes']) && $this->rp['riparto_zes'] !== null && $this->rp['riparto_zes'] !== '' ? (float) $this->rp['riparto_zes'] / 100 : (float) $z['riparto_default'];
        $inv = $this->d('INV_TOTALE', $pi);

        $this->n('ZES_REQ_MIN', $pi, 'Verifica investimento minimo ZES', 'eur', 'Investimento previsto (confronto con la soglia minima di ' . IR_Format::num($z['investimento_minimo'], 0) . ' €)',
            ['Investimento' => $inv, 'Soglia minima' => $this->param('Investimento minimo per progetto', (float) $z['investimento_minimo'], $z['fonte'])], fn($v) => $v['Investimento'],
            ['eval' => fn($x) => ['level' => $x >= $z['investimento_minimo'] ? 'good' : 'bad', 'text' => $x >= $z['investimento_minimo'] ? 'Soglia minima rispettata' : 'Sotto la soglia minima: credito non spettante', 'soglia' => 'Minimo ' . IR_Format::num($z['investimento_minimo'], 0) . ' €', 'fonte' => $z['fonte']]]);
        $pim = $this->latest('INV_IMMOBILI');
        if ($pim) {
            $this->n('ZES_QUOTA_IMMOBILI', $pi, 'Quota immobili sull\'investimento', 'pct', 'Investimento in immobili / Investimento totale × 100', ['Immobili' => $this->d('INV_IMMOBILI', $pim), 'Investimento' => $inv], fn($v) => $v['Investimento'] > 0 ? $v['Immobili'] / $v['Investimento'] * 100 : null,
                ['eval' => fn($x) => ['level' => $x <= $z['quota_max_immobili'] * 100 ? 'good' : 'bad', 'text' => $x <= $z['quota_max_immobili'] * 100 ? 'Entro il limite' : 'Oltre il limite: quota eccedente non agevolabile', 'soglia' => 'Massimo ' . ($z['quota_max_immobili'] * 100) . '% dell\'investimento agevolato', 'fonte' => $z['fonte']]]);
        }
        $this->n('ZES_TEORICO', $pi, 'Credito d\'imposta ZES teorico', 'eur', 'Investimento ammissibile × Aliquota nominale',
            ['Investimento' => $inv, 'Aliquota' => $this->param('Aliquota nominale (' . (IR_Catalog::DIMENSIONI[$dim] ?? $dim) . ', Sicilia)', $aliq, $z['fonte'])], fn($v) => $v['Investimento'] * $v['Aliquota'],
            ['note' => 'L\'intero investimento indicato nel piano è assunto come ammissibile: l\'ammissibilità delle singole spese va verificata.']);
        $this->n('ZES_ATTESO', $pi, 'Credito d\'imposta ZES atteso effettivo', 'eur', 'Credito teorico × Coefficiente di riparto stimato',
            ['Credito teorico' => $this->k('ZES_TEORICO', $pi), 'Riparto' => $this->param('Coefficiente di riparto stimato', $rip, isset($this->rp['riparto_zes']) && $this->rp['riparto_zes'] !== null && $this->rp['riparto_zes'] !== '' ? 'Indicato dal consulente' : $z['fonte'])], fn($v) => $v['Credito teorico'] * $v['Riparto']);
        $this->n('ZES_NETTO', $pi, 'Beneficio netto dopo le imposte', 'eur', 'Credito atteso × (1 − IRES − quota IRAP)',
            ['Credito atteso' => $this->k('ZES_ATTESO', $pi), 'IRES' => $this->param('Aliquota IRES', (float) $z['ires'], 'Art. 77 TUIR'), 'IRAP' => $this->param('Quota IRAP stimata', (float) $z['irap_quota'], $z['fonte'])],
            fn($v) => $v['Credito atteso'] * (1 - $v['IRES'] - $v['IRAP']), ['note' => 'Approssimazione: l\'effetto IRAP dipende dal valore della produzione netto.']);
        $this->n('ZES_RECUPERO', $pi, 'Recupero effettivo sull\'investimento', 'pct', 'Beneficio netto / Investimento × 100', ['Beneficio netto' => $this->k('ZES_NETTO', $pi), 'Investimento' => $inv], fn($v) => $v['Investimento'] > 0 ? $v['Beneficio netto'] / $v['Investimento'] * 100 : null);
    }

    private function rating(): void
    {
        if (!$this->annual) {
            return;
        }
        $p = end($this->annual);
        $R = $this->P['rating'];
        $scale = $R['scala'];
        $cls = function (float $v, array $def) use ($scale): int {
            foreach ($def['limiti'] as $i => $lim) {
                if (($def['dir'] === 'high' && $v > $lim) || ($def['dir'] === 'low' && $v < $lim)) {
                    return $i;
                }
            }
            return 8; // C
        };
        $items = ['EBITDA_MARGIN' => "EBITDA_M@$p", 'PFN_EBITDA' => "PFN_EBITDA@$p", 'AUTONOMIA' => "AUTONOMIA@$p"];
        if ($this->zmodel() === 'Z1') {
            $items['ZSCORE_Z1'] = "ZSCORE@$p";
        }
        if (isset($this->nodes['DSCR@prosp'])) {
            $items['DSCR'] = 'DSCR@prosp';
        }
        $in = [];
        $idx = [];
        foreach ($items as $rk => $nid) {
            $n = $this->nodes[$nid] ?? null;
            $val = $n['value'] ?? null;
            $in[$rk] = ['ref' => "K:$nid", 'label' => $n['label'] ?? $rk, 'value' => $val, 'why' => 'indicatore non valutabile'];
            if ($val !== null) {
                $i = $cls($val, $R[$rk]);
                $idx[$rk] = $i;
                $this->n("RATING_$rk", $p, 'Classe di rating — ' . ($n['label'] ?? $rk), 'rating', 'Classe della tabella di rating per il valore dell\'indicatore', [$rk => $in[$rk]], fn() => (float) $i,
                    ['display' => fn($x) => $scale[(int) $x]]);
            }
        }
        // rimuove gli indicatori non valutabili dagli ingressi del rating finale (sono elencati nella nota)
        $nv = array_keys(array_filter($in, fn($i) => $i['value'] === null));
        $usable = array_filter($in, fn($i) => $i['value'] !== null);
        if (count($usable) < 2) {
            return;
        }
        $rin = [];
        foreach (array_keys($usable) as $rk) {
            $rin["Classe $rk"] = $this->k("RATING_$rk", $p);
        }
        $ccii = $this->nodes['CCII_SEGNALI@tot']['value'] ?? null;
        $pn = $this->nodes["PN@$p"]['value'] ?? null;
        if ($ccii !== null) {
            $rin['Segnali CCII'] = $this->k('CCII_SEGNALI', 'tot');
        }
        $this->n('RATING', $p, 'Rating interno sintetico', 'rating', 'Mediana delle classi per indicatore (in caso di numero pari, la classe peggiore tra le due centrali); −1 classe con segnali CCII attivi; −2 classi con PN negativo',
            $rin,
            function () use ($idx, $ccii, $pn) {
                $v = array_values($idx);
                sort($v);
                $m = $v[intdiv(count($v), 2)]; // per numero pari prende la centrale peggiore (indice più alto)
                if ($ccii !== null && $ccii > 0) {
                    $m += 1;
                }
                if ($pn !== null && $pn < 0) {
                    $m += 2;
                }
                return (float) min($m, 9);
            },
            ['display' => fn($x) => $scale[(int) $x],
             'note' => ($nv ? 'Indicatori non valutabili esclusi: ' . implode(', ', $nv) . '. ' : '') . ($this->zmodel() === 'Z2' ? 'Lo Z\'\'-Score non è incluso perché la tabella di rating è tarata sullo Z\'-Score. ' : '') . 'Giudizio con valenza esclusivamente consulenziale e gestionale.',
             'eval' => fn($x) => ['level' => $x <= 3 ? 'good' : ($x <= 5 ? 'warn' : 'bad'), 'text' => $scale[(int) $x], 'soglia' => 'Scala AAA → D', 'fonte' => $R['fonte']]]);
    }
}
