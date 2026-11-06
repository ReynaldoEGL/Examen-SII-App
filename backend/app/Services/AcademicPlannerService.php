<?php
/*

namespace App\Services;

use App\Models\Subject;
use Illuminate\Support\Facades\DB;

class AcademicPlannerService
{
    /**
     * Simulación orientativa por periodos. No almacena el historial de calificaciones:
     * las materias aprobadas se derivan del kardex del SII en cada ejecución.
     */

    /*
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
*/


namespace App\Services;

use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Motor determinista de planeación. No guarda nada: cada llamada recalcula con los datos actuales del SII.
 * Regla de oferta: una materia se ofrece en todos los periodos iguales o posteriores al periodo
 * en que la trayectoria alcanza el semestre donde se imparte (índice de periodo >= semestre sugerido).
 */
class AcademicPlannerService
{
    private const HARD_CAP = 18;

    public function __construct(private readonly AcademicStatusResolver $resolver)
    {
    }

    public function preview(array $kardexData, array $scheduleData, array $profile, array $params): ?array
    {
        $ctx = $this->context($kardexData, $scheduleData, $profile);
        if ($ctx === null) {
            return null;
        }

        $none = ['summer' => [], 'deferred' => [], 'leave' => [], 'fail' => []];
        $input = $this->normalize($params);
        $changed = $input !== $none;
        $base = $this->simulate($ctx, $none);
        $scenario = $changed ? $this->simulate($ctx, $input) : $base;

        return $this->present($ctx, $scenario, $changed ? $base : null);
    }

    // ------------------------------------------------------------------ contexto

    private function normalize(array $params): array
    {
        $code = static fn (mixed $value): string => strtoupper(trim((string) $value));

        $summer = [];
        foreach ((array) ($params['summer'] ?? []) as $row) {
            $summer[] = ['code' => $code($row['code'] ?? ''), 'after' => (int) ($row['after'] ?? 0)];
        }
        $deferred = [];
        foreach ((array) ($params['deferred'] ?? []) as $row) {
            $deferred[] = ['code' => $code($row['code'] ?? ''), 'periods' => max(1, min(4, (int) ($row['periods'] ?? 1)))];
        }

        return [
            'summer' => array_values(array_filter($summer, fn (array $r) => $r['code'] !== '' && $r['after'] >= 1)),
            'deferred' => array_values(array_filter($deferred, fn (array $r) => $r['code'] !== '')),
            'leave' => array_values(array_unique(array_filter(array_map('intval', (array) ($params['leave'] ?? [])), fn (int $v) => $v >= 1))),
            'fail' => array_values(array_unique(array_filter(array_map($code, (array) ($params['fail'] ?? [])), fn (string $v) => $v !== ''))),
        ];
    }

