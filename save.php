<?php
/**
 * save.php — DailyFlow JSON database
 * ─────────────────────────────────────────────────────────────────
 * Place this file in the SAME folder as index.html and data.json.
 *
 *   GET  save.php?action=load   → returns contents of data.json
 *   POST save.php               → writes JSON body to data.json
 *                                 also keeps last 3 rolling backups
 *                                 in a backups/ sub-folder
 * ─────────────────────────────────────────────────────────────────
 */

/* ── CORS / origin check ───────────────────────────────────────── */
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$host   = isset($_SERVER['HTTP_HOST'])   ? $_SERVER['HTTP_HOST']   : '';

$allowed = [
    'http://localhost',
    'http://localhost:8080',
    'http://localhost:3000',
    'http://localhost:5500',
    'http://127.0.0.1',
    'http://127.0.0.1:5500',
    'http://127.0.0.1:8080',
    'https://' . $host,
    'http://'  . $host,
];

if ($origin && !in_array($origin, $allowed)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden origin']));
}

if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

/* Preflight */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── File paths ────────────────────────────────────────────────── */
$dataFile  = __DIR__ . '/data.json';
$backupDir = __DIR__ . '/backups';

/* ════════════════════════════════════════
   LOAD  (GET)
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists($dataFile)) {
        /* First run — return empty structure so the app bootstraps cleanly */
        echo json_encode([
            'tasks'   => [],
            'links'   => [],
            'notes'   => [],
            'updated' => null,
        ]);
        exit;
    }

    $raw = file_get_contents($dataFile);
    if ($raw === false) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => 'Cannot read data.json']));
    }
    echo $raw;
    exit;
}

/* ════════════════════════════════════════
   SAVE  (POST)
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    if (empty($body)) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Empty request body']));
    }

    /* Validate JSON */
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]));
    }

    /* ── Rolling backup: keep last 3 saves ── */
    if (file_exists($dataFile)) {
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        $ts     = date('Ymd_His');
        $backup = $backupDir . '/data_' . $ts . '.json';
        copy($dataFile, $backup);

        /* Delete oldest backups, keep 3 */
        $files = glob($backupDir . '/data_*.json');
        if ($files && count($files) > 3) {
            sort($files);
            foreach (array_slice($files, 0, count($files) - 3) as $f) unlink($f);
        }
    }

    /* ── Write new data ── */
    $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $result = file_put_contents($dataFile, $pretty, LOCK_EX);

    if ($result === false) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => 'Cannot write data.json — check file permissions (chmod 664 data.json)']));
    }

    echo json_encode([
        'ok'      => true,
        'bytes'   => $result,
        'updated' => date('c'),
    ]);
    exit;
}

/* ── Any other HTTP method ── */
http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
