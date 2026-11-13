<?php
/**
 * Wrapper sull'SDK ufficiale Anthropic per PHP.
 * - streaming (evita i timeout HTTP sulle risposte lunghe) con accumulo del messaggio finale;
 * - fallback lato server in caso di rifiuto (beta server-side-fallback-2026-07-01, modalità "default");
 * - prompt caching sul system prompt e, se richiesto, sull'ultimo documento allegato;
 * - modello distinto per estrazione e stesura, e per documenti semplici, per contenere i costi;
 * - memoria delle risposte di estrazione: lo stesso documento non viene mai pagato due volte;
 * - stima della spesa per report a partire dai token consumati.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;

final class IR_Claude
{
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (consigliato)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (più rapido ed economico)',
        'claude-fable-5-2' => 'Claude Fable 5.2 (massima capacità, costo più alto)',
    ];
    /**
     * Prezzi di listino in dollari per milione di token: [ingresso, uscita, lettura da cache].
     * La scrittura in cache costa 1,25 volte l'ingresso. Servono solo alla stima mostrata
     * nell'interfaccia: la spesa effettiva è quella della console Anthropic. Aggiornabili con il
     * filtro "ir_model_prices".
     */
    public const PRICES = [
        'claude-opus-5-5' => [4.0, 20.0, 0.20],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.10],
        'claude-fable-5-2' => [8.0, 40.0, 0.20],
    ];
    public const EFFORTS = ['low' => 'Bassa', 'medium' => 'Media', 'high' => 'Alta', 'xhigh' => 'Molto alta', 'max' => 'Massima'];

    public static function api_key(): string
    {
        if (defined('INTEGRA_REPORT_ANTHROPIC_KEY') && INTEGRA_REPORT_ANTHROPIC_KEY) {
            return (string) INTEGRA_REPORT_ANTHROPIC_KEY;
        }
        return (string) get_option('ir_api_key', '');
    }

    /** Chiamata periodicamente durante lo streaming (segnale di vita per i lavori in background). */
    public static $onTick = null;

    /**
     * Modello per scopo ('extract' = lettura dei documenti, 'write' = stesura dei capitoli).
     * Se per lo scopo non è stato scelto un modello specifico vale quello generale.
     */
    public static function model(?string $purpose = null): string
    {
        $general = (string) get_option('ir_model', 'claude-opus-5-5');
        $general = isset(self::MODELS[$general]) ? $general : 'claude-opus-5-5';
        if ($purpose === null) {
            return $general;
        }
        $m = (string) get_option('ir_model_' . $purpose, '');
        return isset(self::MODELS[$m]) ? $m : $general;
    }

    /**
     * Modello per l'estrazione di un documento. I documenti "semplici" (categorie da cui si
     * ricavano quasi solo dati anagrafici e descrittivi) usano un modello più economico; quelli
     * con molti valori numerici (bilanci, Centrale Rischi, scadenziari) il modello di estrazione.
     */
    public static function model_for_slot(string $slot): string
    {
        if (!IR_Catalog::is_simple_slot($slot)) {
            return self::model('extract');
        }
        $m = (string) get_option('ir_model_extract_simple', 'claude-sonnet-5-5');
        return isset(self::MODELS[$m]) ? $m : self::model('extract');
    }

    /** Capitoli analitici che restano comunque sul modello di stesura: sono giudizio, non commento a tabelle. */
    private const WRITE_FULL = ['S21', 'S22'];

    /**
     * Modello per la stesura di un capitolo. Nei capitoli analitici il modello commenta tabelle
     * già calcolate e non può scrivere numeri (solo segnaposto verificati dal plugin): lì basta
     * un modello più economico. Sommario, capitoli qualitativi, rating e raccomandazioni usano
     * il modello di stesura.
     */
    public static function model_for_section(string $sid): string
    {
        $kind = IR_Catalog::sections()[$sid]['kind'] ?? '';
        if ($sid === 'S0' || $kind !== 'analitica' || in_array($sid, self::WRITE_FULL, true)) {
            return self::model('write');
        }
        $m = (string) get_option('ir_model_write_analytic', 'claude-sonnet-5-5');
        return isset(self::MODELS[$m]) ? $m : self::model('write');
    }

    /** Costo stimato in dollari di un consumo di token; null se il prezzo del modello non è noto. */
    public static function cost(array $usage, string $model): ?float
    {
        $prices = apply_filters('ir_model_prices', self::PRICES);
        if (!isset($prices[$model])) {
            return null;
        }
        [$in, $out, $read] = $prices[$model];
        return (($usage['input'] ?? 0) * $in + ($usage['output'] ?? 0) * $out + ($usage['cache_read'] ?? 0) * $read + ($usage['cache_write'] ?? 0) * $in * 1.25) / 1e6;
    }

    /**
     * Registra un consumo nel registro di spesa del report (cumulativo: comprende anche le
     * rielaborazioni). $saved = true per le risposte riprese dalla memoria, che non costano nulla.
     */
    public static function log_spend(array &$state, string $purpose, string $model, array $usage, bool $saved = false): void
    {
        $k = ($saved ? 'saved' : 'paid') . '|' . $purpose . '|' . $model;
        $e = $state['spent'][$k] ?? ['calls' => 0, 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $e['calls']++;
        foreach (['input', 'output', 'cache_read', 'cache_write'] as $f) {
            $e[$f] += (int) ($usage[$f] ?? 0);
        }
        $state['spent'][$k] = $e;
    }

    /** Riepilogo della spesa stimata del report, per fase. */
    public static function report_cost(array $state): array
    {
        $spent = $state['spent'] ?? [];
        if (!$spent) {
            // report creati prima del registro di spesa: si ricostruisce dall'ultima elaborazione nota
            foreach ($state['extraction']['docs'] ?? [] as $d) {
                if (!empty($d['usage'])) {
                    self::log_spend($state, 'extract', (string) ($d['model'] ?? ''), $d['usage']);
                }
            }
            foreach ($state['report']['sections'] ?? [] as $sec) {
                if (!empty($sec['usage'])) {
                    self::log_spend($state, 'write', (string) ($sec['model'] ?? ''), $sec['usage']);
                }
            }
            $spent = $state['spent'] ?? [];
        }
        $out = ['usd' => 0.0, 'extract' => 0.0, 'write' => 0.0, 'saved_usd' => 0.0, 'saved_calls' => 0, 'calls' => 0, 'tokens' => 0, 'unknown' => []];
        foreach ($spent as $k => $e) {
            [$kind, $purpose, $model] = explode('|', $k, 3);
            $c = self::cost($e, $model);
            if ($kind === 'saved') {
                $out['saved_calls'] += $e['calls'];
                $out['saved_usd'] += (float) $c;
                continue;
            }
            $out['calls'] += $e['calls'];
            $out['tokens'] += $e['input'] + $e['output'] + $e['cache_read'] + $e['cache_write'];
            if ($c === null) {
                $out['unknown'][$model] = true;
                continue;
            }
            $out['usd'] += $c;
            $out[$purpose] = ($out[$purpose] ?? 0) + $c;
        }
        $out['unknown'] = array_keys($out['unknown']);
        return $out;
    }

    // ------------------------------------------------------------------ memoria delle risposte

    /** Giorni di conservazione delle risposte di estrazione (0 = memoria disattivata). */
    public static function cache_days(): int
    {
        return max(0, min(365, (int) get_option('ir_cache_days', 30)));
    }

    private static function cache_file(string $key): string
    {
        $dir = IR_Store::base_dir() . '/cache';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir . '/' . $key . '.json';
    }

    /** Impronta della richiesta: cambia se cambia un solo byte di documento, istruzioni, schema, modello o accuratezza. */
    public static function cache_key(string $model, string $effort, string $system, array $content, ?array $schema): string
    {
        return hash('sha256', serialize([$model, $effort, $system, $content, $schema]));
    }

    public static function cache_get(string $key): ?array
    {
        $days = self::cache_days();
        $f = self::cache_file($key);
        if (!$days || !is_file($f) || filemtime($f) < time() - $days * 86400) {
            return null;
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) && isset($d['text'], $d['usage']) ? $d : null;
    }

    public static function cache_put(string $key, array $result): void
    {
        $days = self::cache_days();
        if (!$days) {
            return;
        }
        $f = self::cache_file($key);
        @file_put_contents($f, wp_json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
        foreach (glob(dirname($f) . '/*.json') ?: [] as $old) { // pulizia delle risposte scadute
            if (filemtime($old) < time() - $days * 86400) {
                @unlink($old);
            }
        }
    }

    public static function effort(string $purpose): string
    {
        $default = $purpose === 'extract' ? 'high' : 'high';
        $e = (string) get_option('ir_effort_' . $purpose, $default);
        return isset(self::EFFORTS[$e]) ? $e : $default;
    }

    /**
     * @param string $system   istruzioni di sistema (stabili, messe in cache)
     * @param array  $content  blocchi di contenuto del messaggio utente (formato API)
     * @param array|null $schema JSON schema per structured output (null = testo libero)
     * @param string|null $model modello da usare (null = quello previsto per lo scopo)
     * @param bool $cache   true = riusa la risposta già ottenuta per una richiesta identica
     * @return array{text:string, blocks:array, usage:array, model:string, stop_reason:?string, cached:bool, saved:?array}
     */
    public static function call(string $system, array $content, ?array $schema, string $purpose, ?string $model = null, bool $cache = false): array
    {
        $model = $model ?? self::model($purpose);
        $cacheKey = self::cache_days() ? self::cache_key($model, self::effort($purpose), $system, $content, $schema) : null;
        if ($cache && $cacheKey && ($hit = self::cache_get($cacheKey))) {
            $hit['saved'] = $hit['usage'];
            $hit['usage'] = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
            $hit['cached'] = true;
            return $hit;
        }

        if (!class_exists(Client::class)) {
            throw new RuntimeException('SDK Anthropic non caricato: cartella vendor/ mancante.');
        }
        $key = self::api_key();
        if ($key === '') {
            throw new RuntimeException('Chiave API Anthropic non configurata (Integra Report > Impostazioni).');
        }

        $client = new Client(apiKey: $key, requestOptions: apply_filters('ir_claude_request_options', ['timeout' => 900.0, 'maxRetries' => 3]));

        $outputConfig = ['effort' => self::effort($purpose)];
        if ($schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }

        $stream = $client->beta->messages->createStream(
            maxTokens: 64000,
            messages: [['role' => 'user', 'content' => $content]],
            model: $model,
            fallbacks: 'default',
            outputConfig: $outputConfig,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            betas: ['server-side-fallback-2026-07-01'],
        );

        $acc = MessageAccumulator::forBetaMessages();
        $lastTick = time();
        foreach ($stream as $event) {
            $acc->accumulate($event);
            if (self::$onTick && time() - $lastTick >= 20) {
                $lastTick = time();
                (self::$onTick)();
            }
        }
        $message = json_decode((string) wp_json_encode($acc->message()), true);
        if (!is_array($message)) {
            throw new RuntimeException('Risposta API non interpretabile.');
        }

        $stop = $message['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            $cat = $message['stop_details']['category'] ?? 'n.d.';
            throw new RuntimeException('Il modello ha rifiutato la richiesta (categoria: ' . $cat . '). Nessun dato è stato salvato.');
        }
        if ($stop === 'max_tokens') {
            throw new RuntimeException('Risposta troncata per limite di lunghezza: dividi il documento in file più piccoli e riprova.');
        }

        $text = '';
        $blocks = [];
        foreach (($message['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= $b['text'];
                $blocks[] = ['text' => $b['text'], 'citations' => $b['citations'] ?? []];
            }
        }
        $u = $message['usage'] ?? [];
        $result = [
            'text' => $text,
            'blocks' => $blocks,
            'usage' => [
                'input' => (int) ($u['input_tokens'] ?? 0),
                'output' => (int) ($u['output_tokens'] ?? 0),
                'cache_read' => (int) ($u['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($u['cache_creation_input_tokens'] ?? 0),
            ],
            'model' => (string) ($message['model'] ?? $model),
            'stop_reason' => $stop,
        ];
        if ($purpose === 'extract' && $cacheKey) {
            self::cache_put($cacheKey, $result); // solo l'estrazione: la stesura deve poter variare
        }
        return $result + ['cached' => false, 'saved' => null];
    }

    /** Blocco di contenuto API per un file caricato. */
    public static function file_block(int $report_id, array $file, bool $citations, ?string $title = null): array
    {
        $path = IR_Store::file_path($report_id, $file);
        $kind = IR_Text_Converter::kind(pathinfo($file['name'], PATHINFO_EXTENSION));
        $title = $title ?? $file['name'];
        if ($kind === 'pdf') {
            $block = ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($path))], 'title' => $title];
        } elseif ($kind === 'image') {
            $mime = wp_check_filetype($file['name'])['type'] ?: 'image/png';
            return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode((string) file_get_contents($path))]];
        } else {
            $block = ['type' => 'document', 'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => IR_Text_Converter::to_text($path, $kind)], 'title' => $title];
        }
        if ($citations) {
            $block['citations'] = ['enabled' => true];
        }
        return $block;
    }
}
