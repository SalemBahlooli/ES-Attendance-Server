<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Department::create($this->validated($request, 'department'));

        return back()->with('success', __('Department saved.'));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->validated($request, 'department'.$department->id));

        return back()->with('success', __('Changes saved.'));
    }

    public function destroy(Department $department): RedirectResponse
    {
        $department->delete();

        return back()->with('success', __('Deleted.'));
    }

    private function validated(Request $request, string $bag): array
    {
        return $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:100'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ], [], ['name' => __('Department name'), 'shift_id' => __('Department shift')]);
    }
}
