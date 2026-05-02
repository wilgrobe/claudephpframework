<?php
// modules/profile/Controllers/NotificationPreferencesController.php
namespace Modules\Profile\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Services\NotificationService;

/**
 * /profile/notifications — per-channel notification preferences UI.
 *
 * The grid renders one row per type from NotificationService::TYPES
 * with one toggle per supported channel. Adding a new entry to TYPES
 * makes it appear here automatically. Defaults are "on" until the user
 * explicitly toggles a channel off.
 *
 * Save flow uses INSERT ... ON DUPLICATE KEY UPDATE per cell so a slow
 * page load + concurrent toggle don't lose later writes - each cell
 * write is its own DB statement, idempotent.
 */
class NotificationPreferencesController
{
    private Auth                $auth;
    private NotificationService $notif;

    public function __construct()
    {
        $this->auth  = Auth::getInstance();
        $this->notif = new NotificationService();
    }

    public function show(Request $request): Response
    {
        return Response::view('profile::notification_preferences', [
            'types' => NotificationService::TYPES,
            'prefs' => $this->notif->preferencesFor((int) $this->auth->id()),
            'user'  => $this->auth->user(),
        ]);
    }

    public function save(Request $request): Response
    {
        $posted = (array) $request->post('prefs', []);
        $this->notif->setPreferences((int) $this->auth->id(), $posted);
        $this->auth->auditLog('user.notification_prefs_save', 'users', (int) $this->auth->id());
        return Response::redirect('/profile/notifications')
            ->withFlash('success', 'Notification preferences saved.');
    }
}
