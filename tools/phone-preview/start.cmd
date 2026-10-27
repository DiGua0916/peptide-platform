@echo off
rem Phone preview of the local store. See README.md (firewall rule needed once).
set "PY=%LocalAppData%\Programs\Python\Python312\python.exe"
if not exist "%PY%" set "PY=python"
"%PY%" -u "%~dp0phone_preview.py" %*
pause
