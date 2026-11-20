{{-- Language switch. Languages come from config/settings.php → locales. --}}
@php $locales = config('settings.locales', []); @endphp
@if (count($locales) > 1)
    <x-dropdown align="end" width="w-44">
        <x-slot:trigger>
            <button type="button" class="topbar-link cursor-pointer text-xs font-bold uppercase" aria-label="{{ __('Language') }}">
                <i class="fa-solid fa-globe fa-fw text-base"></i>{{ app()->getLocale() }}
            </button>
        </x-slot:trigger>
        @foreach ($locales as $code => $locale)
            <form method="POST" action="{{ route('locale.switch') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}">
                <button type="submit" @class(['menu-item cursor-pointer justify-between', 'bg-brand-50 font-semibold text-brand-700 hover:bg-brand-50 hover:text-brand-700' => app()->getLocale() === $code])>
                    {{ $locale['native'] }}
                    @if (app()->getLocale() === $code)<i class="fa-solid fa-check text-xs"></i>@endif
                </button>
            </form>
        @endforeach
    </x-dropdown>
@endif
