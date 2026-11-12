<?php
/**
 * Wrapper sull'SDK ufficiale Anthropic per PHP.
 * - streaming (evita i timeout HTTP sulle risposte lunghe) con accumulo del messaggio finale;
 * - fallback lato server in caso di rifiuto (beta server-side-fallback-2026-07-01, modalità "default");
 * - prompt caching sul system prompt e, se richiesto, sull'ultimo documento allegato.
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
    public const EFFORTS = ['low' => 'Bassa', 'medium' => 'Media', 'high' => 'Alta', 'xhigh' => 'Molto alta', 'max' => 'Massima'];

    public static function api_key(): string
    {
        if (defined('INTEGRA_REPORT_ANTHROPIC_KEY') && INTEGRA_REPORT_ANTHROPIC_KEY) {
            return (string) INTEGRA_REPORT_ANTHROPIC_KEY;
        }
        return (string) get_option('ir_api_key', '');
    }

    public static function model(): string
    {
        $m = (string) get_option('ir_model', 'claude-opus-5-5');
        return $m !== '' ? $m : 'claude-opus-5-5';
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
     * @return array{text:string, blocks:array, usage:array, model:string, stop_reason:?string}
     */
    public static function call(string $system, array $content, ?array $schema, string $purpose): array
    {
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
            model: self::model(),
            fallbacks: 'default',
            outputConfig: $outputConfig,
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            betas: ['server-side-fallback-2026-07-01'],
        );

        $acc = MessageAccumulator::forBetaMessages();
        foreach ($stream as $event) {
            $acc->accumulate($event);
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
        return [
            'text' => $text,
            'blocks' => $blocks,
            'usage' => [
                'input' => (int) ($u['input_tokens'] ?? 0),
                'output' => (int) ($u['output_tokens'] ?? 0),
                'cache_read' => (int) ($u['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($u['cache_creation_input_tokens'] ?? 0),
            ],
            'model' => (string) ($message['model'] ?? self::model()),
            'stop_reason' => $stop,
        ];
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
