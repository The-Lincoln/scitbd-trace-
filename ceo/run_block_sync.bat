@echo off
REM ============================================================
REM  SCITBD AI CEO - Operational Block Sync (scheduled wrapper)
REM  Runs sync_block_status() every 15 minutes to:
REM    - flip operational_blocks.status to match the BST clock
REM    - auto-materialise today's daily_tasks from block_tasks
REM    - append an audit row to operational_logs
REM
REM  Register with:
REM    schtasks /create /tn "SCITBD_BlockSync" /sc minute /mo 15 ^
REM      /tr "C:\path\to\run_block_sync.bat" /f
REM ============================================================
setlocal
set "PYTHON=C:\Python314\python.exe"
set "SCRIPT=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy\scitbd_block_scheduler.py"
set "LOG=D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy\logs\block_sync.log"

if not exist "D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy\logs" (
    mkdir "D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy\logs"
)

echo ================================================== >> "%LOG%"
echo  RUN %DATE% %TIME% >> "%LOG%"
"%PYTHON%" "%SCRIPT%" sync >> "%LOG%" 2>&1
echo  EXIT %ERRORLEVEL% >> "%LOG%"
endlocal
