<?php
// PhpAgent v2 - Gemini key setup. Run: php setup-gemini.php
// The key is created by YOU at Google AI Studio, in your own browser:
//   https://aistudio.google.com/apikey  (free tier)
// This script never opens a browser and never signs in to Google. It only
// stores the key you paste, on this machine only, in config/key.php with
// mode 0600. The key is sent only to Google's Gemini API, and only when
// you ask a Gemini-mode question. Local mode works without any key.

declare(strict_types=1);

echo "PHP Agent - Gemini setup\n";
echo "========================\n\n";
echo "1. In your own browser, open Google AI Studio and create a free API key:\n";
echo "   https://aistudio.google.com/apikey\n\n";
echo "2. Paste the key below. It is stored ONLY on this machine, in\n";
echo "   config/key.php (mode 0600, gitignored, never committed).\n";
echo "   Or press Enter with no key to skip: set the GEMINI_API_KEY\n";
echo "   environment variable instead. Local mode works without a key.\n\n";
echo "Note: a key rejected by Google before (project denied access) may\n";
echo "need a fresh key from AI Studio. This script does not test the key.\n\n";

$line = function_exists('readline') ? readline('Paste your Gemini API key (hidden from this output, Enter to skip): ') : fgets(STDIN);
$key = is_string($line) ? trim($line) : '';
if ($key === '') {
    echo "\nSkipped. No key was stored. Set GEMINI_API_KEY or run this again later.\n";
    exit(0);
}

$configDir = __DIR__ . '/config';
if (!is_dir($configDir)) {
    mkdir($configDir, 0700, true);
}
$keyFile = $configDir . '/key.php';
$content = "<?php\n// PhpAgent Gemini API key. PRIVATE. Never commit, never share.\nreturn " . var_export($key, true) . ";\n";
file_put_contents($keyFile, $content);
chmod($keyFile, 0600);

$masked = substr($key, 0, 4) . '...' . substr($key, -4);
echo "\nStored. Key on file (masked): " . $masked . "\n";
echo "Test it with: php agent.php ai \"how do I sort an array in PHP?\"\n";
echo "Remove it any time by deleting config/key.php.\n";
