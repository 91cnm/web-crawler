<?php
/**
 * ============================================================
 *  全能网页爬虫工具 PHP 版（由「网页爬虫pro.py」移植）
 *
 *  流程：前端输入网址 → POST 到本文件 → 爬取同站全部文件
 *        （HTML/CSS/JS 二次深层检索、同站过滤、自动防重命名）
 *        → 生成「所有下载文件列表.txt」「下载统计.txt」
 *        → 将第一级域名文件夹（如 example.com）打包为 .zip
 *        → 返回 JSON（zip 下载链接 + 两个 txt 内容）给前端
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

set_time_limit(0);
ignore_user_abort(true);

// ==================== 配置区 ====================
define('BASE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'sites'); // 保存根目录（域名文件夹的同级位置）
define('MAX_RETRIES', 3);          // 单个 URL 最大重试次数
define('REQUEST_TIMEOUT', 20);     // 单次请求超时（秒）
define('MAX_RUNTIME_SECONDS', 0);  // 最大运行时长（秒），0 = 不限
define('MAX_FILES', 0);            // 最大下载文件数，0 = 不限
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
// ================================================

// ---- 依赖扩展检查 ----
foreach (['curl' => '抓取网页', 'zip' => '打包压缩', 'mbstring' => '中文编码处理', 'dom' => '解析HTML'] as $ext => $desc) {
    if (!extension_loaded($ext)) {
        json_out(['success' => false, 'error' => "缺少 PHP 扩展：{$ext}（{$desc}）。请在 php.ini 中启用后重试。"]);
    }
}

// ==================== 基础工具函数 ====================

function json_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 清洗名称，保留中文、字母、数字、点、短横线 */
function sanitize_name(string $name): string
{
    $clean = preg_replace('/[^\w\x{4e00}-\x{9fa5}\.\-]/u', '_', $name);
    return $clean === null ? '_' : $clean;
}

function str_ends_with_safe(string $haystack, string $needle): bool
{
    $len = strlen($needle);
    return $len === 0 || substr($haystack, -$len) === $needle;
}

/** 规范化路径中的 . 和 .. 段 */
function normalize_path(string $path): string
{
    $segs = explode('/', $path);
    $out = [];
    foreach ($segs as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (count($out) > 0) array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return '/' . implode('/', $out);
}

function build_url(array $bp, string $path, string $query): string
{
    $scheme = $bp['scheme'] ?? 'http';
    $host = $bp['host'] ?? '';
    $port = isset($bp['port']) ? ':' . $bp['port'] : '';
    $u = $scheme . '://' . $host . $port . $path;
    if ($query !== '') $u .= '?' . $query;
    return $u;
}

/** 拼接相对 URL（等价 Python 的 urljoin） */
function url_join(string $base, string $rel): string
{
    $bp = parse_url($base);
    if (!$bp || !isset($bp['host'])) return $rel;

    if (strncmp($rel, '//', 2) === 0) { // 协议相对 //host/path
        return ($bp['scheme'] ?? 'http') . ':' . $rel;
    }
    if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $rel)) return $rel; // 绝对 URL

    $path = $rel;
    $query = '';
    $pos = strpos($rel, '?');
    if ($pos !== false) {
        $path = substr($rel, 0, $pos);
        $query = substr($rel, $pos + 1);
    }
    if ($path === '') { // 仅 ?query
        return build_url($bp, $bp['path'] ?? '/', $query);
    }
    if ($path[0] === '/') { // 根相对
        return build_url($bp, $path, $query);
    }
    // 相对路径：基于 base 的目录部分
    $basePath = $bp['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '', $basePath);
    if ($dir === '') $dir = '/';
    $path = normalize_path($dir . '/' . $path);
    return build_url($bp, $path, $query);
}

