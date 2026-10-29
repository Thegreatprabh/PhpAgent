<?php
// PhpAgent - library API for the offline PHP expert agent.
// No API calls, no network, no database. All answers come from the local
// JSON knowledge base in data/*.json, loaded through src/lib.php.
// v2 adds: the exhaustive reflection-generated function index
// (FunctionIndex), data-driven custom commands (CustomCommands), grounded
// Gemini answers (GeminiClient, BYOK, only when the user configured a key),
// and follow-up resolution against a previous topic.
// Requires PHP 8.1+. No external dependencies.

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/FunctionIndex.php';
require_once __DIR__ . '/CustomCommands.php';
require_once __DIR__ . '/Suggest.php';

/**
 * Library API:
 *
 *   $agent = PhpAgent::create();
 *   $result = $agent->answer('how do I connect to MySQL with PDO?');
 *   // $result['status'] is 'found' | 'not_found' | 'help' | 'topics'
 *   //   | 'search' | 'category' | 'function' | 'command'
 *   // $result['entry'] is the best knowledge entry, or null.
 *   // Pass the previous topic id as the 2nd argument so follow-ups like
 *   // "example" resolve against it: $agent->answer('example', $lastId).
 */
final class PhpAgent
{
    /** @var array<int, array<string, mixed>> */
    private array $entries;

    private FunctionIndex $index;

