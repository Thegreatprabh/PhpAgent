<?php
// PhpAgent v2 - custom commands. Commands are data, not code: adding one
// means adding one entry to data/custom-commands.json. A command with a
// bad mode is rejected with a clear error, never a crash.
// Requires PHP 8.1+. No network, no API (except a command whose mode is
// "gemini" and which is given a configured Gemini client).

declare(strict_types=1);

final class CustomCommands
{
    /** @var array<int, array<string, mixed>> */
    private array $commands = [];

    public function __construct(string $dataDir, ?string $commandsFile = null)
    {
        $file = $commandsFile ?? (rtrim($dataDir, '/') . '/custom-commands.json');
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->commands = $decoded;
            }
        }
    }

    public static function create(?string $dataDir = null): self
    {
        return new self($dataDir ?? (__DIR__ . '/../data'));
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->commands;
    }

    /** @return array<string, mixed>|null */
    public function find(string $name): ?array
    {
        foreach ($this->commands as $command) {
            if (strcasecmp((string) ($command['name'] ?? ''), $name) === 0) {
                return $command;
            }
        }
        return null;
    }

    /**
     * Execute "/command argument". Returns a result array with at least
     * 'status' => 'command', 'text' (plain rendering), and structured
     * fields. $gemini is a GeminiClient or null; $lastTopicId resolves
     * follow-ups such as "/example" with no argument.
     *
     * @param mixed $gemini
     */
    public function execute(string $input, PhpAgent $agent, FunctionIndex $index, $gemini = null, ?string $lastTopicId = null): array
    {
        $input = trim($input);
        $parts = preg_split('/\s+/', $input, 2);
        $name = strtolower((string) ($parts[0] ?? ''));
        $arg = trim((string) ($parts[1] ?? ''));
        $command = $this->find($name);
        if ($command === null) {
            return $this->errorResult($input, 'Unknown command "' . $name . '". Available: ' . $this->namesList());
        }
        $mode = (string) ($command['mode'] ?? '');
        if ($mode !== 'local' && $mode !== 'gemini') {
            return $this->errorResult($input, 'Command "' . $name . '" is misconfigured (mode "' . $mode . '" is not "local" or "gemini"). Fix data/custom-commands.json; nothing was run.');
        }

        switch ($name) {
            case '/fn':
                return $this->fnCommand($arg, $index, $input);
            case '/example':
                return $this->exampleCommand($arg, $agent, $lastTopicId, $input);
            case '/compare':
                return $this->compareCommand($arg, $agent, $index, $input);
            case '/debug':
                return $this->debugCommand($arg, $agent, $input);
            case '/cheat':
                return $this->cheatCommand($arg, $agent, $input);
            case '/explain':
            default:
                return $this->explainCommand($arg, $agent, $index, $gemini, $mode, $input);
        }
    }

    /** @return array<string, mixed> */
    private function errorResult(string $query, string $message): array
    {
        return [
            'status' => 'command',
            'command' => null,
            'entry' => null,
            'score' => 0,
            'suggestions' => [],
            'query' => $query,
            'error' => $message,
            'text' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function okResult(string $query, string $name, string $text, array $extra = []): array
    {
        return array_merge([
            'status' => 'command',
            'command' => $name,
            'entry' => null,
            'score' => 0,
            'suggestions' => [],
            'query' => $query,
            'error' => null,
            'text' => $text,
        ], $extra);
    }

    private function namesList(): string
    {
        $names = [];
        foreach ($this->commands as $command) {
            $names[] = (string) $command['name'];
        }
        return implode(', ', $names);
    }

    /** @return array<string, mixed> */
    private function fnCommand(string $arg, FunctionIndex $index, string $input): array
    {
        if ($arg === '') {
            return $this->errorResult($input, 'Usage: /fn <function name>, for example /fn str_replace');
        }
        $fn = $index->find($arg);
        if ($fn !== null) {
            $text = $this->fnText($fn);
            return $this->okResult($input, '/fn', $text, ['function' => $fn, 'entry' => null]);
        }
        $construct = $index->findConstruct($arg);
        if ($construct !== null) {
            return $this->okResult($input, '/fn', (string) $construct['title'] . "\n" . (string) $construct['answer'] . "\n\n" . (string) $construct['code'], ['entry' => $construct]);
        }
        $closest = $index->closest($arg, 3);
        $text = '"' . $arg . '" is not a built-in function in this index (PHP ' . $index->phpVersion() . ', ' . $index->count() . " functions).\nClosest names: " . implode(', ', $closest) . '.';
        return $this->okResult($input, '/fn', $text, ['closest' => $closest]);
    }

    private function fnText(array $fn): string
    {
        $lines = [];
        $lines[] = (string) $fn['name'];
        $lines[] = 'Signature: ' . (string) $fn['signature'];
        $lines[] = 'Extension: ' . (string) $fn['extension'];
        if (!empty($fn['is_deprecated'])) {
            $lines[] = 'Deprecated: yes - avoid in new code, see the php.net page for the replacement.';
        }
        $lines[] = 'Docs: ' . (string) $fn['url'];
        if (!empty($fn['example_entry'])) {
            $lines[] = 'Verified example: see curated entry "' . (string) $fn['example_entry'] . '".';
        } else {
            $lines[] = 'No verified example in the curated knowledge base for this function yet.';
        }
        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function exampleCommand(string $arg, PhpAgent $agent, ?string $lastTopicId, string $input): array
    {
        $entry = null;
        if ($arg !== '') {
            $result = $agent->answer($arg);
            $entry = $result['entry'];
        } elseif ($lastTopicId !== null) {
            $entry = $agent->entryById($lastTopicId);
        }
        if ($entry === null) {
            return $this->errorResult($input, 'No example found. Ask about a topic first, for example "how do I sort an array?", then use /example.');
        }
        $text = (string) $entry['title'] . "\n\n" . (string) $entry['code'];
        if (!empty($entry['output'])) {
            $text .= "\n\nExpected output:\n" . (string) $entry['output'];
        }
        return $this->okResult($input, '/example', $text, ['entry' => $entry]);
    }

    /** @return array<string, mixed> */
    private function compareCommand(string $arg, PhpAgent $agent, FunctionIndex $index, string $input): array
    {
        if ($arg === '') {
            return $this->errorResult($input, 'Usage: /compare <a> vs <b>, for example /compare sort vs asort');
        }
        $split = preg_split('/\s+(?:vs|and|,)\s+/i', $arg, 2);
        if ($split === false || count($split) < 2) {
            $tokens = preg_split('/\s+/', $arg);
            if ($tokens === false || count($tokens) < 2) {
                return $this->errorResult($input, 'Usage: /compare <a> vs <b>, for example /compare sort vs asort');
            }
            $split = [$tokens[0], $tokens[count($tokens) - 1]];
        }
        $sides = [];
        foreach ($split as $side) {
            $side = trim($side);
            $fn = $index->find($side);
            if ($fn !== null) {
                $sides[] = $this->fnText($fn);
                continue;
            }
            $result = $agent->answer($side);
            if ($result['entry'] !== null) {
                $sides[] = (string) $result['entry']['title'] . "\n" . (string) $result['entry']['answer'] . "\n\n" . (string) $result['entry']['code'];
            } else {
                $sides[] = '"' . $side . '" - not found in the function index or the curated knowledge base.';
            }
        }
        return $this->okResult($input, '/compare', $sides[0] . "\n\n--- vs ---\n\n" . $sides[1]);
    }

    /** @return array<string, mixed> */
    private function debugCommand(string $arg, PhpAgent $agent, string $input): array
    {
        if ($arg === '') {
            return $this->errorResult($input, 'Usage: /debug <error message>, for example /debug headers already sent');
        }
        $errors = $agent->byCategory('Common Errors');
        $best = null;
        $bestScore = 0;
        $normalized = php_agent_normalize($arg);
        $tokens = php_agent_tokens($normalized);
        foreach ($errors as $entry) {
            $score = php_agent_score($entry, $normalized, $tokens);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }
        if ($best === null || $bestScore < 25) {
            return $this->errorResult($input, 'No matching common-error entry for that message. Try the exact error words, for example "headers already sent" or "undefined array key".');
        }
        $text = (string) $best['title'] . "\n\n" . (string) $best['answer'] . "\n\n" . (string) $best['code'];
        return $this->okResult($input, '/debug', $text, ['entry' => $best]);
    }

    /** @return array<string, mixed> */
    private function cheatCommand(string $arg, PhpAgent $agent, string $input): array
    {
        if ($arg === '') {
            return $this->errorResult($input, 'Usage: /cheat <category>, for example /cheat Arrays');
        }
        $entries = $agent->byCategory($arg);
        if ($entries === []) {
            return $this->errorResult($input, 'No category named "' . $arg . '". Categories: ' . implode(', ', $agent->categories()));
        }
        $lines = ['Cheat sheet: ' . (string) $entries[0]['category']];
        foreach ($entries as $entry) {
            $lines[] = '- ' . (string) $entry['title'] . ' (' . (string) $entry['id'] . ')';
        }
        return $this->okResult($input, '/cheat', implode("\n", $lines), ['results' => $entries]);
    }

    /**
     * @param mixed $gemini
     * @return array<string, mixed>
     */
    private function explainCommand(string $arg, PhpAgent $agent, FunctionIndex $index, $gemini, string $mode, string $input): array
    {
        if ($arg === '') {
            return $this->errorResult($input, 'Usage: /explain <function or topic>, for example /explain str_replace');
        }
        if ($mode === 'gemini' && $gemini !== null && method_exists($gemini, 'isConfigured') && $gemini->isConfigured()) {
            $grounding = $agent->groundingFor($arg);
            $answer = $gemini->ask($arg, $grounding['text']);
            if (!empty($answer['ok']) && !empty($answer['text'])) {
                return $this->okResult($input, '/explain', (string) $answer['text'], ['grounded' => $grounding['entries'], 'source' => 'gemini']);
            }
            // Fall through to local on any Gemini failure, with the error noted.
            $local = $this->localExplain($arg, $agent, $index);
            $local['text'] = 'Gemini could not answer (' . (string) ($answer['message'] ?? 'unavailable') . "). Local answer instead:\n\n" . $local['text'];
            return $local;
        }
        return $this->localExplain($arg, $agent, $index);
    }

    /** @return array<string, mixed> */
    private function localExplain(string $arg, PhpAgent $agent, FunctionIndex $index): array
    {
        $parts = [];
        $fn = $index->find($arg);
        if ($fn !== null) {
            $parts[] = $this->fnText($fn);
        }
        $result = $agent->answer($arg);
        $entry = $result['entry'];
        if ($entry !== null) {
            $parts[] = (string) $entry['title'] . "\n" . (string) $entry['answer'] . "\n\n" . (string) $entry['code'];
        }
        if ($parts === []) {
            return $this->errorResult('/explain ' . $arg, 'No explanation found for "' . $arg . '" in the function index or the curated knowledge base. Closest functions: ' . implode(', ', $index->closest($arg, 3)) . '.');
        }
        return $this->okResult('/explain ' . $arg, '/explain', implode("\n\n", $parts), ['entry' => $entry, 'function' => $fn]);
    }
}