/** 归一化 URL：去 fragment、补协议、过滤非法协议；非法返回 null */
function normalize_url(string $url, string $base): ?string
{
    $url = trim($url);
    if ($url === '' || strncmp($url, '#', 1) === 0) return null;
    if (preg_match('#^(mailto:|tel:|javascript:|data:)#i', $url)) return null;
    if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
        $url = url_join($base, $url);
    }
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) $url = substr($url, 0, $hashPos);
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) return null;
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
    return $url;
}

/** 同站判断（含子域名） */
function same_site(string $url, string $domain): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $domain = strtolower($domain);
    $dLen = strlen($domain);
    $hLen = strlen($host);
    return $host === $domain
        || ($hLen > $dLen && substr($host, -$dLen - 1) === '.' . $domain)
        || ($dLen > $hLen && substr($domain, -$hLen - 1) === '.' . $host);
}

/** 返回（域名, 目录路径, 文件名, query） */
function parse_url_structure(string $url): array
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    $path = rtrim($parts['path'] ?? '', '/');
    $query = $parts['query'] ?? '';
    $fullName = basename($path);
    if ($fullName === '') $fullName = 'index';
    $lower = strtolower($fullName);
    if ($path === '' || in_array($lower, ['index', 'index.html', 'index.htm', 'index.php'], true)) {
        return [$host, '', 'index', $query]; // 主页固定为 index
    }
    // Windows 上 dirname() 可能返回反斜杠（如 '/' => '\'），统一转成正斜杠再裁剪
    $dirPath = str_replace('\\', '/', dirname($path));
    $dirPath = ltrim($dirPath, '/');
    if ($dirPath === '.') $dirPath = '';
    return [$host, $dirPath, $fullName, $query];
}

/** 从 URL / Content-Type 推断扩展名 */
function get_file_extension_from_url(string $url, string $contentType): string
{
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (strpos($path, '.') !== false) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && strlen($ext) <= 10) return '.' . $ext;
    }
    $main = strtolower(trim(explode(';', $contentType)[0]));
    $map = [
        'text/html' => '.html', 'text/css' => '.css',
        'text/javascript' => '.js', 'application/javascript' => '.js',
        'application/json' => '.json', 'application/xml' => '.xml',
        'image/jpeg' => '.jpg', 'image/jpg' => '.jpg', 'image/png' => '.png',
        'image/gif' => '.gif', 'image/svg+xml' => '.svg', 'image/webp' => '.webp',
        'image/bmp' => '.bmp', 'image/x-icon' => '.ico',
        'image/vnd.microsoft.icon' => '.ico', 'application/pdf' => '.pdf',
        'application/zip' => '.zip', 'application/gzip' => '.gz',
        'application/x-tar' => '.tar', 'application/msword' => '.doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'application/vnd.ms-excel' => '.xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
        'application/vnd.ms-powerpoint' => '.ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx',
        'text/plain' => '.txt', 'text/csv' => '.csv',
        'audio/mpeg' => '.mp3', 'audio/wav' => '.wav',
        'video/mp4' => '.mp4', 'video/webm' => '.webm',
        'font/woff' => '.woff', 'font/woff2' => '.woff2',
        'font/ttf' => '.ttf', 'font/otf' => '.otf',
        'application/font-woff' => '.woff', 'application/font-woff2' => '.woff2',
        'application/x-font-ttf' => '.ttf', 'application/x-font-otf' => '.otf',
    ];
    return $map[$main] ?? '';
}

/** 跳过动态脚本与登录类地址 */
function should_download_file(string $url): bool
{
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    foreach (['.php', '.asp', '.aspx', '.jsp', '.cgi', '.pl', '.py', '.rb'] as $ext) {
        if (str_ends_with_safe($path, $ext)) return false;
    }
    $lower = strtolower($url);
    foreach (['logout', 'signin', 'signup', 'register'] as $p) {
        if (strpos($lower, $p) !== false) return false;
    }
    return true;
}

