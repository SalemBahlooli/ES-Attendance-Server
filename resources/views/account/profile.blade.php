<x-layouts.app :title="__('My account')" :breadcrumbs="[__('My account') => null, __('Profile') => null]">
    @include('account.partials.nav')

    <form method="POST" action="{{ route('account.profile.update') }}" enctype="multipart/form-data" class="grid gap-5 lg:grid-cols-3">
        @csrf
        @method('PUT')

        <x-card :title="__('Photo')" icon="fa-camera">
            <div class="flex flex-col items-center gap-4 text-center" x-data="{ preview: null }">
                <template x-if="preview"><img :src="preview" class="size-24 rounded-full object-cover ring-1 ring-slate-200" alt=""></template>
                <div x-show="!preview"><x-avatar :user="$user" size="xl" class="[&>*]:size-24!" /></div>
                <label class="btn btn-sm btn-secondary cursor-pointer">
                    <i class="fa-solid fa-upload"></i>{{ __('Choose a photo') }}
                    <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="sr-only"
                           @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                </label>
                <p class="text-xs text-slate-500">{{ __('JPG, PNG or WebP, up to 2 MB.') }}</p>
            </div>
            @if ($user->avatar_path)
                <x-slot:footer>
                    <button form="remove-avatar" class="btn btn-sm btn-ghost text-red-600">{{ __('Remove photo') }}</button>
                </x-slot:footer>
            @endif
        </x-card>

        <x-card :title="__('Profile')" icon="fa-user" class="lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input name="name" :label="__('Full name')" :value="$user->name" required :disabled="! $canEditCore" class="sm:col-span-2" />
                <x-input name="email" type="email" :label="__('Email')" :value="$user->email" required :disabled="! $canEditCore"
                         :help="$canEditCore ? __('You sign in with this email.') : __('Ask an administrator to change your name or email.')" />
                <x-input name="phone" :label="__('Phone')" :value="$user->phone" />
                <x-input name="job_title" :label="__('Job title')" :value="$user->job_title" />
                <x-select name="locale" :label="__('Language')" :value="$user->locale ?? app()->getLocale()"
                          :options="collect(config('settings.locales'))->map(fn ($l) => $l['native'])->all()" />
                <x-select name="timezone" :label="__('Time zone')" :value="$user->timezone" :placeholder="__('System default (:tz)', ['tz' => setting('timezone')])"
                          :options="array_combine(timezone_identifiers_list(), timezone_identifiers_list())" class="sm:col-span-2" />
            </div>
            <x-slot:footer>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button>
            </x-slot:footer>
        </x-card>
    </form>

    <form id="remove-avatar" method="POST" action="{{ route('account.profile.avatar.destroy') }}">@csrf @method('DELETE')</form>
</x-layouts.app>
