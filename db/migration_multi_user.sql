-- Run this once on existing installations to enable multi-user roles
USE loan_management;

ALTER TABLE users
ADD COLUMN role ENUM('superadmin', 'admin', 'collector') NOT NULL DEFAULT 'admin' AFTER password_hash;

-- Set one existing account as first superadmin (adjust username if needed)
UPDATE users
SET role = 'superadmin'
WHERE username = 'admin'
LIMIT 1;