/** 文本类 MIME 判定（按文本保存，其余一律二进制） */
function is_text_type(string $contentType): bool
{
    $main = strtolower(trim(explode(';', $contentType)[0]));
    $textual = [
        'text/html', 'text/css', 'text/plain', 'text/xml', 'text/csv',
        'application/json', 'application/xml', 'application/javascript',
        'text/javascript', 'image/svg+xml', 'application/xhtml+xml',
        'application/rss+xml', 'application/atom+xml',
    ];
    if (in_array($main, $textual, true)) return true;
    if (strncmp($main, 'text/', 5) === 0) return true;
    if (strpos($main, 'javascript') !== false || strpos($main, 'json') !== false || strpos($main, 'xml') !== false) return true;
    return false;
}

/** 统一转成 UTF-8 保存（兼容 GBK/BIG-5 等网页） */
function to_utf8(string $body): string
{
    $enc = mb_detect_encoding($body, ['UTF-8', 'GB18030', 'GBK', 'BIG-5', 'ISO-8859-1', 'Windows-1252'], true);
    if ($enc && strtoupper($enc) !== 'UTF-8') {
        $converted = @mb_convert_encoding($body, 'UTF-8', $enc);
        if ($converted !== false) return $converted;
    }
    if (mb_check_encoding($body, 'UTF-8')) return $body;
    $converted = @mb_convert_encoding($body, 'UTF-8', 'GB18030');
    return $converted !== false ? $converted : $body;
}

/** 下载 URL（带重试），成功返回 [内容, Content-Type]，最终失败返回 null */
function http_get(string $url): ?array
{
    for ($i = 0; $i < MAX_RETRIES; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_ACCEPT_ENCODING => '', // 自动解压 gzip/deflate
            CURLOPT_HTTPHEADER => ['Accept: */*'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body !== false && $status >= 200 && $status < 400) {
            return [$body, $contentType];
        }
        if ($i < MAX_RETRIES - 1) usleep(300000); // 0.3 秒后退重试
    }
    return null;
}

// ==================== 链接提取（含二次深层检索） ====================

