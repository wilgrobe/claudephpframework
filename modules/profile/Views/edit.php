<?php
// modules/profile/Views/edit.php
//
// Page-chrome Batch C: fragment view. The `profile.edit` system layout
// (1×1, max-width 560px) provides the surrounding header/footer at
// chrome-wrap time.
?>
<?php $pageTitle = 'Edit Profile'; ?>

<div style="max-width:560px;margin:0 auto">
<div class="card">
    <div class="card-header">
        <h2>Edit Profile</h2>
        <a href="/profile" class="btn btn-secondary btn-sm">Cancel</a>
    </div>
    <div class="card-body">
        <?php $errors = \Core\Session::flash('errors') ?? []; ?>
        <form method="POST" action="/profile/edit" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- Avatar upload -->
            <div class="form-group">
                <label for="avatar-input">Profile Photo</label>
                <div style="display:flex;align-items:center;gap:1rem;margin-bottom:.5rem">
                    <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= e($user['avatar']) ?>" alt="Current avatar"
                         style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb">
                    <?php else: ?>
                    <div style="width:56px;height:56px;border-radius:50%;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700">
                        <?= e(strtoupper(substr($user['first_name']??'?',0,1))) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="avatar" id="avatar-input" accept="image/jpeg,image/png,image/gif,image/webp"
                               style="font-size:13px" onchange="previewAvatar(this)">
                        <div style="font-size:11.5px;color:#6b7280;margin-top:.2rem">JPEG, PNG, GIF or WebP · max 2 MB</div>
                    </div>
                </div>
                <?php if (!empty($errors['avatar'])): ?><span class="form-error"><?= e($errors['avatar'][0]) ?></span><?php endif; ?>
                <img id="avatar-preview" src="" alt="" style="display:none;width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #4f46e5;margin-top:.5rem">
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label for="first_name">First Name *</label>
                    <input type="text" name="first_name" class="form-control <?= !empty($errors['first_name'])?'is-invalid':''?>"
                           value="<?= e($user['first_name'] ?? '') ?>" required id="first_name">
                    <?php if (!empty($errors['first_name'])): ?><span class="form-error"><?= e($errors['first_name'][0]) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name *</label>
                    <input type="text" name="last_name" class="form-control <?= !empty($errors['last_name'])?'is-invalid':''?>"
                           value="<?= e($user['last_name'] ?? '') ?>" required id="last_name">
                    <?php if (!empty($errors['last_name'])): ?><span class="form-error"><?= e($errors['last_name'][0]) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number
                    <span style="font-weight:400;color:#6b7280;font-size:12px">(required for SMS two-factor authentication)</span>
                </label>
                <input type="tel" name="phone" class="form-control" value="<?= e($user['phone'] ?? '')?>" placeholder="+1 555 000 0000" id="phone">
                <?php if (!empty($errors['phone'])): ?><span class="form-error"><?= e($errors['phone'][0]) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label for="bio">Bio</label>
                <textarea name="bio" class="form-control" rows="3" maxlength="1000" id="bio"><?= e($user['bio'] ?? '') ?></textarea>
            </div>

            <div style="border-top:1px solid #e5e7eb;margin:1.25rem 0;padding-top:1.25rem">
                <div style="font-weight:600;font-size:14px;margin-bottom:.75rem">Change Password <span style="font-weight:400;color:#6b7280">(leave blank to keep current)</span></div>
                <div class="form-group">
                    <label for="password-input">New Password</label>
                    <input type="password" id="password-input" name="password" class="form-control <?= !empty($errors['password'])?'is-invalid':'' ?>"
                           minlength="12" autocomplete="new-password">
                    <?php if (!empty($errors['password'])): ?><span class="form-error"><?= e($errors['password'][0]) ?></span><?php endif; ?>
                    <?php include BASE_PATH . '/app/Views/layout/password_strength.php'; ?>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm New Password</label>
                    <input type="password" name="password_confirm" class="form-control <?= !empty($errors['password_confirm'])?'is-invalid':''?>"
                           autocomplete="new-password" id="password_confirm">
                    <?php if (!empty($errors['password_confirm'])): ?><span class="form-error"><?= e($errors['password_confirm'][0]) ?></span><?php endif; ?>
                </div>
            </div>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="/profile" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<script>
function previewAvatar(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 2097152) {
        alert('Image is too large. Maximum size is 2 MB.');
        input.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        const preview = document.getElementById('avatar-preview');
        preview.src = e.target.result;
        preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
}
</script>

