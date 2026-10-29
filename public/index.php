<?php
// PHP Agent - web entry point. Run: php -S localhost:8000 -t public
// No JavaScript, no external assets, no API calls, no CDN.
// Form POST only. All output is escaped with htmlspecialchars.

declare(strict_types=1);

require __DIR__ . '/../src/PhpAgent.php';

$agent = PhpAgent::create(__DIR__ . '/../data');
$query = trim((string) ($_POST['q'] ?? ''));
$result = null;
if ($query !== '') {
    $result = $agent->answer($query);
}

/** Escape helper for every dynamic value printed into HTML. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
pre { background: #111827; color: #f9fafb; padding: 1rem; overflow-x: auto; border-radius: 8px; white-space: pre-wrap; }
input[type=text] { width: 68%; padding: .6rem; font-size: 1rem; }
button { padding: .6rem 1rem; font-size: 1rem; }
.pitfall { background: #fef3c7; padding: .75rem 1rem; border-radius: 8px; }
.output { background: #ecfdf5; padding: .75rem 1rem; border-radius: 8px; }
</style>
</head>
<body>
<h1>PHP Agent</h1>
<p>Offline PHP expert. No API, no account, no network. <?php echo count($agent->all()); ?> pre-decided topics.</p>
<form method="post" action="">
  <input type="text" name="q" value="<?php echo e($query); ?>" placeholder="e.g. array ko sort kaise kare" autofocus>
  <button type="submit">Batao</button>
</form>
<form method="post" action="">
  <input type="hidden" name="q" value="topics">
  <button type="submit">Show all categories</button>
</form>
<?php if ($result !== null): ?>
  <?php if ($result['status'] === 'topics'): ?>
    <h2>Categories</h2>
    <ul>
    <?php foreach (($result['categories'] ?? []) as $category): ?>
      <li><?php echo e($category); ?> (<?php echo count($agent->byCategory($category)); ?>)</li>
    <?php endforeach; ?>
    </ul>
  <?php elseif ($result['status'] === 'search' || $result['status'] === 'category'): ?>
    <h2>Results</h2>
    <?php if (($result['results'] ?? []) === []): ?>
      <p>No matches. Try different words, or ask about one of the listed categories.</p>
    <?php else: ?>
      <ul>
      <?php foreach (($result['results'] ?? []) as $item): ?>
        <li>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="q" value="<?php echo e((string) $item['title']); ?>">
            <button type="submit"><?php echo e((string) $item['title']); ?></button>
          </form>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php elseif ($result['entry'] === null): ?>
    <p><strong>This exact topic is not in the knowledge base yet.</strong> No answer was invented.</p>
    <?php if (($result['suggestions'] ?? []) !== []): ?>
      <p>Closest topics:</p>
      <ul>
      <?php foreach ($result['suggestions'] as $s): ?>
        <li>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="q" value="<?php echo e((string) $s['title']); ?>">
            <button type="submit"><?php echo e((string) $s['title']); ?></button>
          </form>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php else: $e = $result['entry']; ?>
    <h2><?php echo e((string) $e['title']); ?></h2>
    <p><small>Category: <?php echo e((string) ($e['category'] ?? 'General')); ?></small></p>
    <p><?php echo e((string) $e['answer']); ?></p>
    <pre><?php echo e((string) $e['code']); ?></pre>
    <?php if (array_key_exists('output', $e) && $e['output'] !== null && $e['output'] !== ''): ?>
      <p class="output"><strong>Expected output:</strong><br><span style="white-space: pre-wrap"><?php echo e((string) $e['output']); ?></span></p>
    <?php endif; ?>
    <?php if (!empty($e['pitfall'])): ?>
      <p class="pitfall"><strong>Common mistake:</strong> <?php echo e((string) $e['pitfall']); ?></p>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<p><small>Categories: <?php echo e(implode(', ', $agent->categories())); ?></small></p>
</body>
</html>
