# PHP Agent

An offline PHP expert, written in PHP itself. No API key, no network calls,
no database, no Composer packages. Every answer is pre-decided text stored
in JSON files: ask a question in English or Hinglish, get a short
explanation and a complete, runnable PHP code example.

## Honest limits

Read this before assuming what the agent can do:

- **Pre-decided knowledge base only.** The agent answers from exactly the
  **115 curated entries** listed below. It does not understand arbitrary new
  PHP questions beyond those entries, and it never invents an answer. If a
  question scores below the match threshold, it says the topic is not in the
  knowledge base yet and shows the 3 closest topics.
- **It does not run your code.** Code examples are teaching snippets. The
  test suite executes the runnable ones to prove they work, but the agent
  itself never executes anything you type.
- **It does not call any API.** There is no LLM, no HTTP request, and no
  account involved. The matching engine is local token scoring, phrase
  matching, and a fuzzy fallback.
- **"All" means the entries below.** PHP has well over 1,000 built-in
  functions plus large frameworks. This project covers core PHP 8 and the
  patterns beginners ask about most. Framework entries (Laravel-style,
  WordPress) are plain-PHP explanations and clearly marked framework-only
  where they need a framework installed.
- **Snippets need adapting.** Database credentials, file paths, and
  validation rules in the examples are placeholders for a real project.

## Requirements

PHP 8.1 or newer, command line. No extensions beyond the PHP standard
distribution are required (the matcher works with or without `mbstring`).
Check your version first:

```bash
php -v
```

## Run it

```bash
# One question (CLI). `ask` is optional.
php agent.php ask "how do I connect to MySQL with PDO?"
php agent.php "array ko sort kaise kare"

# Interactive chat loop (type exit to quit)
php agent.php

# Built-in commands
php agent.php help
php agent.php topics
php agent.php search password hash
php agent.php category:Security

# Web UI (one page, form POST only, no JavaScript, no CDN)
php -S localhost:8000 -t public
# then open http://localhost:8000 in a browser

# Demo: 8 sample questions and answers
php demo.php

# Tests (plain PHP runner, no PHPUnit)
php tests/run.php
```

## Use it from your own PHP code

```php
<?php
require __DIR__ . '/src/PhpAgent.php';

$agent = PhpAgent::create(); // defaults to data/ next to src/
$result = $agent->answer('how do I sort an array?');

if ($result['status'] === 'found') {
    echo $result['entry']['title'] . "\n";
    echo $result['entry']['answer'] . "\n";
    echo $result['entry']['code'] . "\n";
} else {
    // 'not_found': $result['suggestions'] holds the 3 closest entries.
    echo "Not in the knowledge base yet.\n";
}

// Listing helpers:
$agent->categories();          // string[]
$agent->byCategory('Security'); // entries in one category
$agent->search('password hash'); // up to 5 ranked entries
$agent->all();                 // every entry
```

`PhpAgent::answer()` returns an array with `status` (`found`, `not_found`,
`help`, `topics`, `search`, `category`), `entry` (the best entry or `null`),
`score`, `suggestions`, and `query`.

## How matching works

1. The question is normalised (lowercased, punctuation stripped) and split
   into tokens. Hinglish synonyms are expanded (`nikalo` → `get`,
   `banao` → `create`, `jodo` → `add`, and others), so romanised questions
   match English keywords.
2. Every entry is scored: an exact PHP function name in the question scores
   highest, then whole-word keyword phrases (English and Hinglish), then
   title words (including shared stems such as `prepare`/`prepared`), then a
   fuzzy fallback for near-misses on function names and titles.
3. A match needs a score of at least 25. Below that, the agent reports the
   topic as missing instead of guessing.
4. Ties break by entry id, so the same input always returns the same answer.

## Knowledge base inventory (115 entries, 21 categories)

