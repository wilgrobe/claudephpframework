<?php
// tests/Unit/Modules/Seo/GetFoundPageTextTest.php
namespace Tests\Unit\Modules\Seo;

use Modules\Seo\Services\PageText;
use Modules\Seo\Services\SearchHead;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;
use Tests\TestCase;

/**
 * 24 Nov 2026: the owner can set the title and description of any public address. Module pages (/faq, /blog, a
 * comparison) had no SEO fields, so every one carried the site's tagline as its description.
 */
final class GetFoundPageTextTest extends TestCase
{
    private const PAGE = "<html><head>\n<title>FAQ — Acme</title>\n<meta name=\"description\" content=\"Tagline.\">\n"
        . "<meta property=\"og:title\" content=\"FAQ — Acme\">\n<meta property=\"og:description\" content=\"Tagline.\">\n"
        . "<meta name=\"twitter:title\" content=\"FAQ — Acme\">\n<meta name=\"twitter:description\" content=\"Tagline.\">\n"
        . "<meta name=\"keywords\" content=\"a description here\">\n</head><body><svg><title>icon</title></svg></body></html>";

    public static function setUpBeforeClass(): void
    {
        foreach (['Services/SearchSettings', 'Services/SiteProfile', 'Services/SearchPages', 'Services/PageText', 'Services/SearchHead',
                  'Services/StructuredData'] as $f) {
            $class = 'Modules\\Seo\\' . str_replace('/', '\\', $f);
            if (!class_exists($class, false)) { require_once BASE_PATH . '/modules/seo/' . $f . '.php'; }
        }
    }

    protected function setUp(): void { parent::setUp(); SearchSettings::using([]); PageText::reset(); }
    protected function tearDown(): void { SearchSettings::using(null); PageText::reset(); parent::tearDown(); }

    public function testTheTitleAndDescriptionAndTheirSocialCopiesAreReplaced(): void
    {
        $out = PageText::apply(self::PAGE, ['title' => 'Plans from $49 & up', 'description' => 'Only $1 "today" \\1.']);
        $this->assertStringContainsString('<title>Plans from $49 &amp; up</title>', $out, '$49 is not a back-reference');
        $this->assertSame(3, substr_count($out, 'Only $1 &quot;today&quot; \1.'), 'description, og and twitter');
        $this->assertSame(3, substr_count($out, 'Plans from $49 &amp; up'), 'title, og and twitter');
        $this->assertStringNotContainsString('Tagline.', $out);
        $this->assertStringContainsString('content="a description here"', $out, 'another meta that mentions the word is left alone');
        $this->assertStringContainsString('<svg><title>icon</title></svg>', $out, 'only the head is touched');
    }

    public function testABlankPartIsLeftAsThePagePrintedIt(): void
    {
        $out = PageText::apply(self::PAGE, ['title' => '', 'description' => 'New.']);
        $this->assertStringContainsString('<title>FAQ — Acme</title>', $out);
        $this->assertStringContainsString('<meta name="description" content="New.">', $out);
        $out = PageText::apply(self::PAGE, ['title' => 'New', 'description' => '']);
        $this->assertStringContainsString('<meta name="description" content="Tagline.">', $out);
        $this->assertSame(self::PAGE, PageText::apply(self::PAGE, null));
        $this->assertSame('{"a":1}', PageText::apply('{"a":1}', ['title' => 'x', 'description' => 'y']), 'no head, no change');
    }

    public function testAPageWithoutTheTagsGetsThem(): void
    {
        $out = PageText::apply('<html><head></head><body></body></html>', ['title' => 'T', 'description' => 'D']);
        $this->assertStringContainsString("<title>T</title>\n<meta name=\"description\" content=\"D\">\n</head>", $out);
    }

    public function testAMetaWithItsAttributesTheOtherWayRoundIsReplacedToo(): void
    {
        $out = PageText::apply("<html><head><meta content='a > b' name='description'></head></html>", ['title' => '', 'description' => 'D']);
        $this->assertSame('<html><head><meta name="description" content="D"></head></html>', $out);
    }

