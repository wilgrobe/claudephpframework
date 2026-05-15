<?php $pageTitle = 'Create Account'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Account — <?= e(setting('site_name', 'App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/auth.css">
<style>
.invite-notice {
    background: var(--color-purple-bg);
    border: 1px solid #c4b5fd;
    color: var(--color-purple-fg);
    padding: .75rem 1rem;
    border-radius: 6px;
    font-size: 13.5px;
    margin-bottom: 1rem;
}
.policy-box {
    background: var(--color-gray-50);
    border: 1px solid var(--color-gray-200);
    border-radius: 6px;
    padding: .85rem 1rem;
    font-size: 13px; line-height: 1.5;
    margin-bottom: 1rem;
}
.policy-box label {
    display: flex; align-items: flex-start;
    gap: .5rem; margin-bottom: .4rem !important;
    font-weight: 400 !important;
}
.policy-box label input { margin-top: .2rem; }
.policy-box a {
    color: var(--color-primary); text-decoration: none;
}
.username-status { font-size: 11.5px; font-weight: 400; margin-left: .4rem; }
.username-suggestions { margin-top: .4rem; display: none; }
.username-suggestion-row {
    display: flex; gap: .35rem; flex-wrap: wrap;
}
.username-suggestion-btn {
    padding: .25rem .55rem;
    font-size: 12px;
    border: 1px solid var(--color-gray-300);
    background: var(--bg-panel, #fff);
    border-radius: 4px;
    cursor: pointer;
    color: var(--color-gray-900);
    font-family: inherit;
}
</style>
</head>
<body class="auth">
<div class="auth-card auth-card--wide">
    <div class="auth-logo">
        <div class="auth-emoji">✨</div>
        <h1>Create your account</h1>
        <p>Join <?= e(setting('site_name', 'App')) ?></p>
    </div>

    <?php if (!empty($invite_token)): ?>
    <div class="invite-notice">🎉 You have a group invitation waiting! Create an account to accept it.</div>
    <?php endif; ?>

    <?php $errs = $errors ?? []; ?>

    <?php if (!empty($oauth_providers)): ?>
    <?php $meta = ['google'=>['G','#ea4335'],'microsoft'=>['M','#00a4ef'],'apple'=>['🍎','#000'],'facebook'=>['f','#1877f2'],'linkedin'=>['in','#0a66c2']]; ?>
    <?php foreach ($oauth_providers as $p): $m = $meta[$p] ?? ['?','#666']; ?>
    <a href="/auth/oauth/<?= e($p) ?>" class="btn btn-oauth btn-block">
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

        <div class="form-grid form-grid--2">
            <div class="form-row">
                <label for="first_name">First Name</label>
                <input type="text" name="first_name" value="<?= old('first_name') ?>" required id="first_name">
                <?php if (!empty($errs['first_name'])): ?><span class="error"><?= e($errs['first_name'][0]) ?></span><?php endif; ?>
            </div>
            <div class="form-row">
                <label for="last_name">Last Name</label>
                <input type="text" name="last_name" value="<?= old('last_name') ?>" required id="last_name">
                <?php if (!empty($errs['last_name'])): ?><span class="error"><?= e($errs['last_name'][0]) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <label for="reg-email">Email address</label>
            <input type="email" id="reg-email" name="email" value="<?= old('email') ?>" required autocomplete="email">
            <?php if (!empty($errs['email'])): ?><span class="error"><?= e($errs['email'][0]) ?></span><?php endif; ?>
        </div>

        <div class="form-row">
            <label for="reg-username">Username<span id="username-status" class="username-status"></span></label>
            <input type="text" id="reg-username" name="username"
                   value="<?= old('username') ?>"
                   minlength="3" maxlength="50"
                   pattern="[a-z0-9][a-z0-9._-]{1,48}[a-z0-9]"
                   autocomplete="username">
            <?php if (!empty($errs['username'])): ?>
                <span class="error"><?= e($errs['username'][0]) ?></span>
            <?php else: ?>
                <small>
                    3-50 chars: letters, numbers, dots, underscores, hyphens.
                    Leave blank and we'll suggest one based on your email.
                </small>
            <?php endif; ?>
            <div id="username-suggestions" class="username-suggestions">
                <small>Try one of these:</small>
                <div id="username-suggestion-buttons" class="username-suggestion-row"></div>
            </div>
        </div>

        <div class="form-row">
            <label for="password-input">Password <span class="text-muted" style="font-weight:400">(min 12 characters)</span></label>
            <input type="password" id="password-input" name="password" required autocomplete="new-password" minlength="12">
            <?php if (!empty($errs['password'])): ?><span class="error"><?= e($errs['password'][0]) ?></span><?php endif; ?>
            <?php include BASE_PATH . '/app/Views/layout/password_strength.php'; ?>
        </div>

        <div class="form-row">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" name="password_confirm" required autocomplete="new-password" id="password_confirm">
            <?php if (!empty($errs['password_confirm'])): ?><span class="error"><?= e($errs['password_confirm'][0]) ?></span><?php endif; ?>
        </div>

        <?php if (!empty($coppa_enabled)): ?>
        <div class="form-row">
            <label for="date_of_birth">Date of birth</label>
            <input type="date" name="date_of_birth"
                   value="<?= old('date_of_birth') ?>"
                   max="<?= date('Y-m-d') ?>"
                   required id="date_of_birth">
            <?php if (!empty($errs['date_of_birth'])): ?>
                <span class="error"><?= e($errs['date_of_birth'][0]) ?></span>
            <?php else: ?>
                <small>You must be at least <?= (int) ($coppa_min_age ?? 13) ?> years old to create an account.</small>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $__captcha = captcha_widget(); if ($__captcha): ?>
        <div class="form-row"><?= $__captcha ?></div>
        <?php endif; ?>

        <?php if (!empty($required_policies)): ?>
        <div class="policy-box">
            <?php foreach ($required_policies as $rp):
                $kindSlug  = htmlspecialchars((string) $rp['kind_slug'], ENT_QUOTES);
                $kindLabel = htmlspecialchars((string) $rp['kind_label'], ENT_QUOTES);
                $verId     = (int) $rp['version_id'];
            ?>
            <label>
                <input type="checkbox" name="accept_policy[<?= $verId ?>]" value="1" required>
                <span>I agree to the
                    <a href="/policies/<?= $kindSlug ?>" target="_blank" rel="noopener"><?= $kindLabel ?></a>
                    (v<?= htmlspecialchars((string) $rp['version_label'], ENT_QUOTES) ?>).
                </span>
            </label>
            <?php endforeach; ?>
            <?php if (!empty($errs['accept_policy'])): ?>
                <span class="error"><?= e($errs['accept_policy'][0]) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    </form>

    <div class="auth-footer">Already have an account? <a href="/login">Sign in</a></div>
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
        $status.style.color = color || 'var(--color-gray-500)';
    }

    function renderSuggestions(list) {
        if (!list || !list.length) { $sugBox.style.display = 'none'; return; }
        $sugBtns.innerHTML = '';
        list.forEach(function (s) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = s;
            btn.className = 'username-suggestion-btn';
            btn.addEventListener('click', function () {
                $u.value = s;
                $sugBox.style.display = 'none';
                check();
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
        setStatus('checking…', 'var(--color-gray-500)');
        fetch(buildUrl(true), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    setStatus('✗ ' + data.error, 'var(--color-danger)');
                    renderSuggestions(data.suggestions);
                } else if (data.available) {
                    setStatus('✓ available', 'var(--color-success)');
                    $sugBox.style.display = 'none';
                } else {
                    setStatus('✗ taken', 'var(--color-danger)');
                    renderSuggestions(data.suggestions);
                }
            })
            .catch(function () { setStatus('', ''); });
    }

    $u.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(check, 400);
    });

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

<script src="/assets/js/app.js"></script>
</body>
</html>