| Category | Entries |
|---|---|
| Basics | 15 |
| OOP | 9 |
| Arrays | 8 |
| PHP 8 Features | 8 |
| Strings | 7 |
| Security | 6 |
| PDO & Databases | 6 |
| Files | 5 |
| HTTP, JSON & APIs | 5 |
| Laravel-Style Patterns | 5 |
| Performance, CLI & Deployment | 5 |
| Common Errors | 5 |
| Errors & Exceptions | 4 |
| Sessions & Cookies | 4 |
| Forms & Validation | 4 |
| WordPress | 4 |
| Testing & Debugging | 4 |
| Functions | 3 |
| Regular Expressions | 3 |
| Composer & Autoloading | 3 |
| MySQLi | 2 |

Each entry has an `id`, `title`, `category`, `keywords` (including
Hinglish/romanised phrasing), `functions`, a short plain-English
`answer`, a complete `code` example, the expected `output` where the
example produces one, a `runnable` flag, a common-mistake `pitfall`, and
`related` entry ids. Entries marked `runnable: false` need a database,
a web server, or a framework, and are syntax-checked only.

Topics include: variables and types, operators, strings, arrays, control
flow, functions and closures, classes, traits, interfaces, enums,
attributes, errors and exceptions, dates, files, sessions and cookies,
forms and validation, superglobals, XSS/SQL injection/CSRF prevention,
`password_hash`, prepared statements, PDO and MySQLi, cURL, JSON/XML,
regex, Composer/autoloading, PHP 8 features (named arguments, `match`,
nullsafe operator, union types, fibers, readonly properties), Laravel-style
routing/MVC/middleware patterns in plain PDO, WordPress hooks, REST APIs,
PHPUnit concepts, debugging, performance, deployment, CLI scripts, and
fixes for the most common PHP errors.

## Example questions

```text
how do I connect to MySQL with PDO?
array ko sort kaise kare
array me value kaise nikale
how do I use str_replace in PHP?
how to use password_hash and password_verify
session kaise banaye
how do I prevent XSS in PHP?
csrf token kaise banaye
match kaise use kare
enum kaise banaye
```

## Project structure

```text
agent.php          CLI: one-shot questions and interactive loop
demo.php           8 sample Q&A pairs from the knowledge base
public/index.php   Web UI (php -S localhost:8000 -t public)
index.php          Alternate web UI at the project root (GET form)
src/lib.php        Loading, normalising, scoring, matching
src/PhpAgent.php   Library API: PhpAgent::answer(string): array
data/*.json        The knowledge base (21 files, 115 entries)
tests/run.php      Plain-PHP test runner (no dependencies)
```

## Tests

`php tests/run.php` asserts, with exact counts printed at the end:

- every JSON file parses and every entry has all required fields, unique
  ids, and related ids that exist;
- every entry's code passes `php -l`, and every runnable example executes
  in a subprocess without a fatal error and produces its declared output;
- the matcher returns the expected entry for 46 sample questions,
  including Hinglish ones;
- unknown questions return `not_found` with 3 suggestions, never an
  invented answer, and matching is deterministic;
- `help`, `topics`, `search`, and `category:` commands work, and the CLI
  one-shot mode works;
- no PHP file in the project makes a network call (the cURL entry exists
  only as knowledge-base example text in JSON, never as executed code).

Exit code 0 means every assertion passed.

## Contributing

Adding a topic needs no code change — add one JSON object to the right
file in `data/` (or a new `data/NN-name.json` file):

```json
{
  "id": "example-topic",
  "title": "Example topic",
  "category": "Basics",
  "keywords": ["example", "udahaaran kaise kare"],
  "functions": ["example_fn"],
  "answer": "Short explanation in simple words.",
  "code": "echo \"example\";\n",
  "output": "example",
  "runnable": true,
  "pitfall": "The one mistake beginners make here.",
  "related": ["hello-first-program"]
}
```

Rules for new entries:

- `id` is lowercase letters, digits, and hyphens, and must be unique.
- `code` must be correct PHP. If it runs standalone, set
  `"runnable": true` and set `output` to its exact output. If it needs a
  database, server, or framework, set `"runnable": false` and
  `"output": null` — it will be syntax-checked only.
- Include Hinglish/romanised keywords when beginners would ask that way.
- `related` ids must already exist.
- Run `php tests/run.php` before submitting; it must exit 0.

## License

MIT. See `LICENSE`.
