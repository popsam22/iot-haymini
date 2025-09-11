-- Migration: Add address, contact_person, email, phone columns to organizations table
-- Run this SQL to update your database schema

-- Step 1: Add new columns to organizations table
ALTER TABLE organizations 
ADD COLUMN address TEXT NULL AFTER description,
ADD COLUMN contact_person VARCHAR(255) NULL AFTER address,
ADD COLUMN email VARCHAR(255) NULL AFTER contact_person,
ADD COLUMN phone VARCHAR(50) NULL AFTER email;

-- Step 2: Update existing Default Organization with sample data
UPDATE organizations 
SET 
    address = '123 Default Street, City, State 12345',
    contact_person = 'System Administrator', 
    email = 'admin@defaultorg.com',
    phone = '+1-555-000-0000'
WHERE name = 'Default Organization';

-- Step 3: Verify the changes
SELECT * FROM organizations;

-- Step 4: Show new table structure
DESCRIBE organizations;
