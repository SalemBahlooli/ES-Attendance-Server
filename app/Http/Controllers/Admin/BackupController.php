<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Backups;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Backups: make one now, download, delete, and set the schedule and
 * retention. The schedule itself runs from routes/console.php.
 */
class BackupController extends Controller
{
    public function index(): View
    {
        $files = Backups::files();
        $root = Storage::disk(Backups::DISK)->path('');

        return view('settings.backups', [
            'files' => $files,
            'total' => array_sum(array_column($files, 'bytes')),
            'diskFree' => @disk_free_space(is_dir($root) ? $root : storage_path()) ?: 0,
            'diskTotal' => @disk_total_space(is_dir($root) ? $root : storage_path()) ?: 0,
            'cron' => Backups::cron(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contents = $request->input('contents') === 'db' ? 'db' : 'full';
        [$ok, $message] = Backups::run($contents);

        return back()->with($ok ? 'success' : 'error', $ok ? $message : __('The backup failed: :error', ['error' => $message]));
    }

    public function policy(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'backup_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'backup_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'backup_weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'backup_month_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'backup_contents' => ['required', Rule::in(['full', 'db'])],
            'backup_keep_count' => ['required', 'integer', 'min:0', 'max:500'],
            'backup_keep_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);
        $data['backup_enabled'] = $request->boolean('backup_enabled');

        $settings->set(array_filter($data, fn ($v) => $v !== null));
        Activity::log('settings_changed', null, __('Backups'), ['changes' => collect($data)->map(fn ($v) => ['old' => null, 'new' => (string) $v])->all()]);

        return back()->with('success', __('Backup settings saved.'));
    }

    public function download(string $file): StreamedResponse
    {
        $path = Backups::path($file);
        abort_unless($path, 404);

        return Storage::disk(Backups::DISK)->download($path);
    }

    public function destroy(string $file): RedirectResponse
    {
        $path = Backups::path($file);
        abort_unless($path, 404);

        Storage::disk(Backups::DISK)->delete($path);
        Activity::log('backup_deleted', null, basename($path));

        return back()->with('success', __('Backup deleted.'));
    }
}
