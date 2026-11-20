{{--
    A small form that sends DELETE after a confirmation.
    <x-delete-button :action="route('users.destroy', $user)" :confirm="__('Delete this user?')">{{ __('Delete') }}</x-delete-button>
--}}
@props(['action', 'confirm' => null, 'method' => 'DELETE', 'icon' => 'fa-trash'])
<form method="POST" action="{{ $action }}" data-confirm="{{ $confirm ?? __('Are you sure?') }}" class="inline">
    @csrf
    @method($method)
    <button type="submit" {{ $attributes->merge(['class' => 'btn btn-danger-soft']) }}>
        @if ($icon)<i class="fa-solid {{ $icon }}"></i>@endif{{ $slot }}
    </button>
</form>
