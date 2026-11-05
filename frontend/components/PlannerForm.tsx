'use client';

import { useActionState } from 'react';
import { generatePlannerPreview, type PlannerActionState } from '@/app/actions';

const initialState: PlannerActionState = {};

export default function PlannerForm() {
  const [state, action, pending] = useActionState(generatePlannerPreview, initialState);

  return (
    <div className="planner-layout">
      <section className="panel planner-controls">
        <p className="eyebrow">SIMULACIÓN</p>
        <h2>Configura el escenario</h2>
        <p className="muted">El cálculo consulta tu kardex y las materias cursando directamente al SII; usa el catálogo académico administrado por el equipo.</p>
        <form action={action} className="form-stack">
          <label htmlFor="max_credits">Límite de créditos por periodo</label>
          <input id="max_credits" name="max_credits" type="number" min="10" max="40" defaultValue="30" required />
          <label htmlFor="max_periods">Máximo de periodos a simular</label>
          <input id="max_periods" name="max_periods" type="number" min="1" max="12" defaultValue="8" required />
          <button className="button button-primary" type="submit" disabled={pending}>{pending ? 'Calculando…' : 'Generar planeación'}</button>
        </form>
        {state.error ? <div className="alert alert-error" role="alert">{state.error}</div> : null}
      </section>

      <section className="planner-results">
        {!state.data ? <div className="panel planner-empty"><span className="empty-symbol">⌁</span><h2>Tu trayectoria proyectada aparecerá aquí</h2><p className="muted">Genera una simulación después de que el administrador haya cargado el plan de estudios y los prerrequisitos.</p></div> : <>
          <div className="planner-summary">
            <div className="panel"><span>Materias aprobadas</span><strong>{state.data.completed_count}</strong></div>
            <div className="panel"><span>Materias cursando</span><strong>{state.data.currently_studying_count ?? 0}</strong></div>
            <div className="panel"><span>Materias pendientes en catálogo</span><strong>{state.data.remaining_catalog_count}</strong></div>
          </div>
          {state.data.periods.length ? state.data.periods.map((period) => <article className="panel projected-period" key={period.number}>
            <div className="panel-heading"><div><p className="eyebrow">ETAPA {period.number}</p><h2>{period.label}</h2></div><span className="status-pill">{period.total_credits} créditos</span></div>
            <ul className="planned-subjects">{period.subjects.map((subject) => <li key={subject.code}><span><strong>{subject.name}</strong><small>{subject.code} · Dificultad {subject.difficulty}/5</small></span><b>{subject.credits} cr.</b></li>)}</ul>
          </article>) : <div className="panel"><p>No fue posible asignar materias a los periodos. Verifica que el catálogo esté cargado y que los prerrequisitos no formen ciclos.</p></div>}
          {state.data.warnings.map((warning, index) => <p className="planner-warning" key={index}>! {warning}</p>)}
          {state.data.unassigned_subjects?.length ? <div className="panel"><h3>Materias sin asignar</h3><ul>{state.data.unassigned_subjects.map((subject) => <li key={subject.code}>{subject.code} — {subject.name}</li>)}</ul></div> : null}
        </>}
      </section>
    </div>
  );
}