    private function context(array $kardexData, array $scheduleData, array $profile): ?array
    {
        $subjects = [];
        $byId = [];
        $rows = Subject::query()->where('active', true)->orderBy('suggested_semester')->orderBy('code')->get();
        foreach ($rows as $row) {
            $code = strtoupper($row->code);
            $subjects[$code] = [
                'code' => $code,
                'name' => $row->name,
                'credits' => (int) $row->credits,
                'semester' => (int) $row->suggested_semester,
                'difficulty' => (int) $row->difficulty,
                'specialty' => (bool) ($row->is_specialty ?? false),
                'percent' => $row->min_credit_percent !== null ? (float) $row->min_credit_percent : null,
            ];
            $byId[$row->id] = $code;
        }
        if ($subjects === []) {
            return null;
        }

        $prereq = [];
        $coreq = [];
        $edges = [];
        $dependents = [];
        foreach (DB::table('subject_relations')->get(['subject_id', 'related_subject_id', 'relation_type']) as $r) {
            $code = $byId[$r->subject_id] ?? null;
            $required = $byId[$r->related_subject_id] ?? null;
            if ($code === null || $required === null) {
                continue;
            }
            if ($r->relation_type === 'corequisite') {
                $coreq[$code][] = $required;
            } else {
                $prereq[$code][] = $required;
            }
            $edges[] = ['source' => $required, 'target' => $code, 'type' => $r->relation_type];
            $dependents[$required][] = $code;
        }

        $values = [];
        foreach (DB::table('academic_rules')->get(['rule_key', 'value']) as $row) {
            $values[$row->rule_key] = json_decode((string) $row->value, true);
        }
        $loads = [];
        foreach (DB::table('load_rules')->get() as $row) {
            $loads[$row->situation] = [
                'min' => $row->min_credits === null ? null : (int) $row->min_credits,
                'max' => $row->max_credits === null ? null : (int) $row->max_credits,
                'only_special' => (bool) $row->only_special_courses,
            ];
        }
        $milestoneRules = [];
        foreach (DB::table('milestones')->get() as $row) {
            $milestoneRules[$row->code] = [
                'name' => $row->name,
                'credits' => (int) $row->credits,
                'percent' => $row->min_credit_percent === null ? null : (float) $row->min_credit_percent,
                'kardex_code' => $row->kardex_code,
                'window_end' => $row->window_end_semester === null ? null : (int) $row->window_end_semester,
            ];
        }

        $kardexRows = is_array($kardexData['kardex'] ?? null) ? $kardexData['kardex'] : [];
        [$currentCodes, $currentPeriod] = $this->currentEnrollment($scheduleData);
        $resolved = $this->resolver->resolve($kardexRows, array_keys($subjects), $currentCodes);
        $ssCode = strtoupper((string) ($milestoneRules['SERVICIO_SOCIAL']['kardex_code'] ?? 'SS-0000'));
        $done = $this->detectMilestones($kardexRows, $profile, $subjects, $ssCode);

        $milestoneCredits =
            ($done['SERVICIO_SOCIAL'] ? ($milestoneRules['SERVICIO_SOCIAL']['credits'] ?? 10) : 0)
            + ($done['ACT_COMPLEMENTARIAS'] ? ($milestoneRules['ACT_COMPLEMENTARIAS']['credits'] ?? 5) : 0)
            + ($done['RESIDENCIAS'] ? ($milestoneRules['RESIDENCIAS']['credits'] ?? 10) : 0);

        $foreign = [];
        foreach ($kardexRows as $row) {
            $code = strtoupper(trim((string) ($row['clave_materia'] ?? '')));
            $name = Str::upper(Str::ascii((string) ($row['nombre_materia'] ?? '')));
            if ($code === '' || isset($subjects[$code]) || $code === $ssCode
                || str_contains($name, 'SERVICIO SOCIAL') || str_contains($name, 'ACTIVIDADES') || str_contains($name, 'RESIDENCIA')) {
                continue;
            }
            $foreign[$code] = true;
        }

        return [
            'subjects' => $subjects,
            'prereq' => $prereq,
            'coreq' => $coreq,
            'edges' => $edges,
            'tail' => $this->tails(array_keys($subjects), $dependents),
            'rules' => ['values' => $values, 'loads' => $loads, 'milestones' => $milestoneRules],
            'resolved' => $resolved,
            'milestonesDone' => $done,
            'milestoneCredits' => $milestoneCredits,
            'currentPeriod' => $currentPeriod,
            'currentOrdinal' => $currentPeriod !== null ? $this->ordinal($currentPeriod) : null,
            'currentIndex' => $this->currentIndex($kardexRows, $currentPeriod, $profile),
            'base' => (float) ($values['percent_base_credits'] ?? 260),
            'max' => (int) ($values['max_semesters'] ?? 12),
            'foreign' => $foreign,
        ];
    }

