<?php
// PHP Agent - test runner. Plain PHP, no PHPUnit, no network, no API.
// Run: php tests/run.php
// Exit code 0 = every assertion passed. Every failure is printed by id.

declare(strict_types=1);

require __DIR__ . '/../src/PhpAgent.php';

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failures = [];

/** @param mixed $actual */
function check(bool $ok, string $label, $actual = null): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        $detail = $actual !== null ? ' :: ' . (is_string($actual) ? $actual : json_encode($actual)) : '';
        $failures[] = 'FAIL  ' . $label . $detail;
        echo 'FAIL  ' . $label . $detail . "\n";
    }
}

/** Strip <?php / ?> tags so a snippet can be written into a temp file. */
function kb_strip_tags(string $code): string
{
    $code = trim($code);
    if (str_starts_with($code, '<?php')) {
        $code = trim(substr($code, 5));
    }
    if (str_ends_with($code, '?>')) {
        $code = trim(substr($code, 0, -2));
    }
    return $code;
}

/** Run a command, return [exitCode, combinedOutput]. */
function kb_run(string $command): array
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

$timeout = trim((string) shell_exec('command -v timeout')) !== '' ? 'timeout 10 ' : '';
$phpBin = PHP_BINARY;
$agent = PhpAgent::create($root . '/data');
$entries = $agent->all();

// ---------------------------------------------------------------- load
$dataFiles = glob($root . '/data/*.json');
check($dataFiles !== false && count($dataFiles) >= 15, 'data files present (>=15)', $dataFiles === false ? 0 : count($dataFiles));
foreach ($dataFiles as $file) {
    $raw = file_get_contents($file);
    $decoded = $raw === false ? null : json_decode($raw, true);
    check(is_array($decoded) && json_last_error() === JSON_ERROR_NONE, 'valid JSON: ' . basename($file));
}
check(count($entries) >= 100, 'at least 100 knowledge entries', count($entries));

// ---------------------------------------------------------------- fields
$ids = [];
$required = ['id', 'category', 'title', 'keywords', 'functions', 'answer', 'code', 'output', 'runnable', 'pitfall'];
foreach ($entries as $entry) {
    $id = (string) ($entry['id'] ?? '(missing id)');
    foreach ($required as $key) {
        check(array_key_exists($key, $entry), "entry $id has field $key");
    }
    check(preg_match('/^[a-z0-9-]+$/', $id) === 1, "entry $id id format");
    check(!isset($ids[$id]), "entry $id id unique");
    $ids[$id] = true;
    check(is_string($entry['category'] ?? null) && $entry['category'] !== '', "entry $id category non-empty");
    check(is_string($entry['title'] ?? null) && $entry['title'] !== '', "entry $id title non-empty");
    check(is_array($entry['keywords'] ?? null) && count($entry['keywords']) > 0, "entry $id keywords non-empty");
    check(is_array($entry['functions'] ?? null), "entry $id functions is array");
    check(is_string($entry['answer'] ?? null) && trim((string) $entry['answer']) !== '', "entry $id answer non-empty");
    check(is_string($entry['code'] ?? null) && trim((string) $entry['code']) !== '', "entry $id code non-empty");
    check(array_key_exists('output', $entry) && ($entry['output'] === null || is_string($entry['output'])), "entry $id output is string|null");
    check(is_bool($entry['runnable'] ?? null), "entry $id runnable is bool");
    check(is_string($entry['pitfall'] ?? null) && trim((string) $entry['pitfall']) !== '', "entry $id pitfall non-empty");
    $related = $entry['related'] ?? [];
    check(is_array($related), "entry $id related is array");
}
foreach ($entries as $entry) {
    foreach (($entry['related'] ?? []) as $rel) {
        check(isset($ids[$rel]), "entry {$entry['id']} related id exists: $rel");
    }
}

// Categories cover the required areas.
$categories = $agent->categories();
check(count($categories) >= 15, 'at least 15 categories', count($categories));
foreach (['Basics', 'Strings', 'Arrays', 'OOP', 'Security', 'PDO & Databases', 'PHP 8 Features', 'Laravel-Style Patterns', 'WordPress'] as $needed) {
    check(in_array($needed, $categories, true), "category present: $needed");
}

