<?php
// PhpAgent v2 - suggestion endpoint for the search-style UI.
// GET suggest.php?q=... -> {"suggestions":[...]}. Local data only, no
// network, no key. Safe for empty or unknown input: returns an empty list.

declare(strict_types=1);

require __DIR__ . '/../src/PhpAgent.php';

header('Content-Type: application/json; charset=utf-8');
$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['suggestions' => []]);
    exit;
}
$agent = PhpAgent::create(__DIR__ . '/../data');
$suggestions = php_agent_suggest($agent, $agent->index(), $agent->commands(), $query, 8);
echo json_encode(['suggestions' => $suggestions]);
