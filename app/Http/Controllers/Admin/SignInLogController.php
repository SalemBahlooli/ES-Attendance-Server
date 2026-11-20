<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SignIn;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Every sign-in attempt in the system: successes, failures, lockouts, new devices. */
class SignInLogController extends Controller
{
    public function index(Request $request): View
    {
        $tz = display_timezone();

        $query = SignIn::with('user')
            ->when($request->query('login'), fn ($q, $l) => $q->where('login', 'like', '%'.$l.'%'))
            ->when($request->query('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('result') === 'ok', fn ($q) => $q->where('successful', true))
            ->when($request->query('result') === 'failed', fn ($q) => $q->where('successful', false))
            ->when($request->query('result') === 'new_device', fn ($q) => $q->where('new_device', true))
            ->when($request->query('method'), fn ($q, $m) => $q->where('method', $m))
            ->when($request->query('ip'), fn ($q, $ip) => $q->where('ip_address', 'like', $ip.'%'))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()));

        $since = now()->subDay();

        return view('sign-ins.index', [
            'rows' => $query->latest('id')->paginate(setting('per_page', 25))->withQueryString(),
            'stats' => [
                'ok' => SignIn::where('successful', true)->where('created_at', '>=', $since)->count(),
                'failed' => SignIn::where('successful', false)->where('created_at', '>=', $since)->count(),
                'locked' => SignIn::where('reason', 'locked_out')->where('created_at', '>=', $since)->count(),
                'new' => SignIn::where('new_device', true)->where('created_at', '>=', $since)->count(),
            ],
        ]);
    }
}
