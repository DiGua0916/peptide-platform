@echo off
setlocal EnableExtensions DisableDelayedExpansion
rem peptide-platform collaborator sync. See docs/COLLABORATION.md.
rem Saved as GBK (code page 936) on purpose: cmd misreads UTF-8 batch files with Chinese text.
rem First run: clone the repo next to this file, ask a name, create <name>/work from origin/main.
rem Every run: fetch, merge origin/main into your own branch, push only your own branch.
title peptide-platform sync
rem Use the Windows find/findstr even when a Unix-style find comes first in PATH.
set "FIND=%SystemRoot%\System32\find.exe"
set "FINDSTR=%SystemRoot%\System32\findstr.exe"
if not defined PC_SYNC_REMOTE set "PC_SYNC_REMOTE=https://github.com/DiGua0916/peptide-platform.git"

echo ==========================================
echo   多肽项目 peptide-platform：协作者同步
echo ==========================================
echo.

where git >nul 2>nul
if errorlevel 1 goto nogit

rem Inside the project already: use it. Otherwise clone into .\peptide-platform next to this file.
set "REPO="
for /f "delims=" %%i in ('git -C "%~dp0." rev-parse --show-toplevel 2^>nul') do set "REPO=%%i"
if not defined REPO goto standalone
rem DiGua's own checkout (several worktrees share one config): never touch it.
echo %REPO%| "%FINDSTR%" /i /c:"Codex/Projects/peptide-platform" >nul
if not errorlevel 1 goto ownerrepo
set "WT=0"
for /f %%c in ('git -C "%REPO%" worktree list ^| "%FIND%" /c /v ""') do set "WT=%%c"
if %WT% GTR 1 goto ownerrepo
git -C "%REPO%" config --local peptide.role >nul 2>nul
if not errorlevel 1 goto have
git -C "%REPO%" remote get-url origin 2>nul | "%FINDSTR%" /i /c:"peptide-platform" >nul
if errorlevel 1 set "REPO="
:standalone
if not defined REPO set "REPO=%~dp0peptide-platform"
if exist "%REPO%\.git" goto have
echo 第一次使用：把项目下载到 %REPO%
echo 会弹出 GitHub 登录窗口，请用收到仓库邀请的那个账号登录。
echo.
git clone "%PC_SYNC_REMOTE%" "%REPO%"
if errorlevel 1 goto clonefail

:have
cd /d "%REPO%"
set "ME="
for /f "delims=" %%n in ('git config --local peptide.name 2^>nul') do set "ME=%%n"
if defined ME goto named
set "TRIES=0"

:askname
set /a TRIES+=1
if %TRIES% GTR 3 goto noname
set "ME="
set /p "ME=请输入你的名字（英文字母、数字或 -，用于分支名和提交署名，例如 evan）："
if not defined ME goto askname
echo %ME%| "%FINDSTR%" /r /x "[a-zA-Z0-9-]*" >nul
if errorlevel 1 goto badname
if /i "%ME%"=="digua" goto reserved
if /i "%ME%"=="claude" goto reserved
if /i "%ME%"=="codex" goto reserved
git config --local peptide.name "%ME%"
git config --local peptide.role collaborator
git config --local user.name "%ME%"
git config user.email >nul 2>nul
if errorlevel 1 git config --local user.email ""
echo 已记下名字：%ME%（只写进这个项目，不改电脑上的其他设置）
echo.

:named
set "BR=%ME%/work"
echo 正在获取最新内容...
git fetch origin --prune
if errorlevel 1 goto fetchfail

set "CUR="
for /f "delims=" %%b in ('git branch --show-current') do set "CUR=%%b"
echo %CUR%| "%FINDSTR%" /i /b /c:"%ME%/" >nul
if not errorlevel 1 goto onmine

rem Not on your own branch yet (fresh clone is on main): switch to <name>/work.
call :isdirty
if not errorlevel 1 goto dirtyswitch
git show-ref --verify --quiet "refs/heads/%BR%"
if not errorlevel 1 goto switchlocal
git show-ref --verify --quiet "refs/remotes/origin/%BR%"
if not errorlevel 1 goto switchremote
git switch -c "%BR%" origin/main
if errorlevel 1 goto switchfail
goto onmine
:switchlocal
git switch "%BR%"
if errorlevel 1 goto switchfail
goto onmine
:switchremote
git switch -c "%BR%" --track "origin/%BR%"
if errorlevel 1 goto switchfail

:onmine
for /f "delims=" %%b in ('git branch --show-current') do set "CUR=%%b"
call :isdirty
if not errorlevel 1 goto dirty
echo 把最新的 main 合进你的分支 %CUR% ...
git merge --no-edit origin/main
if errorlevel 1 goto conflict
echo 上传你的分支 %CUR%（只上传你自己的分支，不碰 main）...
git push -u origin "%CUR%"
if errorlevel 1 goto pushfail
echo.
echo 同步完成。
goto summary

:isdirty
git status --porcelain | "%FINDSTR%" . >nul
exit /b %errorlevel%

:dirty
echo.
echo 你有还没提交的改动，这次只下载了最新内容，没有合并也没有上传。
echo 先让 Claude 提交这些改动（或者自己 git commit），再运行一次本文件。
goto summary

:dirtyswitch
echo.
echo 现在在分支 %CUR% 上，而且有没提交的改动，不能切换到你的分支 %BR%。
echo 请让 Claude 先处理这些改动，再运行一次本文件。
goto summary

:conflict
git merge --abort >nul 2>nul
echo.
echo 最新的 main 和你的改动有冲突，已经撤销这次合并，文件保持原样。
echo 请让 Claude 运行 git merge origin/main 并解决冲突。
goto summary

:summary
echo.
echo 项目目录：%REPO%
git status -sb
echo.
echo main 最近 5 次更新：
git log --oneline -5 origin/main
echo.
echo 下一步：在这个目录里打开 Claude，先让它读 docs\COLLABORATION.md。
goto end

:ownerrepo
echo 这是 DiGua 电脑上的项目目录（几个工作目录共用一份设置），本文件是给协作者用的，不在这里运行。
goto end

:nogit
echo 没有找到 Git。请先安装 Git for Windows：https://git-scm.com/download/win
echo 安装好后再双击本文件。
goto failend
:clonefail
echo.
echo 下载失败。请确认：已经在 GitHub 邮件里接受了仓库邀请，并且登录的是那个账号。
goto failend
:fetchfail
echo.
echo 获取最新内容失败。请检查网络，或者 GitHub 登录是否过期。
goto failend
:switchfail
echo.
echo 切换到分支 %BR% 失败，请把上面的提示发给 Claude 处理。
goto failend
:pushfail
echo.
echo 上传失败。请检查网络和 GitHub 登录；也可以把上面的提示发给 Claude 处理。
goto failend
:badname
echo 名字只能用英文字母、数字和 -，请重新输入。
goto askname
:reserved
echo 这个名字已经被项目里的其他人用了，请换一个。
goto askname
:noname
echo 没有输入有效的名字，已停止。
goto failend

:failend
echo.
if not defined PC_SYNC_NOPAUSE pause
exit /b 1
:end
echo.
if not defined PC_SYNC_NOPAUSE pause
exit /b 0
