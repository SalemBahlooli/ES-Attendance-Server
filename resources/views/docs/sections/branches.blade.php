<section id="branches" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-code-branch', 'title' => __('Imports and branches'), 'lead' => __('Bringing punches from files, and sharing attendance between branches that each run this program.')])

    <div class="grid gap-3 lg:grid-cols-2">
        <div class="card space-y-3 p-4">
            <p class="text-sm font-semibold text-slate-800"><i class="fa-solid fa-file-import me-1.5 text-brand-600"></i>{{ __('Importing a file') }}</p>
            <ol class="list-decimal space-y-1.5 ps-5 text-sm text-slate-600">
                <li>{{ __('Drop the file on Import & export. It is read first and nothing is saved yet.') }}</li>
                <li>{{ __('The preview shows how many punches are new, how many are already saved and which employees will be added.') }}</li>
                <li>{{ __('Press Import. Only new punches are saved; the import is listed in the history.') }}</li>
            </ol>
            @include('docs.partials.tip', ['text' => __('Importing the same file twice never creates duplicates, so when in doubt, import again.')])
        </div>
        <div class="card space-y-3 p-4">
            <p class="text-sm font-semibold text-slate-800"><i class="fa-solid fa-code-branch me-1.5 text-brand-600"></i>{{ __('Between branches') }}</p>
            <div class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 p-3 text-center text-xs text-slate-600">
                <div><i class="fa-solid fa-building text-lg text-slate-500"></i><p class="mt-1">{{ __('Branch A') }}</p><p class="text-slate-400">{{ __('Report → Send to another branch') }}</p></div>
                <i class="fa-solid fa-arrow-right-long text-brand-600 rtl:rotate-180"></i>
                <div><i class="fa-solid fa-file-code text-lg text-brand-600"></i><p class="mt-1">{{ __('Branch file') }}</p></div>
                <i class="fa-solid fa-arrow-right-long text-brand-600 rtl:rotate-180"></i>
                <div><i class="fa-solid fa-building text-lg text-slate-500"></i><p class="mt-1">{{ __('Branch B') }}</p><p class="text-slate-400">{{ __('Import & export') }}</p></div>
            </div>
            @include('docs.partials.fields', ['fields' => [
                __('What is sent') => __('The current punches (after corrections) and leaves of the period, with employee names and departments.'),
                __('Same person') => __('Employee IDs are company-wide: the same ID is the same person in every branch.'),
                __('Sending again') => __('What branch B got from branch A for that period is made to match the new file: removed items are taken out, new ones added.'),
                __('Own data is safe') => __('Branch B keeps its own punches and corrections untouched.'),
                __('This branch name') => __('Written in the files you send, set in Settings → Attendance.'),
            ]])
        </div>
    </div>
</section>
