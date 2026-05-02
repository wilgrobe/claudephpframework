<?php
// tests/Unit/Modules/Forms/FormServiceFileUploadTest.php
namespace Tests\Unit\Modules\Forms;

use Core\Database\Database;
use Modules\Forms\Services\FormService;
use Tests\TestCase;

/**
 * File upload validation + the resolveUploadedFile path-traversal guard.
 *
 * The actual move via handleFileUploads() needs is_uploaded_file() to
 * return true, which only works inside a real SAPI request — out of
 * scope for unit tests. The validation pass is what matters most for
 * security (size + extension) and we cover that here against a faked
 * $_FILES superglobal.
 */
final class FormServiceFileUploadTest extends TestCase
{
    private FormService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $mockDb = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->mockDatabase($mockDb);
        $this->svc = new FormService();

        // Reset any leakage from previous tests.
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        parent::tearDown();
    }

    private function fileField(array $overrides = []): array
    {
        return array_merge([
            'id'               => 1,
            'form_id'          => 1,
            'field_key'        => 'attachment',
            'type'             => 'file',
            'label'            => 'Attachment',
            'placeholder'      => null,
            'help_text'        => null,
            'required'         => 0,
            'options'          => null,
            'validation_rules' => null,
            'visibility_rules' => null,
            'sort_order'       => 10,
            'created_at'       => '2026-05-01 00:00:00',
        ], $overrides);
    }

    public function test_file_field_required_blank_errors(): void
    {
        $fields = [$this->fileField(['required' => 1])];
        // No $_FILES['attachment'] at all.
        [$values, $errors] = $this->svc->validateSubmission($fields, []);
        $this->assertArrayHasKey('attachment', $errors);
        $this->assertNull($values['attachment']);
    }

    public function test_file_field_optional_blank_ok(): void
    {
        $fields = [$this->fileField(['required' => 0])];
        [$values, $errors] = $this->svc->validateSubmission($fields, []);
        $this->assertSame([], $errors);
        $this->assertNull($values['attachment']);
    }

    public function test_file_field_rejects_oversized_upload(): void
    {
        $rules = json_encode(['max_size_kb' => 10]);
        $fields = [$this->fileField(['validation_rules' => $rules])];
        $_FILES['attachment'] = [
            'name'     => 'big.pdf',
            'tmp_name' => '/tmp/whatever',
            'size'     => 100 * 1024,   // 100 KB > 10 KB allowed
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'application/pdf',
        ];
        [$values, $errors] = $this->svc->validateSubmission($fields, []);
        $this->assertArrayHasKey('attachment', $errors);
        $this->assertStringContainsString('size limit', $errors['attachment'][0]);
        $this->assertNull($values['attachment']);
    }

    public function test_file_field_rejects_disallowed_extension(): void
    {
        $rules = json_encode(['allowed_extensions' => ['pdf']]);
        $fields = [$this->fileField(['validation_rules' => $rules])];
        $_FILES['attachment'] = [
            'name'     => 'evil.exe',
            'tmp_name' => '/tmp/whatever',
            'size'     => 1024,
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'application/octet-stream',
        ];
        [$values, $errors] = $this->svc->validateSubmission($fields, []);
        $this->assertArrayHasKey('attachment', $errors);
        $this->assertStringContainsString('unsupported', $errors['attachment'][0]);
        $this->assertNull($values['attachment']);
    }

    public function test_file_field_passes_validation_with_pending_marker(): void
    {
        $fields = [$this->fileField(['required' => 1])];
        $_FILES['attachment'] = [
            'name'     => 'invoice.pdf',
            'tmp_name' => '/tmp/whatever',
            'size'     => 5 * 1024,
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'application/pdf',
        ];
        [$values, $errors] = $this->svc->validateSubmission($fields, []);
        $this->assertSame([], $errors);
        // Validation marks the field as "pending move" — the controller
        // swaps in the real path after handleFileUploads moves the file.
        $this->assertSame('__pending_file_upload__', $values['attachment']);
    }

    public function test_resolve_uploaded_file_rejects_path_traversal(): void
    {
        // A stored value of '../../etc/passwd' must not escape storage/.
        $r = new \ReflectionMethod(FormService::class, 'resolveUploadedFile');
        $bad = $r->invoke($this->svc, '../../etc/passwd');
        $this->assertNull($bad);

        $also = $r->invoke($this->svc, '/etc/passwd');
        $this->assertNull($also);
    }

    public function test_resolve_uploaded_file_returns_null_for_nonexistent(): void
    {
        $r = new \ReflectionMethod(FormService::class, 'resolveUploadedFile');
        $missing = $r->invoke($this->svc, 'forms/uploads/9999/9999/' . bin2hex(random_bytes(16)) . '.pdf');
        $this->assertNull($missing);
    }
}
