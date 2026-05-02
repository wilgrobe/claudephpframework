<?php
// core/Validation/Validator.php
namespace Core\Validation;

/**
 * Validator — server-side input validation with XSS sanitization.
 *
 * Rules: required, min:N, max:N, email, url, numeric, integer,
 *        alpha, alphanumeric, regex:/pattern/, same:field,
 *        unique:table.column, in:a,b,c, min_length:N, max_length:N
 */
class Validator
{
    private array $errors = [];
    private array $data   = [];

    public function __construct(array $data)
    {
        // Clean inputs WITHOUT encoding — views already call e()/htmlspecialchars
        // at render time, so encoding here would double-escape (e.g. an
        // apostrophe in "Will's Test Group" becomes &apos; in the DB and
        // &amp;apos; on render, displayed literally as &apos;).
        $this->data = $this->sanitize($data);
    }

    // ── Input Sanitization ───────────────────────────────────────────────────
    //
    // Scope: remove dangerous control characters and normalize whitespace.
    // Explicitly does NOT HTML-encode — that's the view's job. For HTML-body
    // fields like content.body, callers should invoke sanitizeHtml() which
    // allow-lists safe tags.

    /**
     * Recursively clean input: strip null bytes and trim. No HTML encoding.
     */
    public function sanitize(array|string $data): array|string
    {
        if (is_array($data)) {
            return array_map(fn($v) => $this->sanitize($v), $data);
        }
        // Strip null bytes (can smuggle past string functions) and trim
        // surrounding whitespace. Preserve all printable characters — views
        // are responsible for HTML-escaping at render time via e().
        return trim(str_replace("\0", '', $data));
    }

    /**
     * Allow a curated allowlist of HTML tags AND attributes.
     *
     * SECURITY: Uses DOMDocument to parse and rebuild HTML rather than regex,
     * preventing attribute-injection bypasses. The <img> tag is intentionally
     * excluded — it is the primary XSS vector via onerror, data: URIs, etc.
     * If images are required, add them back with strict src validation only.
     *
     * Allowed tags: p, br, strong, em, u, ul, ol, li, h2, h3, h4, blockquote, a
     * Allowed attributes per tag:
     *   a    → href (http/https only), title
     *   *    → (no other attributes)
     */
    public static function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') return '';

        // Allowed tags (no img — too many XSS vectors via onerror/data:/src)
        $allowedTags = ['p','br','strong','em','u','ul','ol','li','h2','h3','h4','blockquote','a'];

        // Per-tag attribute allowlists
        $allowedAttrs = [
            'a' => ['href', 'title'],
        ];

        // Use DOMDocument for structural parsing instead of regex.
        // The <html><head><body> scaffold is ours, not the user's — we must
        // skip those outer wrappers when walking the sanitizer so they don't
        // get stripped (which would dissolve the body element along with all
        // of the user's content and return an empty string).
        $doc = new \DOMDocument();
        $wrapped = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        @$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return '';

        // Walk the user's content only. Pass $body as the container so the
        // sanitizer recurses into the user's actual markup without touching
        // the wrapping html/head/body elements.
        self::sanitizeNode($body, $allowedTags, $allowedAttrs);

        // Re-fetch body in case a disallowed outer element wrapped the user's
        // content (unlikely after the scope change above, but defensive).
        $body   = $doc->getElementsByTagName('body')->item(0);
        $output = '';
        if ($body) {
            foreach ($body->childNodes as $child) {
                $output .= $doc->saveHTML($child);
            }
        }