    /** @return array{0: array<int,string>, 1: ?string} claves cursando y periodo, desde /horarios */
    private function currentEnrollment(array $schedule): array
    {
        if (isset($schedule['horario'])) {
            $schedule = [$schedule];
        }
        $best = null;
        $bestOrdinal = -1;
        foreach ($schedule as $block) {
            if (!is_array($block)) {
                continue;
            }
            $ordinal = $this->ordinal((string) data_get($block, 'periodo.clave_periodo', '')) ?? 0;
            if ($best === null || $ordinal >= $bestOrdinal) {
                $best = $block;
                $bestOrdinal = $ordinal;
            }
        }
        if ($best === null) {
            return [[], null];
        }
        $codes = [];
        foreach ((array) ($best['horario'] ?? []) as $item) {
            $code = strtoupper(trim((string) ($item['clave_materia'] ?? '')));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        $period = (string) data_get($best, 'periodo.clave_periodo', '');

        return [array_keys($codes), $period !== '' ? $period : null];
    }

    /** '26/2' => 53. Solo periodos ordinarios (/1 = ene-jun, /2 = ago-dic). */
    private function ordinal(string $period): ?int
    {
        return preg_match('/^(\d{2})\/([12])$/', trim($period), $m) === 1 ? ((int) $m[1]) * 2 + (int) $m[2] - 1 : null;
    }

    /** Índice de periodo del alumno (1 = su primer periodo). Cuenta bajas temporales. */
    private function currentIndex(array $kardexRows, ?string $currentPeriod, array $profile): int
    {
        $ordinals = [];
        foreach ($kardexRows as $row) {
            $o = $this->ordinal((string) ($row['periodo'] ?? ''));
            if ($o !== null) {
                $ordinals[] = $o;
            }
        }
        if ($ordinals !== []) {
            $current = $currentPeriod !== null ? $this->ordinal($currentPeriod) : null;

            return max(1, ($current ?? max($ordinals)) - min($ordinals) + 1);
        }

        return max(1, (int) ($profile['semestre'] ?? 1));
    }

    private function calendar(?int $ordinal, int $offset): ?array
    {
        if ($ordinal === null) {
            return null;
        }
        $o = $ordinal + $offset;
        $yy = intdiv($o, 2);
        $term = $o % 2 + 1;
        $year = 2000 + $yy;

        return [
            'code' => sprintf('%02d/%d', $yy % 100, $term),
            'term' => $term,
            'label' => $term === 1 ? "Ene–Jun {$year}" : "Ago–Dic {$year}",
        ];
    }

    private function detectMilestones(array $rows, array $profile, array $subjects, string $ssCode): array
    {
        $ss = $res = $ac = false;
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['clave_materia'] ?? '')));
            $name = Str::upper(Str::ascii((string) ($row['nombre_materia'] ?? '')));
            $grade = strtoupper(trim((string) ($row['calificacion'] ?? '')));
            $passed = $grade === 'AC' || (is_numeric($grade) && (float) $grade >= 70);
            if (!$passed) {
                continue;
            }
            if ($code === $ssCode || str_contains($name, 'SERVICIO SOCIAL')) {
                $ss = true;
            } elseif (str_contains($name, 'RESIDENCIA')) {
                $res = true;
            } elseif (str_contains($name, 'ACTIVIDADES COMPLEMENTARIAS') || ($grade === 'AC' && !isset($subjects[$code]))) {
                $ac = true;
            }
        }
        if ((float) ($profile['creditos_complementarios'] ?? 0) >= 5) {
            $ac = true;
        }

        return ['SERVICIO_SOCIAL' => $ss, 'ACT_COMPLEMENTARIAS' => $ac, 'RESIDENCIAS' => $res];
    }

    /** Longitud de la cadena más larga de materias que dependen de cada una (ruta crítica). */
    private function tails(array $codes, array $dependents): array
    {
        $memo = [];
        $walk = function (string $code, array $stack) use (&$walk, &$memo, $dependents): int {
            if (isset($memo[$code])) {
                return $memo[$code];
            }
            if (in_array($code, $stack, true)) {
                return 0;
            }
            $best = 0;
            foreach ($dependents[$code] ?? [] as $child) {
                $best = max($best, 1 + $walk($child, [...$stack, $code]));
            }

            return $memo[$code] = $best;
        };
        foreach ($codes as $code) {
            $walk($code, []);
        }

        return $memo;
    }

    // ------------------------------------------------------------------ simulación

    private function simulate(array $ctx, array $params): array
    {
        $subjects = $ctx['subjects'];
        $state = $ctx['resolved']['subjects'];
        $currentIndex = $ctx['currentIndex'];
        $max = $ctx['max'];
        $warnings = [];

        // "¿Qué pasa si repruebo X?"
        foreach ($params['fail'] as $code) {
            if (($state[$code]['status'] ?? null) !== 'in_progress') {
                continue;
            }
            $attempts = $state[$code]['failed_attempts'] + 1;
            $name = $subjects[$code]['name'];
            $state[$code]['failed_attempts'] = $attempts;
            $state[$code]['status'] = 'pending';
            $state[$code]['forced_fail'] = true;
            $state[$code]['opportunity'] = match (true) {
                $attempts === 1 => 'repetition',
                $attempts === 2 => 'special',
                default => 'withdrawn',
            };
            $warnings[] = match (true) {
                $attempts === 1 => $this->warn('warning', "Si repruebas {$name}, pasa a curso de repetición y debes cursarla en el siguiente periodo en que se ofrezca.", [$code]),
                $attempts === 2 => $this->warn('warning', "Si repruebas {$name}, pasa a curso especial: baja tu carga máxima y no podrás iniciar Residencias hasta acreditarla.", [$code]),
                default => $this->warn('danger', "Si repruebas {$name} en curso especial, causas baja definitiva.", [$code]),
            };
        }

        $done = [];
        $credits = $ctx['milestoneCredits'];
        $pending = [];
        foreach ($state as $code => $s) {
            if (in_array($s['status'], ['approved', 'in_progress'], true)) {
                $done[$code] = true;
                $credits += $subjects[$code]['credits'];
            } elseif ($s['status'] === 'pending') {
                $pending[$code] = true;
            }
        }

        $withdrawn = [];
        foreach (array_keys($pending) as $code) {
            if ($state[$code]['opportunity'] === 'withdrawn') {
                $withdrawn[] = $code;
                unset($pending[$code]);
            }
        }
        if ($withdrawn !== []) {
            $warnings[] = $this->warn('danger', 'Hay materias con baja definitiva por reprobar el curso especial; el plan no es viable sin una resolución de Servicios Escolares.', $withdrawn);
        }

        $deferFrom = [];
        foreach ($params['deferred'] as $d) {
            $c = $d['code'];
            if (!isset($pending[$c])) {
                $warnings[] = $this->warn('info', "No se pospuso {$c}: no está pendiente.", [$c]);
                continue;
            }
            if (in_array($state[$c]['opportunity'], ['repetition', 'special'], true)) {
                $warnings[] = $this->warn('warning', "{$subjects[$c]['name']} está en repetición o curso especial y debe cursarse en el siguiente periodo en que se ofrezca; no se puede posponer.", [$c]);
                continue;
            }
            $deferFrom[$c] = $d['periods'] + 1;
        }

        $leave = array_fill_keys($params['leave'], true);
        $summerByPeriod = [];
        foreach ($params['summer'] as $s) {
            $summerByPeriod[$s['after']][] = $s['code'];
        }

        $periods = [];
        $planned = [];
        $timeline = [['k' => 0, 'index' => $currentIndex, 'credits' => $credits, 'special' => 0]];
        $ordinaryCount = 0;
        $lastIndex = $currentIndex;

        for ($k = 1; $k <= self::HARD_CAP; $k++) {
            $remaining = array_values(array_filter(array_keys($pending), fn (string $c) => !isset($done[$c])));
            if ($remaining === []) {
                break;
            }
            $index = $currentIndex + $k;
            $beyond = $index > $max;
            $cal = $this->calendar($ctx['currentOrdinal'], $k);
            $repetition = count(array_filter($remaining, fn (string $c) => $state[$c]['opportunity'] === 'repetition'));
            $special = count(array_filter($remaining, fn (string $c) => $state[$c]['opportunity'] === 'special'));
            $timeline[] = ['k' => $k, 'index' => $index, 'credits' => $credits, 'special' => $special];

            if (isset($leave[$k])) {
                $periods[] = $this->row('leave', $k, $index, $cal, [], $subjects, $beyond, ['note' => 'Baja temporal: no cursas materias y el periodo cuenta para el límite de semestres.']);
                continue;
            }

            $situation = $this->situation($repetition, $special);
            $load = $this->loadFor($ctx, $situation);
            $maxCredits = $load['max'] ?? PHP_INT_MAX;
            $pendingSet = array_fill_keys($remaining, true);

            $candidates = $remaining;
            usort($candidates, fn (string $a, string $b) => [$this->rank($state[$a]['opportunity']), -$ctx['tail'][$a], $subjects[$a]['semester'], $a]
                <=> [$this->rank($state[$b]['opportunity']), -$ctx['tail'][$b], $subjects[$b]['semester'], $b]);

            $selected = [];
            $total = 0;
            foreach ($candidates as $code) {
                if (isset($selected[$code])) {
                    continue;
                }
                if ($load['only_special'] && $state[$code]['opportunity'] !== 'special') {
                    continue;
                }
                $unit = $this->unit($ctx, $code, $done, $pendingSet, $selected, $credits, $index, $k, $deferFrom);
                if ($unit === null) {
                    continue;
                }
                $extra = array_sum(array_map(fn (string $c) => $subjects[$c]['credits'], $unit));
                if ($total + $extra > $maxCredits) {
                    continue;
                }
                foreach ($unit as $member) {
                    $selected[$member] = true;
                }
                $total += $extra;
            }

            if ($selected === []) {
                $waiting = false;
                foreach ($remaining as $c) {
                    if ($subjects[$c]['semester'] > $index || ($deferFrom[$c] ?? 1) > $k) {
                        $waiting = true;
                        break;
                    }
                }
                if (!$waiting) {
                    break; // atorado: nada puede avanzar
                }
                $periods[] = $this->row('ordinary', $k, $index, $cal, [], $subjects, $beyond, [
                    'situation' => $situation, 'min' => $load['min'], 'max' => $load['max'],
                    'note' => 'Sin materias elegibles: esperas a que se alcance su semestre o termine el aplazamiento.',
                ]);
                $ordinaryCount++;
                continue;
            }

            $members = array_keys($selected);
            usort($members, fn (string $a, string $b) => [$subjects[$a]['semester'], $a] <=> [$subjects[$b]['semester'], $b]);
            foreach ($members as $code) {
                $done[$code] = true;
                $credits += $subjects[$code]['credits'];
                $planned[$code] = ['period_id' => 'p'.$k, 'kind' => 'ordinary', 'number' => $k, 'index' => $index, 'beyond' => $beyond];
            }

            $isLast = count(array_filter($remaining, fn (string $c) => !isset($selected[$c]))) === 0;
            $min = $load['min'];
            $lowLoad = $min !== null && $total < $min;
            if ($lowLoad && !$isLast) {
                $warnings[] = $this->warn('warning', "El semestre {$index} queda con {$total} créditos, por debajo del mínimo de {$min}. Solo se permite en el último semestre o durante Residencias.", $members);
            }
            $avg = array_sum(array_map(fn (string $c) => $subjects[$c]['difficulty'], $members)) / count($members);
            if (count($members) >= 4 && $avg >= 3.6) {
                $warnings[] = $this->warn('info', "El semestre {$index} concentra materias de dificultad alta (promedio ".round($avg, 1).'/5).', $members);
            }

            $periods[] = $this->row('ordinary', $k, $index, $cal, $members, $subjects, $beyond, [
                'situation' => $situation, 'min' => $load['min'], 'max' => $load['max'],
                'note' => ($lowLoad && $isLast) ? 'Último semestre: se permite una carga menor al mínimo.' : null,
            ]);
            $ordinaryCount++;
            $lastIndex = $index;

            if (isset($summerByPeriod[$k])) {
                if ($cal !== null && $cal['term'] !== 1) {
                    $warnings[] = $this->warn('warning', 'El verano solo existe después del periodo enero–junio; se ignoró esa solicitud.', []);
                } else {
                    $picked = $this->summer($ctx, $summerByPeriod[$k], $state, $pending, $done, $credits, $index, $warnings);
                    if ($picked !== []) {
                        foreach ($picked as $code) {
                            $planned[$code] = ['period_id' => 's'.$k, 'kind' => 'summer', 'number' => $k, 'index' => $index, 'beyond' => $beyond];
                        }
                        $summerCal = $cal ? ['code' => null, 'term' => 0, 'label' => 'Verano '.substr($cal['label'], -4)] : null;
                        $periods[] = $this->row('summer', $k, $index, $summerCal, $picked, $subjects, false, [
                            'needs_group' => true,
                            'note' => 'Sujeta a que se abra grupo (mínimo 15 alumnos). No cuenta para el límite de semestres.',
                        ]);
                    }
                }
            }
        }

        $final = ['k' => $k, 'index' => $currentIndex + $k, 'credits' => $credits, 'special' => 0];
        if ((end($timeline)['k'] ?? -1) !== $k) {
            $timeline[] = $final;
        }

        foreach ($params['summer'] as $s) {
            if ($s['after'] >= $k) {
                $warnings[] = $this->warn('info', "El verano de {$s['code']} no aplica: tu plan termina antes de ese periodo.", [$s['code']]);
            }
        }
        foreach ($params['leave'] as $l) {
            if ($l >= $k) {
                $warnings[] = $this->warn('info', "La baja temporal del periodo {$l} no aplica: tu plan termina antes.", []);
            }
        }

        $unassigned = [];
        foreach (array_keys($pending) as $code) {
            if (!isset($done[$code])) {
                $unassigned[$code] = $this->blockReason($ctx, $code, $done, $credits, $currentIndex + $k, $k, $deferFrom) ?? 'No cabe dentro del horizonte simulado';
            }
        }
        if ($unassigned !== []) {
            $warnings[] = $this->warn('danger', 'No fue posible ubicar '.count($unassigned).' materia(s) en el plan. Revisa los requisitos o el límite de semestres.', array_keys($unassigned));
        }

        $over = array_keys(array_filter($planned, fn (array $p) => $p['beyond']));
        if ($lastIndex > $max && $unassigned === []) {
            $warnings[] = $this->warn('danger', "El plan termina en el semestre {$lastIndex}; el máximo permitido es {$max}. Las materias marcadas quedarían fuera del límite y causarían baja.", $over);
        } elseif ($unassigned === [] && $lastIndex === $max) {
            $warnings[] = $this->warn('warning', "Terminas justo en el semestre {$max}: no queda margen para reprobar ni retrasarte.", []);
        }

        return [
            'periods' => $periods,
            'planned' => $planned,
            'state' => $state,
            'deferred' => array_keys($deferFrom),
            'unassigned' => $unassigned,
            'withdrawn' => $withdrawn,
            'warnings' => $warnings,
            'timeline' => $timeline,
            'last_index' => $lastIndex,
            'ordinary_count' => $ordinaryCount,
            'fits' => $unassigned === [] && $withdrawn === [] && $lastIndex <= $max,
        ];
    }

    /** Motivo por el que una materia no es elegible ahora (null = elegible). */
    private function blockReason(array $ctx, string $code, array $done, int $credits, int $index, int $k, array $deferFrom): ?string
    {
        $subject = $ctx['subjects'][$code];
        if ($index < $subject['semester']) {
            return "Se ofrece a partir del semestre {$subject['semester']} de tu trayectoria";
        }
        if (($deferFrom[$code] ?? 1) > $k) {
            return 'Pospuesta por ti';
        }
        foreach ($ctx['prereq'][$code] ?? [] as $required) {
            if (!isset($done[$required])) {
                return "Requiere {$required} acreditada";
            }
        }
        if ($subject['percent'] !== null) {
            $need = (int) ceil($subject['percent'] * $ctx['base'] / 100);
            if ($credits < $need) {
                return "Requiere {$need} créditos acumulados ({$subject['percent']} %)";
            }
        }

        return null;
    }

    /** Materia + sus correquisitos aún no acreditados (deben cursarse en el mismo periodo). */
    private function unit(array $ctx, string $code, array $done, array $pendingSet, array $selected, int $credits, int $index, int $k, array $deferFrom): ?array
    {
        $unit = [];
        $stack = [$code];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($unit[$current]) || isset($selected[$current])) {
                continue;
            }
            if ($this->blockReason($ctx, $current, $done, $credits, $index, $k, $deferFrom) !== null) {
                return null;
            }
            $unit[$current] = true;
            foreach ($ctx['coreq'][$current] ?? [] as $co) {
                if (isset($done[$co]) || isset($selected[$co])) {
                    continue;
                }
                if (!isset($pendingSet[$co])) {
                    return null;
                }
                $stack[] = $co;
            }
        }

        return array_keys($unit);
    }

    private function summer(array $ctx, array $codes, array $state, array $pending, array &$done, int &$credits, int $index, array &$warnings): array
    {
        $subjects = $ctx['subjects'];
        $limitNormal = (int) ($ctx['rules']['values']['summer_max_subjects'] ?? 2);
        $limitSpecial = (int) ($ctx['rules']['values']['summer_max_subjects_if_special'] ?? 1);
        $picked = [];
        $deny = function (string $code, string $why) use (&$warnings, $subjects): void {
            $name = $subjects[$code]['name'] ?? $code;
            $warnings[] = $this->warn('warning', "No se pudo agendar {$name} en verano: {$why}.", [$code]);
        };

        foreach (array_unique($codes) as $code) {
            if (!isset($subjects[$code])) {
                $deny($code, 'la clave no está en el catálogo');
                continue;
            }
            if (!isset($pending[$code]) || isset($done[$code])) {
                $deny($code, 'ya no está pendiente');
                continue;
            }
            if ($subjects[$code]['specialty']) {
                $deny($code, 'las materias de especialidad no se ofrecen en verano');
                continue;
            }
            $hasSpecial = $state[$code]['opportunity'] === 'special'
                || array_reduce($picked, fn (bool $carry, string $p) => $carry || $state[$p]['opportunity'] === 'special', false);
            $limit = $hasSpecial ? $limitSpecial : $limitNormal;
            if (count($picked) >= $limit) {
                $deny($code, "el máximo es de {$limit} materia(s)");
                continue;
            }
            $reason = $this->blockReason($ctx, $code, $done, $credits, $index, 1, []);
            if ($reason !== null) {
                $deny($code, $reason);
                continue;
            }
            foreach ($ctx['coreq'][$code] ?? [] as $co) {
                if (!isset($done[$co]) && !in_array($co, $picked, true)) {
                    $deny($code, "su correquisito {$co} debe estar acreditado o cursarse junto");
                    continue 2;
                }
            }
            $picked[] = $code;
        }
        foreach ($picked as $code) {
            $done[$code] = true;
            $credits += $subjects[$code]['credits'];
        }

        return $picked;
    }

    private function situation(int $repetition, int $special): string
    {
        return match (true) {
            $special >= 2 => 'two_special',
            $special === 1 => 'one_special',
            $repetition >= 2 => 'two_or_more_repetitions',
            default => 'regular',
        };
    }

    private function loadFor(array $ctx, string $situation): array
    {
        $fallback = [
            'regular' => ['min' => 18, 'max' => 36, 'only_special' => false],
            'two_or_more_repetitions' => ['min' => 18, 'max' => 27, 'only_special' => false],
            'one_special' => ['min' => 18, 'max' => 22, 'only_special' => false],
            'two_special' => ['min' => null, 'max' => null, 'only_special' => true],
        ];

        return $ctx['rules']['loads'][$situation] ?? $fallback[$situation];
    }

    private function rank(?string $opportunity): int
    {
        return match ($opportunity) {
            'special' => 0,
            'repetition' => 1,
            default => 2,
        };
    }

    private function warn(string $severity, string $message, array $subjects): array
    {
        return ['severity' => $severity, 'message' => $message, 'subjects' => array_values($subjects)];
    }

    private function row(string $kind, int $k, ?int $index, ?array $cal, array $codes, array $subjects, bool $beyond, array $extra = []): array
    {
        $label = match ($kind) {
            'summer' => "Verano · tras el semestre {$index}",
            'leave' => "Baja temporal · semestre {$index}",
            default => "Semestre {$index}",
        };

        return [
            'id' => match ($kind) {
                'summer' => 's'.$k,
                'leave' => 'l'.$k,
                default => 'p'.$k,
            },
            'kind' => $kind,
            'number' => $k,
            'index' => $index,
            'label' => $label,
            'calendar' => $cal['label'] ?? null,
            'calendar_code' => $cal['code'] ?? null,
            'subjects' => array_map(fn (string $c) => [
                'code' => $c, 'name' => $subjects[$c]['name'],
                'credits' => $subjects[$c]['credits'], 'difficulty' => $subjects[$c]['difficulty'],
            ], $codes),
            'total_credits' => array_sum(array_map(fn (string $c) => $subjects[$c]['credits'], $codes)),
            'min_credits' => $extra['min'] ?? null,
            'max_credits' => $extra['max'] ?? null,
            'situation' => $extra['situation'] ?? null,
            'beyond_limit' => $kind === 'summer' ? false : $beyond,
            'needs_group' => (bool) ($extra['needs_group'] ?? false),
            'note' => $extra['note'] ?? null,
        ];
    }

    // ------------------------------------------------------------------ presentación

    private function milestones(array $ctx, array $timeline): array
    {
        $rules = $ctx['rules']['milestones'];
        $done = $ctx['milestonesDone'];
        $base = $ctx['base'];
        $need = fn (string $code, float $fallback): int => (int) ceil((($rules[$code]['percent'] ?? $fallback) * $base) / 100);
        $firstK = function (int $credits, bool $noSpecial) use ($timeline): ?int {
            foreach ($timeline as $t) {
                if ($t['k'] >= 1 && $t['credits'] >= $credits && (!$noSpecial || $t['special'] === 0)) {
                    return $t['k'];
                }
            }

            return null;
        };
        $label = fn (?int $k): ?string => $k === null ? null : ($this->calendar($ctx['currentOrdinal'], $k)['label'] ?? null);

        $ssNeed = $need('SERVICIO_SOCIAL', 70.0);
        $resNeed = $need('RESIDENCIAS', 80.0);
        $ssK = $done['SERVICIO_SOCIAL'] ? null : $firstK($ssNeed, false);
        $resFrom = $done['SERVICIO_SOCIAL'] ? 1 : ($ssK === null ? null : $ssK + 1);
        $resBase = $firstK($resNeed, true);
        $resK = ($resFrom === null || $resBase === null) ? null : max($resFrom, $resBase);

        $make = function (string $code, string $name, bool $isDone, ?int $k, int $needCredits, string $detail) use ($label, $ctx): array {
            return [
                'code' => $code,
                'name' => $name,
                'status' => $isDone ? 'done' : ($k === null ? 'blocked' : ($k === 1 ? 'eligible' : 'future')),
                'credits_required' => $needCredits,
                'earliest_number' => $isDone ? null : $k,
                'earliest_index' => ($isDone || $k === null) ? null : $ctx['currentIndex'] + $k,
                'earliest_label' => $isDone ? null : $label($k),
                'detail' => $detail,
            ];
        };

        return [
            [
                'code' => 'ACT_COMPLEMENTARIAS',
                'name' => 'Actividades complementarias',
                'status' => $done['ACT_COMPLEMENTARIAS'] ? 'done' : 'pending',
                'credits_required' => 0,
                'earliest_number' => null, 'earliest_index' => null, 'earliest_label' => null,
                'detail' => $done['ACT_COMPLEMENTARIAS']
                    ? 'Registradas en tu kardex.'
                    : 'No se detectaron. Son requisito para Residencias (la retícula indica cumplirlas entre los semestres 1 y 7).',
            ],
            $make('SERVICIO_SOCIAL', 'Servicio social', $done['SERVICIO_SOCIAL'], $ssK, $ssNeed,
                "Requiere {$ssNeed} créditos acumulados (70 % del plan)."),
            $make('RESIDENCIAS', 'Residencias profesionales', $done['RESIDENCIAS'], $resK, $resNeed,
                "Requiere {$resNeed} créditos (80 %), servicio social y actividades complementarias acreditados y ningún curso especial."),
        ];
    }

    private function present(array $ctx, array $s, ?array $base): array
    {
        $subjects = $ctx['subjects'];
        $resolved = $ctx['resolved'];
        $state = $s['state'];

        $rows = [];
        foreach ($subjects as $code => $sub) {
            $st = $state[$code];
            $p = $s['planned'][$code] ?? null;
            $status = match (true) {
                $st['status'] === 'approved' => 'approved',
                $st['status'] === 'in_progress' => 'in_progress',
                $st['opportunity'] === 'withdrawn' => 'withdrawn',
                $p !== null => 'planned',
                default => 'unassigned',
            };
            $rows[] = [
                'code' => $code,
                'name' => $sub['name'],
                'credits' => $sub['credits'],
                'semester' => $sub['semester'],
                'difficulty' => $sub['difficulty'],
                'specialty' => $sub['specialty'],
                'min_percent' => $sub['percent'],
                'status' => $status,
                'opportunity' => $st['opportunity'],
                'approved_via' => $st['approved_via'] ?? null,
                'failed_attempts' => $st['failed_attempts'],
                'forced_fail' => (bool) ($st['forced_fail'] ?? false),
                'period_id' => $p['period_id'] ?? null,
                'planned_kind' => $p['kind'] ?? null,
                'planned_index' => $p['index'] ?? null,
                'delay' => $p !== null ? max(0, $p['index'] - $sub['semester']) : 0,
                'deferred' => in_array($code, $s['deferred'], true),
                'beyond_limit' => (bool) ($p['beyond'] ?? false),
                'reason' => $s['unassigned'][$code] ?? null,
            ];
        }

        $approvedCredits = $ctx['milestoneCredits'];
        foreach ($resolved['subjects'] as $code => $st) {
            if ($st['status'] === 'approved') {
                $approvedCredits += $subjects[$code]['credits'];
            }
        }
        $calendar = $this->calendar($ctx['currentOrdinal'], 0);

        $summary = [
            'catalog_total' => count($subjects),
            'approved' => $resolved['counts']['approved'],
            'approved_via_repetition' => $resolved['counts']['approved_via_repetition'],
            'in_progress' => $resolved['counts']['in_progress'],
            'pending' => $resolved['counts']['pending'],
            'credits_approved' => $approvedCredits,
            'credits_total' => (int) $ctx['base'],
            'percent' => round($approvedCredits / $ctx['base'] * 100, 1),
            'current_index' => $ctx['currentIndex'],
            'current_period' => $ctx['currentPeriod'],
            'current_label' => $calendar['label'] ?? null,
            'max_semesters' => $ctx['max'],
            'planned_semesters' => (int) ($ctx['rules']['values']['planned_semesters'] ?? 9),
            'last_index' => $s['last_index'],
            'ordinary_periods' => $s['ordinary_count'],
            'fits_limit' => $s['fits'],
            'unassigned' => count($s['unassigned']),
            'situation' => $resolved['situation'],
            'load' => $this->loadFor($ctx, $resolved['situation']),
        ];

        $comparison = $base === null ? null : [
            'base' => ['last_index' => $base['last_index'], 'ordinary_periods' => $base['ordinary_count'], 'fits' => $base['fits']],
            'scenario' => ['last_index' => $s['last_index'], 'ordinary_periods' => $s['ordinary_count'], 'fits' => $s['fits']],
            'delta' => $s['last_index'] - $base['last_index'],
        ];

        $summerCandidates = [];
        $deferrable = [];
        $failable = [];
        foreach ($subjects as $code => $sub) {
            $st = $resolved['subjects'][$code];
            if ($st['status'] === 'pending' && $st['opportunity'] !== 'withdrawn') {
                $item = ['code' => $code, 'name' => $sub['name'], 'credits' => $sub['credits']];
                if (!$sub['specialty']) {
                    $summerCandidates[] = $item;
                }
                $deferrable[] = $item + ['locked' => in_array($st['opportunity'], ['repetition', 'special'], true)];
            } elseif ($st['status'] === 'in_progress') {
                $failable[] = ['code' => $code, 'name' => $sub['name'], 'opportunity' => $st['opportunity']];
            }
        }
        $periodOptions = [];
        for ($k = 1; $k <= 6; $k++) {
            $cal = $this->calendar($ctx['currentOrdinal'], $k);
            $periodOptions[] = [
                'number' => $k,
                'label' => $cal ? "{$cal['label']} ({$cal['code']})" : "Periodo proyectado {$k}",
                'summer_ok' => $cal === null || $cal['term'] === 1,
            ];
        }

        $global = [];
        $inProgress = array_keys(array_filter($resolved['subjects'], fn (array $x) => $x['status'] === 'in_progress'));
        if ($inProgress !== []) {
            $global[] = $this->warn('info', 'Se asume que apruebas las materias del periodo en curso para proyectar lo que sigue.', $inProgress);
        }
        $global[] = $this->warn('info', 'La oferta de grupos es simulada: cada materia se considera disponible desde el periodo en que tu trayectoria alcanza su semestre. Esta herramienta es orientativa y no sustituye a Servicios Escolares ni a tu coordinación.', []);
        if (count($ctx['foreign']) > 2) {
            $global[] = $this->warn('warning', 'Tu kardex contiene claves que no están en el catálogo; verifica que tu plan sea ISIC-2010-224 con la especialidad de Inteligencia de Datos.', array_slice(array_keys($ctx['foreign']), 0, 8));
        }
        if ($resolved['situation'] !== 'regular') {
            $global[] = $this->warn('warning', 'Tienes materias en repetición o curso especial: tu carga máxima y tus opciones se reducen.', []);
        }
        $ac = $ctx['rules']['milestones']['ACT_COMPLEMENTARIAS'] ?? null;
        if (!$ctx['milestonesDone']['ACT_COMPLEMENTARIAS'] && ($ac['window_end'] ?? 7) <= $ctx['currentIndex']) {
            $global[] = $this->warn('warning', 'No se detectaron actividades complementarias acreditadas, y son requisito para Residencias. Confirma el plazo con tu coordinación.', []);
        }

        $warnings = array_merge($global, $s['warnings']);
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($warnings, fn (array $a, array $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return [
            'summary' => $summary,
            'subjects' => $rows,
            'relations' => $ctx['edges'],
            'periods' => $s['periods'],
            'milestones' => $this->milestones($ctx, $s['timeline']),
            'warnings' => $warnings,
            'comparison' => $comparison,
            'options' => [
                'summer_candidates' => $summerCandidates,
                'deferrable' => $deferrable,
                'failable' => $failable,
                'periods' => $periodOptions,
            ],
        ];
    }
}