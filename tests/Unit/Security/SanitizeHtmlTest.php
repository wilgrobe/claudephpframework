<?php
// tests/Unit/Security/SanitizeHtmlTest.php
namespace Tests\Unit\Security;

use Core\Validation\Validator;
use Tests\TestCase;

/**
 * Validator::sanitizeHtml is the only thing between user-written HTML (page bodies, FAQ answers, policies, tooltips)
 * and every visitor's browser. It had no test (#466 test profile, 4 Nov 2026).
 *
 * The check is structural, not a string match: the OUTPUT is parsed again the way a browser would, and every element,
 * attribute and URL in it must be on the allow-list — so a new bypass shape fails here even if nobody thought of it.
 */
final class SanitizeHtmlTest extends TestCase
{
    /** The richer allow-list some surfaces pass (FAQ answers, tabs): tables, images, class. */
    private const RICH_TAGS  = ['p','br','strong','em','u','ul','ol','li','h2','h3','h4','blockquote','a','img','table','tr','td','th','code','pre','span','div'];
    private const RICH_ATTRS = ['a' => ['href', 'title'], 'img' => ['src', 'alt'], 'span' => ['class'], 'div' => ['class']];

    /** @return array<string, array{0:string}> */
    public static function attacks(): array
    {
        $v = [
            'script tag'                    => '<p>hi</p><script>alert(1)</script>',
            'script inside a disallowed div' => '<div><script>alert(1)</script></div>',
            'nested disallowed wrappers'    => '<section><article><script>alert(1)</script><p onclick="x()">t</p></article></section>',
            'event handler'                 => '<p onmouseover="alert(1)">x</p>',
            'event handler, odd case'       => '<P OnMouseOver="alert(1)">x</P>',
            'javascript href'               => '<a href="javascript:alert(1)">x</a>',
            'javascript href, spaced'       => '<a href="  javascript:alert(1)">x</a>',
            'javascript href, tab inside'   => "<a href=\"java\tscript:alert(1)\">x</a>",
            'javascript href, entity tab'   => '<a href="jav&#x09;ascript:alert(1)">x</a>',
            'javascript href, entities'     => '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>',
            'javascript href, upper case'   => '<a href="JaVaScRiPt:alert(1)">x</a>',
            'vbscript href'                 => '<a href="vbscript:msgbox(1)">x</a>',
            'data html href'                => '<a href="data:text/html,<script>alert(1)</script>">x</a>',
            'iframe'                        => '<iframe src="https://evil.example"></iframe>',
            'svg onload'                    => '<svg onload="alert(1)"><circle/></svg>',
            'math/xlink'                    => '<math><a xlink:href="javascript:alert(1)">x</a></math>',
            'style tag'                     => '<style>body{background:url(javascript:alert(1))}</style><p>x</p>',
            'style attribute'               => '<p style="background:url(javascript:alert(1))">x</p>',
            'img onerror (default list)'    => '<img src=x onerror="alert(1)">',
            'object/embed'                  => '<object data="x.swf"></object><embed src="x.swf">',
            'form + button formaction'      => '<form action="https://evil.example"><button formaction="javascript:alert(1)">go</button></form>',
            'meta refresh'                  => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
            'base href'                     => '<base href="https://evil.example/">',
            'comment hiding a script'       => '<!--<script>alert(1)</script>--><p>x</p>',
            'unclosed attribute'            => '<p title="x" onclick="alert(1)>broken</p>',
            'attribute without quotes'      => '<a href=javascript:alert(1)>x</a>',
            'null byte in tag'              => "<scr\0ipt>alert(1)</script>",
            'encoded tag stays text'        => '&lt;script&gt;alert(1)&lt;/script&gt;',
        ];
        $out = [];
        foreach ($v as $k => $html) $out[$k] = [$html];
        return $out;
    }

    /** @dataProvider attacks */
    public function test_default_allow_list_output_is_structurally_safe(string $html): void
    {
        $this->assertSafe(Validator::sanitizeHtml($html), ['p','br','strong','em','u','ul','ol','li','h2','h3','h4','blockquote','a'], ['a' => ['href', 'title']]);
    }

