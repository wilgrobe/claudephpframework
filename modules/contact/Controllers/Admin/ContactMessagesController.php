<?php
// modules/contact/Controllers/Admin/ContactMessagesController.php
namespace Modules\Contact\Controllers\Admin;

use Core\Http\Request;
use Core\Response;
use Core\Services\SettingsService;
use Core\Session;
use Modules\Contact\Services\ContactService;

/**
 * Admin queue for /admin/contact-messages + settings panel for
 * /admin/settings/contact (recipient list + autoreply + master switch).
 *
 * Status lifecycle: new → read (auto on show) → replied → archived.
 * No "delete" without explicit click; admins can purge spam by hand.
 */
final class ContactMessagesController
{
    public function index(Request $request): Response
    {
        $filters = [
            'status' => (string) ($_GET['status'] ?? ''),
            'q'      => (string) ($_GET['q']      ?? ''),
        ];
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;

        $listing = ContactService::listForAdmin($filters, $page, $perPage);
        $counts  = ContactService::statusCounts();

        return Response::view('contact::admin.index', [
            'rows'      => $listing['rows'],
            'total'     => $listing['total'],
            'counts'    => $counts,
            'filters'   => $filters,
            'page'      => $page,
            'perPage'   => $perPage,
            'pageTitle' => 'Contact messages',
        ]);
    }

    public function show(Request $request): Response
    {
        $id  = (int) $request->param(0);
        $row = ContactService::get($id);
        if ($row === null) {
            Session::flash('error', 'Message not found.');
            return Response::redirect('/admin/contact-messages');
        }
        // Auto-mark as read on view (only flips status when it's 'new').
        ContactService::markRead($id);

        return Response::view('contact::admin.show', [
            'row'       => $row,
            'pageTitle' => 'Contact message #' . $id,
        ]);
    }

    public function markReplied(Request $request): Response
    {
        $id = (int) $request->param(0);
        ContactService::markReplied($id);
        Session::flash('success', 'Marked as replied.');
        return Response::redirect("/admin/contact-messages/{$id}");
    }

    public function archive(Request $request): Response
    {
        $id = (int) $request->param(0);
        ContactService::archive($id);
        Session::flash('success', 'Message archived.');
        return Response::redirect('/admin/contact-messages');
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->param(0);
        ContactService::delete($id);
        Session::flash('success', 'Message deleted.');
        return Response::redirect('/admin/contact-messages');
    }

    // ─────────────────────────────────────────────────────────────
    // Settings panel — /admin/settings/contact
    // ─────────────────────────────────────────────────────────────

    public function settingsShow(Request $request): Response
    {
        $svc = new SettingsService();
        $get = static fn(string $key, mixed $default = '') => $svc->get($key, $default, 'site');

        return Response::view('contact::admin.settings', [
            'pageTitle'                  => 'Contact settings',
            'contact_form_enabled'       => $get('contact_form_enabled', '1'),
            'contact_notify_enabled'     => $get('contact_notify_enabled', '1'),
            'contact_recipient_emails'   => $get('contact_recipient_emails', ''),
            'contact_autoreply_enabled'  => $get('contact_autoreply_enabled', '0'),
            'contact_autoreply_body'     => $get('contact_autoreply_body', ''),
            'contact_min_seconds'        => $get('contact_min_seconds', '3'),
            'legacy_contact_email'       => $get('contact_email', ''),
            'resolved_recipients'        => ContactService::resolveRecipients(),
        ]);
    }

    public function settingsSave(Request $request): Response
    {
        $svc = new SettingsService();

        // Bool toggles — explicit hidden=0 + checked=1 pattern.
        $bool = static fn(string $key) => isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';

        $svc->set('contact_form_enabled',      $bool('contact_form_enabled'),      'site', null, 'boolean');
        $svc->set('contact_notify_enabled',    $bool('contact_notify_enabled'),    'site', null, 'boolean');
        $svc->set('contact_autoreply_enabled', $bool('contact_autoreply_enabled'), 'site', null, 'boolean');

        // Recipient list — preserve user-entered separators; service
        // normalizes + validates at read time.
        $recipients = trim((string) ($_POST['contact_recipient_emails'] ?? ''));
        $svc->set('contact_recipient_emails', $recipients, 'site', null, 'string');

        // Autoreply body — cap at 4000 chars to keep template manageable.
        $body = mb_substr(trim((string) ($_POST['contact_autoreply_body'] ?? '')), 0, 4000);
        $svc->set('contact_autoreply_body', $body, 'site', null, 'string');

        // Min seconds — clamp 0..30 (anything more annoys legit users).
        $min = max(0, min(30, (int) ($_POST['contact_min_seconds'] ?? 3)));
        $svc->set('contact_min_seconds', (string) $min, 'site', null, 'integer');

        Session::flash('success', 'Contact settings saved.');
        return Response::redirect('/admin/settings/contact');
    }
}
