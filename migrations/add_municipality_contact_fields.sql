-- Migration: Add contact information fields to municipalities table
-- This centralizes contact info for use across topbar, footer, and all pages
-- Run with: railway run psql -f migrations/add_municipality_contact_fields.sql

-- Add contact_phone column if not exists
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'municipalities' AND column_name = 'contact_phone') THEN
        ALTER TABLE municipalities ADD COLUMN contact_phone VARCHAR(50) DEFAULT '(046) 886-4454';
        RAISE NOTICE 'Added contact_phone column';
    ELSE
        RAISE NOTICE 'contact_phone column already exists';
    END IF;
END $$;

-- Add contact_email column if not exists
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'municipalities' AND column_name = 'contact_email') THEN
        ALTER TABLE municipalities ADD COLUMN contact_email VARCHAR(100) DEFAULT 'educaid@generaltrias.gov.ph';
        RAISE NOTICE 'Added contact_email column';
    ELSE
        RAISE NOTICE 'contact_email column already exists';
    END IF;
END $$;

-- Add contact_address column if not exists
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'municipalities' AND column_name = 'contact_address') THEN
        ALTER TABLE municipalities ADD COLUMN contact_address TEXT DEFAULT 'General Trias City Hall, Cavite';
        RAISE NOTICE 'Added contact_address column';
    ELSE
        RAISE NOTICE 'contact_address column already exists';
    END IF;
END $$;

-- Add office_hours column if not exists
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'municipalities' AND column_name = 'office_hours') THEN
        ALTER TABLE municipalities ADD COLUMN office_hours VARCHAR(100) DEFAULT 'Mon–Fri 8:00AM - 5:00PM';
        RAISE NOTICE 'Added office_hours column';
    ELSE
        RAISE NOTICE 'office_hours column already exists';
    END IF;
END $$;

-- Verify the columns were added
SELECT column_name, data_type, column_default 
FROM information_schema.columns 
WHERE table_name = 'municipalities' 
AND column_name IN ('contact_phone', 'contact_email', 'contact_address', 'office_hours')
ORDER BY column_name;

-- Show current data
SELECT municipality_id, name, contact_phone, contact_email, contact_address, office_hours 
FROM municipalities 
ORDER BY municipality_id;
