# Integra Report — plugin WordPress

Interfaccia web guidata per produrre i **Report di Consulenza per PMI siciliane** (metodologia della skill `report-consulenza-pmi-sicilia`) a partire dai documenti aziendali, usando le API di Anthropic per leggere i documenti e scrivere i commenti.

L'obiettivo principale è l'**affidabilità**: ogni numero del report deve essere riconducibile alla sua fonte.

## Come garantisce l'affidabilità

1. **L'AI non esegue calcoli.** Claude legge ogni documento ed estrae i singoli valori (es. "C.II.1 Crediti verso clienti 2024") indicando **pagina del PDF e citazione testuale**. Se un valore si otterrebbe solo con un calcolo, non lo riporta.
2. **Controllo automatico delle citazioni.** Se la citazione non contiene il numero estratto, il dato viene marcato "affidabilità bassa" e va verificato.
3. **Fonti incrociate.** Lo stesso dato letto da due documenti (es. il 2023 nel bilancio 2023 e come comparativo nel 2024) viene confrontato: se coincide risulta "confermato da 2 fonti". Se differisce, per le voci di bilancio valgono due regole contabili fisse, sempre tracciate e modificabili dal consulente: prevale il **bilancio più recente che riespone l'esercizio** (saldi comparativi riclassificati) e, a parità di bilancio, il **prospetto ufficiale** sulla Nota Integrativa. Negli altri casi è un **conflitto** che blocca il calcolo finché il consulente non sceglie.
   I numeri di Stato Patrimoniale, Conto Economico e Rendiconto si leggono solo dai prospetti ufficiali: dalla Relazione sulla gestione (documento **B7**) si traggono esclusivamente informazioni qualitative.
4. **Calcoli deterministici in PHP** con le formule di `formule_kpi.md`. Ogni indicatore registra formula, valori in ingresso e fonte di ciascuno. Se manca anche un solo dato, l'indicatore è "non valutabile" ed elenca cosa manca: nessuna stima.
   Le quote di crediti e debiti **entro/oltre l'esercizio** partono sempre dal totale di Stato Patrimoniale: la quota oltre è letta dal prospetto (righe delle singole voci negli schemi ordinari), la quota entro è la differenza. Così il riclassificato quadra con il totale di bilancio anche per le voci non ripartite (es. imposte anticipate) e per gli esercizi presenti solo come colonna comparativa.
5. **Controlli di quadratura** su ogni esercizio (attivo = passivo, riclassificato = totale di bilancio, A − B = EBIT, risultato ante imposte, utile CE = utile SP, rendiconto...). Un valore letto male fa fallire il controllo.
6. **Nel testo l'AI non scrive numeri**: usa segnaposto che il plugin sostituisce con i valori calcolati. Qualunque numero scritto "a mano" viene **evidenziato in giallo** come non tracciato. Le affermazioni qualitative tratte dai documenti sono collegate al passaggio citato (citazioni dell'API).
7. **Correzioni manuali solo con nota obbligatoria**: il valore originale e l'autore restano nella traccia.
8. **Tracciabilità cliccabile**: nell'interfaccia ogni numero apre un pannello con formula → valori → dato → documento e pagina (con pulsante "Apri il documento a pagina N").
9. **Due file distinti all'esportazione.** Il **Report per il cliente** contiene copertina, premessa, capitoli numerati in sequenza, documentazione esaminata (descritta in modo formale), appendice contabile, fonti e disclaimer: nessun nome di file, nome utente, avviso di lavorazione o materiale interno. Il **Fascicolo di audit interno**, a uso esclusivo dello studio, contiene checklist, controlli di quadratura con la possibile causa degli scostamenti, avvisi sul testo e le appendici *A — Tracciabilità dei calcoli*, *B — Registro dei dati estratti*, *C — Citazioni documentali*.
10. **Contesto globale condiviso.** Prima della stesura una scheda di inquadramento (qualifica dimensionale ex Racc. 2003/361/CE compresa la regola sul controllo pubblico, rapporti di gruppo, specificità del settore) viene generata una volta e passata a tutti i capitoli insieme ai dati dimensionali certi (ricavi, totale attivo, patrimonio netto, addetti). La scheda orienta il testo e non modifica alcun numero; ciò che non è documentato resta "da verificare".