    private CustomCommands $commands;

    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = $dataDir;
        $this->entries = php_agent_load($dataDir);
        $this->index = new FunctionIndex($dataDir);
        $this->commands = new CustomCommands($dataDir);
    }

    public static function create(?string $dataDir = null): self
    {
        return new self($dataDir ?? (__DIR__ . '/../data'));
    }

    public function index(): FunctionIndex
    {
        return $this->index;
    }

    public function commands(): CustomCommands
    {
        return $this->commands;
    }

    /** @return array<string, mixed>|null */
    public function entryById(string $id): ?array
    {
        foreach ($this->entries as $entry) {
            if ((string) ($entry['id'] ?? '') === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Answer a question, handle a command, or look up a function.
     *
     * Commands (case-insensitive, trimmed):
     *   help | topics | search <words> | category:<name> | category <name>
     *   fn <name> | /explain | /example | /compare | /debug | /cheat | /fn
     *
     * @param mixed $gemini A GeminiClient or null (used by /explain).
     * @return array{status: string, entry: ?array, score: int, suggestions: array, categories?: array, results?: array, query: string}
     */
    public function answer(string $question, ?string $contextId = null, $gemini = null): array
    {
        $question = trim($question);
        $lower = php_agent_lower($question);

        if ($lower === '' || $lower === 'help' || $lower === '?') {
            return [
                'status' => 'help',
                'entry' => null,
                'score' => 0,
                'suggestions' => [],
                'query' => $question,
                'help' => 'Ask any PHP question in English, for example "how do I sort an array?". Commands: help, topics, search <words>, category:<name>, fn <name>, /explain, /example, /compare, /debug, /cheat, /fn. Type exit to quit the interactive loop.',
            ];
        }

        if ($lower === 'topics' || $lower === 'categories' || $lower === 'list topics') {
            return [
                'status' => 'topics',
                'entry' => null,
                'score' => 0,
                'suggestions' => [],
                'query' => $question,
                'categories' => $this->categories(),
            ];
        }

        // Follow-up against the previous topic: "example", "show me an example",
        // "tell me more". Needs the caller's remembered topic id.
        if ($contextId !== null && $this->isFollowUp($lower)) {
            $entry = $this->entryById($contextId);
            if ($entry !== null) {
                return [
                    'status' => 'found',
                    'entry' => $entry,
                    'score' => 100,
                    'suggestions' => [],
                    'query' => $question,
                    'followup' => true,
                ];
            }
        }

        // Custom commands: "/fn str_replace", "/example array sort", ...
        if (str_starts_with($lower, '/')) {
            return $this->commands->execute($question, $this, $this->index, $gemini, $contextId);
        }

        if (str_starts_with($lower, 'fn ')) {
            $name = trim(substr($question, 3));
            return $this->functionResult($name, $question);
        }

        if (str_starts_with($lower, 'search ')) {
            $words = trim(substr($question, 7));
            // A single function name searched directly resolves to its signature.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $words) === 1 && $this->index->find($words) !== null) {
                return $this->functionResult($words, $question);
            }
            return [
                'status' => 'search',
                'entry' => null,
                'score' => 0,
                'suggestions' => [],
                'query' => $question,
                'results' => $this->search($words),
            ];
        }

        if (str_starts_with($lower, 'category:')) {
            $name = trim(substr($question, 9));
            return $this->categoryResult($name, $question);
        }

        if (str_starts_with($lower, 'category ')) {
            $name = trim(substr($question, 9));
            return $this->categoryResult($name, $question);
        }

        $found = php_agent_find($this->entries, $question);
        if ($found['entry'] !== null) {
            return [
                'status' => 'found',
                'entry' => $found['entry'],
                'score' => $found['score'],
                'suggestions' => $found['suggestions'],
                'query' => $question,
            ];
        }

        // A bare function name with no curated match resolves to the index.
        $normalized = php_agent_normalize($question);
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $normalized) === 1 && $this->index->find($normalized) !== null) {
            return $this->functionResult($normalized, $question);
        }

        return [
            'status' => 'not_found',
            'entry' => null,
            'score' => $found['score'],
            'suggestions' => $found['suggestions'],
            'query' => $question,
        ];
    }

    /** @return array<string, mixed> */
    private function functionResult(string $name, string $question): array
    {
        $fn = $this->index->find($name);
        if ($fn !== null) {
            return [
                'status' => 'function',
                'entry' => null,
                'score' => 100,
                'suggestions' => [],
                'query' => $question,
                'name' => $name,
                'function' => $fn,
                'confidence' => 'Exact',
            ];
        }
        $construct = $this->index->findConstruct($name);
        if ($construct !== null) {
            return [
                'status' => 'found',
                'entry' => $construct,
                'score' => 100,
                'suggestions' => [],
                'query' => $question,
                'confidence' => 'Exact',
            ];
        }
        return [
            'status' => 'function',
            'entry' => null,
            'score' => 0,
            'suggestions' => [],
            'query' => $question,
            'name' => $name,
            'function' => null,
            'closest' => $this->index->closest($name, 3),
            'confidence' => '',
        ];
    }

    private function isFollowUp(string $lower): bool
    {
        $normalized = php_agent_normalize($lower);
        $followUps = [
            'example', 'show me an example', 'show example', 'give an example',
            'show me the code', 'show the code', 'show code', 'code',
            'tell me more', 'more', 'explain more', 'go on',
        ];
        return in_array($normalized, $followUps, true);
    }

    /**
     * Local grounding for a Gemini call: top-3 curated matches plus the
     * function signature when the query names a built-in function.
     *
     * @return array{text: string, entries: array<int, array{id: string, title: string}>, function: ?array}
     */
    public function groundingFor(string $query): array
    {
        $matches = $this->search($query, 3);
        $lines = [];
        $entries = [];
        foreach ($matches as $entry) {
            $entries[] = ['id' => (string) ($entry['id'] ?? ''), 'title' => (string) ($entry['title'] ?? '')];
            $code = (string) ($entry['code'] ?? '');
            if (strlen($code) > 600) {
                $code = substr($code, 0, 600) . "\n... (truncated)";
            }
            $lines[] = 'TOPIC: ' . (string) $entry['title'] . "\n" . (string) $entry['answer'] . "\nCODE:\n" . $code;
        }
        $fn = null;
        foreach (php_agent_tokens(php_agent_normalize($query)) as $token) {
            $candidate = $this->index->find($token);
            if ($candidate !== null) {
                $fn = $candidate;
                $lines[] = 'BUILT-IN FUNCTION SIGNATURE: ' . (string) $candidate['signature'] . ' (extension: ' . (string) $candidate['extension'] . ', deprecated: ' . (!empty($candidate['is_deprecated']) ? 'yes' : 'no') . ')';
                break;
            }
        }
        if ($lines === []) {
            $lines[] = '(No local curated entry matched this question. Say so if you cannot answer from verified PHP knowledge.)';
        }
        return ['text' => implode("\n\n", $lines), 'entries' => $entries, 'function' => $fn];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return array<int, string> */
    public function categories(): array
    {
        return php_agent_categories($this->entries);
    }

    /** @return array<int, array<string, mixed>> */
    public function byCategory(string $category): array
    {
        $wanted = php_agent_normalize($category);
        $out = [];
        foreach ($this->entries as $entry) {
            if (php_agent_normalize((string) ($entry['category'] ?? '')) === $wanted) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Ranked search results (best first). Returns at most $limit entries
     * with a positive score, in deterministic order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 5): array
    {
        $normalized = php_agent_normalize($query);
        $tokens = php_agent_tokens($normalized);
        $scored = [];
        foreach ($this->entries as $entry) {
            $score = php_agent_score($entry, $normalized, $tokens);
            // Search is looser than answering: plain words like "password
            // hash" should surface the entry even when no exact keyword
            // phrase or function name matches.
            if (!empty($entry['keywords']) && is_array($entry['keywords'])) {
                $blob = ' ' . php_agent_normalize(implode(' ', array_map('strval', $entry['keywords']))) . ' ';
                foreach ($tokens as $token) {
                    if (strlen($token) >= 3 && str_contains($blob, $token)) {
                        $score += 5;
                    }
                }
            }
            $scored[] = [
                'entry' => $entry,
                'score' => $score,
            ];
        }
        usort($scored, function ($a, $b) {
            $byScore = $b['score'] <=> $a['score'];
            if ($byScore !== 0) {
                return $byScore;
            }
            return strcmp((string) $a['entry']['id'], (string) $b['entry']['id']);
        });
        $out = [];
        foreach ($scored as $item) {
            if ($item['score'] > 0) {
                $out[] = $item['entry'];
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function categoryResult(string $name, string $question): array
    {
        $entries = $this->byCategory($name);
        if ($entries === []) {
            // Fuzzy category name: accept a unique prefix/substring match.
            $wanted = php_agent_normalize($name);
            foreach ($this->categories() as $category) {
                $norm = php_agent_normalize($category);
                if ($wanted !== '' && (str_contains($norm, $wanted) || str_contains($wanted, $norm))) {
                    $entries = $this->byCategory($category);
                    break;
                }
            }
        }
        return [
            'status' => 'category',
            'entry' => null,
            'score' => 0,
            'suggestions' => [],
            'query' => $question,
            'results' => $entries,
            'category' => $name,
        ];
    }
}
