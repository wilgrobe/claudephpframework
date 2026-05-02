<?php
// tests/Unit/Modules/Forms/FormServiceVisibilityTest.php
namespace Tests\Unit\Modules\Forms;

use Core\Database\Database;
use Modules\Forms\Services\FormService;
use Tests\TestCase;

/**
 * Conditional field visibility — both the standalone evaluateVisibility
 * helper (string + array values, all 5 operators, all/any modes) and the
 * integration with validateSubmission (hidden required fields don't error,
 * forged POST values dropped to null).
 */
final class FormServiceVisibilityTest extends TestCase
{
    private FormService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $mockDb = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->mockDatabase($mockDb);
        $this->svc = new FormService();
    }

    private function field(array $overrides = []): array
    {
        return array_merge([
            'id'               => 1,
            'form_id'          => 1,
            'field_key'        => 'extra_info',
            'type'             => 'text',
            'label'            => 'Extra info',
            'placeholder'      => null,
            'help_text'        => null,
            'required'         => 1,
            'options'          => null,
            'validation_rules' => null,
            'visibility_rules' => null,
            'sort_order'       => 10,
            'created_at'       => '2026-05-01 00:00:00',
        ], $overrides);
    }

    // ── evaluateVisibility unit table ────────────────────────────────────

    public function test_no_rules_means_show(): void
    {
        $this->assertTrue(FormService::evaluateVisibility([], ['x' => 'y']));
        $this->assertTrue(FormService::evaluateVisibility(['show_when' => []], []));
    }

    public function test_equals_operator(): void
    {
        $rules = ['show_when' => [['field_key' => 'subject', 'op' => 'equals', 'value' => 'Other']]];
        $this->assertTrue(FormService::evaluateVisibility($rules,  ['subject' => 'Other']));
        $this->assertFalse(FormService::evaluateVisibility($rules, ['subject' => 'Billing']));
        $this->assertFalse(FormService::evaluateVisibility($rules, []));
    }

    public function test_not_equals_operator(): void
    {
        $rules = ['show_when' => [['field_key' => 'country', 'op' => 'not_equals', 'value' => 'US']]];
        $this->assertTrue(FormService::evaluateVisibility($rules,  ['country' => 'CA']));
        $this->assertFalse(FormService::evaluateVisibility($rules, ['country' => 'US']));
    }

    public function test_contains_operator_case_insensitive(): void
    {
        $rules = ['show_when' => [['field_key' => 'note', 'op' => 'contains', 'value' => 'urgent']]];
        $this->assertTrue(FormService::evaluateVisibility($rules,  ['note' => 'This is URGENT — please act fast']));
        $this->assertFalse(FormService::evaluateVisibility($rules, ['note' => 'just some thoughts']));
        // Empty needle never matches (defensive — empty 'value' shouldn't make every field visible).
        $emptyNeedle = ['show_when' => [['field_key' => 'note', 'op' => 'contains', 'value' => '']]];
        $this->assertFalse(FormService::evaluateVisibility($emptyNeedle, ['note' => 'anything']));
    }

    public function test_not_empty_and_is_empty_operators(): void
    {
        $notEmpty = ['show_when' => [['field_key' => 'phone', 'op' => 'not_empty']]];
        $isEmpty  = ['show_when' => [['field_key' => 'phone', 'op' => 'is_empty']]];
        $this->assertTrue(FormService::evaluateVisibility($notEmpty,  ['phone' => '555']));
        $this->assertFalse(FormService::evaluateVisibility($notEmpty, ['phone' => '']));
        $this->assertTrue(FormService::evaluateVisibility($isEmpty,   ['phone' => '']));
        $this->assertFalse(FormService::evaluateVisibility($isEmpty,  ['phone' => '555']));
    }

    public function test_array_value_handled_by_equals_and_contains(): void
    {
        $eq = ['show_when' => [['field_key' => 'tags', 'op' => 'equals', 'value' => 'beta']]];
        $this->assertTrue(FormService::evaluateVisibility($eq,  ['tags' => ['alpha', 'beta']]));
        $this->assertFalse(FormService::evaluateVisibility($eq, ['tags' => ['alpha']]));

        $con = ['show_when' => [['field_key' => 'tags', 'op' => 'contains', 'value' => 'be']]];
        $this->assertTrue(FormService::evaluateVisibility($con, ['tags' => ['alpha', 'beta']]));
    }

    public function test_match_all_vs_any(): void
    {
        $rules = [
            'match'     => 'all',
            'show_when' => [
                ['field_key' => 'a', 'op' => 'equals', 'value' => '1'],
                ['field_key' => 'b', 'op' => 'equals', 'value' => '2'],
            ],
        ];
        $this->assertTrue(FormService::evaluateVisibility($rules,  ['a' => '1', 'b' => '2']));
        $this->assertFalse(FormService::evaluateVisibility($rules, ['a' => '1', 'b' => '9']));

        $any = ['match' => 'any'] + $rules;
        $this->assertTrue(FormService::evaluateVisibility($any, ['a' => '1', 'b' => '9']));
        $this->assertFalse(FormService::evaluateVisibility($any, ['a' => '9', 'b' => '9']));
    }

    // ── validateSubmission integration ───────────────────────────────────

    public function test_hidden_required_field_does_not_error(): void
    {
        $vis = json_encode([
            'show_when' => [['field_key' => 'subject', 'op' => 'equals', 'value' => 'Other']],
        ]);
        $fields = [
            $this->field(['field_key' => 'subject', 'required' => 0]),
            $this->field(['field_key' => 'extra_info', 'required' => 1, 'visibility_rules' => $vis]),
        ];

        // subject != Other → extra_info hidden → required-check skipped.
        [$values, $errors] = $this->svc->validateSubmission($fields, ['subject' => 'Billing']);
        $this->assertSame([], $errors);
        $this->assertNull($values['extra_info']);
    }

    public function test_hidden_field_drops_forged_post_value(): void
    {
        $vis = json_encode([
            'show_when' => [['field_key' => 'subject', 'op' => 'equals', 'value' => 'Other']],
        ]);
        $fields = [
            $this->field(['field_key' => 'subject', 'required' => 0]),
            $this->field(['field_key' => 'extra_info', 'required' => 0, 'visibility_rules' => $vis]),
        ];

        // Attacker passes a value for extra_info even though the UI hides it.
        // Service should drop that to null.
        [$values, $errors] = $this->svc->validateSubmission($fields, [
            'subject'    => 'Billing',
            'extra_info' => 'sneaky',
        ]);
        $this->assertSame([], $errors);
        $this->assertNull($values['extra_info']);
    }

    public function test_visible_field_validates_normally(): void
    {
        $vis = json_encode([
            'show_when' => [['field_key' => 'subject', 'op' => 'equals', 'value' => 'Other']],
        ]);
        $fields = [
            $this->field(['field_key' => 'subject', 'required' => 0]),
            $this->field(['field_key' => 'extra_info', 'required' => 1, 'visibility_rules' => $vis]),
        ];

        // subject == Other → extra_info IS shown → required-check runs.
        [$_, $errors] = $this->svc->validateSubmission($fields, ['subject' => 'Other']);
        $this->assertArrayHasKey('extra_info', $errors);
    }
}
