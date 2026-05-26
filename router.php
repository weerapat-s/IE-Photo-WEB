<?php
// router.php — PHP built-in server router for Railway
// Handles static files, PHP pages, directory indexes, and root "/"

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;          // /app
$file = $root . $uri;     // e.g. /app/guest/studio_booking.php

// 1. Existing file (not a directory) → let PHP serve it natively
//    Covers: CSS/JS/images AND all .php files under sub-directories
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// 2. Root "/" → run index.php which redirects based on session
$index = $root . '/index.php';
if (file_exists($index)) {
    chdir($root);
    $_SERVER['SCRIPT_FILENAME'] = $index;
    $_SERVER['SCRIPT_NAME']     = '/index.php';
    $_SERVER['PHP_SELF']        = '/index.php';
    require $index;
    return true;
}

// 3. Absolute fallback — should never reach here
http_response_code(404);
echo '<h1>404 Not Found</h1>';
return true;
