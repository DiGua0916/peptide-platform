@echo off
set "AB_DEMO_PYTHON=%LocalAppData%\Programs\Python\Python312\pythonw.exe"
if exist "%AB_DEMO_PYTHON%" (
  start "" "%AB_DEMO_PYTHON%" "%~dp0server.py" open
) else (
  python "%~dp0server.py" open
)
