@php $tz = display_timezone(); @endphp
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if ($method !== 'POST') @method($method) @endif
    <x-input name="title" :label="__('Title')" :value="$announcement->title" required :id="'title_'.($announcement->id ?? 'new')" />
    <x-textarea name="body" :label="__('Message')" :value="$announcement->body" rows="4" :id="'body_'.($announcement->id ?? 'new')" />
    <x-select name="type" :label="__('Type')" :value="$announcement->type" :id="'type_'.($announcement->id ?? 'new')"
              :options="collect(\App\Models\Announcement::TYPES)->map(fn ($t) => __($t[0]))->all()" />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="starts_at" type="datetime-local" :label="__('Show from')" :id="'starts_'.($announcement->id ?? 'new')"
                 :value="$announcement->starts_at?->setTimezone($tz)->format('Y-m-d\TH:i')" :help="__('Empty = now')" />
        <x-input name="ends_at" type="datetime-local" :label="__('Show until')" :id="'ends_'.($announcement->id ?? 'new')"
                 :value="$announcement->ends_at?->setTimezone($tz)->format('Y-m-d\TH:i')" :help="__('Empty = until hidden')" />
    </div>
    <x-toggle name="is_active" :label="__('Visible')" :checked="$announcement->is_active" :id="'active_'.($announcement->id ?? 'new')"
              :help="__('Saving an announcement shows it as new again in everyone\'s bell.')" />
    <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn btn-secondary" @click="show = false">{{ __('Cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
    </div>
</form>
