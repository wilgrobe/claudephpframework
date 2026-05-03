-- database/unescape_user_data_migration.sql
--
-- One-time cleanup after fixing the Validator double-escape bug.
--
-- Any user-supplied text field that went through Validator (group names,
-- user first/last names, content titles/bodies, bios, etc.) would have had
-- special characters HTML-encoded on save:  '  →  &apos;   etc. Views then
-- escaped again on render, producing visible `&apos;` in the browser.
--
-- Now that the Validator no longer HTML-encodes on input, we still have a
-- backlog of rows with encoded entities baked in. This script reverses the
-- common encodings across the tables that historically received sanitized
-- input.
--
-- Run ONCE after deploying the Validator fix:
--     mysql -u root -p phpframework < unescape_user_data_migration.sql
--
-- Safe to re-run (REPLACE on a string with no matching needle is a no-op).

-- Helper trick: we wrap each column in a chain of REPLACE calls. Order
-- matters: &amp; must be last, otherwise it would re-expand every other
-- entity. &#039; is the ENT_HTML5-style numeric entity for apostrophe;
-- &apos; is the named form — both surfaced at different moments, both are
-- cleaned up.

-- groups
UPDATE `groups`
   SET name        = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name,       '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       `slug`      = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`slug`,     '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description,'&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&');

-- users (first_name, last_name, bio, username)
UPDATE users
   SET first_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(first_name,'&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       last_name  = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(last_name, '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       bio        = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(bio,        '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       username   = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(username,   '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&');

-- content_items
UPDATE content_items
   SET title           = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(title,           '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       `slug`          = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`slug`,          '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       seo_title       = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(seo_title,       '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       seo_description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(seo_description, '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       seo_keywords    = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(seo_keywords,    '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&');
-- NB: content_items.body is stored as HTML (allow-listed tags from sanitizeHtml),
--     NOT as encoded text, so leave that column alone.

-- group_roles
UPDATE group_roles
   SET name        = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name,        '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       `slug`      = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`slug`,      '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
       description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description, '&apos;', ''''), '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&');
