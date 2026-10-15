import requests
import os
import re
import sys
import time
import hashlib
import threading
import datetime
from urllib.parse import urlparse, urljoin, urldefrag
from mimetypes import guess_extension
from queue import Queue, Empty
from bs4 import BeautifulSoup
from rich.console import Console

sys.setrecursionlimit(10000)

# ==================== 配置区 ====================
BASE_DIR = "/storage/emulated/0/网页爬取/"
THREAD_COUNT = 8        # 线程数：手机建议4~8，电脑可16~32
MAX_RETRIES = 3
REQUEST_TIMEOUT = 20
# ================================================

os.makedirs(BASE_DIR, exist_ok=True)

headers = {
    "User-Agent": "Mozilla/5.0 (Linux; Android 12; SM-S9010) AppleWebKit/537.36 Chrome/100.0.4896.127 Mobile Safari/537.36",
    "Accept": "*/*"
}

console = Console()

# 文本类 MIME（按文本保存，其余一律二进制）
TEXTUAL_TYPES = {
    'text/html', 'text/css', 'text/plain', 'text/xml', 'text/csv',
    'application/json', 'application/xml', 'application/javascript',
    'text/javascript', 'image/svg+xml', 'application/xhtml+xml',
    'application/rss+xml', 'application/atom+xml',
}

CSS_URL_RE = re.compile(r'url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)', re.IGNORECASE)
CSS_IMPORT_RE = re.compile(r'@import\s+(?:url\(\s*)?[\'"]([^\'"]+)[\'"]', re.IGNORECASE)
JS_ASSET_RE = re.compile(
    r'''["']((?:\.{1,2}/|/)[^"'<>\\\s]+\.(?:js|css|mjs|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|json))(\?[^"']*)?["']''',
    re.IGNORECASE
)
META_REFRESH_RE = re.compile(r'url\s*=\s*([^;]+)$', re.IGNORECASE)


# ==================== 基础工具函数 ====================

def sanitize_name(name: str) -> str:
    """清洗名称，保留中文、字母、数字、点、短横线"""
    return re.sub(r'[^\w\u4e00-\u9fa5\.\-]', '_', name)


def normalize_url(url, base_url):
    """拼接相对URL、去fragment、补协议；非法返回None"""
    if not url or not url.strip():
        return None
    url = url.strip()
    if url.startswith(('mailto:', 'tel:', 'javascript:', 'data:', '#')):
        return None
    url = urljoin(base_url, url)
    url, _frag = urldefrag(url)
    parsed = urlparse(url)
    if parsed.scheme not in ('http', 'https') or not parsed.netloc:
        return None
    return parsed.geturl()


def same_site(url: str, domain: str) -> bool:
    netloc = urlparse(url).netloc.lower()
    domain = domain.lower()
    return netloc == domain or netloc.endswith('.' + domain) or domain.endswith('.' + netloc)


def parse_url_structure(url: str):
    """返回（域名, 目录路径, 文件名, query）"""
    parsed = urlparse(url)
    path = parsed.path.rstrip('/')
    full_name = os.path.basename(path) or "index"

    if not path or full_name.lower() in ("index", "index.html", "index.htm", "index.php"):
        return parsed.netloc, "", "index", parsed.query  # 主页固定为 index

    dir_path = os.path.dirname(path).lstrip('/')
    return parsed.netloc, dir_path, full_name, parsed.query


def create_nested_folders(domain: str, dir_path: str) -> str:
    if dir_path:
        nested_dirs = [sanitize_name(part) for part in dir_path.split('/') if part]
        save_path = os.path.join(BASE_DIR, sanitize_name(domain), *nested_dirs)
    else:
        save_path = os.path.join(BASE_DIR, sanitize_name(domain))
    os.makedirs(save_path, exist_ok=True)
    return save_path


