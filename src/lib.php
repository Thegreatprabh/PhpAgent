<?php
// PHP Agent - offline, no-API expert system.
// All answers are pre-decided text in data/*.json. Adding a topic
// means adding one JSON entry - no code change is needed.
// Requires PHP 7.4+ only. No extensions, no Composer, no network.

declare(strict_types=1);

/**
 * Lowercase helper that works with or without the mbstring extension.
 * Queries here are ASCII / romanised text, so strtolower is a safe fallback.
 */
function php_agent_lower(string $text): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

/** Length helper that works with or without the mbstring extension. */
function php_agent_len(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    return strlen($text);
}

/**
 * Load every entry from data/*.json and return one flat list.
 * @return array<int, array<string, mixed>>
 */
function php_agent_load(string $dataDir): array
{
    $entries = [];
    $files = glob(rtrim($dataDir, '/') . '/*.json');
    if ($files === false) {
        return [];
    }
    sort($files);
    // The function index, constructs, and custom commands are separate
    // layers with their own shapes; they are loaded by FunctionIndex and
    // CustomCommands, not as curated entries.
    $skip = ['all-functions.json', 'language-constructs.json', 'custom-commands.json'];
    foreach ($files as $file) {
        if (in_array(basename($file), $skip, true)) {
            continue;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $entry) {
            if (isset($entry['id'], $entry['title'], $entry['answer'], $entry['code'])) {
                $entries[] = $entry;
            }
        }
    }
    return $entries;
}

