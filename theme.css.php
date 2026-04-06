<?php
/**
 * Per-user CSS endpoint.
 * Outputs @import for the chosen Bootswatch theme + :root CSS variable overrides
 * stored in the user's THEME_JSON preference.
 * Linked as a stylesheet on every page: <link rel="stylesheet" href="/theme.css.php">
 */
ob_start();
require_once __DIR__ . '/db.php';
ob_end_clean();

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: private, max-age=300');

$defaultBootswatch = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
$allowedHosts      = ['cdn.jsdelivr.net', 'bootswatch.com', 'stackpath.bootstrapcdn.com', 'maxcdn.bootstrapcdn.com'];

$user  = trim($_COOKIE['user'] ?? '');
$theme = ['bootswatch' => $defaultBootswatch, 'vars' => []];

if ($user !== '') {
    $json = getUserPreference($user, 'THEME_JSON', getPreference('THEME_JSON', ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $theme = $decoded;
        }
    }
}

// Validate Bootswatch URL — must be https from an allowed CDN
$bsw    = $theme['bootswatch'] ?? $defaultBootswatch;
$parsed = parse_url($bsw);
if (($parsed['scheme'] ?? '') !== 'https' || !in_array($parsed['host'] ?? '', $allowedHosts, true)) {
    $bsw = $defaultBootswatch;
}

$vars = is_array($theme['vars'] ?? null) ? $theme['vars'] : [];

echo "@import url(" . $bsw . ");\n";

// Separate out component-level overrides from root vars
$componentVars = ['--form-control-color', '--form-control-bg'];
$rootVars      = [];
$components    = [];

foreach ($vars as $prop => $val) {
    if (!preg_match('/^--[a-zA-Z][a-zA-Z0-9-]*$/', $prop)) continue;
    $val = preg_replace('/[;<>{}]/', '', (string)$val);
    $val = trim($val);
    if (in_array($prop, $componentVars, true)) {
        $components[$prop] = $val;
    } else {
        $rootVars[$prop] = $val;
    }
}

if ($rootVars) {
    echo "\n:root {\n";
    foreach ($rootVars as $prop => $val) {
        echo '    ' . $prop . ': ' . $val . ";\n";
    }
    echo "}\n";
}

if ($components) {
    echo "\n.form-control, .form-select, .form-check-input {\n";
    if (isset($components['--form-control-color'])) {
        echo '    color: ' . $components['--form-control-color'] . ";\n";
    }
    if (isset($components['--form-control-bg'])) {
        echo '    background-color: ' . $components['--form-control-bg'] . ";\n";
    }
    echo "}\n";
}
