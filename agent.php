<?php
// PHP Agent - CLI entry point. No API, no network, no database by default.
// Usage:
//   php agent.php ask "how do I connect to MySQL with PDO?"
//   php agent.php "how do I sort an array?"   (one-shot, ask is optional)
//   php agent.php help | topics | search <words> | category:<name>
//   php agent.php fn <name>                   (exhaustive function index)
//   php agent.php /explain str_replace        (custom commands)
//   php agent.php ai "question"               (Gemini, BYOK - needs a key)
//   php agent.php                             (interactive loop, remembers
//                                              the last topic for follow-ups
//                                              like "example")

declare(strict_types=1);

require __DIR__ . '/src/PhpAgent.php';
require __DIR__ . '/src/GeminiClient.php';

$agent = PhpAgent::create(__DIR__ . '/data');
if ($agent->all() === []) {
    fwrite(STDERR, "Knowledge base empty: data/*.json not found.\n");
    exit(1);
}

/** Print one function-index result. */
function php_agent_cli_print_function(PhpAgent $agent, array $result): void
{
    $fn = $result['function'] ?? null;
    if ($fn === null) {
        echo '"' . ($result['name'] ?? $result['query'] ?? '') . "\" is not a built-in function in this index.\n";
        if (!empty($result['closest'])) {
            echo 'Did you mean: ' . implode(', ', $result['closest']) . "?\n";
        }
        echo "The index holds " . $agent->index()->count() . ' functions from PHP ' . $agent->index()->phpVersion() . ". Regenerate it on another install with: php tools/generate-function-index.php\n";
        return;
    }
    echo "\n" . $fn['name'] . "()\n";
    echo 'Signature: ' . $fn['signature'] . "\n";
    echo 'Extension: ' . $fn['extension'] . "\n";
    if (!empty($fn['is_deprecated'])) {
        echo "Deprecated: yes - avoid in new code.\n";
    }
    echo 'Docs: ' . $fn['url'] . "\n";
    if (!empty($fn['example_entry'])) {
        echo 'Verified example: curated entry "' . $fn['example_entry'] . "\".\n";
    } else {
        echo "No verified example in the curated knowledge base for this function yet.\n";
    }
}

/** Print one answer result to STDOUT. */
function php_agent_cli_print(PhpAgent $agent, array $result): void
{
    switch ($result['status']) {
        case 'help':
            echo "PHP Agent (offline local mode; Gemini only with your own key).\n\n";
            echo ($result['help'] ?? '') . "\n";
            echo 'Topics: ' . count($agent->all()) . ' entries in ' . count($agent->categories()) . ' categories. ';
            echo 'Function index: ' . $agent->index()->count() . ' functions (PHP ' . $agent->index()->phpVersion() . ").\n";
            echo "Custom commands: ";
            $names = [];
            foreach ($agent->commands()->all() as $command) {
                $names[] = $command['name'];
            }
            echo implode(', ', $names) . "\n";
            return;
        case 'topics':
            echo "Categories:\n";
            foreach (($result['categories'] ?? []) as $category) {
                $count = count($agent->byCategory($category));
                echo '  - ' . $category . ' (' . $count . " entries)\n";
            }
            return;
        case 'search':
            $results = $result['results'] ?? [];
            if ($results === []) {
                echo "No topics matched that search.\n";
                return;
            }
            echo "Search results:\n";
            foreach ($results as $entry) {
                echo '  - ' . $entry['title'] . '  (' . $entry['id'] . ")\n";
            }
            return;
        case 'category':
            $results = $result['results'] ?? [];
            if ($results === []) {
                echo 'No category named "' . ($result['category'] ?? '') . "\" found.\n";
                echo 'Categories: ' . implode(', ', $agent->categories()) . "\n";
                return;
            }
            echo 'Category: ' . ($results[0]['category'] ?? '') . "\n";
            foreach ($results as $entry) {
                echo '  - ' . $entry['title'] . '  (' . $entry['id'] . ")\n";
            }
            return;
        case 'function':
            php_agent_cli_print_function($agent, $result);
            return;
        case 'command':
            if (!empty($result['error'])) {
                echo $result['error'] . "\n";
                return;
            }
            echo "\n" . ($result['text'] ?? '') . "\n";
            return;
        case 'gemini':
            if (!empty($result['error'])) {
                echo $result['error'] . "\n";
                return;
            }
            if (!empty($result['grounded'])) {
                echo "Grounded in local data: ";
                $titles = [];
                foreach ($result['grounded'] as $g) {
                    $titles[] = $g['title'];
                }
                echo implode(' | ', $titles) . "\n\n";
            }
            echo ($result['text'] ?? '') . "\n";
            return;
    }

    $entry = $result['entry'];
    if ($entry === null) {
        echo "This exact topic is not in the knowledge base yet. No answer was invented.\n\n";
        if (($result['suggestions'] ?? []) !== []) {
            echo "Did you mean one of these?\n";
            foreach ($result['suggestions'] as $suggestion) {
                echo '  - ' . $suggestion['title'] . '  (' . $suggestion['id'] . ")\n";
            }
            echo "\nAsk about one of these, or run: php agent.php topics\n";
        }
        return;
    }

    $label = php_agent_confidence_label((int) ($result['score'] ?? 0));
    echo "\n" . $entry['title'] . ($label !== '' ? '  [' . $label . ' match]' : '') . "\n";
    echo str_repeat('-', strlen((string) $entry['title'])) . "\n";
    echo 'Category: ' . ($entry['category'] ?? 'General') . "\n\n";
    echo $entry['answer'] . "\n\n";
    echo $entry['code'] . "\n\n";
    if (array_key_exists('output', $entry) && $entry['output'] !== null && $entry['output'] !== '') {
        echo "Expected output:\n" . $entry['output'] . "\n\n";
    }
    if (!empty($entry['pitfall'])) {
        echo 'Common mistake: ' . $entry['pitfall'] . "\n\n";
    }
    if (!empty($entry['related']) && is_array($entry['related'])) {
        $related = php_agent_by_ids($agent->all(), $entry['related']);
        if ($related !== []) {
            $titles = [];
            foreach ($related as $item) {
                $titles[] = $item['title'];
            }
            echo 'Related: ' . implode(' | ', $titles) . "\n";
        }
    }
    // Function signature card when the entry is about a built-in function.
    foreach ((array) ($entry['functions'] ?? []) as $fnName) {
        $fn = $agent->index()->find((string) $fnName);
        if ($fn !== null) {
            echo 'Function: ' . $fn['signature'] . '  [' . $fn['extension'] . ']  ' . $fn['url'] . "\n";
            break;
        }
    }
}