/** CSS 中的 url() 和 @import */
function extract_links_from_css(string $content, string $baseUrl, string $domain): array
{
    $links = [];
    preg_match_all('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $content, $m);
    foreach ($m[1] as $u) $links[] = $u;
    preg_match_all('/@import\s+(?:url\(\s*)?[\'"]([^\'"]+)[\'"]/i', $content, $m);
    foreach ($m[1] as $u) $links[] = $u;
    return $links;
}

/** JS 中的静态资源字符串 */
function extract_links_from_js(string $content, string $baseUrl, string $domain): array
{
    $links = [];
    preg_match_all('/["\']((?:\.{1,2}\/|\/)[^"\'<>\\\\\s]+\.(?:js|css|mjs|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|json))(\?[^"\']*)?["\']/i', $content, $m);
    foreach ($m[0] as $raw) {
        $links[] = substr($raw, 1, -1);
    }
    return $links;
}

/** HTML 中所有可提取的链接（a/link/img/script/... + srcset + style + meta refresh） */
function extract_links_from_html(string $content, string $baseUrl, string $domain): array
{
    $links = [];
    // 去掉注释，避免把注释里的链接也抓进来
    $content = preg_replace('/<!--.*?-->/s', '', $content) ?? '';

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    if ($loaded) {
        $xp = new DOMXPath($doc);
        $tags = ['a', 'link', 'img', 'script', 'iframe', 'embed', 'object',
                 'audio', 'video', 'source', 'track', 'frame', 'area', 'form',
                 'use', 'button', 'input'];
        $attrs = ['href', 'src', 'data-src', 'data-href', 'data-original',
                  'action', 'poster', 'formaction'];
        foreach ($tags as $tag) {
            $nodes = $xp->query('//' . $tag);
            if ($nodes === false) continue;
            foreach ($nodes as $node) {
                foreach ($attrs as $attr) {
                    if ($node->hasAttribute($attr)) {
                        $val = $node->getAttribute($attr);
                        if ($val !== '') $links[] = $val;
                    }
                }
                foreach (['srcset', 'data-srcset', 'imagesrcset'] as $attr) {
                    if ($node->hasAttribute($attr)) {
                        foreach (preg_split('/,/', $node->getAttribute($attr)) as $candidate) {
                            $first = trim(explode(' ', trim($candidate))[0]);
                            if ($first !== '') $links[] = $first;
                        }
                    }
                }
            }
        }
        // meta refresh 跳转
        $metas = $xp->query('//meta[@http-equiv and @content]');
        foreach ($metas as $meta) {
            if (strtolower($meta->getAttribute('http-equiv')) === 'refresh'
                && preg_match('/url\s*=\s*([^;]+)$/i', $meta->getAttribute('content'), $m)) {
                $links[] = trim($m[1], " \t\n\r\"'");
            }
        }
        // <style> 标签内的 CSS
        $styles = $xp->query('//style');
        foreach ($styles as $style) {
            $styleText = $style->textContent;
            if ($styleText !== '') {
                foreach (extract_links_from_css($styleText, $baseUrl, $domain) as $l) $links[] = $l;
            }
        }
    }
    // 兜底：SVG use 的 xlink:href
    preg_match_all('/xlink:href\s*=\s*["\']([^"\']+)["\']/i', $content, $m);
    foreach ($m[1] as $u) $links[] = $u;

    return array_values(array_unique($links));
}

/** 同目录冲突时自动加 _2 _3... */
function make_unique_filename(string $savePath, string $filename, array &$used): string
{
    if (!isset($used[$filename]) && !file_exists($savePath . DIRECTORY_SEPARATOR . $filename)) {
        $used[$filename] = true;
        return $filename;
    }
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;
    $i = 2;
    while (true) {
        $candidate = $ext !== '' ? "{$stem}_{$i}.{$ext}" : "{$stem}_{$i}";
        if (!isset($used[$candidate]) && !file_exists($savePath . DIRECTORY_SEPARATOR . $candidate)) {
            $used[$candidate] = true;
            return $candidate;
        }
        $i++;
    }
}

/** 按 URL 目录结构创建嵌套文件夹 */
function create_nested_folders(string $domain, string $dirPath): string
{
    $savePath = BASE_DIR . DIRECTORY_SEPARATOR . sanitize_name($domain);
    if ($dirPath !== '') {
        foreach (explode('/', $dirPath) as $part) {
            $part = sanitize_name($part);
            if ($part === '' || $part === '.') continue;
            $savePath .= DIRECTORY_SEPARATOR . $part;
        }
    }
    if (!is_dir($savePath)) @mkdir($savePath, 0777, true);
    return $savePath;
}

// ==================== 爬取主逻辑 ====================

function crawl_site(string $startUrl): array
{
    $startTs = time();
    $baseDomain = strtolower((string)parse_url($startUrl, PHP_URL_HOST));

    $queue = [$startUrl];          // 待处理队列（BFS）
    $qIdx = 0;
    $queued = [$startUrl => true]; // 已入队去重
    $visited = [];                 // 已访问 URL
    $downloaded = [];              // 已下载文件绝对路径
    $usedNames = [];               // 每个保存目录的名字占用集合
    $failLog = [];                 // 下载失败记录

    while (isset($queue[$qIdx])) {
        if (MAX_RUNTIME_SECONDS > 0 && (time() - $startTs) > MAX_RUNTIME_SECONDS) break;
        if (MAX_FILES > 0 && count($downloaded) >= MAX_FILES) break;

        $currentUrl = $queue[$qIdx++];
        $normUrl = normalize_url($currentUrl, $startUrl);
        if ($normUrl === null || !should_download_file($normUrl)) continue;
        if (isset($visited[$normUrl])) continue;
        $visited[$normUrl] = true;

        // ---- 下载（带重试） ----
        $resp = http_get($normUrl);
        if ($resp === null) {
            $failLog[] = $normUrl;
            continue;
        }
        [$body, $contentType] = $resp;

        // ---- 解析保存路径和文件名 ----
        [$domain, $dirPath, $fullName, $query] = parse_url_structure($normUrl);
        $ext = get_file_extension_from_url($normUrl, $contentType);

        if (strpos($fullName, '.') !== false) {
            $fileExt = pathinfo($fullName, PATHINFO_EXTENSION);
            $filename = pathinfo($fullName, PATHINFO_FILENAME);
            if ($ext === '' && $fileExt !== '') $ext = '.' . $fileExt;
        } else {
            $filename = $fullName;
        }

        if ($ext === '') {
            $main = strtolower(trim(explode(';', $contentType)[0]));
            if (strpos($main, 'html') !== false) $ext = '.html';
            elseif (strncmp($main, 'text/', 5) === 0) $ext = '.txt';
            else $ext = '.bin';
        }

        $cleaned = sanitize_name($filename);
        if ($cleaned === '') $cleaned = 'index'; // 主页/无名文件用 index

        // 带 query 的 URL 加哈希后缀，避免不同参数互相覆盖
        if ($query !== '') {
            $cleaned .= '_' . substr(md5($query), 0, 6);
        }

        $savePath = create_nested_folders($domain, $dirPath);
        if (!isset($usedNames[$savePath])) $usedNames[$savePath] = [];
        $fullFilename = make_unique_filename($savePath, $cleaned . $ext, $usedNames[$savePath]);
        $filePath = $savePath . DIRECTORY_SEPARATOR . $fullFilename;

        // ---- 保存文件 ----
        if (is_text_type($contentType)) {
            file_put_contents($filePath, to_utf8($body));
        } else {
            file_put_contents($filePath, $body);
        }
        $downloaded[] = $filePath;

        // ---- 二次深层检索：按类型提取嵌套引用 ----
        $main = strtolower(trim(explode(';', $contentType)[0]));
        $isHtml = in_array($ext, ['.html', '.htm', '.php', ''], true) && strpos($main, 'html') !== false;
        $newLinks = [];
        if ($isHtml) {
            $newLinks = extract_links_from_html(to_utf8($body), $normUrl, $baseDomain);
        } elseif ($ext === '.css') {
            $newLinks = extract_links_from_css(to_utf8($body), $normUrl, $baseDomain);
        } elseif (in_array($ext, ['.js', '.mjs'], true)) {
            $newLinks = extract_links_from_js(to_utf8($body), $normUrl, $baseDomain);
        }

        foreach ($newLinks as $link) {
            $n = normalize_url($link, $normUrl);
            if ($n !== null && same_site($n, $baseDomain) && !isset($queued[$n]) && !isset($visited[$n])) {
                $queued[$n] = true;
                $queue[] = $n;
            }
        }
    }

    // ---- 生成记录文件 ----
    $recordDir = BASE_DIR . DIRECTORY_SEPARATOR . sanitize_name($baseDomain);
    if (!is_dir($recordDir)) @mkdir($recordDir, 0777, true);

    $listLines = ['=== 所有下载文件列表 ===', ''];
    foreach ($downloaded as $f) {
        $listLines[] = str_replace('\\', '/', ltrim(str_replace(BASE_DIR, '', $f), '/\\'));
    }
    file_put_contents($recordDir . DIRECTORY_SEPARATOR . '所有下载文件列表.txt', implode("\n", $listLines) . "\n");

    $statsLines = [
        '=== 下载统计 ===', '',
        '总下载文件数: ' . count($downloaded),
        '访问的URL数量: ' . count($visited),
        '起始URL: ' . $startUrl,
        '下载时间: ' . date('Y-m-d H:i:s'),
    ];
    file_put_contents($recordDir . DIRECTORY_SEPARATOR . '下载统计.txt', implode("\n", $statsLines) . "\n");

    if ($failLog) {
        $failLines = [];
        foreach ($failLog as $u) {
            $failLines[] = $u . ': 下载失败（多次重试后仍失败）';
        }
        file_put_contents(BASE_DIR . DIRECTORY_SEPARATOR . '下载失败记录.txt', implode("\n", $failLines) . "\n", FILE_APPEND);
    }

    return [
        'downloadCount' => count($downloaded),
        'visitedCount' => count($visited),
        'failCount' => count($failLog),
    ];
}

/** 递归删除文件夹（打包完成后只保留 zip 用） */
function delete_folder(string $dir): bool
{
    if (!is_dir($dir)) return false;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    return @rmdir($dir);
}

/** 将域名文件夹整体压缩为 zip（zip 内含第一级文件夹本身） */
function create_zip(string $folder, string $zipPath): bool
{
    $root = realpath($folder);
    if ($root === false || !is_dir($root)) return false;
    $domainName = basename($root);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $abs = $file->getPathname();
        $rel = $domainName . '/' . str_replace('\\', '/', substr($abs, strlen($root) + 1));
        $zip->addFile($abs, $rel);
    }
    return $zip->close();
}