def get_file_extension_from_url(url: str, content_type=None) -> str:
    parsed = urlparse(url)
    path = parsed.path
    if '.' in path:
        ext = os.path.splitext(path)[1].lower()
        if ext and len(ext) <= 10:
            return ext

    if content_type:
        mime_to_ext = {
            'text/html': '.html', 'text/css': '.css',
            'text/javascript': '.js', 'application/javascript': '.js',
            'application/json': '.json', 'application/xml': '.xml',
            'image/jpeg': '.jpg', 'image/jpg': '.jpg', 'image/png': '.png',
            'image/gif': '.gif', 'image/svg+xml': '.svg', 'image/webp': '.webp',
            'image/bmp': '.bmp', 'image/x-icon': '.ico', 'image/vnd.microsoft.icon': '.ico',
            'application/pdf': '.pdf', 'application/zip': '.zip',
            'application/gzip': '.gz', 'application/x-tar': '.tar',
            'application/msword': '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
            'application/vnd.ms-excel': '.xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': '.xlsx',
            'application/vnd.ms-powerpoint': '.ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation': '.pptx',
            'text/plain': '.txt', 'text/csv': '.csv',
            'audio/mpeg': '.mp3', 'audio/wav': '.wav',
            'video/mp4': '.mp4', 'video/webm': '.webm',
            'font/woff': '.woff', 'font/woff2': '.woff2',
            'font/ttf': '.ttf', 'font/otf': '.otf',
            'application/font-woff': '.woff', 'application/font-woff2': '.woff2',
            'application/x-font-ttf': '.ttf', 'application/x-font-otf': '.otf',
            'application/octet-stream': '',
        }
        main_type = content_type.split(';')[0].strip()
        if main_type in mime_to_ext:
            return mime_to_ext[main_type]
        ext = guess_extension(main_type)
        if ext:
            return ext
    return ""


def should_download_file(url: str) -> bool:
    parsed = urlparse(url)
    path = parsed.path.lower()
    for ext in ('.php', '.asp', '.aspx', '.jsp', '.cgi', '.pl', '.py', '.rb'):
        if path.endswith(ext):
            return False
    for pattern in ('logout', 'signin', 'signup', 'register'):
        if pattern in url.lower():
            return False
    return True


# ==================== 链接提取（含二次深层检索） ====================

def _collect(link, base_url, domain, out):
    norm = normalize_url(link, base_url)
    if norm and same_site(norm, domain):
        out.append(norm)


def extract_links_from_html(content: str, base_url: str, domain: str) -> list:
    soup = BeautifulSoup(content, 'html.parser')
    links = []

    link_tags = soup.find_all(['a', 'link', 'img', 'script', 'iframe', 'embed',
                               'object', 'audio', 'video', 'source', 'track',
                               'frame', 'area', 'form', 'use', 'button', 'input'])
    for tag in link_tags:
        for attr in ('href', 'src', 'data-src', 'data-href', 'data-original',
                     'action', 'poster', 'xlink:href', 'formaction'):
            if tag.has_attr(attr):
                _collect(tag[attr], base_url, domain, links)
        # srcset: "a.jpg 1x, b.jpg 2x"
        for attr in ('srcset', 'data-srcset', 'imagesrcset'):
            if tag.has_attr(attr):
                for candidate in tag[attr].split(','):
                    _collect(candidate.strip().split(' ')[0], base_url, domain, links)

    # <style> 标签内的 CSS
    for style_tag in soup.find_all('style'):
        if style_tag.string:
            links.extend(extract_links_from_css(style_tag.string, base_url, domain))

    # meta refresh 跳转
    for meta_tag in soup.find_all('meta'):
        if meta_tag.get('http-equiv', '').lower() == 'refresh' and meta_tag.has_attr('content'):
            m = META_REFRESH_RE.search(meta_tag['content'].strip())
            if m:
                _collect(m.group(1).strip(' \'"'), base_url, domain, links)

    return list(set(links))


def extract_links_from_css(content: str, base_url: str, domain: str) -> list:
    """二次深层检索：CSS 中的 url() 和 @import"""
    links = []
    for m in CSS_URL_RE.finditer(content):
        _collect(m.group(1), base_url, domain, links)
    for m in CSS_IMPORT_RE.finditer(content):
        _collect(m.group(1), base_url, domain, links)
    return list(set(links))