/** Remember the last topic id from a result, for follow-ups. */
function php_agent_last_topic_id(array $result): ?string
{
    if (!empty($result['entry']['id'])) {
        return (string) $result['entry']['id'];
    }
    if (!empty($result['function']['name'])) {
        return 'fn:' . (string) $result['function']['name'];
    }
    return null;
}

$args = array_slice($argv, 1);
if ($args !== []) {
    // Gemini mode: php agent.php ai "question"  (BYOK, key on this machine)
    if ($args[0] === 'ai' || $args[0] === '--gemini') {
        $query = trim(implode(' ', array_slice($args, 1)));
        if ($query === '') {
            fwrite(STDERR, "Usage: php agent.php ai \"your PHP question\"\n");
            exit(1);
        }
        $gemini = new GeminiClient(__DIR__);
        $grounding = $agent->groundingFor($query);
        $answer = $gemini->ask($query, $grounding['text']);
        if (!$answer['ok']) {
            echo $answer['message'] . "\n";
            exit(0);
        }
        php_agent_cli_print($agent, [
            'status' => 'gemini',
            'entry' => null,
            'score' => 0,
            'suggestions' => [],
            'query' => $query,
            'text' => $answer['text'],
            'grounded' => $grounding['entries'],
        ]);
        exit(0);
    }
    // `php agent.php ask "..."` and `php agent.php "..."` both work.
    if ($args[0] === 'ask') {
        $args = array_slice($args, 1);
    }
    $query = trim(implode(' ', $args));
    if ($query === '') {
        fwrite(STDERR, "Usage: php agent.php ask \"your PHP question\"\n");
        exit(1);
    }
    php_agent_cli_print($agent, $agent->answer($query));
    exit(0);
}

// Interactive mode: remembers the last topic for follow-ups like "example".
echo "PHP Agent (offline local mode; Gemini with: php agent.php ai \"...\"). Ask any PHP question, 'exit' to quit.\n";
echo 'Topics: ' . count($agent->all()) . ' entries | Functions: ' . $agent->index()->count() . " | Categories: " . implode(', ', $agent->categories()) . "\n\n";
$lastTopicId = null;
while (true) {
    echo 'poochho > ';
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }
    $query = trim($line);
    if ($query === '') {
        continue;
    }
    if (in_array(php_agent_lower($query), ['exit', 'quit', 'bye'], true)) {
        break;
    }
    $result = $agent->answer($query, $lastTopicId);
    php_agent_cli_print($agent, $result);
    $remembered = php_agent_last_topic_id($result);
    if ($remembered !== null) {
        $lastTopicId = $remembered;
    }
    echo "\n";
}
