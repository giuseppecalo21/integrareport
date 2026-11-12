<?php
/**
 * Esportazione .docx (OOXML scritto direttamente, nessuna dipendenza esterna).
 * Design system Integra: Arial; H1 706F6F 18pt; H2 009FE3 13pt; tabelle con intestazione 009FE3,
 * righe alternate F4F4F4/FFFFFF, bordi CCCCCC, larghezze in DXA; copertina con banda laterale blu.
 * Ogni numero è un collegamento interno all'appendice di tracciabilità (formula, valori, fonte).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Docx
{
    private const BLUE = '009FE3';
    private const GRAY = '706F6F';
    private const TEXT = '222222';
    private const ROW = 'F4F4F4';
    private const LIGHT = 'E6F6FD';
    private const CONTENT_W = 9638; // 11906 - 2 × 1134

    private array $state;
    private array $model;
    private array $calc;
    private array $bm = [];          // ref => nome segnalibro
    private array $rels = [];        // collegamenti esterni
    private array $citeIndex = [];   // "sid:n" => numero globale
    private array $cites = [];       // numero globale => citazione
    private int $bmId = 1;

    public function __construct(array $state)
    {
        $this->state = $state;
        $this->model = IR_Document::build($state);
        $this->calc = $state['kpi'] ?? ['nodes' => [], 'data' => []];
    }

    public static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function filename(): string
    {
        $name = sanitize_file_name($this->state['profile']['ragione_sociale'] ?: 'Report');
        return 'Report_Consulenza_' . $name . '_' . wp_date('Y-m-d') . '.docx';
    }

    /** Genera il file e restituisce il percorso temporaneo. */
    public function build(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione PHP zip non disponibile sul server.');
        }
        $refs = $this->closure(IR_Document::used_refs($this->model));
        $i = 0;
        foreach ($refs as $r) {
            $this->bm[$r] = 'ir_r' . (++$i);
        }

        $body = $this->cover() . $this->content($refs);
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $body . '</w:body></w:document>';

        $tmp = wp_tempnam('ir-report.docx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $this->content_types());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', $this->core());
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->addFromString('word/numbering.xml', $this->numbering());
        $zip->addFromString('word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:updateFields w:val="true"/><w:defaultTabStop w:val="708"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>');
        $zip->addFromString('word/header1.xml', $this->header());
        $zip->addFromString('word/footer1.xml', $this->footer());
        $zip->addFromString('word/_rels/document.xml.rels', $this->doc_rels());
        $zip->close();
        return $tmp;
    }

    /** Chiusura transitiva dei riferimenti: ogni indicatore porta con sé i suoi ingressi. */
    private function closure(array $refs): array
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
                foreach ($this->calc['nodes'][substr($r, 2)]['inputs'] ?? [] as $in) {
                    if (!empty($in['ref'])) {
                        $queue[] = $in['ref'];
                    }
                }
            }
        }
        $k = array_values(array_filter(array_keys($out), fn($r) => str_starts_with($r, 'K:')));
        $v = array_values(array_filter(array_keys($out), fn($r) => str_starts_with($r, 'V:')));
        $i = array_values(array_filter(array_keys($out), fn($r) => str_starts_with($r, 'I:')));
        return array_merge($k, $v, $i);
    }

    // ------------------------------------------------------------------ primitive

    private function run(string $text, array $o = []): string
    {
        $rpr = '';
        if (!empty($o['style'])) {
            $rpr .= '<w:rStyle w:val="' . $o['style'] . '"/>';
        }
        if (!empty($o['b'])) {
            $rpr .= '<w:b/>';
        }
        if (!empty($o['i'])) {
            $rpr .= '<w:i/>';
        }
        if (!empty($o['color'])) {
            $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        }
        if (!empty($o['sz'])) {
            $rpr .= '<w:sz w:val="' . $o['sz'] . '"/><w:szCs w:val="' . $o['sz'] . '"/>';
        }
        if (!empty($o['hl'])) {
            $rpr .= '<w:highlight w:val="' . $o['hl'] . '"/>';
        }
        if (!empty($o['sup'])) {
            $rpr .= '<w:vertAlign w:val="superscript"/>';
        }
        if (!empty($o['u'])) {
            $rpr .= '<w:u w:val="single"/>';
        }
        return '<w:r>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '<w:t xml:space="preserve">' . self::x($text) . '</w:t></w:r>';
    }

    private function para(string $runs, array $o = []): string
    {
        $ppr = '';
        if (!empty($o['style'])) {
            $ppr .= '<w:pStyle w:val="' . $o['style'] . '"/>';
        }
        if (!empty($o['keep'])) {
            $ppr .= '<w:keepNext/>';
        }
        if (!empty($o['pagebreak'])) {
            $ppr .= '<w:pageBreakBefore/>';
        }
        if (!empty($o['border'])) {
            $ppr .= '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="4" w:color="' . self::BLUE . '"/></w:pBdr>';
        }
        if (isset($o['num'])) {
            $ppr .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $o['num'] . '"/></w:numPr>';
        }
        if (isset($o['spacing'])) {
            $ppr .= '<w:spacing w:before="' . $o['spacing'][0] . '" w:after="' . $o['spacing'][1] . '"/>';
        }
        if (!empty($o['jc'])) {
            $ppr .= '<w:jc w:val="' . $o['jc'] . '"/>';
        }
        if (!empty($o['sect'])) {
            $ppr .= $o['sect'];
        }
        if (!empty($o['rpr'])) {
            $ppr .= '<w:rPr>' . $o['rpr'] . '</w:rPr>';
        }
        return '<w:p>' . ($ppr ? "<w:pPr>$ppr</w:pPr>" : '') . $runs . '</w:p>';
    }

    private function bookmark(string $name, string $inner): string
    {
        $id = $this->bmId++;
        return '<w:bookmarkStart w:id="' . $id . '" w:name="' . $name . '"/>' . $inner . '<w:bookmarkEnd w:id="' . $id . '"/>';
    }

    private function ref_run(string $ref, string $text, bool $bold = false): string
    {
        $r = $this->run($text, ['style' => 'IrRef', 'b' => $bold]);
        return isset($this->bm[$ref]) ? '<w:hyperlink w:anchor="' . $this->bm[$ref] . '">' . $r . '</w:hyperlink>' : $r;
    }

    private function ext_link(string $text, string $url): string
    {
        $id = 'rIdL' . (count($this->rels) + 1);
        $this->rels[$id] = $url;
        return '<w:hyperlink r:id="' . $id . '">' . $this->run($text, ['style' => 'Hyperlink']) . '</w:hyperlink>';
    }

    private function inline(array $runs, string $sid = ''): string
    {
        $out = '';
        foreach ($runs as $r) {
            $b = !empty($r['b']);
            switch ($r['t']) {
                case 'text':
                    $out .= $this->run($r['v'], ['b' => $b]);
                    break;
                case 'ref':
                    $out .= $this->ref_run($r['ref'], $r['v'], $b);
                    break;
                case 'flag':
                    $out .= $this->run($r['v'], ['b' => $b, 'hl' => 'yellow']);
                    break;
                case 'bad':
                    $out .= $this->run($r['v'], ['hl' => 'red']);
                    break;
                case 'link':
                    $out .= $this->ext_link($r['v'], $r['href']);
                    break;
                case 'cite':
                    $key = $sid . ':' . $r['c'];
                    $n = $this->citeIndex[$key] ?? null;
                    if ($n !== null) {
                        $out .= '<w:hyperlink w:anchor="ir_c' . $n . '">' . $this->run('[' . $n . ']', ['sup' => true, 'color' => self::BLUE]) . '</w:hyperlink>';
                    }
                    break;
            }
        }
        return $out;
    }

    private function blocks(array $blocks, string $sid = '', array $tables = []): string
    {
        $out = '';
        foreach ($blocks as $b) {
            switch ($b['type']) {
                case 'h2':
                    $out .= $this->para($this->inline($b['inline'], $sid), ['style' => 'Heading2', 'keep' => true]);
                    break;
                case 'p':
                    $out .= $this->para($this->inline($b['inline'], $sid), ['jc' => 'both']);
                    break;
                case 'ul':
                case 'ol':
                    foreach ($b['items'] as $it) {
                        $out .= $this->para($this->inline($it, $sid), ['num' => $b['type'] === 'ul' ? 1 : $this->new_num()]);
                    }
                    break;
                case 'table':
                    if (isset($tables[$b['id']])) {
                        $out .= $this->table($tables[$b['id']]);
                    }
                    break;
            }
        }
        return $out;
    }

    private int $numCounter = 1;
    private array $olNums = [];

    private function new_num(): int
    {
        // ogni elenco numerato ricomincia da 1: numId distinti che puntano allo stesso abstractNum
        static $current = null;
        $this->numCounter++;
        $this->olNums[] = $this->numCounter;
        return $this->numCounter;
    }

    private function cell(string $content, int $w, ?string $fill, array $o = []): string
    {
        $tcpr = '<w:tcW w:w="' . $w . '" w:type="dxa"/>';
        if ($fill) {
            $tcpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>';
        }
        $tcpr .= '<w:tcMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar>';
        return '<w:tc><w:tcPr>' . $tcpr . '</w:tcPr>' . $this->para($content, ['style' => 'IrTable', 'jc' => $o['jc'] ?? 'left']) . '</w:tc>';
    }

    private function table(array $t): string
    {
        $ncols = count($t['columns']);
        $first = $ncols > 3 ? 3400 : ($ncols === 2 ? 4800 : 4200);
        $rest = $ncols > 1 ? intdiv(self::CONTENT_W - $first, $ncols - 1) : 0;
        $widths = array_merge([$first], array_fill(0, max(0, $ncols - 1), $rest));
        $grid = implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $widths));
        $border = '<w:tblBorders>' . implode('', array_map(fn($s) => "<w:$s w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"CCCCCC\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders>';

        $xml = $this->para($this->run($t['title'], ['b' => true, 'color' => self::GRAY]), ['keep' => true, 'spacing' => [200, 80]]);
        $xml .= '<w:tbl><w:tblPr><w:tblW w:w="' . self::CONTENT_W . '" w:type="dxa"/>' . $border . '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';
        // intestazione
        $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($t['columns'] as $i => $c) {
            $xml .= $this->cell($this->run($c, ['b' => true, 'color' => 'FFFFFF']), $widths[$i], self::BLUE, ['jc' => $i ? 'right' : 'left']);
        }
        $xml .= '</w:tr>';
        $fills = ['good' => 'E5F5E5', 'bad' => 'FFE5E5', 'warn' => 'FFF4D6'];
        foreach ($t['rows'] as $ri => $r) {
            $rowFill = !empty($r['total']) ? self::LIGHT : ($ri % 2 ? self::ROW : 'FFFFFF');
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            $xml .= $this->cell($this->run($r['label'], ['b' => !empty($r['total'])]), $widths[0], $rowFill);
            foreach ($r['cells'] as $ci => $c) {
                $content = !empty($c['ref']) ? $this->ref_run($c['ref'], $c['v'], !empty($r['total'])) : $this->run($c['v'], ['b' => !empty($r['total'])]);
                $fill = isset($c['level'], $fills[$c['level']]) ? $fills[$c['level']] : $rowFill;
                $xml .= $this->cell($content, $widths[$ci + 1] ?? $rest, $fill, ['jc' => 'right']);
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>';
        foreach ($t['notes'] as $n) {
            $xml .= $this->para($this->run($n), ['style' => 'Caption']);
        }
        return $xml;
    }

    // ------------------------------------------------------------------ parti del documento

    private function cover(): string
    {
        $m = $this->model['meta'];
        $none = '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>';
        $right = '';
        $right .= $this->para($this->run('R E P O R T', ['b' => true, 'color' => self::GRAY, 'sz' => 72]), ['spacing' => [0, 120]]);
        $right .= $this->para($this->run('Analisi Economico-Finanziaria', ['color' => self::BLUE, 'sz' => 28]), ['spacing' => [0, 40]]);
        $right .= $this->para($this->run('e Strategica', ['color' => self::BLUE, 'sz' => 28]), ['spacing' => [0, 600]]);
        $right .= $this->para($this->run('PREPARATO PER', ['b' => true, 'color' => self::GRAY, 'sz' => 22]), ['spacing' => [0, 80]]);
        $right .= $this->para($this->run($m['ragione_sociale'] ?: '—', ['b' => true, 'color' => self::GRAY, 'sz' => 36]), ['spacing' => [0, 80]]);
        $right .= $this->para($this->run($m['forma'] . ($m['piva'] ? '  │  P.IVA ' . $m['piva'] : ''), ['color' => self::GRAY, 'sz' => 22]), ['spacing' => [0, 600]]);
        $right .= $this->para($this->run($m['data'], ['color' => self::GRAY, 'sz' => 22]), ['spacing' => [0, 60]]);
        $right .= $this->para($this->run('Periodo di riferimento: ' . $m['periodo'], ['color' => self::BLUE, 'i' => true, 'sz' => 22]), ['spacing' => [0, 60]]);
        $right .= $this->para($this->run('Finalità: ' . $m['finalita'], ['color' => self::GRAY, 'sz' => 20]), ['spacing' => [0, 2400]]);
        $right .= $this->para($this->run('Documento riservato — Versione 1.0 — Rev. del ' . $m['data'], ['color' => self::GRAY, 'sz' => 18]));
        if ($m['studio']) {
            $right .= $this->para($this->run($m['studio'] . ($m['sito'] ? '  │  ' . $m['sito'] : ''), ['color' => self::GRAY, 'sz' => 18]));
        }

        $tbl = '<w:tbl><w:tblPr><w:tblW w:w="11906" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="1100"/><w:gridCol w:w="10806"/></w:tblGrid>'
            . '<w:tr><w:trPr><w:trHeight w:val="16200" w:hRule="exact"/></w:trPr>'
            . '<w:tc><w:tcPr><w:tcW w:w="1100" w:type="dxa"/>' . $none . '<w:shd w:val="clear" w:color="auto" w:fill="' . self::BLUE . '"/></w:tcPr><w:p/></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="10806" w:type="dxa"/>' . $none . '<w:tcMar><w:top w:w="4320" w:type="dxa"/><w:left w:w="720" w:type="dxa"/><w:right w:w="720" w:type="dxa"/></w:tcMar></w:tcPr>' . $right . '</w:tc>'
            . '</w:tr></w:tbl>';

        $sect = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="0" w:right="0" w:bottom="0" w:left="0" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>';
        return $tbl . $this->para('', ['sect' => $sect, 'spacing' => [0, 0], 'rpr' => '<w:sz w:val="2"/>']);
    }

    private function h1(string $text, bool $pagebreak = true): string
    {
        return $this->para('', ['border' => true, 'spacing' => [0, 0], 'pagebreak' => $pagebreak]) . $this->para($this->run($text), ['style' => 'Heading1', 'keep' => true]);
    }

    private function content(array $refs): string
    {
        $m = $this->model;
        // numerazione globale delle citazioni
        $n = 0;
        foreach ($m['sections'] as $s) {
            foreach ($s['citations'] as $ci => $c) {
                $this->citeIndex[$s['id'] . ':' . $ci] = ++$n;
                $this->cites[$n] = $c;
            }
        }

        $x = '';
        // indice (campo TOC con elenco statico come risultato iniziale)
        $x .= $this->para($this->run('Indice'), ['style' => 'Heading1']);
        $x .= '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \\o "1-2" \\h \\z \\u </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . '<w:r><w:t xml:space="preserve">Premessa metodologica</w:t></w:r></w:p>';
        foreach ($m['sections'] as $s) {
            $x .= $this->para($this->run($s['title']));
        }
        foreach (['Checklist delle operazioni', 'Fonti normative e bibliografia', 'Disclaimer professionale', 'Appendice A — Tracciabilità dei calcoli', 'Appendice B — Registro dei dati estratti', 'Appendice C — Citazioni documentali'] as $t) {
            $x .= $this->para($this->run($t));
        }
        $x .= '<w:p><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
        $x .= $this->para($this->run("Per aggiornare l'indice con i numeri di pagina: in Word, clic destro sull'indice > Aggiorna campo.", ['i' => true, 'color' => self::GRAY, 'sz' => 16]));

        $x .= $this->h1('Premessa metodologica');
        $x .= $this->blocks($m['premessa']);

        foreach ($m['sections'] as $s) {
            $x .= $this->h1($s['title']);
            $x .= $this->blocks($s['blocks'], $s['id'], $s['tables']);
        }

        $x .= $this->h1('Checklist delle operazioni');
        $x .= $this->blocks($m['checklist']);
        $x .= $this->h1('Fonti normative e bibliografia');
        $x .= $this->blocks($m['fonti']);
        $x .= $this->h1('Disclaimer professionale');
        $x .= $this->blocks($m['disclaimer']);

        $x .= $this->appendix_calc($refs);
        $x .= $this->appendix_data($refs);
        $x .= $this->appendix_cites();

        $sect = '<w:sectPr><w:headerReference w:type="default" r:id="rIdH1"/><w:footerReference w:type="default" r:id="rIdF1"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr>';
        return $x . $sect;
    }

    private function input_value(array $in): string
    {
        if (!empty($in['param'])) {
            $v = (float) $in['value'];
            return abs($v) < 1 && $v != 0.0 ? IR_Format::num($v * 100, 4) . '%' : IR_Format::num($v, 2);
        }
        if ($in['ref'] && str_starts_with($in['ref'], 'K:')) {
            $n = $this->calc['nodes'][substr($in['ref'], 2)] ?? null;
            return $n && $n['value'] !== null ? ($n['display'] ?? IR_Format::value($n['value'], $n['unit'])) : 'non disponibile';
        }
        if ($in['ref'] && str_starts_with($in['ref'], 'V:')) {
            $e = $this->calc['data'][substr($in['ref'], 2)] ?? null;
            return $e && $e['value'] !== null ? IR_Format::value($e['value'], $e['unit']) : 'non disponibile';
        }
        return $in['value'] === null ? 'non disponibile' : IR_Format::num((float) $in['value'], 2);
    }

    private function appendix_calc(array $refs): string
    {
        $x = $this->h1('Appendice A — Tracciabilità dei calcoli');
        $x .= $this->para($this->run('Per ogni indicatore citato nel report: formula applicata, valori utilizzati (con collegamento alla rispettiva fonte), risultato ed eventuale soglia di valutazione. I calcoli sono eseguiti senza arrotondamenti intermedi; i valori sono esposti con ' . IR_Format::rounding_note('eur') . ', percentuali e indici con 1-2 decimali.'), ['jc' => 'both']);
        foreach ($refs as $r) {
            if (!str_starts_with($r, 'K:')) {
                continue;
            }
            $n = $this->calc['nodes'][substr($r, 2)] ?? null;
            if (!$n) {
                continue;
            }
            $val = $n['value'] === null ? 'non valutabile' : ($n['display'] ?? IR_Format::value($n['value'], $n['unit']));
            $head = $this->run($n['label'] . ' — ' . IR_Format::period_label($n['period']) . ': ', ['b' => true, 'color' => self::GRAY]) . $this->run($val, ['b' => true, 'color' => self::BLUE]);
            $x .= $this->para($this->bookmark($this->bm[$r], $head), ['keep' => true, 'spacing' => [200, 40]]);
            $x .= $this->para($this->run('Formula: ', ['b' => true]) . $this->run($n['formula']), ['style' => 'IrSmall']);
            foreach ($n['inputs'] as $in) {
                $label = $in['sym'] . ' = ';
                $v = $this->input_value($in);
                $src = $in['ref'] ? $this->ref_run($in['ref'], $v) : $this->run($v);
                $desc = $in['param'] ? ' (parametro del modello — ' . $in['param'] . ')' : ' — ' . $in['label'];
                $x .= $this->para($this->run($label) . $src . $this->run($desc, ['color' => self::GRAY]), ['style' => 'IrSmall', 'num' => 1]);
            }
            if ($n['value'] === null) {
                $why = $n['nv_reason'] ?: implode('; ', $n['missing']);
                $x .= $this->para($this->run('Non valutabile: ', ['b' => true]) . $this->run($why), ['style' => 'IrSmall']);
            }
            if ($n['eval']) {
                $x .= $this->para($this->run('Valutazione: ', ['b' => true]) . $this->run($n['eval']['text'] . ' — ' . $n['eval']['soglia'] . ' (fonte: ' . $n['eval']['fonte'] . ')'), ['style' => 'IrSmall']);
            }
            if ($n['note']) {
                $x .= $this->para($this->run('Nota: ', ['b' => true]) . $this->run($n['note']), ['style' => 'IrSmall']);
            }
        }
        return $x;
    }

    private function file_label(?string $file_id): string
    {
        foreach ($this->state['files'] as $f) {
            if ($f['id'] === $file_id) {
                return $f['slot'] . ' — ' . (IR_Catalog::documents()[$f['slot']]['name'] ?? '') . ' — file "' . $f['name'] . '"';
            }
        }
        return 'documento non più presente';
    }

    private function appendix_data(array $refs): string
    {
        $x = $this->h1('Appendice B — Registro dei dati estratti');
        $x .= $this->para($this->run('Per ogni dato utilizzato: documento di provenienza, pagina o posizione, citazione testuale, livello di affidabilità ed eventuali interventi manuali del consulente.'), ['jc' => 'both']);
        foreach ($refs as $r) {
            if (str_starts_with($r, 'V:')) {
                $e = $this->calc['data'][substr($r, 2)] ?? null;
                if (!$e) {
                    continue;
                }
                $val = $e['value'] === null ? 'non disponibile' : IR_Format::value($e['value'], $e['unit']);
                $head = $this->run($e['label'] . ' — ' . IR_Format::period_label($e['period']) . ': ', ['b' => true, 'color' => self::GRAY]) . $this->run($val, ['b' => true, 'color' => self::BLUE]);
                $x .= $this->para($this->bookmark($this->bm[$r], $head), ['keep' => true, 'spacing' => [200, 40]]);
                $s = $e['source'];
                if (!empty($s['manual'])) {
                    $x .= $this->para($this->run('Inserito manualmente da ' . $s['by'] . ' il ' . IR_Format::date_it((int) $s['at']) . '. Motivazione: ' . $s['note']), ['style' => 'IrSmall']);
                } elseif ($s) {
                    $pos = $s['page'] ? 'pag. ' . $s['page'] : ($s['pos'] ?: 'posizione n.d.');
                    if ($s['page'] && $s['pos']) {
                        $pos .= ', ' . $s['pos'];
                    }
                    $x .= $this->para($this->run('Fonte: ', ['b' => true]) . $this->run($this->file_label($s['file_id']) . ' — ' . $pos), ['style' => 'IrSmall']);
                    $x .= $this->para($this->run('Citazione: ', ['b' => true]) . $this->run('«' . $s['quote'] . '»', ['i' => true]), ['style' => 'IrSmall']);
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
                    $x .= $this->para($this->run($extra, ['color' => self::GRAY]), ['style' => 'IrSmall']);
                }
            } elseif (str_starts_with($r, 'I:')) {
                foreach ($this->state['extraction']['info'] as $i) {
                    if ('I:' . $i['id'] === $r) {
                        $head = $this->run((IR_Catalog::info_fields()[$i['field']] ?? $i['field']) . ': ', ['b' => true, 'color' => self::GRAY]) . $this->run($i['value'], ['b' => true, 'color' => self::BLUE]);
                        $x .= $this->para($this->bookmark($this->bm[$r], $head), ['keep' => true, 'spacing' => [200, 40]]);
                        $pos = $i['page'] ? 'pag. ' . $i['page'] : ($i['pos'] ?: 'posizione n.d.');
                        $x .= $this->para($this->run('Fonte: ', ['b' => true]) . $this->run($this->file_label($i['file_id']) . ' — ' . $pos), ['style' => 'IrSmall']);
                        $x .= $this->para($this->run('Citazione: ', ['b' => true]) . $this->run('«' . $i['quote'] . '»', ['i' => true]), ['style' => 'IrSmall']);
                    }
                }
            }
        }
        return $x;
    }

    private function appendix_cites(): string
    {
        if (!$this->cites) {
            return '';
        }
        $x = $this->h1('Appendice C — Citazioni documentali');
        $x .= $this->para($this->run('Passaggi dei documenti a supporto delle affermazioni qualitative, numerati come nel testo.'));
        foreach ($this->cites as $n => $c) {
            $src = match ($c['src']['kind'] ?? '') {
                'file' => $this->file_label($c['src']['file_id']),
                'interview' => 'Note dell\'intervista al management',
                'norm' => 'Riferimento normativo interno (aggiornato a maggio 2026)',
                default => $c['title'],
            };
            $pg = $c['page_start'] ? ' — pag. ' . $c['page_start'] . (($c['page_end'] && $c['page_end'] != $c['page_start']) ? '-' . $c['page_end'] : '') : '';
            $head = $this->run('[' . $n . '] ', ['b' => true, 'color' => self::BLUE]) . $this->run($src . $pg, ['b' => true]);
            $x .= $this->para($this->bookmark('ir_c' . $n, $head), ['style' => 'IrSmall', 'keep' => true]);
            $x .= $this->para($this->run('«' . trim($c['cited_text']) . '»', ['i' => true]), ['style' => 'IrSmall']);
        }
        return $x;
    }

    // ------------------------------------------------------------------ parti statiche del pacchetto

    private function header(): string
    {
        $m = $this->model['meta'];
        $p = $this->para($this->run('REPORT DI CONSULENZA  •  ' . ($m['ragione_sociale'] ?: '') . '  •  ' . $m['periodo'], ['color' => self::GRAY, 'sz' => 16]), ['jc' => 'right', 'border' => true]);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . $p . '</w:hdr>';
    }

    private function footer(): string
    {
        $m = $this->model['meta'];
        $left = $this->run(($m['studio'] ?: 'Integra'), ['color' => self::GRAY, 'sz' => 16]);
        if ($m['sito']) {
            $left .= $this->run('  │  ', ['color' => self::BLUE, 'sz' => 16]) . $this->run($m['sito'], ['color' => self::GRAY, 'sz' => 16]);
        }
        $left .= $this->run('  │  ', ['color' => self::BLUE, 'sz' => 16]) . $this->run('Pagina ', ['color' => self::GRAY, 'sz' => 16])
            . '<w:r><w:rPr><w:color w:val="706F6F"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:color w:val="706F6F"/><w:sz w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:rPr><w:color w:val="706F6F"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:color w:val="706F6F"/><w:sz w:val="16"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:color w:val="706F6F"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>'
            . $this->run('  │  Emesso il ' . $m['data'], ['color' => self::GRAY, 'sz' => 16]);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . $this->para($left) . '</w:ftr>';
    }

    private function doc_rels(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdN" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rIdT" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            . '<Relationship Id="rIdH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
            . '<Relationship Id="rIdF1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
        foreach ($this->rels as $id => $url) {
            $x .= '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . self::x($url) . '" TargetMode="External"/>';
        }
        return $x . '</Relationships>';
    }

    private function content_types(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
    }

    private function core(): string
    {
        $t = self::x('Report di Consulenza — ' . ($this->state['profile']['ragione_sociale'] ?? ''));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . "<dc:title>$t</dc:title><dc:creator>" . self::x((string) get_option('ir_studio', 'Integra')) . '</dc:creator>'
            . "<dcterms:created xsi:type=\"dcterms:W3CDTF\">$now</dcterms:created><dcterms:modified xsi:type=\"dcterms:W3CDTF\">$now</dcterms:modified></cp:coreProperties>";
    }

    private function numbering(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="singleLevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="567" w:hanging="283"/></w:pPr><w:rPr><w:color w:val="' . self::BLUE . '"/></w:rPr></w:lvl></w:abstractNum>'
            . '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="singleLevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="567" w:hanging="340"/></w:pPr></w:lvl></w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>';
        foreach ($this->olNums as $id) {
            $x .= '<w:num w:numId="' . $id . '"><w:abstractNumId w:val="1"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride></w:num>';
        }
        return $x . '</w:numbering>';
    }

    private function styles(): string
    {
        $s = static fn($id, $name, $rpr, $ppr = '', $type = 'paragraph', $extra = '') => '<w:style w:type="' . $type . '" w:styleId="' . $id . '"' . ($type === 'paragraph' && $id === 'Normal' ? ' w:default="1"' : '') . '><w:name w:val="' . $name . '"/>' . $extra . ($ppr ? "<w:pPr>$ppr</w:pPr>" : '') . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '</w:style>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial" w:eastAsia="Arial"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:color w:val="' . self::TEXT . '"/><w:lang w:val="it-IT"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="288" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . $s('Normal', 'Normal', '')
            . $s('Heading1', 'heading 1', '<w:b/><w:color w:val="' . self::GRAY . '"/><w:sz w:val="36"/><w:szCs w:val="36"/>', '<w:keepNext/><w:spacing w:before="160" w:after="200"/><w:outlineLvl w:val="0"/>', 'paragraph', '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>')
            . $s('Heading2', 'heading 2', '<w:b/><w:color w:val="' . self::BLUE . '"/><w:sz w:val="26"/><w:szCs w:val="26"/>', '<w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="1"/>', 'paragraph', '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>')
            . $s('Caption', 'caption', '<w:i/><w:color w:val="' . self::GRAY . '"/><w:sz w:val="18"/><w:szCs w:val="18"/>', '<w:spacing w:before="40" w:after="40"/>', 'paragraph', '<w:basedOn w:val="Normal"/>')
            . $s('IrTable', 'Integra Tabella', '<w:sz w:val="18"/><w:szCs w:val="18"/>', '<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>', 'paragraph', '<w:basedOn w:val="Normal"/>')
            . $s('IrSmall', 'Integra Nota', '<w:sz w:val="18"/><w:szCs w:val="18"/>', '<w:spacing w:before="0" w:after="40"/>', 'paragraph', '<w:basedOn w:val="Normal"/>')
            . $s('TOC1', 'toc 1', '<w:b/><w:color w:val="' . self::GRAY . '"/><w:sz w:val="24"/>', '<w:spacing w:before="120" w:after="60"/>', 'paragraph', '<w:basedOn w:val="Normal"/>')
            . $s('TOC2', 'toc 2', '<w:sz w:val="22"/>', '<w:ind w:left="284"/><w:spacing w:after="40"/>', 'paragraph', '<w:basedOn w:val="Normal"/>')
            . $s('Hyperlink', 'Hyperlink', '<w:color w:val="' . self::BLUE . '"/><w:u w:val="single"/>', '', 'character')
            . $s('IrRef', 'Integra Valore tracciato', '<w:color w:val="' . self::TEXT . '"/><w:u w:val="dotted" w:color="' . self::BLUE . '"/>', '', 'character')
            . '</w:styles>';
    }
}
