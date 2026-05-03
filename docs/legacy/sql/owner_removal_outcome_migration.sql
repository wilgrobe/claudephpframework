-- database/owner_removal_outcome_migration.sql
--
-- Adds a new_role_id column to group_owner_removal_requests. Stores what
-- should happen to the target user when a removal request is approved:
--
--   new_role_id IS NULL      → remove from group entirely (delete user_groups row)
--   new_role_id IS NOT NULL  → switch them to that group_roles.id
--
-- The requester picks the outcome at the time they file the removal request.
-- The target owner sees that outcome on the approval confirmation page so
-- they know exactly what approving entails.
--
-- Run ONCE:
--     mysql -u root -p phpframework < owner_removal_outcome_migration.sql

ALTER TABLE group_owner_removal_requests
    ADD COLUMN new_role_id INT UNSIGNED NULL AFTER target_user_id,
    ADD CONSTRAINT fk_owner_removal_new_role
        FOREIGN KEY (new_role_id) REFERENCES group_roles(id) ON DELETE SET NULL;
