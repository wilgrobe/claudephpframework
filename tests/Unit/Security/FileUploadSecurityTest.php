<?php
// tests/Unit/Security/FileUploadSecurityTest.php
namespace Tests\Unit\Security;

use Core\Services\FileUploadService;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

/**
 * FileUploadService::uploadImage decides what a user may put on the server (avatars, group images). It had no test
 * (#466 profile, 4 Nov 2026). What must hold: the TYPE comes from the bytes, never the name; only JPEG/PNG/GIF/WebP;
 * the stored name is random with the extension of the detected type; and an image carrying a payload is re-encoded
 * so the payload does not survive.
 */
final class FileUploadSecurityTest extends TestCase
{
    private string $root;
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/upl466-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($this->root);
        parent::tearDown();
    }

    /** A local-disk service rooted in a temp folder — the real class, without reading config/storage.php. */
    private function svc(): FileUploadService
    {
        $s = (new \ReflectionClass(FileUploadService::class))->newInstanceWithoutConstructor();
        foreach (['driver' => 'local', 'cfg' => ['root_path' => $this->root], 'fs' => new Filesystem(new LocalFilesystemAdapter($this->root))] as $p => $v) {
            (new \ReflectionProperty(FileUploadService::class, $p))->setValue($s, $v);
        }
        return $s;
    }

    /** A $_FILES-shaped entry for these bytes, with the client's claimed name. */
    private function file(string $bytes, string $clientName): array
    {
        $p = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($p, $bytes);
        $this->tmp[] = $p;
        return ['name' => $clientName, 'type' => 'image/jpeg', 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    private function png(): string
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('ext-gd not loaded');
        $im = imagecreatetruecolor(4, 4);
        ob_start(); imagepng($im); return (string) ob_get_clean();
    }

    private function jpeg(): string
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('ext-gd not loaded');
        $im = imagecreatetruecolor(8, 8);
        ob_start(); imagejpeg($im); return (string) ob_get_clean();
    }

    /** @return array<string, array{0:string, 1:string}> bytes that are not an image, whatever their name says */
    public static function notImages(): array
    {
        return [
            'PHP named .jpg'          => ['<?php echo "payload-466"; ?>', 'avatar.jpg'],
            'PHP named .php.png'      => ["<?php echo 1;\n", 'shell.php.png'],
            'SVG with a script'       => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg.png'],
            'HTML page'               => ['<!doctype html><html><body><script>alert(1)</script></body></html>', 'photo.gif'],
            'empty file'              => ['', 'empty.png'],
        ];
    }

    /** @dataProvider notImages */
    public function test_anything_that_is_not_an_allowed_image_is_refused_whatever_its_name(string $bytes, string $name): void
    {
        $f = $this->file($bytes, $name);
        // A flag, not try { …; $this->fail() } catch (\RuntimeException): PHPUnit's own failure IS a
        // RuntimeException, so that shape swallowed it and an accepted upload still passed (#466).
        $threw = false;
        try {
            $this->svc()->uploadImage($f, 'avatars');
        } catch (\RuntimeException $e) {
            $threw = $e->getMessage() !== '';
        }
        $this->assertTrue($threw, "accepted $name");
        $this->assertSame([], glob($this->root . '/avatars/*') ?: [], 'nothing may be written for a refused upload');
    }

    public function test_a_real_image_is_stored_under_a_random_name_with_the_detected_extension(): void
    {
        $path = $this->svc()->uploadImage($this->file($this->png(), 'my photo.php.jpg'), 'avatars');
        $this->assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.png$#', $path, 'the client name (and its .php) must play no part');
        $this->assertFileExists($this->root . '/' . $path);
    }

    public function test_a_payload_inside_a_jpeg_does_not_survive_re_encoding(): void
    {
        // PHP smuggled into a JPEG comment segment (COM, 0xFFFE) right after SOI — still a valid JPEG to finfo and GD.
        $jpeg = $this->jpeg();
        $payload = '<?php echo "payload-466"; ?>';
        $poly = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
        $this->assertStringContainsString($payload, $poly);
        $path = $this->svc()->uploadImage($this->file($poly, 'poly.jpg'), 'avatars');
        $stored = (string) file_get_contents($this->root . '/' . $path);
        $this->assertStringStartsWith("\xFF\xD8", $stored, 'still a JPEG');
        $this->assertStringNotContainsString('<?php', $stored, 'the payload survived re-encoding');
    }

    public function test_a_payload_appended_to_a_png_does_not_survive_re_encoding(): void
    {
        $poly = $this->png() . '<?php echo "payload-466"; ?>';
        $path = $this->svc()->uploadImage($this->file($poly, 'poly.png'), 'avatars');
        $this->assertStringNotContainsString('<?php', (string) file_get_contents($this->root . '/' . $path));
    }

    public function test_size_and_upload_errors_are_refused(): void
    {
        $png = $this->png();
        foreach ([fn () => $this->svc()->uploadImage($this->file($png, 'a.png'), 'avatars', 10),
                  fn () => $this->svc()->uploadImage(['error' => UPLOAD_ERR_PARTIAL] + $this->file($png, 'a.png'), 'avatars'),
                  fn () => $this->svc()->uploadImage(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0], 'avatars')] as $i => $call) {
            $threw = false;
            try { $call(); } catch (\RuntimeException) { $threw = true; }
            $this->assertTrue($threw, "case $i accepted");
        }
    }
}