// ==================== 请求处理 ====================

$url = trim((string)($_POST['url'] ?? $_GET['url'] ?? ''));
if ($url === '') {
    json_out(['success' => false, 'error' => '请先输入要爬取的网址']);
}
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}
$parts = parse_url($url);
if (!$parts || empty($parts['host'])) {
    json_out(['success' => false, 'error' => '网址格式不正确，请检查后重试']);
}
$scheme = strtolower($parts['scheme'] ?? '');
if (!in_array($scheme, ['http', 'https'], true)) {
    json_out(['success' => false, 'error' => '仅支持 http / https 协议']);
}

if (!is_dir(BASE_DIR)) @mkdir(BASE_DIR, 0777, true);

$siteKey = sanitize_name($parts['host']);
$domainFolder = BASE_DIR . DIRECTORY_SEPARATOR . $siteKey;
$lockFile = BASE_DIR . DIRECTORY_SEPARATOR . $siteKey . '.lock';

// 简单防并发：同一域名爬取期间加锁
if (file_exists($lockFile) && (time() - @filemtime($lockFile)) < 3600) {
    json_out(['success' => false, 'error' => '该网站正在爬取中，请稍候再试。若上次爬取异常中断，可删除 sites 目录下的 ' . $siteKey . '.lock 文件后重试。']);
}
@file_put_contents($lockFile, time());

