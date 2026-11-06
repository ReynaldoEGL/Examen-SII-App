<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = [
        'code',
        'name',
        'credits',
        'theory_hours',
        'practice_hours',
        'suggested_semester',
        'difficulty',
        'is_specialty',
        'min_credit_percent',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'theory_hours' => 'integer',
            'practice_hours' => 'integer',
            'suggested_semester' => 'integer',
            'difficulty' => 'integer',
            'is_specialty' => 'boolean',
            'min_credit_percent' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /** La materia actual requiere la materia relacionada ya aprobada. */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'subject_relations',
            'subject_id',
            'related_subject_id'
        )->withPivot('relation_type')->wherePivot('relation_type', 'prerequisite');
    }

    /**
     * La materia relacionada puede estar aprobada o cursarse en el mismo
     * periodo que esta materia. La relación es direccional en la tabla.
     */
    public function corequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'subject_relations',
            'subject_id',
            'related_subject_id'
        )->withPivot('relation_type')->wherePivot('relation_type', 'corequisite');
    }
}
