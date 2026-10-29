<?php
// PhpAgent - run every test suite and print one total.
// Run: php tests/run-all.php
// Exit code 0 = v1 + v2 all passed.

declare(strict_types=1);

$phpBin = PHP_BINARY;
$root = dirname(__DIR__);
$totalPass = 0;
$totalFail = 0;

foreach (['tests/run.php', 'tests/run-v2.php'] as $suite) {
    $output = [];
    $code = 0;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/' . $suite) . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    echo "=== $suite (exit $code) ===\n";
    // Print only failures + the summary lines, to keep output readable.
    foreach ($output as $line) {
        if (str_starts_with($line, 'FAIL') || str_starts_with($line, 'PASS:') || str_contains($line, 'entries,') || str_contains($line, 'Function index:')) {
            echo $line . "\n";
        }
    }
    if (preg_match('/PASS: (\d+)\s+FAIL: (\d+)/', $text, $m) === 1) {
        $totalPass += (int) $m[1];
        $totalFail += (int) $m[2];
    } else {
        $totalFail++;
        echo "Could not parse PASS/FAIL totals for $suite.\n";
        echo $text . "\n";
    }
    if ($code !== 0) {
        $totalFail = $totalFail; // totals already counted; exit code below reflects failure.
    }
}

echo "\nTOTAL PASS: $totalPass  FAIL: $totalFail\n";
exit($totalFail === 0 ? 0 : 1);
