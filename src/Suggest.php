<?php
// PhpAgent v2 - suggestion engine for the search-style UI.
// Pure local data: curated entries + function index + constructs +
// custom commands. No network. Used by public/suggest.php and unit-tested
// directly in tests/run-v2.php.

declare(strict_types=1);

/**
 * @return array<int, array{title:string, label:string, kind:string, query:string}>
 */
function php_agent_suggest(PhpAgent $agent, FunctionIndex $index, CustomCommands $commands, string $query, int $limit = 8): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $lower = php_agent_lower($query);
    $out = [];
    $seen = [];

    $add = function (string $title, string $label, string $kind, string $ask) use (&$out, &$seen) {
        $key = $kind . '|' . $title;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $out[] = ['title' => $title, 'label' => $label, 'kind' => $kind, 'query' => $ask];
    };

    // Custom commands first when the user types a slash command.
    if (str_starts_with($lower, '/')) {
        foreach ($commands->all() as $command) {
            $name = (string) ($command['name'] ?? '');
            if (str_starts_with(strtolower($name), $lower)) {
                $add($name . ' - ' . (string) ($command['description'] ?? ''), 'Command', 'command', $name . ' ');
            }
        }
    }

    // Exact function name wins.
    $fn = $index->find($query);
    if ($fn !== null) {
        $add((string) $fn['name'] . '() - ' . (string) $fn['signature'], (string) $fn['extension'], 'function', '/fn ' . (string) $fn['name']);
    }

    // A function name mentioned inside the question also surfaces.
    foreach (php_agent_tokens(php_agent_normalize($query)) as $token) {
        $tokenFn = $index->find($token);
        if ($tokenFn !== null) {
            $add((string) $tokenFn['name'] . '() - ' . (string) $tokenFn['signature'], (string) $tokenFn['extension'], 'function', '/fn ' . (string) $tokenFn['name']);
        }
    }

    // Curated entries, best matches first.
    foreach ($agent->search($query, 5) as $entry) {
        $add((string) $entry['title'], (string) ($entry['category'] ?? 'General'), 'curated', (string) $entry['title']);
    }

    // Functions whose names contain the query (prefix/contains), top few.
    if (strlen($lower) >= 3) {
        $names = [];
        foreach ($index->closest($query, 30) as $name) {
            $names[] = $name;
        }
        // closest() is distance-ordered; keep only names containing the query
        // or within a small edit distance, so suggestions stay relevant.
        foreach ($names as $name) {
            $entry = $index->find($name);
            if ($entry === null) {
                continue;
            }
            $lname = strtolower($name);
            if (str_contains($lname, $lower) || levenshtein($lower, $lname) <= 2) {
                $add($name . '() - ' . (string) $entry['signature'], (string) $entry['extension'], 'function', '/fn ' . $name);
            }
            if (count($out) >= $limit) {
                break;
            }
        }
    }

    // Language constructs.
    foreach ($index->constructs() as $construct) {
        $title = (string) ($construct['title'] ?? '');
        $firstWord = strtolower(strtok($title, ' '));
        if ($firstWord !== '' && (str_starts_with($firstWord, $lower) || str_contains($title, $query))) {
            $add($title, 'Language construct', 'construct', '/fn ' . $firstWord);
        }
    }

    // "Did you mean?" - closest function names when nothing else matched.
    if ($out === []) {
        foreach ($index->closest($query, 3) as $name) {
            $entry = $index->find($name);
            if ($entry !== null) {
                $add($name . '() - ' . (string) $entry['signature'], (string) $entry['extension'], 'function', '/fn ' . $name);
            }
        }
    }

    return array_slice($out, 0, $limit);
}
