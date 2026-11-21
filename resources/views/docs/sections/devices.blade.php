@php
    $setup = [
        __('On the device open Menu → COMM → Cloud Server Setting.'),
        __('Enter the address and port of this server, with HTTPS off.'),
        __('Check that the device clock and time zone are right: punches are saved with the device time as it is.'),
        __('Within a minute the device shows on the Devices page as online, and every new punch arrives by itself.'),
        __('To bring in older punches, use Re-pull all punches on the Devices page.'),
    ];
@endphp
<section id="devices" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-tablet-screen-button', 'title' => __('Connecting the devices'), 'lead' => __('ZKTeco devices send their punches to this program by themselves (ADMS / Cloud Server).')])

    <div class="grid gap-3 lg:grid-cols-2">
        <div class="card p-4">
            <p class="text-sm font-semibold text-slate-800">{{ __('Setting up a device') }}</p>
            <ol class="mt-3 space-y-2">
                @foreach ($setup as $i => $step)
                    <li class="flex gap-3 text-sm text-slate-600">
                        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white">{{ $i + 1 }}</span>{{ $step }}
                    </li>
                @endforeach
            </ol>
        </div>
        <div class="card space-y-3 p-4">
            <p class="text-sm font-semibold text-slate-800">{{ __('Good to know') }}</p>
            @include('docs.partials.fields', ['fields' => [
                __('Time') => __('The device time is the truth. It is shown as is and never converted between time zones.'),
                __('Allowed devices') => __('Settings → Attendance → Allowed device serial numbers. Empty = any device may send punches.'),
                __('Duplicates') => __('One punch per employee per second. Sending the same punches again is always safe.'),
                __('Deleted punches') => __('A punch deleted here stays deleted even if the device sends it again.'),
                __('No internet at the office') => __('Use the LAN bridge (tools/lan-bridge) on an office PC, or import the USB file.'),
            ]])
        </div>
    </div>
</section>
