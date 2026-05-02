<?php $pageTitle = 'Create Account'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Account — <?= e(setting('site_name', 'App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
:root{--primary:#4f46e5;--primary-dark:#3730a3;--danger:#ef4444;--gray-50:#f9fafb;--gray-200:#e5e7eb;--gray-300:#d1d5db;--gray-500:#6b7280;--gray-900:#111827}
body{margin:0;font-family:'Inter',sans-serif;background:var(--gray-50);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem 1rem}
.auth-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;max-width:460px}
h1{font-size:1.4rem;font-weight:700;margin:0 0 .25rem;text-align:center}
.subtitle{color:var(--gray-500);font-size:14px;text-align:center;margin-bottom:1.75rem}
.row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-weight:500;font-size:13.5px;margin-bottom:.35rem}
.form-control{width:100%;padding:.6rem .8rem;border:1px solid var(--gray-300);border-radius:6px;font-size:14px;font-family:inherit;transition:border-color .15s,box-shadow .15s}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,70,229,.15)}
.form-control.is-invalid{border-color:var(--danger)}
.form-error{color:var(--danger);font-size:12px;margin-top:.25rem;display:block}
.btn{display:flex;align-items:center;justify-content:center;width:100%;padding:.7rem;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;border:none;font-family:inherit;background:var(--primary);color:#fff;transition:background .15s}
.btn:hover{background:var(--primary-dark)}
.btn-oauth{background:#fff;color:var(--gray-900);border:1px solid var(--gray-300);font-weight:500;margin-bottom:.5rem}
.btn-oauth:hover{background:var(--gray-50)}
.divider{display:flex;align-items:center;gap:.75rem;margin:1.25rem 0;color:var(--gray-500);font-size:12px}
.divider::before,.divider::after{content:'';flex:1;border-top:1px solid var(--gray-200)}
.footer{text-align:center;margin-top:1.5rem;font-size:13.5px;color:var(--gray-500)}
.footer a{color:var(--primary);text-decoration:none;font-weight:500}
.invite-notice{background:#ede9fe;border:1px solid #c4b5fd;color:#4c1d95;padding:.75rem 1rem;border-radius:6px;font-size:13.5px;margin-bottom:1rem}
</style>
</head>
<body>
<div class="auth-card">
    <h1>Create your account</h1>
    <p class="subtitle">Join <?= e(setting('site_name', 'App')) ?></p>

    <?php if (!empty($invite_token)): ?>
    <div class="invite-notice">🎉 You have a group invitation waiting! Create an account to accept it.</div>
    <?php endif; ?>

    <?php $errs = $errors ?? []; ?>

    <?php if (!empty($oauth_providers)): ?>
    <?php $meta = ['google'=>['G','#ea4335'],'microsoft'=>['M','#00a4ef'],'apple'=>['🍎','#000'],'facebook'=>['f','#1877f2'],'linkedin'=>['in','#0a66c2']]; ?>
    <?php foreach ($oauth_providers as $p): $m = $meta[$p] ?? ['?','#666']; ?>
    <a href="/auth/oauth/<?= e($p) ?>" class="btn btn-oauth">
        <span style="font-weight:700;color:<?= $m[1] ?>"><?= $m[0] ?></span>
        Continue with <?= e(ucfirst($p)) ?>
    </a>
    <?php endforeach; ?>
    <div class="divider">or register with email</div>
    <?php endif; ?>

    <form method="POST" action="/register">
        <?= csrf_field() ?>
        <?php if (!empty($invite_token)): ?>
        <input type="hidden" name="invite_token" value="<?= e($invite_token) ?>">
        <?php endif; ?>

        <div class="row">
            <div class="form-group">
                <label for="first_name">First Name</label>
                <input type="text" name="first_name" class="form-control <?= !empty($errs['first_name']) ? 'is-invalid':''?>"
                       value="<?= old('first_name') ?>" required id="first_name">
                <?php if (!empty($errs['first_name'])): ?><span class="form-error"><?= e($errs['first_name'][0]) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="last_name">Last Name</label>
                <input type="text" name="last_name" class="form-control <?= !empty($errs['last_name']) ? 'is-invalid':''?>"
                       value="<?= old('last_name') ?>" required id="last_name">
                <?php if (!empty($errs['last_name'])): ?><span class="form-error"><?= e($errs['last_name'][0]) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="reg-email">Email address</label>
            <input type="email" id="reg-email" name="email" class="form-control <?= !empty($errs['email']) ? 'is-invalid':'' ?>"
                   value="<?= old('email') ?>" required autocomplete="email">
            <?php if (!empty($errs['email'])): ?><span class="form-error"><?= e($errs['email'][0]) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="reg-username">Username
                <span id="username-status" style="font-size:11.5px;font-weight:400;margin-left:.4rem"></span>
            </label>
            <input type="text" id="reg-username" name="username"
                   class="form-control <?= !empty($errs['username']) ? 'is-invalid':'' ?>"
                   value="<?= old('username') ?>"
                   minlength="3" maxlength="50"
                   pattern="[a-z0-9][a-z0-9._-]{1,48}[a-z0-9]"
                   autocomplete="username">
            <?php if (!empty($errs['username'])): ?>
                <span class="form-error"><?= e($errs['username'][0]) ?></span>
            <?php else: ?>
                <small style="color:var(--gray-500);font-size:12px">
                    3-50 chars: letters, numbers, dots, underscores, hyphens.
                    Leave blank and we'll suggest one based on your email.
                </small>
            <?php endif; ?>
            <div id="username-suggestions" style="margin-top:.4rem;display:none">
                <small style="color:var(--gray-500);font-size:12px;display:block;margin-bottom:.25rem">Try one of these:</small>
                <div id="username-suggestion-buttons" style="display:flex;gap:.35rem;flex-wrap:wrap"></div>
            </div>
        </div>

        <div class="form-group">
            <label for="password-input">Password <span style="color:var(--gray-500);font-weight:400">(min 12 characters)</span></label>
            <input type="password" id="password-input" name="password" class="form-control <?= !empty($errs['password']) ? 'is-invalid':'' ?>"
                   required autocomplete="new-password" minlength="12">
            <?php if (!empty($errs['password'])): ?><span class="form-error"><?= e($errs['password'][0]) ?></span><?php endif; ?>
            <?php include BASE_PATH . '/app/Views/layout/password_strength.php'; ?>
        </div>

        <div class="form-group">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" name="password_confirm" class="form-control <?= !empty($errs['password_confirm']) ? 'is-invalid':''?>"
                   required autocomplete="new-password" id="password_confirm">
            <?php if (!empty($errs['password_confirm'])): ?><span class="form-error"><?= e($errs['password_confirm'][0]) ?></span><?php endif; ?>
        </div>

        <?php if (!empty($coppa_enabled)): ?>
        <div class="form-group">
            <label for="date_of_birth">Date of birth</label>
            <input type="date" name="date_of_birth"
                   class="form-control <?= !empty($errs['date_of_birth']) ? 'is-invalid':''?>"
                   value="<?= old('date_of_birth') ?>"
                   max="<?= date('Y-m-d') ?>"
                   required id="date_of_birth">
            <?php if (!empty($errs['date_of_birth'])): ?>
                <span class="form-error"><?= e($errs['date_of_birth'][0]) ?></span>
            <?php else: ?>
                <small style="color:var(--gray-500);font-size:12px">
                    You must be at least <?= (int) ($coppa_min_age ?? 13) ?> years old to create an account.
                </small>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $__captcha = captcha_widget(); if ($__captcha): ?>
        <div class="form-group"><?= $__captcha ?></div>
        <?php endif; ?>

        <?php if (!empty($required_policies)): ?>
        <div class="form-group" style="background:#f9fafb;border:1px solid var(--gray-200);border-radius:6px;padding:.85rem 1rem;font-size:13px;line-height:1.5">
            <?php foreach ($required_policies as $rp):
                $kindSlug  = htmlspecialchars((string) $rp['kind_slug'], ENT_QUOTES);
                $kindLabel = htmlspecialchars((string) $rp['kind_label'], ENT_QUOTES);
                $verId     = (int) $rp['version_id'];
            ?>
            <label style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.4rem">
                <input type="checkbox"
                       name="accept_policy[<?= $verId ?>]"
                       value="1"
                       required
                       style="margin-top:.2rem">
                <span>I agree to the
                    <a href="/policies/<?= $kindSlug ?>" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none"><?= $kindLabel ?></a>
                    (v<?= htmlspecialchars((string) $rp['version_label'], ENT_QUOTES) ?>).
                </span>
            </label>
            <?php endforeach; ?>
            <?php if (!empty($errs['accept_policy'])): ?>
                <div class="form-error" style="margin-top:.4rem"><?= e($errs['accept_policy'][0]) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn">Create Account</button>
    </form>

    <div class="footer">Already have an account? <a href="/login">Sign in</a></div>
</div>

<script>
// Username live availability + suggestions. Debounced ~400ms after the
// last keystroke. Hits /api/users/check-username (public, no auth).
// On blur of email/first/last with empty username, the same endpoint
// auto-fills a suggestion.
(function () {
    const $u = document.getElementById('reg-username');
    const $email = document.getElementById('reg-email');
    const $first = document.querySelector('input[name="first_name"]');
    const $last  = document.querySelector('input[name="last_name"]');
    const $status = document.getElementById('username-status');
    const $sugBox = document.getElementById('username-suggestions');
    const $sugBtns = document.getElementById('username-suggestion-buttons');
    if (!$u || !$status) return;

    let timer = null;

    function setStatus(text, color) {
        $status.textContent = text;
        $status.style.color = color || 'var(--gray-500)';
    }

    function renderSuggestions(list) {
        if (!list || !list.length) { $sugBox.style.display = 'none'; return; }
        $sugBtns.innerHTML = '';
        list.forEach(function (s) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = s;
            btn.style.cssText = 'padding:.25rem .55rem;font-size:12px;border:1px solid var(--gray-300);background:#fff;border-radius:4px;cursor:pointer;color:var(--gray-900)';
            btn.addEventListener('click', function () {
                $u.value = s;
                $sugBox.style.display = 'none';
                check(); // re-validate via the endpoint to set the OK badge
            });
            $sugBtns.appendChild(btn);
        });
        $sugBox.style.display = 'block';
    }

    function buildUrl(includeUsername) {
        const params = new URLSearchParams();
        if (includeUsername && $u.value) params.set('u', $u.value);
        if ($email.value) params.set('email', $email.value);
        if ($first.value) params.set('first', $first.value);
        if ($last.value)  params.set('last',  $last.value);
        return '/api/users/check-username?' + params.toString();
    }

    function check() {
        if (!$u.value) {
            setStatus('', '');
            $sugBox.style.display = 'none';
            return;
        }
        setStatus('checking…', 'var(--gray-500)');
        fetch(buildUrl(true), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    setStatus('✗ ' + data.error, 'var(--danger)');
                    renderSuggestions(data.suggestions);
                } else if (data.available) {
                    setStatus('✓ available', '#10b981');
                    $sugBox.style.display = 'none';
                } else {
                    setStatus('✗ taken', 'var(--danger)');
                    renderSuggestions(data.suggestions);
                }
            })
            .catch(function () { setStatus('', ''); });
    }

    $u.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(check, 400);
    });

    // Auto-suggest on blur of email / first / last when username is empty
    function autoSuggest() {
        if ($u.value.trim() !== '') return;
        if (!$email.value && !$first.value && !$last.value) return;
        fetch(buildUrl(false), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.suggestions && data.suggestions.length) {
                    renderSuggestions(data.suggestions);
                }
            })
            .catch(function () {});
    }
    $email.addEventListener('blur', autoSuggest);
    $first.addEventListener('blur', autoSuggest);
    $last.addEventListener('blur', autoSuggest);
})();
</script>

</body>
</html>
