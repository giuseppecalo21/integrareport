<?php
/**
 * Stesura delle sezioni. Regola cardine: il modello NON scrive numeri; li richiama tramite
 * segnaposto ([[K:...]] indicatori calcolati, [[V:...]] dati estratti, [[I:...]] informazioni
 * qualitative) che il plugin sostituisce con i valori tracciati. Per le sezioni qualitative i
 * documenti sono allegati con le citazioni abilitate: ogni affermazione citata resta collegata
 * al documento e alla pagina.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Writer
{
    public static function system_prompt(): string
    {
        return "Sei un Senior Financial Analyst e consulente aziendale specializzato nelle PMI siciliane. Redigi una sezione di un Report di Consulenza professionale destinato al cliente, a banche o a investitori.

STILE
- Italiano professionale, formale, oggettivo e asciutto, di registro istituzionale. Niente toni promozionali, enfasi o emoji.
- Scrivi in un italiano naturale da professionista: usa le espressioni proprie della prassi italiana (es. \"tensione finanziaria\", \"equilibrio patrimoniale\", \"margine operativo\"), evita calchi dall'inglese e anglicismi non necessari.
- Frasi chiare, paragrafi brevi, terminologia tecnica corretta (schemi CEE, OIC, CCII).

REGOLE SUI NUMERI (INDEROGABILI)
- Non scrivere MAI valori numerici (importi, percentuali, rapporti, indici, giorni, quote) direttamente nel testo.
- Per citare un valore usa esclusivamente i segnaposto forniti: [[K:CHIAVE@PERIODO]] per gli indicatori calcolati, [[V:CODICE@PERIODO]] per i dati estratti dai documenti, [[I:id]] per le informazioni qualitative (nomi, date, cariche, codici). Il segnaposto verrà sostituito con il valore e con il collegamento alla fonte.
- Usa solo segnaposto presenti negli elenchi forniti, copiandoli esattamente. Se il valore che ti servirebbe non è disponibile, scrivi \"Dato non valutabile per assenza di documentazione specifica.\"
- Puoi scrivere in chiaro solo anni (es. 2024), riferimenti normativi (es. art. 2086 c.c.) e numerazione dei paragrafi.
- Per le soglie, usa la descrizione testuale fornita con l'indicatore (non riscriverne i numeri) oppure richiama l'esito (\"adeguato\", \"attenzione\", \"critico\").

REGOLE SUI CONTENUTI
- Basati solo sui dati, sugli esiti e sui documenti forniti. Nessuna inferenza azzardata, nessun fatto non documentato, nessun benchmark non fornito.
- Quando un indicatore è \"non valutabile\", dichiaralo e indica il motivo fornito.
- Se i documenti allegati supportano un'affermazione qualitativa, formulala in modo fedele al documento: le citazioni vengono registrate automaticamente.
- Distingui sempre i fatti (dati) dalle valutazioni (giudizio professionale motivato dai dati).

FORMATO DI USCITA
- Non ripetere il titolo della sezione.
- Usa le intestazioni dei paragrafi indicate nella scaletta, ciascuna su una riga che inizia con \"## \".
- Paragrafi separati da una riga vuota. Elenchi puntati con righe che iniziano con \"- \"; elenchi numerati con \"1. \".
- Grassetto con **testo** solo per evidenziare conclusioni chiave, con parsimonia.
- Inserisci ogni tabella disponibile con il segnaposto [[TABELLA:id]] su una riga a sé, nel punto più appropriato (di solito subito dopo l'intestazione del paragrafo che la commenta). Non riprodurre le tabelle in testo.
- Lunghezza indicativa: da 300 a 700 parole, salvo indicazione diversa.";
    }

    /** Elenco dei nodi utili per una sezione: quelli presenti nelle tabelle + selezione chiave. */
    private static function nodes_for(array $calc, array $tables, array $extraKeys): array
    {
        $ids = [];
        foreach ($tables as $t) {
            foreach ($t['rows'] as $r) {
                foreach ($r['cells'] as $c) {
                    if (!empty($c['ref'])) {
                        $ids[$c['ref']] = true;
                    }
                }
            }
        }
        foreach ($calc['nodes'] as $nid => $n) {
            if (in_array($n['key'], $extraKeys, true)) {
                $ids['K:' . $nid] = true;
            }
        }
        return array_keys($ids);
    }

    private static function describe_ref(array $calc, array $state, string $ref): ?string
    {
        if (str_starts_with($ref, 'K:')) {
            $n = $calc['nodes'][substr($ref, 2)] ?? null;
            if (!$n) {
                return null;
            }
            $val = $n['value'] === null ? 'NON VALUTABILE (' . ($n['nv_reason'] ?: implode('; ', $n['missing'])) . ')' : ($n['display'] ?? IR_Format::value($n['value'], $n['unit']));
            $ev = $n['eval'] ? " | esito: {$n['eval']['text']} | soglia: {$n['eval']['soglia']}" : '';
            return "[[{$ref}]] = {$n['label']} — periodo " . IR_Format::period_label($n['period']) . " — valore {$val}{$ev}" . ($n['note'] ? " | nota: {$n['note']}" : '');
        }
        if (str_starts_with($ref, 'V:')) {
            $e = $calc['data'][substr($ref, 2)] ?? null;
            if (!$e) {
                return null;
            }
            return "[[{$ref}]] = {$e['label']} — periodo " . IR_Format::period_label($e['period']) . ' — valore ' . ($e['value'] === null ? 'non disponibile' : IR_Format::value($e['value'], $e['unit']));
        }
        return null;
    }

    private static function table_as_text(array $t): string
    {
        $out = "Tabella [[TABELLA:{$t['id']}]] — {$t['title']}\nColonne: " . implode(' | ', $t['columns']) . "\n";
        foreach ($t['rows'] as $r) {
            $cells = array_map(static fn($c) => $c['v'] . ($c['ref'] ? " ({$c['ref']})" : ''), $r['cells']);
            $out .= '- ' . $r['label'] . ': ' . implode(' | ', $cells) . "\n";
        }
        return $out;
    }

    private const KEY_SUMMARY = ['RICAVI', 'EBITDA', 'EBITDA_M', 'EBIT', 'UTILE', 'PFN', 'PFN_EBITDA', 'PN', 'AUTONOMIA', 'CR', 'QR', 'ROE', 'ROI', 'ZSCORE', 'DSCR', 'CCII_SEGNALI', 'RATING', 'CCC', 'DSO', 'COPERTURA_OF', 'VAR_RICAVI', 'VAR_EBITDA', 'ZES_ATTESO', 'ZES_NETTO', 'CR_UTILIZZO'];

    /**
     * Scrive una sezione. $sid = 'S0' (sommario esecutivo) oppure 'S1'..'S22'.
     */
    public static function write_section(int $report_id, array $state, array $calc, string $sid): array
    {
        $sections = IR_Catalog::sections();
        $tablesB = new IR_Tables($state, $calc);
        $profile = $state['profile'];
        $fin = IR_Catalog::finalita()[$profile['finalita']]['label'] ?? '';
        $last = $calc['periods']['last'] ?? null;

        if ($sid === 'S0') {
            $title = 'Sommario esecutivo';
            $outline = ['Profilo sintetico', 'Punti di forza', 'Aree di attenzione', 'Indicatori chiave (ultimo esercizio)', 'Indicatori della crisi', 'Raccomandazione prioritaria'];
            $tables = [];
            $extra = self::KEY_SUMMARY;
            $attachSlots = [];
            $instr = 'Massimo 450 parole. Elenchi puntati brevi per punti di forza e aree di attenzione (massimo 3 ciascuno). Gli indicatori chiave vanno richiamati con i segnaposto, preferendo quelli dell\'ultimo esercizio' . ($last ? " ($last)" : '') . '. Sezioni incluse nel report: ' . implode(', ', array_map(static fn($s) => '§' . $sections[$s]['n'] . ' ' . $sections[$s]['title'], $state['sections_selected'])) . '.';
        } else {
            $s = $sections[$sid];
            $title = '§' . $s['n'] . '. ' . $s['title'];
            $outline = $s['outline'];
            $tables = $tablesB->for_section($sid);
            $extra = in_array($sid, ['S21', 'S22'], true) ? self::KEY_SUMMARY : [];
            if ($sid === 'S12') {
                $extra = array_merge($extra, ['DSCR', 'CCII_SEGNALI']);
            }
            if ($sid === 'S7') {
                $extra[] = 'RIS_UTILI';
            }
            $attachSlots = $s['attach'] ?? [];
            $instr = '';
            if ($sid === 'S7') {
                $instr = 'Includi nel paragrafo 7.4 l\'avvertenza: "Lo Z-Score è uno strumento di screening statistico calibrato originariamente su campioni statunitensi e successivamente validato in contesti europei. Va interpretato come indicatore complementare, non sostitutivo di un\'analisi qualitativa approfondita." Non attribuire allo Z-Score percentuali di accuratezza.';
            } elseif ($sid === 'S21') {
                $instr = 'Includi nel paragrafo 21.5 l\'avvertenza: "Il rating interno è un giudizio sintetico costruito sulla base degli indicatori sopra esposti e ha valenza esclusivamente consulenziale e gestionale. Non sostituisce e non equipara il rating attribuibile da agenzie di rating regolamentate né il rating creditizio attribuito dagli istituti bancari sulla base di modelli interni regolamentati dalla normativa di Basilea."';
            } elseif (in_array($sid, ['S17', 'S20'], true)) {
                $instr = 'Per la normativa usa SOLO il documento "Riferimento normativo interno" allegato (aggiornato a maggio 2026) e segnala che aliquote, dotazioni e scadenze vanno verificate sui portali istituzionali alla data di presentazione. Nessuna promessa di risultato.';
            } elseif ($sid === 'S22') {
                $instr = 'Le azioni devono discendere esplicitamente dalle criticità emerse dagli indicatori (richiamati con segnaposto). Nel paragrafo 22.4 elenca i KPI di monitoraggio con elenco puntato (nome indicatore, frequenza di rilevazione consigliata).';
            } elseif ($s['kind'] === 'qualitativa') {
                $instr = 'Sezione qualitativa: basati sui documenti allegati; per dati anagrafici puntuali preferisci i segnaposto [[I:...]].';
            }
        }

        // --- contesto
        $refs = self::nodes_for($calc, $tables, $extra);
        $kpiLines = [];
        foreach ($refs as $r) {
            $d = self::describe_ref($calc, $state, $r);
            if ($d) {
                $kpiLines[] = $d;
            }
        }
        $infoLines = [];
        foreach ($state['extraction']['info'] ?? [] as $i) {
            $infoLines[] = "[[I:{$i['id']}]] = " . (IR_Catalog::info_fields()[$i['field']] ?? $i['field']) . ': ' . mb_substr($i['value'], 0, 300);
        }
        $tableText = $tables ? implode("\n", array_map([self::class, 'table_as_text'], $tables)) : 'Nessuna tabella per questa sezione.';

        $content = [];
        $docmap = [];
        $budget = 20 * 1024 * 1024; // byte di file originali: la codifica base64 aggiunge circa un terzo, limite API 32 MB
        $skipped = [];
        foreach ($attachSlots as $slot) {
            if ($slot === 'INT') {
                if (trim((string) $state['interview']['text']) !== '') {
                    $content[] = ['type' => 'document', 'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => $state['interview']['text']], 'title' => 'Note dell\'intervista al management' . ($state['interview']['date'] ? ' del ' . $state['interview']['date'] : ''), 'citations' => ['enabled' => true]];
                    $docmap[] = ['kind' => 'interview'];
                }
                continue;
            }
            foreach ($state['files'] as $f) {
                if ($f['slot'] === $slot && ($f['extraction']['status'] ?? '') !== 'error') {
                    $kind = IR_Text_Converter::kind(pathinfo($f['name'], PATHINFO_EXTENSION));
                    if ($kind === 'image') {
                        continue; // le immagini non supportano le citazioni
                    }
                    if ((int) $f['size'] > $budget) {
                        $skipped[] = $f['name'];
                        continue;
                    }
                    $budget -= (int) $f['size'];
                    $content[] = IR_Claude::file_block($report_id, $f, true, $f['slot'] . ' — ' . $f['name']);
                    $docmap[] = ['kind' => 'file', 'file_id' => $f['id']];
                }
            }
        }
        if (in_array($sid, ['S17', 'S20'], true)) {
            $content[] = ['type' => 'document', 'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => (string) file_get_contents(IR_DIR . 'references/sicilia_normativa.md')], 'title' => 'Riferimento normativo interno (aggiornato a maggio 2026)', 'citations' => ['enabled' => true]];
            $docmap[] = ['kind' => 'norm'];
        }
        if ($content) {
            $content[count($content) - 1]['cacheControl'] = ['type' => 'ephemeral'];
        }

        $prompt = "Impresa: {$profile['ragione_sociale']} ({$profile['forma_giuridica']}), sede in provincia di {$profile['provincia']}. Settore: " . ($profile['ateco'] ?: 'n.d.') . ". Finalità del report: {$fin}" . ($profile['finalita_altro'] ? " ({$profile['finalita_altro']})" : '') . ".\n"
            . "Sezione da redigere: {$title}\n"
            . "Scaletta dei paragrafi (usa queste intestazioni con \"## \"):\n- " . implode("\n- ", $outline) . "\n\n"
            . ($instr ? "Istruzioni specifiche: {$instr}\n\n" : '')
            . "TABELLE DISPONIBILI (da inserire con il segnaposto, già calcolate dal motore deterministico):\n{$tableText}\n\n"
            . "INDICATORI E DATI CITABILI (usa solo questi segnaposto per i numeri):\n" . ($kpiLines ? implode("\n", $kpiLines) : 'Nessuno.') . "\n\n"
            . "INFORMAZIONI QUALITATIVE CITABILI:\n" . ($infoLines ? implode("\n", array_slice($infoLines, 0, 120)) : 'Nessuna.') . "\n\n"
            . ($docmap ? "Sono allegati i documenti pertinenti: basa le affermazioni qualitative su di essi.\n\n" : '')
            . 'Redigi ora la sezione.';
        $content[] = ['type' => 'text', 'text' => $prompt];

        $res = IR_Claude::call(self::system_prompt(), $content, null, 'write');

        // ricompone il testo inserendo i marcatori di citazione
        $text = '';
        $citations = [];
        foreach ($res['blocks'] as $b) {
            $text .= $b['text'];
            foreach ($b['citations'] as $c) {
                $idx = (int) ($c['document_index'] ?? -1);
                $src = $docmap[$idx] ?? ['kind' => 'unknown'];
                $citations[] = [
                    'src' => $src,
                    'title' => (string) ($c['document_title'] ?? ''),
                    'cited_text' => (string) ($c['cited_text'] ?? ''),
                    'page_start' => $c['start_page_number'] ?? null,
                    'page_end' => isset($c['end_page_number']) ? max((int) $c['start_page_number'], (int) $c['end_page_number'] - 1) : null,
                ];
                $text .= '⟦C:' . (count($citations) - 1) . '⟧';
            }
        }

        $parsed = IR_Document::parse($text, $calc, $state, array_keys($tables));
        if ($skipped) {
            $parsed['warnings'][] = 'Documenti non allegati alla stesura per limite di dimensione della richiesta: ' . implode(', ', $skipped) . '. Verificare che il capitolo non ometta informazioni rilevanti.';
        }
        return [
            'status' => 'ok',
            'title' => $title,
            'text' => $text,
            'blocks' => $parsed['blocks'],
            'warnings' => $parsed['warnings'],
            'tables' => $tables,
            'citations' => $citations,
            'usage' => $res['usage'],
            'model' => $res['model'],
            'at' => time(),
        ];
    }
}