    /** @dataProvider attacks */
    public function test_rich_allow_list_output_is_structurally_safe(string $html): void
    {
        $this->assertSafe(Validator::sanitizeHtml($html, self::RICH_TAGS, self::RICH_ATTRS), self::RICH_TAGS, self::RICH_ATTRS);
    }

    public function test_image_sources_are_limited_to_http_paths_and_raster_data(): void
    {
        $san = fn (string $src) => Validator::sanitizeHtml('<img src="' . $src . '" alt="a">', self::RICH_TAGS, self::RICH_ATTRS);
        $this->assertStringContainsString('src="https://cdn.example/a.png"', $san('https://cdn.example/a.png'));
        $this->assertStringContainsString('src="/uploads/a.png"', $san('/uploads/a.png'));
        $this->assertStringContainsString('src="data:image/png;base64,iVBOR"', $san('data:image/png;base64,iVBOR'));
        foreach (['data:image/svg+xml;base64,PHN2Zy8+', 'javascript:alert(1)', 'data:text/html,<b>', 'file:///etc/passwd'] as $bad) {
            $this->assertStringNotContainsString('src=', $san($bad), $bad);
        }
    }

    public function test_ordinary_content_survives(): void
    {
        $in = '<h2>Title</h2><p>Some <strong>bold</strong>, <em>italic</em> and <a href="https://example.com/x?a=1&amp;b=2" title="T">a link</a>.</p>'
            . '<ul><li>one</li><li>two</li></ul><blockquote>quoted</blockquote><p><a href="/about">about</a> <a href="#top">top</a> <a href="mailto:a@b.c">mail</a></p>';
        $out = Validator::sanitizeHtml($in);
        foreach (['<h2>Title</h2>', '<strong>bold</strong>', '<em>italic</em>', 'href="https://example.com/x?a=1&amp;b=2"', 'title="T"', '<li>two</li>',
                  '<blockquote>quoted</blockquote>', 'href="/about"', 'href="#top"', 'href="mailto:a@b.c"'] as $kept) {
            $this->assertStringContainsString($kept, $out);
        }
    }

    public function test_disallowed_wrappers_keep_their_text(): void
    {
        $out = Validator::sanitizeHtml('<div><span>keep me</span></div><p>and me</p>');
        $this->assertStringContainsString('keep me', $out);
        $this->assertStringContainsString('<p>and me</p>', $out);
        $this->assertStringNotContainsString('<div', $out);
    }

    public function test_empty_input_is_empty(): void
    {
        $this->assertSame('', Validator::sanitizeHtml(''));
        $this->assertSame('', Validator::sanitizeHtml("  \n "));
    }

    /**
     * Parse $html like a browser and fail on anything off the allow-list: a tag, an attribute, or an href/src whose
     * scheme (after entity decoding and stripping the characters browsers ignore) is not http(s)/mailto/relative.
     */
    private function assertSafe(string $html, array $tags, array $attrs): void
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $body = $doc->getElementsByTagName('body')->item(0);
        $this->assertNotNull($body);
        $walk = function (\DOMNode $n) use (&$walk, $tags, $attrs, $html) {
            foreach ($n->childNodes as $c) {
                if ($c->nodeType === XML_COMMENT_NODE) $this->fail("a comment survived: $html");
                if ($c->nodeType !== XML_ELEMENT_NODE) continue;
                $tag = strtolower($c->nodeName);
                $this->assertContains($tag, $tags, "element <$tag> survived in: $html");
                foreach ($c->attributes as $a) {
                    $name = strtolower($a->name);
                    $this->assertContains($name, $attrs[$tag] ?? [], "attribute $name on <$tag> survived in: $html");
                    if ($name === 'href' || $name === 'src') {
                        $probe = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($a->value, ENT_QUOTES | ENT_HTML5)));
                        $ok = !preg_match('/^[a-z][a-z0-9+.\-]*:/', $probe) || preg_match('#^(https?:|mailto:|data:image/(png|jpe?g|gif|webp);base64,)#', $probe);
                        $this->assertTrue((bool) $ok, "$name=\"{$a->value}\" survived in: $html");
                    }
                }
                $walk($c);
            }
        };
        $walk($body);
    }
}