// ------------------------------------------------------- lint + execute
foreach ($entries as $entry) {
    $id = (string) $entry['id'];
    $tmp = tempnam(sys_get_temp_dir(), 'phpagent_') . '.php';
    file_put_contents($tmp, "<?php\n" . kb_strip_tags((string) $entry['code']) . "\n");
    [$lintCode, $lintOut] = kb_run(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($tmp));
    check($lintCode === 0, "entry $id lint clean", $lintOut);
    if (($entry['runnable'] ?? false) === true) {
        [$runCode, $runOut] = kb_run($timeout . escapeshellarg($phpBin) . ' ' . escapeshellarg($tmp));
        $clean = $runCode === 0
            && !str_contains($runOut, 'Fatal error')
            && !str_contains($runOut, 'Parse error')
            && !str_contains($runOut, 'Uncaught');
        check($clean, "entry $id executes without fatal error (exit $runCode)", $runOut);
        if (isset($entry['output']) && $entry['output'] !== null && $entry['output'] !== '') {
            check(trim($runOut) === trim((string) $entry['output']), "entry $id output matches", 'got: ' . $runOut);
        }
    }
    @unlink($tmp);
}

// Lint every PHP file in the project (including this runner).
$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
        $phpFiles[] = $file->getPathname();
    }
}
check(count($phpFiles) >= 5, 'php files present (>=5)', count($phpFiles));
foreach ($phpFiles as $file) {
    [$code, $out] = kb_run(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($file));
    check($code === 0, 'lint: ' . str_replace($root . '/', '', $file), $out);
}

// ---------------------------------------------------------------- matcher
$cases = [
    ['how do I connect to MySQL with PDO?', 'pdo-connect'],
    ['how do I sort an array?', 'array-sort'],
    ['how do I get a value from an array?', 'arrays-basics'],
    ['how do I use str_replace in PHP?', 'string-functions'],
    ['how to check string contains in PHP using str_contains', 'string-search'],
    ['how to use password_hash and password_verify', 'password-verify'],
    ['how do I create a session?', 'sessions'],
    ['how do I set a cookie?', 'cookies'],
    ['how do I prevent XSS in PHP?', 'xss-prevention'],
    ['how do I prevent SQL injection?', 'sql-injection-prevention'],
    ['how do I create a csrf token?', 'csrf-protection'],
    ['how do I make a cURL GET request?', 'curl-get'],
    ['how do I use json_encode in PHP?', 'json-encode-decode'],
    ['how do I use preg_match?', 'regex-basics'],
    ['how do I create a class?', 'class-object'],
    ['how do I use inheritance in PHP?', 'inheritance'],
    ['how do I use traits in PHP?', 'traits'],
    ['how do I create an enum?', 'enums-php8'],
    ['how do I use match in PHP?', 'match-expression-php8'],
    ['how do I use named arguments?', 'named-arguments'],
    ['how do I use readonly properties?', 'readonly-properties'],
    ['how do I use attributes in PHP?', 'attributes-php8'],
    ['how do I use fibers in PHP?', 'fibers-intro'],
    ['how do I read a file in PHP?', 'file-read-write'],
    ['how do I upload a file in PHP?', 'file-uploads'],
    ['how do I get data from a form?', 'get-post-forms'],
    ['how do I validate form input?', 'validation-sanitization'],
    ['how do I prepare a PDO statement?', 'pdo-prepared-statements'],
    ['how do I insert with PDO?', 'pdo-insert-update'],
    ['how do I use mysqli?', 'mysqli-basics'],
    ['how do I use composer?', 'composer-autoload'],
    ['how do I set up PSR-4 autoloading?', 'psr4-autoloading'],
    ['how do I use WordPress hooks?', 'wordpress-hooks'],
    ['how do I write a PHPUnit test?', 'phpunit-concepts'],
    ['how do I check OPcache?', 'opcache-performance'],
    ['how do I deploy a PHP app?', 'deployment-checklist'],
    ['how do I fix headers already sent error?', 'error-headers-sent'],
    ['how do I fix undefined array key error?', 'error-undefined-index'],
    ['how do I use strlen?', 'string-functions'],
    ['how do I use array_map?', 'array-map-filter'],
    ['how do I write a union type?', 'union-types'],
    ['how do I use the nullsafe operator?', 'nullsafe-operator'],
    ['how do I create routing in plain PHP?', 'routing-plain-php'],
    ['how to create a closure?', 'closures-callbacks'],
    ['what is a superglobal and how do I use it?', 'superglobals'],
    ['how do I format a date in PHP?', 'date-time'],
];
foreach ($cases as [$query, $expected]) {
    $result = $agent->answer($query);
    $got = $result['entry']['id'] ?? '(no match)';
    check($result['status'] === 'found' && $got === $expected, "matcher: \"$query\" -> $expected", "got $got (score {$result['score']})");
}

