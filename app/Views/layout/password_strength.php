<?php
/*
 * app/Views/layout/password_strength.php
 *
 * Include after any password input. Example:
 *     include BASE_PATH . '/app/Views/layout/password_strength.php';
 *
 * Assumes a password input with id="password-input" or the first
 * [type=password] input on the page.
 */
?>
<div class="password-strength-wrap" id="pw-strength-wrap" style="display:none">
    <div class="password-strength-bar">
        <div class="password-strength-fill" id="pw-fill"></div>
    </div>
    <div class="password-strength-label" id="pw-label">Enter a password</div>
    <ul class="password-requirements" id="pw-reqs">
        <li id="req-len">At least 12 characters</li>
        <li id="req-upper">Uppercase letter</li>
        <li id="req-lower">Lowercase letter</li>
        <li id="req-digit">Number</li>
        <li id="req-special">Special character</li>
    </ul>
</div>
<script>
(function () {
    const input = document.getElementById('password-input')
        || document.querySelector('input[type="password"][name="password"]');
    if (!input) return;

    const wrap  = document.getElementById('pw-strength-wrap');
    const fill  = document.getElementById('pw-fill');
    const label = document.getElementById('pw-label');
    const checks = {
        len    : { el: document.getElementById('req-len'),     fn: v => v.length >= 12 },
        upper  : { el: document.getElementById('req-upper'),   fn: v => /[A-Z]/.test(v) },
        lower  : { el: document.getElementById('req-lower'),   fn: v => /[a-z]/.test(v) },
        digit  : { el: document.getElementById('req-digit'),   fn: v => /[0-9]/.test(v) },
        special: { el: document.getElementById('req-special'), fn: v => /[^A-Za-z0-9]/.test(v) },
    };
    const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];

    input.addEventListener('input', function () {
        const val = this.value;
        if (!val) { wrap.style.display = 'none'; return; }
        wrap.style.display = '';

        let score = 0;
        for (const [key, check] of Object.entries(checks)) {
            const met = check.fn(val);
            check.el.classList.toggle('met', met);
            if (met) score++;
        }
        // Score 0–4: map 5 checks to 4 bar levels (len+upper+lower+digit+special)
        // Use 4 levels: ≤1 = 1, 2–3 = 2, 4 = 3, 5 = 4
        const barScore = score === 0 ? 0 : score <= 1 ? 1 : score <= 3 ? 2 : score === 4 ? 3 : 4;
        fill.dataset.score  = barScore;
        label.dataset.score = barScore;
        label.textContent   = barScore === 0 ? 'Enter a password' : labels[barScore];
    });
})();
</script>
