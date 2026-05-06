<?php
/**
 * Shared "who can manage this module?" fieldset.
 *
 * Drop into any module's settings page like:
 *
 *   $policy_prefix = 'forms';
 *   $policy_values = [
 *       'access_mode'    => $values['forms_access_mode']    ?? 'all_users',
 *       'access_roles'   => $values['forms_access_roles']   ?? '',
 *       'access_groups'  => $values['forms_access_groups']  ?? '',
 *   ];
 *   $policy_label  = 'Who can build forms?';
 *   $policy_help   = ['admins_only' => 'Only admins can create forms.'];
 *   include BASE_PATH . '/app/Views/admin/_module_policy_form.php';
 *
 * Each subclass of `\Core\Module\ModulePolicy` reads exactly the setting
 * keys this partial writes, so the round-trip stays consistent across
 * modules without any per-module form glue.
 *
 * @var string $policy_prefix    e.g. 'forms', 'helpdesk' — produces inputs named "{prefix}_access_mode" etc.
 * @var array  $policy_values    Current values keyed by short-name ('access_mode', 'access_roles', 'access_groups')
 * @var string $policy_label     Card heading, e.g. "Who can manage X?"
 * @var array  $policy_help      Optional per-mode help strings overriding the defaults
 */
$prefix = (string) ($policy_prefix ?? 'module');
$values = (array)  ($policy_values ?? []);
$label  = (string) ($policy_label  ?? 'Who can manage this module?');
$help   = (array)  ($policy_help   ?? []);

$accessMode    = (string) ($values['access_mode']   ?? 'all_users');
$accessRoles   = (string) ($values['access_roles']  ?? '');
$accessGroups  = (string) ($values['access_groups'] ?? '');

$defaultHelp = [
    'all_users'   => 'Any authenticated user with the module\'s permission. (Default — preserves existing behaviour.)',
    'admins_only' => 'Restrict to users with the <code>admin</code> or <code>super-admin</code> role.',
    'roles'       => 'Only users with one of the listed role slugs. Supply slugs comma-separated below.',
    'groups'      => 'Only members of the listed groups. Supply group ids comma-separated below.',
];
foreach ($defaultHelp as $k => $v) {
    if (!isset($help[$k])) $help[$k] = $v;
}
?>
<div class="card" style="max-width:780px">
    <div class="card-header"><h2><?= e($label) ?></h2></div>
    <div class="card-body">
        <?php foreach (['all_users','admins_only','roles','groups'] as $mode):
            $titles = ['all_users' => 'Any user with the permission', 'admins_only' => 'Admins only', 'roles' => 'Specific roles', 'groups' => 'Specific groups'];
        ?>
        <div class="form-row" style="margin-bottom:.6rem">
            <label style="display:block;cursor:pointer">
                <input type="radio"
                       name="<?= e($prefix) ?>_access_mode"
                       value="<?= e($mode) ?>"
                       <?= $accessMode === $mode ? 'checked' : '' ?>>
                <strong><?= e($titles[$mode]) ?></strong><br>
                <small style="margin-left:1.5rem;color:var(--text-muted)"><?= $help[$mode] /* trusted defaults; admin-overridable */ ?></small>
            </label>
        </div>
        <?php endforeach; ?>

        <div class="form-row" style="margin-top:1rem">
            <label for="<?= e($prefix) ?>_access_roles">Allowed role slugs (CSV) — used when <em>Specific roles</em> is selected</label>
            <input type="text"
                   name="<?= e($prefix) ?>_access_roles"
                   id="<?= e($prefix) ?>_access_roles"
                   value="<?= e($accessRoles) ?>"
                   placeholder="moderator, editor">
            <small>Comma-separated role slugs (lowercase). Empty list when this mode is selected blocks everyone except superadmins.</small>
        </div>

        <div class="form-row">
            <label for="<?= e($prefix) ?>_access_groups">Allowed group ids (CSV) — used when <em>Specific groups</em> is selected</label>
            <input type="text"
                   name="<?= e($prefix) ?>_access_groups"
                   id="<?= e($prefix) ?>_access_groups"
                   value="<?= e($accessGroups) ?>"
                   placeholder="12, 47">
            <small>Comma-separated group ids (numeric). Membership checked against <code>user_groups</code>; the gate fails closed when the groups module is not installed.</small>
        </div>
    </div>
</div>
<?php
unset($prefix, $values, $label, $help, $accessMode, $accessRoles, $accessGroups, $defaultHelp);
?>
