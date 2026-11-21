<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftRequest extends FormRequest
{
    /** Errors of each shift form land in their own bag, so only that card shows them. */
    protected $errorBag = 'shift';

    public function authorize(): bool
    {
        return true; // the route checks shifts.manage
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = 'shift'.($this->route('shift')?->id ?? 'new');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'grace_in' => ['required', 'integer', 'min:0', 'max:600'],
            'grace_out' => ['required', 'integer', 'min:0', 'max:600'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'break_start' => [Rule::requiredIf(fn () => (int) $this->input('break_minutes') > 0), 'nullable', 'date_format:H:i'],
            'break_paid' => ['nullable', 'boolean'],
            'weekend' => ['nullable', 'array'],
            'weekend.*' => ['integer', 'between:0,6'],
            'flexible' => ['nullable', 'boolean'],
            'required_hours' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('Name'), 'start_time' => __('Start'), 'end_time' => __('End'),
            'grace_in' => __('Late grace (min)'), 'grace_out' => __('Early leave grace (min)'), 'weekend' => __('Weekend'),
            'break_start' => __('Break starts'), 'break_minutes' => __('Break (min)'),
            'required_hours' => __('Hours to work per day'),
        ];
    }

    /** @return array<string, mixed> */
    public function shift(): array
    {
        $data = $this->validated();
        $data['weekend'] = collect($data['weekend'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        $data['break_minutes'] = (int) ($data['break_minutes'] ?? 0);
        $data['break_start'] = $data['break_minutes'] ? $data['break_start'] : null;
        $data['break_paid'] = $this->boolean('break_paid');
        // Flexible hours: only touched while the feature is on
        unset($data['required_hours']);
        if (feature('flexible')) {
            $data['flexible'] = $this->boolean('flexible');
            $data['required_minutes'] = $this->filled('required_hours') ? (int) round((float) $this->input('required_hours') * 60) : null;
        } else {
            unset($data['flexible']);
        }

        return $data;
    }
}