// Unknown questions: no invented answer, 3 closest topics.
foreach (['how do I train a neural network in PHP?', 'how to build a rocket in PHP', 'what is the weather today?'] as $query) {
    $result = $agent->answer($query);
    check($result['status'] === 'not_found' && $result['entry'] === null, "unknown: \"$query\" returns not_found");
    check(count($result['suggestions'] ?? []) === 3, "unknown: \"$query\" returns 3 suggestions", count($result['suggestions'] ?? []));
}

// Determinism: same input, same output.
$a = $agent->answer('how do I sort an array?');
$b = $agent->answer('how do I sort an array?');
check($a['entry']['id'] === $b['entry']['id'] && $a['score'] === $b['score'], 'matcher deterministic');

// ------------------------------------------------------------- commands
$help = $agent->answer('help');
check($help['status'] === 'help' && !empty($help['help']), 'command: help');
$topics = $agent->answer('topics');
check($topics['status'] === 'topics' && count($topics['categories'] ?? []) >= 15, 'command: topics');
$search = $agent->answer('search password hash');
check($search['status'] === 'search' && count($search['results'] ?? []) >= 1 && count($search['results']) <= 5, 'command: search');
$cat = $agent->answer('category:Security');
check($cat['status'] === 'category' && count($cat['results'] ?? []) >= 1, 'command: category:Security');
$catBad = $agent->answer('category:NoSuchCategoryXYZ');
check($catBad['status'] === 'category' && ($catBad['results'] ?? []) === [], 'command: unknown category empty');
$securityEntries = $agent->byCategory('Security');
check(count($securityEntries) >= 5, 'byCategory Security >= 5', count($securityEntries));
$searchTop = $agent->search('pdo connect');
check(($searchTop[0]['id'] ?? '') === 'pdo-connect', 'library search top hit: pdo connect');

// ------------------------------------------------------------- CLI one-shot
[$cliCode, $cliOut] = kb_run(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/agent.php') . ' ask ' . escapeshellarg('how do I sort an array?'));
check($cliCode === 0 && str_contains($cliOut, 'Sorting arrays'), 'CLI one-shot works', $cliOut);
[$cliCode2, $cliOut2] = kb_run(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/agent.php') . ' ' . escapeshellarg('how do I train a neural network in PHP?'));
check($cliCode2 === 0 && str_contains($cliOut2, 'not in the knowledge base yet'), 'CLI unknown question honest', $cliOut2);

// ------------------------------------------------------- no network calls
// Patterns are built by concatenation so this very file never contains the
// forbidden literal strings itself. Only the cURL *knowledge example text*
// (in JSON data files, not PHP code) may mention them.
// v2: the Gemini client, the function index (php.net URLs), the key setup
// script, and the index generator legitimately contain network-related
// strings. They are allowlisted below and checked separately for key
// leakage; every other shipped file must contain no network pattern.
// Tests are not shipped runtime code and are excluded from this scan.
$networkAllowed = [
    'src/GeminiClient.php',
    'src/FunctionIndex.php',
    'setup-gemini.php',
    'config/gemini.php',
    'tools/generate-function-index.php',
    'public/ai.php',
];
$forbidden = [
    'curl' . '_init', 'curl' . '_exec', 'curl' . '_setopt',
    'file_get_contents' . "('http", 'file_get_contents' . '("http',
    'fopen' . "('http", 'fopen' . '("http',
    'guz' . 'zle', 'open' . 'ai', 'api' . '_key', 'ev' . 'al(',
    'http' . '://', 'https' . '://',
    'stream_socket_' . 'client', 'fsock' . 'open',
];
$scanned = 0;
foreach ($phpFiles as $file) {
    $rel = str_replace($root . '/', '', $file);
    if (str_contains($file, '/tests/')) {
        continue;
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        continue;
    }
    $scanned++;
    if (in_array($rel, $networkAllowed, true)) {
        // Allowlisted: must never contain a real key literal.
        check(!str_contains($contents, 'AI' . 'za'), 'no key literal in allowlisted ' . $rel);
        continue;
    }
    foreach ($forbidden as $pattern) {
        check(!str_contains($contents, $pattern), 'no network call pattern in ' . $rel, $pattern);
    }
}
check($scanned >= 5, 'network scan covered php files', $scanned);

echo "\n" . count($entries) . ' entries, ' . count($categories) . " categories.\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
