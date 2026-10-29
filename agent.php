<?php
// PHP Agent - CLI entry point. No API, no network, no database.
// Usage:
//   php agent.php ask "how do I connect to MySQL with PDO?"
//   php agent.php "array ko sort kaise kare"   (one-shot, ask is optional)
//   php agent.php help | topics | search <words> | category:<name>
//   php agent.php                             (interactive loop)

declare(strict_types=1);

require __DIR__ . '/src/PhpAgent.php';

$agent = PhpAgent::create(__DIR__ . '/data');
if ($agent->all() === []) {
    fwrite(STDERR, "Knowledge base empty: data/*.json not found.\n");
    exit(1);
}

/** Print one answer result to STDOUT. */
function php_agent_cli_print(PhpAgent $agent, array $result): void
{
    switch ($result['status']) {
        case 'help':
            echo "PHP Agent (offline, no API).\n\n";
            echo ($result['help'] ?? '') . "\n";
            echo 'Topics: ' . count($agent->all()) . ' entries in ' . count($agent->categories()) . " categories.\n";
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
    }

    $entry = $result['entry'];
    if ($entry === null) {
        echo "This exact topic is not in the knowledge base yet. No answer was invented.\n\n";
        if (($result['suggestions'] ?? []) !== []) {
            echo "Closest topics:\n";
            foreach ($result['suggestions'] as $suggestion) {
                echo '  - ' . $suggestion['title'] . '  (' . $suggestion['id'] . ")\n";
            }
            echo "\nAsk about one of these, or run: php agent.php topics\n";
        }
        return;
    }

    echo "\n" . $entry['title'] . "\n";
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
}

$args = array_slice($argv, 1);
if ($args !== []) {
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

// Interactive mode.
echo "PHP Agent (offline, no API). Ask any PHP question, 'exit' to quit.\n";
echo 'Topics: ' . count($agent->all()) . ' entries | Categories: ' . implode(', ', $agent->categories()) . "\n\n";
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
    php_agent_cli_print($agent, $agent->answer($query));
    echo "\n";
}
