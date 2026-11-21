@php
    $steps = [
        ['fa-fingerprint', __('The device'), __('The employee puts a finger or face on the device. It records the employee ID and the time on its own clock.')],
        ['fa-cloud-arrow-up', __('Sent to the server'), __('Within seconds the device sends the punch to this program over the network (ADMS). No one needs to press anything.')],
        ['fa-database', __('Saved as a punch'), __('The punch is saved once. The same employee at the same second is never saved twice, whatever its source.')],
        ['fa-gears', __('The rules'), __('When a page opens, the calculator takes the punches of each day and applies the shift, grace, break, leaves and holidays.')],
        ['fa-chart-column', __('What you see'), __('Daily attendance, the report, the overview, the timesheet in Excel and the employee portal.')],
    ];
    $sources = [
        ['fa-tower-broadcast', __('Device over the network'), __('Automatic, every punch as it happens.')],
        ['fa-hard-drive', __('USB or a file'), __('The device export (attlog.dat) or a CSV / TXT file, from Import & export.')],
        ['fa-network-wired', __('Over the LAN'), __('Read straight from the device on port 4370, or by the LAN bridge on an office PC.')],
        ['fa-code-branch', __('Another branch'), __('A branch file sent by another copy of this program.')],
        ['fa-hand', __('By hand'), __('Added or corrected in the Edit punches window, or an approved punch correction request.')],
    ];
@endphp
<section id="journey" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-route', 'title' => __('The journey of a punch'), 'lead' => __('From a finger on the device to a number in the report.')])

    <ol class="grid gap-3 md:grid-cols-5">
        @foreach ($steps as $i => [$icon, $title, $text])
            <li class="card relative p-4">
                <span class="absolute end-3 top-3 text-xs font-bold text-slate-300">{{ $i + 1 }}</span>
                <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-white"><i class="fa-solid {{ $icon }} text-sm"></i></span>
                <p class="mt-3 font-semibold text-slate-900">{{ $title }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $text }}</p>
                @unless ($loop->last)
                    <i class="fa-solid fa-chevron-right absolute -end-2.5 top-1/2 z-10 hidden -translate-y-1/2 text-slate-300 md:block rtl:rotate-180"></i>
                @endunless
            </li>
        @endforeach
    </ol>

    <div class="card mt-4 p-4">
        <p class="text-sm font-semibold text-slate-800">{{ __('Five ways a punch can arrive') }}</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($sources as [$icon, $title, $text])
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-sm font-medium text-slate-800"><i class="fa-solid {{ $icon }} me-1.5 text-brand-600"></i>{{ $title }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
