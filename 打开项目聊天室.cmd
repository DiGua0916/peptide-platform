@echo off
set "ROOM_PYTHON=%LocalAppData%\Programs\Python\Python312\pythonw.exe"
if exist "%ROOM_PYTHON%" (
  start "" "%ROOM_PYTHON%" "%~dp0tools\chatroom\room.py" open
) else (
  python "%~dp0tools\chatroom\room.py" open
)
