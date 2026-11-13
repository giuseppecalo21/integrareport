# Integra Report — plugin WordPress

Interfaccia web guidata per produrre i **Report di Consulenza per PMI siciliane** (metodologia della skill `report-consulenza-pmi-sicilia`) a partire dai documenti aziendali, usando le API di Anthropic per leggere i documenti e scrivere i commenti.

L'obiettivo principale è l'**affidabilità**: ogni numero del report deve essere riconducibile alla sua fonte.

## Come garantisce l'affidabilità

1. **L'AI non esegue calcoli.** Claude legge ogni documento ed estrae i singoli valori (es. "C.II.1 Crediti verso clienti 2024") indicando **pagina del PDF e citazione testuale**. Se un valore si otterrebbe solo con un calcolo, non lo riporta.
2. **Controllo automatico delle citazioni.** Se la citazione non contiene il numero estratto, il dato viene marcato "affidabilità bassa" e va verificato.
3. **Fonti incrociate.** Lo stesso dato letto da due documenti (es. il 2023 nel bilancio 2023 e come comparativo nel 2024) viene confrontato: se coincide risulta "confermato da 2 fonti", se differisce è un **conflitto** che blocca il calcolo finché il consulente non sceglie.
4. **Calcoli deterministici in PHP** con le formule di `formule_kpi.md`. Ogni indicatore registra formula, valori in ingresso e fonte di ciascuno. Se manca anche un solo dato, l'indicatore è "non valutabile" ed elenca cosa manca: nessuna stima.
5. **Controlli di quadratura** su ogni esercizio (attivo = passivo, riclassificato = totale di bilancio, A − B = EBIT, risultato ante imposte, utile CE = utile SP, rendiconto...). Un valore letto male fa fallire il controllo.
6. **Nel testo l'AI non scrive numeri**: usa segnaposto che il plugin sostituisce con i valori calcolati. Qualunque numero scritto "a mano" viene **evidenziato in giallo** come non tracciato. Le affermazioni qualitative tratte dai documenti sono collegate al passaggio citato (citazioni dell'API).
7. **Correzioni manuali solo con nota obbligatoria**: il valore originale e l'autore restano nella traccia.
8. **Tracciabilità cliccabile**: nell'interfaccia ogni numero apre un pannello con formula → valori → dato → documento e pagina (con pulsante "Apri il documento a pagina N"). Nel file Word ogni numero è un collegamento interno alle appendici: *A — Tracciabilità dei calcoli*, *B — Registro dei dati estratti*, *C — Citazioni documentali*. Le stesse appendici compaiono in coda al visualizzatore e nella stampa/PDF del browser, con la medesima numerazione delle citazioni.

## Il percorso (sempre uguale)

1. **Azienda** — ragione sociale, settore (per benchmark e scelta Z'/Z''), dimensione, finalità A-F.
2. **Documenti** — matrice dei documenti A1…G5 per area, con caricamento per riga (anche trascinando i file) e note dell'intervista al management. A destra, in tempo reale, i capitoli abilitati e quelli obbligatori per la finalità ancora mancanti.
3. **Capitoli** — preselezione automatica di obbligatori, consigliati e specifici per la Sicilia (§17, §20).
4. **Dati e KPI** — estrazione, verifica dei dati (conflitti, dati da verificare, correzioni), parametri, calcolo, controlli di quadratura e cruscotto KPI da approvare.
5. **Report** — stesura, lettura con tracciabilità, rigenerazione del singolo capitolo, download del **.docx** (design system Integra, apribile in Google Docs) e stampa/PDF.

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

### Ripresa dei lavori interrotti

Durante le chiamate lunghe il lavoro aggiorna un segnale di vita. Se il processo viene interrotto dal server (timeout dell'hosting), dopo cinque minuti senza segnale il browser lo fa ripartire dal punto di arresto: documenti e capitoli già completati non vengono rielaborati. Per la stesura sono disponibili anche *Riprendi la stesura* e *Completa i capitoli mancanti*, che riscrivono solo i capitoli in errore o mai generati. Un capitolo previsto ma non generato viene sempre dichiarato: avviso nel visualizzatore e voce *Capitoli non generati* nella Checklist del documento.

### Modelli e costi

In *Impostazioni* si sceglie un modello generale e, facoltativamente, un modello distinto per l'estrazione dei dati e per la stesura. Senza scelta esplicita le due fasi usano il modello generale.

- **Documenti semplici.** Le categorie da cui si ricavano quasi solo dati anagrafici e descrittivi (visura, statuto, organigramma, verbali, polizze…) vengono lette con un modello più economico, per impostazione predefinita Claude Sonnet 5.5. Bilanci, Centrale Rischi, scadenziari e gli altri documenti numerici usano sempre il modello di estrazione.
- **Capitoli analitici.** I capitoli che commentano tabelle già calcolate vengono scritti con un modello più economico, per impostazione predefinita Claude Sonnet 5.5: lì il testo non può contenere numeri propri, solo riferimenti verificati dal plugin. Sommario esecutivo, capitoli qualitativi, rating (§21) e raccomandazioni (§22) usano il modello di stesura.
- **Allegati in cache.** I capitoli che allegano la visura vengono scritti di seguito e con gli allegati nello stesso ordine, così la seconda lettura dello stesso documento è servita dalla cache dell'API a prezzo ridotto.
- **Memoria delle estrazioni.** Se lo stesso file viene elaborato di nuovo con le stesse istruzioni e lo stesso modello, anche in un altro report, la risposta già ottenuta viene riusata senza costo. *Rielabora* su un singolo file forza una nuova lettura. Le risposte restano nella cartella protetta dei documenti per 30 giorni (valore modificabile; 0 disattiva la memoria).
- **Spesa stimata.** L'intestazione di ogni report mostra la spesa API stimata: token registrati per prezzi di listino, comprese le rielaborazioni, con il dettaglio per fase e il risparmio dovuto alla memoria. È una stima: fa fede la console Anthropic.

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
