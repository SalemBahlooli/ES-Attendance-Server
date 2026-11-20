<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route checks employees.edit
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('Name'), 'department_id' => __('Department'), 'shift_id' => __('Shift'),
            'start_date' => __('Start date'), 'is_active' => __('Active'),
        ];
    }
}
