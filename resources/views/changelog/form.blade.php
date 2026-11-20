@php $key = $entry->id ?? 'new'; @endphp
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if ($method !== 'POST') @method($method) @endif
    <div class="grid gap-4 sm:grid-cols-3">
        <x-input name="released_on" type="date" :label="__('Date')" :value="$entry->released_on?->format('Y-m-d')" required :id="'released_'.$key" />
        <x-input name="version" :label="__('Version')" :value="$entry->version" placeholder="1.2.0" :id="'version_'.$key" />
        <x-select name="category" :label="__('Type')" :value="$entry->category" :id="'category_'.$key"
                  :options="collect(\App\Models\ChangelogEntry::CATEGORIES)->map(fn ($c) => __($c[0]))->all()" />
    </div>
    <x-input name="title" :label="__('Title')" :value="$entry->title" required :id="'title_'.$key" />
    <x-textarea name="body" :label="__('Details')" :value="$entry->body" rows="4" :id="'body_'.$key" />
    <x-toggle name="is_published" :label="__('Published')" :checked="$entry->is_published" :help="__('Drafts are only visible to people who manage the changelog.')" :id="'published_'.$key" />
    <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn btn-secondary" @click="show = false">{{ __('Cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
    </div>
</form>
