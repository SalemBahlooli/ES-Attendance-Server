@php $editing = $user->exists; @endphp
<x-layouts.app :title="$editing ? __('Edit :name', ['name' => $user->name]) : __('New user')"
               :breadcrumbs="[__('Users') => route('users.index')] + ($editing ? [$user->name => route('users.show', $user), __('Edit') => null] : [__('New user') => null])">

    <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="grid gap-5 lg:grid-cols-3">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="space-y-5 lg:col-span-2">
            <x-card :title="__('Details')" icon="fa-id-card">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input name="name" :label="__('Full name')" :value="$user->name" required class="sm:col-span-2" />
                    <x-input name="email" type="email" :label="__('Email')" :value="$user->email" required :help="__('Used to sign in.')" />
                    <x-input name="username" :label="__('Username')" :value="$user->username" :help="__('Optional. Can also be used to sign in.')" />
                    <x-input name="phone" :label="__('Phone')" :value="$user->phone" />
                    <x-input name="job_title" :label="__('Job title')" :value="$user->job_title" />
                    <x-select name="locale" :label="__('Language')" :value="$user->locale" :placeholder="__('System default')"
                              :options="collect(config('settings.locales'))->map(fn ($l) => $l['native'])->all()" />
                    <x-select name="timezone" :label="__('Time zone')" :value="$user->timezone" :placeholder="__('System default')"
                              :options="array_combine(timezone_identifiers_list(), timezone_identifiers_list())" />
                    <x-textarea name="notes" :label="__('Notes')" :value="$user->notes" rows="3" class="sm:col-span-2" :help="__('Only people who can see users read this.')" />
                </div>
            </x-card>
        </div>

        <div class="space-y-5">
            @unless ($editing)
                <x-card :title="__('Sign-in')" icon="fa-key">
                    <div class="space-y-4">
                        @can('users.sign_in')
                            <x-toggle name="is_active" :label="__('Can sign in')" :checked="true" />
                        @endcan
                        <x-toggle name="send_welcome" :label="__('Email a link to choose a password')" :checked="true"
                                  :help="__('Recommended. The person picks their own password.')" />
                        <div class="border-t border-slate-100 pt-4">
                            <p class="mb-3 text-xs text-slate-500">{{ __('Or set a password now:') }}</p>
                            <div class="space-y-3">
                                <x-input name="password" type="password" :label="__('Password')" autocomplete="new-password" />
                                <x-input name="password_confirmation" type="password" :label="__('Repeat the password')" autocomplete="new-password" />
                            </div>
                        </div>
                    </div>
                </x-card>

                @if ($canSetAccess)
                    <x-card :title="__('Groups')" icon="fa-user-shield">
                        <div class="space-y-2">
                            @forelse ($groups as $g)
                                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
                                    <input type="checkbox" name="groups[]" value="{{ $g->id }}" class="checkbox mt-0.5" @checked(in_array($g->id, old('groups', $selected)))>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-slate-800">{{ $g->name }}</span>
                                        @if ($g->description)<span class="block text-xs text-slate-500">{{ $g->description }}</span>@endif
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">{{ __('No groups yet.') }}</p>
                            @endforelse
                        </div>
                        <p class="help mt-3">{{ __('Fine-tune single permissions on the user page after saving.') }}</p>
                    </x-card>
                @endif
            @endunless

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1"><i class="fa-solid fa-check"></i>{{ $editing ? __('Save changes') : __('Create user') }}</button>
                <a href="{{ $editing ? route('users.show', $user) : route('users.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</x-layouts.app>
