<?php
/**
 * Estrazione dati: un documento per chiamata. Claude restituisce, tramite structured output,
 * i singoli valori con codice canonico, periodo, pagina, posizione e citazione testuale.
 * Nessun calcolo viene affidato al modello: somme, medie e indici li esegue IR_Calculator.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Extractor
{
    /** Parte del documento da cui è letto un valore. */
    public const ORIGINI = ['prospetto', 'nota_integrativa', 'relazione', 'altro'];
    /** Categorie di documento da cui non si estraggono valori di bilancio (solo informazioni qualitative). */
    public const SLOT_SOLO_QUALITATIVI = ['B7'];

    public static function system_prompt(): string
    {
        $dict = [];
        foreach (IR_Catalog::data_dictionary() as $code => $d) {
            $dict[] = "- {$code}: {$d['label']}" . ($d['hint'] ? " — {$d['hint']}" : '');
        }
        $info = [];
        foreach (IR_Catalog::info_fields() as $k => $label) {
            $info[] = "- {$k}: {$label}";
        }

        return "Sei un analista contabile incaricato di ESTRARRE dati da documenti aziendali italiani (bilanci CEE, visure, Centrale Rischi, scadenziari, budget, piani di ammortamento) per un report di consulenza professionale. Il tuo lavoro sarà verificato voce per voce: l'affidabilità conta più della completezza.

REGOLE INDEROGABILI
1. Riporta SOLO valori scritti nel documento. Non calcolare, non sommare, non sottrarre, non stimare, non dedurre, non completare voci mancanti. Se un valore si otterrebbe solo con un calcolo, NON riportarlo: i calcoli li esegue un motore separato.
2. Ogni valore deve avere: numero di pagina del file PDF (la prima pagina del file è 1, indipendentemente dalla numerazione stampata) oppure, per fogli di calcolo e testi, la posizione (es. \"Foglio CE, cella C14\", \"Riga 12\", \"§34\"); e una citazione testuale breve (massimo 200 caratteri) copiata dal documento, che contenga l'etichetta della voce e il numero esattamente come stampato.
3. Il campo \"valore\" è il numero come stampato, convertito dalla notazione italiana (1.234.567,89 → 1234567.89). Non applicare scale: se il documento esprime gli importi in migliaia, riporta il numero stampato e indica la scala in documento.scala_importi.
4. Segno: riporta il valore con il segno con cui appare nel prospetto. Se è preceduto da \"-\" o è tra parentesi, è negativo; altrimenti è positivo. Non cambiare segno per convenzioni di calcolo (es. i costi della voce B e gli interessi C.17 sono positivi se stampati positivi; le perdite sono negative).
5. Colonne multiple: se il prospetto riporta più esercizi (corrente e precedente), estrai i valori di ciascuna colonna con il rispettivo periodo.
6. Periodo: esercizio annuale → \"AAAA\" (anno della data di chiusura; se l'esercizio non è solare indicalo in nota); situazione infrannuale, scadenziari, piani di ammortamento → \"AAAA-MM-GG\" (data di riferimento); Centrale Rischi → \"AAAA-MM\" (mese di rilevazione); dati prospettici → anno di riferimento \"AAAA\" o, per piani a 12 mesi, la data di inizio \"AAAA-MM-GG\".
7. Voci assenti: SOLO per le voci degli schemi di Stato Patrimoniale (codici SP_*) e Conto Economico (codici CE_*), se il prospetto è completo e leggibile e la voce semplicemente non compare (per legge le voci a zero possono essere omesse), riporta valore 0 con stato \"assente_nel_prospetto\" e, come citazione, una descrizione del controllo eseguito (es. \"Voce non presente nel prospetto di Conto Economico, pagg. 4-5\"). Non usare mai questo stato per gli altri codici, né quando il prospetto è parziale, illeggibile o abbreviato in modo da accorpare la voce.
8. Gerarchia delle fonti contabili: i valori di Stato Patrimoniale (SP_*), Conto Economico (CE_*) e Rendiconto finanziario (RF_*) si leggono ESCLUSIVAMENTE dai prospetti contabili ufficiali (schemi ex artt. 2424 e 2425 c.c., rendiconto OIC 10). Le tabelle della Nota Integrativa si usano solo per le voci che il prospetto non espone (es. crediti verso clienti nei bilanci abbreviati, numero medio dei dipendenti). Non estrarre MAI valori numerici dalla Relazione sulla gestione, dalle relazioni del collegio sindacale o del revisore, né da tabelle riclassificate o riepilogative: da quelle parti si traggono solo informazioni qualitative. Per ogni valore indica in \"origine\" la parte del documento da cui è letto.
8-bis. Scadenza di crediti e debiti: nello schema ordinario ogni voce di C.II e di D riporta nel prospetto la riga \"esigibili oltre l'esercizio successivo\": estraila per ciascuna voce e per ciascuna colonna (esercizio corrente e precedente) con i codici SP_CII_OLTRE_* e SP_D*_OLTRE; se la riga non compare per una voce, riporta 0 con stato \"assente_nel_prospetto\". I codici aggregati SP_CII_ENTRO, SP_CII_OLTRE, SP_D_ENTRO e SP_D_OLTRE vanno usati solo quando il prospetto (schema abbreviato) stampa la riga riferita al totale: non ricavarli dalle tabelle di scadenza della Nota Integrativa, che non riportano l'esercizio precedente e non ripartiscono tutte le voci.
9. Affidabilità: \"alta\" se il numero è chiaramente leggibile e univocamente riferito alla voce; \"media\" se la lettura è corretta ma il riferimento richiede interpretazione (es. etichetta diversa dallo schema CEE); \"bassa\" se scansione poco leggibile o dubbio: in questo caso spiega il dubbio nella nota.
10. Usa esclusivamente i codici del dizionario. Se una voce del documento non corrisponde con certezza a un codice, non forzarla: segnalala negli avvisi.
11. Informazioni qualitative: riporta solo fatti scritti nel documento (nomi, date, quote, cariche, codici), in forma sintetica e fedele, con pagina e citazione. Nessuna valutazione. Nel campo \"valore\" scrivi solo il contenuto, senza ripetere il nome del campo o un'etichetta iniziale (scrivi \"vendita di gas naturale ed energia elettrica\", non \"Attività svolta: vendita di...\"), in una forma che possa essere ripresa in una frase. Quando il fatto è riferito a una data o a un esercizio (addetti, cariche, capitale, eventi), indicalo nel testo (es. \"14 nel 2024, di cui 1 dirigente e 13 impiegati\").
12. Se il documento non corrisponde alla categoria indicata, imposta documento.corrisponde_alla_categoria = false e spiegalo in documento.note; estrai comunque solo ciò che è certo.
13. Scrivi note e avvisi in italiano professionale e conciso.

DIZIONARIO DEI CODICI NUMERICI
" . implode("\n", $dict) . "

CAMPI DELLE INFORMAZIONI QUALITATIVE
" . implode("\n", $info);
    }

    public static function schema(): array
    {
        $nullableInt = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['documento', 'valori', 'informazioni', 'avvisi'],
            'properties' => [
                'documento' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['tipo_riconosciuto', 'corrisponde_alla_categoria', 'periodi_presenti', 'scala_importi', 'note'],
                    'properties' => [
                        'tipo_riconosciuto' => ['type' => 'string'],
                        'corrisponde_alla_categoria' => ['type' => 'boolean'],
                        'periodi_presenti' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'scala_importi' => ['type' => 'string', 'enum' => ['unita', 'migliaia', 'milioni']],
                        'note' => ['type' => 'string'],
                    ],
                ],
                'valori' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['codice', 'periodo', 'valore', 'stato', 'origine', 'pagina', 'posizione', 'citazione', 'affidabilita', 'nota'],
                        'properties' => [
                            'codice' => ['type' => 'string', 'enum' => array_keys(IR_Catalog::data_dictionary())],
                            'periodo' => ['type' => 'string'],
                            'valore' => ['type' => 'number'],
                            'stato' => ['type' => 'string', 'enum' => ['rilevato', 'assente_nel_prospetto']],
                            'origine' => ['type' => 'string', 'enum' => self::ORIGINI],
                            'pagina' => $nullableInt,
                            'posizione' => ['type' => 'string'],
                            'citazione' => ['type' => 'string'],
                            'affidabilita' => ['type' => 'string', 'enum' => ['alta', 'media', 'bassa']],
                            'nota' => ['type' => 'string'],
                        ],
                    ],
                ],
                'informazioni' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['campo', 'valore', 'pagina', 'posizione', 'citazione'],
                        'properties' => [
                            'campo' => ['type' => 'string', 'enum' => array_keys(IR_Catalog::info_fields())],
                            'valore' => ['type' => 'string'],
                            'pagina' => $nullableInt,
                            'posizione' => ['type' => 'string'],
                            'citazione' => ['type' => 'string'],
                        ],
                    ],
                ],
                'avvisi' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * Estrae un file. Restituisce ['doc' => ..., 'candidates' => [...], 'info' => [...], 'warnings' => [...], 'usage' => ...]
     */
    public static function extract_file(int $report_id, array $state, array $file): array
    {
        $docs = IR_Catalog::documents();
        $slot = $file['slot'];
        $codes = IR_Catalog::extraction_slot_codes($slot);
        $dict = IR_Catalog::data_dictionary();

        $lines = [];
        foreach ($codes as $c) {
            $lines[] = "- {$c}: {$dict[$c]['label']}";
        }
        $profile = $state['profile'];
        $intro = "Categoria del documento: {$slot} — {$docs[$slot]['name']}.\n"
            . "Nome del file: {$file['name']}.\n"
            . "Impresa analizzata (dichiarata dal consulente): {$profile['ragione_sociale']} ({$profile['forma_giuridica']}).\n"
            . ($profile['esercizi'] !== '' ? "Esercizi da analizzare indicati dal consulente: {$profile['esercizi']}.\n" : '')
            . "\n" . ($codes
                ? "Codici numerici da cercare prioritariamente in questo documento:\n" . implode("\n", $codes ? $lines : []) . "\n\nPuoi riportare anche altri codici del dizionario se presenti con certezza."
                : (in_array($slot, self::SLOT_SOLO_QUALITATIVI, true)
                    ? "Da questo documento vanno estratte SOLO informazioni qualitative (attività svolta, appartenenza a un gruppo e rapporti con parti correlate, fatti di rilievo successivi alla chiusura, contenziosi, continuità aziendale, organi sociali, personale). Non riportare valori di Stato Patrimoniale, Conto Economico o Rendiconto finanziario: eventuali tabelle numeriche della relazione vanno ignorate."
                    : "Per questa categoria non sono previsti codici numerici specifici: estrai soprattutto le informazioni qualitative; riporta codici numerici del dizionario solo se presenti con certezza."))
            . "\n\nEstrai i dati seguendo rigorosamente le regole.";

        $content = [
            IR_Claude::file_block($report_id, $file, false),
            ['type' => 'text', 'text' => $intro],
        ];

        $res = IR_Claude::call(self::system_prompt(), $content, self::schema(), 'extract');
        $data = json_decode($res['text'], true);
        if (!is_array($data)) {
            throw new RuntimeException('Output di estrazione non valido (JSON non interpretabile).');
        }

        $scale = match ($data['documento']['scala_importi'] ?? 'unita') {
            'migliaia' => 1000,
            'milioni' => 1000000,
            default => 1,
        };

        $candidates = [];
        $warnings = [];
        foreach (($data['valori'] ?? []) as $v) {
            $code = (string) ($v['codice'] ?? '');
            if (!isset($dict[$code])) {
                continue;
            }
            $period = trim((string) ($v['periodo'] ?? ''));
            if ($period === '' || !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $period)) {
                $warnings[] = "Valore {$code} scartato: periodo non valido (\"{$period}\").";
                continue;
            }
            $raw = (float) $v['valore'];
            $stato = $v['stato'] === 'assente_nel_prospetto' ? 'assente' : 'rilevato';
            if ($stato === 'assente' && !preg_match('/^(SP|CE)_/', $code)) {
                $warnings[] = "Valore {$code} scartato: lo stato \"assente nel prospetto\" è ammesso solo per le voci di SP e CE.";
                continue;
            }
            $origine = in_array($v['origine'] ?? '', self::ORIGINI, true) ? $v['origine'] : 'altro';
            if (preg_match('/^(SP|CE|RF)_/', $code) && ($origine === 'relazione' || in_array($slot, self::SLOT_SOLO_QUALITATIVI, true))) {
                $skippedRel = ($skippedRel ?? 0) + 1;
                continue;
            }
            $unit = $dict[$code]['unit'];
            $mult = $unit === 'eur' ? $scale : 1;
            $aff = $v['affidabilita'];
            $checks = [];
            if ($stato === 'rilevato' && !self::citation_contains_number((string) $v['citazione'], $raw)) {
                $aff = 'bassa';
                $checks[] = 'La citazione non contiene il numero estratto: verificare manualmente.';
            }
            $candidates[] = [
                'id' => 'c' . substr(md5($file['id'] . $code . $period . $raw . wp_rand()), 0, 10),
                'code' => $code,
                'period' => $period,
                'raw' => $raw,
                'scale' => $mult,
                'value' => $raw * $mult,
                'stato' => $stato,
                'file_id' => $file['id'],
                'slot' => $slot,
                'origine' => $origine,
                'page' => is_int($v['pagina'] ?? null) ? $v['pagina'] : null,
                'pos' => (string) ($v['posizione'] ?? ''),
                'quote' => (string) ($v['citazione'] ?? ''),
                'aff' => $aff,
                'note' => trim((string) ($v['nota'] ?? '') . ($checks ? ' ' . implode(' ', $checks) : '')),
            ];
        }

        if (!empty($skippedRel)) {
            $warnings[] = "{$skippedRel} valori di bilancio non acquisiti perché letti da una relazione o da un prospetto non ufficiale: i numeri si prendono solo dagli schemi di Stato Patrimoniale, Conto Economico e Rendiconto finanziario.";
        }

        $info = [];
        foreach (($data['informazioni'] ?? []) as $i) {
            $i['valore'] = self::clean_info_value((string) ($i['campo'] ?? ''), (string) ($i['valore'] ?? ''));
            if ($i['valore'] === '') {
                continue;
            }
            $info[] = [
                'id' => 'i' . substr(md5($file['id'] . wp_rand() . ($i['campo'] ?? '')), 0, 10),
                'field' => (string) $i['campo'],
                'value' => (string) $i['valore'],
                'file_id' => $file['id'],
                'slot' => $slot,
                'page' => is_int($i['pagina'] ?? null) ? $i['pagina'] : null,
                'pos' => (string) ($i['posizione'] ?? ''),
                'quote' => (string) ($i['citazione'] ?? ''),
            ];
        }

        foreach (($data['avvisi'] ?? []) as $w) {
            $warnings[] = (string) $w;
        }

        return [
            'doc' => [
                'file_id' => $file['id'],
                'tipo' => (string) ($data['documento']['tipo_riconosciuto'] ?? ''),
                'match' => (bool) ($data['documento']['corrisponde_alla_categoria'] ?? true),
                'periodi' => $data['documento']['periodi_presenti'] ?? [],
                'scala' => $data['documento']['scala_importi'] ?? 'unita',
                'note' => (string) ($data['documento']['note'] ?? ''),
                'model' => $res['model'],
                'usage' => $res['usage'],
                'at' => time(),
            ],
            'candidates' => $candidates,
            'info' => $info,
            'warnings' => array_map(static fn($w) => ['file_id' => $file['id'], 'msg' => $w], $warnings),
        ];
    }

    /**
     * Toglie dal valore di un'informazione qualitativa l'eventuale etichetta iniziale
     * ("Attività svolta: ...", "Soci e quote: ..."), che nel testo produrrebbe ripetizioni.
     */
    public static function clean_info_value(string $field, string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (!preg_match('/^([^:]{3,60}):\s+(.+)$/us', $value, $m)) {
            return $value;
        }
        $norm = static fn(string $t): string => trim(preg_replace('/[^a-z0-9àèéìòù]+/u', ' ', mb_strtolower($t)));
        $prefix = $norm($m[1]);
        $labels = [];
        foreach (IR_Catalog::info_fields() as $k => $label) {
            $labels[] = $norm($label);
            $labels[] = $norm((string) preg_split('/[\/(]/u', $label)[0]);
            $labels[] = $norm(str_replace('_', ' ', $k));
        }
        $labels = array_merge($labels, ['attività svolta', 'attività esercitata', 'attività prevalente', 'socio unico', 'soci', 'compagine sociale', 'evento successivo', 'numero medio dipendenti', 'numero dipendenti', 'dipendenti', 'amministratori', 'organo amministrativo', 'nota']);
        foreach ($labels as $l) {
            if ($l !== '' && ($prefix === $l || str_starts_with($prefix, $l . ' '))) {
                return trim($m[2]);
            }
        }
        return $value;
    }

    /** Controllo automatico: la sequenza di cifre del valore compare nella citazione? */
    public static function citation_contains_number(string $quote, float $raw): bool
    {
        $qd = preg_replace('/\D/', '', $quote);
        $int = (string) (int) floor(abs($raw));
        if ($int === '0') {
            return str_contains($quote, '0') || str_contains($quote, '-') || str_contains($quote, '—');
        }
        return $qd !== '' && str_contains($qd, $int);
    }
}
