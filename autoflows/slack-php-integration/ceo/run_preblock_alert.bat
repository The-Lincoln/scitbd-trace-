@echo off
REM ============================================================
REM  SCITBD AI CEO - 15-min Pre-Block Alert (recurring wrapper)
REM
REM  Runs EVERY 5 MINUTES rather than at fixed wall-clock times.
REM  Reason: scitbd_alerting.precheck() evaluates the trigger window
REM  using now_bst() (Asia/Dhaka via zoneinfo), NOT the OS clock.
REM  A recurring cadence therefore makes firing:
REM     * timezone-independent (host tz can be anything)
REM     * jitter-proof (15-min window / 5-min cadence = >=2 chances)
REM     * idempotent (block_alert_state dedupes once per block/day)
REM
REM  Trigger windows (BST): 05:45, 11:45, 17:45, 23:45 (each 15 min)
REM ============================================================
setlocal
set "PYTHON=C:\Python314\python.exe"
set "ROOT=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy"
set "LOG=%ROOT%\logs\preblock_alert.log"

if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"
"%PYTHON%" "%ROOT%\scitbd_alerting.py" precheck >> "%LOG%" 2>&1
endlocal