$response = null;
try {
    $result = crawl_site($url);

    // ---- 将第一级域名文件夹压缩成 .zip ----
    $zipName = $siteKey . '.zip';
    $zipPath = BASE_DIR . DIRECTORY_SEPARATOR . $zipName;
    $zipOk = create_zip($domainFolder, $zipPath);

    $statsTxt = @file_get_contents($domainFolder . DIRECTORY_SEPARATOR . '下载统计.txt');
    $listTxt  = @file_get_contents($domainFolder . DIRECTORY_SEPARATOR . '所有下载文件列表.txt');

    // 打包成功后自动删除域名文件夹，只保留 zip 压缩包
    // （txt 内容已读入内存并随 JSON 返回给前端展示，zip 内含完整文件）
    if ($zipOk && is_dir($domainFolder)) {
        delete_folder($domainFolder);
    }

    $response = [
        'success' => true,
        'zipName' => $zipName,
        'zipUrl' => rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/') . '/download.php?file=' . rawurlencode($zipName),
        'zipOk' => $zipOk,
        'folderDeleted' => $zipOk ? true : false,
        'downloadCount' => $result['downloadCount'],
        'failCount' => $result['failCount'],
        'stats' => $statsTxt !== false ? $statsTxt : '（未生成）',
        'fileList' => $listTxt !== false ? $listTxt : '（未生成）',
    ];
} catch (Throwable $e) {
    $response = ['success' => false, 'error' => '爬取过程中出错：' . $e->getMessage()];
} finally {
    @unlink($lockFile);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
