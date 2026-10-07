#!/usr/bin/env bash
# SCITBD agent-browser installer (Linux/macOS)
set -e
npm install -g agent-browser
agent-browser install --with-deps || agent-browser install
php "$(dirname "$0")/install_agent_browser.php"
