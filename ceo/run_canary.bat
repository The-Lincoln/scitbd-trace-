@echo off
REM ============================================================
REM  SCITBD AI CEO - Channel Canary Probe (scheduled wrapper)
REM  Probes DOWN channels every 30 min to move them into
REM  probation DEGRADED so intelligent re-ordering can self-heal.
REM
REM    schtasks /create /tn "SCITBD_Canary" /sc minute /mo 30 ^
REM      /tr "D:\...\run_canary.bat" /f
REM ============================================================
setlocal
set "PYTHON=C:\Python314\python.exe"
set "ROOT=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy"
set "LOG=%ROOT%\logs\canary.log"

if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"
"%PYTHON%" "%ROOT%\scitbd_alerting.py" canary >> "%LOG%" 2>&1
endlocal
