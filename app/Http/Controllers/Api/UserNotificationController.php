<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->appNotifications()->latest();

        // ?is_read=0 is how the shared sidebar badge (notification-badge.js)
        // asks "how many unread do I have" - it only ever needs the
        // paginator's own `total`, not the actual rows, so a plain filtered
        // count via the existing paginate() call is enough; no separate
        // "unread count" endpoint needed.
        if ($request->has('is_read')) {
            $query->where('is_read', $request->boolean('is_read'));
        }

        return response()->json($query->paginate(20));
    }

    public function markRead(Request $request, UserNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['is_read' => true]);

        return response()->json($notification->fresh());
    }

    public function markAllRead(Request $request)
    {
        $request->user()->appNotifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read']);
    }
}
