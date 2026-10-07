@echo off
REM SCITBD agent-browser installer (Windows)
REM Installs CLI + Chrome-for-Testing + migrates all SQLite DBs.
npm install -g agent-browser
agent-browser install
php "%~dp0install_agent_browser.php"
pause
