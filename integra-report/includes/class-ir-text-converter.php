<?php
/**
 * Conversione in testo dei formati non PDF, conservando i riferimenti di posizione
 * (foglio e cella per Excel, paragrafo per Word, riga per CSV) così che le citazioni
 * dell'estrazione restino verificabili.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Text_Converter
{
    public const MAX_CHARS = 600000;

    public static function kind(string $ext): string
    {
        return match (strtolower($ext)) {
            'pdf' => 'pdf',
            'png', 'jpg', 'jpeg', 'webp', 'gif' => 'image',
            'xlsx' => 'xlsx',
            'docx' => 'docx',
            'csv' => 'csv',
            'txt', 'md' => 'text',
            'xml', 'xbrl' => 'xml',
            default => 'unsupported',
        };
    }

    public static function to_text(string $path, string $kind): string
    {
        $text = match ($kind) {
            'xlsx' => self::xlsx($path),
            'docx' => self::docx($path),
            'csv' => self::csv($path),
            'xml' => self::xml($path),
            'text' => (string) file_get_contents($path),
            default => '',
        };
        $text = self::utf8($text);
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new RuntimeException('Il documento convertito in testo supera ' . number_format(self::MAX_CHARS, 0, ',', '.') . ' caratteri: dividilo in file più piccoli.');
        }
        return $text;
    }

    private static function utf8(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $s;
    }

    private static function xlsx(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione PHP zip non disponibile: impossibile leggere file .xlsx.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File .xlsx non leggibile.');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = @simplexml_load_string($ss);
            if ($x) {
                foreach ($x->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string) $si->t;
                    } else {
                        $t = '';
                        foreach ($si->r as $r) {
                            $t .= (string) $r->t;
                        }
                        $shared[] = $t;
                    }
                }
            }
        }
        // nomi dei fogli
        $names = [];
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relmap = [];
        if ($rels !== false) {
            $rx = @simplexml_load_string($rels);
            if ($rx) {
                foreach ($rx->Relationship as $r) {
                    $relmap[(string) $r['Id']] = 'xl/' . ltrim(str_replace('/xl/', '', (string) $r['Target']), '/');
                }
            }
        }
        if ($wb !== false) {
            $wx = @simplexml_load_string($wb);
            if ($wx) {
                foreach ($wx->sheets->sheet as $sh) {
                    $rid = (string) $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    $names[] = ['name' => (string) $sh['name'], 'path' => $relmap[$rid] ?? ''];
                }
            }
        }
        $out = [];
        foreach ($names as $sheet) {
            $xml = $sheet['path'] ? $zip->getFromName($sheet['path']) : false;
            if ($xml === false) {
                continue;
            }
            $sx = @simplexml_load_string($xml);
            if (!$sx || !isset($sx->sheetData)) {
                continue;
            }
            $out[] = '=== Foglio: ' . $sheet['name'] . ' ===';
            foreach ($sx->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $ref = (string) $c['r'];
                    $t = (string) $c['t'];
                    $v = isset($c->v) ? (string) $c->v : '';
                    if ($t === 's') {
                        $v = $shared[(int) $v] ?? '';
                    } elseif ($t === 'inlineStr') {
                        $v = (string) ($c->is->t ?? '');
                    }
                    if (trim($v) !== '') {
                        $cells[] = $ref . ': ' . trim($v);
                    }
                }
                if ($cells) {
                    $out[] = implode(' | ', $cells);
                }
            }
        }
        $zip->close();
        return "Contenuto del foglio di calcolo (formato \"cella: valore\"; i numeri sono riportati come memorizzati nel file).\n" . implode("\n", $out);
    }

    private static function docx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File .docx non leggibile.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return '';
        }
        $xml = preg_replace('/<w:tab\/>/', "\t", $xml);
        $xml = preg_replace('/<\/w:tc>/', ' | ', $xml);
        $paras = preg_split('/<\/w:p>/', $xml);
        $out = [];
        $n = 0;
        foreach ($paras as $p) {
            $t = trim(html_entity_decode(wp_strip_all_tags($p), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($t !== '') {
                $n++;
                $out[] = "[§{$n}] {$t}";
            }
        }
        return "Contenuto del documento Word (paragrafi numerati [§n]).\n" . implode("\n", $out);
    }

    private static function csv(string $path): string
    {
        $raw = self::utf8((string) file_get_contents($path));
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        foreach ($lines as $i => $l) {
            if (trim($l) !== '') {
                $out[] = 'Riga ' . ($i + 1) . ': ' . $l;
            }
        }
        return "Contenuto del file CSV (righe numerate).\n" . implode("\n", $out);
    }

    private static function xml(string $path): string
    {
        $raw = (string) file_get_contents($path);
        // XBRL: rende leggibili i fatti "concetto [contesto] = valore"
        $x = @simplexml_load_string($raw);
        if (!$x) {
            return $raw;
        }
        $out = [];
        $walk = static function (SimpleXMLElement $el, string $prefix = '') use (&$walk, &$out) {
            foreach ($el->getNamespaces(true) as $ns) {
                foreach ($el->children($ns) as $child) {
                    $name = $child->getName();
                    $attrs = [];
                    foreach ($child->attributes() as $k => $v) {
                        $attrs[] = $k . '=' . $v;
                    }
                    $val = trim((string) $child);
                    if ($child->count() === 0 && $val !== '') {
                        $out[] = $prefix . $name . ($attrs ? ' [' . implode(', ', $attrs) . ']' : '') . ' = ' . $val;
                    }
                    if ($child->count() > 0) {
                        $walk($child, $prefix . $name . '/');
                    }
                }
            }
            foreach ($el->children() as $child) {
                $val = trim((string) $child);
                if ($child->count() === 0 && $val !== '') {
                    $out[] = $prefix . $child->getName() . ' = ' . $val;
                } elseif ($child->count() > 0) {
                    $walk($child, $prefix . $child->getName() . '/');
                }
            }
        };
        $walk($x);
        return "Contenuto del file XML/XBRL (concetto [attributi] = valore).\n" . implode("\n", array_unique($out));
    }
}
