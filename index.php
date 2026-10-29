<?php
// PHP Agent - web entry point. Run: php -S localhost:8000
// No JavaScript, no external assets, no API calls. Pure PHP + HTML.

declare(strict_types=1);

require __DIR__ . '/src/lib.php';

$entries = php_agent_load(__DIR__ . '/data');
$query = trim((string) ($_GET['q'] ?? ''));
$result = null;
if ($query !== '') {
    $result = php_agent_find($entries, $query);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Agent - offline PHP expert</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
pre { background: #111827; color: #f9fafb; padding: 1rem; overflow-x: auto; border-radius: 8px; }
input[type=text] { width: 70%; padding: .6rem; font-size: 1rem; }
button { padding: .6rem 1rem; font-size: 1rem; }
.pitfall { background: #fef3c7; padding: .75rem 1rem; border-radius: 8px; }
</style>
</head>
<body>
<h1>PHP Agent</h1>
<p>Offline PHP expert. No API, no account. <?php echo count($entries); ?> pre-decided topics.</p>
<form method="get" action="">
  <input type="text" name="q" value="<?php echo htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. how do I sort an array?" autofocus>
  <button type="submit">Ask</button>
</form>
<?php if ($result !== null): ?>
  <?php if ($result['entry'] === null): ?>
    <p><strong>This exact topic is not in the knowledge base yet.</strong> No guessed code was given.</p>
    <?php if ($result['suggestions'] !== []): ?>
      <p>Closest topics:</p>
      <ul>
      <?php foreach ($result['suggestions'] as $s): ?>
        <li><a href="?q=<?php echo urlencode($s['title']); ?>"><?php echo htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8'); ?></a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php else: $e = $result['entry']; ?>
    <h2><?php echo htmlspecialchars($e['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <p><?php echo htmlspecialchars($e['answer'], ENT_QUOTES, 'UTF-8'); ?></p>
    <pre><?php echo htmlspecialchars($e['code'], ENT_QUOTES, 'UTF-8'); ?></pre>
    <?php if (!empty($e['pitfall'])): ?>
      <p class="pitfall"><strong>Watch out:</strong> <?php echo htmlspecialchars($e['pitfall'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php $related = !empty($e['related']) ? php_agent_by_ids($entries, $e['related']) : []; ?>
    <?php if ($related !== []): ?>
      <p>Related:
      <?php foreach ($related as $i => $r): ?><?php if ($i > 0) echo ' | '; ?><a href="?q=<?php echo urlencode($r['title']); ?>"><?php echo htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8'); ?></a><?php endforeach; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<p><small>Categories: <?php echo htmlspecialchars(implode(', ', php_agent_categories($entries)), ENT_QUOTES, 'UTF-8'); ?></small></p>
</body>
</html>
