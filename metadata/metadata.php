<?php
require_once __DIR__ . '/metadata_sources.php';

header('Content-Type: application/json');
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($query === '') {
    echo json_encode([]);
    exit;
}

$results = [];
$functions = get_defined_functions();
foreach ($functions['user'] as $fn) {
    if (strpos($fn, 'search_') !== 0) {
        continue;
    }
    try {
        $sourceResults = call_user_func($fn, $query);
        if (!is_array($sourceResults)) {
            $sourceResults = [];
        }
    } catch (Throwable $e) {
        $sourceResults = [];
    }
    $source = substr($fn, strlen('search_'));
    foreach ($sourceResults as &$item) {
        if (!isset($item['source'])) {
            $item['source'] = $source;
        }
    }
    unset($item);
    $results = array_merge($results, $sourceResults);
}

echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
