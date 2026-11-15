<?php
/**
 * Consolidamento dei dati estratti.
 * Per ogni coppia codice@periodo:
 *  - un solo valore, o più fonti concordi → valore accettato (con numero di conferme);
 *  - fonti discordanti, voci di bilancio annuali → si applicano due regole contabili fisse:
 *      1. saldi riesposti: se il bilancio di un esercizio successivo riporta, nella colonna
 *         comparativa, un valore diverso da quello del fascicolo originario, prevale il bilancio
 *         più recente (i comparativi riclassificati sostituiscono i saldi originari);
 *      2. prospetto ufficiale: a parità di bilancio, il valore letto nel prospetto prevale su
 *         quello letto in Nota Integrativa.
 *    Il dato risolto così è marcato "auto" con la regola applicata e i valori scartati;
 *  - fonti discordanti negli altri casi → CONFLITTO: il valore resta nullo finché il consulente non sceglie;
 *  - correzione manuale → ammessa solo con nota obbligatoria; il valore originale resta in traccia.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Dataset
{
    public const TOLERANCE = 0.5; // euro

    /** @return array<string,array> chiave "CODE@PERIOD" */
    public static function resolve(array $state): array
    {
        $dict = IR_Catalog::data_dictionary();
        $groups = [];
        foreach ($state['extraction']['candidates'] as $c) {
            $groups[$c['code'] . '@' . $c['period']][] = $c;
        }
        foreach ($state['overrides'] as $key => $ov) {
            if (!isset($groups[$key]) && ($ov['type'] ?? '') === 'manual') {
                $groups[$key] = [];
            }
        }

        $docYear = self::document_years($state['extraction']['candidates']);

        $out = [];
        foreach ($groups as $key => $cands) {
            [$code, $period] = explode('@', $key, 2);
            if (!isset($dict[$code])) {
                continue;
            }
            $ov = $state['overrides'][$key] ?? null;
            $entry = [
                'key' => $key,
                'code' => $code,
                'period' => $period,
                'label' => $dict[$code]['label'],
                'unit' => $dict[$code]['unit'],
                'candidates' => $cands,
                'value' => null,
                'source' => null,
                'status' => 'ok',
                'confirmations' => 0,
                'override' => $ov,
            ];

            if ($ov && $ov['type'] === 'manual') {
                $entry['value'] = (float) $ov['value'];
                $entry['status'] = 'manual';
                $entry['source'] = ['manual' => true, 'note' => $ov['note'], 'by' => $ov['by'], 'at' => $ov['at']];
            } elseif ($ov && $ov['type'] === 'exclude') {
                $entry['status'] = 'excluded';
            } elseif ($ov && $ov['type'] === 'choice') {
                foreach ($cands as $c) {
                    if ($c['id'] === $ov['candidate']) {
                        $entry['value'] = (float) $c['value'];
                        $entry['source'] = $c;
                        $entry['status'] = 'chosen';
                    }
                }
                if ($entry['source'] === null) {
                    $entry['status'] = 'conflict';
                }
            } else {
                $values = array_map(static fn($c) => (float) $c['value'], $cands);
                $distinct = [];
                foreach ($values as $v) {
                    $found = false;
                    foreach ($distinct as $d) {
                        if (abs($d - $v) <= self::TOLERANCE) {
                            $found = true;
                        }
                    }
                    if (!$found) {
                        $distinct[] = $v;
                    }
                }
                if (count($distinct) === 1 && $cands) {
                    // preferisce la fonte "rilevato" con affidabilità più alta
                    usort($cands, static function ($a, $b) {
                        $rank = ['alta' => 0, 'media' => 1, 'bassa' => 2];
                        $sa = ($a['stato'] === 'rilevato' ? 0 : 10) + $rank[$a['aff']];
                        $sb = ($b['stato'] === 'rilevato' ? 0 : 10) + $rank[$b['aff']];
                        return $sa <=> $sb;
                    });
                    $entry['value'] = (float) $cands[0]['value'];
                    $entry['source'] = $cands[0];
                    $entry['confirmations'] = count($cands);
                    if ($cands[0]['aff'] === 'bassa' && !($ov && $ov['type'] === 'confirm')) {
                        $entry['status'] = 'to_verify';
                    }
                } elseif (count($distinct) > 1) {
                    $auto = self::auto_resolve($code, $period, $cands, $docYear);
                    if ($auto) {
                        $entry['value'] = (float) $auto['cand']['value'];
                        $entry['source'] = $auto['cand'];
                        $entry['status'] = 'auto';
                        $entry['confirmations'] = $auto['confirmations'];
                        $entry['auto'] = ['rule' => $auto['rule'], 'note' => $auto['note'], 'discarded' => $auto['discarded']];
                    } else {
                        $entry['status'] = 'conflict';
                    }
                }
            }
            if ($ov && $ov['type'] === 'confirm') {
                $entry['verified'] = ['by' => $ov['by'], 'at' => $ov['at']];
                if ($entry['status'] === 'to_verify') {
                    $entry['status'] = 'ok';
                }
            }
            $out[$key] = $entry;
        }
        ksort($out);
        return $out;
    }

    /** Esercizio di riferimento di ciascun file di bilancio: l'anno più recente tra i suoi valori di SP/CE/RF. */
    private static function document_years(array $candidates): array
    {
        $years = [];
        foreach ($candidates as $c) {
            if (in_array($c['slot'] ?? '', ['B1', 'B2', 'B6'], true) && preg_match('/^(SP|CE|RF)_/', $c['code']) && preg_match('/^\d{4}$/', (string) $c['period'])) {
                $years[$c['file_id']] = max($years[$c['file_id']] ?? 0, (int) $c['period']);
            }
        }
        return $years;
    }

    private static function distinct_values(array $cands): array
    {
        $distinct = [];
        foreach ($cands as $c) {
            $v = (float) $c['value'];
            $found = false;
            foreach ($distinct as $d) {
                if (abs($d - $v) <= self::TOLERANCE) {
                    $found = true;
                }
            }
            if (!$found) {
                $distinct[] = $v;
            }
        }
        return $distinct;
    }

    /**
     * Risoluzione automatica dei conflitti sulle voci di bilancio annuali.
     * Restituisce null se nessuna regola è applicabile in modo univoco.
     */
    private static function auto_resolve(string $code, string $period, array $cands, array $docYear): ?array
    {
        if (!preg_match('/^(SP|CE|RF)_/', $code) || !preg_match('/^\d{4}$/', $period)) {
            return null;
        }
        foreach ($cands as $c) {
            if (!isset($docYear[$c['file_id']])) {
                return null; // una fonte non è un bilancio: decide il consulente
            }
        }
        $rules = [];
        $pool = $cands;

        // 1. saldi riesposti: prevale il bilancio più recente
        $maxYear = max(array_map(static fn($c) => $docYear[$c['file_id']], $pool));
        $newer = array_values(array_filter($pool, static fn($c) => $docYear[$c['file_id']] === $maxYear));
        if (count($newer) < count($pool)) {
            $rules[] = 'riesposto';
            $pool = $newer;
        }
        // 2. a parità di bilancio prevale il prospetto ufficiale
        if (count(self::distinct_values($pool)) > 1) {
            $prosp = array_values(array_filter($pool, static fn($c) => ($c['origine'] ?? '') === 'prospetto'));
            if ($prosp && count($prosp) < count($pool)) {
                $rules[] = 'prospetto';
                $pool = $prosp;
            }
        }
        if (!$rules || count(self::distinct_values($pool)) !== 1) {
            return null;
        }
        $rank = ['alta' => 0, 'media' => 1, 'bassa' => 2];
        usort($pool, static fn($a, $b) => (($a['stato'] === 'rilevato' ? 0 : 10) + $rank[$a['aff']]) <=> (($b['stato'] === 'rilevato' ? 0 : 10) + $rank[$b['aff']]));
        $chosen = $pool[0];
        $discarded = [];
        foreach ($cands as $c) {
            if (abs((float) $c['value'] - (float) $chosen['value']) > self::TOLERANCE) {
                $discarded[] = ['value' => (float) $c['value'], 'file_id' => $c['file_id'], 'page' => $c['page'] ?? null, 'origine' => $c['origine'] ?? null];
            }
        }
        $notes = [];
        if (in_array('riesposto', $rules, true)) {
            $notes[] = "saldo riesposto: utilizzato il valore della colonna comparativa del bilancio {$maxYear}, che sostituisce quello del fascicolo originario";
        }
        if (in_array('prospetto', $rules, true)) {
            $notes[] = 'utilizzato il valore del prospetto ufficiale, che prevale su quello della Nota Integrativa';
        }
        return ['cand' => $chosen, 'rule' => implode('+', $rules), 'note' => ucfirst(implode('; ', $notes)) . '.', 'discarded' => $discarded, 'confirmations' => count($pool)];
    }

    public static function summary(array $resolved): array
    {
        $s = ['totale' => 0, 'conflitti' => 0, 'da_verificare' => 0, 'manuali' => 0, 'automatici' => 0];
        foreach ($resolved as $e) {
            $s['totale']++;
            if ($e['status'] === 'conflict') {
                $s['conflitti']++;
            }
            if ($e['status'] === 'to_verify') {
                $s['da_verificare']++;
            }
            if ($e['status'] === 'manual') {
                $s['manuali']++;
            }
            if ($e['status'] === 'auto') {
                $s['automatici']++;
            }
        }
        return $s;
    }

    /** Periodi annuali (AAAA) presenti nei dati di bilancio, ordinati. */
    public static function annual_periods(array $resolved): array
    {
        $p = [];
        foreach ($resolved as $e) {
            if (preg_match('/^(SP|CE|RF)_/', $e['code']) && preg_match('/^\d{4}$/', $e['period'])) {
                $p[$e['period']] = true;
            }
        }
        $p = array_map('strval', array_keys($p));
        sort($p, SORT_STRING);
        return $p;
    }

    public static function periods_matching(array $resolved, string $code_regex, string $period_regex): array
    {
        $p = [];
        foreach ($resolved as $e) {
            if (preg_match($code_regex, $e['code']) && preg_match($period_regex, $e['period'])) {
                $p[$e['period']] = true;
            }
        }
        $p = array_map('strval', array_keys($p));
        sort($p, SORT_STRING);
        return $p;
    }
}
