<?php
// database/migrations/2026_05_02_200000_seed_default_policy_pages.php
use Core\Database\Migration;
use Core\Support\Markdown;

/**
 * Seed three default policy pages — Terms of Service, Privacy Policy,
 * and Cookie Policy — so a fresh install has compliance-ready boilerplate
 * available at standard slugs out of the box. Admins are expected to
 * review and customise each before going live (each policy ships with a
 * prominent template warning at the top).
 *
 * Source files live at:
 *   database/seeds/policies/terms-of-service.md
 *   database/seeds/policies/privacy-policy.md
 *   database/seeds/policies/cookie-policy.md
 *
 * Markdown is converted to HTML at insert time via Core\Support\Markdown
 * — admins edit the rendered HTML thereafter via /admin/pages, so the
 * markdown source files are only the canonical seed content (not a live
 * source-of-truth that overrides admin edits on subsequent migration runs).
 *
 * Idempotency: each row is created via INSERT IGNORE on the slug. If a
 * page already exists at /terms, /privacy, or /cookie-policy — whether
 * from this seed, an admin's manual creation, or a re-run of the
 * migration — we leave it alone. Admin edits are never clobbered.
 *
 * Rollback: down() removes the rows ONLY when their body still matches
 * what the seed created (we hash the original HTML at insert time and
 * stamp the hash into the seo_keywords column under a sentinel prefix).
 * If an admin edited the body in /admin/pages, the hash diverges and
 * we leave the row alone. This protects admin work from a careless
 * `migrate:rollback`.
 */
return new class extends Migration {
    /**
     * Sentinel prefix marking a row as "this was created by the seed
     * migration; the hash that follows is of the original HTML body".
     * Stored in seo_keywords because that column is otherwise free-form
     * and rarely used on policy pages. Admins are free to overwrite it
     * — doing so detaches the row from rollback eligibility, which is
     * the desired behaviour (we shouldn't auto-delete a page an admin
     * has been curating).
     */
    private const SEED_MARKER = 'cphpfw:policy-seed:';

    /**
     * @return array<int, array{slug: string, title: string, source: string, seo_description: string}>
     */
    private function pages(): array
    {
        return [
            [
                'slug'            => 'terms',
                'title'           => 'Terms of Service',
                'source'          => 'terms-of-service.md',
                'seo_description' => 'The terms governing your use of this site and its services.',
            ],
            [
                'slug'            => 'privacy',
                'title'           => 'Privacy Policy',
                'source'          => 'privacy-policy.md',
                'seo_description' => 'How we collect, use, and protect personal information, and what rights you have over it.',
            ],
            [
                'slug'            => 'cookie-policy',
                'title'           => 'Cookie Policy',
                'source'          => 'cookie-policy.md',
                'seo_description' => 'The cookies and similar technologies this site uses, and how you can control them.',
            ],
        ];
    }

    public function up(): void
    {
        $seedDir = BASE_PATH . '/database/seeds/policies';

        foreach ($this->pages() as $page) {
            // Skip if a row already exists at this slug — admin-authored
            // OR previously seeded. INSERT IGNORE achieves the same end
            // but a pre-check lets us skip the file read on re-runs.
            $existing = $this->db->fetchOne(
                "SELECT id FROM pages WHERE slug = ?",
                [$page['slug']]
            );
            if ($existing) continue;

            $sourcePath = $seedDir . '/' . $page['source'];
            if (!is_file($sourcePath)) {
                // Seed file missing — fail loud rather than insert an empty
                // page. Better to surface the migration error than leave
                // admins with mystery-blank policy pages.
                throw new \RuntimeException(
                    "[seed_default_policy_pages] missing source file: $sourcePath"
                );
            }

            $markdown = (string) file_get_contents($sourcePath);
            $html     = Markdown::render($markdown);
            $hash     = hash('sha256', $html);

            $this->db->query(
                "INSERT INTO pages
                    (title, slug, body, layout, status, is_public,
                     seo_title, seo_description, seo_keywords,
                     sort_order, published_at)
                 VALUES (?, ?, ?, 'default', 'published', 1, ?, ?, ?, 0, NOW())",
                [
                    $page['title'],
                    $page['slug'],
                    $html,
                    $page['title'],
                    $page['seo_description'],
                    self::SEED_MARKER . $hash,
                ]
            );
        }
    }

    public function down(): void
    {
        // Roll back ONLY rows that still match the seed's hash. If an
        // admin has edited the body via /admin/pages, the body's
        // sha256 won't match the hash we stored in seo_keywords, and
        // we leave the row alone.
        foreach ($this->pages() as $page) {
            $row = $this->db->fetchOne(
                "SELECT id, body, seo_keywords FROM pages WHERE slug = ?",
                [$page['slug']]
            );
            if (!$row) continue;

            $stored = (string) ($row['seo_keywords'] ?? '');
            if (!str_starts_with($stored, self::SEED_MARKER)) {
                // No seed marker — admin took ownership of seo_keywords.
                // Leave the page alone.
                continue;
            }
            $expectedHash = substr($stored, strlen(self::SEED_MARKER));
            $actualHash   = hash('sha256', (string) ($row['body'] ?? ''));
            if (!hash_equals($expectedHash, $actualHash)) {
                // Body diverged from the seed — admin has been editing
                // through /admin/pages. Don't delete their work.
                continue;
            }

            $this->db->query("DELETE FROM pages WHERE id = ?", [$row['id']]);
        }
    }
};