def extract_links_from_js(content: str, base_url: str, domain: str) -> list:
    """二次深层检索：JS 中的静态资源字符串"""
    links = []
    for m in JS_ASSET_RE.finditer(content):
        _collect(m.group(0)[1:-1], base_url, domain, links)
    return list(set(links))


def make_unique_filename(save_path: str, filename: str, used_names: set) -> str:
    """同目录冲突时自动加 _2 _3...（调用方需持有锁）"""
    if filename not in used_names and not os.path.exists(os.path.join(save_path, filename)):
        used_names.add(filename)
        return filename
    stem, ext = os.path.splitext(filename)
    i = 2
    while True:
        candidate = f"{stem}_{i}{ext}"
        if candidate not in used_names and not os.path.exists(os.path.join(save_path, candidate)):
            used_names.add(candidate)
            return candidate
        i += 1


# ==================== 多线程下载主逻辑 ====================

def download_file(url: str):
    console.print(f"开始处理 URL: {url}", style="bold green")

    queue = Queue()
    queue.put((url, 0))

    visited_urls = set()
    visited_lock = threading.Lock()

    queued_urls = {url}
    queued_lock = threading.Lock()

    used_names = set()
    names_lock = threading.Lock()

    downloaded_files = []
    files_lock = threading.Lock()

    stats = {"count": 0}
    stats_lock = threading.Lock()

    base_domain = urlparse(url).netloc
    stop_flag = threading.Event()

    def already_visited(u):
        """原子化 check-and-set，多线程同时抢同一URL也只会下载一次"""
        with visited_lock:
            if u in visited_urls:
                return True
            visited_urls.add(u)
            return False

    def enqueue(links, depth):
        for link in links:
            with queued_lock:
                if link in queued_urls:
                    continue
                queued_urls.add(link)
            queue.put((link, depth))

    def worker():
        while not stop_flag.is_set():
            try:
                current_url, depth = queue.get(timeout=5)
            except Empty:
                return  # 队列真正耗尽，线程收工

            try:
                norm_url = normalize_url(current_url, url)
                if not norm_url or not should_download_file(norm_url):
                    continue
                if already_visited(norm_url):
                    continue

                # 下载（带重试）
                response = None
                for retry in range(MAX_RETRIES):
                    try:
                        response = requests.get(norm_url, headers=headers,
                                                stream=True, timeout=REQUEST_TIMEOUT)
                        response.raise_for_status()
                        break
                    except requests.exceptions.RequestException as e:
                        if retry < MAX_RETRIES - 1:
                            console.print(f"⚠️ 重试中... {str(e)[:60]}", style="yellow")
                        else:
                            console.print(f"❌ 失败: {norm_url}", style="bold red")
                            with open(os.path.join(BASE_DIR, "下载失败记录.txt"),
                                      "a", encoding="utf-8") as f:
                                f.write(f"{norm_url}: {str(e)}\n")
                if response is None:
                    continue

                # 解析保存路径和文件名
                content_type = response.headers.get("Content-Type", "").split(';')[0].strip()
                domain, dir_path, full_name, query = parse_url_structure(norm_url)
                ext = get_file_extension_from_url(norm_url, content_type)

                if '.' in full_name:
                    filename, file_ext = os.path.splitext(full_name)
                    if not ext and file_ext:
                        ext = file_ext
                else:
                    filename = full_name

                if not ext:
                    if 'html' in content_type:
                        ext = '.html'
                    elif 'text' in content_type:
                        ext = '.txt'
                    else:
                        ext = '.bin'

                cleaned_filename = sanitize_name(filename)
                if not cleaned_filename:
                    cleaned_filename = 'index'  # 主页/无名文件用 index，不用 file

                # 带 query 的 URL 加哈希后缀，避免不同参数互相覆盖
                if query:
                    cleaned_filename += '_' + hashlib.md5(query.encode()).hexdigest()[:6]

                save_path = create_nested_folders(domain, dir_path)
                with names_lock:
                    full_filename = make_unique_filename(save_path, f"{cleaned_filename}{ext}", used_names)
                file_path = os.path.join(save_path, full_filename)

                # 保存
                is_text = (content_type in TEXTUAL_TYPES or content_type.startswith('text/')
                           or 'javascript' in content_type or 'json' in content_type
                           or 'xml' in content_type)

                content_str = None
                if is_text:
                    encoding = response.encoding or response.apparent_encoding or 'utf-8'
                    content_str = response.content.decode(encoding, errors='ignore')
                    with open(file_path, "w", encoding=encoding) as f:
                        f.write(content_str)
                else:
                    with open(file_path, "wb") as f:
                        for chunk in response.iter_content(chunk_size=8192):
                            if chunk:
                                f.write(chunk)

                with stats_lock:
                    stats["count"] += 1
                    n = stats["count"]
                with files_lock:
                    downloaded_files.append(file_path)

                console.print(f"✅ [{n}] {full_filename} ({content_type})", style="green")

                # ===== 二次深层检索：按类型提取嵌套引用 =====
                is_html = ext in ('.html', '.htm', '.php', '') and 'html' in content_type
                if is_html:
                    if content_str is None:
                        content_str = response.content.decode(
                            response.encoding or 'utf-8', errors='ignore')
                    found = extract_links_from_html(content_str, norm_url, base_domain)
                    found += extract_links_from_css(content_str, norm_url, base_domain)
                    enqueue(found, depth + 1)
                elif ext == '.css':
                    if content_str is None:
                        content_str = response.content.decode('utf-8', errors='ignore')
                    enqueue(extract_links_from_css(content_str, norm_url, base_domain), depth)
                elif ext in ('.js', '.mjs'):
                    if content_str is None:
                        content_str = response.content.decode('utf-8', errors='ignore')
                    enqueue(extract_links_from_js(content_str, norm_url, base_domain), depth)

            except Exception as e:
                console.print(f"❌ 处理出错 {current_url}: {str(e)[:80]}", style="bold red")
            finally:
                queue.task_done()

    # 启动线程池
    threads = [threading.Thread(target=worker, daemon=True) for _ in range(THREAD_COUNT)]
    for t in threads:
        t.start()

    try:
        for t in threads:
            t.join()
    except KeyboardInterrupt:
        stop_flag.set()
        console.print("\n用户中断，等待线程退出...", style="bold yellow")
        for t in threads:
            t.join()

    # ===== 生成记录文件 =====
    domain, _, _, _ = parse_url_structure(url)
    record_dir = os.path.join(BASE_DIR, sanitize_name(domain))
    os.makedirs(record_dir, exist_ok=True)

    with open(os.path.join(record_dir, "所有下载文件列表.txt"), "w", encoding="utf-8") as f:
        f.write("=== 所有下载文件列表 ===\n\n")
        for file in downloaded_files:
            f.write(f"{os.path.relpath(file, BASE_DIR)}\n")

    with open(os.path.join(record_dir, "下载统计.txt"), "w", encoding="utf-8") as f:
        f.write("=== 下载统计 ===\n\n")
        f.write(f"总下载文件数: {len(downloaded_files)}\n")
        f.write(f"访问的URL数量: {len(visited_urls)}\n")
        f.write(f"起始URL: {url}\n")
        f.write(f"下载时间: {datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")

    console.print(f"✅ 记录文件已生成于: {record_dir}", style="green")
    console.print(f"结束处理 URL: {url}，共下载 {len(downloaded_files)} 个文件", style="bold green")


# ==================== 入口 ====================

if __name__ == "__main__":
    console.print("===== 全能网页爬虫工具 V3（多线程版） =====", style="bold cyan")
    console.print(f"功能：无限爬取全站文件，支持CSS/JS二次深层检索 | 线程数: {THREAD_COUNT}", style="cyan")

    while True:
        target_url = input("\n请输入网址（输入q退出）：").strip()
        if target_url.lower() == 'q':
            console.print("退出程序", style="bold yellow")
            break

        if not target_url:
            console.print("请输入有效的网址", style="bold red")
            continue

        if not target_url.startswith(('http://', 'https://')):
            target_url = 'https://' + target_url

        try:
            download_file(target_url)
        except KeyboardInterrupt:
            console.print("\n用户中断操作", style="bold yellow")
            break
        except Exception as e:
            console.print(f"程序执行出错: {str(e)}", style="bold red")
            import traceback
            traceback.print_exc()