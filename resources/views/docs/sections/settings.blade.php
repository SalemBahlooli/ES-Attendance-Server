@php
    $types = [
        'text' => __('Text'), 'email' => __('Email'), 'url' => __('Link'), 'number' => __('Number'), 'textarea' => __('Text'),
        'select' => __('Choice'), 'toggle' => __('On / off'), 'color' => __('Colour'), 'image' => __('Image'), 'password' => __('Secret'), 'time' => __('Time'),
    ];
    $show = function ($field) {
        $d = $field['default'];
        if ($d === null || $d === '') {
            return null;
        }
        if ($field['type'] === 'toggle') {
            return $d ? __('On') : __('Off');
        }
        if ($field['options'] && isset($field['options'][$d])) {
            return __($field['options'][$d]);
        }

        return is_scalar($d) ? (string) $d : null;
    };
@endphp
<section id="settings" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-sliders', 'title' => __('Settings, field by field'), 'lead' => __('Every page of Settings with what each field does. Only people with the permission to manage settings can change them.')])

    <div class="space-y-3">
        @foreach ($settings as $key => $group)
            <details class="card group overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center gap-3 p-4 select-none">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><i class="{{ str_contains($group['icon'], 'fa-brands') ? $group['icon'] : 'fa-solid '.$group['icon'] }}"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-slate-900">{{ __($group['label']) }}</span>
                        <span class="block text-xs text-slate-500">{{ __($group['description']) }}</span>
                    </span>
                    <span class="text-xs text-slate-400">{{ count($group['fields']) }}</span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180"></i>
                </summary>
                <div class="border-t border-slate-100 p-4">
                    <dl class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                        @foreach ($group['fields'] as $field)
                            <div class="grid gap-1 px-3 py-2.5 text-sm sm:grid-cols-[220px_minmax(0,1fr)] sm:gap-4">
                                <dt>
                                    <span class="font-medium text-slate-800">{{ __($field['label']) }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-400">{{ $types[$field['type']] ?? $field['type'] }}</span>
                                </dt>
                                <dd class="text-slate-600">
                                    {{ $field['help'] ? __($field['help']) : '' }}
                                    @if ($field['options'] && $field['type'] === 'select')
                                        <span class="mt-1 flex flex-wrap gap-1">@foreach ($field['options'] as $opt)<span class="chip">{{ __($opt) }}</span>@endforeach</span>
                                    @endif
                                    @if (($d = $show($field)) !== null)
                                        <span class="mt-1 block text-xs text-slate-400">{{ __('Default') }}: {{ $d }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </details>
        @endforeach
    </div>
</section>
