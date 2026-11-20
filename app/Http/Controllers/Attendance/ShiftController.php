<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ShiftRequest;
use App\Models\Department;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Shifts and departments on one page. */
class ShiftController extends Controller
{
    public function index(): View
    {
        return view('attendance.shifts', [
            'shifts' => Shift::withCount(['employees', 'departments'])->orderByDesc('is_default')->orderBy('id')->get(),
            'departments' => Department::with('shift:id,name')->withCount('employees')->orderBy('name')->get(),
        ]);
    }

    public function store(ShiftRequest $request): RedirectResponse
    {
        Shift::create($request->shift() + ['is_default' => Shift::doesntExist()]);

        return back()->with('success', __('Shift saved.'));
    }

    public function update(ShiftRequest $request, Shift $shift): RedirectResponse
    {
        $shift->update($request->shift());

        return back()->with('success', __('Changes saved.'));
    }

    public function makeDefault(Shift $shift): RedirectResponse
    {
        DB::transaction(function () use ($shift) {
            Shift::whereKeyNot($shift->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
            $shift->update(['is_default' => true]);
        });

        return back()->with('success', __(':name is now the default shift.', ['name' => $shift->name]));
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        if ($shift->is_default) {
            return back()->with('error', __('The default shift cannot be deleted.'));
        }
        $shift->delete();

        return back()->with('success', __('Deleted.'));
    }
}
