<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$token = preg_replace('/[^a-z0-9]/i', '', trim($_POST['token'] ?? ''));
if ($token === '') {
    echo json_encode(['error' => 'No token']);
    exit;
}

$flag = sys_get_temp_dir() . '/calibre_nilla_stop_' . $token;
file_put_contents($flag, '1');
echo json_encode(['ok' => true]);
