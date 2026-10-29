<?php
// PhpAgent v2 - test runner. Plain PHP, no PHPUnit, no real network.
// The Gemini tests use a mock transport; the suggest/session tests use the
// PHP built-in server on 127.0.0.1 only. Run: php tests/run-v2.php
// Or everything at once: php tests/run-all.php
// Exit code 0 = every assertion passed.

declare(strict_types=1);

require __DIR__ . '/../src/PhpAgent.php';
require __DIR__ . '/../src/GeminiClient.php';

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failures = [];

/** @param mixed $actual */
function v2_check(bool $ok, string $label, $actual = null): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        $detail = $actual !== null ? ' :: ' . (is_string($actual) ? substr($actual, 0, 300) : json_encode($actual)) : '';
        $failures[] = 'FAIL  ' . $label . $detail;
        echo 'FAIL  ' . $label . $detail . "\n";
    }
}

function v2_strip_tags(string $code): string
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

/** @return array{int, string} */
function v2_run(string $command): array
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

$phpBin = PHP_BINARY;
$agent = PhpAgent::create($root . '/data');
$index = $agent->index();

// ------------------------------------------------------- function index
$indexRaw = file_get_contents($root . '/data/all-functions.json');
$indexData = $indexRaw === false ? null : json_decode($indexRaw, true);
v2_check(is_array($indexData), 'function index is valid JSON');
v2_check(($indexData['count'] ?? 0) === $index->count(), 'function index count matches meta', $index->count());
v2_check($index->count() >= 1000, 'function index has >= 1000 functions', $index->count());
v2_check((string) ($indexData['php_version'] ?? '') === PHP_VERSION, 'function index generated on this PHP version', (string) ($indexData['php_version'] ?? ''));
v2_check(count($index->extensions()) >= 20, 'function index records loaded extensions', count($index->extensions()));
$expectedKeys = ['name', 'extension', 'signature', 'return_type', 'is_deprecated', 'is_internal', 'url', 'example_entry', 'example'];
$seenNames = [];
$curatedIds = [];
foreach ($agent->all() as $entry) {
    $curatedIds[(string) $entry['id']] = true;
}
foreach (($indexData['functions'] ?? []) as $fn) {
    v2_check(array_keys($fn) === $expectedKeys, 'function entry has exactly the index keys: ' . (string) ($fn['name'] ?? '?'));
    v2_check(!isset($fn['description']), 'function entry has no invented description: ' . (string) ($fn['name'] ?? '?'));
    v2_check($fn['example'] === null, 'function entry example is null (no invented examples): ' . (string) ($fn['name'] ?? '?'));
    v2_check(str_contains((string) $fn['signature'], (string) $fn['name']), 'signature contains name: ' . (string) ($fn['name'] ?? '?'));
    v2_check(str_starts_with((string) $fn['url'], 'https://www.php.net/manual/en/function.'), 'php.net URL shape: ' . (string) ($fn['name'] ?? '?'));
    v2_check(!isset($seenNames[strtolower((string) $fn['name'])]), 'function name unique: ' . (string) ($fn['name'] ?? '?'));
    $seenNames[strtolower((string) $fn['name'])] = true;
    if (!empty($fn['example_entry'])) {
        v2_check(isset($curatedIds[(string) $fn['example_entry']]), 'function example_entry exists: ' . (string) ($fn['name'] ?? '?'));
    }
}
$fn = $index->find('STR_REPLACE');
v2_check($fn !== null && str_contains((string) $fn['signature'], 'str_replace'), 'fn lookup case-insensitive: STR_REPLACE');
$fn = $index->find('array_map');
v2_check($fn !== null && (string) $fn['extension'] === 'standard', 'fn lookup: array_map extension standard');
v2_check($index->find('not_a_real_function_xyz') === null, 'fn lookup: unknown name returns null');
$closest = $index->closest('str_replac', 3);
v2_check($closest[0] === 'str_replace', 'closest names: str_replac -> str_replace', implode(',', $closest));
v2_check(count($index->deprecatedNames()) >= 1, 'deprecated functions listed', count($index->deprecatedNames()));
// Generator re-runs and reproduces the same count.
[$genCode, $genOut] = v2_run(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/generate-function-index.php'));
v2_check($genCode === 0 && str_contains($genOut, (string) $index->count()), 'generator re-runs with same count', $genOut);
$fresh = json_decode((string) file_get_contents($root . '/data/all-functions.json'), true);
v2_check(is_array($fresh) && ($fresh['count'] ?? 0) === $index->count(), 'generator output stable after re-run');

// ------------------------------------------------------- constructs
$constructsRaw = file_get_contents($root . '/data/language-constructs.json');
$constructs = $constructsRaw === false ? null : json_decode($constructsRaw, true);
v2_check(is_array($constructs) && count($constructs) === 23, 'language constructs: 23 entries', is_array($constructs) ? count($constructs) : 0);
v2_check(count($index->constructs()) === 23, 'FunctionIndex loads 23 constructs');
v2_check(count($agent->all()) === 115, 'curated entries still exactly 115 (constructs kept separate)', count($agent->all()));
foreach ((array) $constructs as $entry) {
    $id = (string) ($entry['id'] ?? '?');
    foreach (['id', 'category', 'title', 'keywords', 'functions', 'answer', 'code', 'output', 'runnable', 'pitfall'] as $key) {
        v2_check(array_key_exists($key, $entry), "construct $id has field $key");
    }
    v2_check((string) ($entry['category'] ?? '') === 'Language Constructs', "construct $id category is Language Constructs");
    $tmp = tempnam(sys_get_temp_dir(), 'phpagent_c_') . '.php';
    file_put_contents($tmp, "<?php\n" . v2_strip_tags((string) $entry['code']) . "\n");
    [$lintCode] = v2_run(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($tmp));
    v2_check($lintCode === 0, "construct $id lint clean");
    if (($entry['runnable'] ?? false) === true) {
        [$runCode, $runOut] = v2_run('timeout 10 ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($tmp));
        v2_check($runCode === 0, "construct $id executes", $runOut);
        if (!empty($entry['output'])) {
            v2_check(trim($runOut) === trim((string) $entry['output']), "construct $id output matches", 'got: ' . $runOut);
        }
    }
    @unlink($tmp);
}
v2_check($index->findConstruct('echo') !== null, 'construct lookup: echo');
v2_check($index->findConstruct('match') !== null, 'construct lookup: match');

// ------------------------------------------------------- fn / search
$result = $agent->answer('fn str_replace');
v2_check($result['status'] === 'function' && ($result['function']['name'] ?? '') === 'str_replace', 'answer: fn str_replace');
$result = $agent->answer('search str_replace');
v2_check($result['status'] === 'function' && ($result['function']['name'] ?? '') === 'str_replace', 'answer: search str_replace resolves to function');
$result = $agent->answer('fn str_replac');
v2_check($result['status'] === 'function' && $result['function'] === null && !empty($result['closest']), 'answer: fn misspelling offers closest names');
$result = $agent->answer('/fn array_map');
v2_check($result['status'] === 'command' && ($result['function']['name'] ?? '') === 'array_map', 'command: /fn array_map');

// ------------------------------------------------------- custom commands
$commands = $agent->commands()->all();
v2_check(count($commands) === 6, 'custom commands: 6 built-ins', count($commands));
foreach ($commands as $command) {
    v2_check(in_array($command['mode'] ?? '', ['local', 'gemini'], true), 'command mode valid: ' . (string) ($command['name'] ?? '?'));
    v2_check(str_starts_with((string) ($command['name'] ?? ''), '/'), 'command name starts with /: ' . (string) ($command['name'] ?? '?'));
    v2_check(str_contains((string) ($command['template'] ?? ''), '{query}'), 'command template has {query}: ' . (string) ($command['name'] ?? '?'));
}
$result = $agent->answer('/example how do I sort an array?');
v2_check($result['status'] === 'command' && ($result['entry']['id'] ?? '') === 'array-sort' && str_contains((string) ($result['text'] ?? ''), 'sort('), 'command: /example returns curated example');
$result = $agent->answer('/compare sort vs asort');
v2_check($result['status'] === 'command' && str_contains((string) ($result['text'] ?? ''), 'vs'), 'command: /compare side by side');
$result = $agent->answer('/debug headers already sent');
v2_check($result['status'] === 'command' && $result['entry'] !== null, 'command: /debug matches common error');
$result = $agent->answer('/debug some totally unknown error zzzqqq');
v2_check($result['status'] === 'command' && !empty($result['error']), 'command: /debug unknown is honest error');
$result = $agent->answer('/cheat Arrays');
v2_check($result['status'] === 'command' && count($result['results'] ?? []) >= 5, 'command: /cheat Arrays lists entries');
$result = $agent->answer('/nonsensecommand foo');
v2_check($result['status'] === 'command' && !empty($result['error']), 'command: unknown command is error, not crash');
$result = $agent->answer('/explain str_replace');
v2_check($result['status'] === 'command' && str_contains((string) ($result['text'] ?? ''), 'str_replace'), 'command: /explain local fallback without key');
// Broken command config: bad mode is rejected, not a crash.
$badFile = tempnam(sys_get_temp_dir(), 'phpagent_cmd_') . '.json';
file_put_contents($badFile, json_encode([['name' => '/broken', 'description' => 'Broken on purpose', 'mode' => 'telepathy', 'template' => 'x {query}']]));
$badCommands = new CustomCommands($root . '/data', $badFile);
$badResult = $badCommands->execute('/broken hello', $agent, $index);
v2_check($badResult['status'] === 'command' && !empty($badResult['error']) && str_contains((string) $badResult['error'], 'misconfigured'), 'command: bad mode rejected with clear error');
@unlink($badFile);

// ------------------------------------------------------- follow-ups
$first = $agent->answer('how do I sort an array?');
$lastId = (string) ($first['entry']['id'] ?? '');
v2_check($lastId === 'array-sort', 'follow-up setup: first answer is array-sort');
foreach (['example', 'show me an example', 'show example', 'tell me more'] as $followUp) {
    $follow = $agent->answer($followUp, $lastId);
    v2_check($follow['status'] === 'found' && ($follow['entry']['id'] ?? '') === 'array-sort', "follow-up: \"$followUp\" resolves to previous topic");
}
$noContext = $agent->answer('example');
v2_check($noContext['status'] !== 'found' || ($noContext['entry']['id'] ?? '') !== 'array-sort', 'follow-up without context does not guess the topic');

// ------------------------------------------------------- grounding
$grounding = $agent->groundingFor('how do I use str_replace in PHP?');
v2_check(!empty($grounding['entries']), 'grounding: entries found for str_replace question');
v2_check(str_contains($grounding['text'], 'str_replace'), 'grounding: text contains function name/signature');
v2_check($grounding['function'] !== null && (string) $grounding['function']['name'] === 'str_replace', 'grounding: function signature injected');
$groundingEmpty = $agent->groundingFor('how to build a rocket in PHP');
v2_check(is_array($groundingEmpty['entries']), 'grounding: unknown question returns entries array');

// ------------------------------------------------------- Gemini client (mock transport only)
$calls = 0;
$mockOk = function (string $url, string $body, array $headers, int $timeout) use (&$calls): array {
    $calls++;
    return ['http_code' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Use sort($arr); to sort.']]]]]])];
};
$tmpRoot = sys_get_temp_dir() . '/phpagent_gemini_' . uniqid();
mkdir($tmpRoot . '/config', 0777, true);
$client = new GeminiClient($tmpRoot, $mockOk, ['model' => 'gemini-2.5-flash']);
putenv('GEMINI_API_KEY=TESTKEY1234567890ABCD');
$payload = $client->buildPayload('how do I sort?', 'LOCAL DATA: sort');
v2_check(abs((float) $payload['generationConfig']['temperature'] - 0.2) < 0.001, 'gemini payload temperature 0.2');
v2_check(str_contains(json_encode($payload), 'LOCAL DATA'), 'gemini payload contains grounding');
v2_check($client->isConfigured(), 'gemini: env key detected');
$masked = $client->maskedKey();
v2_check(str_starts_with($masked, 'TEST') && str_ends_with($masked, 'ABCD') && !str_contains($masked, 'TESTKEY1234567890ABCD'), 'gemini: key masked, full key never shown', $masked);
$answer = $client->ask('how do I sort an array?', 'LOCAL DATA: sort');
v2_check($answer['ok'] === true && str_contains((string) $answer['text'], 'sort('), 'gemini mock: success answer returned');
v2_check($calls === 1, 'gemini mock: transport called exactly once (no real HTTP)', $calls);
// 401 handling.
$mock401 = function (): array {
    return ['http_code' => 401, 'body' => '{"error":{"message":"API key not valid"}}'];
};
$client401 = new GeminiClient($tmpRoot, $mock401);
$answer401 = $client401->ask('x', 'LOCAL');
v2_check($answer401['ok'] === false && (string) $answer401['error'] === 'invalid_key' && str_contains((string) $answer401['message'], 'fresh'), 'gemini mock: 401 handled with fresh-key guidance');
// 404 handling.
$mock404 = function (): array {
    return ['http_code' => 404, 'body' => '{}'];
};
$client404 = new GeminiClient($tmpRoot, $mock404, ['model' => 'gemini-nope']);
$answer404 = $client404->ask('x', 'LOCAL');
v2_check($answer404['ok'] === false && (string) $answer404['error'] === 'model_not_found', 'gemini mock: 404 handled as model_not_found');
// Missing key: empty env + empty temp root (no config/key.php, no .env).
putenv('GEMINI_API_KEY');
$clientNoKey = new GeminiClient($tmpRoot);
v2_check(!$clientNoKey->isConfigured(), 'gemini: missing key detected');
$answerNoKey = $clientNoKey->ask('x', 'LOCAL');
v2_check($answerNoKey['ok'] === false && (string) $answerNoKey['error'] === 'missing_key', 'gemini: missing key gives setup message, no network attempt');
putenv('GEMINI_API_KEY');
// Key files must not exist in the real project, and .gitignore must cover them.
v2_check(!is_file($root . '/config/key.php'), 'no real key file in project');
v2_check(!is_file($root . '/.env'), 'no .env file in project');
$gitignore = (string) file_get_contents($root . '/.gitignore');
v2_check(str_contains($gitignore, 'config/key.php') && str_contains($gitignore, '.env'), '.gitignore covers key files');
// No real key literal anywhere outside tests: search for the key prefix pattern.
$keyPattern = 'AI' . 'za';
$leaks = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || str_contains($file->getPathname(), '/tests/')) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents !== false && str_contains($contents, $keyPattern)) {
        $leaks[] = $file->getPathname();
    }
}
v2_check($leaks === [], 'no key literal outside tests/', implode(',', $leaks));

