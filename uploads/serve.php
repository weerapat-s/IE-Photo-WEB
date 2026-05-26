<?php
/**
 * uploads/serve.php
 * ──────────────────────────────────────────────────────────────────
 * CleverCloud filesystem is ephemeral — uploaded files get wiped on
 * every deploy because uploads/ is in .gitignore.
 *
 * This script fetches file bytes from MySQL (uploaded_files table)
 * so files remain accessible after deploys.
 *
 * Called via uploads/.htaccess when the physical file is not found:
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteRule ^(.+)$ serve.php?f=$1 [L,QSA]
 *
 * Direct URL pattern stays unchanged:
 *   /uploads/booking_forms/booking_xxx.jpg   → serve.php?f=booking_forms/booking_xxx.jpg
 *   /uploads/returns/return_xxx.jpg          → serve.php?f=returns/return_xxx.jpg
 *   /uploads/profiles/123_xxx.jpg            → serve.php?f=profiles/123_xxx.jpg
 */

require_once __DIR__ . '/../config/database.php';

// ── Sanitise requested filepath ──────────────────────────────────────────────
$raw = $_GET['f'] ?? '';
// Strip leading slash and prevent path traversal
$filepath = ltrim(str_replace(['..', "\0", '\\'], '', $raw), '/');

if ($filepath === '' || !preg_match('#^[a-zA-Z0-9_/.\-]+$#', $filepath)) {
    http_response_code(400);
    exit('Bad request');
}

// ── Try filesystem first (works on local dev / same-request upload) ──────────
$absPath = __DIR__ . '/' . $filepath;
if (is_file($absPath) && is_readable($absPath)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $absPath) ?: 'application/octet-stream';
    finfo_close($finfo);
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
}

// ── Fetch from MySQL ──────────────────────────────────────────────────────────
try {
    upload_ensure_table($pdo);
    $stmt = $pdo->prepare("SELECT mime_type, file_data FROM uploaded_files WHERE filepath = ?");
    $stmt->execute([$filepath]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit('Server error');
}

if (!$row || $row['file_data'] === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ไม่พบไฟล์');
}

header('Content-Type: ' . $row['mime_type']);
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . strlen($row['file_data']));
echo $row['file_data'];
