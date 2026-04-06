<?php
require_once '../db.php';
requireLogin();

$data  = json_decode(file_get_contents('php://input'), true) ?: [];
$html  = $data['html']  ?? '';
$title = $data['title'] ?? 'note';

// Safe filename
$filename = preg_replace('/[^\w\s\-]/', '', $title) ?: 'note';
$filename = trim($filename);

// Write full HTML document to a temp file
$tmpHtml = tempnam(sys_get_temp_dir(), 'wpnote_') . '.html';
$tmpDocx = sys_get_temp_dir() . '/' . uniqid('wpnote_') . '.docx';

$fullHtml = '<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>' . htmlspecialchars($title) . '</title>
<style>body{font-family:Georgia,serif;font-size:12pt;line-height:1.6;max-width:800px;margin:0 auto;}</style>
</head><body>' . $html . '</body></html>';

file_put_contents($tmpHtml, $fullHtml);

$cmd = sprintf(
    'pandoc %s -f html -t docx --metadata title=%s -o %s 2>&1',
    escapeshellarg($tmpHtml),
    escapeshellarg($title),
    escapeshellarg($tmpDocx)
);
exec($cmd, $output, $exitCode);

unlink($tmpHtml);

if ($exitCode !== 0 || !file_exists($tmpDocx)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Export failed: ' . implode(' ', $output)]);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '.docx"');
header('Content-Length: ' . filesize($tmpDocx));
readfile($tmpDocx);
unlink($tmpDocx);