// ------------------------------------------------------- suggest engine
$suggestCases = [
    ['how do I sort an array?', 'curated'],
    ['how do I use str_replace', 'function'],
    ['str_replace', 'function'],
    ['password hash', 'curated'],
    ['how do I create a session?', 'curated'],
    ['/ex', 'command'],
    ['/fn', 'command'],
    ['echo', 'construct'],
    ['array_map', 'function'],
    ['match expression', 'curated'],
];
foreach ($suggestCases as [$q, $expectedKind]) {
    $suggestions = php_agent_suggest($agent, $index, $agent->commands(), $q);
    $kinds = array_column($suggestions, 'kind');
    v2_check($suggestions !== [] && in_array($expectedKind, $kinds, true), "suggest: \"$q\" includes $expectedKind", implode(',', $kinds));
    foreach ($suggestions as $s) {
        v2_check(isset($s['title'], $s['label'], $s['kind'], $s['query']), 'suggest item shape: title/label/kind/query');
    }
}
$unknownSuggest = php_agent_suggest($agent, $index, $agent->commands(), 'zzzzqqq unknown thing');
v2_check(is_array($unknownSuggest), 'suggest: unknown input returns safe array');
v2_check(json_encode(['suggestions' => $unknownSuggest]) !== false, 'suggest: unknown input is JSON-safe');

