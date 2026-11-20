<?php

namespace App\Http\Controllers;

use App\Models\ChangelogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "What's new": an in-app changelog. Everyone reads published entries;
 * people with changelog.manage also see drafts and edit them.
 */
class ChangelogController extends Controller
{
    public function index(Request $request): View
    {
        $canManage = $request->user()->hasAccess('changelog.manage');

        $entries = ChangelogEntry::query()
            ->when(! $canManage, fn ($q) => $q->where('is_published', true))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('released_on')->orderByDesc('id')
            ->get();

        return view('changelog.index', [
            'entries' => $entries,
            'byMonth' => $entries->groupBy(fn ($e) => $e->released_on->translatedFormat('F Y')),
            'canManage' => $canManage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $entry = new ChangelogEntry($this->validated($request));
        $entry->created_by = $request->user()->id;
        $entry->save();

        return back()->with('success', __('Changelog entry added.'));
    }

    public function update(Request $request, ChangelogEntry $entry): RedirectResponse
    {
        $entry->update($this->validated($request));

        return back()->with('success', __('Changelog entry saved.'));
    }

    public function destroy(ChangelogEntry $entry): RedirectResponse
    {
        $entry->delete();

        return back()->with('success', __('Changelog entry deleted.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'released_on' => ['required', 'date'],
            'version' => ['nullable', 'string', 'max:30'],
            'category' => ['required', Rule::in(array_keys(ChangelogEntry::CATEGORIES))],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
