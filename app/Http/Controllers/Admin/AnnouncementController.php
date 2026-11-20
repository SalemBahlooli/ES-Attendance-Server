<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Announcements show in everyone's notification bell while active.
 * Re-activating (or editing) one brings it back as unread for everyone.
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('announcements.index', [
            'announcements' => Announcement::with('creator')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = new Announcement($this->validated($request));
        $announcement->created_by = $request->user()->id;
        $announcement->save();

        return back()->with('success', __('Announcement published.'));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->fill($this->validated($request))->touch();
        $announcement->save();

        return back()->with('success', __('Announcement saved.'));
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return back()->with('success', __('Announcement deleted.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Announcement::TYPES))],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $tz = display_timezone();
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = ! empty($data[$field]) ? Carbon::parse($data[$field], $tz)->utc() : null;
        }
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
