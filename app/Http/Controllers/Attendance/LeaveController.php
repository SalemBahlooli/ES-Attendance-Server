<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Services\Attendance\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Employee leaves (annual, sick...) and public holidays. Their days are not counted as absent. */
class LeaveController extends Controller
{
    public function index(Request $request): View
    {
        $since = gmdate('Y-m-d', strtotime(Clock::today().' 12:00 UTC') - 120 * 86400);

        return view('attendance.leaves', [
            'leaves' => Leave::with('employee:id,pin,name')
                ->when(! $request->boolean('all'), fn ($q) => $q->where('end_date', '>=', $since))
                ->orderByDesc('start_date')->paginate(setting('per_page', 25))->withQueryString(),
            'holidays' => Holiday::orderByDesc('start_date')->limit(300)->get(),
            'employees' => Employee::where('is_active', true)->ordered()->get(['id', 'pin', 'name']),
            'types' => collect(Leave::TYPES)->mapWithKeys(fn ($t) => [$t => __($t)])->all(),
            'all' => $request->boolean('all'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('leave', [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', Rule::in(Leave::TYPES)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$this->yearAfter($request->input('start_date'))],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['employee_id' => __('Employee'), 'type' => __('Type'), 'start_date' => __('From'), 'end_date' => __('To')]);

        Leave::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', __('Leave saved.'));
    }

    public function destroy(Leave $leave): RedirectResponse
    {
        $leave->delete();

        return back()->with('success', __('Deleted.'));
    }

    private function yearAfter(mixed $date): string
    {
        $t = is_string($date) ? strtotime($date.' 12:00 UTC') : false;

        return $t ? gmdate('Y-m-d', $t + 366 * 86400) : '2099-12-31';
    }
}
