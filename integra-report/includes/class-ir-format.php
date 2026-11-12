<?php
/**
 * Formattazione numerica all'italiana (separatore migliaia "." e decimali ",").
 * Arrotondamenti come da formule_kpi.md: importi 2 decimali, percentuali e indici 1 decimale,
 * Z-Score e DSCR 2 decimali.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Format
{
    public static function num(?float $v, int $dec = 2): string
    {
        if ($v === null || is_nan($v) || is_infinite($v)) {
            return 'n.d.';
        }
        $s = number_format(abs($v), $dec, ',', '.');
        return ($v < 0 && (float) str_replace(['.', ','], ['', '.'], $s) != 0.0 ? '−' : '') . $s;
    }

    public static function value(?float $v, string $unit): string
    {
        if ($v === null) {
            return 'n.d.';
        }
        return match ($unit) {
            'eur' => self::num($v, 2) . ' €',
            'pct' => self::num($v, 1) . '%',
            'x' => self::num($v, 2) . 'x',
            'gg' => self::num($v, 0) . ' gg',
            'z' => self::num($v, 2),
            'ratio' => self::num($v, 2),
            'num' => self::num($v, 0),
            default => self::num($v, 2),
        };
    }

    /** Arrotondamento dichiarato per unità (usato nella traccia). */
    public static function rounding_note(string $unit): string
    {
        return match ($unit) {
            'eur' => 'importi arrotondati a 2 decimali',
            'pct' => 'percentuali arrotondate a 1 decimale',
            'gg' => 'giorni arrotondati all\'unità',
            'num' => 'arrotondato all\'unità',
            default => 'arrotondato a 2 decimali',
        };
    }

    public static function date_it(int $ts): string
    {
        $mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        return wp_date('j', $ts) . ' ' . $mesi[(int) wp_date('n', $ts) - 1] . ' ' . wp_date('Y', $ts);
    }

    /** Etichetta leggibile di un periodo: "2024", "30/09/2025", "09/2025". */
    public static function period_label(string $p): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $p, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) {
            return "{$m[2]}/{$m[1]}";
        }
        return $p;
    }
}
