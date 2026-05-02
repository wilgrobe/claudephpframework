<?php $pageTitle = $user_edit ? 'Edit User' : 'Create User'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:680px;margin:0 auto">
<div class="card">
    <div class="card-header">
        <h2><?= $user_edit ? 'Edit User: ' . e(($user_edit['first_name']??'').' '.($user_edit['last_name']??'')) : 'Create New User' ?></h2>
    </div>
    <div class="card-body">
        <?php $errors = \Core\Session::flash('errors') ?? []; ?>
        <form method="POST" action="<?= $user_edit ? '/admin/users/'.$user_edit['id'].'/edit' : '/admin/users/create' ?>">
            <?= csrf_field() ?>

            <div class="grid grid-2">
                <div class="form-group">
                    <label for="first_name">First Name *</label>
                    <input type="text" name="first_name" class="form-control <?= !empty($errors['first_name'])?'is-invalid':''?>"
                           value="<?= e($user_edit['first_name'] ?? old('first_name')) ?>" required id="first_name">
                    <?php if (!empty($errors['first_name'])): ?><span class="form-error"><?= e($errors['first_name'][0]) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name *</label>
                    <input type="text" name="last_name" class="form-control <?= !empty($errors['last_name'])?'is-invalid':''?>"
                           value="<?= e($user_edit['last_name'] ?? old('last_name')) ?>" required id="last_name">
                    <?php if (!empty($errors['last_name'])): ?><span class="form-error"><?= e($errors['last_name'][0]) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" name="email" class="form-control <?= !empty($errors['email'])?'is-invalid':''?>"
                       value="<?= e($user_edit['email'] ?? old('email')) ?>" required id="email">
                <?php if (!empty($errors['email'])): ?><span class="form-error"><?= e($errors['email'][0]) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password <?= $user_edit ? '(leave blank to keep current)' : '*' ?></label>
                <input type="password" name="password" class="form-control <?= !empty($errors['password'])?'is-invalid':''?>"
                       <?= !$user_edit ? 'required' : '' ?> minlength="8" autocomplete="new-password" id="password">
                <?php if (!empty($errors['password'])): ?><span class="form-error"><?= e($errors['password'][0]) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="tel" name="phone" class="form-control" value="<?= e($user_edit['phone'] ?? old('phone'))?>" id="phone">
            </div>

            <!-- Roles -->
            <div class="form-group">
                <label>System Roles</label>
                <div style="display:flex;flex-direction:column;gap:.4rem">
                <?php foreach ($all_roles as $r): ?>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="roles[]" value="<?= $r['id'] ?>"
                           <?= in_array($r['id'], array_column($assigned_roles??[], 'id')) ? 'checked' : '' ?>>
                    <span><?= e($r['name']) ?></span>
                    <?php if ($r['is_system']): ?><span class="badge badge-gray" style="font-size:10px">system</span><?php endif; ?>
                </label>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="is_active" value="1" <?= ($user_edit['is_active'] ?? 1) ? 'checked' : '' ?>>
                    Account Active
                </label>
            </div>

            <?php if (auth()->isSuperadminModeOn()): ?>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="is_superadmin" value="1" <?= ($user_edit['is_superadmin'] ?? 0) ? 'checked' : '' ?>>
                    Grant Superadmin Access
                </label>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary"><?= $user_edit ? 'Save Changes' : 'Create User' ?></button>
                <a href="/admin/users" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
