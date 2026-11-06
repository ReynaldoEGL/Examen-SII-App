<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Convierte los intentos del kardex en el estado por materia del catálogo. Una clave = una materia. */
final class AcademicStatusResolver
{
    public const PASSING_GRADE = 70.0;

    /**
     * @param  array<int, array<string, mixed>>  $kardexRows   data.kardex del SII
     * @param  array<int, string>                $catalogCodes  claves de public.subjects
     * @param  array<int, string>                $currentCodes  claves de /horarios (periodo actual)
     */
    public function resolve(array $kardexRows, array $catalogCodes, array $currentCodes): array
    {
        $catalog = array_fill_keys(array_map($this->code(...), $catalogCodes), true);
        $current = array_fill_keys(array_map($this->code(...), $currentCodes), true);

        $attempts = [];
        foreach ($kardexRows as $row) {
            $code = $this->code((string) ($row['clave_materia'] ?? ''));
            if (! isset($catalog[$code])) {
                continue; // SS-0000, actividades complementarias, residencias...
            }
            $attempts[$code][] = [
                'period' => $this->periodKey((string) ($row['periodo'] ?? '')),
                'grade'  => $this->grade($row['calificacion'] ?? null),
                'type'   => $this->type((string) ($row['descripcion'] ?? '')),
            ];
        }

        $subjects = [];
        $counts = ['approved' => 0, 'approved_via_repetition' => 0, 'in_progress' => 0,
                   'pending' => 0, 'repetition' => 0, 'special' => 0];

        foreach (array_keys($catalog) as $code) {
            $list = $attempts[$code] ?? [];
            usort($list, fn (array $a, array $b) => $a['period'] <=> $b['period']);

            $passing = null;
            $failures = 0;
            foreach ($list as $attempt) {
                if ($attempt['grade'] === null) {
                    continue; // sin calificación numérica: no es aprobada ni reprobada
                }
                if ($attempt['grade'] >= self::PASSING_GRADE) {
                    $passing = $attempt;
                    break;
                }
                $failures++;
            }

            if ($passing !== null) {
                $status = 'approved';
                $opportunity = null;
                $counts['approved']++;
                if ($passing['type'] !== 'ordinary') {
                    $counts['approved_via_repetition']++;
                }
            } else {
                $status = isset($current[$code]) ? 'in_progress' : 'pending';
                $opportunity = match (true) {
                    $failures === 0 => 'ordinary',
                    $failures === 1 => 'repetition',
                    $failures === 2 => 'special',
                    default         => 'withdrawn', // reprobó el curso especial: baja definitiva
                };
                $counts[$status]++;
                if ($opportunity === 'repetition') $counts['repetition']++;
                if ($opportunity === 'special')    $counts['special']++;
            }

            $subjects[$code] = [
                'status' => $status,
                'opportunity' => $opportunity,
                'approved_via' => $passing['type'] ?? null,
                'failed_attempts' => $failures,
            ];
        }

        $situation = match (true) {
            $counts['special'] >= 2    => 'two_special',
            $counts['special'] === 1   => 'one_special',
            $counts['repetition'] >= 2 => 'two_or_more_repetitions',
            default                    => 'regular',   // claves de public.load_rules
        };

        return ['subjects' => $subjects, 'counts' => $counts, 'situation' => $situation];
    }

    private function code(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function periodKey(string $period): int   // '24/1' -> 241
    {
        return preg_match('/^(\d{2})\/(\d)$/', trim($period), $m) ? ((int) $m[1] * 10 + (int) $m[2]) : 0;
    }

    private function grade(mixed $value): ?float
    {
        $text = trim((string) $value);
        return is_numeric($text) ? (float) $text : null;   // 'AC', 'NA' o vacío no son numéricos
    }

    private function type(string $description): string
    {
        $text = Str::upper(Str::ascii($description));
        return match (true) {
            str_contains($text, 'REPETICION') => 'repetition',
            str_contains($text, 'ESPECIAL')   => 'special',
            default                           => 'ordinary',
        };
    }
}