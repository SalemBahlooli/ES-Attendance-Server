<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The activity log: filter by person, action, record type, date or text. */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = $this->filtered($request)->with(['causer', 'subject'])->latest('id')
            ->paginate(setting('per_page', 25))->withQueryString();

        return view('activity.index', [
            'logs' => $logs,
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
            'types' => ActivityLog::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type'),
            'causer' => $request->query('user') ? User::withTrashed()->find($request->query('user')) : null,
        ]);
    }

    public function show(ActivityLog $activity): View
    {
        $activity->load(['causer', 'subject']);

        return view('activity.show', ['log' => $activity]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('causer')->latest('id');
        Activity::log('exported', null, __('Activity log (CSV)'), ['rows' => (clone $query)->count(), 'filters' => $request->query()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Date'), __('User'), __('Action'), __('Record'), __('Description'), __('Changes'), __('IP address')]);
            $query->chunk(1000, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    fputcsv($out, [
                        $log->created_at?->toDateTimeString(),
                        $log->causer?->name ?? __('System'),
                        $log->actionLabel(),
                        trim(($log->subjectTypeLabel() ?? '').' '.($log->subject_id ? '#'.$log->subject_id : '')),
                        $log->description,
                        collect($log->changes())->map(fn ($c, $k) => $k.': '.($c['old'] ?? '∅').' → '.($c['new'] ?? '∅'))->implode('; '),
                        $log->ip_address,
                    ]);
                }
            });
            fclose($out);
        }, 'activity-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request)
    {
        $tz = display_timezone();

        return ActivityLog::query()
            ->when($request->query('user'), fn ($q, $id) => $q->where('causer_id', $id))
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
            ->when($request->query('type'), fn ($q, $t) => $q->where('subject_type', $t))
            ->when($request->query('subject'), fn ($q, $id) => $q->where('subject_id', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()))
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('properties', 'like', $like)->orWhere('ip_address', 'like', $like));
            });
    }
}