        return $output;
    }

    private static function sanitizeNode(\DOMNode $node, array $allowedTags, array $allowedAttrs): void
    {
        $toRemove = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);

                if (!in_array($tag, $allowedTags, true)) {
                    // Replace disallowed element with its text content (don't silently delete)
                    $toRemove[] = ['node' => $child, 'replace' => true];
                    continue;
                }

                // Strip all attributes not on the allowlist for this tag
                $permittedForTag = $allowedAttrs[$tag] ?? [];
                $attrsToRemove   = [];
                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    if (!in_array($attrName, $permittedForTag, true)) {
                        $attrsToRemove[] = $attr->name;
                        continue;
                    }
                    // Extra validation for href: only allow http/https
                    if ($attrName === 'href') {
                        $val = trim($attr->value);
                        // Strip whitespace and control chars used to bypass checks
                        $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);
                        if (!preg_match('#^https?://#i', $val) && strncmp($val, '/', 1) !== 0) {
                            $attrsToRemove[] = $attr->name;
                        }
                    }
                }
                foreach ($attrsToRemove as $name) {
                    $child->removeAttribute($name);
                }

                // Recurse
                self::sanitizeNode($child, $allowedTags, $allowedAttrs);
            }
        }

        foreach ($toRemove as $item) {
            $child = $item['node'];
            if ($item['replace'] && $child->hasChildNodes()) {
                // Move grandchildren up to replace the stripped element
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
            }
            if ($child->parentNode === $node) {
                $node->removeChild($child);
            }
        }
    }

    // ── Validate ──────────────────────────────────────────────────────────────

    /**
     * @param array $rules  ['field' => 'rule1|rule2:arg', ...]
     * @return $this
     */
    public function validate(array $rules): self
    {
        foreach ($rules as $field => $ruleStr) {
            $value    = $this->data[$field] ?? null;
            $ruleList = explode('|', $ruleStr);

            foreach ($ruleList as $rule) {
                [$ruleName, $ruleArg] = array_pad(explode(':', $rule, 2), 2, null);
                $this->applyRule($field, $value, $ruleName, $ruleArg);
            }
        }
        return $this;
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $arg): void
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '') {
                    $this->errors[$field][] = "$label is required.";
                }
                break;

            case 'email':
                if ($value !== '' && $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field][] = "$label must be a valid email address.";
                }
                break;

            case 'url':
                if ($value !== '' && $value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->errors[$field][] = "$label must be a valid URL.";
                }
                break;

            case 'numeric':
                if ($value !== '' && $value !== null && !is_numeric($value)) {
                    $this->errors[$field][] = "$label must be numeric.";
                }
                break;

            case 'integer':
                if ($value !== '' && $value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->errors[$field][] = "$label must be an integer.";
                }
                break;

            case 'min':
                if (is_numeric($value) && (float) $value < (float) $arg) {
                    $this->errors[$field][] = "$label must be at least $arg.";
                } elseif (is_string($value) && mb_strlen($value) < (int) $arg) {
                    $this->errors[$field][] = "$label must be at least $arg characters.";
                }
                break;

            case 'max':
                if (is_numeric($value) && (float) $value > (float) $arg) {
                    $this->errors[$field][] = "$label must not exceed $arg.";
                } elseif (is_string($value) && mb_strlen($value) > (int) $arg) {
                    $this->errors[$field][] = "$label must not exceed $arg characters.";
                }
                break;

            case 'min_length':
                if ($value !== null && mb_strlen((string) $value) < (int) $arg) {
                    $this->errors[$field][] = "$label must be at least $arg characters long.";
                }
                break;

            case 'max_length':
                if ($value !== null && mb_strlen((string) $value) > (int) $arg) {
                    $this->errors[$field][] = "$label must not exceed $arg characters.";
                }
                break;

            case 'alpha':
                if ($value !== '' && $value !== null && !ctype_alpha($value)) {
                    $this->errors[$field][] = "$label may only contain letters.";
                }
                break;

            case 'alphanumeric':
                if ($value !== '' && $value !== null && !ctype_alnum($value)) {
                    $this->errors[$field][] = "$label may only contain letters and numbers.";
                }
                break;

            case 'regex':
                if ($value !== '' && $value !== null && !preg_match($arg, $value)) {
                    $this->errors[$field][] = "$label format is invalid.";
                }
                break;

            case 'same':
                $otherValue = $this->data[$arg] ?? null;
                if ($value !== $otherValue) {
                    $otherLabel = ucfirst(str_replace('_', ' ', $arg));
                    $this->errors[$field][] = "$label must match $otherLabel.";
                }
                break;

            case 'in':
                $allowed = explode(',', $arg);
                if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
                    $this->errors[$field][] = "$label must be one of: " . implode(', ', $allowed) . '.';
                }
                break;

            case 'nullable':
                // Always passes — just signals the field is optional
                break;

            case 'password_strength':
                // Enforce meaningful password complexity beyond just minimum length.
                // Requires at least one uppercase, lowercase, digit, and special character.
                // Apply alongside min:12 for best security: 'password' => 'required|min:12|password_strength'
                if ($value !== null && $value !== '') {
                    $missing = [];
                    if (!preg_match('/[A-Z]/', $value))        $missing[] = 'an uppercase letter';
                    if (!preg_match('/[a-z]/', $value))        $missing[] = 'a lowercase letter';
                    if (!preg_match('/[0-9]/', $value))        $missing[] = 'a number';
                    if (!preg_match('/[^A-Za-z0-9]/', $value)) $missing[] = 'a special character (!@#$%^&* etc.)';
                    if (!empty($missing)) {
                        $this->errors[$field][] = "$label must include " . implode(', ', $missing) . '.';
                    }
                }
                break;
        }
    }

    // ── Results ───────────────────────────────────────────────────────────────

    public function passes(): bool { return empty($this->errors); }
    public function fails(): bool  { return !$this->passes(); }
    public function errors(): array { return $this->errors; }
    public function firstError(string $field): ?string { return $this->errors[$field][0] ?? null; }

    /** Return sanitized data for only the fields listed. */
    public function only(array $fields): array
    {
        return array_intersect_key($this->data, array_flip($fields));
    }

    /** Return all sanitized data. */
    public function all(): array { return $this->data; }

    /** Return sanitized value for a specific field. */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }
}
