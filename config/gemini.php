<?php
// PhpAgent v2 - Gemini settings. The model name is a default you can
// change: it was not verified live against Google's API (no key was used
// while building this project). If Gemini returns HTTP 404, set "model"
// to a model available for your key. The key itself is NEVER stored here.

return [
    // Default model. Change this if your key/project lists a different one.
    'model' => 'gemini-2.5-flash',
    'temperature' => 0.2,
    'max_output_tokens' => 1024,
    'timeout' => 30,
];
