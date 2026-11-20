<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Punch;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The devices that called in, their punch counts and queued commands. */
class DeviceController extends Controller
{
    public function index(): View
    {
        $devices = Device::query()
            ->addSelect(['total' => Punch::selectRaw('COUNT(*)')->whereColumn('punches.sn', 'devices.sn')])
            ->addSelect(['pending' => DeviceCommand::selectRaw('COUNT(*)')->whereColumn('device_commands.sn', 'devices.sn')->whereIn('status', ['pending', 'sent'])])
            ->orderByDesc('last_seen_at')
            ->get();

        return view('attendance.devices', [
            'devices' => $devices,
            'commands' => DeviceCommand::COMMANDS,
            'recent' => DeviceCommand::latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $device->update(['name' => trim((string) ($data['name'] ?? '')) ?: null]);

        return back()->with('success', __('Changes saved.'));
    }

    /** Queued; the device picks it up on its next call (usually within a minute). */
    public function command(Request $request, Device $device, string $action): RedirectResponse
    {
        $def = DeviceCommand::COMMANDS[$action];
        DeviceCommand::create(['sn' => $device->sn, 'command' => $def['command'], 'created_by' => $request->user()->id]);
        Activity::log('device_command', $device, 'Device command: '.$def['label']);

        return back()->with('success', __('Command sent. The device picks it up on its next call, usually within a minute.'));
    }
}
