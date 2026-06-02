<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Notification\NotificationManager;

/**
 * Centre de notifications (cloche du header).
 *
 * Strictement scopé à l'utilisateur connecté : on ne renvoie jamais les
 * notifications d'un autre utilisateur, même pour un admin.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class NotificationController extends Controller
{
    private NotificationManager $notifications;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->notifications = new NotificationManager();
    }

    /**
     * Liste des notifications visibles + compteur de non-lues (pour la pastille).
     */
    public function index(Request $request): void
    {
        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            $this->error('Not authenticated', 401);
        }

        $this->success([
            'notifications' => $this->notifications->listForUser($userId),
            'unread_count'  => $this->notifications->unreadCount($userId),
        ]);
    }

    /**
     * Marque toutes les notifications de l'utilisateur comme lues
     * (déclenché à l'ouverture de la cloche).
     */
    public function markRead(Request $request): void
    {
        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            $this->error('Not authenticated', 401);
        }

        $this->notifications->markAllRead($userId);
        $this->success(['unread_count' => 0]);
    }
}
