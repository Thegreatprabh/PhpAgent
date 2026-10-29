<?php
// PHP Agent - demo. Prints 8 sample questions and answers, one per
// category, all taken from the local knowledge base. No API, no network.
// Run: php demo.php

declare(strict_types=1);

require __DIR__ . '/src/PhpAgent.php';

$agent = PhpAgent::create(__DIR__ . '/data');

$questions = [
    'how do I sort an array?',
    'how to use password_hash and password_verify',
    'how do I connect to MySQL with PDO?',
    'how do I create a class?',
    'how do I create an enum?',
    'how do I upload a file?',
    'how do I use preg_match?',
    'how do I create a session?',
];

foreach ($questions as $i => $question) {
    $result = $agent->answer($question);
    $entry = $result['entry'];
    echo 'Q' . ($i + 1) . ': ' . $question . "\n";
    if ($entry === null) {
        echo "  (not in knowledge base)\n\n";
        continue;
    }
    echo 'A' . ($i + 1) . ': ' . $entry['title'] . ' [' . ($entry['category'] ?? 'General') . "]\n";
    echo '  ' . $entry['answer'] . "\n";
    echo $entry['code'] . "\n";
    if (!empty($entry['output'])) {
        echo '  Expected output: ' . $entry['output'] . "\n";
    }
    echo "\n";
}
