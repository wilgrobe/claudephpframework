<?php
// Smoke-test the 5 marketing blocks. Drives them via the BlockDescriptor's
// render closure with hand-rolled settings so we exercise the real code.
// We bypass the full container bootstrap (which needs a DB connection)
// and just load enough autoload + module discovery to instantiate the
// siteblocks BlockDescriptor objects directly.

define('BASE_PATH', dirname(__DIR__, 2));

// Sandbox PHP is 8.1; vendored composer was built for 8.4 so we can't
// require vendor/autoload.php. Load just the framework classes the
// block-render closures actually touch.
spl_autoload_register(function (string $class) {
    $prefix = 'Core\\';
    if (strpos($class, $prefix) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $f   = BASE_PATH . '/core/' . $rel . '.php';
    if (is_file($f)) require $f;
});

// Pull the module file directly. It returns an anonymous-class
// ModuleProvider instance whose blocks() we can call without needing
// the full ModuleRegistry / Container chain.
$provider = require BASE_PATH . '/modules/siteblocks/module.php';
$blocks   = [];
foreach ($provider->blocks() as $b) $blocks[$b->key] = $b;

$cases = [
    [
        'siteblocks.pricing_table',
        [
            'heading' => 'Choose your plan',
            'plans' => [
                ['name' => 'Starter', 'price' => '$0',  'period' => '/mo',
                 'features' => ['1 user', '500 MB storage'], 'cta_label' => 'Start free', 'cta_url' => '/signup'],
                ['name' => 'Pro',     'price' => '$29', 'period' => '/mo', 'popular' => true,
                 'features' => ['10 users', '50 GB storage', 'Priority support'], 'cta_label' => 'Go Pro', 'cta_url' => '/signup?plan=pro'],
                ['name' => 'Team',    'price' => '$99', 'period' => '/mo',
                 'features' => ['Unlimited users', '500 GB storage', '24/7 support'], 'cta_label' => 'Contact sales', 'cta_url' => 'mailto:sales@example.com'],
            ],
        ],
    ],
    [
        'siteblocks.testimonials',
        [
            'heading' => 'What customers say',
            'layout'  => 'grid',
            'items' => [
                ['quote' => 'Game-changer for our team.', 'author' => 'Ada Lovelace', 'role' => 'CTO, ExampleCo'],
                ['quote' => 'Saved us months of work.',    'author' => 'Grace Hopper', 'role' => 'Eng Manager'],
            ],
        ],
    ],
    [
        'siteblocks.feature_grid',
        [
            'heading' => 'Why teams pick us',
            'columns' => 3,
            'features' => [
                ['icon' => '⚡', 'title' => 'Fast',    'description' => 'Sub-100ms response globally.'],
                ['icon' => '🔒', 'title' => 'Secure',  'description' => 'SOC 2 + GDPR compliant.'],
                ['icon' => '🤝', 'title' => 'Support', 'description' => 'Real humans, real fast.'],
            ],
        ],
    ],
    [
        'siteblocks.logo_cloud',
        [
            'heading'   => 'Trusted by teams at',
            'grayscale' => true,
            'logos' => [
                ['src' => 'https://example.com/logo-a.png', 'alt' => 'Acme', 'link' => 'https://acme.example.com'],
                ['src' => '/static/logo-b.png',             'alt' => 'Beta'],
                ['src' => 'javascript:alert(1)',            'alt' => 'XSS'], // should be filtered out
            ],
        ],
    ],
    [
        'siteblocks.stats_showcase',
        [
            'heading' => '',
            'stats' => [
                ['value' => '10', 'suffix' => 'K+', 'label' => 'customers'],
                ['value' => '99.9', 'suffix' => '%', 'label' => 'uptime'],
                ['value' => '12',   'prefix' => '$', 'suffix' => 'M', 'label' => 'raised'],
            ],
        ],
    ],
];

foreach ($cases as [$key, $settings]) {
    $desc = $blocks[$key] ?? null;
    if (!$desc) { echo "❌ $key: not registered\n"; continue; }
    $html = ($desc->render)(['user' => null], $settings);
    $len  = strlen($html);
    echo $len > 0 ? "✅ $key: $len chars\n" : "❌ $key: empty render\n";
    if ($key === 'siteblocks.logo_cloud') {
        $bad = strpos($html, 'javascript:') !== false;
        echo $bad ? "  ❌ logo_cloud leaked javascript: URL!\n" : "  ✅ javascript: URL was filtered\n";
    }
}
