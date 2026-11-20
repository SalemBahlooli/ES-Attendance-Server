{{-- Drawn from config/settings.php: add a field there and it appears here. --}}
<x-layouts.app :title="__($group['label'])" :subtitle="__($group['description'] ?? '')"
               :breadcrumbs="[__('Settings') => route('settings.index'), __($group['label']) => null]">
    <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
        <aside class="hidden lg:block">@include('settings.partials.nav')</aside>

        <form method="POST" action="{{ route('settings.update', $groupKey) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="card divide-y divide-slate-100">
                @foreach ($group['fields'] as $key => $field)
                    @php
                        $type = $field['type'] ?? 'text';
                        $value = $values[$key];
                        $label = __($field['label'] ?? $key);
                        $help = isset($field['help']) ? __($field['help']) : null;
                    @endphp
                    <div class="grid gap-3 p-5 md:grid-cols-[1fr_1.4fr] md:gap-8">
                        @if ($type === 'toggle')
                            <div class="md:col-span-2"><x-toggle :name="$key" :label="$label" :help="$help" :checked="(bool) $value" /></div>
                        @else
                            <div>
                                <label for="f_{{ $key }}" class="text-sm font-medium text-slate-800">{{ $label }}</label>
                                @if ($help)<p class="mt-0.5 text-xs text-slate-500">{{ $help }}</p>@endif
                            </div>
                            <div>
                                @switch($type)
                                    @case('select')
                                        <x-select :name="$key" :value="$value" :options="collect($settings->options($field))->map(fn ($l) => __($l))->all()" />
                                        @break
                                    @case('textarea')
                                        <x-textarea :name="$key" :value="$value" rows="3" />
                                        @break
                                    @case('color')
                                        <div class="flex items-center gap-3" x-data="{ c: '{{ old($key, $value) }}' }">
                                            <input type="color" x-model="c" class="h-10 w-14 cursor-pointer rounded-lg border border-slate-300 bg-white p-1">
                                            <input type="text" name="{{ $key }}" x-model="c" class="input w-32 font-mono" maxlength="7">
                                            <span class="btn btn-sm text-white" :style="'background:' + c">{{ __('Preview') }}</span>
                                        </div>
                                        @error($key)<p class="error">{{ $message }}</p>@enderror
                                        @break
                                    @case('image')
                                        <div class="flex flex-wrap items-center gap-4">
                                            @if ($value)
                                                <img src="{{ Storage::disk('public')->url($value) }}" alt="" class="h-14 w-auto max-w-40 rounded-lg border border-slate-200 bg-slate-50 object-contain p-1">
                                            @endif
                                            <input type="file" name="{{ $key }}" accept="image/*,.ico" class="text-sm text-slate-600 file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                                            @if ($value)
                                                <label class="flex items-center gap-2 text-sm text-red-600"><input type="checkbox" name="{{ $key }}_remove" value="1" class="checkbox">{{ __('Remove') }}</label>
                                            @endif
                                        </div>
                                        @error($key)<p class="error">{{ $message }}</p>@enderror
                                        @break
                                    @case('password')
                                        <x-input :name="$key" type="password" autocomplete="new-password" :placeholder="$value ? '••••••••  '.__('(saved)') : ''" />
                                        @break
                                    @default
                                        <x-input :name="$key" :type="in_array($type, ['email', 'url', 'number', 'time']) ? $type : 'text'" :value="$value" />
                                @endswitch
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($groupKey === 'sso')
                    <div class="bg-slate-50/60 p-5 text-sm text-slate-600">
                        <div class="font-medium text-slate-800">{{ __('Redirect URI for the Azure app registration:') }}</div>
                        <code class="mt-1 block rounded-lg bg-white px-3 py-2 font-mono text-xs ring-1 ring-slate-200" dir="ltr">{{ route('auth.microsoft.callback') }}</code>
                    </div>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                @if ($groupKey === 'mail')
                    <button type="submit" form="test-mail" class="btn btn-secondary"><i class="fa-solid fa-paper-plane"></i>{{ __('Send me a test email') }}</button>
                @endif
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save settings') }}</button>
            </div>
        </form>
    </div>

    @if ($groupKey === 'mail')
        <form id="test-mail" method="POST" action="{{ route('settings.mail.test') }}">@csrf</form>
    @endif
</x-layouts.app>
