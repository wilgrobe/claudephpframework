-- database/message_retry_migration.sql
--
-- Adds retry bookkeeping columns to message_log.
--
-- Run ONCE against your `phpframework` database:
--     mysql -u root -p phpframework < message_retry_migration.sql
-- or paste into your GUI client with `phpframework` selected as the default DB.
--
-- Re-run behavior: if the columns/index already exist you will see errors like
--     ERROR 1060 (42S21): Duplicate column name 'attempts'
--     ERROR 1061 (42000): Duplicate key name 'idx_retry_queue'
-- These are BENIGN. They just mean the migration was already applied. Subsequent
-- statements will continue executing normally.
--
-- The previous version of this migration used a SET/IF/PREPARE idempotency shim
-- that didn't survive GUI clients which reset @-variables between statements.
-- This version prefers "fails loudly on re-run" over "tries to be clever".

ALTER TABLE message_log
    ADD COLUMN attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `error`,
    ADD COLUMN max_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER attempts,
    ADD COLUMN next_attempt_at   TIMESTAMP NULL DEFAULT NULL          AFTER max_attempts,
    ADD COLUMN last_attempted_at TIMESTAMP NULL DEFAULT NULL          AFTER next_attempt_at;

ALTER TABLE message_log
    ADD INDEX idx_retry_queue (`status`, next_attempt_at);

-- Backfill: make any existing failed rows eligible for the retry worker.
-- attempts stays at 0 so they get the full retry budget.
UPDATE message_log
   SET next_attempt_at = NOW()
 WHERE status = 'failed'
   AND next_attempt_at IS NULL;
