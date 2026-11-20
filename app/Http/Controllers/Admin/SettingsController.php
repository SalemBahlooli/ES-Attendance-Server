<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Settings pages, drawn from config/settings.php. Adding a setting there adds
 * it here — no controller or view changes needed.
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings) {}

    public function index(): View
    {
        return view('settings.index', ['groups' => $this->settings->groups()]);
    }

    public function edit(string $group): View
    {
        $config = $this->group($group);

        return view('settings.edit', [
            'groupKey' => $group,
            'group' => $config,
            'groups' => $this->settings->groups(),
            'values' => collect($config['fields'])->mapWithKeys(fn ($f, $key) => [$key => $this->settings->get($key)])->all(),
            'settings' => $this->settings,
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $config = $this->group($group);
        $fields = $config['fields'];

        $rules = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = $this->settings->rules($key);
            if (($field['type'] ?? '') === 'toggle') {
                $request->merge([$key => $request->boolean($key)]);
            }
        }
        $data = $request->validate($rules);

        $values = [];
        foreach ($fields as $key => $field) {
            $type = $field['type'] ?? 'text';

            if ($type === 'image') {
                if ($request->boolean($key.'_remove')) {
                    $this->deleteFile($this->settings->get($key));
                    $values[$key] = null;
                } elseif ($request->hasFile($key)) {
                    $this->deleteFile($this->settings->get($key));
                    $values[$key] = $request->file($key)->store('branding', 'public');
                }

                continue;
            }

            if ($type === 'password' && blank($data[$key] ?? null)) {
                continue; // empty = keep the saved secret
            }

            $values[$key] = $data[$key] ?? null;
        }

        $old = collect($values)->mapWithKeys(fn ($v, $k) => [$k => $this->settings->get($k)])->all();
        $this->settings->set($values);

        $changes = [];
        foreach ($values as $key => $new) {
            if ((string) $old[$key] !== (string) $new) {
                $secret = ($fields[$key]['type'] ?? '') === 'password';
                $changes[$key] = ['old' => $secret ? '••••' : $this->short($old[$key]), 'new' => $secret ? '••••' : $this->short($new)];
            }
        }
        if ($changes) {
            Activity::log('settings_changed', null, __($config['label']), ['changes' => $changes]);
        }

        return redirect()->route('settings.edit', $group)->with('success', __('Settings saved.'));
    }

    /** Send a test email to the signed-in admin. */
    public function testMail(Request $request): RedirectResponse
    {
        try {
            Mail::raw(__('This is a test email from :app. If you can read it, email works.', ['app' => setting('app_name')]), function ($message) use ($request) {
                $message->to($request->user()->email)->subject(__('Test email from :app', ['app' => setting('app_name')]));
            });
        } catch (Throwable $e) {
            return back()->with('error', __('The email could not be sent: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('A test email was sent to :email.', ['email' => $request->user()->email]));
    }

    private function group(string $group): array
    {
        $groups = $this->settings->groups();
        abort_unless(isset($groups[$group]), 404);

        return $groups[$group];
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function short(mixed $value): string
    {
        return mb_strimwidth(is_bool($value) ? ($value ? '1' : '0') : (string) $value, 0, 120, '…');
    }
}
