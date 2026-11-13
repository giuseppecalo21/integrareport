<?php
/**
 * Catalogo statico: documenti input, sezioni del report, regole per finalità,
 * dizionario dei dati estraibili. Fonte: skill "report-consulenza-pmi-sicilia"
 * (references/mapping_input_sezioni.md, formule_kpi.md, template_report_struttura.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class IR_Catalog
{
    public const AREAS = [
        'A' => 'Anagrafica, Società e Governance',
        'B' => 'Bilanci, Contabilità e Dati Economico-Finanziari',
        'C' => 'Banche, Centrale Rischi e Rapporti Finanziari',
        'D' => 'Debiti Scaduti, Segnali di Crisi e Continuità',
        'E' => 'Assetti Organizzativi, Amministrativi e Contabili',
        'F' => 'Budget, Business Plan e Dati Prospettici',
        'G' => 'Mercato, Operatività, Investimenti',
        'X' => 'Informazioni raccolte dal consulente',
    ];

    /** Livello: base | sugg | opt */
    public static function documents(): array
    {
        return [
            'A1' => ['area' => 'A', 'name' => 'Visura camerale aggiornata (max 6 mesi)', 'level' => 'base', 'hint' => 'Registro Imprese / Telemaco'],
            'A2' => ['area' => 'A', 'name' => 'Statuto sociale e atto costitutivo', 'level' => 'base', 'hint' => 'Notaio / Camera di Commercio'],
            'A3' => ['area' => 'A', 'name' => 'Elenco soci e amministratori con quote', 'level' => 'base', 'hint' => 'Visura + Libro Soci'],
            'A4' => ['area' => 'A', 'name' => 'Organigramma aziendale aggiornato', 'level' => 'sugg', 'hint' => 'Documento interno'],
            'A5' => ['area' => 'A', 'name' => 'Mansionario / Job description ruoli chiave', 'level' => 'opt', 'hint' => 'Documento interno'],
            'A6' => ['area' => 'A', 'name' => 'Procure e deleghe operative', 'level' => 'opt', 'hint' => 'Atti notarili / interni'],
            'B1' => ['area' => 'B', 'name' => "Bilanci d'esercizio depositati ultimi 3 anni (SP + CE + Nota Integrativa)", 'level' => 'base', 'hint' => 'Telemaco / Bilancio depositato — un file per esercizio'],
            'B2' => ['area' => 'B', 'name' => 'Bilancio in formato XBRL (.xbrl)', 'level' => 'sugg', 'hint' => 'Telemaco'],
            'B3' => ['area' => 'B', 'name' => 'Bilancio di verifica infrannuale (max 90 gg)', 'level' => 'sugg', 'hint' => 'Software gestionale'],
            'B4' => ['area' => 'B', 'name' => 'Libro giornale / Mastrini contabili', 'level' => 'opt', 'hint' => 'Software gestionale'],
            'B5' => ['area' => 'B', 'name' => 'Dichiarazioni fiscali ultimi 2 anni (Redditi SC, IRAP)', 'level' => 'sugg', 'hint' => 'Cassetto fiscale AdE'],
            'B6' => ['area' => 'B', 'name' => 'Rendiconto Finanziario OIC 10 (se non già in B1)', 'level' => 'opt', 'hint' => "Predisposto dall'azienda"],
            'C1' => ['area' => 'C', 'name' => "Centrale Rischi Banca d'Italia (ultimi 6/12 mesi)", 'level' => 'sugg', 'hint' => "Banca d'Italia (gratuito)"],
            'C2' => ['area' => 'C', 'name' => 'Estratti conto bancari (ultimi 12 mesi)', 'level' => 'sugg', 'hint' => 'Home banking / Banche'],
            'C3' => ['area' => 'C', 'name' => 'Piani di ammortamento finanziamenti in essere', 'level' => 'sugg', 'hint' => 'Contratti bancari'],
            'C4' => ['area' => 'C', 'name' => 'Lettere di affidamento bancario (linee di fido)', 'level' => 'sugg', 'hint' => 'Banche'],
            'C5' => ['area' => 'C', 'name' => 'Estratti CRIF / CTC (se autorizzato)', 'level' => 'opt', 'hint' => 'CRIF / CTC'],
            'D1' => ['area' => 'D', 'name' => 'DURC (Documento Unico di Regolarità Contributiva)', 'level' => 'base', 'hint' => 'INPS / INAIL'],
            'D2' => ['area' => 'D', 'name' => 'Estratto debiti fiscali ed estratto di ruolo (AdER)', 'level' => 'sugg', 'hint' => 'Agenzia Entrate Riscossione'],
            'D3' => ['area' => 'D', 'name' => 'Estratto INPS posizione contributiva azienda', 'level' => 'sugg', 'hint' => 'INPS — Cassetto previdenziale'],
            'D4' => ['area' => 'D', 'name' => 'Scadenziario clienti aperto (aging report)', 'level' => 'sugg', 'hint' => 'Software gestionale'],
            'D5' => ['area' => 'D', 'name' => 'Scadenziario fornitori aperto (aging report)', 'level' => 'sugg', 'hint' => 'Software gestionale'],
            'D6' => ['area' => 'D', 'name' => 'Elenco contenziosi attivi e passivi', 'level' => 'opt', 'hint' => 'Studio legale / interno'],
            'D7' => ['area' => 'D', 'name' => 'Carichi pendenti (visura protesti)', 'level' => 'opt', 'hint' => 'Camera di Commercio'],
            'D8' => ['area' => 'D', 'name' => 'Rateazioni AdE / AdER / INPS in essere', 'level' => 'sugg', 'hint' => "Documenti dell'azienda"],
            'E1' => ['area' => 'E', 'name' => 'Manuale procedure amministrativo-contabili', 'level' => 'opt', 'hint' => 'Documento interno'],
            'E2' => ['area' => 'E', 'name' => 'Sistema di reporting interno (KPI periodici)', 'level' => 'opt', 'hint' => 'Documento interno'],
            'E3' => ['area' => 'E', 'name' => 'Verbali assemblee soci ultimi 2 anni', 'level' => 'opt', 'hint' => 'Libro Verbali'],
            'E4' => ['area' => 'E', 'name' => 'Verbali CdA ultimi 12 mesi', 'level' => 'opt', 'hint' => 'Libro Verbali'],
            'E5' => ['area' => 'E', 'name' => 'Modello 231 (D.Lgs. 231/2001) se adottato', 'level' => 'opt', 'hint' => 'Documento interno'],
            'F1' => ['area' => 'F', 'name' => 'Budget economico-finanziario anno corrente', 'level' => 'sugg', 'hint' => 'Documento interno'],
            'F2' => ['area' => 'F', 'name' => 'Business Plan 3-5 anni con proiezioni', 'level' => 'sugg', 'hint' => 'Documento interno'],
            'F3' => ['area' => 'F', 'name' => 'Piano di tesoreria a 12 mesi (cash flow forecast)', 'level' => 'opt', 'hint' => 'Documento interno'],
            'F4' => ['area' => 'F', 'name' => 'Piano industriale / strategico', 'level' => 'opt', 'hint' => 'Documento interno'],
            'F5' => ['area' => 'F', 'name' => 'Stress test / Sensitivity analysis', 'level' => 'opt', 'hint' => 'Documento interno'],
            'G1' => ['area' => 'G', 'name' => 'Elenco principali clienti (Top 10 con % fatturato)', 'level' => 'sugg', 'hint' => 'Software gestionale'],
            'G2' => ['area' => 'G', 'name' => 'Elenco principali fornitori (Top 10 con % acquisti)', 'level' => 'sugg', 'hint' => 'Software gestionale'],
            'G3' => ['area' => 'G', 'name' => 'Piano investimenti previsti (beni strumentali)', 'level' => 'opt', 'hint' => 'Documento interno'],
            'G4' => ['area' => 'G', 'name' => 'Contratti di locazione / leasing immobiliari', 'level' => 'opt', 'hint' => 'Documenti contrattuali'],
            'G5' => ['area' => 'G', 'name' => 'Polizze assicurative principali', 'level' => 'opt', 'hint' => 'Documenti contrattuali'],
            'INT' => ['area' => 'X', 'name' => 'Intervista al management (note del consulente)', 'level' => 'sugg', 'hint' => 'Compilata nel modulo, non si carica un file', 'virtual' => true],
        ];
    }

    /**
     * Sezioni. "required": elenco di gruppi in AND; ogni gruppo è un elenco di alternative in OR.
     * "kind": analitica (tabelle calcolate + commento) | qualitativa (documenti + citazioni).
     * "attach": documenti allegati alla chiamata di stesura (solo per sezioni qualitative/miste).
     */
    public static function sections(): array
    {
        return [
            'S1' => ['n' => 1, 'title' => 'Profilo Aziendale e Governance', 'required' => [['A1']], 'optional' => ['A2', 'A3', 'A4', 'A5', 'A6', 'E3', 'E4'], 'kind' => 'qualitativa', 'attach' => ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'E3', 'E4'],
                'outline' => ['1.1 Anagrafica societaria', '1.2 Compagine sociale', '1.3 Organi di amministrazione', '1.4 Struttura organizzativa', '1.5 Sintesi della governance'], 'tables' => ['anagrafica']],
            'S2' => ['n' => 2, 'title' => 'Posizionamento di Mercato e Modello di Business', 'required' => [['A1'], ['INT']], 'optional' => ['G1', 'G2'], 'kind' => 'qualitativa', 'attach' => ['A1', 'INT', 'G1', 'G2', 'F4'],
                'outline' => ['2.1 Settore di attività', '2.2 Modello di business', '2.3 Clienti e fornitori chiave e dipendenze', '2.4 Sintesi e implicazioni'], 'tables' => ['concentrazione']],
            'S3' => ['n' => 3, 'title' => 'Riclassificazione di Bilancio (SP Finanziario + Funzionale)', 'required' => [['B1']], 'optional' => ['B2', 'B3'], 'kind' => 'analitica',
                'outline' => ['3.1 Stato Patrimoniale riclassificato — Schema finanziario', '3.2 Stato Patrimoniale riclassificato — Schema funzionale', '3.3 Commento sulle variazioni significative', '3.4 Quadratura e note metodologiche'], 'tables' => ['sp_finanziario', 'sp_funzionale', 'controlli']],
            'S4' => ['n' => 4, 'title' => 'Riclassificazione del Conto Economico (Valore Aggiunto)', 'required' => [['B1']], 'optional' => ['B2'], 'kind' => 'analitica',
                'outline' => ['4.1 Conto Economico a Valore Aggiunto', '4.2 Marginalità', '4.3 Commento ai risultati', '4.4 Sintesi e implicazioni'], 'tables' => ['ce_va']],
            'S5' => ['n' => 5, 'title' => 'Analisi per Indici', 'required' => [['B1']], 'optional' => ['B2', 'B3'], 'kind' => 'analitica', 'min_years' => 2,
                'outline' => ['5.1 Indici reddituali', '5.2 Indici di liquidità e circolante', '5.3 Indici di solidità patrimoniale', '5.4 Indici della gestione finanziaria', '5.5 Confronto con i benchmark settoriali', '5.6 Sintesi e implicazioni'], 'tables' => ['indici_reddituali', 'indici_liquidita', 'indici_solidita', 'indici_finanziari', 'benchmark']],
            'S6' => ['n' => 6, 'title' => 'Rendiconto Finanziario OIC 10', 'required' => [['B1']], 'optional' => ['B6'], 'kind' => 'analitica', 'min_years' => 2,
                'outline' => ['6.1 Flussi finanziari per area', '6.2 Commento ai risultati', '6.3 Sintesi e implicazioni'], 'tables' => ['rendiconto']],
            'S7' => ['n' => 7, 'title' => 'Z-Score di Altman e Modelli Predittivi di Insolvenza', 'required' => [['B1']], 'optional' => [], 'kind' => 'analitica',
                'outline' => ['7.1 Modello applicato e motivazione', '7.2 Calcolo per esercizio', '7.3 Classificazione e trend', '7.4 Commento e avvertenze'], 'tables' => ['zscore']],
            'S8' => ['n' => 8, 'title' => 'KPI Infrannuali e Cruscotto Andamentale', 'required' => [['B3']], 'optional' => ['B1', 'A4'], 'kind' => 'analitica',
                'outline' => ['8.1 Dati infrannuali', '8.2 Confronto con l\'ultimo esercizio', '8.3 Sintesi e implicazioni'], 'tables' => ['infrannuale']],
            'S9' => ['n' => 9, 'title' => 'Posizione Finanziaria Netta e Sostenibilità del Debito', 'required' => [['B1']], 'optional' => ['B3', 'C3', 'C4'], 'kind' => 'analitica',
                'outline' => ['9.1 Calcolo della PFN', '9.2 Rapporti PFN/EBITDA, PFN/PN e leverage', '9.3 Composizione del debito finanziario', '9.4 Sintesi della sostenibilità'], 'tables' => ['pfn', 'pfn_rapporti']],
            'S10' => ['n' => 10, 'title' => 'Cash Conversion Cycle e Gestione del Circolante', 'required' => [['B1'], ['D4'], ['D5']], 'optional' => ['B3'], 'kind' => 'analitica',
                'outline' => ['10.1 Giorni di incasso, pagamento e giacenza', '10.2 Ciclo di conversione di cassa', '10.3 Analisi degli scadenziari', '10.4 Sintesi e implicazioni'], 'tables' => ['ccc', 'aging']],
            'S11' => ['n' => 11, 'title' => 'Analisi Andamentale Bancaria (Centrale Rischi)', 'required' => [['C1']], 'optional' => ['C2', 'C4'], 'kind' => 'analitica', 'attach' => ['C1'],
                'outline' => ['11.1 Accordato e utilizzato', '11.2 Sconfinamenti e segnali di tensione', '11.3 Sintesi e implicazioni'], 'tables' => ['centrale_rischi']],
            'S12' => ['n' => 12, 'title' => 'Indicatori della Crisi (art. 3 e art. 25-novies CCII)', 'required' => [['B1'], ['C1', 'D2', 'D3', 'D5', 'D8', 'B3', 'F1', 'F2']], 'optional' => ['D1', 'D4'], 'kind' => 'analitica',
                'outline' => ['12.1 Verifica delle soglie ex art. 3, comma 4, CCII', '12.2 Verifica delle soglie ex art. 25-novies CCII', '12.3 DSCR (se disponibili dati prospettici)', '12.4 Riepilogo dei segnali attivi', '12.5 Implicazioni operative'], 'tables' => ['ccii']],
            'S13' => ['n' => 13, 'title' => 'DSCR a 12 Mesi Prospettico', 'required' => [['B1'], ['F1', 'F2']], 'optional' => ['F3', 'C3'], 'kind' => 'analitica',
                'outline' => ['13.1 Ipotesi e dati utilizzati', '13.2 Calcolo del DSCR', '13.3 Commento e soglie', '13.4 Sintesi e implicazioni'], 'tables' => ['dscr']],
            'S14' => ['n' => 14, 'title' => 'Stress Test e Analisi di Scenario', 'required' => [['F1', 'F2']], 'optional' => ['F5', 'B1'], 'kind' => 'analitica',
                'outline' => ['14.1 Scenari considerati', '14.2 Impatti su EBITDA, PFN e DSCR', '14.3 Sintesi e implicazioni'], 'tables' => ['stress']],
            'S15' => ['n' => 15, 'title' => 'Sostenibilità del Debito e Piani di Ammortamento', 'required' => [['B1'], ['C3']], 'optional' => ['C4', 'F1', 'F2'], 'kind' => 'analitica', 'attach' => ['C3', 'C4'],
                'outline' => ['15.1 Profilo delle scadenze', '15.2 Copertura del servizio del debito', '15.3 Rischio di rifinanziamento', '15.4 Sintesi e implicazioni'], 'tables' => ['debito_scadenze']],
            'S16' => ['n' => 16, 'title' => 'Valutazione del Capitale Circolante Netto', 'required' => [['B1']], 'optional' => ['D4', 'D5'], 'kind' => 'analitica', 'min_years' => 2,
                'outline' => ['16.1 CCN finanziario e operativo', '16.2 Evoluzione e fabbisogno', '16.3 Sintesi e implicazioni'], 'tables' => ['ccn']],
            'S17' => ['n' => 17, 'title' => "Stima Credito d'Imposta ZES Unica (Sicilia)", 'required' => [['A1'], ['G3']], 'optional' => ['B1', 'F1'], 'kind' => 'analitica', 'sicilia' => true, 'attach' => ['G3'],
                'outline' => ['17.1 Requisiti soggettivi', '17.2 Requisiti oggettivi', '17.3 Calcolo del credito teorico', '17.4 Stima del credito atteso effettivo', '17.5 Beneficio netto post-tassazione', '17.6 Tempistiche e scadenze', '17.7 Avvertenze'], 'tables' => ['zes']],
            'S18' => ['n' => 18, 'title' => 'Adeguatezza degli Assetti (art. 2086 c.c. e art. 3 CCII)', 'required' => [['A4'], ['E1', 'E2']], 'optional' => ['A5', 'A6', 'E3', 'E4', 'E5'], 'kind' => 'qualitativa', 'attach' => ['A4', 'E1', 'E2', 'A5', 'A6', 'E3', 'E4', 'E5', 'INT'],
                'outline' => ['18.1 Assetto organizzativo', '18.2 Assetto amministrativo-contabile', '18.3 Sistema di rilevazione tempestiva della crisi', '18.4 Gap analysis', '18.5 Raccomandazioni'], 'tables' => []],
            'S19' => ['n' => 19, 'title' => 'Profilo Fiscale e Carico Tributario (IRES + IRAP)', 'required' => [['B1'], ['B5']], 'optional' => [], 'kind' => 'analitica',
                'outline' => ['19.1 Imposte di esercizio', '19.2 Aliquota fiscale effettiva', '19.3 Sintesi e implicazioni'], 'tables' => ['fiscale']],
            'S20' => ['n' => 20, 'title' => 'Opportunità di Finanza Agevolata Regionale (Sicilia)', 'required' => [['A1']], 'optional' => ['G3', 'F2', 'B1'], 'kind' => 'qualitativa', 'sicilia' => true, 'attach' => ['A1', 'G3', 'INT'],
                'outline' => ['20.1 Profilo di ammissibilità', '20.2 Strumenti potenzialmente applicabili', '20.3 Requisiti e precondizioni da verificare', '20.4 Avvertenze'], 'tables' => []],
            'S21' => ['n' => 21, 'title' => 'Valutazione Sintetica e Rating Interno', 'required' => [['B1']], 'optional' => [], 'kind' => 'analitica',
                'outline' => ['21.1 Cruscotto dei principali KPI', '21.2 Assegnazione del rating interno', '21.3 Punti di forza', '21.4 Aree di miglioramento e criticità', '21.5 Avvertenze sul rating'], 'tables' => ['rating']],
            'S22' => ['n' => 22, 'title' => 'Raccomandazioni Operative e Roadmap', 'required' => [['B1']], 'optional' => [], 'kind' => 'analitica', 'attach' => ['INT'],
                'outline' => ['22.1 Azioni a breve termine (0-6 mesi)', '22.2 Azioni a medio termine (6-18 mesi)', '22.3 Azioni strategiche (oltre 18 mesi)', '22.4 KPI di monitoraggio consigliati'], 'tables' => []],
        ];
    }

    public static function finalita(): array
    {
        return [
            'A' => ['label' => 'Autovalutazione interna', 'mandatory' => ['S1', 'S3', 'S4', 'S5', 'S9', 'S21', 'S22'], 'recommended' => ['S6', 'S7', 'S10', 'S12', 'S16']],
            'B' => ['label' => 'Presentazione bancaria per affidamento', 'mandatory' => ['S1', 'S2', 'S3', 'S4', 'S5', 'S9', 'S11', 'S13', 'S15', 'S21'], 'recommended' => ['S6', 'S7', 'S12', 'S14']],
            'C' => ['label' => 'Adeguati Assetti ex art. 2086 c.c.', 'mandatory' => ['S1', 'S3', 'S4', 'S5', 'S9', 'S12', 'S13', 'S18', 'S22'], 'recommended' => ['S7', 'S10', 'S11', 'S14']],
            'D' => ['label' => 'Due diligence per investitore', 'mandatory' => ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S9', 'S10', 'S11', 'S16', 'S19', 'S21'], 'recommended' => ['S13', 'S14', 'S15', 'S22']],
            'E' => ['label' => 'Allerta crisi ex art. 3 CCII', 'mandatory' => ['S1', 'S3', 'S5', 'S9', 'S11', 'S12', 'S13', 'S15', 'S22'], 'recommended' => ['S7', 'S14', 'S16', 'S18']],
            'F' => ['label' => 'Altro / personalizzato', 'mandatory' => [], 'recommended' => ['S1', 'S3', 'S4', 'S5', 'S21']],
        ];
    }

    public const FORME = ['S.r.l.', 'S.r.l.s.', 'S.p.A.', 'S.a.p.a.', 'S.n.c.', 'S.a.s.', 'Ditta individuale', 'Società cooperativa', 'Consorzio', 'Altro'];
    public const DIMENSIONI = ['micro' => 'Micro impresa', 'piccola' => 'Piccola impresa', 'media' => 'Media impresa', 'grande' => 'Grande impresa'];
    public const PROVINCE = ['Agrigento', 'Caltanissetta', 'Catania', 'Enna', 'Messina', 'Palermo', 'Ragusa', 'Siracusa', 'Trapani', 'Fuori Sicilia'];

    /**
     * Dizionario dei dati estraibili.
     * unit: eur | num | pct | bool ; slots: documenti in cui il dato viene cercato.
     */
    public static function data_dictionary(): array
    {
        $bil = ['B1', 'B2', 'B3'];
        $d = [];
        $add = static function (string $code, string $label, string $group, array $slots, string $unit = 'eur', string $hint = '') use (&$d) {
            $d[$code] = ['label' => $label, 'group' => $group, 'slots' => $slots, 'unit' => $unit, 'hint' => $hint];
        };

        // --- Stato Patrimoniale Attivo
        $g = 'Stato Patrimoniale — Attivo';
        $add('SP_A', 'A) Crediti verso soci per versamenti ancora dovuti', $g, $bil);
        $add('SP_BI', 'B.I) Immobilizzazioni immateriali (nette)', $g, $bil);
        $add('SP_BII', 'B.II) Immobilizzazioni materiali (nette)', $g, $bil);
        $add('SP_BIII', 'B.III) Immobilizzazioni finanziarie (totale)', $g, $bil);
        $add('SP_CI', 'C.I) Rimanenze (totale)', $g, $bil);
        $add('SP_CII_CLIENTI', 'C.II.1) Crediti verso clienti esigibili entro l\'esercizio successivo', $g, $bil, 'eur', 'Spesso solo in Nota Integrativa nei bilanci abbreviati.');
        $add('SP_CII_TOT', 'C.II) Crediti — totale', $g, $bil);
        $add('SP_CII_ENTRO', 'C.II) Crediti — di cui esigibili entro l\'esercizio successivo', $g, $bil);
        $add('SP_CII_OLTRE', 'C.II) Crediti — di cui esigibili oltre l\'esercizio successivo', $g, $bil);
        $add('SP_CIII', 'C.III) Attività finanziarie che non costituiscono immobilizzazioni', $g, $bil);
        $add('SP_CIV', 'C.IV) Disponibilità liquide (totale)', $g, $bil);
        $add('SP_D_ATT', 'D) Ratei e risconti attivi', $g, $bil);
        $add('SP_TOT_ATT', 'Totale attivo', $g, $bil);

        // --- Stato Patrimoniale Passivo
        $g = 'Stato Patrimoniale — Passivo';
        $add('SP_PN', 'A) Patrimonio netto (totale)', $g, $bil);
        $add('SP_PN_I', 'A.I) Capitale', $g, $bil);
        $add('SP_PN_IV', 'A.IV) Riserva legale', $g, $bil);
        $add('SP_PN_V', 'A.V) Riserve statutarie', $g, $bil);
        $add('SP_PN_VI', 'A.VI) Altre riserve (totale)', $g, $bil);
        $add('SP_PN_VIII', 'A.VIII) Utili (perdite) portati a nuovo', $g, $bil);
        $add('SP_PN_IX', 'A.IX) Utile (perdita) dell\'esercizio', $g, $bil);
        $add('SP_B_FONDI', 'B) Fondi per rischi e oneri (totale)', $g, $bil);
        $add('SP_C_TFR', 'C) Trattamento di fine rapporto di lavoro subordinato', $g, $bil);
        $add('SP_D_TOT', 'D) Debiti — totale', $g, $bil);
        $add('SP_D_ENTRO', 'D) Debiti — di cui esigibili entro l\'esercizio successivo', $g, $bil);
        $add('SP_D_OLTRE', 'D) Debiti — di cui esigibili oltre l\'esercizio successivo', $g, $bil);
        $add('SP_D1', 'D.1) Obbligazioni (totale)', $g, $bil);
        $add('SP_D2', 'D.2) Obbligazioni convertibili (totale)', $g, $bil);
        $add('SP_D3', 'D.3) Debiti verso soci per finanziamenti (totale)', $g, $bil);
        $add('SP_D4', 'D.4) Debiti verso banche (totale)', $g, $bil);
        $add('SP_D4_ENTRO', 'D.4) Debiti verso banche esigibili entro l\'esercizio successivo', $g, $bil);
        $add('SP_D4_OLTRE', 'D.4) Debiti verso banche esigibili oltre l\'esercizio successivo', $g, $bil);
        $add('SP_D5', 'D.5) Debiti verso altri finanziatori (totale)', $g, $bil);
        $add('SP_D5_ENTRO', 'D.5) Debiti verso altri finanziatori esigibili entro l\'esercizio successivo', $g, $bil);
        $add('SP_D5_OLTRE', 'D.5) Debiti verso altri finanziatori esigibili oltre l\'esercizio successivo', $g, $bil);
        $add('SP_D7', 'D.7) Debiti verso fornitori (totale)', $g, $bil);
        $add('SP_D12', 'D.12) Debiti tributari (totale)', $g, $bil);
        $add('SP_D13', 'D.13) Debiti verso istituti di previdenza e sicurezza sociale (totale)', $g, $bil);
        $add('SP_E_PASS', 'E) Ratei e risconti passivi', $g, $bil);
        $add('SP_TOT_PASS', 'Totale passivo', $g, $bil);

        // --- Conto Economico
        $g = 'Conto Economico';
        $add('CE_A1', 'A.1) Ricavi delle vendite e delle prestazioni', $g, $bil);
        $add('CE_A2', 'A.2) Variazioni delle rimanenze di prodotti in corso di lavorazione, semilavorati e finiti', $g, $bil);
        $add('CE_A3', 'A.3) Variazioni dei lavori in corso su ordinazione', $g, $bil);
        $add('CE_A4', 'A.4) Incrementi di immobilizzazioni per lavori interni', $g, $bil);
        $add('CE_A5', 'A.5) Altri ricavi e proventi (totale)', $g, $bil);
        $add('CE_A_TOT', 'A) Totale valore della produzione', $g, $bil);
        $add('CE_B6', 'B.6) Costi per materie prime, sussidiarie, di consumo e di merci', $g, $bil);
        $add('CE_B7', 'B.7) Costi per servizi', $g, $bil);
        $add('CE_B8', 'B.8) Costi per godimento di beni di terzi', $g, $bil);
        $add('CE_B9', 'B.9) Costi per il personale (totale)', $g, $bil);
        $add('CE_B10', 'B.10) Ammortamenti e svalutazioni (totale)', $g, $bil);
        $add('CE_B11', 'B.11) Variazioni delle rimanenze di materie prime, sussidiarie, di consumo e merci', $g, $bil);
        $add('CE_B12', 'B.12) Accantonamenti per rischi', $g, $bil);
        $add('CE_B13', 'B.13) Altri accantonamenti', $g, $bil);
        $add('CE_B14', 'B.14) Oneri diversi di gestione', $g, $bil);
        $add('CE_B_TOT', 'B) Totale costi della produzione', $g, $bil);
        $add('CE_C15', 'C.15) Proventi da partecipazioni (totale)', $g, $bil);
        $add('CE_C16', 'C.16) Altri proventi finanziari (totale)', $g, $bil);
        $add('CE_C17', 'C.17) Interessi e altri oneri finanziari (totale)', $g, $bil);
        $add('CE_C17BIS', 'C.17-bis) Utili e perdite su cambi', $g, $bil);
        $add('CE_C_TOT', 'C) Totale proventi e oneri finanziari', $g, $bil);
        $add('CE_D_TOT', 'D) Totale rettifiche di valore di attività e passività finanziarie', $g, $bil);
        $add('CE_EBT', 'Risultato prima delle imposte', $g, $bil);
        $add('CE_20', '20) Imposte sul reddito dell\'esercizio, correnti, differite e anticipate', $g, $bil);
        $add('CE_21', '21) Utile (perdita) dell\'esercizio', $g, $bil);

        // --- Rendiconto finanziario
        $g = 'Rendiconto finanziario (OIC 10)';
        $rf = ['B1', 'B6'];
        $add('RF_A', 'A) Flusso finanziario dell\'attività operativa', $g, $rf);
        $add('RF_B', 'B) Flusso finanziario dell\'attività di investimento', $g, $rf);
        $add('RF_C', 'C) Flusso finanziario dell\'attività di finanziamento', $g, $rf);
        $add('RF_DELTA', 'Incremento (decremento) delle disponibilità liquide (A ± B ± C)', $g, $rf);
        $add('RF_LIQ_INI', 'Disponibilità liquide a inizio esercizio', $g, $rf);
        $add('RF_LIQ_FIN', 'Disponibilità liquide a fine esercizio', $g, $rf);

        // --- Dati generali
        $g = 'Dati generali';
        $add('ADDETTI', 'Numero medio dipendenti / addetti', $g, ['A1', 'B1', 'A4'], 'num');

        // --- Fiscale
        $g = 'Dichiarazioni fiscali';
        $add('FISC_IRES', 'IRES dovuta (Modello Redditi SC)', $g, ['B5']);
        $add('FISC_IRAP', 'IRAP dovuta (Dichiarazione IRAP)', $g, ['B5']);
        $add('FISC_VOLUME_AFFARI', 'Volume d\'affari IVA dell\'anno', $g, ['B5', 'D2']);

        // --- Centrale Rischi (periodo = mese AAAA-MM)
        $g = "Centrale Rischi Banca d'Italia";
        $add('CR_ACC_TOT', 'Accordato operativo totale (cassa)', $g, ['C1']);
        $add('CR_UTI_TOT', 'Utilizzato totale (cassa)', $g, ['C1']);
        $add('CR_ACC_AUTOLIQ', 'Rischi autoliquidanti — accordato operativo', $g, ['C1']);
        $add('CR_UTI_AUTOLIQ', 'Rischi autoliquidanti — utilizzato', $g, ['C1']);
        $add('CR_ACC_SCADENZA', 'Rischi a scadenza — accordato operativo', $g, ['C1']);
        $add('CR_UTI_SCADENZA', 'Rischi a scadenza — utilizzato', $g, ['C1']);
        $add('CR_ACC_REVOCA', 'Rischi a revoca — accordato operativo', $g, ['C1']);
        $add('CR_UTI_REVOCA', 'Rischi a revoca — utilizzato', $g, ['C1']);
        $add('CR_SCONFINO', 'Sconfinamento totale', $g, ['C1']);
        $add('CR_SOFFERENZE', 'Sofferenze', $g, ['C1']);
        $add('CR_GARANZIE_RICEVUTE', 'Garanzie ricevute', $g, ['C1']);

        // --- Affidamenti e piani di ammortamento
        $g = 'Finanziamenti e affidamenti';
        $add('FIN_RESIDUO_TOT', 'Debito residuo complessivo dei finanziamenti in essere', $g, ['C3']);
        $add('FIN_QUOTA_12M', 'Quote capitale in scadenza nei prossimi 12 mesi', $g, ['C3', 'F1', 'F3']);
        $add('FIN_QUOTA_1_5', 'Quote capitale in scadenza tra 1 e 5 anni', $g, ['C3']);
        $add('FIN_QUOTA_OLTRE5', 'Quote capitale in scadenza oltre 5 anni', $g, ['C3']);
        $add('FIN_INTERESSI_12M', 'Quote interessi in scadenza nei prossimi 12 mesi', $g, ['C3', 'F1', 'F3']);
        $add('FIDI_ACCORDATI', 'Totale linee di fido accordate', $g, ['C4']);

        // --- Scadenziari (periodo = data AAAA-MM-GG)
        $g = 'Scadenziari clienti e fornitori';
        $add('AGING_CLI_TOT', 'Crediti verso clienti aperti — totale', $g, ['D4']);
        $add('AGING_CLI_SCADUTO', 'Crediti verso clienti — scaduto totale', $g, ['D4']);
        $add('AGING_CLI_SCADUTO_90', 'Crediti verso clienti — scaduto da oltre 90 giorni', $g, ['D4']);
        $add('AGING_FOR_TOT', 'Debiti verso fornitori aperti — totale', $g, ['D5']);
        $add('AGING_FOR_NON_SCADUTO', 'Debiti verso fornitori — non scaduti', $g, ['D5']);
        $add('AGING_FOR_SCADUTO', 'Debiti verso fornitori — scaduto totale', $g, ['D5']);
        $add('AGING_FOR_SCADUTO_90', 'Debiti verso fornitori — scaduti da almeno 90 giorni', $g, ['D5']);

        // --- Indicatori crisi
        $g = 'Debiti scaduti e indicatori della crisi';
        $add('CCII_RETRIB_SCADUTE_30', 'Debiti per retribuzioni scaduti da almeno 30 giorni', $g, ['D8', 'B3', 'D3']);
        $add('CCII_MONTE_SALARI_MENSILE', 'Ammontare complessivo mensile delle retribuzioni', $g, ['D3', 'B3', 'D8']);
        $add('CCII_BANCHE_SCADUTE_60', 'Esposizioni verso banche/intermediari scadute o sconfinanti da oltre 60 giorni', $g, ['C1', 'C2']);
        $add('CCII_BANCHE_TOT', 'Totale esposizioni verso banche e intermediari', $g, ['C1']);
        $add('CCII_INPS_SCADUTO_90', 'Contributi INPS non versati da oltre 90 giorni', $g, ['D3', 'D8']);
        $add('CCII_INPS_DOVUTI_ANNO_PREC', 'Contributi INPS dovuti nell\'anno precedente', $g, ['D3']);
        $add('CCII_INAIL_SCADUTO_90', 'Premi INAIL scaduti da oltre 90 giorni e non versati', $g, ['D3', 'D8']);
        $add('CCII_IVA_SCADUTA', 'Debito IVA scaduto e non versato (da LIPE)', $g, ['D2', 'D8']);
        $add('CCII_ADER_SCADUTO_90', 'Crediti affidati ad AdER scaduti da oltre 90 giorni', $g, ['D2', 'D8']);
        $add('CCII_RATEAZIONI_RESIDUO', 'Debito residuo complessivo delle rateazioni in essere', $g, ['D8']);

        // --- Dati prospettici
        $g = 'Dati prospettici (budget / piano)';
        $fp = ['F1', 'F2', 'F3', 'F5'];
        $add('PROSP_RICAVI', 'Ricavi previsti (12 mesi o anno di budget)', $g, $fp);
        $add('PROSP_EBITDA', 'EBITDA previsto (12 mesi o anno di budget)', $g, $fp);
        $add('PROSP_IMPOSTE', 'Imposte previste nei 12 mesi', $g, $fp);
        $add('PROSP_DELTA_CCN', 'Variazione prevista del CCN nei 12 mesi (aumento = valore positivo)', $g, $fp, 'eur', 'Un aumento del circolante assorbe cassa: riportalo positivo; una riduzione negativa. Solo se il documento lo dichiara esplicitamente.');
        $add('PROSP_CASSA_INIZIALE', 'Disponibilità liquide iniziali del piano di tesoreria', $g, ['F3']);
        $add('PROSP_FLUSSI_OPERATIVI_12M', 'Flussi di cassa operativi netti previsti nei 12 mesi', $g, ['F3', 'F1']);

        // --- Concentrazione
        $g = 'Clienti e fornitori';
        $add('CLI_TOP1_PCT', 'Quota di fatturato del primo cliente', $g, ['G1'], 'pct');
        $add('CLI_TOP5_PCT', 'Quota di fatturato dei primi 5 clienti', $g, ['G1'], 'pct');
        $add('CLI_TOP10_PCT', 'Quota di fatturato dei primi 10 clienti', $g, ['G1'], 'pct');
        $add('FOR_TOP1_PCT', 'Quota di acquisti dal primo fornitore', $g, ['G2'], 'pct');
        $add('FOR_TOP5_PCT', 'Quota di acquisti dai primi 5 fornitori', $g, ['G2'], 'pct');
        $add('FOR_TOP10_PCT', 'Quota di acquisti dai primi 10 fornitori', $g, ['G2'], 'pct');

        // --- Investimenti
        $g = 'Piano investimenti';
        $add('INV_TOTALE', 'Investimento complessivo previsto in beni strumentali', $g, ['G3']);
        $add('INV_IMMOBILI', 'di cui: acquisto/costruzione/ampliamento di immobili strumentali', $g, ['G3']);

        return $d;
    }

    /** Informazioni qualitative estraibili (campi testuali con fonte). */
    public static function info_fields(): array
    {
        return [
            'ragione_sociale' => 'Ragione sociale / denominazione',
            'forma_giuridica' => 'Forma giuridica',
            'codice_fiscale' => 'Codice fiscale',
            'partita_iva' => 'Partita IVA',
            'rea' => 'Numero REA e CCIAA',
            'pec' => 'Indirizzo PEC',
            'sede_legale' => 'Sede legale',
            'sedi_operative' => 'Sedi operative / unità locali',
            'data_costituzione' => 'Data di costituzione',
            'stato_attivita' => "Stato dell'attività",
            'ateco' => 'Codice ATECO e descrizione',
            'oggetto_sociale' => 'Oggetto sociale (sintesi testuale)',
            'capitale_sociale' => 'Capitale sociale (deliberato/sottoscritto/versato)',
            'soci' => 'Soci e quote',
            'amministratori' => 'Organo amministrativo e componenti',
            'organo_controllo' => 'Collegio sindacale / revisore',
            'procure' => 'Procuratori e deleghe',
            'addetti' => 'Addetti (con data di riferimento)',
            'durc' => 'Esito DURC e scadenza di validità',
            'protesti' => 'Protesti / procedure / pregiudizievoli',
            'tipo_bilancio' => 'Tipologia di bilancio (ordinario / abbreviato / micro)',
            'principi_contabili' => 'Cambiamenti di principi contabili o operazioni straordinarie',
            'altro' => 'Altra informazione rilevante',
        ];
    }

    /** Documenti da cui estrarre: tutti quelli reali (non virtuali). */
    public static function extraction_slot_codes(string $slot): array
    {
        $out = [];
        foreach (self::data_dictionary() as $code => $def) {
            if (in_array($slot, $def['slots'], true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /** Calcola le sezioni abilitate dai documenti disponibili. */
    public static function enabled_sections(array $available): array
    {
        $out = [];
        foreach (self::sections() as $id => $s) {
            $missing = [];
            foreach ($s['required'] as $group) {
                if (!array_intersect($group, $available)) {
                    $missing[] = $group;
                }
            }
            $out[$id] = ['enabled' => empty($missing), 'missing' => $missing];
        }
        return $out;
    }

    /** Dati esportati al client JS. */
    public static function for_js(): array
    {
        return [
            'areas' => self::AREAS,
            'documents' => self::documents(),
            'sections' => self::sections(),
            'finalita' => self::finalita(),
            'forme' => self::FORME,
            'dimensioni' => self::DIMENSIONI,
            'province' => self::PROVINCE,
            'settori' => array_map(static fn($s) => $s['label'], IR_Params::get()['benchmark']),
            'dictionary' => self::data_dictionary(),
            'info_fields' => self::info_fields(),
        ];
    }
}
