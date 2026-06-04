-- ============================================================
-- CALLNOW CRM V5.00 - Database Setup Script
-- Database: callnow_incredit
-- Author: Sangam Mahale
-- Description: Creates all required tables, indexes,
--              triggers, and default seed data
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- -----------------------------------------------
-- 1. USERS TABLE
-- -----------------------------------------------
DROP TABLE IF EXISTS USERS;

CREATE TABLE USERS (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    NAME VARCHAR(100) NOT NULL,
    MOBILE VARCHAR(15) NOT NULL,
    COMPANY VARCHAR(100) NOT NULL DEFAULT 'CallNow',
    PACKAGE VARCHAR(100) DEFAULT '',
    STATUS ENUM('Active', 'Inactive', 'Suspended') DEFAULT 'Active',
    JOIN_DATE DATETIME DEFAULT CURRENT_TIMESTAMP,
    ROLE ENUM('Admin', 'Manager', 'Supervisor', 'Officer') DEFAULT 'Officer',
    TEAM_ID INT DEFAULT NULL,
    PASSWORD VARCHAR(255) NOT NULL,
    LOGIN_ID VARCHAR(100) NOT NULL UNIQUE,
    DEVICE_ID VARCHAR(255) DEFAULT NULL,
    LAST_LOGIN DATETIME DEFAULT NULL,
    CREATED_AT DATETIME DEFAULT CURRENT_TIMESTAMP,
    UPDATED_AT DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (ROLE),
    INDEX idx_team (TEAM_ID),
    INDEX idx_status (STATUS),
    INDEX idx_company (COMPANY),
    INDEX idx_login_id (LOGIN_ID),
    INDEX idx_mobile (MOBILE),
    INDEX idx_team_role (TEAM_ID, ROLE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------
-- 2. TEAMS TABLE
-- -----------------------------------------------
DROP TABLE IF EXISTS TEAMS;

CREATE TABLE TEAMS (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    NAME VARCHAR(100) NOT NULL UNIQUE,
    SUPERVISOR_ID INT DEFAULT NULL,
    MANAGER_ID INT DEFAULT NULL,
    DESCRIPTION TEXT DEFAULT NULL,
    CREATED_AT DATETIME DEFAULT CURRENT_TIMESTAMP,
    UPDATED_AT DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supervisor (SUPERVISOR_ID),
    INDEX idx_manager (MANAGER_ID),
    FOREIGN KEY (SUPERVISOR_ID) REFERENCES USERS(ID) ON DELETE SET NULL,
    FOREIGN KEY (MANAGER_ID) REFERENCES USERS(ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Add TEAM_ID foreign key to USERS after TEAMS is created
ALTER TABLE USERS ADD CONSTRAINT fk_users_team
    FOREIGN KEY (TEAM_ID) REFERENCES TEAMS(ID) ON DELETE SET NULL;


-- -----------------------------------------------
-- 3. MAIN_DATABASE TABLE
-- -----------------------------------------------
DROP TABLE IF EXISTS MAIN_DATABASE;

CREATE TABLE MAIN_DATABASE (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    MAINDATABASE_NAME VARCHAR(100) NOT NULL,
    MAINDATABASE_MOBILE VARCHAR(15) NOT NULL UNIQUE,
    MAINDATABASE_COMPANY VARCHAR(100) DEFAULT '',
    MAINDATABASE_PACKAGE VARCHAR(100) DEFAULT '',
    MAINDATABASE_OTHER_INFO TEXT DEFAULT NULL,
    MAINDATABASE_CALL_DIALED_STATUS ENUM(
        'Not Called', 'Dialed', 'Connected', 'Busy',
        'No Answer', 'Do Not Call', 'Pending', 'Callback',
        'Interested', 'Follow Up', 'Sale'
    ) DEFAULT 'Not Called',
    MAINDATABASE_CALL_DIALED_USER INT DEFAULT NULL,
    MAINDATABASE_CALL_DIAL_TIME DATETIME DEFAULT NULL,
    MAINDATABASE_UPLOAD_DATETIME DATETIME DEFAULT CURRENT_TIMESTAMP,
    LAST_DIALED_DATE_TIME DATETIME DEFAULT NULL,
    LAST_CONNECTED_PERIOD DATETIME DEFAULT NULL,
    CALL_BY INT DEFAULT NULL,
    CALL_TIME DATETIME DEFAULT NULL,
    INDEX idx_mobile (MAINDATABASE_MOBILE),
    INDEX idx_status (MAINDATABASE_CALL_DIALED_STATUS),
    INDEX idx_agent (MAINDATABASE_CALL_DIALED_USER),
    INDEX idx_upload_dt (MAINDATABASE_UPLOAD_DATETIME),
    INDEX idx_last_dialed (LAST_DIALED_DATE_TIME),
    INDEX idx_call_by (CALL_BY),
    FULLTEXT idx_search (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_OTHER_INFO)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------
-- 4. TEMPORARY_DATABASE TABLE
-- -----------------------------------------------
DROP TABLE IF EXISTS TEMPORARY_DATABASE;

CREATE TABLE TEMPORARY_DATABASE (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    CUST_NAME VARCHAR(100) NOT NULL,
    CUST_MOBILE VARCHAR(15) NOT NULL UNIQUE,
    CUST_COMPANY VARCHAR(100) DEFAULT '',
    CUST_PACKAGE VARCHAR(100) DEFAULT '',
    CUST_OTHER_INFO TEXT DEFAULT NULL,
    TEMP_UPLOAD_DATETIME DATETIME DEFAULT CURRENT_TIMESTAMP,
    CALL_DIALED_STATUS ENUM(
        'Not Called', 'Dialed', 'Connected', 'Busy',
        'No Answer', 'Do Not Call', 'Pending', 'Callback',
        'Interested', 'Follow Up', 'Sale'
    ) DEFAULT 'Not Called',
    CALL_DIALED_TELECALLER INT DEFAULT NULL,
    LAST_DIALED_DATE_TIME DATETIME DEFAULT NULL,
    LAST_CONNECTED_PERIOD DATETIME DEFAULT NULL,
    SOURCE VARCHAR(100) DEFAULT 'Upload',
    NOTES TEXT DEFAULT NULL,
    INDEX idx_cust_mobile (CUST_MOBILE),
    INDEX idx_dial_status (CALL_DIALED_STATUS),
    INDEX idx_telecaller (CALL_DIALED_TELECALLER),
    INDEX idx_upload_dt (TEMP_UPLOAD_DATETIME),
    INDEX idx_last_dialed (LAST_DIALED_DATE_TIME),
    FULLTEXT idx_search (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_OTHER_INFO)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------
-- 5. LEADS_TABLE TABLE  (lead_id auto-increment reset fix)
-- -----------------------------------------------
DROP TABLE IF EXISTS LEADS_TABLE;

CREATE TABLE LEADS_TABLE (
    lead_id INT AUTO_INCREMENT PRIMARY KEY,
    cust_id INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    assigned_by INT DEFAULT NULL,
    lead_status ENUM('New', 'In_Progress', 'Follow_Up', 'Converted', 'Lost') DEFAULT 'New',
    lead_stage ENUM('Hot', 'Warm', 'Cold') DEFAULT 'Cold',
    priority ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    next_followup_at DATETIME DEFAULT NULL,
    followup_count INT DEFAULT 0,
    last_contacted_at DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    converted_at DATETIME DEFAULT NULL,
    lost_reason VARCHAR(255) DEFAULT NULL,
    INDEX idx_cust_id (cust_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_lead_status (lead_status),
    INDEX idx_lead_stage (lead_stage),
    INDEX idx_priority (priority),
    INDEX idx_next_followup (next_followup_at),
    INDEX idx_created_at (created_at),
    INDEX idx_assigned_status (assigned_to, lead_status),
    FULLTEXT idx_remarks (remarks),
    FOREIGN KEY (cust_id) REFERENCES MAIN_DATABASE(ID) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES USERS(ID) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES USERS(ID) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES USERS(ID) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES USERS(ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------
-- 6. ACTIVITY_LOG TABLE
-- -----------------------------------------------
DROP TABLE IF EXISTS ACTIVITY_LOG;

CREATE TABLE ACTIVITY_LOG (
    LOG_ID INT AUTO_INCREMENT PRIMARY KEY,
    USER_ID INT NOT NULL,
    ACTION_TYPE ENUM(
        'INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT',
        'UPLOAD', 'TRANSFER', 'EXPORT', 'ASSIGN', 'STATUS_CHANGE',
        'REMARK', 'BULK_DELETE', 'BULK_UPDATE', 'TEAM_CHANGE'
    ) NOT NULL,
    ACTION_DETAILS TEXT DEFAULT NULL,
    AFFECTED_IDS VARCHAR(500) DEFAULT NULL,
    TARGET_TABLE VARCHAR(100) DEFAULT NULL,
    LOG_TIME DATETIME DEFAULT CURRENT_TIMESTAMP,
    IP_ADDRESS VARCHAR(45) DEFAULT NULL,
    USER_AGENT VARCHAR(500) DEFAULT NULL,
    INDEX idx_user_id (USER_ID),
    INDEX idx_action_type (ACTION_TYPE),
    INDEX idx_target_table (TARGET_TABLE),
    INDEX idx_log_time (LOG_TIME),
    INDEX idx_affected_ids (AFFECTED_IDS(255)),
    INDEX idx_user_time (USER_ID, LOG_TIME),
    FOREIGN KEY (USER_ID) REFERENCES USERS(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------
-- 7. MAIN_DATABASE_archive TABLE (for archive feature)
-- Note: Created manually to allow duplicate mobiles and proper archive_id PK
DROP TABLE IF EXISTS MAIN_DATABASE_archive;

CREATE TABLE MAIN_DATABASE_archive (
    archive_id INT AUTO_INCREMENT PRIMARY KEY,
    ID INT NOT NULL,
    MAINDATABASE_NAME VARCHAR(100) NOT NULL,
    MAINDATABASE_MOBILE VARCHAR(15) NOT NULL,
    MAINDATABASE_COMPANY VARCHAR(100) DEFAULT '',
    MAINDATABASE_PACKAGE VARCHAR(100) DEFAULT '',
    MAINDATABASE_OTHER_INFO TEXT DEFAULT NULL,
    MAINDATABASE_CALL_DIALED_STATUS ENUM(
        'Not Called', 'Dialed', 'Connected', 'Busy',
        'No Answer', 'Do Not Call', 'Pending', 'Callback',
        'Interested', 'Follow Up', 'Sale'
    ) DEFAULT 'Not Called',
    MAINDATABASE_CALL_DIALED_USER INT DEFAULT NULL,
    MAINDATABASE_CALL_DIAL_TIME DATETIME DEFAULT NULL,
    MAINDATABASE_UPLOAD_DATETIME DATETIME DEFAULT CURRENT_TIMESTAMP,
    LAST_DIALED_DATE_TIME DATETIME DEFAULT NULL,
    LAST_CONNECTED_PERIOD DATETIME DEFAULT NULL,
    CALL_BY INT DEFAULT NULL,
    CALL_TIME DATETIME DEFAULT NULL,
    archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    archived_by INT DEFAULT NULL,
    archive_reason VARCHAR(255) DEFAULT NULL,
    INDEX idx_original_id (ID),
    INDEX idx_mobile (MAINDATABASE_MOBILE),
    INDEX idx_archived_at (archived_at),
    INDEX idx_archived_by (archived_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------
-- 8. TRIGGERS
--    Auto-update USERS.updated_at on any change
-- -------------------------------------------

-- (Trigger is handled by the ON UPDATE CURRENT_TIMESTAMP
--  column definition; no additional trigger needed.)

-- -----------------------------------------------
-- 9. DEFAULT SEED DATA
-- -----------------------------------------------

-- Insert default Admin user
-- Password: Admin@123  (change immediately in production)
INSERT INTO USERS (NAME, MOBILE, COMPANY, PACKAGE, STATUS, ROLE, PASSWORD, LOGIN_ID)
VALUES (
    'System Administrator',
    '9999999999',
    'CallNow',
    'Premium',
    'Active',
    'Admin',
    '$2y$10$tS5fdeWE6YpuLDw5kYCo1uq4.sOLF.wH5AiioOlFh/g5kYkojEOh2',
    'admin@callnow.com'
);


-- Insert default Team
INSERT INTO TEAMS (NAME, DESCRIPTION)
VALUES ('Default Team', 'Default team for initial setup');


-- Insert sample data for testing
INSERT INTO USERS (NAME, MOBILE, COMPANY, PACKAGE, STATUS, ROLE, TEAM_ID, PASSWORD, LOGIN_ID)
VALUES
    ('Rahul Sharma',     '9876543210', 'CallNow', 'Basic',    'Active',   'Manager',   1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rahul@callnow.com'),
    ('Priya Patel',      '9876543211', 'CallNow', 'Basic',    'Active',   'Supervisor', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'priya@callnow.com'),
    ('Amit Kumar',       '9876543212', 'CallNow', 'Basic',    'Active',   'Officer',    1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'amit@callnow.com'),
    ('Sneha Reddy',      '9876543213', 'CallNow', 'Basic',    'Active',   'Officer',    1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sneha@callnow.com'),
    ('Vikram Singh',     '9876543214', 'CallNow', 'Basic',    'Inactive', 'Officer',    1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'vikram@callnow.com');


-- Insert sample data for MAIN_DATABASE
INSERT INTO MAIN_DATABASE (
    MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY,
    MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO,
    MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER,
    MAINDATABASE_CALL_DIAL_TIME, MAINDATABASE_UPLOAD_DATETIME,
    LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, CALL_BY, CALL_TIME
)
VALUES
    ('Rajesh Gupta',     '9988776655', 'TechSol Pvt Ltd', 'Premium', 'Interested in Enterprise plan',     'Connected',   3, NOW(), NOW(), NOW(), NOW(), 3, NOW()),
    ('Anita Joshi',      '9988776656', 'WebPro Solutions', 'Basic',   'Needs follow-up next week',          'Follow Up',   3, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 3, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    ('Karan Mehta',      '9988776657', 'DataFlow Inc',    'Premium', 'Callback requested on 15th',        'Callback',    3, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 2 DAY), NULL, 3, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    ('Pooja Nair',       '9988776658', 'CloudNine',       'Basic',   'Not interested - mark as lost',     'Lost',        4, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, 4, DATE_SUB(NOW(), INTERVAL 3 DAY)),
    ('Sanjay Verma',     '9988776659', 'NetSpeed Ltd',    'Premium', 'Busy - try again after 3 PM',      'Busy',        4, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL, 4, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    ('Meera Iyer',       '9988776660', 'AlphaTech',       'Basic',   'Do Not Call - requested',          'Do Not Call', 3, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 4 DAY), NULL, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
    ('Arjun Reddy',      '9988776661', 'BetaSoft',        'Premium', 'New lead - not contacted yet',      'Not Called',  NULL, NULL, NOW(), NULL, NULL, NULL, NULL),
    ('Kavita Shah',      '9988776662', 'GammaCorp',       'Basic',   'Connected - needs demo',            'Connected',   4, NOW(), NOW(), NOW(), NOW(), 4, NOW()),
    ('Rohan Das',        '9988776663', 'DeltaWave',       'Premium', 'No Answer - will retry',            'No Answer',   4, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL, 4, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    ('Nisha Kapoor',     '9988776664', 'EpsilonLabs',    'Basic',   'Pending - awaiting approval',       'Pending',     NULL, NULL, NOW(), NULL, NULL, NULL, NULL),
    ('Deepak Rao',       '9988776665', 'ZetaSystems',    'Premium', 'Dialed - no response',              'Dialed',      3, NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 6 HOUR), NULL, 3, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    ('Simran Kaur',      '9988776666', 'EtaGroup',        'Basic',   'Hot lead - urgent follow-up',       'Interested',  3, NOW(), NOW(), NOW(), NOW(), 3, NOW()),
    ('Manish Tiwari',    '9988776667', 'ThetaNetworks',  'Premium', 'Sale closed - ₹50,000 deal',        'Sale',        4, NOW(), NOW(), NOW(), NOW(), 4, NOW()),
    ('Divya Menon',      '9988776668', 'IotaServices',   'Basic',   'Not Called - fresh upload',         'Not Called',  NULL, NULL, NOW(), NULL, NULL, NULL, NULL),
    ('Suresh Babu',      '9988776669', 'KappaDigital',   'Premium', 'Connected - sending proposal',      'Connected',   3, NOW(), NOW(), NOW(), NOW(), 3, NOW());


-- Insert sample data for TEMPORARY_DATABASE
INSERT INTO TEMPORARY_DATABASE (
    CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE,
    CUST_OTHER_INFO, CALL_DIALED_STATUS, CALL_DIALED_TELECALLER,
    LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, SOURCE, NOTES
)
VALUES
    ('Rohit Sharma',    '9876543201', 'FreshLead Co',   'Basic',   'Uploaded from latest campaign',        'Not Called', NULL, NULL, NULL,         'Campaign_Jan2026', 'New upload - pending dial'),
    ('Neha Kapoor',     '9876543202', 'QuickServe',     'Premium', 'Spoke with decision maker',            'Connected',  4,  NOW(), NOW(),               'Manual Entry',     'Very interested'),
    ('Tarun Aggarwal',  '9876543203', 'RapidTech',      'Basic',   'Left voicemail - callback later',       'No Answer',  4,  DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, 'Campaign_Jan2026', 'Voicemail left'),
    ('Isha Malhotra',   '9876543204', 'SmartSol',       'Premium', 'Busy - follow up tomorrow',            'Busy',       4,  DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL, 'Referral',        'Ref: Amit Kumar'),
    ('Gaurav Joshi',    '9876543205', 'NexGen',         'Basic',   'DNC requested by customer',            'Do Not Call', 3, NOW(), NULL,                'Web Form',        'Customer asked to be removed'),
    ('Bhavya Singh',    '9876543206', 'PeakPerformers', 'Premium', 'Follow-up scheduled for next Friday',  'Follow Up',  3,  DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'Campaign_Jan2026', 'Next follow-up: 2026-01-10'),
    ('Riya Thakur',     '9876543207', 'ApexGroups',     'Basic',   'New - first call pending',             'Not Called', NULL, NULL, NULL,            'Campaign_Jan2026', 'Fresh upload'),
    ('Kunal Oberoi',    '9876543208', 'SummitWave',     'Premium', 'Interested - needs pricing',           'Interested', 4,  NOW(), NOW(),               'Cold Call',       'Sent proposal email'),
    ('Tanya Bose',      '9876543209', 'VerveDigital',   'Basic',   'Not Reachable - wrong number?',        'No Answer',  4,  DATE_SUB(NOW(), INTERVAL 4 HOUR), NULL, 'Campaign_Jan2026', 'Verify number'),
    ('Aditya Pillai',   '9876543210', 'CrestBridge',    'Premium', 'Connected - demo scheduled',            'Connected',  3,  NOW(), NOW(),               'Referral',        'Demo on Monday'),
    ('Priyanka Das',    '9876543211', 'OrbitLabs',      'Basic',   'Pending - legal review',                'Pending',    NULL, NULL, NULL,              'Web Form',        'Awaiting legal sign-off'),
    ('Harsh Vardhan',   '9876543212', 'NovaTech Solutions', 'Premium', 'Sale closed - contract signed',       'Sale',       3,  NOW(), NOW(),               'Trade Show',      'Contract signed, ₹75K deal'),
    ('Sakshi Malhotra', '9876543213', 'PrimeEdge',      'Basic',   'Dialed - no response yet',             'Dialed',     4,  DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL, 'Campaign_Jan2026', 'Retry in evening'),
    ('Yash Agarwal',    '9876543214', 'ZenithCorp',     'Premium', 'Fresh lead - no contact made',         'Not Called', NULL, NULL, NULL,              'Campaign_Jan2026', 'Queued for tomorrow'),
    ('Pallavi Reddy',   '9876543215', 'AtlasGroup',     'Basic',   'Callback after 6 PM',                  'Callback',   3,  NOW(), NOW(),               'Cold Call',       'Customer requested callback');


-- Insert sample LEADS_TABLE entries
INSERT INTO LEADS_TABLE (
    cust_id, assigned_to, assigned_by, lead_status, lead_stage,
    priority, created_by, next_followup_at, followup_count,
    last_contacted_at, remarks, converted_at
)
VALUES
    (1,  3, 1, 'Hot',       'Hot',    'High',   1, DATE_ADD(NOW(), INTERVAL 2 DAY), 3,  NOW(), 'Very interested - sent proposal',    NOW()),
    (2,  3, 1, 'In_Progress', 'Warm', 'Medium', 1, DATE_ADD(NOW(), INTERVAL 1 DAY), 2,  NOW(), 'Needs follow-up next week',           NULL),
    (3,  3, 1, 'Cold',      'Cold',   'Low',    1, NULL,                             0,  NULL, 'Callback requested - low priority',   NULL),
    (4,  4, 1, 'Hot',       'Hot',    'High',   1, DATE_ADD(NOW(), INTERVAL 1 DAY), 1,  NOW(), 'Needs demo scheduling',               NULL),
    (5,  4, 1, 'Follow_Up', 'Warm',   'Medium', 1, DATE_ADD(NOW(), INTERVAL 3 DAY), 4,  NOW(), 'Busy - retry after 3 PM',             NULL),
    (6,  3, 1, 'Converted', 'Hot',    'High',   1, NULL,                             6,  NOW(), 'Sale closed - ₹60K deal',            NOW()),
    (7,  4, 1, 'New',       'Cold',   'Low',    1, NULL,                             0,  NULL, 'Fresh lead - not contacted',          NULL),
    (8,  3, 1, 'Lost',      'Cold',   'Low',    1, NULL,                             1,  NOW(), 'Not interested - budget constraints', NOW());


-- Insert sample ACTIVITY_LOG entries
INSERT INTO ACTIVITY_LOG (USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, IP_ADDRESS)
VALUES
    (1, 'LOGIN',       'Admin logged in',                  '1',          'USERS',      '127.0.0.1'),
    (1, 'INSERT',      'Created user Rahul Sharma',         '2',          'USERS',      '127.0.0.1'),
    (1, 'INSERT',      'Created team Default Team',         '1',          'TEAMS',      '127.0.0.1'),
    (3, 'LOGIN',       'Manager Rahul logged in',           '2',          'USERS',      '127.0.0.1'),
    (3, 'INSERT',      'Inserted lead Rajesh Gupta',        '1',          'LEADS_TABLE','127.0.0.1'),
    (3, 'UPDATE',      'Updated status to Connected',       '1',          'LEADS_TABLE','127.0.0.1'),
    (4, 'LOGIN',       'Officer Sneha logged in',           '4',          'USERS',      '127.0.0.1'),
    (4, 'INSERT',      'Inserted into TEMPORARY_DATABASE',  '16',         'TEMPORARY_DATABASE', '127.0.0.1'),
    (3, 'TRANSFER',    'Transferred leads to MAIN_DATABASE','1,2,3',      'MAIN_DATABASE', '127.0.0.1'),
    (1, 'UPLOAD',      'CSV bulk upload completed',         '11-25',      'MAIN_DATABASE',  '127.0.0.1');


-- -----------------------------------------------
-- 10. POST-INSTALL NOTES
-- -----------------------------------------------
-- Default login credentials:
--   Company Name : CallNow
--   Email        : admin@callnow.com
--   Password     : Admin@123
--
--  *** CHANGE THE DEFAULT PASSWORD IMMEDIATELY AFTER LOGIN ***
--
--  To switch to production credentials, set environment variables:
--    set DB_SERVERNAME=your_host
--    set DB_USERNAME=your_user
--    set DB_PASSWORD=your_pass
--    set DB_NAME=your_db
--
--  Or update config.php with actual values.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;
