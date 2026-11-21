<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\SpecialPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Special working hours between two dates (Ramadan), on the shifts page. */
class SpecialPeriodController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ], [], [
            'name' => __('Name'), 'start_date' => __('From'), 'end_date' => __('To'),
            'start_time' => __('Start time'), 'end_time' => __('End time'), 'shift_id' => __('Shift'),
        ]);
        if ((strtotime($data['end_date']) - strtotime($data['start_date'])) / 86400 > 92) {
            throw ValidationException::withMessages(['end_date' => __('Pick a period of three months or less.')]);
        }

        SpecialPeriod::create($data);

        return back()->with('success', __('Special working hours saved.'));
    }

    public function destroy(SpecialPeriod $specialPeriod): RedirectResponse
    {
        $specialPeriod->delete();

        return back()->with('success', __('Deleted.'));
    }
}
