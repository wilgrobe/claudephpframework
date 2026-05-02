<?php
// config/app.php
return [
    'name'     => $_ENV['APP_NAME'] ?? 'ClaudePHPFramework',
    'env'      => $_ENV['APP_ENV']  ?? 'production',
    'debug'    => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
    'url'      => $_ENV['APP_URL']  ?? 'http://localhost',
    'key'      => $_ENV['APP_KEY']  ?? 'change-me-32-characters-minimum!!',
    'timezone' => 'UTC',
    'session'  => [
        'lifetime' => 120,
        // NOTE: renamed from 'phpfw_session' to 'cphpfw_session' during rebrand.
        // Any deploy that carries this change will invalidate existing session
        // cookies — all users will need to log in again once. Safe and expected
        // during the one-time rename.
        'name'     => 'cphpfw_session',
        'secure'   => ($_ENV['APP_ENV'] ?? 'production') === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
        // Storage backend for session payloads.
        //   'file' — PHP's default filesystem handler (session.save_path).
        //   'db'   — Core\Session\DbSessionHandler against the `sessions`
        //            table. Required for multi-web-node deploys; also
        //            enables admin surfaces for active-session review
        //            and forced sign-out via row deletion.
        // Override via SESSION_DRIVER in .env without touching this file.
        'driver'   => strtolower((string) ($_ENV['SESSION_DRIVER'] ?? 'db')),
    ],
    'csrf' => true,
    'oauth' => [
        'enabled_providers' => ['google','microsoft','apple','facebook','linkedin'],
    ],
];
