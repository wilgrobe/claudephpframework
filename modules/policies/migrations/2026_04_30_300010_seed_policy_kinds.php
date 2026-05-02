<?php
// modules/policies/migrations/2026_04_30_300010_seed_policy_kinds.php
use Core\Database\Migration;

/**
 * Seed the standard policy kinds — Terms of Service, Privacy Policy,
 * Acceptable Use Policy. Marked is_system=1 so admins can't delete them
 * from /admin/policies (they can edit the live source page + bump
 * versions, but the kind row stays).
 *
 * Idempotent — INSERT IGNORE on the slug unique key.
 */
return new class extends Migration {
    public function up(): void
    {
        $kinds = [
            [
                'slug' => 'tos',
                'label' => 'Terms of Service',
                'description' => 'The contract that governs use of the site. Required-acceptance.',
                'requires_acceptance' => 1,
                'sort_order' => 1,
            ],
            [
                'slug' => 'privacy',
                'label' => 'Privacy Policy',
                'description' => 'How personal data is collected, used, and shared. GDPR Art. 13/14 transparency notice.',
                'requires_acceptance' => 1,
                'sort_order' => 2,
            ],
            [
                'slug' => 'acceptable_use',
                'label' => 'Acceptable Use Policy',
                'description' => 'Rules of conduct for community features. Required-acceptance once enabled.',
                'requires_acceptance' => 0,
                'sort_order' => 3,
            ],
        ];

        foreach ($kinds as $k) {
            $this->db->query("
                INSERT IGNORE INTO policy_kinds (slug, label, description, requires_acceptance, is_system, sort_order)
                VALUES (?, ?, ?, ?, 1, ?)
            ", [
                $k['slug'], $k['label'], $k['description'], $k['requires_acceptance'], $k['sort_order'],
            ]);

            // Ensure a matching `policies` row exists per kind so the
            // admin UI doesn't have to LEFT JOIN every read.
            $kindId = (int) $this->db->fetchColumn(
                "SELECT id FROM policy_kinds WHERE slug = ?", [$k['slug']]
            );
            if ($kindId > 0) {
                $this->db->query("
                    INSERT IGNORE INTO policies (kind_id, source_page_id, current_version_id)
                    VALUES (?, NULL, NULL)
                ", [$kindId]);
            }
        }
    }

    public function down(): void
    {
        // Leave the rows. is_system=1 protects them from admin UI
        // deletion; a rollback shouldn't either.
    }
};
