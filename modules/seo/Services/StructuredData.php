<?php
// modules/seo/Services/StructuredData.php
namespace Modules\Seo\Services;

/**
 * Structured data (schema.org JSON-LD) that tells search engines and AI answer engines what the site IS, by the kind
 * of business it is: who runs it, its address, how to reach it, and the site itself. Emitted on the homepage.
 *
 * ⚠ ONLY WHAT THE SITE SAYS (lesson from StoriesDen's SeoMarkup): names, descriptions, address and contact come from
 * the site's own settings. No ratings, review counts or prices — structured data that claims more than the page is
 * what search engines penalise.
 *
 * graph() is pure; forSite() reads the settings.
 */
final class StructuredData
{
    /** Business types an owner can pick: schema type => label. LocalBusiness kinds share the address fields. */
    public const TYPES = [
        'Organization'            => 'Company or organisation',
        'LocalBusiness'           => 'Local business (has a place customers visit)',
        'Restaurant'              => 'Restaurant or café',
        'Store'                   => 'Shop (physical)',
        'OnlineStore'             => 'Online shop',
        'ProfessionalService'     => 'Professional service (agency, consultant, accountant…)',
        'HealthAndBeautyBusiness' => 'Health and beauty',
        'MedicalBusiness'         => 'Medical practice or clinic',
        'LegalService'            => 'Legal service',
        'HomeAndConstructionBusiness' => 'Home services and construction',
        'LodgingBusiness'         => 'Hotel, B&B or other lodging',
        'SportsActivityLocation'  => 'Gym, studio or sports venue',
        'EducationalOrganization' => 'School, course provider or tutor',
        'SoftwareApplication'     => 'Software or web app',
        'NewsMediaOrganization'   => 'News, magazine or blog',
        'NGO'                     => 'Charity or non-profit',
        'Person'                  => 'A person (personal site or portfolio)',
    ];

    /** Types that are places: they carry the postal address and phone as a local business. */
    private const LOCAL = ['LocalBusiness', 'Restaurant', 'Store', 'ProfessionalService', 'HealthAndBeautyBusiness',
        'MedicalBusiness', 'LegalService', 'HomeAndConstructionBusiness', 'LodgingBusiness', 'SportsActivityLocation'];

    /**
     * Pure.
     * @param array{type?:string,name?:string,description?:string,email?:string,phone?:string,logo?:string,
     *              street?:string,city?:string,region?:string,postal?:string,country?:string,same_as?:string} $b
     */
    public static function graph(string $base, array $b): array
    {
        $base = rtrim($base, '/');
        $type = (string) ($b['type'] ?? 'Organization');
        if (!isset(self::TYPES[$type])) { $type = 'Organization'; }
        $name = trim((string) ($b['name'] ?? ''));
        if ($base === '' || $name === '') { return []; }

        $isPerson = $type === 'Person';
        $isApp    = $type === 'SoftwareApplication';
        $orgId    = $base . ($isPerson ? '/#person' : '/#organization');

        $org = [
            '@type' => $isApp ? 'Organization' : $type,
            '@id'   => $orgId,
            'name'  => $name,
            'url'   => $base . '/',
        ];
        $desc = trim((string) ($b['description'] ?? ''));
        if ($desc !== '' && !$isApp) { $org['description'] = $desc; }
        $logo = self::url((string) ($b['logo'] ?? ''), $base);
        if ($logo !== '') { $org[$isPerson ? 'image' : 'logo'] = $logo; }
        $email = trim((string) ($b['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) { $org['email'] = $email; }
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^[0-9+().\-\s]{5,30}$/', $phone)) { $org['telephone'] = $phone; }

        $addr = array_filter([
            'streetAddress'   => trim((string) ($b['street'] ?? '')),
            'addressLocality' => trim((string) ($b['city'] ?? '')),
            'addressRegion'   => trim((string) ($b['region'] ?? '')),
            'postalCode'      => trim((string) ($b['postal'] ?? '')),
            'addressCountry'  => trim((string) ($b['country'] ?? '')),
        ], static fn ($v) => $v !== '');
        if ($addr && (in_array($type, self::LOCAL, true) || $type === 'EducationalOrganization' || $type === 'NGO' || $type === 'Organization')) {
            $org['address'] = ['@type' => 'PostalAddress'] + $addr;
        }

        $same = [];
        foreach (preg_split('/[\s,]+/', (string) ($b['same_as'] ?? '')) ?: [] as $u) {
            if (preg_match('#^https://[^\s"<>]+$#i', $u)) { $same[] = $u; }
        }
        if ($same) { $org['sameAs'] = array_values(array_unique($same)); }

        $graph = [$org];
        $graph[] = [
            '@type'     => 'WebSite',
            '@id'       => $base . '/#website',
            'name'      => $name,
            'url'       => $base . '/',
            'publisher' => ['@id' => $orgId],
        ];
        if ($isApp) {
            $app = [
                '@type'               => 'SoftwareApplication',
                '@id'                 => $base . '/#software',
                'name'                => $name,
                'url'                 => $base . '/',
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem'     => 'Web browser',
                'publisher'           => ['@id' => $orgId],
            ];
            if ($desc !== '') { $app['description'] = $desc; }
            $graph[] = $app;
        }
        return $graph;
    }

    /** The <script> tag. JSON_HEX_TAG keeps a "</script>" inside any copy from closing it early. */
    public static function script(array $graph): string
    {
        if (!$graph) { return ''; }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /** The homepage's block, from this site's settings (business fields fall back to the site name and tagline). */
    public static function forSite(string $base): string
    {
        $b = [];
        foreach (['type', 'name', 'description', 'email', 'phone', 'street', 'city', 'region', 'postal', 'country', 'same_as'] as $f) {
            $b[$f] = SearchSettings::get('seo.business.' . $f);
        }
        if ($b['name'] === '')        { $b['name'] = self::siteSetting('site_name'); }
        if ($b['description'] === '') { $b['description'] = self::siteSetting('site_tagline'); }
        if ($b['email'] === '')       { $b['email'] = self::siteSetting('contact_email') ?: self::siteSetting('site_email'); }
        $b['logo'] = self::siteSetting('builder.branding.logo_url');
        return self::script(self::graph($base, $b));
    }

    private static function siteSetting(string $key): string
    {
        try {
            $v = function_exists('setting') ? setting($key, '') : '';
            return is_scalar($v) ? trim((string) $v) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** An absolute https/http URL, or a site path made absolute; anything else (data:, javascript:) is dropped. */
    private static function url(string $u, string $base): string
    {
        $u = trim($u);
        if ($u === '') { return ''; }
        if (preg_match('#^https?://[^\s"<>]+$#i', $u)) { return $u; }
        if ($u[0] === '/' && !str_starts_with($u, '//')) { return $base . $u; }
        return '';
    }
}
