<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Punch;
use App\Services\Attendance\Clock;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The punch log, and adding, editing and deleting punches by hand.
 *
 * Nothing is really removed: a deleted or edited punch is kept with deleted_at set,
 * so the device cannot send it back. An edit keeps the original and adds a manual punch.
 */
class PunchController extends Controller
{
    use AttendanceFilters;

    public function index(Request $request): View
    {
        [$from, $to] = $this->period($request, Clock::today());
        $pin = trim((string) $request->query('pin'));

        $punches = Punch::query()
            ->with('employee:id,pin,name')
            ->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')
            ->when($pin !== '', fn ($q) => $q->where('pin', $pin))
            ->orderByDesc('punch_time')
            ->paginate(100)
            ->withQueryString();

        return view('attendance.punches.index', [
            'punches' => $punches, 'from' => $from, 'to' => $to, 'pin' => $pin,
            'employees' => Employee::ordered()->get(['pin', 'name']),
        ]);
    }

    /** One employee's punches on one date, with where each came from (the edit dialog). */
    public function day(Request $request): JsonResponse
    {
        $date = (string) $request->query('date');
        $pin = trim((string) $request->query('pin'));
        abort_unless($this->isDate($date) && $pin !== '', 422);

        $punches = Punch::query()->with(['device:id,sn,name', 'creator:id,name'])
            ->where('pin', $pin)
            ->where('punch_time', '>=', $date.' 00:00:00')->where('punch_time', '<=', $date.' 23:59:59')
            ->orderBy('punch_time')->get()
            ->map(fn (Punch $p) => [
                'id' => $p->id, 'time' => $p->time(), 'source' => $p->source,
                'source_label' => $this->sourceLabel($p),
                'note' => $p->note, 'created_by' => $p->creator?->name,
            ]);

        return response()->json(['employee' => Employee::labelFor($pin), 'punches' => $punches]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'string', 'max:50'],
            'time' => ['required', 'date_format:Y-m-d H:i'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $time = $data['time'].':00';
        $this->ensureFree($data['pin'], $time);
        Employee::ensure([$data['pin']]);
        $punch = Punch::addManual($data['pin'], $time, $data['note'] ?? null, $request->user()->id);

        Activity::log('punch_added', $punch, $this->describe('Added punch', $punch, $data['note'] ?? null));

        return response()->json(['ok' => true, 'id' => $punch->id]);
    }

    public function update(Request $request, Punch $punch): JsonResponse
    {
        $data = $request->validate([
            'time' => ['required', 'date_format:Y-m-d H:i'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $time = $data['time'].':00';
        $old = $punch->time();
        if ($old === $time) {
            return response()->json(['ok' => true, 'id' => $punch->id]);
        }
        $this->ensureFree($punch->pin, $time, $punch->id);

        $punch->forceFill(['deleted_by' => $request->user()->id])->save();
        $punch->delete();
        $new = Punch::addManual($punch->pin, $time, $data['note'] ?? null, $request->user()->id, $punch->id);

        Activity::log('punch_edited', $new, $this->describe('Edited punch', $new, $data['note'] ?? null, $old), ['old' => $old, 'new' => $time]);

        return response()->json(['ok' => true, 'id' => $new->id]);
    }

    public function destroy(Request $request, Punch $punch): JsonResponse
    {
        $note = trim((string) $request->input('note')) ?: null;
        $punch->forceFill(['deleted_by' => $request->user()->id, 'note' => $note ? mb_substr($note, 0, 200) : $punch->note])->save();
        $punch->delete();

        Activity::log('punch_deleted', $punch, $this->describe('Deleted punch', $punch, $note));

        return response()->json(['ok' => true]);
    }

    private function ensureFree(string $pin, string $time, ?int $exceptId = null): void
    {
        $taken = Punch::where('pin', $pin)->where('punch_time', $time)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['time' => __('There is already a punch at this time.')]);
        }
    }

    private function describe(string $what, Punch $punch, ?string $note, ?string $old = null): string
    {
        $label = Employee::labelFor($punch->pin).': '.($old ? substr($old, 0, 16).' → ' : '').substr($punch->time(), 0, 16);

        return "{$what}. {$label}".($note ? " - {$note}" : '');
    }

    private function sourceLabel(Punch $p): string
    {
        return match (true) {
            $p->source === 'manual' => __('Added by hand'),
            $p->source === 'file' => __('USB / file'),
            $p->source === 'lan' => __('LAN'),
            $p->source === 'branch' || str_starts_with($p->sn, 'BRANCH:') => __('Another branch'),
            default => $p->device?->name ?: __('Device :sn', ['sn' => $p->sn]),
        };
    }
}
