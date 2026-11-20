<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One small request every 60 seconds from every open tab:
 *   - keeps presence fresh (TrackPresence middleware stamps the user)
 *   - returns the numbers the layout shows live: unread bell count, people online,
 *     and any module counters (sidebar badges, see config/menu.php → badge)
 *
 * Add a counter for your module in counters() below.
 */
class HeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'unread' => $this->unread($user),
            'badges' => $this->counters($user),
        ];

        if ($user->hasAccess('presence.view')) {
            $data['online'] = User::where('last_activity_at', '>=', now()->subMinutes(5))->count();
        }

        return response()->json($data);
    }

    /** Unread notifications + announcements published since the bell was last opened. */
    private function unread(User $user): int
    {
        $announcements = Announcement::visible()
            ->when($user->announcements_seen_at, fn ($q, $seen) => $q->where('updated_at', '>', $seen))
            ->count();

        return $user->unreadNotifications()->count() + $announcements;
    }

    /**
     * Live counters for sidebar badges, keyed like the menu item's `badge`.
     *
     * Example for a module:
     *   if ($user->hasAccess('tickets.view')) {
     *       $out['tickets'] = Ticket::where('status', 'open')->count();
     *   }
     *
     * @return array<string, int>
     */
    private function counters(User $user): array
    {
        $out = [];

        return $out;
    }
}
