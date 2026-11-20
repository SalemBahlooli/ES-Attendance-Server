<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'shift_id'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
