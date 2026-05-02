<?php
// tests/Unit/Modules/Forms/FormServiceMultiStepTest.php
namespace Tests\Unit\Modules\Forms;

use Modules\Forms\Services\FormService;
use Tests\TestCase;

/**
 * Multi-step forms: step number assignment + the layout-only nature of
 * the new step_break pseudo-type. The full replaceFieldsAndSettings
 * round-trip needs a transactional DB; here we verify the surface that
 * downstream code (the validator + the public renderer) depends on.
 */
final class FormServiceMultiStepTest extends TestCase
{
    public function test_step_break_is_in_field_types_and_layout_only(): void
    {
        $this->assertArrayHasKey('step_break', FormService::FIELD_TYPES);
        $this->assertContains('step_break', FormService::LAYOUT_TYPES,
            'step_break must be layout-only so validateSubmission skips it. '
            . 'Otherwise an empty step_break value would always count as blank-required.');
    }

    public function test_file_type_is_in_field_types(): void
    {
        $this->assertArrayHasKey('file', FormService::FIELD_TYPES);
        $this->assertNotContains('file', FormService::LAYOUT_TYPES,
            'file must NOT be layout-only — file fields capture user data.');
    }

    public function test_other_layout_types_unchanged(): void
    {
        // The original layout set: heading/prose/divider. New addition: step_break.
        $this->assertContains('heading', FormService::LAYOUT_TYPES);
        $this->assertContains('prose',   FormService::LAYOUT_TYPES);
        $this->assertContains('divider', FormService::LAYOUT_TYPES);
    }
}
