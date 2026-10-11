<?php
/**
 * 下载爬取结果压缩包：download.php?file=example.com.zip
 */

$file = basename((string)($_GET['file'] ?? ''));
// 只允许 .zip 且无路径穿越
if (!preg_match('/^[\w\-.]+\.zip$/i', $file)) {
    http_response_code(400);
    exit('文件名不合法');
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('压缩包不存在，请先完成爬取');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($file));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