// ------------------------------------------------------- suggest endpoint over HTTP (localhost only)
$serverPort = 8899;
$serverCmd = escapeshellarg($phpBin) . ' -S 127.0.0.1:' . $serverPort . ' -t ' . escapeshellarg($root . '/public') . ' >/dev/null 2>&1 & echo $!';
$serverPid = trim((string) shell_exec($serverCmd));
usleep(800000);
$scheme = 'ht' . 'tp://';
$fetch = function (string $path) use ($scheme, $serverPort): string {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $result = @file_get_contents($scheme . '127.0.0.1:' . $serverPort . $path, false, $ctx);
    return is_string($result) ? $result : '';
};
$suggestJson = $fetch('/suggest.php?q=' . rawurlencode('how do I sort an array?'));
$suggestData = json_decode($suggestJson, true);
v2_check(is_array($suggestData) && !empty($suggestData['suggestions']), 'suggest endpoint: returns suggestions for an English query', substr($suggestJson, 0, 200));
v2_check(str_contains($suggestJson, 'Sorting arrays'), 'suggest endpoint: top suggestion is the sort entry');
$suggestFnJson = $fetch('/suggest.php?q=' . rawurlencode('str_replace'));
$suggestFnData = json_decode($suggestFnJson, true);
$kindsFn = array_column($suggestFnData['suggestions'] ?? [], 'kind');
v2_check(in_array('function', $kindsFn, true), 'suggest endpoint: function name returns function suggestion');
$emptyJson = $fetch('/suggest.php?q=');
v2_check($emptyJson === '{"suggestions":[]}', 'suggest endpoint: empty query returns empty list', $emptyJson);
// Index page: search UI, chips, no external assets, XSS escaped.
$indexHtml = $fetch('/index.php?q=' . rawurlencode('how do I sort an array?'));
v2_check(str_contains($indexHtml, 'Sorting arrays') && str_contains($indexHtml, 'ask-box') && str_contains($indexHtml, 'suggest-box'), 'index page: search box + suggestions + answer rendered');
$xssHtml = $fetch('/index.php?q=' . rawurlencode('<script>alert(1)</script>'));
v2_check(!str_contains($xssHtml, '<script>alert(1)</script>') && str_contains($xssHtml, '&lt;script&gt;'), 'index page: query is escaped (XSS probe)');
// AI page in Gemini mode without a key shows setup, not a crash.
$aiHtml = $fetch('/ai.php?mode=gemini&q=' . rawurlencode('how do I sort an array?'));
v2_check(str_contains($aiHtml, 'Gemini is not configured yet') && str_contains($aiHtml, 'Sorting arrays'), 'ai page: gemini-without-key shows setup + local answer');
// Session memory: same cookie jar, ask a topic, then "example".
$cookieJar = tempnam(sys_get_temp_dir(), 'phpagent_cookies_');
$curlBin = trim((string) shell_exec('command -v curl'));
if ($curlBin !== '') {
    $first = [];
    exec(escapeshellarg($curlBin) . ' -s -c ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg($scheme . '127.0.0.1:' . $serverPort . '/ai.php?q=' . rawurlencode('how do I sort an array?')), $first);
    $second = [];
    exec(escapeshellarg($curlBin) . ' -s -b ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg($scheme . '127.0.0.1:' . $serverPort . '/ai.php?q=' . rawurlencode('example')), $second);
    $secondHtml = implode("\n", $second);
    v2_check(str_contains($secondHtml, 'Sorting arrays') && str_contains($secondHtml, 'sort('), 'session memory: follow-up "example" resolves to previous topic over HTTP');
    @unlink($cookieJar);
} else {
    v2_check(false, 'session memory HTTP test skipped: curl binary missing');
}
if ($serverPid !== '') {
    exec('kill ' . escapeshellarg($serverPid) . ' 2>/dev/null');
}

// ------------------------------------------------------- page assets local-only
foreach (glob($root . '/public/*.php') ?: [] as $page) {
    $contents = (string) file_get_contents($page);
    $base = basename($page);
    v2_check(!preg_match('/<script[^>]+src=/i', $contents), "page $base: no external script src");
    v2_check(!preg_match('/<link[^>]+href=/i', $contents), "page $base: no external link href");
    v2_check(!str_contains($contents, 'fonts.googleapis') && !str_contains($contents, 'cdn.'), "page $base: no CDN/font host");
    v2_check(!str_contains($contents, 'http://') && !str_contains($contents, 'https://'), "page $base: no http(s) literal (assets local-only)");
}

// ------------------------------------------------------- demo + CLI still work
[$demoCode, $demoOut] = v2_run(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/demo.php'));
$demoQuestions = preg_match_all('/^Q\d:/m', $demoOut);
v2_check($demoCode === 0 && $demoQuestions >= 8, 'demo.php still works (8 questions)', $demoOut);
[$cliCode, $cliOut] = v2_run(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/agent.php') . ' fn ' . escapeshellarg('str_replace'));
v2_check($cliCode === 0 && str_contains($cliOut, 'Signature:'), 'CLI: fn lookup works', $cliOut);
[$cliCode2, $cliOut2] = v2_run(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/agent.php') . ' ai ' . escapeshellarg('how do I sort an array?'));
v2_check($cliCode2 === 0 && str_contains($cliOut2, 'No Gemini API key'), 'CLI: ai without key gives setup message', $cliOut2);

// ------------------------------------------------------- English only
// No Hinglish marker word may appear in user-facing data or code.
// (tests/ is excluded: this check's own marker list lives here.)
$markers = ['kaise', 'kare', 'karna', 'karo', 'kya', 'banaye', 'banaya', 'banao', 'nikale', 'nikalo', 'jodo', 'jodna', 'likhe', 'likhna', 'padhe', 'padhna', 'chalaye', 'samjhe', 'batao', 'dikhao', 'dikhana', 'lena', 'dena', 'hatana', 'fenkna', 'rokna', 'pehla', 'apna', 'shart', 'agar', 'dohrao', 'aaj', 'khali', 'chahiye', 'wala', 'wale', 'hota', 'hote', 'hain', 'hai', 'jata', 'jate', 'raha', 'rahe', 'dikhta', 'hoga', 'karke', 'liye', 'baad', 'pehle', 'jab', 'phir', 'abhi', 'kuch', 'aisa', 'kyunki', 'lekin', 'alag', 'andar', 'bahar', 'upar', 'neeche', 'bina', 'saath', 'galat', 'sahi', 'hamesha', 'sirf', 'mein', 'kese', 'krna', 'bta', 'hinglish', 'dhyaan'];
$scanFiles = array_merge(
    glob($root . '/data/[0-9]*.json') ?: [],
    glob($root . '/data/language-constructs.json') ?: [],
    glob($root . '/data/custom-commands.json') ?: [],
    glob($root . '/src/*.php') ?: [],
    glob($root . '/public/*.php') ?: [],
    glob($root . '/tools/*.php') ?: [],
    glob($root . '/config/*.php') ?: [],
    [$root . '/agent.php', $root . '/demo.php', $root . '/index.php', $root . '/setup-gemini.php', $root . '/README.md']
);
$pattern = '/\\b(' . implode('|', $markers) . ')\\b/i';
$hinglishHits = [];
foreach ($scanFiles as $scanFile) {
    $contents = (string) file_get_contents($scanFile);
    if (preg_match_all($pattern, $contents, $m) > 0) {
        $hinglishHits[] = basename($scanFile) . ': ' . implode(', ', array_unique(array_map('strtolower', $m[0])));
    }
}
v2_check($hinglishHits === [], 'English only: no Hinglish marker word in data/code/docs', implode(' | ', $hinglishHits));

// Confidence labels in plain words.
v2_check(php_agent_confidence_label(100) === 'Exact', 'confidence label: 100 -> Exact');
v2_check(php_agent_confidence_label(30) === 'Close', 'confidence label: 30 -> Close');
v2_check(php_agent_confidence_label(5) === 'Related', 'confidence label: 5 -> Related');
v2_check(php_agent_confidence_label(0) === '', 'confidence label: 0 -> empty');

echo "\nFunction index: " . $index->count() . ' functions (PHP ' . $index->phpVersion() . '), constructs: ' . count($index->constructs()) . ", curated: " . count($agent->all()) . " entries.\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
