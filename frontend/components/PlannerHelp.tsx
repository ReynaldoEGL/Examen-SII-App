'use client';

import { useRef } from 'react';

const COLORS: Array<[string, string, string]> = [
  ['approved', 'Aprobada', 'Ya la acreditaste (en tu kardex).'],
  ['progress', 'Cursando', 'Está en tu horario actual. La simulación asume que la apruebas.'],
  ['next', 'Próximo periodo', 'Se recomienda cursarla en el primer periodo con materias.'],
  ['later', 'Más adelante', 'Quedó planeada en un periodo posterior.'],
  ['summer', 'Verano', 'La elegiste para verano; depende de que se abra grupo.'],
  ['deferred', 'Pospuesta', 'La aplazaste tú. Borde punteado.'],
  ['blocked', 'Bloqueada o fuera de límite', 'No se pudo ubicar, o cae después del semestre 12.'],
];

const BADGES: Array<[string, string]> = [
  ['★', 'Materia de la especialidad.'],
  ['R', 'Repetición: segunda oportunidad.'],
  ['E', 'Curso especial: tercera y última oportunidad.'],
  ['✕', 'Materia que marcaste como reprobada en el escenario.'],
  ['+N', 'Retraso de N periodos frente a su semestre original.'],
  ['⚠', 'Tiene una advertencia; ábrela para leerla.'],
];

export default function PlannerHelp() {
  const ref = useRef<HTMLDialogElement>(null);

  return (
    <>
      <button type="button" className="button button-secondary button-small" aria-haspopup="dialog" onClick={() => ref.current?.showModal()}>
        ? Ayuda y simbología
      </button>
      <dialog ref={ref} className="help-dialog" aria-labelledby="help-title" onClick={(e) => { if (e.target === ref.current) ref.current?.close(); }}>
        <div className="help-body">
          <h2 id="help-title">Cómo leer el mapa</h2>

          <h3>Colores</h3>
          <ul className="help-list">
            {COLORS.map(([tone, name, text]) => (
              <li key={tone}><i className={`swatch tone-${tone}`} /><span><strong>{name}.</strong> {text}</span></li>
            ))}
          </ul>

          <h3>Insignias</h3>
          <ul className="help-list">
            {BADGES.map(([symbol, text]) => <li key={symbol}><b className="help-badge">{symbol}</b><span>{text}</span></li>)}
          </ul>

          <h3>Flechas</h3>
          <ul className="help-list">
            <li>
              <svg width="46" height="12" aria-hidden="true"><line x1="2" y1="6" x2="34" y2="6" stroke="#8396aa" strokeWidth="2" /><polygon points="34,1 44,6 34,11" fill="#8396aa" /></svg>
              <span><strong>Prerrequisito.</strong> La materia de origen debe estar acreditada antes de cursar la de destino.</span>
            </li>
            <li>
              <svg width="46" height="12" aria-hidden="true"><line x1="2" y1="6" x2="44" y2="6" stroke="#9a8bd6" strokeWidth="2" strokeDasharray="6 4" /></svg>
              <span><strong>Correquisito.</strong> Debe estar acreditada o cursarse en el mismo periodo.</span>
            </li>
          </ul>

          <h3>Advertencias</h3>
          <ul className="help-list">
            <li><span className="alert-chip is-danger">⛔ Crítica</span><span>Pone en riesgo tu permanencia o tu egreso.</span></li>
            <li><span className="alert-chip is-warning">⚠ Aviso</span><span>Algo a revisar: carga baja, repetición, verano sin grupo asegurado.</span></li>
            <li><span className="alert-chip is-ok">ℹ Información</span><span>Supuestos de la simulación.</span></li>
          </ul>

          <h3>Reglas que usa el cálculo</h3>
          <ul className="help-rules">
            <li>Una materia se ofrece en todos los periodos iguales o posteriores al periodo en que tu trayectoria alcanza su semestre.</li>
            <li>La carga permitida depende de tu situación: regular, repetición o curso especial.</li>
            <li>El máximo es de 12 semestres. La baja temporal cuenta; el verano no.</li>
            <li>Verano: hasta 2 materias, sin especialidad, y solo si se abre grupo (15 alumnos).</li>
            <li>Algunas materias exigen un porcentaje de créditos (40, 50, 60 o 65 %). Servicio social, 70 %. Residencias, 80 %, con servicio social y actividades complementarias acreditados y sin curso especial.</li>
            <li>Se asume que apruebas todo lo que cursas. La oferta de grupos es simulada.</li>
          </ul>
          <p className="muted">Es una herramienta orientativa: no sustituye a Servicios Escolares ni a tu coordinación de carrera.</p>

          <form method="dialog"><button className="button button-primary" type="submit">Entendido</button></form>
        </div>
      </dialog>
    </>
  );
}