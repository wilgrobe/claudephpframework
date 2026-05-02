<?php
// modules/notifications/Controllers/NotificationsController.php
namespace Modules\Notifications\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Services\NotificationService;

/**
 * Ported from App\Controllers\NotificationsController. Behavior unchanged.
 * Only namespace moved and Response::view() now uses the 'notifications::' prefix.
 */
class NotificationsController
{
    private NotificationService $notif;
    private Auth                $auth;

    public function __construct()
    {
        $this->notif = new NotificationService();
        $this->auth  = Auth::getInstance();
    }

    public function index(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        // Annotate so the view can toggle the "×" dismissal button based
        // on the same canDelete() rule the delete endpoint enforces.
        $notifications = $this->notif->annotate(
            $this->notif->getAll($this->auth->id(), 50)
        );

        return Response::view('notifications::index', [
            'notifications' => $notifications,
            'user'          => $this->auth->user(),
        ]);
    }

    public function markRead(Request $request): Response
    {
        if ($this->auth->guest()) return Response::json(['error' => 'Unauthenticated'], 401);

        $id = $request->param(0);
        $this->notif->markRead($id, $this->auth->id());

        return Response::json(['success' => true]);
    }

    /**
     * POST /notifications/{id}/delete
     * Dismiss a notification permanently. Gated by NotificationService::
     * canDelete(): must be read, and if it represents an action (transfer
     * request, invitation, owner removal), the action must no longer be
     * pending. Returns 409 with a message when blocked so the UI can
     * surface an inline explanation rather than a silent failure.
     */
    public function delete(Request $request): Response
    {
        if ($this->auth->guest()) return Response::json(['error' => 'Unauthenticated'], 401);

        $id     = $request->param(0);
        $userId = (int) $this->auth->id();

        $n = \Core\Database\Database::getInstance()->fetchOne(
            "SELECT * FROM notifications WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        if (!$n) return Response::json(['error' => 'Notification not found.'], 404);

        if (empty($n['read_at'])) {
            return Response::json(['error' => 'Mark the notification as read before deleting it.'], 409);
        }
        if (!$this->notif->actionResolved($n)) {
            return Response::json(
                ['error' => 'This notification has a pending action. Accept, reject, or cancel it before deleting.'],
                409
            );
        }

        $this->notif->delete($id, $userId);
        return Response::json(['success' => true]);
    }

    public function markAllRead(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $db = \Core\Database\Database::getInstance();
        $db->query(
            "UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL",
            [$this->auth->id()]
        );

        return Response::redirect('/notifications')->withFlash('success', 'All notifications marked as read.');
    }

    /** JSON endpoint for the bell counter in the nav */
    public function unreadCount(Request $request): Response
    {
        if ($this->auth->guest()) return Response::json(['count' => 0]);

        $count = count($this->notif->getUnread($this->auth->id(), 99));
        return Response::json(['count' => $count]);
    }
}
