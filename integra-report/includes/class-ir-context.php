<?php
/**
 * Contesto globale del report, condiviso da tutti i capitoli.
 *
 * Due livelli:
 *  1. dati dimensionali certi (ricavi, totale attivo, patrimonio netto, addetti dell'ultimo
 *     esercizio), ricavati dal motore di calcolo: sono sempre disponibili a ogni capitolo, anche a
 *     quelli qualitativi come la finanza agevolata;
 *  2. scheda di inquadramento (una sola chiamata al modello prima della stesura): qualifica
 *     dimensionale ai sensi della Racc. 2003/361/CE, controllo pubblico, rapporti di gruppo,
 *     specificità del settore. La scheda orienta la scrittura e non modifica alcun numero: ogni
 *     conclusione non documentata deve restare "da verificare".
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Context
{
    /** Riferimenti ai dati dimensionali dell'ultimo esercizio, citabili in ogni capitolo. */
    public static function dimension_refs(array $calc): array
    {
        $last = $calc['periods']['last'] ?? null;
        $refs = [];
        if ($last) {
            foreach (["K:RICAVI@$last", "V:SP_TOT_ATT@$last", "K:PN@$last", "K:UTILE@$last"] as $r) {
                $src = str_starts_with($r, 'K:') ? ($calc['nodes'][substr($r, 2)] ?? null) : ($calc['data'][substr($r, 2)] ?? null);
                if ($src && $src['value'] !== null) {
                    $refs[] = $r;
                }
            }
        }
        $best = null;
        foreach ($calc['data'] ?? [] as $key => $e) {
            if ($e['code'] === 'ADDETTI' && $e['value'] !== null && ($best === null || strcmp($e['period'], $calc['data'][$best]['period']) > 0)) {
                $best = $key;
            }
        }
        if ($best !== null) {
            $refs[] = "V:$best";
        }
        return $refs;
    }

    /**
     * Informazioni qualitative ordinate, con l'anno di riferimento stimato. Per i campi univoci
     * (addetti, capitale, sede...) resta solo il dato più recente.
     */
    public static function infos(array $state, bool $latestOnly = true): array
    {
        $docYear = [];
        foreach ($state['extraction']['candidates'] ?? [] as $c) {
            if (preg_match('/^\d{4}$/', (string) $c['period']) && preg_match('/^(SP|CE|RF)_/', $c['code'])) {
                $docYear[$c['file_id']] = max($docYear[$c['file_id']] ?? 0, (int) $c['period']);
            }
        }
        $uploaded = [];
        foreach ($state['files'] ?? [] as $f) {
            $uploaded[$f['id']] = (int) ($f['uploaded_at'] ?? 0);
        }
        $out = [];
        $seen = [];
        foreach ($state['extraction']['info'] ?? [] as $pos => $i) {
            $years = [];
            if (preg_match_all('/\b(19|20)\d{2}\b/', $i['value'] . ' ' . ($i['quote'] ?? ''), $m)) {
                $years = array_map('intval', $m[0]);
            }
            $i['year'] = $years ? max($years) : ($docYear[$i['file_id']] ?? 0);
            $i['order'] = [$i['year'], $docYear[$i['file_id']] ?? 0, $uploaded[$i['file_id']] ?? 0, -$pos];
            $k = $i['field'] . '|' . mb_strtolower(trim($i['value']));
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $i;
        }
        if (!$latestOnly) {
            return $out;
        }
        $bestByField = [];
        foreach ($out as $idx => $i) {
            if (in_array($i['field'], IR_Catalog::INFO_UNIVOCHE, true) && (!isset($bestByField[$i['field']]) || $i['order'] > $out[$bestByField[$i['field']]]['order'])) {
                $bestByField[$i['field']] = $idx;
            }
        }
        return array_values(array_filter($out, static fn($i, $idx) => !in_array($i['field'], IR_Catalog::INFO_UNIVOCHE, true) || $bestByField[$i['field']] === $idx, ARRAY_FILTER_USE_BOTH));
    }

    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $list = ['type' => 'array', 'items' => $str];
        $obj = static fn(array $props) => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
        return $obj([
            'qualifica_dimensionale' => $obj([
                'esito' => ['type' => 'string', 'enum' => ['pmi', 'non_pmi', 'da_verificare']],
                'motivazione' => $str,
                'strumenti_preclusi' => $list,
                'strumenti_accessibili' => $list,
                'verifiche_necessarie' => $list,
            ]),
            'controllo_pubblico' => $obj([
                'presente' => ['type' => 'string', 'enum' => ['si', 'no', 'non_documentato']],
                'descrizione' => $str,
            ]),
            'rapporti_di_gruppo' => $obj([
                'rilevanti' => ['type' => 'boolean'],
                'descrizione' => $str,
                'avvertenze_sugli_indici' => $list,
            ]),
            'settore' => $obj([
                'descrizione' => $str,
                'dinamiche_fisiologiche' => $str,
                'benchmark_rappresentativo' => ['type' => 'string', 'enum' => ['si', 'no', 'non_valutabile']],
                'motivazione_benchmark' => $str,
            ]),
            'avvertenze_generali' => $list,
        ]);
    }

    public static function system_prompt(): string
    {
        return "Sei un dottore commercialista e revisore legale esperto di bilanci civilistici italiani, di aiuti di Stato e di società a partecipazione pubblica. Prima della stesura di un report di consulenza devi predisporre una scheda di inquadramento dell'impresa, che orienterà tutti i capitoli.

REGOLE INDEROGABILI
1. Usa solo i fatti forniti (anagrafica, compagine sociale, dati di bilancio, informazioni qualitative estratte dai documenti). Non aggiungere fatti, nomi, importi o circostanze che non vi compaiono.
2. Ogni conclusione deve poggiare su un fatto documentato che richiami espressamente. Se il fatto manca o è ambiguo, l'esito è \"da_verificare\" (o \"non_documentato\") e spieghi quale documento servirebbe. Nessuna deduzione azzardata.
3. Non modificare né ricalcolare alcun valore: i numeri sono quelli forniti.
4. Qualifica dimensionale: applica la Raccomandazione 2003/361/CE (allegato): effettivi, fatturato o totale di bilancio, imprese associate e collegate (art. 3, parr. 2 e 3) e la regola dell'art. 3, par. 4, per cui non è PMI l'impresa in cui almeno il 25% del capitale o dei diritti di voto è controllato, direttamente o indirettamente, da uno o più enti pubblici, salve le eccezioni dell'art. 3, par. 2, secondo comma. Se i soci a monte non sono documentati fino all'ultimo livello, l'esito è \"da_verificare\". Se l'impresa fa parte di un gruppo e i dati delle collegate non sono disponibili, segnalalo tra le verifiche necessarie.
5. Strumenti agevolativi: in \"strumenti_preclusi\" e \"strumenti_accessibili\" indica solo categorie o strumenti presenti nell'elenco fornito, motivando in base alla qualifica. Non citare bandi che non compaiono nell'elenco.
6. Rapporti di gruppo: se dal bilancio emergono debiti o crediti verso controllanti, controllate o collegate, o piani di rientro, spiega come vanno letti gli indici che ne risentono (giorni di pagamento, capitale circolante, posizione finanziaria netta), senza qualificare la natura dei rapporti oltre quanto documentato.
7. Settore: descrivi le dinamiche fisiologiche di incasso, pagamento e marginalità dell'attività effettivamente svolta (codice ATECO e descrizione forniti) e valuta se il benchmark selezionato dal consulente è rappresentativo. Non indicare valori numerici di benchmark alternativi.
8. Scrivi in italiano professionale, con frasi complete e concise, senza elenchi di etichette e senza formattazione Markdown.";
    }

    /** Genera la scheda di inquadramento. */
    public static function generate(array $state, array $calc): array
    {
        $profile = $state['profile'];
        $P = IR_Params::get();
        $last = $calc['periods']['last'] ?? null;
        $lines = [];
        foreach (self::dimension_refs($calc) as $r) {
            $lines[] = '- ' . self::describe($calc, $r);
        }
        if ($last) {
            foreach (['SP_D9', 'SP_D10', 'SP_D11', 'SP_D11BIS', 'SP_D7', 'SP_CII_CLIENTI', 'SP_D3'] as $code) {
                $e = $calc['data']["$code@$last"] ?? null;
                if ($e && $e['value']) {
                    $lines[] = "- {$e['label']} ({$last}): " . IR_Format::value($e['value'], $e['unit']);
                }
            }
            foreach (['DSO', 'DPO', 'DPO_TOT', 'PFN', 'EBITDA_M'] as $k) {
                $n = $calc['nodes']["$k@$last"] ?? null;
                if ($n && $n['value'] !== null) {
                    $lines[] = "- {$n['label']} ({$last}): " . IR_Format::value($n['value'], $n['unit']);
                }
            }
        }
        $info = [];
        foreach (self::infos($state) as $i) {
            $info[] = '- ' . (IR_Catalog::info_fields()[$i['field']] ?? $i['field']) . ': ' . mb_substr($i['value'], 0, 500);
        }
        $bench = $P['benchmark'][$profile['settore'] ?? ''] ?? null;
        $norm = self::norm_reference();
        $strumenti = [];
        if (preg_match_all('/^#{2,4}\s+(.+)$/m', $norm, $m)) {
            $strumenti = array_map(static fn($t) => '- ' . trim($t, " #*✅"), $m[1]);
        }

        $prompt = "Impresa: {$profile['ragione_sociale']} ({$profile['forma_giuridica']}), provincia di {$profile['provincia']}.\n"
            . 'Attività dichiarata dal consulente: ' . ($profile['ateco'] ?: 'n.d.') . ".\n"
            . 'Dimensione dichiarata dal consulente: ' . (IR_Catalog::DIMENSIONI[$profile['dimensione'] ?? ''] ?? 'n.d.') . ".\n"
            . 'Benchmark di settore selezionato dal consulente: ' . ($bench ? $bench['label'] . " (giorni di incasso attesi {$bench['dso'][0]}-{$bench['dso'][1]})" : 'nessuno') . ".\n\n"
            . "DATI DI BILANCIO (ultimo esercizio disponibile)\n" . ($lines ? implode("\n", $lines) : 'Nessuno.') . "\n\n"
            . "INFORMAZIONI QUALITATIVE ESTRATTE DAI DOCUMENTI\n" . ($info ? implode("\n", array_slice($info, 0, 150)) : 'Nessuna.') . "\n\n"
            . "ELENCO DEGLI STRUMENTI AGEVOLATIVI CONSIDERATI DAL REPORT\n" . ($strumenti ? implode("\n", $strumenti) : 'Nessuno.') . "\n\n"
            . 'Predisponi la scheda di inquadramento.';

        $res = IR_Claude::call(self::system_prompt(), [['type' => 'text', 'text' => $prompt]], self::schema(), 'write');
        $data = json_decode($res['text'], true);
        if (!is_array($data)) {
            throw new RuntimeException('Scheda di inquadramento non interpretabile.');
        }
        return ['status' => 'ok', 'data' => $data, 'usage' => $res['usage'], 'model' => $res['model'], 'at' => time()];
    }

    private static function describe(array $calc, string $ref): string
    {
        if (str_starts_with($ref, 'K:')) {
            $n = $calc['nodes'][substr($ref, 2)];
            return "{$n['label']} (" . IR_Format::period_label($n['period']) . '): ' . IR_Format::value($n['value'], $n['unit']);
        }
        $e = $calc['data'][substr($ref, 2)];
        return "{$e['label']} (" . IR_Format::period_label($e['period']) . '): ' . IR_Format::value($e['value'], $e['unit']);
    }

    /**
     * Riferimento normativo interno, senza le parti di servizio (istruzioni operative, elenco
     * fonti, disclaimer, note di manutenzione) che non devono mai arrivare nel testo del report.
     */
    public static function norm_reference(): string
    {
        $md = (string) @file_get_contents(IR_DIR . 'references/sicilia_normativa.md');
        $cut = strpos($md, '## 6. CHECKLIST OPERATIVA');
        if ($cut !== false) {
            $md = substr($md, 0, $cut);
        }
        $start = strpos($md, '## 1.');
        return trim($start !== false ? substr($md, $start) : $md);
    }

    /** Testo della scheda da inserire nel prompt di ogni capitolo. */
    public static function as_prompt(?array $ctx): string
    {
        $d = $ctx['data'] ?? null;
        if (!is_array($d)) {
            return '';
        }
        $esiti = ['pmi' => 'rientra nella definizione di PMI', 'non_pmi' => 'NON rientra nella definizione di PMI', 'da_verificare' => 'qualifica di PMI DA VERIFICARE (non documentata)'];
        $q = $d['qualifica_dimensionale'] ?? [];
        $out = "CONTESTO GLOBALE DELL'IMPRESA (vale per tutti i capitoli; è una guida alla lettura, non una fonte di nuovi fatti)\n";
        $out .= '- Qualifica dimensionale (Racc. 2003/361/CE): ' . ($esiti[$q['esito'] ?? ''] ?? 'non determinata') . '. ' . ($q['motivazione'] ?? '') . "\n";
        foreach (['strumenti_preclusi' => 'Strumenti agevolativi preclusi', 'strumenti_accessibili' => 'Strumenti agevolativi potenzialmente accessibili', 'verifiche_necessarie' => 'Verifiche necessarie'] as $k => $l) {
            if (!empty($q[$k])) {
                $out .= "- {$l}: " . implode('; ', $q[$k]) . "\n";
            }
        }
        $cp = $d['controllo_pubblico'] ?? [];
        if (($cp['presente'] ?? '') !== 'no' && !empty($cp['descrizione'])) {
            $out .= '- Controllo pubblico (' . ($cp['presente'] === 'si' ? 'documentato' : 'non documentato') . '): ' . $cp['descrizione'] . "\n";
        }
        $g = $d['rapporti_di_gruppo'] ?? [];
        if (!empty($g['rilevanti'])) {
            $out .= '- Rapporti di gruppo: ' . ($g['descrizione'] ?? '') . "\n";
            foreach ($g['avvertenze_sugli_indici'] ?? [] as $a) {
                $out .= "  · {$a}\n";
            }
        }
        $s = $d['settore'] ?? [];
        if (!empty($s['descrizione'])) {
            $out .= '- Settore: ' . $s['descrizione'] . ' ' . ($s['dinamiche_fisiologiche'] ?? '') . "\n";
        }
        if (($s['benchmark_rappresentativo'] ?? '') === 'no') {
            $out .= '- Il benchmark di settore selezionato NON è rappresentativo dell\'attività svolta: ' . ($s['motivazione_benchmark'] ?? '') . " Dichiaralo quando commenti il confronto con il benchmark e non trarre giudizi negativi da quel solo confronto.\n";
        }
        foreach ($d['avvertenze_generali'] ?? [] as $a) {
            $out .= "- {$a}\n";
        }
        return $out;
    }

    /** Avvertenza da riportare sotto la tabella dei benchmark quando il settore selezionato non è rappresentativo. */
    public static function benchmark_note(array $state): ?string
    {
        $s = $state['report']['context']['data']['settore'] ?? null;
        if (!$s || ($s['benchmark_rappresentativo'] ?? '') !== 'no') {
            return null;
        }
        return 'Avvertenza: il benchmark selezionato non è pienamente rappresentativo dell\'attività svolta dall\'impresa; il confronto ha valore puramente indicativo. ' . trim((string) ($s['motivazione_benchmark'] ?? ''));
    }
}
