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
        self::$factNumbers = self::fact_numbers($state);
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
            // residui di formattazione che non devono arrivare al lettore
            if (preg_match('/^(```.*|[-*_]{3,}|={3,})$/u', $line)) {
                $line = '';
            }
            $line = preg_replace('/^>\s*/u', '', $line);
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

    /** Numeri presenti nei fatti documentati: se ripresi nel testo non sono "numeri senza fonte". */
    private static array $factNumbers = [];

    private static function norm_number(string $n): string
    {
        return preg_replace('/[\s€x]/u', '', $n);
    }

    private static function fact_numbers(array $state): array
    {
        $set = [];
        foreach ($state['extraction']['info'] ?? [] as $i) {
            if (preg_match_all(self::NUM_PATTERN, $i['value'], $m)) {
                foreach ($m[0] as $n) {
                    $set[self::norm_number($n)] = true;
                }
            }
        }
        return $set;
    }

    /** Elimina i residui di sintassi Markdown da un tratto di testo semplice. */
    public static function clean_text(string $t): string
    {
        $t = str_replace(['`', '⟦', '⟧'], '', $t);
        $t = preg_replace('/(?<![\p{L}\p{N}])[*_]+(?=[\p{L}\p{N}"«“(])|(?<=[\p{L}\p{N}"»”).,;:!?])[*_]+(?![\p{L}\p{N}])/u', '', $t);
        $t = preg_replace('/\*+/u', '', $t);
        return preg_replace('/^#{1,6}\s+/u', '', $t);
    }

    private static function inline(string $s, array $calc, array $state, array &$warnings): array
    {
        $runs = [];
        $bold = false;
        $parts = preg_split('/(\[\[(?:K|V|I|F):[^\]]+\]\]|⟦C:\d+⟧|\*\*)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            if ($part === '**') {
                $bold = !$bold;
                continue;
            }
            if (preg_match('/^⟦C:(\d+)⟧$/u', $part, $m)) {
                $runs[] = ['t' => 'cite', 'c' => (int) $m[1]];
                continue;
            }
            if (preg_match('/^\[\[F:([^\]]+)\]\]$/', $part, $m)) {
                // marcatore di fonte di un fatto riformulato: invisibile al lettore, serve alla tracciabilità
                $ref = 'I:' . trim($m[1]);
                if (self::ref_display($ref, $calc, $state) !== null) {
                    $runs[] = ['t' => 'src', 'ref' => $ref];
                }
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
            $part = self::clean_text($part);
            $offset = 0;
            if (preg_match_all(self::NUM_PATTERN, $part, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as [$match, $pos]) {
                    if (isset(self::$factNumbers[self::norm_number($match)])) {
                        continue; // numero contenuto in un fatto documentato
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

    /**
     * Numerazione progressiva dei capitoli effettivamente presenti nel report: i capitoli non
     * selezionati o esclusi (analitici senza indicatori calcolabili) non lasciano buchi.
     * @return array<string,int> id sezione => numero
     */
    public static function numbering(array $state): array
    {
        $calc = $state['kpi'] ?? null;
        $tb = $calc ? new IR_Tables($state, $calc) : null;
        $map = [];
        $n = 0;
        foreach (IR_Catalog::sections() as $sid => $s) {
            if (!in_array($sid, $state['sections_selected'] ?? [], true)) {
                continue;
            }
            if (($state['report']['sections'][$sid]['status'] ?? '') === 'skipped') {
                continue;
            }
            if ($tb && $s['kind'] === 'analitica' && $sid !== 'S22' && !$tb->for_section($sid)) {
                continue;
            }
            $map[$sid] = ++$n;
        }
        return $map;
    }

    public static function section_title(string $sid, ?int $num): string
    {
        if ($sid === 'S0') {
            return 'Sommario esecutivo';
        }
        $s = IR_Catalog::sections()[$sid];
        return ($num ? $num . '. ' : '') . $s['title'];
    }

    /** Riallinea al numero definitivo le intestazioni "N.M Titolo" scritte durante la stesura. */
    private static function renumber_blocks(array $blocks, int $num, array $hiddenCites): array
    {
        $fix = static function (array $inline) use ($hiddenCites): array {
            $out = [];
            foreach ($inline as $r) {
                if (($r['t'] ?? '') === 'cite' && isset($hiddenCites[$r['c']])) {
                    continue;
                }
                if (($r['t'] ?? '') === 'text') {
                    $r['v'] = self::clean_text($r['v']);
                }
                $out[] = $r;
            }
            return $out;
        };
        foreach ($blocks as &$b) {
            if (isset($b['inline'])) {
                $b['inline'] = $fix($b['inline']);
                if ($b['type'] === 'h2' && $num && isset($b['inline'][0]) && ($b['inline'][0]['t'] ?? '') === 'text') {
                    $b['inline'][0]['v'] = preg_replace('/^\s*§?\s*\d+\.(\d+)/u', $num . '.$1', $b['inline'][0]['v']);
                }
            }
            if (isset($b['items'])) {
                $b['items'] = array_map($fix, $b['items']);
            }
        }
        unset($b);
        return $blocks;
    }

    /** Descrizione formale di un documento caricato, senza nome del file. */
    public static function formal_doc_name(array $state, array $file): string
    {
        $doc = $state['extraction']['docs'][$file['id']] ?? null;
        $tipo = trim((string) ($doc['tipo'] ?? ''));
        $tipo = trim(preg_replace('/\s*[-—–]\s*(scala|importi)\b.*$/iu', '', $tipo), " .\t\n");
        if ($tipo !== '' && mb_strlen($tipo) <= 260) {
            return mb_strtoupper(mb_substr($tipo, 0, 1)) . mb_substr($tipo, 1);
        }
        return IR_Catalog::documents()[$file['slot']]['name'] ?? 'Documento aziendale';
    }

    /** Modello completo del report. */
    public static function build(array $state): array
    {
        $profile = $state['profile'];
        $calc = $state['kpi'] ?? ['nodes' => [], 'data' => [], 'checks' => [], 'periods' => ['annual' => []]];
        $report = $state['report'] ?? ['sections' => []];
        $catalog = IR_Catalog::sections();
        $finDef = IR_Catalog::finalita()[$profile['finalita']] ?? null;
        $fin = $finDef['label'] ?? '';
        $finTxt = $fin . ($profile['finalita_altro'] ? ' — ' . $profile['finalita_altro'] : '');
        $periods = $calc['periods']['annual'] ?? [];
        $periodTxt = $periods ? (count($periods) > 1 ? 'esercizi ' . reset($periods) . '-' . end($periods) : 'esercizio ' . reset($periods)) : ($profile['esercizi'] ?: 'n.d.');
        $date = IR_Format::date_it((int) ($report['generated_at'] ?? time()));

        // sezioni in ordine, con numerazione progressiva
        $numbering = self::numbering($state);
        $order = array_merge(['S0'], array_values(array_filter(array_keys($catalog), static fn($s) => in_array($s, $state['sections_selected'], true))));
        $sections = [];
        $n = 0;
        foreach ($order as $sid) {
            $sec = $report['sections'][$sid] ?? null;
            if (!$sec || ($sec['status'] ?? '') !== 'ok') {
                continue;
            }
            $num = $sid === 'S0' ? null : ++$n;
            // le citazioni che non puntano a un documento del cliente non vengono mai esposte
            $hidden = [];
            foreach ($sec['citations'] as $ci => $c) {
                if (!in_array($c['src']['kind'] ?? '', ['file', 'interview'], true)) {
                    $hidden[$ci] = true;
                    $sec['citations'][$ci]['hidden'] = true;
                }
            }
            $tables = $sec['tables'];
            if (isset($tables['benchmark']) && ($note = IR_Context::benchmark_note($state)) && !in_array($note, $tables['benchmark']['notes'], true)) {
                $tables['benchmark']['notes'][] = $note;
            }
            $sections[] = ['id' => $sid, 'num' => $num, 'title' => self::section_title($sid, $num), 'blocks' => self::renumber_blocks($sec['blocks'], (int) $num, $hidden), 'tables' => $tables, 'citations' => $sec['citations'], 'warnings' => $sec['warnings']];
        }

        // perimetro: analisi richieste dalla finalità ma non presenti nel report
        $present = array_column($sections, 'id');
        $missing = [];
        foreach ($finDef['mandatory'] ?? [] as $sid) {
            if (!in_array($sid, $present, true)) {
                $missing[] = $catalog[$sid]['title'];
            }
        }
        $coverFin = $finTxt;
        if ($missing && $present) {
            $coverFin = 'Analisi economico-finanziaria — perimetro parziale rispetto alla finalità «' . $fin . '»';
        }

        $meta = [
            'ragione_sociale' => $profile['ragione_sociale'],
            'forma' => $profile['forma_giuridica'],
            'piva' => $profile['partita_iva'],
            'data' => $date,
            'periodo' => $periodTxt,
            'finalita' => $coverFin,
            'finalita_dichiarata' => $finTxt,
            'perimetro_parziale' => (bool) ($missing && $present),
            'studio' => (string) get_option('ir_studio', 'Integra'),
            'sito' => (string) get_option('ir_sito', ''),
            'consulente' => (string) get_option('ir_consulente', ''),
        ];

        $premessa = [
            self::p("Il presente Report di Consulenza è stato redatto nell'ambito di un'analisi economico-finanziaria e strategica della società {$profile['ragione_sociale']}, con finalità: " . rtrim($finTxt, '. ') . '.'),
        ];
        if ($missing && $present) {
            $premessa[] = self::p('Rispetto al perimetro previsto per questa finalità, il report non comprende le seguenti analisi: ' . implode('; ', $missing) . '. Il documento copre pertanto solo in parte tale perimetro e non può essere utilizzato come valutazione completa ai fini della finalità indicata.');
        }
        $premessa = array_merge($premessa, [
            self::p("L'analisi è stata condotta sulla base della documentazione fornita dalla committente e delle informazioni rese disponibili durante l'attività consulenziale. L'elenco dei documenti esaminati è riportato nella sezione \"Documentazione esaminata e perimetro dell'analisi\"."),
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
            self::p("Ogni dato numerico è stato tratto dai documenti esaminati, con registrazione del documento, della pagina e del passaggio di riferimento; ogni indicatore è stato calcolato con formula esplicita, senza stime né arrotondamenti intermedi. I valori dei prospetti contabili sono quelli degli schemi ufficiali di bilancio; per gli esercizi i cui saldi sono stati riesposti nel bilancio successivo sono stati utilizzati i saldi riesposti. Gli schemi riclassificati sono stati sottoposti a controlli di quadratura con i totali di bilancio. Il dettaglio di formule, fonti e controlli è conservato nel fascicolo di lavoro dello studio."),
            ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Limitazioni']]],
            self::p("Per ogni indicatore o sezione che non è stato possibile sviluppare per assenza o incompletezza di documentazione è riportata la dicitura \"Dato non valutabile per assenza di documentazione specifica\" oppure \"non valutabile\". Nessun dato è stato inventato, stimato per analogia o desunto in via presuntiva."),
            self::p('Il presente report ha valore esclusivamente consulenziale e gestionale. Non costituisce certificazione di bilancio, revisione legale dei conti, parere legale o fiscale vincolante. Le valutazioni espresse sono frutto di analisi tecnica condotta in buona fede sulla base delle informazioni disponibili alla data di emissione.'),
        ]);

        return [
            'meta' => $meta,
            'premessa' => $premessa,
            'sections' => $sections,
            'documentazione' => self::documentazione($state, $sections, $missing),
            'appendice' => self::appendice_contabile($state),
            'checklist' => self::checklist($state, $sections),
            'fonti' => self::fonti(),
            'disclaimer' => self::disclaimer($state),
        ];
    }

    /** Sezione destinata al cliente: documenti esaminati (descrizione formale) e perimetro dell'analisi. */
    private static function documentazione(array $state, array $sections, array $missing): array
    {
        $b = [];
        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Documenti esaminati']]];
        $items = [];
        foreach ($state['files'] as $f) {
            if (($f['extraction']['status'] ?? '') === 'error') {
                continue;
            }
            $items[self::formal_doc_name($state, $f)] = true;
        }
        if (trim((string) ($state['interview']['text'] ?? '')) !== '') {
            $items['Intervista al management' . (!empty($state['interview']['date']) ? ' del ' . $state['interview']['date'] : '')] = true;
        }
        $b[] = $items ? self::ul(array_keys($items)) : self::p('Nessun documento.');

        $limited = [];
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
            if ($nv) {
                $limited[] = $s['title'] . ' — indicatori non valutabili per assenza di documentazione specifica: ' . implode(', ', array_slice(array_keys($nv), 0, 8)) . (count($nv) > 8 ? ' e altri' : '') . '.';
            }
        }
        if ($limited || $missing) {
            $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => "Limiti del perimetro dell'analisi"]]];
            if ($missing) {
                $b[] = self::p('Analisi previste dalla finalità del report e non comprese nel presente documento: ' . implode('; ', $missing) . '.');
            }
            if ($limited) {
                $b[] = self::ul($limited);
            }
        }
        return $b;
    }

    /** Appendice contabile del report per il cliente: prospetti riclassificati e indici. */
    private static function appendice_contabile(array $state): array
    {
        if (empty($state['kpi'])) {
            return [];
        }
        $tb = new IR_Tables($state, $state['kpi']);
        $out = [];
        foreach (['sp_finanziario', 'sp_funzionale', 'ce_va', 'rendiconto', 'indici_reddituali', 'indici_liquidita', 'indici_solidita', 'indici_finanziari'] as $tid) {
            $t = $tb->build($tid);
            if ($t) {
                $t['notes'] = [];
                $out[] = $t;
            }
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
                    $not[] = "{$s['title']} — Motivo: {$r['reason']}; sezione esclusa per non presentare un capitolo privo di contenuti valutabili";
                }
                continue;
            }
            if (!$enabled[$sid]['enabled']) {
                $miss = array_map(static fn($g) => implode(' oppure ', $g), $enabled[$sid]['missing']);
                $not[] = "{$s['title']} — Motivo: documentazione mancante (" . implode('; ', $miss) . ')';
            } else {
                $not[] = "{$s['title']} — Motivo: non selezionata per la finalità del report";
            }
        }
        $b[] = ['type' => 'h2', 'inline' => [['t' => 'text', 'v' => 'Analisi non effettuate']]];
        $b[] = $not ? self::ul($not) : self::p('Nessuna.');

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
                'Parametri del modello di calcolo: ' . IR_Params::get()['versione'] . '.',
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
                if (in_array($r['t'] ?? '', ['ref', 'src'], true)) {
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
}
