<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SignIn;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/** Settings → System information: versions, database, queue, storage. */
class SystemController extends Controller
{
    public function index(): View
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = [true, DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName()];
        } catch (Throwable $e) {
            $checks['database'] = [false, $e->getMessage()];
        }

        try {
            Cache::put('system-check', 1, 5);
            $checks['cache'] = [Cache::get('system-check') === 1, config('cache.default')];
        } catch (Throwable $e) {
            $checks['cache'] = [false, $e->getMessage()];
        }

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $checks['queue'] = [$failedJobs === 0, config('queue.default').' · '.__(':pending waiting, :failed failed', ['pending' => $pendingJobs, 'failed' => $failedJobs])];

        $checks['storage'] = [is_writable(storage_path()), storage_path()];
        $checks['storage_link'] = [file_exists(public_path('storage')), 'public/storage'];
        $checks['debug'] = [! config('app.debug') || ! app()->isProduction(), config('app.debug') ? __('On') : __('Off')];

        return view('settings.system', [
            'checks' => $checks,
            'info' => [
                'Application' => setting('app_name'),
                'Environment' => app()->environment(),
                'URL' => config('app.url'),
                'Laravel' => Application::VERSION,
                'PHP' => PHP_VERSION,
                'Server' => php_uname('s').' '.php_uname('r'),
                'Time zone' => setting('timezone').' (stored in '.config('app.timezone').')',
                'Session' => config('session.driver').' · '.config('session.lifetime').' min',
                'Mail' => config('mail.default'),
                'Upload limit' => ini_get('upload_max_filesize'),
                'Memory limit' => ini_get('memory_limit'),
            ],
            'counts' => [
                'Users' => User::withTrashed()->count(),
                'Activity log rows' => ActivityLog::count(),
                'Sign-in log rows' => SignIn::count(),
                'Active sessions' => config('session.driver') === 'database' ? DB::table('sessions')->count() : null,
            ],
            'extensions' => collect(['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'mbstring', 'intl', 'gd', 'zip', 'bcmath', 'opcache', 'openssl', 'fileinfo'])
                ->mapWithKeys(fn ($ext) => [$ext => extension_loaded($ext) || ($ext === 'opcache' && function_exists('opcache_get_status'))]),
        ]);
    }
}
