<?php
// PhpAgent v2 - Gemini client (BYOK). PHP only, no dependencies.
// The API key is the USER's own free Google AI Studio key, stored only on
// his own machine. The key is NEVER printed, logged, or committed: status
// output shows only the first 4 and last 4 characters. Transport is
// injectable so tests never make a real HTTP request.
// This file makes the only network calls in the project, and only when
// the user has configured a key and asked for a Gemini answer.

declare(strict_types=1);

final class GeminiClient
{
    private string $rootDir;

    /** @var callable|null fn(string $url, string $body, array $headers, int $timeout): array{http_code:int, body:string} */
    private $transport;

    /** @var array<string, mixed> */
    private array $config;

    /** @var string|null */
    private ?string $keyCache = null;

    private bool $keyLookedUp = false;

    /**
     * @param callable|null $transport
     * @param array<string, mixed> $configOverride
     */
    public function __construct(?string $rootDir = null, $transport = null, array $configOverride = [])
    {
        $this->rootDir = $rootDir ?? dirname(__DIR__);
        $this->transport = $transport;
        $defaults = [
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.2,
            'max_output_tokens' => 1024,
            'timeout' => 30,
        ];
        $fileConfig = [];
        $configFile = $this->rootDir . '/config/gemini.php';
        if (is_file($configFile)) {
            $loaded = include $configFile;
            if (is_array($loaded)) {
                $fileConfig = $loaded;
            }
        }
        $this->config = array_merge($defaults, $fileConfig, $configOverride);
    }

    public static function create(?string $rootDir = null): self
    {
        return new self($rootDir);
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'gemini-2.5-flash');
    }

    /** Resolve the key: env var, then config/key.php, then .env. Never echoes it. */
    public function resolveKey(): ?string
    {
        if ($this->keyLookedUp) {
            return $this->keyCache;
        }
        $this->keyLookedUp = true;
        $env = getenv('GEMINI_API_KEY');
        if (is_string($env) && trim($env) !== '') {
            $this->keyCache = trim($env);
            return $this->keyCache;
        }
        $keyFile = $this->rootDir . '/config/key.php';
        if (is_file($keyFile)) {
            $loaded = include $keyFile;
            if (is_string($loaded) && trim($loaded) !== '') {
                $this->keyCache = trim($loaded);
                return $this->keyCache;
            }
        }
        $envFile = $this->rootDir . '/.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'GEMINI_API_KEY=')) {
                        $value = trim(substr($line, 15), " \t\"'");
                        if ($value !== '') {
                            $this->keyCache = $value;
                            return $this->keyCache;
                        }
                    }
                }
            }
        }
        $this->keyCache = null;
        return null;
    }

    public function isConfigured(): bool
    {
        return $this->resolveKey() !== null;
    }

    /** Masked key for status display only: first 4 + last 4 characters. */
    public function maskedKey(): string
    {
        $key = $this->resolveKey();
        if ($key === null || strlen($key) < 9) {
            return 'not configured';
        }
        return substr($key, 0, 4) . '...' . substr($key, -4);
    }

    /** Build the generateContent payload. Testable without any network call. */
    public function buildPayload(string $prompt, string $groundingText): array
    {
        $system = "You are a PHP expert answering in plain, practical English. Answer PHP questions only, in English. "
            . "Prefer the LOCAL DATA below over general knowledge. Never invent a function signature: use the injected signature, or say the signature is unknown. "
            . "Show a complete PHP code example and one common pitfall. If the topic is not in the local data, say so plainly.\n\nLOCAL DATA:\n" . $groundingText;
        return [
            'contents' => [
                ['parts' => [['text' => $system . "\n\nUSER QUESTION:\n" . $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => (float) ($this->config['temperature'] ?? 0.2),
                'maxOutputTokens' => (int) ($this->config['max_output_tokens'] ?? 1024),
            ],
        ];
    }

    public function endpointUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model()) . ':generateContent';
    }

    /**
     * Ask Gemini a grounded PHP question.
     * @return array{ok:bool, text:string, error:?string, message:string}
     */
    public function ask(string $question, string $groundingText): array
    {
        $key = $this->resolveKey();
        if ($key === null) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'missing_key',
                'message' => 'No Gemini API key is configured. Local mode still works. Run "php setup-gemini.php" and paste your own free Google AI Studio key, or set the GEMINI_API_KEY environment variable. The key is stored only on your own machine.',
            ];
        }
        $payload = json_encode($this->buildPayload($question, $groundingText));
        if ($payload === false) {
            return ['ok' => false, 'text' => '', 'error' => 'payload', 'message' => 'Could not build the Gemini request.'];
        }
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $key];
        $response = $this->send($this->endpointUrl(), $payload, $headers, (int) ($this->config['timeout'] ?? 30));
        $httpCode = (int) ($response['http_code'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($httpCode === 401 || $httpCode === 403) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'invalid_key',
                'message' => 'Google rejected this API key (HTTP ' . $httpCode . '). A key can be rejected when its Google Cloud project has no access to the Gemini API. Create a fresh free key at Google AI Studio (aistudio.google.com/apikey) and run "php setup-gemini.php" again. This is a key/account issue, not a PhpAgent bug.',
            ];
        }
        if ($httpCode === 404) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'model_not_found',
                'message' => 'The model "' . $this->model() . '" was not found (HTTP 404). Edit config/gemini.php and set "model" to a model name available for your key, then try again.',
            ];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'http_error',
                'message' => 'Gemini returned HTTP ' . $httpCode . '. Check your network connection and try again. No answer was invented.',
            ];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'text' => '', 'error' => 'bad_response', 'message' => 'Gemini returned a response PhpAgent could not read. Try again; no answer was invented.'];
        }
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            $apiMessage = $decoded['error']['message'] ?? null;
            return [
                'ok' => false,
                'text' => '',
                'error' => 'empty_response',
                'message' => is_string($apiMessage) && $apiMessage !== '' ? 'Gemini error: ' . $apiMessage : 'Gemini returned no answer text. Try again; no answer was invented.',
            ];
        }
        return ['ok' => true, 'text' => $text, 'error' => null, 'message' => ''];
    }

    /** @return array{http_code:int, body:string} */
    private function send(string $url, string $body, array $headers, int $timeout): array
    {
        if ($this->transport !== null) {
            return (array) call_user_func($this->transport, $url, $body, $headers, $timeout);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            $result = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['http_code' => $code, 'body' => is_string($result) ? $result : ''];
        }
        // Fallback for PHP installs without the cURL extension: streams.
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('/HTTP\/\S+\s+(\d{3})/', $headerLine, $m) === 1) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }
        return ['http_code' => $code, 'body' => is_string($result) ? $result : ''];
    }
}
