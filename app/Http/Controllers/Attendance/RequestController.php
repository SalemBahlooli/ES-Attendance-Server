<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\EmployeeRequest;
use App\Models\Leave;
use App\Models\Punch;
use App\Notifications\SimpleNotification;
use App\Services\Attendance\LeaveBalance;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Leave and punch correction requests from the employee portal.
 * Approving a leave adds it to the employee's leaves; approving a punch adds a manual punch.
 */
class RequestController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');
        $status = array_key_exists($status, EmployeeRequest::STATUSES) || $status === 'all' ? $status : 'pending';
        $kind = in_array($request->query('kind'), EmployeeRequest::enabledKinds(), true) ? $request->query('kind') : null;

        $requests = EmployeeRequest::enabled()
            ->with(['employee.department:id,name', 'employee.shift', 'employee.department.shift', 'reviewer:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(setting('per_page', 25))
            ->withQueryString();

        $balances = [];
        if (feature('leave_balances')) {
            foreach ($requests as $r) {
                if ($r->kind === 'leave' && $r->leave_type === LeaveBalance::TYPE && $r->isPending() && $r->employee) {
                    $balances[$r->id] = LeaveBalance::for($r->employee, (int) $r->start_date->format('Y'))
                        + ['asked' => LeaveBalance::workDays($r->employee, $r->start_date->toDateString(), $r->end_date->toDateString())];
                }
            }
        }

        return view('attendance.requests', [
            'requests' => $requests,
            'status' => $status,
            'kind' => $kind,
            'kinds' => EmployeeRequest::enabledKinds(),
            'balances' => $balances,
            'pending' => EmployeeRequest::enabled()->where('status', 'pending')->count(),
        ]);
    }

    public function approve(Request $request, EmployeeRequest $employeeRequest): RedirectResponse
    {
        $this->authorizeReview($request, $employeeRequest);
        $note = $this->note($request);
        $employee = $employeeRequest->employee;

        DB::transaction(function () use ($request, $employeeRequest, $employee, $note) {
            if ($employeeRequest->kind === 'leave') {
                $leave = Leave::create([
                    'employee_id' => $employee->id, 'type' => $employeeRequest->leave_type,
                    'start_date' => $employeeRequest->start_date->toDateString(), 'end_date' => $employeeRequest->end_date->toDateString(),
                    'note' => mb_substr(trim(__('Request #:id', ['id' => $employeeRequest->id]).' '.($employeeRequest->reason ?? '')), 0, 255),
                    'source' => 'request', 'created_by' => $request->user()->id,
                ]);
                $resultId = $leave->id;
            } else {
                $time = substr((string) $employeeRequest->punch_time, 0, 19);
                $punch = Punch::where('pin', $employee->pin)->where('punch_time', $time)->first()
                    ?? Punch::addManual($employee->pin, $time, mb_substr(__('Request #:id', ['id' => $employeeRequest->id]).': '.$employeeRequest->reason, 0, 200), $request->user()->id);
                if ($punch->wasRecentlyCreated || $punch->wasChanged()) {
                    Activity::log('punch_added', $punch, __('Added punch').'. '.$punch->activityLabel().' - '.__('Request #:id', ['id' => $employeeRequest->id]));
                }
                $resultId = $punch->id;
            }

            $employeeRequest->forceFill([
                'status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'review_note' => $note, 'result_id' => $resultId,
            ])->save();
        });

        $this->tell($employeeRequest, true);

        return back()->with('success', __('Request approved.'));
    }

    public function reject(Request $request, EmployeeRequest $employeeRequest): RedirectResponse
    {
        $this->authorizeReview($request, $employeeRequest);

        $employeeRequest->forceFill([
            'status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            'review_note' => $this->note($request),
        ])->save();

        $this->tell($employeeRequest, false);

        return back()->with('success', __('Request rejected.'));
    }

    /** Only pending requests of a feature that is on, and never your own. */
    private function authorizeReview(Request $request, EmployeeRequest $employeeRequest): void
    {
        abort_unless(in_array($employeeRequest->kind, EmployeeRequest::enabledKinds(), true), 404);
        abort_if($employeeRequest->employee?->user_id === $request->user()->id, 403, __('You cannot review your own request.'));
        abort_unless($employeeRequest->isPending() && $employeeRequest->employee, 422, __('This request was already reviewed.'));
    }

    private function note(Request $request): ?string
    {
        $note = trim((string) $request->input('review_note'));

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    private function tell(EmployeeRequest $employeeRequest, bool $approved): void
    {
        $user = $employeeRequest->employee?->user ?? $employeeRequest->requester;
        $user?->notify(new SimpleNotification(
            title: $approved ? __('Your request was approved') : __('Your request was rejected'),
            body: $employeeRequest->summary().($employeeRequest->review_note ? ' — '.$employeeRequest->review_note : ''),
            url: feature('portal') ? route('portal.index') : null,
            icon: $approved ? 'fa-circle-check' : 'fa-circle-xmark',
            color: $approved ? 'green' : 'red',
        ));
    }
}
