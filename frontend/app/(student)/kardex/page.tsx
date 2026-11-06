import { studentRequest } from '@/lib/session';

type KardexItem = {
  nombre_materia: string;
  clave_materia: string;
  periodo: string;
  creditos: string;
  calificacion: string;
  descripcion: string;
  semestre: number;
};

function gradeStyle(value: string): string {
  if (value === 'AC') return 'grade-neutral';
  const grade = Number(value);
  if (!Number.isFinite(grade)) return 'grade-neutral';
  return grade >= 90 ? 'grade-good' : grade >= 70 ? 'grade-mid' : 'grade-low';
}

export default async function KardexPage() {
  const response = await studentRequest<{ data: { porcentaje_avance?: number; kardex?: KardexItem[] } }>('kardex');
  const items = response.data.kardex ?? [];
  const progress = Number(response.data.porcentaje_avance ?? 0);

  return <>
    <div className="page-heading"><div><p className="eyebrow">HISTORIAL</p><h1>Kardex académico</h1></div><div className="heading-stat"><strong>{Number.isFinite(progress) ? progress.toFixed(2) : '—'}%</strong><span>Avance del kardex</span></div></div>
    <section className="panel table-panel">
      <div className="panel-heading"><div><h2>Materias cursadas</h2><p className="muted">{items.length} registros históricos</p></div></div>
      <div className="table-wrap"><table className="data-table">
        <thead><tr><th>Semestre</th><th>Materia</th><th>Clave</th><th>Periodo</th><th>Créditos</th><th>Calificación</th><th>Tipo de evaluación</th></tr></thead>
        <tbody>{items.map((item, index) => <tr key={`${item.clave_materia}-${item.periodo}-${index}`}>
          <td>{item.semestre}</td><td><strong>{item.nombre_materia}</strong></td><td><span className="code-text">{item.clave_materia}</span></td><td>{item.periodo}</td><td>{item.creditos}</td><td><span className={`grade-chip ${gradeStyle(item.calificacion)}`}>{item.calificacion}</span></td><td>{item.descripcion}</td>
        </tr>)}{items.length === 0 ? <tr><td colSpan={7} className="empty-state">El SII no devolvió registros del kardex.</td></tr> : null}</tbody>
      </table></div>
    </section>
    
  </>;
}
