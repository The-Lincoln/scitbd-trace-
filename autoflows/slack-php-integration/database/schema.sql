-- SCITBD Slack Integration Database Schema
-- MySQL / MariaDB compatible

CREATE DATABASE IF NOT EXISTS slack_integration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE slack_integration;

-- Table for storing Slack messages
CREATE TABLE IF NOT EXISTS slack_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id VARCHAR(255) NOT NULL,
    channel_name VARCHAR(100),
    user_id VARCHAR(255),
    user_name VARCHAR(100),
    message_text TEXT,
    blocks JSON,
    attachments JSON,
    thread_ts VARCHAR(50),
    response_url VARCHAR(500),
    is_processed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_channel (channel_id),
    INDEX idx_user (user_id),
    INDEX idx_thread (thread_ts),
    INDEX idx_processed (is_processed),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for storing webhook events
CREATE TABLE IF NOT EXISTS slack_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    event_data JSON NOT NULL,
    channel_id VARCHAR(255),
    user_id VARCHAR(255),
    ts VARCHAR(50),
    is_processed TINYINT(1) DEFAULT 0,
    signature_verified TINYINT(1) DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_channel (channel_id),
    INDEX idx_processed (is_processed),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for deployment notifications
CREATE TABLE IF NOT EXISTS slack_deployments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    build_id VARCHAR(100) NOT NULL,
    branch VARCHAR(100) NOT NULL,
    status ENUM('started', 'success', 'failed', 'rolled_back') NOT NULL,
    deployer VARCHAR(255),
    channel_id VARCHAR(255),
    error_message TEXT,
    metadata JSON,
    slack_ts VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_build (build_id),
    INDEX idx_status (status),
    INDEX idx_branch (branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for bug reports
CREATE TABLE IF NOT EXISTS slack_bugs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id VARCHAR(100) NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    reporter VARCHAR(255),
    channel_id VARCHAR(255),
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    slack_ts VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ticket_id (ticket_id),
    INDEX idx_priority (priority),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for channel registry
CREATE TABLE IF NOT EXISTS slack_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slack_channel_id VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('public', 'private') NOT NULL,
    is_member TINYINT(1) DEFAULT 1,
    num_members INT DEFAULT 0,
    purpose TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_slack_id (slack_channel_id),
    INDEX idx_name (name),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for task assignments
CREATE TABLE IF NOT EXISTS slack_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_text TEXT NOT NULL,
    assigned_to VARCHAR(255),
    channel_id VARCHAR(255),
    status ENUM('pending', 'accepted', 'deferred', 'completed') DEFAULT 'pending',
    due_date DATETIME,
    slack_ts VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel (channel_id),
    INDEX idx_status (status),
    INDEX idx_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for logs
CREATE TABLE IF NOT EXISTS slack_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL,
    channel VARCHAR(255),
    message TEXT,
    context JSON,
    slack_event_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER //
CREATE TRIGGER trg_slack_messages_updated
    AFTER UPDATE ON slack_messages
    FOR EACH ROW
    BEGIN
        UPDATE slack_messages SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END//
DELIMITER ;
