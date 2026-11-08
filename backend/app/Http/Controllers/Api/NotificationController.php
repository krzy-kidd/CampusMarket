<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $validated = $request->validate(['cursor' => ['nullable', 'string']]);
        $notifications = $user->campusNotifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(30, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json([
            'notifications' => $notifications->getCollection()
                ->map(fn (Notification $notification): array => $this->present($notification)),
            'unread_count' => $user->campusNotifications()->where('is_read', false)->count(),
            'next_cursor' => $notifications->nextCursor()?->encode(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);

        $notification = $user->campusNotifications()
            ->whereKey($notification->id)
            ->firstOrFail();
        if (! $notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'notification' => $this->present($notification->fresh()),
            'unread_count' => $user->campusNotifications()->where('is_read', false)->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $updated = $user->campusNotifications()
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        $unreadCount = $user->campusNotifications()->where('is_read', false)->count();

        return response()->json([
            'updated_count' => $updated,
            'unread_count' => $unreadCount,
        ]);
    }

    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'notification_type' => $notification->notification_type,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'is_read' => $notification->is_read,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }

    private function ensureActiveAccount(User $user): void
    {
        abort_unless($user->account_status === 'active', 403, 'An active account is required to view notifications.');
    }
}
