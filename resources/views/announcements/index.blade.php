<x-layouts.app :title="__('Announcements')" :breadcrumbs="[__('Announcements') => null]"
               :subtitle="__('Active announcements appear in everyone\'s notification bell and on the home page.')">
    <x-slot:actions>
        <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'announcement-new')"><i class="fa-solid fa-plus"></i>{{ __('New announcement') }}</button>
    </x-slot:actions>

    <div class="space-y-3">
        @forelse ($announcements as $a)
            @php $live = $a->is_active && (! $a->starts_at || $a->starts_at->isPast()) && (! $a->ends_at || $a->ends_at->isFuture()); @endphp
            <div class="card flex flex-wrap items-start gap-4 p-5">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-badge :color="$a->typeColor()" :icon="$a->typeIcon()">{{ $a->typeLabel() }}</x-badge>
                        @if ($live)<x-badge color="green">{{ __('Showing now') }}</x-badge>@elseif (! $a->is_active)<x-badge>{{ __('Hidden') }}</x-badge>@else<x-badge color="amber">{{ __('Scheduled / ended') }}</x-badge>@endif
                    </div>
                    <h3 class="mt-2 font-semibold text-slate-900">{{ $a->title }}</h3>
                    @if ($a->body)<p class="mt-1 text-sm whitespace-pre-line text-slate-600">{{ $a->body }}</p>@endif
                    <p class="mt-2 text-xs text-slate-400">
                        {{ $a->creator?->name ?? __('System') }} · {{ format_datetime($a->created_at) }}
                        @if ($a->starts_at) · {{ __('from :date', ['date' => format_datetime($a->starts_at)]) }}@endif
                        @if ($a->ends_at) · {{ __('until :date', ['date' => format_datetime($a->ends_at)]) }}@endif
                    </p>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-sm btn-secondary" @click="$dispatch('open-modal', 'announcement-{{ $a->id }}')"><i class="fa-solid fa-pen"></i>{{ __('Edit') }}</button>
                    <x-delete-button :action="route('announcements.destroy', $a)" :confirm="__('Delete this announcement?')" class="btn-sm">{{ __('Delete') }}</x-delete-button>
                </div>
            </div>
            <x-modal :name="'announcement-'.$a->id" :title="__('Edit announcement')">
                @include('announcements.form', ['announcement' => $a, 'action' => route('announcements.update', $a), 'method' => 'PUT'])
            </x-modal>
        @empty
            <div class="card"><x-empty icon="fa-bullhorn" :title="__('No announcements yet')">{{ __('Post one to tell everyone about a change, an outage or good news.') }}</x-empty></div>
        @endforelse
    </div>

    <x-modal name="announcement-new" :title="__('New announcement')" :show="$errors->any()">
        @include('announcements.form', ['announcement' => new \App\Models\Announcement(['type' => 'info', 'is_active' => true]), 'action' => route('announcements.store'), 'method' => 'POST'])
    </x-modal>
</x-layouts.app>
