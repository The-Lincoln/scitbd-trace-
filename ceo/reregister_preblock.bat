@echo off
REM ============================================================
REM  Re-registers SCITBD pre-block alert as a RECURRING 5-min task,
REM  replacing the 4 fixed wall-clock tasks (which depended on the
REM  host timezone and were vulnerable to scheduler jitter).
REM ============================================================
setlocal
set "ROOT=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy"

REM Remove the timezone-fragile fixed-time tasks
for %%T in (SCITBD_PreBlock_0545 SCITBD_PreBlock_1145 SCITBD_PreBlock_1745 SCITBD_PreBlock_2345) do (
  schtasks /delete /tn "%%T" /f >nul 2>&1
  echo deleted %%T
)

REM Single recurring task: every 5 minutes, script decides via BST clock
schtasks /create /tn "SCITBD_PreBlock" /sc minute /mo 5 ^
  /tr "%ROOT%\run_preblock_alert.bat" /f

echo.
echo ============ SCITBD TASKS ============
schtasks /query /fo table | findstr /i "SCITBD"
