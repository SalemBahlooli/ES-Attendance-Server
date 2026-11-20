<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Public holidays apply to every employee. */
class HolidayController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('holiday', [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ], [], ['name' => __('Name'), 'start_date' => __('From'), 'end_date' => __('To')]);

        Holiday::create($data);

        return back()->with('success', __('Holiday saved.'));
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('success', __('Deleted.'));
    }
}
