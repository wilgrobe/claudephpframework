-- database/webhook_channel_migration.sql
--
-- Expands message_log.channel ENUM to include 'webhook' so outbound HTTP
-- integrations can share the same logging/retry plumbing as email and SMS.
--
-- Run ONCE:
--     mysql -u root -p phpframework < webhook_channel_migration.sql
--
-- Re-run behavior: ALTER TABLE MODIFY is idempotent — running it again with
-- the same ENUM definition is a no-op.

ALTER TABLE message_log
    MODIFY COLUMN `channel` ENUM('email','sms','webhook') NOT NULL;
