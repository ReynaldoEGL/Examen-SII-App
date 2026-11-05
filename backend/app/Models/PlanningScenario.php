<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanningScenario extends Model
{
    protected $fillable = ['student_number', 'parameters', 'result'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'result' => 'array'];
    }
}
