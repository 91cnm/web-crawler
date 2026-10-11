# 全能网页爬虫工具（PHP 版）

由「网页爬虫pro.py」移植的 PHP 全站爬虫：前端输入网址 → 后端爬取整站文件 → 自动打包 zip 下载，并展示下载统计与文件列表。

## 文件结构

```
网页爬虫PHP版/
├── 启动.bat        # 一键启动（双击即可，无需安装任何环境）
├── index.html      # 前端页面（输入网址、展示结果、下载按钮）
├── crawl.php       # 后端爬虫核心（爬取 + 生成记录 + 打包 zip）
├── download.php    # zip 下载接口（download.php?file=example.com.zip）
├── README.md       # 本说明
├── tools/php/      # 内置的免安装 PHP 8.4 运行环境（已配好所需扩展）
└── sites/          # 运行时自动生成：存放 zip 压缩包（爬完的域名文件夹会被自动清理）
```

## 环境要求

- 本目录已内置免安装 PHP 8.4（`tools/php`），**不需要额外安装任何软件**
- 也可以改用自己电脑上的 XAMPP / phpstudy / WAMP（需启用 curl、zip、mbstring、dom 扩展），此时可删除 `tools/` 目录
- Windows / Linux / macOS 均可运行（Linux/macOS 直接用系统 PHP 即可）

## 运行方式（任选其一）

### 方式一：双击「启动.bat」（推荐，Windows 免安装）

双击 `启动.bat`，浏览器会自动打开 http://localhost:8000 ，关闭黑窗口即停止服务。

### 方式二：XAMPP / WAMP / phpstudy

把 `index.html`、`crawl.php`、`download.php`、`README.md` 复制到网站根目录（如 `htdocs/网页爬虫PHP版`），浏览器访问：

```
http://localhost/网页爬虫PHP版/
```

（用这种方式可删除 `tools/` 内置 PHP 目录）

## 使用说明

1. 在输入框填写网址（如 `example.com`，不加协议会自动补 `https://`）
2. 点击「开始爬取」，等待爬取完成（网站越大越慢）
3. 爬完后页面显示：zip 下载按钮、「下载统计.txt」内容、「所有下载文件列表.txt」内容
4. 也可直接在服务器上打开 `download.php?file=example.com.zip` 下载压缩包

## 行为说明（与原 Python 版一致）

- **无限爬取**：不限制深度和文件数，直到站内链接全部抓完
- **同站过滤**：只爬取同一域名（含子域名），外站、`mailto:`、`javascript:`、锚点等自动跳过
- **跳过动态脚本**：`.php/.asp/.aspx/.jsp/.cgi/.pl/.py/.rb` 结尾的地址不下载；含 `logout/signin/signup/register` 的地址跳过
- **深层检索**：HTML 里的 a/link/img/script/iframe 等标签、CSS 里的 `url()`/`@import`、JS 里的静态资源字符串都会继续往下抓
- **文件名**：按 URL 目录结构保存到 `sites/域名/...`；同目录重名自动加 `_2`、`_3`；带参数的 URL 加哈希后缀防覆盖
- **记录文件**：爬完生成 `sites/域名/所有下载文件列表.txt` 和 `sites/域名/下载统计.txt`；失败的 URL 追加到 `sites/下载失败记录.txt`
- **打包后自动清理**：打包成功后会**自动删除域名文件夹**，只保留 zip 压缩包（统计和文件列表内容已随爬取结果展示在前端，无需再从磁盘读取）

## 与原 Python 版的主要差异

| 项目 | Python 版 | PHP 版 |
| --- | --- | --- |
| 并发 | 8 线程并发下载 | 单线程顺序爬取（PHP 无便携多线程），结果一致、速度稍慢 |
| 文本编码 | 按网页原始编码保存 | 统一转成 UTF-8 保存（兼容 GBK/BIG-5 等） |
| 运行环境 | 手机/本地 Python | 需要 Web 服务器（PHP 内置服务器或 XAMPP 等） |
| 打包 | 无 | 新增：爬完后自动把域名文件夹打包为 .zip |
| 前端 | 命令行交互 | 网页输入网址、展示统计和文件列表、下载 zip |

## 配置项（crawl.php 顶部）

```php
define('MAX_RETRIES', 3);          // 单个 URL 最大重试次数
define('REQUEST_TIMEOUT', 20);     // 单次请求超时（秒）
define('MAX_RUNTIME_SECONDS', 0);  // 最大运行时长（秒），0 = 不限
define('MAX_FILES', 0);            // 最大下载文件数，0 = 不限
```

> 注意：`MAX_RUNTIME_SECONDS` 只约束脚本自身；如果部署在共享主机上，Web 服务器（如 Apache/FastCGI）可能还有自己的请求超时限制，建议放本地或 VPS 使用。

## 常见问题

- **提示缺少扩展**：在 php.ini 中启用 `extension=curl`、`extension=zip`、`extension=mbstring`、`extension=dom`（XAMPP 通常已默认启用）。
- **提示"该网站正在爬取中"**：同一域名同时只允许一个爬取任务；若上次异常中断，删除 `sites/域名.lock` 文件即可。
- **下载按钮无效**：确认是通过 HTTP 服务访问（不是直接双击 index.html），且 PHP 服务正常。
- **超时**：网站太大时把 `MAX_RUNTIME_SECONDS` 调大或设为 0，并确认 Web 服务器无请求超时限制。
