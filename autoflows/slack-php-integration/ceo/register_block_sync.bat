@echo off
REM Registers the SCITBD block-sync scheduled task (run once).
schtasks /create /tn "SCITBD_BlockSync" /sc minute /mo 15 /tr "D:\CRM_with_GOOGLE_Sheet\ceo\ceo - Copy\run_block_sync.bat" /f
echo.
echo --- query ---
schtasks /query /tn "SCITBD_BlockSync" /fo list /v | findstr /i "TaskName Status Next Run Schedule Task To Run"
