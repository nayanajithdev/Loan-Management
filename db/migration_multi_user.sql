-- Run once on old installations to align roles with current multi-user model.
USE loan_management;

ALTER TABLE users
MODIFY COLUMN role ENUM('superadmin', 'admin', 'collector', 'collector_l1', 'collector_l2')
NOT NULL DEFAULT 'admin';

-- Map legacy collector role to Collector L2.
UPDATE users
SET role = 'collector_l2'
WHERE role = 'collector';

-- If no owner exists, promote the default admin username as first owner.
SET @owner_count := (SELECT COUNT(*) FROM users WHERE role = 'superadmin');
UPDATE users
SET role = 'superadmin'
WHERE username = 'admin'
  AND @owner_count = 0
LIMIT 1;
