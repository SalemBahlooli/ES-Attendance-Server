{{-- One line describing the password rules from Settings → Security. --}}
@php
    $rules = [trans_choice('at least :count character|at least :count characters', (int) setting('password_min_length', 8), ['count' => (int) setting('password_min_length', 8)])];
    if (setting('password_mixed_case')) $rules[] = __('upper and lower case');
    if (setting('password_numbers')) $rules[] = __('a number');
    if (setting('password_symbols')) $rules[] = __('a symbol');
@endphp
{{ __('Use :rules.', ['rules' => implode(__(', '), $rules)]) }}
