<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = ['code', 'name', 'credits', 'suggested_semester', 'difficulty', 'active'];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'suggested_semester' => 'integer',
            'difficulty' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'subject_relations',
            'subject_id',
            'related_subject_id'
        )->withPivot('relation_type')->wherePivot('relation_type', 'prerequisite');
    }
}