/** Lowercase, strip punctuation, collapse spaces. Hinglish stays as-is. */
function php_agent_normalize(string $text): string
{
    $text = php_agent_lower($text);
    $text = preg_replace('/[^a-z0-9_]+/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string) $text);
    return trim((string) $text);
}

/**
 * Hinglish / synonym expansion: each token also contributes its canonical
 * English equivalent, so "array me value kaise nikale" matches English
 * keywords like "get" as well as the Hinglish phrase itself.
 * @return array<string, string> token => canonical token
 */
function php_agent_synonyms(): array
{
    return [
        'banao' => 'create', 'banana' => 'create', 'banaye' => 'create', 'banaya' => 'create',
        'nikalo' => 'get', 'nikale' => 'get', 'nikalna' => 'get', 'nikal' => 'get',
        'le' => 'get', 'lena' => 'get',
        'jodo' => 'add', 'jodna' => 'add', 'jod' => 'add',
        'hatao' => 'remove', 'hatana' => 'remove', 'mitao' => 'delete',
        'dikhao' => 'show', 'dikha' => 'show', 'dekho' => 'show',
        'bhejo' => 'send', 'bhejna' => 'send',
        'padho' => 'read', 'padhna' => 'read',
        'likho' => 'write', 'likhna' => 'write',
        'chalao' => 'run', 'chalana' => 'run',
        'samjho' => 'explain', 'samjhao' => 'explain',
        'badlo' => 'change', 'badalna' => 'change',
        'daalo' => 'insert', 'bharo' => 'fill',
        'dhoondho' => 'search', 'dhoondhna' => 'search', 'khojo' => 'search',
        'galti' => 'error',
    ];
}

/** Split a normalized query into tokens, dropping tiny stopwords. */
function php_agent_tokens(string $normalized): array
{
    $stop = ['ka', 'ki', 'ko', 'me', 'se', 'pe', 'kya', 'hai', 'kese', 'kaise', 'karna', 'kare', 'karo', 'the', 'how', 'to', 'in', 'a', 'an', 'do', 'is', 'php', 'batao', 'bta', 'btao', 'chahiye', 'wala', 'wale', 'kr', 'krna'];
    $synonyms = php_agent_synonyms();
    $tokens = explode(' ', $normalized);
    $out = [];
    foreach ($tokens as $token) {
        if ($token !== '' && !in_array($token, $stop, true)) {
            $out[] = $token;
            if (isset($synonyms[$token])) {
                $out[] = $synonyms[$token];
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * Score one entry against a query. Exact function names win outright;
 * keyword phrases and token overlap decide the rest.
 */
function php_agent_score(array $entry, string $normalized, array $tokens): int
{
    $score = 0;

    // Exact PHP function name in the query, e.g. "array_map kaise use kare".
    if (!empty($entry['functions']) && is_array($entry['functions'])) {
        foreach ($entry['functions'] as $fn) {
            if (in_array(php_agent_lower((string) $fn), $tokens, true)) {
                $score += 100;
            }
        }
    }

    // Keyword phrases, e.g. "password hash", "file upload", "form submit".
    // Matched on whole words only: a short keyword like "glob" must not
    // fire inside "superglobal".
    if (!empty($entry['keywords']) && is_array($entry['keywords'])) {
        $padded = ' ' . $normalized . ' ';
        foreach ($entry['keywords'] as $keyword) {
            $kw = php_agent_normalize((string) $keyword);
            if ($kw !== '' && strpos($padded, ' ' . $kw . ' ') !== false) {
                $score += 25;
            }
        }
    }

    // Title words present in the query. An exact word scores most; a
    // shared stem of 5+ letters (prepare/prepared) scores half.
    $titleTokens = php_agent_tokens(php_agent_normalize((string) ($entry['title'] ?? '')));
    foreach ($titleTokens as $titleToken) {
        if (in_array($titleToken, $tokens, true)) {
            $score += 8;
            continue;
        }
        foreach ($tokens as $token) {
            if (strlen($token) >= 5 && strlen($titleToken) >= 5) {
                $common = 0;
                $limit = min(strlen($token), strlen($titleToken));
                while ($common < $limit && $token[$common] === $titleToken[$common]) {
                    $common++;
                }
                if ($common >= 5) {
                    $score += 4;
                    break;
                }
            }
        }
    }

    // Single-word near-miss on a function name, e.g. "str_replac".
    if (!empty($entry['functions']) && is_array($entry['functions'])) {
        foreach ($tokens as $token) {
            foreach ($entry['functions'] as $fn) {
                if (strlen($token) >= 4 && levenshtein($token, (string) $fn) === 1) {
                    $score += 40;
                }
            }
        }
    }

    // Fuzzy fallback: a query close to the whole title still finds the entry,
    // e.g. a one-letter typo in a topic name. Never enough on its own to
    // beat the 25-point match threshold unless other signals agree.
    $titleNorm = php_agent_normalize((string) ($entry['title'] ?? ''));
    if ($titleNorm !== '' && $normalized !== '') {
        $percent = 0.0;
        similar_text($normalized, $titleNorm, $percent);
        if ($percent >= 70.0) {
            $score += 10;
        } elseif (levenshtein($normalized, $titleNorm) <= 3 && strlen($normalized) >= 5) {
            $score += 10;
        }
    }

    return $score;
}

/**
 * Find the best answer. Returns ['entry' => ?array, 'score' => int, 'suggestions' => array].
 * A match needs score >= 25, so a vague query never gets a confident wrong answer.
 */
function php_agent_find(array $entries, string $query): array
{
    $normalized = php_agent_normalize($query);
    $tokens = php_agent_tokens($normalized);
    $scored = [];
    foreach ($entries as $entry) {
        $scored[] = ['entry' => $entry, 'score' => php_agent_score($entry, $normalized, $tokens)];
    }
    usort($scored, function ($a, $b) {
        $byScore = $b['score'] <=> $a['score'];
        if ($byScore !== 0) {
            return $byScore;
        }
        // Deterministic tie-break: same score, same winner, every run.
        return strcmp((string) $a['entry']['id'], (string) $b['entry']['id']);
    });

    $best = $scored[0] ?? null;
    $suggestions = [];
    foreach (array_slice($scored, 0, 3) as $item) {
        $suggestions[] = $item['entry'];
    }

    if ($best !== null && $best['score'] >= 25) {
        return ['entry' => $best['entry'], 'score' => $best['score'], 'suggestions' => $suggestions];
    }
    return ['entry' => null, 'score' => $best['score'] ?? 0, 'suggestions' => $suggestions];
}

/** Match confidence in plain words, never a raw number dump. */
function php_agent_confidence_label(int $score): string
{
    if ($score >= 100) {
        return 'Exact';
    }
    if ($score >= 25) {
        return 'Close';
    }
    if ($score > 0) {
        return 'Related';
    }
    return '';
}

/** All distinct categories, in first-seen order. */
function php_agent_categories(array $entries): array
{
    $categories = [];
    foreach ($entries as $entry) {
        $cat = (string) ($entry['category'] ?? 'General');
        if (!in_array($cat, $categories, true)) {
            $categories[] = $cat;
        }
    }
    return $categories;
}

/** Find entries by id, for the "related" links. */
function php_agent_by_ids(array $entries, array $ids): array
{
    $found = [];
    foreach ($ids as $id) {
        foreach ($entries as $entry) {
            if ($entry['id'] === $id) {
                $found[] = $entry;
                break;
            }
        }
    }
    return $found;
}
