<?php
/**
 * Parametri normativi, soglie e benchmark utilizzati dal motore di calcolo.
 * Valori di default ripresi dalla skill (formule_kpi.md, sicilia_normativa.md, aggiornata a maggio 2026)
 * e sovrascrivibili dall'amministratore in Impostazioni > Parametri (JSON).
 * Ogni parametro usato in un calcolo viene riportato nella traccia del KPI con la sua fonte.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Params
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'versione' => 'Parametri di default — skill report-consulenza-pmi-sicilia v2.0.0 (riferimenti normativi aggiornati a maggio 2026)',

            // Soglie di valutazione (verde / giallo / rosso). dir: 'high' = più alto è meglio, 'low' = più basso è meglio.
            'soglie' => [
                'PFN_EBITDA' => ['dir' => 'low', 'good' => 3.0, 'bad' => 5.0, 'fonte' => 'Prassi bancaria PMI (formule_kpi.md §4)'],
                'PFN_PN' => ['dir' => 'low', 'good' => 1.0, 'bad' => 2.0, 'fonte' => 'formule_kpi.md §4'],
                'LEVERAGE' => ['dir' => 'low', 'good' => 2.0, 'bad' => 3.0, 'fonte' => 'formule_kpi.md §4'],
                'COPERTURA_OF' => ['dir' => 'high', 'good' => 5.0, 'bad' => 2.0, 'fonte' => 'formule_kpi.md §4'],
                'OF_RICAVI' => ['dir' => 'low', 'good' => 1.5, 'bad' => 3.8, 'fonte' => 'Studio Di Crosta (formule_kpi.md §4)'],
                'ROE' => ['dir' => 'high', 'good' => 8.0, 'bad' => 0.0, 'fonte' => 'formule_kpi.md §5'],
                'ROI' => ['dir' => 'high', 'good' => 6.0, 'bad' => 3.0, 'fonte' => 'formule_kpi.md §5 (buono > 6-8% per PMI; soglia inferiore di prassi)'],
                'ROS' => ['dir' => 'high', 'good' => 5.0, 'bad' => 3.0, 'fonte' => 'formule_kpi.md §5 (3-5% normale)'],
                'ROA' => ['dir' => 'high', 'good' => 5.0, 'bad' => 2.0, 'fonte' => 'formule_kpi.md §5'],
                'NET_MARGIN' => ['dir' => 'high', 'good' => 5.0, 'bad' => 0.0, 'fonte' => 'formule_kpi.md §5'],
                'CR' => ['dir' => 'high', 'good' => 1.5, 'bad' => 1.0, 'fonte' => 'formule_kpi.md §6'],
                'QR' => ['dir' => 'high', 'good' => 1.0, 'bad' => 0.8, 'fonte' => 'formule_kpi.md §6'],
                'CASH_RATIO' => ['dir' => 'high', 'good' => 0.2, 'bad' => 0.1, 'fonte' => 'formule_kpi.md §6 (sano > 0,2)'],
                'DSO' => ['dir' => 'low', 'good' => 60, 'bad' => 90, 'fonte' => 'formule_kpi.md §6 (B2B PMI Italia)'],
                'CCC' => ['dir' => 'low', 'good' => 30, 'bad' => 90, 'fonte' => 'formule_kpi.md §6'],
                'AUTONOMIA' => ['dir' => 'high', 'good' => 33.0, 'bad' => 20.0, 'fonte' => 'formule_kpi.md §7'],
                'INDEBITAMENTO' => ['dir' => 'low', 'good' => 67.0, 'bad' => 80.0, 'fonte' => 'formule_kpi.md §7 (sano < 67%)'],
                'PN_TERZI' => ['dir' => 'high', 'good' => 100.0, 'bad' => 50.0, 'fonte' => 'formule_kpi.md §7'],
                'MS_PRIMARIO' => ['dir' => 'high', 'good' => 1.0, 'bad' => 0.7, 'fonte' => 'formule_kpi.md §7'],
                'MS_SECONDARIO' => ['dir' => 'high', 'good' => 1.0, 'bad' => 0.9, 'fonte' => 'formule_kpi.md §7 (regola d\'oro > 1)'],
                'DSCR' => ['dir' => 'high', 'good' => 1.1, 'bad' => 1.0, 'fonte' => 'Soglia di prassi adottata dal modello (formule_kpi.md §9): ≥ 1,1 adeguata; < 1,0 segnale di allarme'],
                'UTILIZZO_FIDI' => ['dir' => 'low', 'good' => 80.0, 'bad' => 100.0, 'fonte' => 'Prassi di analisi andamentale (utilizzo/accordato)'],
            ],

            'zscore' => [
                'Z1' => ['nome' => "Z'-Score (Altman 1983, società non quotate manifatturiere)", 'coeff' => ['X1' => 0.717, 'X2' => 0.847, 'X3' => 3.107, 'X4' => 0.420, 'X5' => 0.998], 'safe' => 2.90, 'distress' => 1.23],
                'Z2' => ['nome' => "Z''-Score (Altman, imprese non manifatturiere / servizi)", 'coeff' => ['X1' => 6.56, 'X2' => 3.26, 'X3' => 6.72, 'X4' => 1.05], 'safe' => 2.60, 'distress' => 1.10],
                'fonte' => 'Altman E.I. (1968, 1983); formule_kpi.md §8',
            ],

            // art. 3, comma 4, CCII e art. 25-novies, comma 1, CCII. Verificare sempre il testo vigente su Normattiva.
            'ccii' => [
                'retribuzioni_quota' => 0.5,
                'banche_quota' => 0.05,
                'inps_quota' => 0.30,
                'inps_minimo' => 15000,
                'inail_minimo' => 5000,
                'iva_minimo' => 5000,
                'iva_quota_volume_affari' => 0.10,
                'iva_soglia_assoluta' => 20000,
                'ader_soglie' => ['individuale' => 100000, 'persone' => 200000, 'capitali' => 500000],
                'fonte' => 'D.Lgs. 14/2019 (CCII), art. 3, comma 4, e art. 25-novies, comma 1 — soglie da verificare sul testo vigente (Normattiva) alla data del report',
            ],

            'zes' => [
                'aliquote' => ['micro' => 0.60, 'piccola' => 0.60, 'media' => 0.50, 'grande' => 0.40],
                'riparto_default' => 0.60,
                'investimento_minimo' => 200000,
                'quota_max_immobili' => 0.50,
                'ires' => 0.24,
                'irap_quota' => 0.015,
                'fonte' => 'D.L. 124/2023 art. 16; L. 199/2025; Carta Aiuti a finalità regionale 2022-2027 (sicilia_normativa.md, aggiornato a maggio 2026). Riparto 2025 applicato da AdE: 60,3811%.',
            ],

            'stress' => [
                'scenari' => [
                    ['nome' => 'Base', 'shock_ebitda' => 0.0],
                    ['nome' => 'Avverso', 'shock_ebitda' => -0.15],
                    ['nome' => 'Peggiore', 'shock_ebitda' => -0.30],
                ],
                'fonte' => 'Shock convenzionali applicati all\'EBITDA prospettico (parametri del modello, modificabili)',
            ],

            'benchmark' => [
                'manifattura' => ['label' => 'Industria manifatturiera', 'ebitda_margin' => [8, 15], 'pfn_ebitda_max' => 3.0, 'dso' => [90, 120], 'autonomia_min' => 25, 'manifatturiero' => true],
                'costruzioni' => ['label' => 'Costruzioni', 'ebitda_margin' => [5, 12], 'pfn_ebitda_max' => 4.0, 'dso' => [90, 180], 'autonomia_min' => 20, 'manifatturiero' => false],
                'ingrosso' => ['label' => "Commercio all'ingrosso", 'ebitda_margin' => [3, 8], 'pfn_ebitda_max' => 3.5, 'dso' => [60, 90], 'autonomia_min' => 20, 'manifatturiero' => false],
                'dettaglio' => ['label' => 'Commercio al dettaglio', 'ebitda_margin' => [4, 10], 'pfn_ebitda_max' => 3.0, 'dso' => [0, 30], 'autonomia_min' => 25, 'manifatturiero' => false],
                'servizi_prof' => ['label' => 'Servizi professionali', 'ebitda_margin' => [10, 25], 'pfn_ebitda_max' => 2.5, 'dso' => [60, 90], 'autonomia_min' => 30, 'manifatturiero' => false],
                'turismo' => ['label' => 'Turismo / Ristorazione', 'ebitda_margin' => [8, 20], 'pfn_ebitda_max' => 4.0, 'dso' => [0, 30], 'autonomia_min' => 15, 'manifatturiero' => false],
                'ict' => ['label' => 'ICT / Software', 'ebitda_margin' => [10, 25], 'pfn_ebitda_max' => 2.0, 'dso' => [60, 90], 'autonomia_min' => 35, 'manifatturiero' => false],
                'agricoltura' => ['label' => 'Agricoltura / Pesca', 'ebitda_margin' => [5, 15], 'pfn_ebitda_max' => 4.0, 'dso' => [30, 90], 'autonomia_min' => 20, 'manifatturiero' => false],
                'trasporti' => ['label' => 'Trasporti / Logistica', 'ebitda_margin' => [6, 12], 'pfn_ebitda_max' => 4.0, 'dso' => [60, 90], 'autonomia_min' => 20, 'manifatturiero' => false],
            ],
            'benchmark_fonte' => 'Valori indicativi per PMI italiane (Banca d\'Italia, ABI, CERVED, Studio Di Crosta — formule_kpi.md §11). Per analisi puntuali consultare i parametri specifici per codice ATECO.',

            // Tabella rating (formule_kpi.md §12). Ogni banda: [limite inferiore escluso / superiore] in ordine AAA → C.
            'rating' => [
                'scala' => ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D'],
                // per ciascun KPI: soglie minime (dir high) o massime (dir low) per le classi AAA..CC; sotto/oltre l'ultima = C
                'EBITDA_MARGIN' => ['dir' => 'high', 'limiti' => [15, 12, 9, 6, 4, 2, 0, -5]],
                'PFN_EBITDA' => ['dir' => 'low', 'limiti' => [1.5, 2.0, 3.0, 4.0, 5.0, 6.0, 8.0, 12.0]],
                'ZSCORE_Z1' => ['dir' => 'high', 'limiti' => [4.0, 3.0, 2.5, 1.8, 1.5, 1.2, 1.0, 0.7]],
                'DSCR' => ['dir' => 'high', 'limiti' => [2.0, 1.5, 1.2, 1.1, 1.0, 0.9, 0.7, 0.5]],
                'AUTONOMIA' => ['dir' => 'high', 'limiti' => [50, 40, 30, 25, 20, 15, 10, 5]],
                'fonte' => 'Modello di rating interno sintetico (formule_kpi.md §12): rating finale = mediana dei rating per KPI; -1 classe con segnali CCII attivi; -2 classi con PN negativo.',
            ],
        ];
    }

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $params = self::defaults();
        $raw = get_option('ir_params_override', '');
        if (is_string($raw) && trim($raw) !== '') {
            $over = json_decode($raw, true);
            if (is_array($over)) {
                $params = array_replace_recursive($params, $over);
            }
        }
        return self::$cache = $params;
    }

    public static function reset_cache(): void
    {
        self::$cache = null;
    }
}
