<?php
/**
 * Consolidamento dei dati estratti.
 * Per ogni coppia codice@periodo:
 *  - un solo valore, o più fonti concordi → valore accettato (con numero di conferme);
 *  - fonti discordanti → CONFLITTO: il valore resta nullo finché il consulente non sceglie;
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
                    $entry['status'] = 'conflict';
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

    public static function summary(array $resolved): array
    {
        $s = ['totale' => 0, 'conflitti' => 0, 'da_verificare' => 0, 'manuali' => 0];
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
