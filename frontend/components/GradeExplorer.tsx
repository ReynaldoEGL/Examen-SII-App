'use client';

import { useMemo, useState } from 'react';

export type GradeRow = {
  period: string;
  subject: string;
  code: string;
  group: string;
  evaluations: Array<string | null>;
};

function gradeClass(value: string | null): string {
  if (value === null || value === '') return 'grade-pending';
  const number = Number(value);
  if (!Number.isFinite(number)) return 'grade-neutral';
  if (number >= 90) return 'grade-good';
  if (number >= 70) return 'grade-mid';
  return 'grade-low';
}

export default function GradeExplorer({ rows }: { rows: GradeRow[] }) {
  const [search, setSearch] = useState('');
  const [period, setPeriod] = useState('all');
  const periods = useMemo(() => Array.from(new Set(rows.map((row) => row.period))), [rows]);
  const filtered = useMemo(() => rows.filter((row) => {
    const matchesText = `${row.subject} ${row.code}`.toLocaleLowerCase('es-MX').includes(search.toLocaleLowerCase('es-MX'));
    return matchesText && (period === 'all' || row.period === period);
  }), [rows, search, period]);

  return (
    <>
      <div className="filters-row">
        <label className="search-field"><span aria-hidden="true">⌕</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar materia o clave..." aria-label="Buscar materia o clave" /></label>
        <select value={period} onChange={(event) => setPeriod(event.target.value)} aria-label="Filtrar por periodo">
          <option value="all">Todos los periodos</option>
          {periods.map((item) => <option value={item} key={item}>{item}</option>)}
        </select>
        <span className="results-count">{filtered.length} materias</span>
      </div>
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Materia</th><th>Clave / Grupo</th><th>Periodo</th><th>Parcial 1</th><th>Parcial 2</th><th>Parcial 3</th><th>Parcial 4</th></tr></thead>
          <tbody>
            {filtered.map((row, index) => <tr key={`${row.code}-${row.period}-${index}`}>
              <td><strong>{row.subject}</strong></td>
              <td><span className="code-text">{row.code}</span><small className="table-sub">Grupo {row.group || '—'}</small></td>
              <td>{row.period}</td>
              {[0, 1, 2, 3].map((partial) => {
                const value = row.evaluations[partial] ?? null;
                return <td key={partial}><span className={`grade-chip ${gradeClass(value)}`}>{value ?? 'Pendiente'}</span></td>;
              })}
            </tr>)}
            {filtered.length === 0 ? <tr><td colSpan={7} className="empty-state">No se encontraron materias con esos filtros.</td></tr> : null}
          </tbody>
        </table>
      </div>
      <p className="data-note">El endpoint entrega evaluaciones parciales, no una calificación final calculada. Los campos null se muestran como pendientes. Colores orientativos: verde ≥90, ámbar 70–89, rojo menor que 70; no representan una regla institucional de evaluación.</p>
    </>
  );
}
