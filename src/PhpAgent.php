<?php
// PhpAgent - library API for the offline PHP expert agent.
// No API calls, no network, no database. All answers come from the local
// JSON knowledge base in data/*.json, loaded through src/lib.php.
// Requires PHP 8.1+ (uses constructor property promotion and readonly-free
// typed properties only). No external dependencies.

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * Library API:
 *
 *   $agent = PhpAgent::create();
 *   $result = $agent->answer('how do I connect to MySQL with PDO?');
 *   // $result['status'] is 'found' | 'not_found' | 'help' | 'topics'
 *   //   | 'search' | 'category'
 *   // $result['entry'] is the best knowledge entry, or null.
 */
final class PhpAgent
{
    /** @var array<int, array<string, mixed>> */
    private array $entries;

    public function __construct(string $dataDir)
    {
        $this->entries = php_agent_load($dataDir);
    }

    public static function create(?string $dataDir = null): self
    {
        return new self($dataDir ?? (__DIR__ . '/../data'));
    }

    /**
     * Answer a question, or handle a built-in command.
     *
     * Commands (case-insensitive, trimmed):
     *   help | topics | search <words> | category:<name> | category <name>
     *
     * @return array{status: string, entry: ?array, score: int, suggestions: array, categories?: array, results?: array, query: string}
     */
    public function answer(string $question): array
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
                'help' => 'Ask any PHP question in English or Hinglish, for example "how do I sort an array?" or "array ko sort kaise kare". Commands: help, topics, search <words>, category:<name>. Type exit to quit the interactive loop.',
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

        if (str_starts_with($lower, 'search ')) {
            $words = trim(substr($question, 7));
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
        return [
            'status' => $found['entry'] !== null ? 'found' : 'not_found',
            'entry' => $found['entry'],
            'score' => $found['score'],
            'suggestions' => $found['suggestions'],
            'query' => $question,
        ];
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
