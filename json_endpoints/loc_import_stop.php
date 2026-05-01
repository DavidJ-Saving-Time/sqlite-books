<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$stopFile = sys_get_temp_dir() . '/loc_import_stop_' . md5(currentUser());
file_put_contents($stopFile, '1');
echo json_encode(['ok' => true]);
