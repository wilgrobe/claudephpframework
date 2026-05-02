<?php
// modules/api-keys/module.php
use Core\Module\ModuleProvider;

/**
 * API-keys module — user-generated scoped tokens for /api/*.
 *
 * Security posture in one line: plaintext tokens are returned exactly
 * once (at mint); only SHA-256 hashes are stored; UNIQUE on token_hash
 * makes lookup an indexed constant-time check.
 *
 * Token format: phpk_live_{32 url-safe base64 chars} → 192 bits of
 * entropy. The prefix is recognizable in logs so ops can spot an
 * accidentally-committed key.
 *
 * Middleware: Modules\ApiKeys\Middleware\ApiAuthMiddleware accepts
 * `Authorization: Bearer <token>`, stashes {user_id, key_id, scopes}
 * on $_SERVER under X_API_AUTH_*, and JSONs a 401 on failure.
 * Session cookies are neither read nor set on /api routes — CSRF is
 * implicitly not an issue because there's no ambient credential.
 *
 * Scope-checking is the caller's responsibility — the middleware only
 * verifies that the presented token is valid. Controllers check
 * required scopes via ApiKeyService::hasScopes($required, $granted).
 */
return new class extends ModuleProvider {
    // Namespace must match View::addNamespace's /^[a-zA-Z0-9_]+$/ regex.
    public function name(): string            { return 'api_keys'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }
};
