<?php
/**
 * Modello del documento finale, condiviso dal visualizzatore web e dall'esportazione .docx.
 * Contiene: parti fisse del template (premessa, checklist, fonti, disclaimer), le sezioni
 * generate e il parser del testo con segnaposto.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Document
{
    private const NUM_PATTERN = '/(€\s?\d[\d.,]*)|(\b\d{1,3}(?:\.\d{3})+(?:,\d+)?\b)|(\b\d+,\d+\b)|(\b\d+(?:,\d+)?\s?%)|(\b\d+(?:,\d+)?\s?x\b)/u';

    /**
     * Converte il testo del modello in blocchi con elementi inline.
     * @return array{blocks:array, warnings:array}
     */
    public static function parse(string $text, array $calc, array $state, array $tableIds): array
    {
        $warnings = [];
        $blocks = [];
        $placed = [];
        $lines = preg_split('/\r\n|\n/', $text);
        $para = [];
        $list = null;

        $flushPara = function () use (&$para, &$blocks, $calc, $state, &$warnings) {
            if ($para) {
                $blocks[] = ['type' => 'p', 'inline' => self::inline(implode(' ', $para), $calc, $state, $warnings)];
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$blocks) {
            if ($list) {
                $blocks[] = $list;
                $list = null;
            }
        };

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                $flushPara();
                $flushList();
                continue;
            }
            if (preg_match('/^\[\[TABELLA:([a-z_]+)\]\]$/', $line, $m)) {
                $flushPara();
                $flushList();
                if (in_array($m[1], $tableIds, true) && !in_array($m[1], $placed, true)) {
                    $blocks[] = ['type' => 'table', 'id' => $m[1]];
                    $placed[] = $m[1];
                }
                continue;
            }
            if (preg_match('/^#{2,4}\s+(.+)$/', $line, $m)) {
                $flushPara();
                $flushList();
                $blocks[] = ['type' => 'h2', 'inline' => self::inline(trim($m[1], '# '), $calc, $state, $warnings)];
                continue;
            }
            if (preg_match('/^[-*•]\s+(.+)$/u', $line, $m)) {
                $flushPara();
                if (!$list || $list['type'] !== 'ul') {
                    $flushList();
                    $list = ['type' => 'ul', 'items' => []];
                }
                $list['items'][] = self::inline($m[1], $calc, $state, $warnings);
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $m)) {
                $flushPara();
                if (!$list || $list['type'] !== 'ol') {
                    $flushList();
                    $list = ['type' => 'ol', 'items' => []];
                }
                $list['items'][] = self::inline($m[1], $calc, $state, $warnings);
                continue;
            }
            $flushList();
            $para[] = $line;
        }
        $flushPara();
        $flushList();

        foreach ($tableIds as $tid) {
            if (!in_array($tid, $placed, true)) {
                $blocks[] = ['type' => 'table', 'id' => $tid];
            }
        }
        return ['blocks' => $blocks, 'warnings' => array_values(array_unique($warnings))];
    }

    private static function inline(string $s, array $calc, array $state, array &$warnings): array
    {
        $runs = [];
        $bold = false;
        $parts = preg_split('/(\[\[(?:K|V|I):[^\]]+\]\]|⟦C:\d+⟧|\*\*)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            if ($part === '**') {
                $bold = !$bold;
                continue;
            }
            if (preg_match('/^⟦C:(\d+)⟧$/u', $part, $m)) {
                $runs[] = ['t' => 'cite', 'c' => (int) $m[1]];
                continue;
            }
            if (preg_match('/^\[\[(K|V|I):([^\]]+)\]\]$/', $part, $m)) {
                $ref = $m[1] . ':' . trim($m[2]);
                $v = self::ref_display($ref, $calc, $state);
                if ($v === null) {
                    $runs[] = ['t' => 'bad', 'v' => '[riferimento non valido]', 'b' => $bold];
                    $warnings[] = "Riferimento non valido nel testo: {$ref}";
                } else {
                    $runs[] = ['t' => 'ref', 'ref' => $ref, 'v' => $v, 'b' => $bold];
                }
                continue;
            }
            // testo semplice: segnala numeri non collegati a una fonte
            $offset = 0;
            if (preg_match_all(self::NUM_PATTERN, $part, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as [$match, $pos]) {
                    if (self::is_reference_number($part, $pos, $match)) {
                        continue; // estremi di norme, atti e registri: non sono dati da tracciare
                    }
                    if ($pos > $offset) {
                        $runs[] = ['t' => 'text', 'v' => substr($part, $offset, $pos - $offset), 'b' => $bold];
                    }
                    $runs[] = ['t' => 'flag', 'v' => $match, 'b' => $bold];
                    $warnings[] = "Numero scritto nel testo senza collegamento a una fonte: \"{$match}\" — verificare.";
                    $offset = $pos + strlen($match);
                }
            }
            if ($offset < strlen($part)) {
                $runs[] = ['t' => 'text', 'v' => substr($part, $offset), 'b' => $bold];
            }
        }
        return $runs;
    }

    /** Parole che introducono un numero identificativo (articolo, repertorio, protocollo, iscrizione…). */
    private const REF_BEFORE = '/(?<![\p{L}])(?:artt?\.|articol[oi]|commi|comma|rep\.|repertorio|racc\.|raccolta|prot\.|protocollo|albo|registro|runts|rea|iscrizione|matricola|pratica)(?:\s*(?:n\.|nr\.|num\.|numero)?|\s+[\p{L}\s]{0,30}?(?:n\.|nr\.|num\.|numero))\s*$/iu';
    /** Atto normativo seguito dal suo numero: "Legge 8 novembre 1991 n. 381", "D.Lgs. n. 14/2019". */
    private const LAW_BEFORE = '/(?<![\p{L}])(?:legge|l\.|d\.\s?lgs\.|d\.\s?l\.|d\.\s?p\.\s?r\.|dpr|d\.\s?m\.|decreto|regolamento|reg\.|direttiva|circolare|delibera|determina)[^.;:()€%]{0,40}?(?:n\.|nr\.|num\.|numero)\s*$/iu';

    /**
     * Vero se il numero è l'estremo di una norma, di un atto o di un registro (art. 2086,
     * rep. n. 616.973, n. 1.234/2019) e non un dato quantitativo. Criterio prudente: importi in
     * euro, percentuali e multipli restano sempre segnalati, e l'abbreviazione "L." da sola non
     * basta (può indicare lire).
     */
    private static function is_reference_number(string $text, int $pos, string $match): bool
    {
        if (preg_match('/[€%x]/u', $match)) {
            return false;
        }
        $after = substr($text, $pos + strlen($match), 12);
        if (preg_match('/^\/(?:\d{4}|\d{2})(?!\d)/', $after)) {
            return true; // forma numero/anno
        }
        if (preg_match('/^\s*(?:€|euro\b|eur\b|%|lire\b)/iu', $after)) {
            return false;
        }
        $before = substr($text, max(0, $pos - 80), min(80, $pos));
        if (!mb_check_encoding($before, 'UTF-8')) {
            $before = mb_substr(mb_convert_encoding($before, 'UTF-8', 'UTF-8'), 1);
        }
        return (bool) (preg_match(self::REF_BEFORE, $before) || preg_match(self::LAW_BEFORE, $before));
    }

    public static function ref_display(string $ref, array $calc, array $state): ?string
    {
        if (str_starts_with($ref, 'K:')) {
            $n = $calc['nodes'][substr($ref, 2)] ?? null;
            if (!$n) {
                return null;
            }
            return $n['value'] === null ? 'non valutabile' : ($n['display'] ?? IR_Format::value($n['value'], $n['unit']));
        }
        if (str_starts_with($ref, 'V:')) {
            $e = $calc['data'][substr($ref, 2)] ?? null;
            if (!$e) {
                return null;
            }
            return $e['value'] === null ? 'non disponibile' : IR_Format::value($e['value'], $e['unit']);
        }
        if (str_starts_with($ref, 'I:')) {
            foreach ($state['extraction']['info'] ?? [] as $i) {
                if ('I:' . $i['id'] === $ref) {
                    return $i['value'];
                }
            }
        }
        return null;
    }

    private static function p(string $text): array
    {
        return ['type' => 'p', 'inline' => [['t' => 'text', 'v' => $text]]];
    }

    private static function ul(array $items): array
    {
        return ['type' => 'ul', 'items' => array_map(static fn($t) => is_array($t) ? $t : [['t' => 'text', 'v' => $t]], $items)];
    }

    private static function link(string $label, string $url): array
    {
        return [['t' => 'link', 'v' => $label, 'href' => $url]];
    }

    /** Modello completo del report. */
    public static function build(array $state): array
    {
        $profile = $state['profile'];
        $calc = $state['kpi'] ?? ['nodes' => [], 'data' => [], 'checks' => [], 'periods' => ['annual' => []]];
        $report = $state['report'] ?? ['sections' => []];
        $catalog = IR_Catalog::sections();
        $fin = IR_Catalog::finalita()[$profile['finalita']]['label'] ?? '';
        $finTxt = $fin . ($profile['finalita_altro'] ? ' — ' . $profile['finalita_altro'] : '');
        $periods = $calc['periods']['annual'] ?? [];
        $periodTxt = $periods ? (count($periods) > 1 ? 'esercizi ' . reset($periods) . '-' . end($periods) : 'esercizio ' . reset($periods)) : ($profile['esercizi'] ?: 'n.d.');
        $date = IR_Format::date_it((int) ($report['generated_at'] ?? time()));

        $meta = [
            'ragione_sociale' => $profile['ragione_sociale'],
            'forma' => $profile['forma_giuridica'],
            'piva' => $profile['partita_iva'],
            'data' => $date,
            'periodo' => $periodTxt,
            'finalita' => $finTxt,
            'studio' => (string) get_option('ir_studio', 'Integra'),
            'sito' => (string) get_option('ir_sito', ''),
            'consulente' => (string) get_option('ir_consulente', ''),
        ];

        $premessa = [
            self::p("Il presente Report di Consulenza è stato redatto nell'ambito di un'analisi economico-finanziaria e strategica della società {$profile['ragione_sociale']}, con finalità: {$finTxt}."),
            self::p("L'analisi è stata condotta sulla base della documentazione fornita dalla committente e delle informazioni rese disponibili durante l'attività consulenziale. L'elenco completo dei documenti analizzati è riportato nella sezione \"Checklist delle operazioni\" al termine del presente documento."),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Metodologia applicata']]],
            self::p("L'analisi si articola in sezioni modulari, ciascuna sviluppata solo qualora la documentazione necessaria fosse disponibile e di qualità adeguata. Le formule e i criteri di calcolo applicati seguono:"),
            self::ul([
                'gli schemi di bilancio civilistico italiani (artt. 2424 e 2425 del Codice Civile);',
                "i principi contabili nazionali emanati dall'OIC, con particolare riferimento all'OIC 12 (composizione e schemi del bilancio) e all'OIC 10 (rendiconto finanziario);",
                "gli indicatori della crisi d'impresa previsti dal Codice della Crisi d'Impresa e dell'Insolvenza (D.Lgs. 14/2019 e successive modificazioni), con riferimento agli artt. 3 e 25-novies;",
                "la metodologia Altman per lo Z-Score di previsione dell'insolvenza, nelle versioni per società non quotate (Z' per imprese manifatturiere, Z'' per imprese di servizi e commercio);",
                'le linee guida CNDCEC per il calcolo del DSCR a 12 mesi prospettico;',
                'la normativa specifica per la Regione Siciliana in materia di agevolazioni (ZES Unica, IRFIS, leggi regionali vigenti).',
            ]),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Tracciabilità dei dati e dei calcoli']]],
            self::p("Ogni dato numerico è stato estratto dai documenti con indicazione del documento, della pagina e del passaggio testuale di riferimento; ogni indicatore è stato calcolato con formula esplicita da un motore di calcolo deterministico, senza stime né arrotondamenti intermedi. Le appendici riportano, per ciascun valore citato, la formula applicata, i valori utilizzati e la fonte. Gli schemi di bilancio sono stati sottoposti a controlli automatici di quadratura."),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Limitazioni']]],
            self::p("Per ogni indicatore o sezione che non è stato possibile sviluppare per assenza o incompletezza di documentazione è riportata la dicitura \"Dato non valutabile per assenza di documentazione specifica\" oppure \"non valutabile\". Nessun dato è stato inventato, stimato per analogia o desunto in via presuntiva."),
            self::p('Il presente report ha valore esclusivamente consulenziale e gestionale. Non costituisce certificazione di bilancio, revisione legale dei conti, parere legale o fiscale vincolante. Le valutazioni espresse sono frutto di analisi tecnica condotta in buona fede sulla base delle informazioni disponibili alla data di emissione.'),
        ];

        // sezioni in ordine; le citazioni hanno numerazione unica in tutto il documento
        $order = array_merge(['S0'], array_values(array_filter(array_keys($catalog), static fn($s) => in_array($s, $state['sections_selected'], true))));
        $sections = [];
        $citeBase = 0;
        foreach ($order as $sid) {
            $sec = $report['sections'][$sid] ?? null;
            if (!$sec || ($sec['status'] ?? '') !== 'ok') {
                continue;
            }
            $sections[] = ['id' => $sid, 'title' => $sec['title'], 'blocks' => $sec['blocks'], 'tables' => $sec['tables'], 'citations' => $sec['citations'], 'warnings' => $sec['warnings'], 'cite_base' => $citeBase];
            $citeBase += count($sec['citations']);
        }

        return [
            'meta' => $meta,
            'premessa' => $premessa,
            'sections' => $sections,
            'pending' => self::pending($state),
            'checklist' => self::checklist($state, $sections),
            'fonti' => self::fonti(),
            'disclaimer' => self::disclaimer($state),
            'appendices' => self::appendices($state, $sections),
        ];
    }

    /**
     * Capitoli richiesti ma non presenti nel documento per un problema tecnico: errore di
     * generazione oppure stesura mai eseguita (lavoro interrotto). Sono diversi dai capitoli
     * esclusi per mancanza di dati ("skipped"), che sono un esito legittimo.
     * @return array<int, array{id:string, title:string, status:string, error:string}>
     */
    public static function pending(array $state): array
    {
        $catalog = IR_Catalog::sections();
        $secs = $state['report']['sections'] ?? [];
        $out = [];
        foreach (array_merge(['S0'], array_keys($catalog)) as $sid) {
            if ($sid !== 'S0' && !in_array($sid, $state['sections_selected'], true)) {
                continue;
            }
            $st = $secs[$sid]['status'] ?? 'missing';
            if ($st === 'ok' || $st === 'skipped') {
                continue;
            }
            $out[] = [
                'id' => $sid,
                'title' => $sid === 'S0' ? 'Sommario esecutivo' : "§{$catalog[$sid]['n']} {$catalog[$sid]['title']}",
                'status' => $st === 'error' ? 'error' : 'missing',
                'error' => $st === 'error' ? (string) ($secs[$sid]['error'] ?? '') : '',
            ];
        }
        return $out;
    }

    private static function checklist(array $state, array $sections): array
    {
        $docs = IR_Catalog::documents();
        $catalog = IR_Catalog::sections();
        $b = [];
        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Documenti ricevuti e analizzati']]];
        $items = [];
        foreach ($state['files'] as $f) {
            $st = $f['extraction']['status'] ?? 'pending';
            $note = $st === 'done' ? 'estratto' : ($st === 'error' ? 'non leggibile: ' . ($f['extraction']['error'] ?? '') : 'non elaborato');
            $doc = $state['extraction']['docs'][$f['id']] ?? null;
            if ($doc && !$doc['match']) {
                $note .= ' — il contenuto non corrisponde pienamente alla categoria dichiarata';
            }
            $items[] = "{$f['slot']} — " . ($docs[$f['slot']]['name'] ?? '') . ": {$f['name']} (caricato il " . IR_Format::date_it((int) $f['uploaded_at']) . "; {$note})";
        }
        $b[] = $items ? self::ul($items) : self::p('Nessun documento caricato.');

        $done = [];
        $partial = [];
        foreach ($sections as $s) {
            if ($s['id'] === 'S0') {
                continue;
            }
            $nv = [];
            foreach ($s['tables'] as $t) {
                foreach ($t['rows'] as $r) {
                    foreach ($r['cells'] as $c) {
                        if ($c['v'] === 'n.v.') {
                            $nv[$r['label']] = true;
                        }
                    }
                }
            }
            if ($nv || $s['warnings']) {
                $lim = [];
                if ($nv) {
                    $lim[] = 'indicatori non valutabili: ' . implode(', ', array_slice(array_keys($nv), 0, 8)) . (count($nv) > 8 ? '…' : '');
                }
                if ($s['warnings']) {
                    $lim[] = count($s['warnings']) . ' avvisi di controllo sul testo';
                }
                $partial[] = $s['title'] . ' — Limitazione: ' . implode('; ', $lim);
            } else {
                $done[] = $s['title'];
            }
        }
        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Analisi completate']]];
        $b[] = $done ? self::ul($done) : self::p('Nessuna.');
        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Analisi parziali (limitate da documentazione incompleta)']]];
        $b[] = $partial ? self::ul($partial) : self::p('Nessuna.');

        $not = [];
        $enabled = IR_Catalog::enabled_sections(IR_Store::available_slots($state));
        foreach ($catalog as $sid => $s) {
            if (in_array($sid, $state['sections_selected'], true)) {
                $r = $state['report']['sections'][$sid] ?? null;
                if ($r && ($r['status'] ?? '') === 'skipped') {
                    $not[] = "§{$s['n']} {$s['title']} — Motivo: {$r['reason']}; sezione esclusa per non presentare un capitolo privo di contenuti valutabili";
                }
                continue;
            }
            if (!$enabled[$sid]['enabled']) {
                $miss = array_map(static fn($g) => implode(' oppure ', $g), $enabled[$sid]['missing']);
                $not[] = "§{$s['n']} {$s['title']} — Motivo: documentazione mancante (" . implode('; ', $miss) . ')';
            } else {
                $not[] = "§{$s['n']} {$s['title']} — Motivo: non selezionata per la finalità del report";
            }
        }
        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Analisi non effettuate']]];
        $b[] = $not ? self::ul($not) : self::p('Nessuna.');

        $pending = self::pending($state);
        if ($pending) {
            $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Capitoli non generati (documento incompleto)']]];
            $b[] = self::p('I capitoli seguenti erano previsti ma non sono presenti nel documento per un problema tecnico in fase di stesura. Vanno rigenerati prima della consegna del report.');
            $b[] = self::ul(array_map(static fn($p) => $p['title'] . ' — ' . ($p['status'] === 'error' ? 'Errore di generazione: ' . ($p['error'] !== '' ? $p['error'] : 'causa non registrata') : 'Stesura non eseguita (elaborazione interrotta)'), $pending));
        }

        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Intervista al management']]];
        $b[] = trim((string) $state['interview']['text']) !== ''
            ? self::p('Effettuata' . ($state['interview']['date'] ? ' in data ' . $state['interview']['date'] : '') . '. Le note sono state utilizzate come fonte e citate nel testo ove pertinenti.')
            : self::p('Non effettuata.');

        $kpi = $state['kpi'] ?? null;
        if ($kpi) {
            $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Validazione dei dati']]];
            $ok = 0;
            $ko = 0;
            foreach ($kpi['checks'] as $c) {
                if ($c['ok'] === true) {
                    $ok++;
                } elseif ($c['ok'] === false) {
                    $ko++;
                }
            }
            $manual = 0;
            foreach ($kpi['data'] as $e) {
                if ($e['status'] === 'manual') {
                    $manual++;
                }
            }
            $b[] = self::ul([
                "Controlli di quadratura superati: {$ok}; non superati: {$ko}.",
                "Dati inseriti o corretti manualmente dal consulente (con nota motivata): {$manual}.",
                'Cruscotto KPI approvato da ' . ($kpi['approved_by'] ?? 'n.d.') . (isset($kpi['approved_at']) ? ' il ' . IR_Format::date_it((int) $kpi['approved_at']) : '') . '.',
            ]);
        }
        return $b;
    }

    private static function fonti(): array
    {
        $L = static fn($label, $url) => self::link($label, $url);
        return [
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Normativa di riferimento']]],
            self::ul([
                $L('Codice Civile — artt. 2086, 2423-ter, 2424, 2425, 2435-bis, 2435-ter', 'https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:codice.civile'),
                $L("Codice della Crisi d'Impresa e dell'Insolvenza — D.Lgs. 14/2019", 'https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2019-01-12;14'),
                $L('TUIR — DPR 917/1986', 'https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.del.presidente.della.repubblica:1986-12-22;917'),
                $L('D.Lgs. 446/1997 — IRAP', 'https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:1997-12-15;446'),
            ]),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Principi contabili']]],
            self::ul([$L('OIC — Organismo Italiano di Contabilità', 'https://www.fondazioneoic.eu/'), 'OIC 10 — Rendiconto finanziario', "OIC 12 — Composizione e schemi del bilancio d'esercizio"]),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Metodologie applicate']]],
            self::ul([
                'Altman E.I. (1968), "Financial Ratios, Discriminant Analysis and the Prediction of Corporate Bankruptcy", Journal of Finance, 23(4), 589-609.',
                'Altman E.I. (1983), "Corporate Financial Distress: A Complete Guide to Predicting, Avoiding, and Dealing with Bankruptcy", Wiley.',
                'CNDCEC, documenti tecnici su indicatori della crisi e DSCR.',
            ]),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Agevolazioni e finanza pubblica']]],
            self::ul([
                $L('Agenzia delle Entrate — Credito d\'imposta ZES Unica', 'https://www.agenziaentrate.gov.it/portale/credito-d-imposta-per-investimenti-nella-zes-unica-2026'),
                $L('IRFIS-FinSicilia', 'https://www.irfis.it/'),
                $L('Portale IncentiviSicilia', 'https://incentivisicilia.it/'),
                $L('Invitalia', 'https://www.invitalia.it/'),
                $L('Carta degli aiuti a finalità regionale 2022-2027', 'https://politichecoesione.governo.it/it/strategia-2021-2027/la-carta-degli-aiuti-di-stato-a-finalita-regionale/'),
            ]),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Banche dati e fonti istituzionali']]],
            self::ul([
                $L('Registro Imprese / Telemaco', 'https://www.registroimprese.it/'),
                $L("Banca d'Italia — Centrale dei Rischi", 'https://www.bancaditalia.it/servizi-cittadino/servizi/accesso-cr'),
                $L('ABI — Associazione Bancaria Italiana', 'https://www.abi.it/'),
            ]),
            self::p('Parametri del modello di calcolo: ' . IR_Params::get()['versione'] . '.'),
        ];
    }

    private static function disclaimer(array $state): array
    {
        $b = [
            self::p('Il presente report è stato redatto sulla base della documentazione fornita dalla committente e delle informazioni rese disponibili durante l\'attività consulenziale. Il consulente declina ogni responsabilità per eventuali inesattezze derivanti da:'),
            self::ul(['documentazione fornita non veritiera o non aggiornata;', 'omissioni informative da parte della committente;', 'eventi posteriori alla data di emissione del documento.']),
            self::p('Il report ha valore esclusivamente consulenziale e gestionale. Non sostituisce:'),
            self::ul(['la revisione legale dei conti ex D.Lgs. 39/2010 e successive modificazioni;', 'la certificazione del bilancio;', 'il parere legale qualificato di un professionista abilitato (avvocato, dottore commercialista, esperto contabile);', 'il rating creditizio formale attribuibile da banche o agenzie di rating regolamentate.']),
        ];
        if (array_intersect(['S17', 'S20'], $state['sections_selected'])) {
            $b[] = self::p('Le stime relative a crediti d\'imposta, agevolazioni regionali e altre forme di finanza agevolata contenute nel presente report sono basate sulla normativa vigente alla data di emissione del documento (riportata in copertina). Tali strumenti sono soggetti a continui aggiornamenti, modifiche normative, vincoli di dotazione finanziaria e meccanismi di riparto che possono comportare scostamenti significativi tra il beneficio teorico calcolato e quello effettivamente fruito. Per la presentazione effettiva di domande di accesso a tali agevolazioni si raccomanda di consultare un professionista abilitato e di verificare puntualmente bandi, scadenze e requisiti sui portali istituzionali competenti.');
        }
        $b[] = self::p('Le valutazioni espresse riflettono il giudizio professionale del consulente alla data di emissione e non costituiscono garanzia o promessa di risultati futuri.');
        $b[] = self::p('Documento riservato. La diffusione, riproduzione e distribuzione del presente report a soggetti terzi rispetto alla committente è subordinata ad autorizzazione scritta del consulente redattore.');
        $c = trim((string) get_option('ir_consulente', ''));
        if ($c !== '') {
            $b[] = self::p($c);
        }
        return $b;
    }

    /** Riferimenti usati nel documento (per le appendici), in ordine di comparsa. */
    public static function used_refs(array $model): array
    {
        $refs = [];
        $walkInline = static function (array $inline) use (&$refs) {
            foreach ($inline as $r) {
                if (($r['t'] ?? '') === 'ref') {
                    $refs[$r['ref']] = true;
                }
            }
        };
        foreach ($model['sections'] as $s) {
            foreach ($s['blocks'] as $b) {
                if (isset($b['inline'])) {
                    $walkInline($b['inline']);
                }
                foreach ($b['items'] ?? [] as $it) {
                    $walkInline($it);
                }
            }
            foreach ($s['tables'] as $t) {
                foreach ($t['rows'] as $row) {
                    foreach ($row['cells'] as $c) {
                        if (!empty($c['ref'])) {
                            $refs[$c['ref']] = true;
                        }
                    }
                }
            }
        }
        return array_keys($refs);
    }

    /** Chiusura transitiva dei riferimenti: ogni indicatore porta con sé i suoi ingressi. */
    public static function closure(array $refs, array $calc): array
    {
        $out = [];
        $queue = $refs;
        while ($queue) {
            $r = array_shift($queue);
            if (isset($out[$r])) {
                continue;
            }
            $out[$r] = true;
            if (str_starts_with($r, 'K:')) {
                foreach ($calc['nodes'][substr($r, 2)]['inputs'] ?? [] as $in) {
                    if (!empty($in['ref'])) {
                        $queue[] = $in['ref'];
                    }
                }
            }
        }
        $k = array_values(array_filter(array_keys($out), static fn($r) => str_starts_with($r, 'K:')));
        $v = array_values(array_filter(array_keys($out), static fn($r) => str_starts_with($r, 'V:')));
        $i = array_values(array_filter(array_keys($out), static fn($r) => str_starts_with($r, 'I:')));
        return array_merge($k, $v, $i);
    }

    private static function file_label(array $state, ?string $file_id): string
    {
        foreach ($state['files'] as $f) {
            if ($f['id'] === $file_id) {
                return $f['slot'] . ' — ' . (IR_Catalog::documents()[$f['slot']]['name'] ?? '') . ' — file "' . $f['name'] . '"';
            }
        }
        return 'documento non più presente';
    }

    private static function input_value(array $in, array $calc): string
    {
        if (!empty($in['param'])) {
            $v = (float) $in['value'];
            return abs($v) < 1 && $v != 0.0 ? IR_Format::num($v * 100, 4) . '%' : IR_Format::num($v, 2);
        }
        if (!empty($in['ref']) && str_starts_with($in['ref'], 'K:')) {
            $n = $calc['nodes'][substr($in['ref'], 2)] ?? null;
            return $n && $n['value'] !== null ? ($n['display'] ?? IR_Format::value($n['value'], $n['unit'])) : 'non disponibile';
        }
        if (!empty($in['ref']) && str_starts_with($in['ref'], 'V:')) {
            $e = $calc['data'][substr($in['ref'], 2)] ?? null;
            return $e && $e['value'] !== null ? IR_Format::value($e['value'], $e['unit']) : 'non disponibile';
        }
        return ($in['value'] ?? null) === null ? 'non disponibile' : IR_Format::num((float) $in['value'], 2);
    }

    /**
     * Appendici di tracciabilità, in forma indipendente dal formato di uscita: le stesse voci
     * alimentano il file Word, il visualizzatore e la stampa.
     * A = calcoli; B = dati estratti; C = citazioni documentali.
     * Ogni riga di dettaglio è ['k' => etichetta, 'v' => testo, 'style' => ''|'i'|'muted'].
     */
    public static function appendices(array $state, array $sections): array
    {
        $calc = $state['kpi'] ?? ['nodes' => [], 'data' => []];
        $refs = self::closure(self::used_refs(['sections' => $sections]), $calc);
        $A = [];
        $B = [];
        foreach ($refs as $r) {
            if (str_starts_with($r, 'K:')) {
                $n = $calc['nodes'][substr($r, 2)] ?? null;
                if (!$n) {
                    continue;
                }
                $inputs = [];
                foreach ($n['inputs'] as $in) {
                    $inputs[] = [
                        'sym' => $in['sym'],
                        'value' => self::input_value($in, $calc),
                        'ref' => !empty($in['ref']) ? $in['ref'] : null,
                        'desc' => !empty($in['param']) ? ' (parametro del modello — ' . $in['param'] . ')' : ' — ' . $in['label'],
                    ];
                }
                $lines = [];
                if ($n['value'] === null) {
                    $lines[] = ['k' => 'Non valutabile', 'v' => $n['nv_reason'] ?: implode('; ', $n['missing']), 'style' => ''];
                }
                if ($n['eval']) {
                    $lines[] = ['k' => 'Valutazione', 'v' => $n['eval']['text'] . ' — ' . $n['eval']['soglia'] . ' (fonte: ' . $n['eval']['fonte'] . ')', 'style' => ''];
                }
                if ($n['note']) {
                    $lines[] = ['k' => 'Nota', 'v' => $n['note'], 'style' => ''];
                }
                $A[] = [
                    'ref' => $r,
                    'label' => $n['label'] . ' — ' . IR_Format::period_label($n['period']),
                    'value' => $n['value'] === null ? 'non valutabile' : ($n['display'] ?? IR_Format::value($n['value'], $n['unit'])),
                    'formula' => $n['formula'],
                    'inputs' => $inputs,
                    'lines' => $lines,
                ];
            } elseif (str_starts_with($r, 'V:')) {
                $e = $calc['data'][substr($r, 2)] ?? null;
                if (!$e) {
                    continue;
                }
                $lines = [];
                $s = $e['source'];
                if (!empty($s['manual'])) {
                    $lines[] = ['k' => '', 'v' => 'Inserito manualmente da ' . $s['by'] . ' il ' . IR_Format::date_it((int) $s['at']) . '. Motivazione: ' . $s['note'], 'style' => ''];
                } elseif ($s) {
                    $pos = $s['page'] ? 'pag. ' . $s['page'] : ($s['pos'] ?: 'posizione n.d.');
                    if ($s['page'] && $s['pos']) {
                        $pos .= ', ' . $s['pos'];
                    }
                    $lines[] = ['k' => 'Fonte', 'v' => self::file_label($state, $s['file_id']) . ' — ' . $pos, 'style' => ''];
                    $lines[] = ['k' => 'Citazione', 'v' => '«' . $s['quote'] . '»', 'style' => 'i'];
                    $extra = 'Affidabilità: ' . $s['aff'];
                    if ($s['stato'] === 'assente') {
                        $extra .= ' — voce non presente nel prospetto (valore zero per omissione ex art. 2423-ter c.c.)';
                    }
                    if ($s['scale'] != 1) {
                        $extra .= ' — valore stampato ' . IR_Format::num($s['raw'], 2) . ' × ' . IR_Format::num($s['scale'], 0) . ' (scala del documento)';
                    }
                    if ($e['confirmations'] > 1) {
                        $extra .= ' — confermato da ' . $e['confirmations'] . ' fonti concordi';
                    }
                    if ($e['status'] === 'chosen') {
                        $extra .= ' — scelto dal consulente tra fonti discordanti';
                    }
                    if (!empty($e['verified'])) {
                        $extra .= ' — verificato da ' . $e['verified']['by'] . ' il ' . IR_Format::date_it((int) $e['verified']['at']);
                    }
                    if ($s['note']) {
                        $extra .= ' — ' . $s['note'];
                    }
                    $lines[] = ['k' => '', 'v' => $extra, 'style' => 'muted'];
                }
                $B[] = [
                    'ref' => $r,
                    'label' => $e['label'] . ' — ' . IR_Format::period_label($e['period']),
                    'value' => $e['value'] === null ? 'non disponibile' : IR_Format::value($e['value'], $e['unit']),
                    'lines' => $lines,
                ];
            } elseif (str_starts_with($r, 'I:')) {
                foreach ($state['extraction']['info'] ?? [] as $i) {
                    if ('I:' . $i['id'] !== $r) {
                        continue;
                    }
                    $pos = $i['page'] ? 'pag. ' . $i['page'] : ($i['pos'] ?: 'posizione n.d.');
                    $B[] = [
                        'ref' => $r,
                        'label' => IR_Catalog::info_fields()[$i['field']] ?? $i['field'],
                        'value' => $i['value'],
                        'lines' => [
                            ['k' => 'Fonte', 'v' => self::file_label($state, $i['file_id']) . ' — ' . $pos, 'style' => ''],
                            ['k' => 'Citazione', 'v' => '«' . $i['quote'] . '»', 'style' => 'i'],
                        ],
                    ];
                }
            }
        }

        $C = [];
        foreach ($sections as $s) {
            foreach ($s['citations'] as $ci => $c) {
                $src = match ($c['src']['kind'] ?? '') {
                    'file' => self::file_label($state, $c['src']['file_id']),
                    'interview' => 'Note dell\'intervista al management',
                    'norm' => 'Riferimento normativo interno (aggiornato a maggio 2026)',
                    default => $c['title'],
                };
                $pg = $c['page_start'] ? ' — pag. ' . $c['page_start'] . (($c['page_end'] && $c['page_end'] != $c['page_start']) ? '-' . $c['page_end'] : '') : '';
                $C[] = ['n' => $s['cite_base'] + $ci + 1, 'source' => $src . $pg, 'text' => '«' . trim($c['cited_text']) . '»'];
            }
        }

        return [
            'refs' => $refs,
            'calc' => [
                'title' => 'Appendice A — Tracciabilità dei calcoli',
                'intro' => 'Per ogni indicatore citato nel report: formula applicata, valori utilizzati (con collegamento alla rispettiva fonte), risultato ed eventuale soglia di valutazione. I calcoli sono eseguiti senza arrotondamenti intermedi; i valori sono esposti con ' . IR_Format::rounding_note('eur') . ', percentuali e indici con 1-2 decimali.',
                'entries' => $A,
            ],
            'data' => [
                'title' => 'Appendice B — Registro dei dati estratti',
                'intro' => 'Per ogni dato utilizzato: documento di provenienza, pagina o posizione, citazione testuale, livello di affidabilità ed eventuali interventi manuali del consulente.',
                'entries' => $B,
            ],
            'cites' => [
                'title' => 'Appendice C — Citazioni documentali',
                'intro' => 'Passaggi dei documenti a supporto delle affermazioni qualitative, numerati come nel testo.',
                'entries' => $C,
            ],
        ];
    }
}
