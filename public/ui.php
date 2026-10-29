<?php
// PhpAgent v2 - shared UI helpers for public/index.php and public/ai.php.
// Local-only: no external fonts, no CDN, no framework, no AI images.
// Vanilla JavaScript only. Suggestions come from suggest.php on this
// same app. History memory: PHP session on the server (last topic +
// conversation) and browser localStorage on localhost only (recent
// searches + pinned topics; nothing leaves the machine).

declare(strict_types=1);

function php_agent_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Render <head> + styles. Call after session_start(), before <body> content. */
function php_agent_ui_head(string $title): void
{
    echo '<!doctype html>' . "\n";
    echo '<html lang="en"><head><meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . php_agent_e($title) . "</title>\n";
    echo '<style>
body { font-family: system-ui, sans-serif; max-width: 780px; margin: 0 auto; padding: 1rem; line-height: 1.5; color: #111827; }
h1 { text-align: center; margin-bottom: .25rem; }
.sub { text-align: center; color: #6b7280; margin-top: 0; }
.search-wrap { position: relative; max-width: 640px; margin: 1.25rem auto; }
.search-wrap input[type=text] { width: 100%; box-sizing: border-box; padding: .9rem 1rem; font-size: 1.1rem; border: 2px solid #374151; border-radius: 999px; }
.search-wrap button.go { margin-top: .6rem; width: 100%; padding: .7rem; font-size: 1rem; border-radius: 999px; border: none; background: #111827; color: #fff; cursor: pointer; }
.suggest-box { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #d1d5db; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 20; display: none; margin-top: 4px; overflow: hidden; }
.suggest-item { padding: .6rem .9rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; }
.suggest-item:last-child { border-bottom: none; }
.suggest-item.active, .suggest-item:hover { background: #eff6ff; }
.suggest-item .badge { float: right; font-size: .75rem; background: #e5e7eb; border-radius: 999px; padding: .1rem .5rem; }
.suggest-item .kind { font-size: .75rem; color: #6b7280; display: block; }
pre { background: #111827; color: #f9fafb; padding: 1rem; overflow-x: auto; border-radius: 8px; white-space: pre-wrap; position: relative; }
code { font-family: ui-monospace, monospace; }
.pitfall { background: #fef3c7; padding: .75rem 1rem; border-radius: 8px; }
.output { background: #ecfdf5; padding: .75rem 1rem; border-radius: 8px; }
.sig { background: #eff6ff; padding: .75rem 1rem; border-radius: 8px; overflow-x: auto; }
.badge { font-size: .75rem; background: #e5e7eb; border-radius: 999px; padding: .15rem .55rem; white-space: nowrap; }
.chips { display: flex; flex-wrap: wrap; gap: .4rem; margin: .6rem 0; justify-content: center; }
.chips button, .chips a { border: 1px solid #d1d5db; background: #fff; border-radius: 999px; padding: .35rem .8rem; font-size: .9rem; cursor: pointer; text-decoration: none; color: #111827; }
.chips button:hover, .chips a:hover { background: #f3f4f6; }
.mode { text-align: center; margin: .6rem 0; }
.mode label { margin: 0 .5rem; }
.answer { margin-top: 1.5rem; }
.copy-btn { float: right; font-size: .8rem; padding: .25rem .6rem; border-radius: 999px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
.pin-btn { font-size: .85rem; padding: .3rem .7rem; border-radius: 999px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
.grounded { background: #f0fdf4; border: 1px solid #bbf7d0; padding: .6rem .9rem; border-radius: 8px; font-size: .9rem; }
.history { margin-top: 1.5rem; border-top: 1px solid #e5e7eb; padding-top: 1rem; }
.history-item { margin-bottom: 1.25rem; }
@media (max-width: 560px) { body { padding: .75rem; } h1 { font-size: 1.5rem; } }
</style></head>' . "\n";
}

/** Command chips + mode toggle + search form, with the suggestion dropdown. */
function php_agent_ui_search_form(PhpAgent $agent, string $query, string $action, bool $showMode, string $mode): void
{
    echo '<div class="chips">';
    foreach ($agent->commands()->all() as $command) {
        echo '<form method="get" action="' . php_agent_e($action) . '" style="display:inline"><input type="hidden" name="q" value="' . php_agent_e((string) $command['name'] . ' ') . '">';
        if ($showMode) {
            echo '<input type="hidden" name="mode" value="' . php_agent_e($mode) . '">';
        }
        echo '<button type="submit" title="' . php_agent_e((string) ($command['description'] ?? '')) . '">' . php_agent_e((string) $command['name']) . '</button></form>';
    }
    echo '</div>';
    // One form holds the mode toggle and the search box (no nested forms).
    echo '<form method="get" action="' . php_agent_e($action) . '" id="ask-form" autocomplete="off">';
    if ($showMode) {
        echo '<div class="mode">';
        echo '<label><input type="radio" name="mode" value="local"' . ($mode === 'local' ? ' checked' : '') . '> Local (verified, no key)</label>';
        echo '<label><input type="radio" name="mode" value="gemini"' . ($mode === 'gemini' ? ' checked' : '') . '> Gemini AI (your key)</label>';
        echo '</div>';
    }
    echo '<div class="search-wrap">';
    echo '<input type="text" name="q" id="ask-box" value="' . php_agent_e($query) . '" placeholder="Ask anything in PHP, e.g. array ko sort kaise kare" aria-label="Ask a PHP question">';
    echo '<div class="suggest-box" id="suggest-box" role="listbox"></div>';
    echo '<button class="go" type="submit">Batao</button>';
    echo '</div></form>';
    echo '<div class="chips" style="justify-content:flex-start"><span style="font-size:.9rem;color:#6b7280">Recent:</span><span id="recent-chips"></span>';
    echo '<span style="font-size:.9rem;color:#6b7280;margin-left:.6rem">Pinned:</span><span id="pinned-chips"></span>';
    echo '<button type="button" id="clear-history" style="border:1px solid #d1d5db;background:#fff;border-radius:999px;padding:.25rem .7rem;cursor:pointer">Clear history</button></div>';
}

/** Vanilla JS: suggestions, keyboard nav, localStorage recent/pinned, copy. */
function php_agent_ui_scripts(): void
{
    echo <<<'JS'
<script>
(function () {
  var box = document.getElementById('ask-box');
  var panel = document.getElementById('suggest-box');
  var form = document.getElementById('ask-form');
  if (!box || !panel || !form) return;
  var items = [], active = -1, timer = null;

  function load(key) { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { return []; } }
  function save(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {} }

  function chip(text, target, isPin) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = (isPin ? '📌 ' : '') + text;
    b.style.cssText = 'border:1px solid #d1d5db;background:#fff;border-radius:999px;padding:.25rem .7rem;margin:.15rem;cursor:pointer;font-size:.85rem';
    b.addEventListener('click', function () { box.value = text; form.submit(); });
    target.appendChild(b);
  }
  function renderMemory() {
    var r = document.getElementById('recent-chips'), p = document.getElementById('pinned-chips');
    if (r) { r.innerHTML = ''; load('phpagent_recent').forEach(function (t) { chip(t, r, false); }); }
    if (p) { p.innerHTML = ''; load('phpagent_pinned').forEach(function (t) { chip(t, p, true); }); }
  }
  function remember(q) {
    if (!q) return;
    var recent = load('phpagent_recent').filter(function (t) { return t !== q; });
    recent.unshift(q);
    save('phpagent_recent', recent.slice(0, 8));
  }
  form.addEventListener('submit', function () { remember(box.value.trim()); });
  var clearBtn = document.getElementById('clear-history');
  if (clearBtn) { clearBtn.addEventListener('click', function () { save('phpagent_recent', []); save('phpagent_pinned', []); renderMemory(); }); }
  document.querySelectorAll('[data-pin]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var q = btn.getAttribute('data-pin') || '';
      var pinned = load('phpagent_pinned').filter(function (t) { return t !== q; });
      pinned.unshift(q);
      save('phpagent_pinned', pinned.slice(0, 8));
      renderMemory();
      btn.textContent = 'Pinned ✓';
    });
  });
  document.querySelectorAll('.copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pre = btn.parentElement ? btn.parentElement.querySelector('pre, code') : null;
      var text = pre ? pre.innerText : '';
      function done() { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); }
      else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done(); }
    });
  });
  renderMemory();

  function closePanel() { panel.style.display = 'none'; items = []; active = -1; }
  function render(items) {
    panel.innerHTML = '';
    items.forEach(function (it, i) {
      var div = document.createElement('div');
      div.className = 'suggest-item' + (i === active ? ' active' : '');
      div.setAttribute('role', 'option');
      var title = document.createElement('span'); title.textContent = it.title;
      var badge = document.createElement('span'); badge.className = 'badge'; badge.textContent = it.label;
      var kind = document.createElement('span'); kind.className = 'kind'; kind.textContent = it.kind;
      div.appendChild(badge); div.appendChild(title); div.appendChild(kind);
      div.addEventListener('mousedown', function (e) { e.preventDefault(); box.value = it.query; form.submit(); });
      panel.appendChild(div);
    });
    panel.style.display = items.length ? 'block' : 'none';
  }
  box.addEventListener('input', function () {
    var q = box.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { closePanel(); return; }
    timer = setTimeout(function () {
      fetch('suggest.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) { items = data.suggestions || []; active = -1; render(items); })
        .catch(function () { closePanel(); });
    }, 120);
  });
  box.addEventListener('keydown', function (e) {
    if (panel.style.display !== 'block' || !items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); render(items); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(items); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); box.value = items[active].query; form.submit(); }
    else if (e.key === 'Escape') { closePanel(); }
  });
  document.addEventListener('click', function (e) { if (!panel.contains(e.target) && e.target !== box) closePanel(); });
})();
</script>
JS;
}

/** Render an answer result as HTML. $pinQuery is the text the Pin button saves. */
function php_agent_ui_render_result(PhpAgent $agent, array $result, string $pinQuery = ''): void
{
    $status = (string) ($result['status'] ?? '');
    if ($status === 'help') {
        echo '<p>' . php_agent_e((string) ($result['help'] ?? '')) . '</p>';
        return;
    }
    if ($status === 'topics') {
        echo '<h2>Categories</h2><ul>';
        foreach (($result['categories'] ?? []) as $category) {
            echo '<li>' . php_agent_e((string) $category) . ' (' . count($agent->byCategory((string) $category)) . ')</li>';
        }
        echo '</ul>';
        return;
    }
    if ($status === 'search' || $status === 'category') {
        $results = $result['results'] ?? [];
        echo '<h2>Results</h2>';
        if ($results === []) {
            echo '<p>No matches. Try different words, or ask about one of the listed categories.</p>';
        } else {
            echo '<ul>';
            foreach ($results as $item) {
                echo '<li><a href="?q=' . urlencode((string) $item['title']) . '">' . php_agent_e((string) $item['title']) . '</a></li>';
            }
            echo '</ul>';
        }
        return;
    }
    if ($status === 'function') {
        $fn = $result['function'] ?? null;
        if ($fn === null) {
            echo '<p><strong>This is not a built-in function in this index.</strong></p>';
            if (!empty($result['closest'])) {
                echo '<p>Did you mean?</p><ul>';
                foreach ($result['closest'] as $name) {
                    echo '<li><a href="?q=' . urlencode('/fn ' . $name) . '">' . php_agent_e($name) . '()</a></li>';
                }
                echo '</ul>';
            }
            return;
        }
        php_agent_ui_render_function($fn);
        return;
    }
    if ($status === 'command') {
        if (!empty($result['error'])) {
            echo '<p><strong>' . php_agent_e((string) $result['error']) . '</strong></p>';
            return;
        }
        if (!empty($result['function'])) {
            php_agent_ui_render_function($result['function']);
        }
        if (!empty($result['entry'])) {
            php_agent_ui_render_entry($agent, $result['entry'], $pinQuery);
        } elseif (!empty($result['text'])) {
            echo '<div class="answer"><pre>' . php_agent_e((string) $result['text']) . '</pre></div>';
        }
        if (!empty($result['grounded'])) {
            echo '<p class="grounded">Grounded in local data: ';
            $titles = [];
            foreach ($result['grounded'] as $g) {
                $titles[] = php_agent_e((string) $g['title']);
            }
            echo implode(' | ', $titles) . '</p>';
        }
        return;
    }
    if ($status === 'gemini') {
        if (!empty($result['error'])) {
            echo '<p><strong>' . php_agent_e((string) $result['error']) . '</strong></p>';
            return;
        }
        if (!empty($result['grounded'])) {
            echo '<p class="grounded"><span class="badge">Local data</span> Grounded in: ';
            $titles = [];
            foreach ($result['grounded'] as $g) {
                $titles[] = php_agent_e((string) $g['title']);
            }
            echo implode(' | ', $titles) . '</p>';
        }
        php_agent_ui_render_markdown_text((string) ($result['text'] ?? ''));
        return;
    }
    $entry = $result['entry'] ?? null;
    if ($entry === null) {
        echo '<p><strong>This exact topic is not in the knowledge base yet.</strong> No answer was invented.</p>';
        if (!empty($result['suggestions'])) {
            echo '<p>Did you mean?</p><ul>';
            foreach ($result['suggestions'] as $s) {
                echo '<li><a href="?q=' . urlencode((string) $s['title']) . '">' . php_agent_e((string) $s['title']) . '</a></li>';
            }
            echo '</ul>';
        }
        return;
    }
    php_agent_ui_render_entry($agent, $entry, $pinQuery, (int) ($result['score'] ?? 0));
}

/** Render a curated/construct entry: title, confidence, answer, code, related, signature card. */
function php_agent_ui_render_entry(PhpAgent $agent, array $entry, string $pinQuery = '', int $score = 0): void
{
    $label = php_agent_confidence_label($score);
    echo '<div class="answer"><h2>' . php_agent_e((string) $entry['title']) . '</h2>';
    echo '<p><span class="badge">' . php_agent_e((string) ($entry['category'] ?? 'General')) . '</span> ';
    if ($label !== '') {
        echo '<span class="badge">' . php_agent_e($label) . ' match</span> ';
    }
    if ($pinQuery !== '') {
        echo '<button type="button" class="pin-btn" data-pin="' . php_agent_e($pinQuery) . '">📌 Pin this topic</button>';
    }
    echo '</p>';
    echo '<p>' . php_agent_e((string) $entry['answer']) . '</p>';
    echo '<div><button type="button" class="copy-btn">Copy</button><pre><code>' . php_agent_e((string) $entry['code']) . '</code></pre></div>';
    if (array_key_exists('output', $entry) && $entry['output'] !== null && $entry['output'] !== '') {
        echo '<p class="output"><strong>Expected output:</strong><br><span style="white-space: pre-wrap">' . php_agent_e((string) $entry['output']) . '</span></p>';
    }
    if (!empty($entry['pitfall'])) {
        echo '<p class="pitfall"><strong>Common mistake:</strong> ' . php_agent_e((string) $entry['pitfall']) . '</p>';
    }
    // Function signature card when the entry is about a built-in function.
    foreach ((array) ($entry['functions'] ?? []) as $fnName) {
        $fn = $agent->index()->find((string) $fnName);
        if ($fn !== null) {
            echo '<p class="sig"><strong>Function:</strong> <code>' . php_agent_e((string) $fn['signature']) . '</code><br><small>Extension: ' . php_agent_e((string) $fn['extension']) . ' &middot; <a href="' . php_agent_e((string) $fn['url']) . '">php.net docs</a></small></p>';
            break;
        }
    }
    if (!empty($entry['related']) && is_array($entry['related'])) {
        $related = php_agent_by_ids($agent->all(), $entry['related']);
        if ($related !== []) {
            echo '<p><strong>Related:</strong> ';
            $links = [];
            foreach ($related as $item) {
                $links[] = '<a href="?q=' . urlencode((string) $item['title']) . '">' . php_agent_e((string) $item['title']) . '</a>';
            }
            echo implode(' | ', $links) . '</p>';
        }
    }
    echo '</div>';
}

/** Render one function-index entry. */
function php_agent_ui_render_function(array $fn): void
{
    echo '<div class="answer"><h2>' . php_agent_e((string) $fn['name']) . '()</h2>';
    echo '<p class="sig"><code>' . php_agent_e((string) $fn['signature']) . '</code><br><small>Extension: ' . php_agent_e((string) $fn['extension']);
    if (!empty($fn['is_deprecated'])) {
        echo ' &middot; <strong>Deprecated</strong>';
    }
    echo ' &middot; <a href="' . php_agent_e((string) $fn['url']) . '">php.net docs</a></small></p>';
    if (!empty($fn['example_entry'])) {
        echo '<p>Verified example: curated entry <strong>' . php_agent_e((string) $fn['example_entry']) . '</strong>. Ask about it for the full answer.</p>';
    } else {
        echo '<p>No verified example in the curated knowledge base for this function yet. The signature above is from PHP reflection; the description lives on the php.net page.</p>';
    }
    echo '</div>';
}

/** Render model text: escaped, with ``` fences shown as code blocks. */
function php_agent_ui_render_markdown_text(string $text): void
{
    $parts = preg_split('/```/', $text);
    echo '<div class="answer">';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            $code = ltrim($part, "a-zA-Z0-9_\n");
            echo '<div><button type="button" class="copy-btn">Copy</button><pre><code>' . php_agent_e($code) . '</code></pre></div>';
        } else {
            $escaped = php_agent_e($part);
            echo '<p style="white-space: pre-wrap">' . $escaped . '</p>';
        }
    }
    echo '</div>';
}