## Il percorso (sempre uguale)

1. **Azienda** — ragione sociale, settore (per benchmark e scelta Z'/Z''), dimensione, finalità A-F.
2. **Documenti** — matrice dei documenti A1…G5 per area, con caricamento per riga (anche trascinando i file) e note dell'intervista al management. A destra, in tempo reale, i capitoli abilitati e quelli obbligatori per la finalità ancora mancanti.
3. **Capitoli** — preselezione automatica di obbligatori, consigliati e specifici per la Sicilia (§17, §20).
4. **Dati e KPI** — estrazione, verifica dei dati (conflitti, dati da verificare, correzioni), parametri, calcolo, controlli di quadratura e cruscotto KPI da approvare.
5. **Report** — stesura, lettura con tracciabilità, rigenerazione del singolo capitolo, download del **report per il cliente** e del **fascicolo di audit interno** in .docx (design system Integra, apribili in Google Docs) e stampa/PDF della versione per il cliente.

L'estrazione e la stesura girano in background sul server: si può chiudere la pagina e tornare più tardi.

## Installazione

Requisiti: WordPress 6.2+, **PHP 8.1+** con estensioni `zip`, `curl`, `mbstring`, `simplexml`; `upload_max_filesize` e `post_max_size` di almeno 20 MB.

1. Scarica lo zip del plugin (vedi *Creare lo zip* sotto) e caricalo da **Plugin › Aggiungi nuovo › Carica plugin**, poi attivalo.
2. Inserisci la chiave API in `wp-config.php` (consigliato rispetto al salvataggio nel database):
   ```php
   define('INTEGRA_REPORT_ANTHROPIC_KEY', 'sk-ant-...');
   ```
   In alternativa: **Integra Report › Impostazioni**.
3. In **Impostazioni** imposta nome dello studio, sito e firma del consulente (copertina, piè di pagina, disclaimer).
4. All'attivazione la capability `use_integra_report` viene assegnata ad **Amministratori** ed **Editori**. Per altri ruoli usa un plugin di gestione ruoli.

### Sicurezza dei documenti

I file sono salvati in `wp-content/uploads/integra-report/{id}/` con nomi casuali e sono serviti solo agli utenti autorizzati. Su Apache l'accesso diretto è bloccato da `.htaccess`; **su Nginx aggiungi**:

```nginx
location ^~ /wp-content/uploads/integra-report/ { deny all; }
```

I documenti vengono inviati alle API di Anthropic per l'elaborazione: verifica che ciò sia coerente con gli accordi con i clienti e con la vostra informativa privacy.

### Elaborazione in background

Il plugin avvia i lavori lunghi con una richiesta "loopback" al proprio sito. Se l'hosting la blocca, dopo 15 secondi il browser esegue il lavoro direttamente (in questo caso è meglio tenere aperta la pagina fino al termine).

## Parametri normativi

Soglie, benchmark di settore, aliquote ZES, soglie CCII e tabella del rating sono raccolti in `includes/class-ir-params.php` (valori della skill, normativa aggiornata a **maggio 2026**). Si aggiornano senza toccare il codice da **Impostazioni › Parametri normativi** inserendo solo le chiavi da modificare in JSON. Ogni report riporta la versione dei parametri usata.

Prima di un report da consegnare, verificare in particolare: aliquote e coefficiente di riparto ZES Unica, soglie dell'art. 25-novies CCII, aliquota IRAP regionale.

## Sviluppo

- Codice PHP in `integra-report/includes/`, interfaccia in `integra-report/assets/` (JavaScript puro, nessun passaggio di build).
- Test del motore di calcolo (senza WordPress né API): `php integra-report/tests/run-tests.php`
- Chiamate API tramite SDK ufficiale `anthropic-ai/sdk` (streaming, structured output per l'estrazione, citazioni per i capitoli qualitativi, prompt caching, fallback lato server in caso di rifiuto). Modello predefinito: Claude Opus 5.5.

### Creare lo zip

```bash
cd integra-report && composer install --no-dev --optimize-autoloader
```

```bash
./bin/build-zip.sh
```

Lo zip (con la cartella `vendor/` inclusa e senza i test) viene creato in `dist/`.
