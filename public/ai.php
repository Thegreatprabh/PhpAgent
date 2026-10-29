<?php
// PhpAgent v2 - AI box (localhost only). Run: php -S localhost:8000 -t public
// Mode toggle: Local (verified curated + function index, no key) or Gemini
// (your own free Google AI Studio key, stored only on this machine).
// Gemini answers are grounded in the top local matches + the function
// signature, and the grounding is shown with a "Local data" badge.
// The PHP session remembers the conversation and the last topic, so
// follow-ups like "example" resolve against it. No JS framework, no CDN,
// no external assets. All output escaped.

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/PhpAgent.php';
require __DIR__ . '/../src/GeminiClient.php';
require __DIR__ . '/ui.php';

$agent = PhpAgent::create(__DIR__ . '/../data');
$gemini = new GeminiClient(dirname(__DIR__));
$query = trim((string) ($_REQUEST['q'] ?? ''));
$mode = (string) ($_REQUEST['mode'] ?? 'local');
if ($mode !== 'local' && $mode !== 'gemini') {
    $mode = 'local';
}
$result = null;
$fallback = null;
$geminiSetup = false;
if ($query !== '') {
    $lastTopic = isset($_SESSION['php_agent_last_topic']) ? (string) $_SESSION['php_agent_last_topic'] : null;
    if ($mode === 'gemini') {
        if (!$gemini->isConfigured()) {
            $geminiSetup = true;
            $result = $agent->answer($query, $lastTopic);
        } else {
            $grounding = $agent->groundingFor($query);
            $answer = $gemini->ask($query, $grounding['text']);
            if ($answer['ok']) {
                $result = [
                    'status' => 'gemini',
                    'entry' => null,
                    'score' => 0,
                    'suggestions' => [],
                    'query' => $query,
                    'text' => $answer['text'],
                    'grounded' => $grounding['entries'],
                    'error' => null,
                ];
            } else {
                $result = [
                    'status' => 'gemini',
                    'entry' => null,
                    'score' => 0,
                    'suggestions' => [],
                    'query' => $query,
                    'text' => '',
                    'error' => $answer['message'] . ' Local answer below.',
                    'grounded' => $grounding['entries'],
                ];
                $fallback = $agent->answer($query, $lastTopic);
            }
        }
    } else {
        $result = $agent->answer($query, $lastTopic, $gemini);
    }
    if ($result !== null) {
        if (!empty($result['entry']['id'])) {
            $_SESSION['php_agent_last_topic'] = (string) $result['entry']['id'];
        } elseif (!empty($result['function']['name'])) {
            $_SESSION['php_agent_last_topic'] = 'fn:' . (string) $result['function']['name'];
        }
        $summary = '';
        if (!empty($result['entry']['title'])) {
            $summary = (string) $result['entry']['title'];
        } elseif (!empty($result['function']['name'])) {
            $summary = (string) $result['function']['name'] . '()';
        } elseif (!empty($result['text'])) {
            $summary = substr((string) $result['text'], 0, 80);
        }
        if ($summary !== '') {
            $_SESSION['php_agent_history'][] = ['q' => $query, 'mode' => $mode, 'summary' => $summary];
            $_SESSION['php_agent_history'] = array_slice((array) $_SESSION['php_agent_history'], -10);
        }
    }
}
$history = (array) ($_SESSION['php_agent_history'] ?? []);

php_agent_ui_head('PHP Agent AI box - Local / Gemini');
echo "<body>\n";
echo '<h1>PHP Agent AI box</h1>';
echo '<p class="sub">Local = verified answers, no key. Gemini = your own free key, grounded in local PHP data.</p>';
echo '<p style="text-align:center"><a href="index.php">&larr; Search home</a> &middot; Gemini key: ' . php_agent_e($gemini->maskedKey()) . '</p>';
php_agent_ui_search_form($agent, $query, 'ai.php', true, $mode);
if ($geminiSetup) {
    echo '<div class="answer"><h2>Gemini is not configured yet</h2>';
    echo '<p>Local mode still works, and the local answer is below. To use Gemini:</p>';
    echo '<ol><li>Create a free key yourself at Google AI Studio: aistudio.google.com/apikey</li>';
    echo '<li>Run <code>php setup-gemini.php</code> on this machine and paste the key, or set the <code>GEMINI_API_KEY</code> environment variable.</li>';
    echo '<li>The key stays on this machine only (config/key.php, mode 0600, never committed).</li></ol>';
    echo '<p>If Google rejected a previous key, create a fresh one at AI Studio; a rejected key usually means its project has no Gemini API access.</p></div>';
}
if ($result !== null) {
    php_agent_ui_render_result($agent, $result, $query);
}
if ($fallback !== null) {
    echo '<h2>Local answer</h2>';
    php_agent_ui_render_result($agent, $fallback, $query);
}
if ($history !== []) {
    echo '<div class="history"><h2>This session</h2>';
    foreach (array_reverse($history) as $item) {
        echo '<div class="history-item"><strong>' . php_agent_e((string) $item['q']) . '</strong> <span class="badge">' . php_agent_e((string) $item['mode']) . '</span><br><small>' . php_agent_e((string) $item['summary']) . '</small></div>';
    }
    echo '</div>';
}
php_agent_ui_scripts();
echo "</body></html>\n";
