<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

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
            'weekend' => ['nullable', 'array'],
            'weekend.*' => ['integer', 'between:0,6'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('Name'), 'start_time' => __('Start'), 'end_time' => __('End'),
            'grace_in' => __('Late grace (min)'), 'grace_out' => __('Early leave grace (min)'), 'weekend' => __('Weekend'),
        ];
    }

    /** @return array<string, mixed> */
    public function shift(): array
    {
        $data = $this->validated();
        $data['weekend'] = collect($data['weekend'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

        return $data;
    }
}
