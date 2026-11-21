{{-- @include('docs.partials.tip', ['text' => ..., 'tone' => 'tip|warn']) --}}
@php $warn = ($tone ?? 'tip') === 'warn'; @endphp
<div class="flex gap-2.5 rounded-lg px-3 py-2.5 text-sm {{ $warn ? 'bg-amber-50 text-amber-800' : 'bg-brand-50 text-brand-700' }}">
    <i class="fa-solid {{ $warn ? 'fa-triangle-exclamation' : 'fa-lightbulb' }} mt-0.5"></i>
    <p>{{ $text }}</p>
</div>
