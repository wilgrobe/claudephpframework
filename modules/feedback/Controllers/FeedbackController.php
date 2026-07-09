<?php
// modules/feedback/Controllers/FeedbackController.php
namespace Modules\Feedback\Controllers;

use Core\Request;
use Core\Response;
use Core\Session;
use Core\Database\Database;

/**
 * Public feedback surface: the /feedback form, its submit handler, and the
 * public /testimonials showcase. All three respect the builder.feedback.enabled
 * site toggle — when the site hasn't turned the feature on, they 404.
 */
class FeedbackController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Is the Feedback feature turned on for this site? */
    private function enabled(): bool
    {
        return function_exists('setting') && (string) setting('builder.feedback.enabled') === '1';
    }

    private function siteName(): string
    {
        $n = function_exists('setting') ? (string) setting('site_name') : '';
        return $n !== '' ? $n : 'us';
    }

    public function show(Request $request): Response
    {
        if (!$this->enabled()) return Response::notFound();

        $prompts = $this->prompts();
        return Response::view('feedback::public.show', [
            'siteName' => $this->siteName(),
            'prompts'  => $prompts,
            'old'      => Session::flash('feedback_old') ?? [],
            'errors'   => Session::flash('feedback_errors') ?? [],
        ]);
    }

    /** The standardized prompt options — the questions we nudge people to answer. */
    private function prompts(): array
    {
        $site = $this->siteName();
        return [
            'thoughts'   => "What did you think of {$site}?",
            'suggestion' => 'What suggestions can you give us?',
            'experience' => 'Tell us about your experience',
            'other'      => 'Something else',
        ];
    }

    public function submit(Request $request): Response
    {
        if (!$this->enabled()) return Response::notFound();
        $back = '/feedback';

        // Honeypot — bots fill hidden fields; humans leave them empty.
        if (trim((string) $request->post('website') ?? '') !== '') {
            Session::flash('success', 'Thanks for your feedback!');
            return Response::redirect($back);
        }

        $kind        = $request->post('kind') === 'testimonial' ? 'testimonial' : 'feedback';
        $message     = trim((string) ($request->post('message') ?? ''));
        $promptKey   = (string) ($request->post('prompt') ?? '');
        $prompts     = $this->prompts();
        $promptLabel = $prompts[$promptKey] ?? null;
        $rating      = (int) ($request->post('rating') ?? 0);
        $rating      = ($rating >= 1 && $rating <= 5) ? $rating : null;
        // A testimonial can never be anonymous; feedback can.
        $isAnon      = $kind === 'testimonial' ? 0 : (!empty($request->post('is_anonymous')) ? 1 : 0);
        $name        = $isAnon ? null : (trim((string) ($request->post('name') ?? '')) ?: null);
        $email       = $isAnon ? null : (trim((string) ($request->post('email') ?? '')) ?: null);
        $wantsReply  = (!$isAnon && !empty($request->post('request_response'))) ? 1 : 0;
        $consent     = $kind === 'testimonial' ? (!empty($request->post('consent_display')) ? 1 : 0) : 0;

        // ── Validation ─────────────────────────────────────────────
        $errors = [];
        if ($message === '' || mb_strlen($message) < 4)   $errors['message'] = 'Please share a little more.';
        if (mb_strlen($message) > 5000)                    $errors['message'] = 'That’s a bit long — please keep it under 5000 characters.';

        if ($kind === 'testimonial') {
            if ($name === null || mb_strlen($name) < 2)    $errors['name']    = 'A testimonial needs your name.';
            if ($consent !== 1)                            $errors['consent'] = 'Please allow us to display your testimonial.';
        }
        if ($wantsReply && ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Add a valid email so we can reply.';
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That email doesn’t look right.';
        }

        if (!empty($errors)) {
            Session::flash('feedback_old', [
                'kind' => $kind, 'message' => $message, 'prompt' => $promptKey, 'rating' => $rating,
                'is_anonymous' => $isAnon, 'name' => $name, 'email' => $email,
                'request_response' => $wantsReply, 'consent_display' => $consent,
            ]);
            Session::flash('feedback_errors', $errors);
            Session::flash('error', 'Please fix the highlighted fields and try again.');
            return Response::redirect($back);
        }

        // ── Persist ────────────────────────────────────────────────
        try {
            $this->db->query(
                "INSERT INTO feedback_submissions
                    (kind, prompt, message, rating, is_anonymous, name, email, request_response, consent_display, ip, user_agent, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW(), NOW())",
                [
                    $kind, $promptLabel, $message, $rating, $isAnon, $name, $email, $wantsReply, $consent,
                    mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[feedback] insert failed: ' . $e->getMessage());
            Session::flash('error', 'Something went wrong saving your feedback. Please try again.');
            return Response::redirect($back);
        }

        Session::flash('success', $kind === 'testimonial'
            ? 'Thank you! Your testimonial has been submitted for review.'
            : ($wantsReply
                ? 'Thanks for your feedback — we’ll get back to you soon.'
                : 'Thanks for your feedback!'));
        return Response::redirect($back);
    }

    public function testimonials(Request $request): Response
    {
        if (!$this->enabled()) return Response::notFound();
        try {
            $rows = $this->db->fetchAll(
                "SELECT name, message, rating, responded_at FROM feedback_submissions
                  WHERE kind = 'testimonial' AND status = 'published'
                  ORDER BY responded_at DESC, id DESC LIMIT 100"
            );
        } catch (\Throwable) {
            $rows = [];
        }
        return Response::view('feedback::public.testimonials', [
            'siteName'     => $this->siteName(),
            'testimonials' => $rows,
        ]);
    }
}
