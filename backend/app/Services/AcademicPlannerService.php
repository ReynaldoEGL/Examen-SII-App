<?php

namespace App\Services;

use App\Models\Subject;
use Illuminate\Support\Facades\DB;

class AcademicPlannerService
{
    /**
     * Simulación orientativa por periodos. No almacena el historial de calificaciones:
     * las materias aprobadas se derivan del kardex del SII en cada ejecución.
     */
    public function preview(array $kardexData, array $gradesData, int $maxCredits, int $maxPeriods): array
    {
        $approved = [];
        foreach (($kardexData['kardex'] ?? []) as $entry) {
            $grade = $entry['calificacion'] ?? null;
            $code = strtoupper((string) ($entry['clave_materia'] ?? ''));
            if ($code !== '' && is_numeric($grade) && (float) $grade >= 70) {
                $approved[$code] = true;
            }
        }

        $currentlyStudying = [];
        foreach ($gradesData as $period) {
            foreach (($period['materias'] ?? []) as $item) {
                $code = strtoupper((string) data_get($item, 'materia.clave_materia', ''));
                if ($code !== '') {
                    $currentlyStudying[$code] = true;
                }
            }
        }

        $subjects = Subject::query()->where('active', true)->get()->keyBy('code');
        if ($subjects->isEmpty()) {
            return [
                'periods' => [],
                'completed_count' => count($approved),
                'remaining_catalog_count' => 0,
                'warnings' => ['El catálogo está vacío. El administrador debe cargar las materias oficiales de ISC y sus prerrequisitos antes de generar una trayectoria.'],
            ];
        }

        $relations = DB::table('subject_relations')
            ->where('relation_type', 'prerequisite')
            ->get(['subject_id', 'related_subject_id']);
        $prerequisiteIds = [];
        foreach ($relations as $relation) {
            $prerequisiteIds[$relation->subject_id][] = $relation->related_subject_id;
        }

        $codeById = $subjects->keyBy('id')->map(fn (Subject $subject) => $subject->code);
        $done = $approved;
        // Las materias inscritas se consideran aprobadas al cierre del periodo actual únicamente para simular.
        foreach ($currentlyStudying as $code => $_) {
            $done[$code] = true;
        }

        $periods = [];
        $warnings = [
            'La simulación supone que las materias que aparecen en el periodo actual se acreditan.',
            'La simulación usa únicamente prerrequisitos registrados en el catálogo; no incluye todavía reglas de porcentaje de créditos, equivalencias, correquisitos ni disponibilidad real de grupos.',
            'La selección es determinista y prioriza semestre sugerido, dificultad y clave; no sustituye una autorización de Servicios Escolares.',
        ];

        for ($periodNumber = 1; $periodNumber <= $maxPeriods; $periodNumber++) {
            $candidates = $subjects->filter(function (Subject $subject) use ($done, $prerequisiteIds, $codeById): bool {
                if (isset($done[$subject->code])) {
                    return false;
                }
                foreach (($prerequisiteIds[$subject->id] ?? []) as $prerequisiteId) {
                    $prerequisiteCode = $codeById->get($prerequisiteId);
                    if (!$prerequisiteCode || !isset($done[$prerequisiteCode])) {
                        return false;
                    }
                }
                return true;
            })->sort(function (Subject $a, Subject $b): int {
                return [$a->suggested_semester, $a->difficulty, $a->code] <=> [$b->suggested_semester, $b->difficulty, $b->code];
            });

            $selected = [];
            $credits = 0;
            foreach ($candidates as $subject) {
                if ($credits + $subject->credits > $maxCredits) {
                    continue;
                }
                $selected[] = [
                    'code' => $subject->code,
                    'name' => $subject->name,
                    'credits' => $subject->credits,
                    'suggested_semester' => $subject->suggested_semester,
                    'difficulty' => $subject->difficulty,
                ];
                $credits += $subject->credits;
            }

            if ($selected === []) {
                break;
            }

            $periods[] = [
                'number' => $periodNumber,
                'label' => 'Periodo proyectado '.$periodNumber,
                'subjects' => $selected,
                'total_credits' => $credits,
            ];

            foreach ($selected as $subject) {
                $done[$subject['code']] = true;
            }
        }

        $remaining = $subjects->filter(fn (Subject $subject) => !isset($done[$subject->code]))->values();
        if ($remaining->isNotEmpty()) {
            $warnings[] = 'Quedaron materias sin asignar dentro del horizonte solicitado. Puede deberse a prerrequisitos faltantes, ciclos en el catálogo o al límite de créditos por periodo.';
        }

        return [
            'periods' => $periods,
            'completed_count' => count($approved),
            'currently_studying_count' => count($currentlyStudying),
            'remaining_catalog_count' => $remaining->count(),
            'unassigned_subjects' => $remaining->map(fn (Subject $subject) => ['code' => $subject->code, 'name' => $subject->name])->all(),
            'warnings' => $warnings,
        ];
    }
}
