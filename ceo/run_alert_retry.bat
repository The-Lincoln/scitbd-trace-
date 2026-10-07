@echo off
REM ============================================================
REM  SCITBD AI CEO - Alert Retry Worker (scheduled wrapper)
REM  Processes due retries with exponential backoff (60s/5m/15m)
REM  and reaps stale IN_FLIGHT jobs. Run every minute.
REM
REM    schtasks /create /tn "SCITBD_AlertRetry" /sc minute /mo 1 ^
REM      /tr "D:\...\run_alert_retry.bat" /f
REM ============================================================
setlocal
set "PYTHON=C:\Python314\python.exe"
set "ROOT=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy"
set "LOG=%ROOT%\logs\alert_retry.log"

if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"
"%PYTHON%" "%ROOT%\scitbd_alerting.py" retry >> "%LOG%" 2>&1
endlocal
