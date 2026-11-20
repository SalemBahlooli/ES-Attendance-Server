<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The notification bell and the "All notifications" page.
 *
 * Two sources: the person's own notifications (Laravel database notifications —
 * send any with $user->notify(new SimpleNotification(...))) and the active
 * announcements everyone sees.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()->paginate(setting('per_page', 25));

        return view('notifications.index', ['notifications' => $notifications]);
    }

    /** The bell dropdown: fetched when it opens. Marks announcements as seen. */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $seenAt = $user->announcements_seen_at;

        $announcements = Announcement::visible()->with('creator')->latest('updated_at')->limit(5)->get()
            ->map(fn (Announcement $a) => [
                'id' => 'a'.$a->id,
                'kind' => 'announcement',
                'title' => $a->title,
                'body' => $a->body,
                'icon' => $a->typeIcon(),
                'color' => $a->typeColor(),
                'label' => $a->typeLabel(),
                'ago' => $a->updated_at->diffForHumans(),
                'unread' => $seenAt === null || $a->updated_at->gt($seenAt),
                'url' => null,
            ]);

        $notifications = $user->notifications()->limit(10)->get()
            ->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => 'notification',
                'title' => $n->data['title'] ?? __('Notification'),
                'body' => $n->data['body'] ?? null,
                'icon' => $n->data['icon'] ?? 'fa-bell',
                'color' => $n->data['color'] ?? 'blue',
                'label' => null,
                'ago' => $n->created_at->diffForHumans(),
                'unread' => $n->read_at === null,
                'url' => route('notifications.open', $n->id),
            ]);

        $user->forceFill(['announcements_seen_at' => now()])->saveQuietly();

        return response()->json([
            'announcements' => $announcements->values(),
            'notifications' => $notifications->values(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Open a notification: mark it read and follow its link. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        return $url && str_starts_with($url, url('/'))
            ? redirect()->to($url)
            : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', __('All notifications are marked as read.'));
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->whereKey($id)->delete();

        return back();
    }
}
