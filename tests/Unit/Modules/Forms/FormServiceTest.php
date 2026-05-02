<?php
// tests/Unit/Modules/Forms/FormServiceTest.php
namespace Tests\Unit\Modules\Forms;

use Core\Database\Database;
use Modules\Forms\Services\FormService;
use Tests\TestCase;

/**
 * Covers the validation + CSV flattening logic in FormService. Database is
 * stubbed via newInstanceWithoutConstructor so these tests don't need MySQL.
 */
final class FormServiceTest extends TestCase
{
    private FormService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        // FormService's constructor hits Database::getInstance(); mock it so
        // the ctor doesn't try to open a real PDO connection.
        $mockDb = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->mockDatabase($mockDb);
        $this->svc = new FormService();
    }

    /** Build a minimal field-row shape matching the DB schema. */
    private function field(array $overrides = []): array
    {
        return array_merge([
            'id'               => 1,
            'form_id'          => 1,
            'field_key'        => 'name',
            'type'             => 'text',
            'label'            => 'Your name',
            'placeholder'      => null,
            'help_text'        => null,
            'required'         => 0,
            'options'          => null,
            'validation_rules' => null,
            'sort_order'       => 10,
            'created_at'       => '2026-04-22 00:00:00',
        ], $overrides);
    }

    // ── Required + blank handling ─────────────────────────────────────────

    public function test_required_blank_field_errors(): void
    {
        [$values, $errors] = $this->svc->validateSubmission(
            [$this->field(['required' => 1])],
            ['name' => '']
        );

        $this->assertArrayHasKey('name', $errors);
        $this->assertStringContainsString('required', $errors['name'][0]);
        $this->assertArrayHasKey('name', $values);
        $this->assertNull($values['name']);
    }

    public function test_optional_blank_field_ok(): void
    {
        [$values, $errors] = $this->svc->validateSubmission(
            [$this->field(['required' => 0])],
            ['name' => '']
        );

        $this->assertSame([], $errors);
        $this->assertNull($values['name']);
    }

    // ── Text + length rules ───────────────────────────────────────────────

    public function test_text_respects_min_max_rules(): void
    {
        $f = $this->field([
            'validation_rules' => json_encode(['min' => 3, 'max' => 10]),
        ]);

        [$_, $tooShort] = $this->svc->validateSubmission([$f], ['name' => 'ab']);
        $this->assertArrayHasKey('name', $tooShort);

        [$_, $tooLong]  = $this->svc->validateSubmission([$f], ['name' => str_repeat('x', 11)]);
        $this->assertArrayHasKey('name', $tooLong);

        [$vOk, $noErr] = $this->svc->validateSubmission([$f], ['name' => 'Alice']);
        $this->assertSame([], $noErr);
        $this->assertSame('Alice', $vOk['name']);
    }

    public function test_text_pattern_rule(): void
    {
        $f = $this->field([
            'validation_rules' => json_encode(['pattern' => '^[A-Z0-9]+$']),
        ]);

        [$_, $bad] = $this->svc->validateSubmission([$f], ['name' => 'mixedCase']);
        $this->assertArrayHasKey('name', $bad);

        [$_, $ok]  = $this->svc->validateSubmission([$f], ['name' => 'ABC123']);
        $this->assertSame([], $ok);
    }

    // ── Email ─────────────────────────────────────────────────────────────

    public function test_email_type_validates_format(): void
    {
        $f = $this->field(['type' => 'email', 'field_key' => 'contact', 'label' => 'Email']);

        [$_, $bad]  = $this->svc->validateSubmission([$f], ['contact' => 'not-email']);
        $this->assertArrayHasKey('contact', $bad);

        [$vOk, $ok] = $this->svc->validateSubmission([$f], ['contact' => 'a@b.com']);
        $this->assertSame([], $ok);
        $this->assertSame('a@b.com', $vOk['contact']);
    }

    // ── Number ────────────────────────────────────────────────────────────

    public function test_number_range_and_type(): void
    {
        $f = $this->field([
            'type'             => 'number',
            'field_key'        => 'age',
            'label'            => 'Age',
            'validation_rules' => json_encode(['min' => 18, 'max' => 120]),
        ]);

        [$_, $notNum] = $this->svc->validateSubmission([$f], ['age' => 'abc']);
        $this->assertArrayHasKey('age', $notNum);

        [$_, $tooYoung] = $this->svc->validateSubmission([$f], ['age' => '12']);
        $this->assertArrayHasKey('age', $tooYoung);

        [$vOk, $ok]  = $this->svc->validateSubmission([$f], ['age' => '30']);
        $this->assertSame([], $ok);
        $this->assertSame(30, $vOk['age']);
    }

    // ── Select / radio against options ────────────────────────────────────

    public function test_select_rejects_value_outside_options(): void
    {
        $f = $this->field([
            'type'      => 'select',
            'field_key' => 'color',
            'label'     => 'Favourite color',
            'options'   => json_encode(['red', 'green', 'blue']),
        ]);

        [$_, $bad] = $this->svc->validateSubmission([$f], ['color' => 'purple']);
        $this->assertArrayHasKey('color', $bad);

        [$vOk, $ok] = $this->svc->validateSubmission([$f], ['color' => 'green']);
        $this->assertSame([], $ok);
        $this->assertSame('green', $vOk['color']);
    }

    // ── Checkbox (single bool) ────────────────────────────────────────────

    public function test_checkbox_normalizes_to_bool(): void
    {
        $f = $this->field(['type' => 'checkbox', 'field_key' => 'agree', 'label' => 'I agree']);

        [$v1, $_] = $this->svc->validateSubmission([$f], ['agree' => '1']);
        $this->assertTrue($v1['agree']);

        [$v0, $_] = $this->svc->validateSubmission([$f], ['agree' => '0']);
        $this->assertFalse($v0['agree']);
    }

    // ── Date ──────────────────────────────────────────────────────────────

    public function test_date_must_be_valid_iso(): void
    {
        $f = $this->field(['type' => 'date', 'field_key' => 'dob', 'label' => 'Birthday']);

        [$_, $bad] = $this->svc->validateSubmission([$f], ['dob' => 'not-a-date']);
        $this->assertArrayHasKey('dob', $bad);

        [$_, $badFmt] = $this->svc->validateSubmission([$f], ['dob' => '01/02/2026']);
        $this->assertArrayHasKey('dob', $badFmt);

        [$vOk, $ok] = $this->svc->validateSubmission([$f], ['dob' => '2026-04-22']);
        $this->assertSame([], $ok);
        $this->assertSame('2026-04-22', $vOk['dob']);
    }

    // ── Roundtrip through multiple fields ─────────────────────────────────

    public function test_multiple_fields_aggregated(): void
    {
        $fields = [
            $this->field(['field_key' => 'name',  'required' => 1]),
            $this->field(['field_key' => 'email', 'type' => 'email']),
            $this->field(['field_key' => 'age',   'type' => 'number']),
        ];

        [$values, $errors] = $this->svc->validateSubmission($fields, [
            'name'  => '',              // required + blank → error
            'email' => 'bad',           // invalid format → error
            'age'   => '25',            // ok
        ]);

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayNotHasKey('age', $errors);
        $this->assertSame(25, $values['age']);
    }
}
