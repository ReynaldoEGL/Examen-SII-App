<?php

namespace App\Http\Controllers\Api\Internal;

use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSubjectController
{
    public function index(): JsonResponse
    {
        $subjects = Subject::query()
            ->with('prerequisites:id,code,name')
            ->orderBy('suggested_semester')
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $subjects]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $subject = DB::transaction(function () use ($data): Subject {
            $subject = Subject::query()->create($data['subject']);
            $this->syncPrerequisites($subject, $data['prerequisite_codes']);
            return $subject->load('prerequisites:id,code,name');
        });

        return response()->json(['data' => $subject], 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $data = $this->validated($request, $subject);
        DB::transaction(function () use ($subject, $data): void {
            $subject->update($data['subject']);
            $this->syncPrerequisites($subject, $data['prerequisite_codes']);
        });

        return response()->json(['data' => $subject->fresh()->load('prerequisites:id,code,name')]);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();
        return response()->json(['message' => 'Materia eliminada del catálogo.']);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $input = $request->validate([
            'csv' => ['required', 'string', 'max:900000'],
        ]);

        $lines = preg_split('/\r\n|\n|\r/', trim($input['csv'])) ?: [];
        if (count($lines) < 2) {
            return response()->json(['message' => 'El CSV debe incluir encabezado y al menos una materia.'], 422);
        }

        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), str_getcsv(array_shift($lines)));
        $required = ['code', 'name', 'credits', 'suggested_semester', 'difficulty'];
        if (array_diff($required, $headers)) {
            return response()->json(['message' => 'Encabezados requeridos: code,name,credits,suggested_semester,difficulty.'], 422);
        }

        $count = 0;
        DB::transaction(function () use ($lines, $headers, &$count): void {
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $values = str_getcsv($line);
                if (count($values) !== count($headers)) {
                    throw new \InvalidArgumentException('Una fila CSV tiene un número de columnas incorrecto.');
                }
                $row = array_combine($headers, $values);
                $validated = validator($row, [
                    'code' => ['required', 'string', 'max:30'],
                    'name' => ['required', 'string', 'max:180'],
                    'credits' => ['required', 'integer', 'between:1,20'],
                    'suggested_semester' => ['required', 'integer', 'between:1,20'],
                    'difficulty' => ['required', 'integer', 'between:1,5'],
                ])->validate();

                Subject::query()->updateOrCreate(
                    ['code' => strtoupper(trim($validated['code']))],
                    [
                        'name' => trim($validated['name']),
                        'credits' => $validated['credits'],
                        'suggested_semester' => $validated['suggested_semester'],
                        'difficulty' => $validated['difficulty'],
                        'active' => true,
                    ]
                );
                $count++;
            }
        });

        return response()->json(['message' => 'Importación completada.', 'imported' => $count]);
    }

    /** @return array{subject: array<string, mixed>, prerequisite_codes: array<int, string>} */
    private function validated(Request $request, ?Subject $subject = null): array
    {
        $uniqueCode = Rule::unique('subjects', 'code');
        if ($subject !== null) {
            $uniqueCode->ignore($subject->getKey());
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', $uniqueCode],
            'name' => ['required', 'string', 'max:180'],
            'credits' => ['required', 'integer', 'between:1,20'],
            'suggested_semester' => ['required', 'integer', 'between:1,20'],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'active' => ['sometimes', 'boolean'],
            'prerequisite_codes' => ['sometimes', 'array', 'max:20'],
            'prerequisite_codes.*' => ['string', 'max:30'],
        ]);

        $codes = array_values(array_unique(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $validated['prerequisite_codes'] ?? []
        )));

        if (in_array(strtoupper(trim($validated['code'])), $codes, true)) {
            abort(422, 'Una materia no puede ser prerrequisito de sí misma.');
        }

        $found = Subject::query()->whereIn('code', $codes)->pluck('code')->all();
        if (count($found) !== count($codes)) {
            abort(422, 'Uno o más códigos de prerrequisito no existen en el catálogo. Crea primero esas materias.');
        }

        return [
            'subject' => [
                'code' => strtoupper(trim($validated['code'])),
                'name' => trim($validated['name']),
                'credits' => $validated['credits'],
                'suggested_semester' => $validated['suggested_semester'],
                'difficulty' => $validated['difficulty'],
                'active' => $validated['active'] ?? true,
            ],
            'prerequisite_codes' => $codes,
        ];
    }

    private function syncPrerequisites(Subject $subject, array $codes): void
    {
        $ids = Subject::query()->whereIn('code', $codes)->pluck('id')->all();
        DB::table('subject_relations')->where('subject_id', $subject->id)->where('relation_type', 'prerequisite')->delete();

        foreach ($ids as $id) {
            DB::table('subject_relations')->insert([
                'subject_id' => $subject->id,
                'related_subject_id' => $id,
                'relation_type' => 'prerequisite',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
