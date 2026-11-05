import GradeExplorer, { type GradeRow } from '@/components/GradeExplorer';
import { studentRequest } from '@/lib/session';

type GradePeriod = {
  periodo?: { descripcion_periodo?: string; clave_periodo?: string };
  materias?: Array<{
    materia?: { nombre_materia?: string; clave_materia?: string; letra_grupo?: string };
    calificaiones?: Array<{ numero_calificacion?: number; calificacion?: string | null }>;
  }>;
};

export default async function GradesPage() {
  const response = await studentRequest<{ data: GradePeriod[] }>('grades');
  const rows: GradeRow[] = (response.data ?? []).flatMap((period) => (period.materias ?? []).map((item) => {
    const evaluations: Array<string | null> = [null, null, null, null];
    for (const evaluation of item.calificaiones ?? []) {
      const position = Number(evaluation.numero_calificacion) - 1;
      if (position >= 0 && position < 4) evaluations[position] = evaluation.calificacion ?? null;
    }
    return {
      period: period.periodo?.descripcion_periodo ?? period.periodo?.clave_periodo ?? 'Periodo sin identificar',
      subject: item.materia?.nombre_materia ?? 'Materia sin nombre',
      code: item.materia?.clave_materia ?? '—',
      group: item.materia?.letra_grupo ?? '—',
      evaluations,
    };
  }));

  return <>
    <div className="page-heading"><div><p className="eyebrow">SEGUIMIENTO</p><h1>Calificaciones</h1><p className="muted">Evaluaciones registradas en el periodo académico actual.</p></div></div>
    <section className="panel table-panel"><GradeExplorer rows={rows} /></section>
  </>;
}
