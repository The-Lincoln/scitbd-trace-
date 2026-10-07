-- Leads & Pipeline Tracking
CREATE TABLE IF NOT EXISTS leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_name TEXT NOT NULL,
    email TEXT NOT NULL,
    country TEXT NOT NULL,
    deal_value REAL DEFAULT 0.0,
    service_line TEXT NOT NULL,
    status TEXT CHECK(status IN ('new', 'proposal_sent', 'won', 'lost')) DEFAULT 'new',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 24-Hour BST Operational Blocks
CREATE TABLE IF NOT EXISTS operational_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    block_name TEXT NOT NULL,
    bst_start TEXT NOT NULL,
    bst_end TEXT NOT NULL,
    utc_start TEXT NOT NULL,
    utc_end TEXT NOT NULL,
    regional_focus TEXT NOT NULL,
    core_execution_focus TEXT NOT NULL,
    status TEXT CHECK(status IN ('ACTIVE', 'INACTIVE', 'UPCOMING', 'COMPLETED')) DEFAULT 'INACTIVE'
);

-- Operational Logs with Block Reference
CREATE TABLE IF NOT EXISTS operational_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    block_id INTEGER,
    block_name TEXT NOT NULL,
    action_taken TEXT NOT NULL,
    status TEXT CHECK(status IN ('SUCCESS', 'PENDING', 'FAILED')) DEFAULT 'SUCCESS',
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (block_id) REFERENCES operational_blocks(id)
);

-- Support Tickets & Escalation SLA Tracking
CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_name TEXT NOT NULL,
    issue_description TEXT NOT NULL,
    nps_score INTEGER DEFAULT 100,
    sla_status TEXT CHECK(sla_status IN ('WITHIN_SLA', 'ESCALATED_TO_CEO')) DEFAULT 'WITHIN_SLA',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed the 4 BST Operational Blocks
INSERT OR IGNORE INTO operational_blocks (id, block_name, bst_start, bst_end, utc_start, utc_end, regional_focus, core_execution_focus, status) VALUES
(1, 'Block 1: 06:00 – 12:00 BST | BD / South Asia', '06:00', '12:00', '00:00', '06:00', 'BD / South Asia', 'SEO, Local Tenders & BD', 'INACTIVE'),
(2, 'Block 2: 12:00 – 18:00 BST | Middle East / EU', '12:00', '18:00', '06:00', '12:00', 'Middle East / EU', 'ME & EU Sales Outreach', 'INACTIVE'),
(3, 'Block 3: 18:00 – 00:00 BST | UK / US East Coast', '18:00', '00:00', '12:00', '18:00', 'UK / US East Coast', 'North America Peak Launch', 'INACTIVE'),
(4, 'Block 4: 00:00 – 06:00 BST | US West / Oceania', '00:00', '06:00', '18:00', '24:00', 'US West / Oceania', 'Night Analytics & Reboot', 'INACTIVE');
