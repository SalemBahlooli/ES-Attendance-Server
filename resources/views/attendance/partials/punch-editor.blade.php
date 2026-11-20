{{--
    The "Edit punches" dialog. Open it with
    <button @click="$dispatch('edit-punches', { pin: '12', date: '2026-11-20' })">
    The page reloads when it closes after a change.
--}}
@can('punches.edit')
<div x-data="punchEditor(@js([
        'dayUrl' => route('punches.day'),
        'storeUrl' => route('punches.store'),
        'punchUrl' => url('punches'),
    ]))"
     x-on:edit-punches.window="open($event.detail)"
     x-on:keydown.escape.window="close()"
     x-show="show" x-cloak class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-900/50" @click="close()"></div>
    <div x-show="show" x-transition class="relative mx-auto mt-10 w-full overflow-hidden rounded-2xl bg-white shadow-2xl sm:max-w-lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h3 class="text-base font-semibold text-slate-900">{{ __('Punches') }}</h3>
                <p class="text-xs text-slate-500"><span x-text="employee"></span> · <span x-text="date" dir="ltr"></span></p>
            </div>
            <button type="button" class="btn-icon size-8" @click="close()" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="space-y-4 p-5">
            <template x-if="loading"><p class="text-sm text-slate-500"><i class="fa-solid fa-spinner fa-spin"></i> {{ __('Loading…') }}</p></template>
            <template x-if="!loading && !punches.length"><p class="text-sm text-slate-500">{{ __('No punches on this day.') }}</p></template>

            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200" x-show="punches.length">
                <template x-for="p in punches" :key="p.id">
                    <li class="flex flex-wrap items-center gap-2 px-3 py-2">
                        <input type="time" class="input w-32" x-model="p.edit" dir="ltr">
                        <span class="min-w-0 flex-1 text-xs text-slate-500">
                            <span x-text="p.source_label"></span>
                            <template x-if="p.created_by"><span> · <span x-text="p.created_by"></span></span></template>
                        </span>
                        <button type="button" class="btn btn-sm btn-secondary" x-show="p.edit !== p.time.slice(11, 16)" @click="save(p)"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button>
                        <button type="button" class="btn btn-sm btn-danger-soft" @click="remove(p)" aria-label="{{ __('Delete') }}"><i class="fa-solid fa-trash"></i></button>
                    </li>
                </template>
            </ul>

            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label class="label" for="pe-new">{{ __('Add a punch') }}</label>
                    <input id="pe-new" type="time" class="input" x-model="newTime" dir="ltr">
                </div>
                <button type="button" class="btn btn-secondary" @click="add()" :disabled="!newTime"><i class="fa-solid fa-plus"></i>{{ __('Add punch') }}</button>
            </div>

            <div>
                <label class="label" for="pe-note">{{ __('Reason (optional, saved in the activity log)') }}</label>
                <input id="pe-note" class="input" x-model="note" maxlength="200">
            </div>

            <p class="error" x-show="error" x-text="error"></p>
            <p class="help">{{ __('Edited and deleted punches are kept in the background, so the device cannot send them back.') }}</p>

            <div class="flex justify-end">
                <button type="button" class="btn btn-primary" @click="close()">{{ __('Done') }}</button>
            </div>
        </div>
    </div>
</div>
@endcan
