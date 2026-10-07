@echo off
REM ============================================================
REM  Registers ALL SCITBD scheduled tasks (idempotent /F).
REM  Run once:  register_scitbd_tasks.bat
REM ============================================================
setlocal
set "ROOT=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy"

REM --- Block sync: every 15 min (already registered earlier) ---
schtasks /create /tn "SCITBD_BlockSync" /sc minute /mo 15 ^
  /tr "%ROOT%\run_block_sync.bat" /f

REM --- Alert retry worker: every 1 minute ---
schtasks /create /tn "SCITBD_AlertRetry" /sc minute /mo 1 ^
  /tr "%ROOT%\run_alert_retry.bat" /f

REM --- Channel canary probe: every 30 minutes ---
schtasks /create /tn "SCITBD_Canary" /sc minute /mo 30 ^
  /tr "%ROOT%\run_canary.bat" /f

REM --- 15-min pre-block alerts: one task per BST trigger time ---
schtasks /create /tn "SCITBD_PreBlock_0545" /sc daily /st 05:45 ^
  /tr "%ROOT%\run_preblock_alert.bat" /f
schtasks /create /tn "SCITBD_PreBlock_1145" /sc daily /st 11:45 ^
  /tr "%ROOT%\run_preblock_alert.bat" /f
schtasks /create /tn "SCITBD_PreBlock_1745" /sc daily /st 17:45 ^
  /tr "%ROOT%\run_preblock_alert.bat" /f
schtasks /create /tn "SCITBD_PreBlock_2345" /sc daily /st 23:45 ^
  /tr "%ROOT%\run_preblock_alert.bat" /f

echo.
echo ============ REGISTERED TASKS ============
schtasks /query /fo table | findstr /i "SCITBD"
