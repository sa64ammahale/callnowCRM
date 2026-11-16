-- ============================================================
-- Migration: Set Company Name to 'Incredit Solutions'
-- Distinguishes Project Name ('CallNow') from Company Name ('Incredit Solutions')
-- ============================================================

-- 1. Update Master Database Company Catalog
UPDATE `callnow_master`.`callnow_telecalling_companys` 
SET `company_name` = 'Incredit Solutions' 
WHERE `company_code` = 'incredit';

-- 2. Update Tenant Settings
UPDATE `app_settings` 
SET `setting_value` = 'Incredit Solutions' 
WHERE `setting_key` = 'company_name' AND (`setting_value` = 'CallNow' OR `setting_value` = 'Incredit Financial Services');

-- 3. Update Super Admin Account Settings
UPDATE `super_admin_account` 
SET `company_name` = 'Incredit Solutions' 
WHERE `company_name` = 'CallNow' OR `company_name` = 'Incredit Financial Services';

-- 4. Update Existing Users where company was stored as 'CallNow' or old name
UPDATE `users` 
SET `COMPANY` = 'Incredit Solutions' 
WHERE `COMPANY` IN ('CallNow', 'incredit', 'Incredit Financial Services');

UPDATE `users` 
SET `COMPANY_NAME` = 'Incredit Solutions' 
WHERE `COMPANY_NAME` IS NULL 
   OR `COMPANY_NAME` = '' 
   OR `COMPANY_NAME` IN ('CallNow', 'Incredit Financial Services');