    public function testTypedAddressesBecomePaths(): void
    {
        foreach (['/faq' => '/faq', 'faq' => '/faq', '/faq/' => '/faq', '/' => '/', 'https://acme.com' => '/', 'https://acme.com/for/shops?x=1#y' => '/for/shops',
                  '' => '', 'javascript:alert(1)' => '', '//evil.com' => '', '/a b' => '', '/a"b' => '', '/a<b' => '', '\\x' => ''] as $in => $want) {
            $this->assertSame($want, PageText::cleanPath((string) $in), "typed: $in");
        }
    }

    public function testTheFormIsCleanedAndABlankRowIsRemoved(): void
    {
        $json = PageText::fromPost([
            'text_path'        => ['faq', '/blog', '', '/empty', '/arr', 'javascript:x'],
            'text_title'       => ['  Big   <b>questions</b> ', '', 'no address', '', ['x'], 'bad'],
            'text_description' => ['', 'Posts é', 'x', '  ', 'kept', 'bad'],
        ]);
        $this->assertSame(['/faq' => ['title' => 'Big questions', 'description' => ''], '/blog' => ['title' => '', 'description' => 'Posts é'],
                           '/arr' => ['title' => '', 'description' => 'kept']], PageText::parse($json));
        $this->assertSame('', PageText::fromPost(['text_path' => ['/x'], 'text_title' => [''], 'text_description' => ['']]), 'nothing left = empty setting');
        $this->assertSame('', PageText::fromPost([]));
        $long = PageText::parse(PageText::encode(['/x' => ['title' => str_repeat('é', 500), 'description' => str_repeat('d', 500)]]));
        $this->assertSame([PageText::MAX_TITLE, PageText::MAX_DESCRIPTION], [mb_strlen($long['/x']['title']), mb_strlen($long['/x']['description'])]);
    }

    public function testABrokenSettingIsNoSetting(): void
    {
        foreach (['', 'nope', '[]', '"x"', '{"/a":"x"}', '{"/a":{"title":5}}'] as $bad) $this->assertSame([], PageText::parse($bad), $bad);
    }

    public function testTheStoredTextIsFoundByPath(): void
    {
        SearchSettings::using([PageText::KEY => PageText::encode(['/faq' => ['title' => 'Q', 'description' => 'A']])]);
        PageText::reset();
        $this->assertSame('Q', PageText::forPath('/faq/')['title'] ?? null);
        $this->assertNull(PageText::forPath('/faq/more'));
        $this->assertNull(PageText::forPath('/'));
    }

    /** The one place it is applied, the form that sets it, and the route: read AND written (CLAUDE.md). */
    public function testItIsWiredEndToEnd(): void
    {
        $src = static fn (string $f) => str_replace("\r\n", "\n", (string) file_get_contents(BASE_PATH . '/modules/seo/' . $f));
        $head = $src('Services/SearchHead.php');
        $a = strpos($head, 'PageText::apply($html, PageText::forPath($path))');
        $this->assertNotFalse($a);
        $this->assertGreaterThan(strpos($head, 'http_response_code() !== 200'), $a, 'only on a finished 200 HTML page');
        $this->assertLessThan(strpos($head, 'MarkdownPage::toMarkdown($html'), $a, 'the Markdown copy carries it too');
        $this->assertStringContainsString('"$A@saveText", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]', $src('routes.php'));
        $this->assertStringContainsString("set(PageText::KEY, \$json, 'site', null)", $src('Controllers/SearchSettingsController.php'));
        $view = $src('Views/admin/settings.php');
        foreach (['text_path[]', 'text_title[]', 'text_description[]', 'action="/admin/settings/search/text"'] as $f) $this->assertStringContainsString($f, $view);
        $this->assertStringContainsString('PageText::KEY', $src('module.php'), 'kept out of the generic settings grid');
    }

    /** StoriesDen's /sample-chapter named itself "/sample-chapter": a bare path becomes the whole address. */
    public function testABarePathCanonicalIsMadeWhole(): void
    {
        $p = SiteProfile::build(['server' => ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'], 'settings' => [], 'app_url' => 'https://example.com', 'builder' => false]);
        $this->assertSame('https://example.com', rtrim($p->base(), '/'));
        $this->assertSame('<link rel="canonical" href="https://example.com/sample-chapter">',
            SearchHead::fixCanonicalTag('<link rel="canonical" href="/sample-chapter">', $p));
        $this->assertSame('<link rel="canonical" href="//other.com/x">', SearchHead::fixCanonicalTag('<link rel="canonical" href="//other.com/x">', $p));
    }
}
