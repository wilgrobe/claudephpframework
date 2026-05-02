<?php
// core/Services/IntegrationConfig.php
namespace Core\Services;

/**
 * Reads third-party integration config from environment variables.
 *
 * Every integration follows the same shape: a "provider" selector (with the
 * string "none" meaning disabled) and a set of provider-specific env vars
 * beneath it. See .env.example for the full list of keys per integration.
 *
 * This class replaces the old DB-backed Core\Services\IntegrationService —
 * config no longer lives in the `integrations` table. The admin UI at
 * /admin/integrations is a read-only status dashboard that consumes this
 * class's describe() output.
 */
class IntegrationConfig
{
    /**
     * Integration type -> descriptor array. Each descriptor lists:
     *   - label: human-readable name for the dashboard
     *   - driver_var: the env var holding the provider selector
     *   - default_driver: provider used if driver_var is blank
     *   - providers: array of provider-name -> [human label, required vars, optional vars]
     *
     * Required vars are the env vars that MUST be set for that provider to
     * be considered "configured". Optional vars are displayed on the
     * dashboard for completeness but don't block the configured flag.
     */
    private const DEFS = [
        'email' => [
            'label'          => 'Email',
            'driver_var'     => 'MAIL_DRIVER',
            'default_driver' => 'smtp',
            'from_vars'      => ['MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'],
            'providers' => [
                'smtp' => [
                    'label'    => 'SMTP',
                    'required' => ['MAIL_HOST', 'MAIL_PORT'],
                    'optional' => ['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION'],
                ],
                'sendgrid' => [
                    'label'    => 'SendGrid',
                    'required' => ['MAIL_SENDGRID_API_KEY'],
                    'optional' => [],
                ],
                'mailgun' => [
                    'label'    => 'Mailgun',
                    'required' => ['MAIL_MAILGUN_API_KEY', 'MAIL_MAILGUN_DOMAIN'],
                    'optional' => ['MAIL_MAILGUN_ENDPOINT'],
                ],
                'ses' => [
                    'label'    => 'Amazon SES',
                    'required' => ['MAIL_SES_REGION', 'MAIL_SES_ACCESS_KEY', 'MAIL_SES_SECRET_KEY'],
                    'optional' => [],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'sms' => [
            'label'          => 'SMS',
            'driver_var'     => 'SMS_DRIVER',
            'default_driver' => 'auto',
            'providers' => [
                'auto' => [
                    'label'    => 'Auto (log in dev, real in prod)',
                    'required' => [],
                    'optional' => ['SMS_TWILIO_ACCOUNT_SID', 'SMS_TWILIO_AUTH_TOKEN', 'SMS_TWILIO_FROM_NUMBER'],
                ],
                'log' => [
                    'label'    => 'Log only',
                    'required' => [],
                    'optional' => [],
                ],
                'twilio' => [
                    'label'    => 'Twilio',
                    'required' => ['SMS_TWILIO_ACCOUNT_SID', 'SMS_TWILIO_AUTH_TOKEN', 'SMS_TWILIO_FROM_NUMBER'],
                    'optional' => [],
                ],
                'vonage' => [
                    'label'    => 'Vonage',
                    'required' => ['SMS_VONAGE_API_KEY', 'SMS_VONAGE_API_SECRET', 'SMS_VONAGE_FROM'],
                    'optional' => [],
                ],
                'aws_sns' => [
                    'label'    => 'AWS SNS',
                    'required' => ['SMS_AWS_REGION', 'SMS_AWS_ACCESS_KEY', 'SMS_AWS_SECRET_KEY'],
                    'optional' => ['SMS_AWS_TOPIC_ARN'],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'storage' => [
            'label'          => 'File Storage',
            'driver_var'     => 'STORAGE_DRIVER',
            'default_driver' => 'local',
            'providers' => [
                'local' => [
                    'label'    => 'Local filesystem',
                    'required' => ['STORAGE_PATH'],
                    'optional' => [],
                ],
                's3' => [
                    'label'    => 'S3-compatible',
                    'required' => ['S3_BUCKET', 'S3_REGION', 'S3_ACCESS_KEY', 'S3_SECRET_KEY'],
                    'optional' => ['S3_ENDPOINT', 'S3_USE_PATH_STYLE', 'S3_PUBLIC_URL'],
                ],
                'gcs' => [
                    'label'    => 'Google Cloud Storage',
                    'required' => ['GCS_BUCKET', 'GCS_PROJECT_ID', 'GCS_KEY_FILE'],
                    'optional' => [],
                ],
            ],
        ],

        'ai' => [
            'label'          => 'AI',
            'driver_var'     => 'AI_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'openai' => [
                    'label'    => 'OpenAI',
                    'required' => ['AI_OPENAI_API_KEY'],
                    'optional' => ['AI_OPENAI_MODEL'],
                ],
                'anthropic' => [
                    'label'    => 'Anthropic',
                    'required' => ['AI_ANTHROPIC_API_KEY'],
                    'optional' => ['AI_ANTHROPIC_MODEL'],
                ],
                'gemini' => [
                    'label'    => 'Google Gemini',
                    'required' => ['AI_GEMINI_API_KEY'],
                    'optional' => ['AI_GEMINI_MODEL'],
                ],
                'bedrock' => [
                    'label'    => 'Amazon Bedrock',
                    'required' => ['AI_BEDROCK_REGION', 'AI_BEDROCK_ACCESS_KEY', 'AI_BEDROCK_SECRET_KEY'],
                    'optional' => ['AI_BEDROCK_MODEL'],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'broadcast' => [
            'label'          => 'Event Broadcasting',
            'driver_var'     => 'BROADCAST_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'pusher' => [
                    'label'    => 'Pusher',
                    'required' => ['BROADCAST_APP_ID', 'BROADCAST_KEY', 'BROADCAST_SECRET'],
                    'optional' => ['BROADCAST_HOST', 'BROADCAST_CLUSTER'],
                ],
                'soketi' => [
                    'label'    => 'Soketi (Pusher-compatible)',
                    'required' => ['BROADCAST_APP_ID', 'BROADCAST_KEY', 'BROADCAST_SECRET', 'BROADCAST_HOST'],
                    'optional' => [],
                ],
                'ably' => [
                    'label'    => 'Ably',
                    'required' => ['BROADCAST_ABLY_API_KEY'],
                    'optional' => [],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'oauth' => [
            'label'          => 'OAuth / Social Login',
            'driver_var'     => null, // OAuth has no single provider — multiple can be enabled
            'default_driver' => '',
            'providers' => [
                'google'    => ['label' => 'Google',    'required' => ['OAUTH_GOOGLE_CLIENT_ID',    'OAUTH_GOOGLE_CLIENT_SECRET'],    'optional' => []],
                'microsoft' => ['label' => 'Microsoft', 'required' => ['OAUTH_MICROSOFT_CLIENT_ID', 'OAUTH_MICROSOFT_CLIENT_SECRET'], 'optional' => []],
                'apple'     => ['label' => 'Apple',     'required' => ['OAUTH_APPLE_CLIENT_ID',     'OAUTH_APPLE_CLIENT_SECRET'],     'optional' => ['OAUTH_APPLE_KEY_FILE', 'OAUTH_APPLE_TEAM_ID', 'OAUTH_APPLE_KEY_ID']],
                'facebook'  => ['label' => 'Facebook',  'required' => ['OAUTH_FACEBOOK_CLIENT_ID',  'OAUTH_FACEBOOK_CLIENT_SECRET'],  'optional' => []],
                'linkedin'  => ['label' => 'LinkedIn',  'required' => ['OAUTH_LINKEDIN_CLIENT_ID',  'OAUTH_LINKEDIN_CLIENT_SECRET'],  'optional' => []],
            ],
        ],

        // Sentry has no provider selector — either SENTRY_DSN is set or it's not.
        'sentry' => [
            'label'          => 'Error Tracking (Sentry)',
            'driver_var'     => null,
            'default_driver' => '',
            'providers' => [
                'sentry' => [
                    'label'    => 'Sentry',
                    'required' => ['SENTRY_DSN'],
                    'optional' => ['SENTRY_ENVIRONMENT', 'SENTRY_TRACES_SAMPLE_RATE'],
                ],
            ],
        ],

        'captcha' => [
            'label'          => 'CAPTCHA / Bot Protection',
            'driver_var'     => 'CAPTCHA_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'turnstile' => [
                    'label'    => 'Cloudflare Turnstile',
                    'required' => ['CAPTCHA_SITE_KEY', 'CAPTCHA_SECRET_KEY'],
                    'optional' => [],
                ],
                'recaptcha' => [
                    'label'    => 'Google reCAPTCHA',
                    'required' => ['CAPTCHA_SITE_KEY', 'CAPTCHA_SECRET_KEY'],
                    'optional' => [],
                ],
                'hcaptcha' => [
                    'label'    => 'hCaptcha',
                    'required' => ['CAPTCHA_SITE_KEY', 'CAPTCHA_SECRET_KEY'],
                    'optional' => [],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'cache' => [
            'label'          => 'Cache',
            'driver_var'     => 'CACHE_DRIVER',
            'default_driver' => 'file',
            'providers' => [
                'file' => [
                    'label'    => 'Filesystem (zero-config)',
                    'required' => [],
                    'optional' => ['STORAGE_PATH'],
                ],
                'redis' => [
                    'label'    => 'Redis / Valkey',
                    'required' => [], // REDIS_URL OR REDIS_HOST — validated dynamically
                    'optional' => ['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_DB'],
                ],
                'memcached' => [
                    'label'    => 'Memcached',
                    'required' => ['MEMCACHED_SERVERS'],
                    'optional' => ['MEMCACHED_USERNAME', 'MEMCACHED_PASSWORD'],
                ],
            ],
        ],

        'analytics' => [
            'label'          => 'Analytics',
            'driver_var'     => 'ANALYTICS_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'plausible' => [
                    'label'    => 'Plausible',
                    'required' => ['ANALYTICS_PLAUSIBLE_DOMAIN'],
                    'optional' => ['ANALYTICS_PLAUSIBLE_HOST'],
                ],
                'ga' => [
                    'label'    => 'Google Analytics 4',
                    'required' => ['ANALYTICS_GA_MEASUREMENT_ID'],
                    'optional' => [],
                ],
                'umami' => [
                    'label'    => 'Umami',
                    'required' => ['ANALYTICS_UMAMI_WEBSITE_ID', 'ANALYTICS_UMAMI_HOST'],
                    'optional' => [],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'payments' => [
            'label'          => 'Payments',
            'driver_var'     => 'PAYMENTS_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'stripe' => [
                    'label'    => 'Stripe',
                    'required' => ['STRIPE_SECRET_KEY'],
                    'optional' => ['STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'],
                ],
                'braintree' => [
                    'label'    => 'Braintree / PayPal',
                    'required' => ['BRAINTREE_MERCHANT_ID', 'BRAINTREE_PUBLIC_KEY', 'BRAINTREE_PRIVATE_KEY'],
                    'optional' => ['BRAINTREE_ENVIRONMENT'],
                ],
                'square' => [
                    'label'    => 'Square',
                    'required' => ['SQUARE_ACCESS_TOKEN', 'SQUARE_LOCATION_ID'],
                    'optional' => ['SQUARE_APPLICATION_ID', 'SQUARE_ENVIRONMENT', 'SQUARE_WEBHOOK_SIGNATURE_KEY'],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'media_cdn' => [
            'label'          => 'Media CDN / Image Transformation',
            'driver_var'     => 'MEDIA_CDN_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'cloudinary' => [
                    'label'    => 'Cloudinary',
                    'required' => ['MEDIA_CDN_CLOUDINARY_CLOUD_NAME'],
                    'optional' => ['MEDIA_CDN_CLOUDINARY_API_KEY', 'MEDIA_CDN_CLOUDINARY_API_SECRET'],
                ],
                'imgix' => [
                    'label'    => 'imgix',
                    'required' => ['MEDIA_CDN_IMGIX_DOMAIN'],
                    'optional' => ['MEDIA_CDN_IMGIX_SIGNING_KEY'],
                ],
                'cloudflare' => [
                    'label'    => 'Cloudflare Images',
                    'required' => ['MEDIA_CDN_CLOUDFLARE_DELIVERY_HASH'],
                    'optional' => ['MEDIA_CDN_CLOUDFLARE_ACCOUNT_ID', 'MEDIA_CDN_CLOUDFLARE_API_TOKEN'],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        'search' => [
            'label'          => 'Search',
            'driver_var'     => 'SEARCH_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'meilisearch' => [
                    'label'    => 'Meilisearch',
                    'required' => ['SEARCH_MEILISEARCH_HOST'],
                    'optional' => ['SEARCH_MEILISEARCH_API_KEY'],
                ],
                'algolia' => [
                    'label'    => 'Algolia',
                    'required' => ['SEARCH_ALGOLIA_APP_ID', 'SEARCH_ALGOLIA_API_KEY'],
                    'optional' => [],
                ],
                'opensearch' => [
                    'label'    => 'AWS OpenSearch',
                    'required' => ['SEARCH_OPENSEARCH_HOST'],
                    'optional' => ['SEARCH_OPENSEARCH_USERNAME', 'SEARCH_OPENSEARCH_PASSWORD'],
                ],
                'none' => ['label' => 'Disabled (use MySQL FULLTEXT)', 'required' => [], 'optional' => []],
            ],
        ],

        'video' => [
            'label'          => 'Video Hosting',
            'driver_var'     => 'VIDEO_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'mux' => [
                    'label'    => 'Mux',
                    'required' => ['VIDEO_MUX_TOKEN_ID', 'VIDEO_MUX_TOKEN_SECRET'],
                    'optional' => ['VIDEO_MUX_SIGNING_KEY_ID', 'VIDEO_MUX_SIGNING_KEY'],
                ],
                'cloudflare_stream' => [
                    'label'    => 'Cloudflare Stream',
                    'required' => ['VIDEO_CLOUDFLARE_ACCOUNT_ID', 'VIDEO_CLOUDFLARE_API_TOKEN'],
                    'optional' => [],
                ],
                'vimeo' => [
                    'label'    => 'Vimeo',
                    'required' => ['VIDEO_VIMEO_ACCESS_TOKEN'],
                    'optional' => ['VIDEO_VIMEO_USER_ID'],
                ],
                'aws_ivs' => [
                    'label'    => 'AWS Interactive Video Service',
                    'required' => ['VIDEO_AWS_REGION', 'VIDEO_AWS_ACCESS_KEY', 'VIDEO_AWS_SECRET_KEY'],
                    'optional' => [],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],

        // CDN — programmatic cache purge / invalidation. Distinct from
        // ASSET_URL (which only rewrites public URLs). Used by CdnService.
        'cdn' => [
            'label'          => 'CDN (purge / invalidation)',
            'driver_var'     => 'CDN_PROVIDER',
            'default_driver' => 'none',
            'providers' => [
                'cloudflare' => [
                    'label'    => 'Cloudflare',
                    'required' => ['CDN_CLOUDFLARE_ZONE_ID', 'CDN_CLOUDFLARE_API_TOKEN'],
                    'optional' => [],
                ],
                'cloudfront' => [
                    'label'    => 'AWS CloudFront',
                    'required' => ['CDN_CLOUDFRONT_DISTRIBUTION_ID', 'CDN_CLOUDFRONT_AWS_REGION', 'CDN_CLOUDFRONT_AWS_ACCESS_KEY', 'CDN_CLOUDFRONT_AWS_SECRET_KEY'],
                    'optional' => [],
                ],
                'fastly' => [
                    'label'    => 'Fastly',
                    'required' => ['CDN_FASTLY_SERVICE_ID', 'CDN_FASTLY_API_TOKEN'],
                    'optional' => [],
                ],
                'bunny' => [
                    'label'    => 'BunnyCDN',
                    'required' => ['CDN_BUNNY_PULL_ZONE_ID', 'CDN_BUNNY_API_KEY'],
                    'optional' => [],
                ],
                'none' => ['label' => 'Disabled', 'required' => [], 'optional' => []],
            ],
        ],
    ];

    /** All known integration type keys. */
    public static function types(): array
    {
        return array_keys(self::DEFS);
    }

    /**
     * The currently selected provider for an integration. Returns ''
     * (empty string) for OAuth, which has no single selector — callers
     * should iterate oauthProviders() instead.
     */
    public static function provider(string $type): string
    {
        $def = self::DEFS[$type] ?? null;
        if (!$def || !$def['driver_var']) return '';
        $val = trim((string) (self::env($def['driver_var']) ?? ''));
        return $val !== '' ? strtolower($val) : $def['default_driver'];
    }

    /**
     * True when the integration has a non-'none' provider AND every required
     * env var for that provider is present. For OAuth, true when ANY
     * provider is fully configured.
     */
    public static function enabled(string $type): bool
    {
        $def = self::DEFS[$type] ?? null;
        if (!$def) return false;

        // OAuth: any provider with full credentials counts as enabled.
        if ($type === 'oauth') {
            foreach (array_keys($def['providers']) as $p) {
                if (self::providerConfigured($type, $p)) return true;
            }
            return false;
        }

        $p = self::provider($type);
        if ($p === '' || $p === 'none') return false;
        return self::providerConfigured($type, $p);
    }

    /**
     * Config array for the active provider of a single-provider integration.
     * Keys are the env var names (stripped of the integration prefix) and
     * values are the env values. Useful for MailService/SmsService etc. to
     * read credentials in one call.
     *
     *   IntegrationConfig::config('email') ->
     *     ['driver' => 'smtp', 'host' => 'smtp.x', 'port' => '587', ...]
     */
    public static function config(string $type): array
    {
        $def = self::DEFS[$type] ?? null;
        if (!$def) return [];

        if ($type === 'oauth') {
            // OAuth returns a nested map of provider => provider config
            $out = [];
            foreach ($def['providers'] as $pKey => $pDef) {
                $out[$pKey] = self::collectVars(array_merge($pDef['required'], $pDef['optional']));
            }
            return $out;
        }

        $provider = self::provider($type);
        $out      = ['driver' => $provider];
        $pDef     = $def['providers'][$provider] ?? null;
        if ($pDef) {
            $vars = array_merge($pDef['required'], $pDef['optional'], $def['from_vars'] ?? []);
            $out  = array_merge($out, self::collectVars($vars));
        }
        return $out;
    }

    /** Structured status list for the /admin/integrations dashboard. */
    public static function describeAll(): array
    {
        $out = [];
        foreach (self::DEFS as $type => $def) {
            $out[] = self::describe($type);
        }
        return $out;
    }

    public static function describe(string $type): array
    {
        $def = self::DEFS[$type] ?? null;
        if (!$def) return [];

        if ($type === 'oauth') {
            $sub = [];
            foreach ($def['providers'] as $pKey => $pDef) {
                $sub[] = [
                    'key'        => $pKey,
                    'label'      => $pDef['label'],
                    'configured' => self::providerConfigured($type, $pKey),
                    'required'   => $pDef['required'],
                    'optional'   => $pDef['optional'],
                ];
            }
            return [
                'type'       => 'oauth',
                'label'      => $def['label'],
                'kind'       => 'multi',
                'providers'  => $sub,
                'configured' => self::enabled('oauth'),
            ];
        }

        $provider  = self::provider($type);
        $pDef      = $def['providers'][$provider] ?? null;
        return [
            'type'          => $type,
            'label'         => $def['label'],
            'kind'          => 'single',
            'driver_var'    => $def['driver_var'],
            'provider'      => $provider,
            'provider_label'=> $pDef['label'] ?? $provider,
            'required'      => $pDef['required'] ?? [],
            'optional'      => $pDef['optional'] ?? [],
            'configured'    => self::enabled($type),
        ];
    }

    /** Whether every required env var for a given provider is present. */
    public static function providerConfigured(string $type, string $provider): bool
    {
        $def = self::DEFS[$type] ?? null;
        if (!$def) return false;
        $pDef = $def['providers'][$provider] ?? null;
        if (!$pDef) return false;
        // Providers with no required vars (e.g. 'log', 'none') count as
        // configured if they're the active selection.
        if (empty($pDef['required'])) return true;
        foreach ($pDef['required'] as $varName) {
            if (trim((string) (self::env($varName) ?? '')) === '') return false;
        }
        return true;
    }

    /** Read a single env var, supporting both $_ENV and getenv() sources. */
    public static function env(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            $v = $_ENV[$key];
            return $v === false ? null : (string) $v;
        }
        $v = getenv($key);
        return $v === false ? null : $v;
    }

    /**
     * Collect a list of env vars into an associative array keyed by a
     * canonical short name (lowercased, prefix-stripped for readability).
     */
    private static function collectVars(array $vars): array
    {
        $out = [];
        foreach ($vars as $v) {
            $out[self::shortKey($v)] = self::env($v);
        }
        return $out;
    }

    /**
     * Map full env var names like MAIL_SENDGRID_API_KEY to a friendly key
     * the consuming service can use (api_key). This deliberately strips
     * the integration/provider prefix so callers don't rebuild the var
     * names themselves.
     */
    private static function shortKey(string $envVar): string
    {
        $prefixes = [
            'MAIL_SENDGRID_', 'MAIL_MAILGUN_', 'MAIL_SES_', 'MAIL_',
            'SMS_TWILIO_', 'SMS_VONAGE_', 'SMS_AWS_', 'SMS_',
            'S3_', 'GCS_',
            'AI_OPENAI_', 'AI_ANTHROPIC_', 'AI_GEMINI_', 'AI_BEDROCK_', 'AI_',
            'BROADCAST_ABLY_', 'BROADCAST_',
            'OAUTH_GOOGLE_', 'OAUTH_MICROSOFT_', 'OAUTH_APPLE_', 'OAUTH_FACEBOOK_', 'OAUTH_LINKEDIN_', 'OAUTH_',
            'STORAGE_',
            'SENTRY_',
            'CAPTCHA_',
            'REDIS_', 'MEMCACHED_', 'CACHE_',
            'ANALYTICS_PLAUSIBLE_', 'ANALYTICS_GA_', 'ANALYTICS_UMAMI_', 'ANALYTICS_',
            'STRIPE_', 'BRAINTREE_', 'SQUARE_', 'PAYMENTS_',
            'MEDIA_CDN_CLOUDINARY_', 'MEDIA_CDN_IMGIX_', 'MEDIA_CDN_CLOUDFLARE_', 'MEDIA_CDN_',
            'SEARCH_MEILISEARCH_', 'SEARCH_ALGOLIA_', 'SEARCH_OPENSEARCH_', 'SEARCH_',
            'VIDEO_MUX_', 'VIDEO_CLOUDFLARE_', 'VIDEO_VIMEO_', 'VIDEO_AWS_', 'VIDEO_',
            'CDN_CLOUDFLARE_', 'CDN_CLOUDFRONT_AWS_', 'CDN_CLOUDFRONT_', 'CDN_FASTLY_', 'CDN_BUNNY_', 'CDN_',
        ];
        foreach ($prefixes as $p) {
            if (str_starts_with($envVar, $p)) {
                return strtolower(substr($envVar, strlen($p)));
            }
        }
        return strtolower($envVar);
    }
}
