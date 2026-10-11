@echo off
chcp 65001 >nul
cd /d "%~dp0"
title 全能网页爬虫工具 - PHP 服务
echo ============================================
echo   全能网页爬虫工具正在启动...
echo   浏览器将自动打开 http://localhost:8000
echo   关闭本窗口即可停止服务
echo ============================================
echo.
start "" http://localhost:8000
"%~dp0tools\php\php.exe" -S localhost:8000
pause
