/*'use client';

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
}*/

'use client';

import { useCallback, useEffect, useRef, useState, useTransition } from 'react';
import { generatePlannerPreview } from '@/app/actions';
import TrajectoryMap from '@/components/TrajectoryMap';
import type { PlannerInput, PlannerResult } from '@/lib/planner-types';

const EMPTY: PlannerInput = { summer: [], deferred: [], leave: [], fail: [] };

const MILESTONE_TEXT: Record<string, string> = {
  done: 'Listo',
  eligible: 'Elegible el próximo periodo',
  future: 'Elegible más adelante',
  blocked: 'Aún no alcanzable',
  pending: 'Pendiente',
};

export default function PlannerForm() {
  const [input, setInput] = useState<PlannerInput>(EMPTY);
  const [result, setResult] = useState<PlannerResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [pending, startTransition] = useTransition();
  const [summerDraft, setSummerDraft] = useState({ code: '', after: '' });
  const [deferDraft, setDeferDraft] = useState({ code: '', periods: '1' });
  const started = useRef(false);

  const run = useCallback((next: PlannerInput) => {
    setError(null);
    startTransition(async () => {
      const response = await generatePlannerPreview(next);
      if (response.error) setError(response.error);
      if (response.data) {
        setResult(response.data);
        setDirty(false);
      }
    });
  }, []);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    run(EMPTY);
  }, [run]);

  const change = (patch: Partial<PlannerInput>) => {
    setInput((previous) => ({ ...previous, ...patch }));
    setDirty(true);
  };

  const options = result?.options;
  const periodLabel = (number: number) => options?.periods.find((p) => p.number === number)?.label ?? `Periodo ${number}`;

  function addSummer() {
    if (!summerDraft.code || !summerDraft.after) return;
    if (input.summer.some((item) => item.code === summerDraft.code)) return;
    change({ summer: [...input.summer, { code: summerDraft.code, after: Number(summerDraft.after) }] });
    setSummerDraft({ code: '', after: '' });
  }

  function addDeferral() {
    if (!deferDraft.code) return;
    if (input.deferred.some((item) => item.code === deferDraft.code)) return;
    change({ deferred: [...input.deferred, { code: deferDraft.code, periods: Number(deferDraft.periods) }] });
    setDeferDraft({ code: '', periods: '1' });
  }

  const toggle = <T,>(list: T[], value: T): T[] => (list.includes(value) ? list.filter((item) => item !== value) : [...list, value]);
  const nameOf = (code: string) => result?.subjects.find((s) => s.code === code)?.name ?? code;

  return (
    <div className="planner-layout">
      <aside className="panel planner-controls">
        <p className="eyebrow">SIMULACIÓN</p>
        <h2>Configura tu escenario</h2>
        <p className="muted">Calculamos tu trayectoria con tu kardex y tu horario actuales. Cada cambio se compara contra tu plan sin modificaciones.</p>

        <section className="scenario-block">
          <h3>Verano</h3>
          <p className="field-hint">Máximo 2 materias, sin especialidad. Requiere que se abra grupo (15 alumnos) y no cuenta para los 12 semestres.</p>
          <select value={summerDraft.code} onChange={(e) => setSummerDraft({ ...summerDraft, code: e.target.value })} aria-label="Materia para verano">
            <option value="">Materia…</option>
            {options?.summer_candidates.map((s) => <option key={s.code} value={s.code}>{s.name}</option>)}
          </select>
          <select value={summerDraft.after} onChange={(e) => setSummerDraft({ ...summerDraft, after: e.target.value })} aria-label="Verano posterior a">
            <option value="">Tras el periodo…</option>
            {options?.periods.filter((p) => p.summer_ok).map((p) => <option key={p.number} value={p.number}>{p.label}</option>)}
          </select>
          <button type="button" className="button button-secondary button-small" onClick={addSummer}>Agregar al verano</button>
          <ul className="chip-row">
            {input.summer.map((item) => (
              <li className="chip" key={item.code}>
                {nameOf(item.code)} · tras {periodLabel(item.after)}
                <button type="button" aria-label={`Quitar ${item.code}`} onClick={() => change({ summer: input.summer.filter((x) => x.code !== item.code) })}>×</button>
              </li>
            ))}
          </ul>
        </section>

        <section className="scenario-block">
          <h3>Dejar una materia para después</h3>
          <select value={deferDraft.code} onChange={(e) => setDeferDraft({ ...deferDraft, code: e.target.value })} aria-label="Materia a posponer">
            <option value="">Materia…</option>
            {options?.deferrable.filter((s) => !s.locked).map((s) => <option key={s.code} value={s.code}>{s.name}</option>)}
          </select>
          <select value={deferDraft.periods} onChange={(e) => setDeferDraft({ ...deferDraft, periods: e.target.value })} aria-label="Periodos a posponer">
            {[1, 2, 3, 4].map((n) => <option key={n} value={n}>{n} periodo{n > 1 ? 's' : ''}</option>)}
          </select>
          <button type="button" className="button button-secondary button-small" onClick={addDeferral}>Posponer</button>
          <ul className="chip-row">
            {input.deferred.map((item) => (
              <li className="chip" key={item.code}>
                {nameOf(item.code)} · {item.periods} periodo{item.periods > 1 ? 's' : ''}
                <button type="button" aria-label={`Quitar ${item.code}`} onClick={() => change({ deferred: input.deferred.filter((x) => x.code !== item.code) })}>×</button>
              </li>
            ))}
          </ul>
        </section>

        <section className="scenario-block">
          <h3>Baja temporal</h3>
          <p className="field-hint">El periodo de baja cuenta para el límite de 12 semestres.</p>
          <div className="chip-row">
            {options?.periods.map((p) => (
              <button
                type="button"
                key={p.number}
                className={`chip-toggle${input.leave.includes(p.number) ? ' is-on' : ''}`}
                aria-pressed={input.leave.includes(p.number)}
                onClick={() => change({ leave: toggle(input.leave, p.number) })}
              >{p.label}</button>
            ))}
          </div>
        </section>

        <section className="scenario-block">
          <h3>¿Qué pasa si repruebo…?</h3>
          {options?.failable.length ? options.failable.map((s) => (
            <label className="check-label" key={s.code}>
              <input type="checkbox" checked={input.fail.includes(s.code)} onChange={() => change({ fail: toggle(input.fail, s.code) })} />
              {s.name}
            </label>
          )) : <p className="field-hint">No hay materias en curso.</p>}
        </section>

        {dirty ? <p className="planner-warning is-info">Hay cambios sin calcular.</p> : null}
        <div className="planner-actions">
          <button type="button" className="button button-primary" disabled={pending} onClick={() => run(input)}>
            {pending ? 'Calculando…' : dirty ? 'Recalcular con cambios' : 'Recalcular'}
          </button>
          <button type="button" className="button button-ghost" disabled={pending} onClick={() => { setInput(EMPTY); run(EMPTY); }}>Restablecer</button>
        </div>
        {error ? <div className="alert alert-error" role="alert">{error}</div> : null}
      </aside>

      <section className="planner-results">
        {!result ? (
          <div className="panel loading-state" role="status">
            <div className="loading-spinner" />
            <strong>Calculando tu trayectoria…</strong>
            <p>Consultamos tu kardex y tu horario en el SII.</p>
          </div>
        ) : (
          <>
            <SummaryCards result={result} />
            <Comparison result={result} />
            <TrajectoryMap result={result} />
            <Periods result={result} />
            <Milestones result={result} />
            <Warnings result={result} />
          </>
        )}
      </section>
    </div>
  );

  function SummaryCards({ result: r }: { result: PlannerResult }) {
    const s = r.summary;
    const cards = [
      { label: 'Materias aprobadas', value: String(s.approved), detail: s.approved_via_repetition ? `${s.approved_via_repetition} acreditada(s) en repetición` : `de ${s.catalog_total} del catálogo` },
      { label: 'Cursando ahora', value: String(s.in_progress), detail: s.current_label ?? 'Periodo actual' },
      { label: 'Pendientes en catálogo', value: String(s.pending), detail: s.pending ? 'Sin cursar' : 'Nada pendiente' },
      { label: 'Avance de créditos', value: `${s.percent} %`, detail: `${s.credits_approved} de ${s.credits_total} cr.` },
      { label: 'Semestre actual', value: String(s.current_index), detail: `Plan: ${s.planned_semesters} · límite ${s.max_semesters}` },
      { label: 'Terminas en', value: `Sem. ${s.last_index}`, detail: s.fits_limit ? 'Dentro del límite' : 'Excede el límite', tone: s.fits_limit ? 'ok' : 'bad' },
    ];
    return (
      <div className="planner-summary">
        {cards.map((card) => (
          <div className={`panel summary-card${card.tone ? ` is-${card.tone}` : ''}`} key={card.label}>
            <span>{card.label}</span>
            <strong>{card.value}</strong>
            <small>{card.detail}</small>
          </div>
        ))}
      </div>
    );
  }

  function Comparison({ result: r }: { result: PlannerResult }) {
    const c = r.comparison;
    if (!c) return null;
    const verdict = c.delta === 0 ? 'Sin cambio en tu fecha de egreso' : c.delta > 0 ? `+${c.delta} semestre(s) más` : `${c.delta} semestre(s) menos`;
    return (
      <div className="panel compare-card">
        <div><span>Sin cambios</span><strong>Sem. {c.base.last_index}</strong><small>{c.base.fits ? 'Dentro del límite' : 'Excede el límite'}</small></div>
        <div className="compare-arrow" aria-hidden="true">→</div>
        <div><span>Con tus cambios</span><strong>Sem. {c.scenario.last_index}</strong><small>{c.scenario.fits ? 'Dentro del límite' : 'Excede el límite'}</small></div>
        <div className={`compare-delta ${c.delta > 0 ? 'is-bad' : c.delta < 0 ? 'is-good' : ''}`}>{verdict}</div>
      </div>
    );
  }

  function Periods({ result: r }: { result: PlannerResult }) {
    if (!r.periods.length) {
      return <div className="panel"><p className="muted">No quedan materias del catálogo por cursar.</p></div>;
    }
    return (
      <div className="period-grid">
        {r.periods.map((p) => (
          <article key={p.id} className={`panel period-card kind-${p.kind}${p.beyond_limit ? ' is-beyond' : ''}`}>
            <header className="panel-heading">
              <div>
                <p className="eyebrow">{p.kind === 'summer' ? 'VERANO' : p.kind === 'leave' ? 'BAJA TEMPORAL' : `SEMESTRE ${p.index}`}</p>
                <h3>{p.calendar ?? p.label}</h3>
              </div>
              {p.kind !== 'leave' ? <span className="status-pill">{p.total_credits}{p.max_credits ? ` / ${p.max_credits}` : ''} cr.</span> : null}
            </header>
            {p.note ? <p className="muted">{p.note}</p> : null}
            {p.beyond_limit ? <p className="planner-warning is-danger">Este periodo excede el máximo de {r.summary.max_semesters} semestres.</p> : null}
            <ul className="planned-subjects">
              {p.subjects.map((s) => (
                <li key={s.code}><span><strong>{s.name}</strong><small>{s.code} · Dificultad {s.difficulty}/5</small></span><b>{s.credits} cr.</b></li>
              ))}
            </ul>
          </article>
        ))}
      </div>
    );
  }

  function Milestones({ result: r }: { result: PlannerResult }) {
    return (
      <div className="milestone-grid">
        {r.milestones.map((m) => (
          <div className={`panel milestone-card is-${m.status}`} key={m.code}>
            <span className="eyebrow">{MILESTONE_TEXT[m.status]}</span>
            <strong>{m.name}</strong>
            {m.earliest_label ? <small>Desde {m.earliest_label} (semestre {m.earliest_index})</small> : null}
            <p className="muted">{m.detail}</p>
          </div>
        ))}
      </div>
    );
  }

  function Warnings({ result: r }: { result: PlannerResult }) {
    const icon = { danger: '⛔', warning: '⚠', info: 'ℹ' } as const;
    return (
      <ul className="warning-list">
        {r.warnings.map((w, i) => (
          <li key={i} className={`planner-warning is-${w.severity}`}>
            <span aria-hidden="true">{icon[w.severity]}</span> {w.message}
            {w.subjects.length ? <small>{w.subjects.join(', ')}</small> : null}
          </li>
        ))}
      </ul>
    );
  }
}