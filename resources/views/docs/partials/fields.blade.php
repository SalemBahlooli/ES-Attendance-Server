{{-- @include('docs.partials.fields', ['fields' => [label => explanation, ...]]) --}}
<dl class="divide-y divide-slate-100 rounded-lg border border-slate-200">
    @foreach ($fields as $label => $text)
        <div class="grid gap-1 px-3 py-2.5 text-sm sm:grid-cols-[180px_minmax(0,1fr)] sm:gap-4">
            <dt class="font-medium text-slate-800">{{ $label }}</dt>
            <dd class="text-slate-600">{{ $text }}</dd>
        </div>
    @endforeach
</dl>
