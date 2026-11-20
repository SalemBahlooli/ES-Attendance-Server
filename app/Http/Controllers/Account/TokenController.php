<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Personal API tokens (Laravel Sanctum). A token acts as its owner: it can
 * only do what the owner's permissions allow. The plain token is shown once.
 */
class TokenController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.tokens', [
            'tokens' => $request->user()->tokens()->latest()->get(),
            'newToken' => session('new_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expires_in' => ['nullable', 'integer', 'in:0,7,30,90,365'],
        ]);

        $days = (int) ($data['expires_in'] ?? 90);
        $token = $request->user()->createToken($data['name'], ['*'], $days > 0 ? now()->addDays($days) : null);

        Activity::log('token_created', $request->user(), $data['name']);

        return back()->with('new_token', $token->plainTextToken)
            ->with('success', __('Token created. Copy it now — it will not be shown again.'));
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $row = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $row->delete();

        Activity::log('token_deleted', $request->user(), $row->name);

        return back()->with('success', __('Token deleted. Anything using it stops working now.'));
    }
}
