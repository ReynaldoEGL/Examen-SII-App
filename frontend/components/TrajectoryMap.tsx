'use client';

import '@xyflow/react/dist/style.css';
import { useMemo, useState } from 'react';
import { Background, Controls, Handle, MarkerType, Panel, Position, ReactFlow } from '@xyflow/react';
import type { Edge, Node, NodeProps } from '@xyflow/react';
import PlannerHelp from '@/components/PlannerHelp';
import type { PlannerPeriod, PlannerResult, PlannerSubject, PlannerWarning } from '@/lib/planner-types';

type View = 'grid' | 'path';
type Badge = { text: string; kind: string };
type SubjectData = { subject: PlannerSubject; tone: string; footer: string; badges: Badge[]; dim: boolean; active: boolean };
type HeaderData = { title: string; subtitle: string; ratio: number | null; tone: string };
type SubjectFlowNode = Node<SubjectData, 'subject'>;
type HeaderFlowNode = Node<HeaderData, 'header'>;
type Placed = { code: string; col: number; row: number };

const COL_W = 224;
const ROW_H = 92;
const TOP = 84;

function tone(s: PlannerSubject, firstPeriodId: string | null): string {
  if (s.status === 'approved') return 'approved';
  if (s.status === 'in_progress') return 'progress';
  if (s.status === 'unassigned' || s.status === 'withdrawn' || s.beyond_limit) return 'blocked';
  if (s.deferred) return 'deferred';
  if (s.planned_kind === 'summer') return 'summer';
  return s.period_id === firstPeriodId ? 'next' : 'later';
}

function footer(s: PlannerSubject, periods: Map<string, PlannerPeriod>): string {
  if (s.status === 'approved') return s.approved_via === 'repetition' ? 'Aprobada · repetición' : s.approved_via === 'special' ? 'Aprobada · especial' : 'Aprobada';
  if (s.status === 'in_progress') return 'Cursando';
  if (s.status === 'withdrawn') return 'Baja definitiva';
  if (s.status === 'unassigned') return 'Sin ubicar';
  const p = s.period_id ? periods.get(s.period_id) : undefined;
  const when = p?.calendar ?? p?.label ?? 'Planeada';
  if (s.deferred) return `Pospuesta · ${when}`;
  return p?.kind === 'summer' ? `Verano · ${when}` : when;
}

function badges(s: PlannerSubject, warn: boolean): Badge[] {
  const out: Badge[] = [];
  if (s.specialty) out.push({ text: '★', kind: 'star' });
  if (s.opportunity === 'repetition' || s.approved_via === 'repetition') out.push({ text: 'R', kind: 'rep' });
  if (s.opportunity === 'special' || s.approved_via === 'special') out.push({ text: 'E', kind: 'esp' });
  if (s.forced_fail) out.push({ text: '✕', kind: 'fail' });
  if (s.status === 'planned' && s.delay > 0) out.push({ text: `+${s.delay}`, kind: 'delay' });
  if (warn) out.push({ text: '⚠', kind: 'warn' });
  return out;
}

function layout(view: View, result: PlannerResult): { headers: HeaderData[]; placed: Placed[] } {
  const subjects = result.subjects;
  const headers: HeaderData[] = [];
  const placed: Placed[] = [];

  if (view === 'grid') {
    const maxSemester = Math.max(...subjects.map((s) => s.semester), 1);
    for (let semester = 1; semester <= maxSemester; semester += 1) {
      const list = subjects.filter((s) => s.semester === semester);
      const approved = list.filter((s) => s.status === 'approved').length;
      headers.push({ title: `${semester}.º semestre`, subtitle: `${approved}/${list.length} aprobadas`, ratio: list.length ? approved / list.length : 0, tone: 'approved' });
      list.forEach((s, row) => placed.push({ code: s.code, col: semester - 1, row }));
    }
    return { headers, placed };
  }

  let col = 0;
  headers.push({ title: 'Cursando', subtitle: result.summary.current_label ?? 'Periodo actual', ratio: null, tone: 'progress' });
  subjects.filter((s) => s.status === 'in_progress').forEach((s, row) => placed.push({ code: s.code, col, row }));
  col += 1;

  for (const period of result.periods) {
    const cap = period.max_credits;
    headers.push({
      title: period.kind === 'summer' ? 'Verano' : period.kind === 'leave' ? 'Baja temporal' : `Semestre ${period.index}`,
      subtitle: period.kind === 'leave' ? (period.calendar ?? 'Sin materias') : `${period.calendar ?? ''} · ${period.total_credits}${cap ? `/${cap}` : ''} cr.`,
      ratio: cap ? period.total_credits / cap : null,
      tone: period.beyond_limit ? 'blocked' : period.kind === 'summer' ? 'summer' : period.kind === 'leave' ? 'leave' : 'later',
    });
    subjects.filter((s) => s.period_id === period.id).forEach((s, row) => placed.push({ code: s.code, col, row }));
    col += 1;
  }

  const loose = subjects.filter((s) => s.status === 'unassigned' || s.status === 'withdrawn');
  if (loose.length) {
    headers.push({ title: 'Sin ubicar', subtitle: `${loose.length} materia(s)`, ratio: null, tone: 'blocked' });
    loose.forEach((s, row) => placed.push({ code: s.code, col, row }));
  }
  return { headers, placed };
}

function SubjectCard({ data }: NodeProps<SubjectFlowNode>) {
  const s = data.subject;
  return (
    <div className={`map-node tone-${data.tone}${data.dim ? ' is-dim' : ''}${data.active ? ' is-active' : ''}`} title={`${s.name} (${s.code})`}>
      <Handle id="tl" type="target" position={Position.Left} isConnectable={false} />
      <Handle id="sr" type="source" position={Position.Right} isConnectable={false} />
      <Handle id="tt" type="target" position={Position.Top} isConnectable={false} />
      <Handle id="st" type="source" position={Position.Top} isConnectable={false} />
      <Handle id="sb" type="source" position={Position.Bottom} isConnectable={false} />
      <Handle id="tb" type="target" position={Position.Bottom} isConnectable={false} />
      <div className="map-node-top">
        <span className="map-node-code">{s.code}</span>
        <span className="map-node-badges">{data.badges.map((b) => <i key={b.kind} className={`badge badge-${b.kind}`}>{b.text}</i>)}</span>
      </div>
      <div className="map-node-name">{s.name}</div>
      <div className="map-node-foot"><span>{s.credits} cr · D{s.difficulty}</span><span>{data.footer}</span></div>
    </div>
  );
}

function ColumnHeader({ data }: NodeProps<HeaderFlowNode>) {
  return (
    <div className={`map-col tone-${data.tone}`}>
      <strong>{data.title}</strong>
      <span>{data.subtitle}</span>
      {data.ratio !== null ? <div className="map-col-bar"><i style={{ width: `${Math.min(100, data.ratio * 100)}%` }} /></div> : null}
    </div>
  );
}

const nodeTypes = { subject: SubjectCard, header: ColumnHeader };

const STATUS_TEXT: Record<PlannerSubject['status'], string> = {
  approved: 'Aprobada',
  in_progress: 'Cursando (se asume aprobada)',
  planned: 'Planeada',
  unassigned: 'Sin ubicar',
  withdrawn: 'Baja definitiva',
};

export default function TrajectoryMap({ result }: { result: PlannerResult }) {
  const [view, setView] = useState<View>('grid');
  const [selected, setSelected] = useState<string | null>(null);
  const [hover, setHover] = useState<string | null>(null);

  const periods = useMemo(() => new Map(result.periods.map((p) => [p.id, p])), [result.periods]);
  const bySubject = useMemo(() => new Map(result.subjects.map((s) => [s.code, s])), [result.subjects]);
  const firstPeriodId = useMemo(() => result.periods.find((p) => p.kind === 'ordinary' && p.total_credits > 0)?.id ?? null, [result.periods]);

  const adjacency = useMemo(() => {
    const up = new Map<string, string[]>();
    const down = new Map<string, string[]>();
    result.relations.forEach((r) => {
      up.set(r.target, [...(up.get(r.target) ?? []), r.source]);
      down.set(r.source, [...(down.get(r.source) ?? []), r.target]);
    });
    return { up, down };
  }, [result.relations]);

  const warningsByCode = useMemo(() => {
    const map = new Map<string, PlannerWarning[]>();
    result.warnings.forEach((w) => {
      if (w.severity === 'info') return;
      w.subjects.forEach((code) => map.set(code, [...(map.get(code) ?? []), w]));
    });
    return map;
  }, [result.warnings]);

  const active = hover ?? selected;
  const chain = useMemo(() => {
    if (!active) return null;
    const seen = new Set<string>([active]);
    for (const direction of [adjacency.up, adjacency.down]) {
      const queue = [active];
      while (queue.length) {
        const current = queue.shift() as string;
        for (const next of direction.get(current) ?? []) {
          if (!seen.has(next)) {
            seen.add(next);
            queue.push(next);
          }
        }
      }
    }
    return seen;
  }, [active, adjacency]);

  const { nodes, edges } = useMemo(() => {
    const { headers, placed } = layout(view, result);
    const position = new Map(placed.map((p) => [p.code, { col: p.col, y: TOP + p.row * ROW_H }]));

    const nodes: Array<SubjectFlowNode | HeaderFlowNode> = headers.map((h, i) => ({
      id: `col-${i}`, type: 'header' as const, position: { x: i * COL_W, y: 0 }, data: h,
      draggable: false, selectable: false, focusable: false,
    }));

    for (const p of placed) {
      const subject = bySubject.get(p.code);
      if (!subject) continue;
      const warn = (warningsByCode.get(p.code) ?? []).length > 0;
      nodes.push({
        id: p.code, type: 'subject' as const, position: { x: p.col * COL_W, y: TOP + p.row * ROW_H }, draggable: false,
        data: {
          subject, tone: tone(subject, firstPeriodId), footer: footer(subject, periods), badges: badges(subject, warn),
          dim: chain !== null && !chain.has(p.code), active: active === p.code,
        },
      });
    }

    const edges: Edge[] = result.relations
      .filter((r) => position.has(r.source) && position.has(r.target))
      .map((r) => {
        const a = position.get(r.source)!;
        const b = position.get(r.target)!;
        const sameColumn = a.col === b.col;
        const downward = a.y <= b.y;
        const inChain = chain !== null && chain.has(r.source) && chain.has(r.target);
        const dim = chain !== null && !inChain;
        const co = r.type === 'corequisite';
        const color = inChain ? (co ? '#765ac8' : '#1769aa') : co ? '#9a8bd6' : '#8396aa';
        return {
          id: `${r.source}>${r.target}`,
          source: r.source,
          target: r.target,
          sourceHandle: sameColumn ? (downward ? 'sb' : 'st') : 'sr',
          targetHandle: sameColumn ? (downward ? 'tt' : 'tb') : 'tl',
          type: 'smoothstep',
          style: { stroke: color, strokeWidth: inChain ? 2.6 : 1.4, strokeDasharray: co ? '6 4' : undefined, opacity: dim ? 0.1 : 0.9 },
          markerEnd: co ? undefined : { type: MarkerType.ArrowClosed, color, width: 16, height: 16 },
          zIndex: inChain ? 10 : 0,
        };
      });

    return { nodes, edges };
  }, [view, result, bySubject, warningsByCode, firstPeriodId, periods, chain, active]);

  const selectedSubject = selected ? bySubject.get(selected) : undefined;
  const requires = selectedSubject ? result.relations.filter((r) => r.target === selectedSubject.code) : [];
  const unlocks = selectedSubject ? result.relations.filter((r) => r.source === selectedSubject.code) : [];
  const selectedPeriod = selectedSubject?.period_id ? periods.get(selectedSubject.period_id) : undefined;
  const count = (severity: PlannerWarning['severity']) => result.warnings.filter((w) => w.severity === severity).length;

  return (
    <section className="panel map-panel">
      <div className="map-toolbar">
        <div>
          <p className="eyebrow">MAPA DE TRAYECTORIA</p>
          <h2>{view === 'grid' ? 'Retícula completa' : 'Mi trayectoria proyectada'}</h2>
        </div>
        <div className="map-toolbar-actions">
          <div className="segmented" role="group" aria-label="Vista del mapa">
            <button type="button" className={view === 'grid' ? 'is-on' : ''} aria-pressed={view === 'grid'} onClick={() => { setView('grid'); setSelected(null); }}>Retícula</button>
            <button type="button" className={view === 'path' ? 'is-on' : ''} aria-pressed={view === 'path'} onClick={() => { setView('path'); setSelected(null); }}>Mi trayectoria</button>
          </div>
          <PlannerHelp />
        </div>
      </div>

      <ul className="map-legend" aria-label="Colores del mapa">
        {[['approved', 'Aprobada'], ['progress', 'Cursando'], ['next', 'Próximo periodo'], ['later', 'Más adelante'], ['summer', 'Verano'], ['deferred', 'Pospuesta'], ['blocked', 'Bloqueada o fuera de límite']].map(([key, label]) => (
          <li key={key}><i className={`swatch tone-${key}`} />{label}</li>
        ))}
      </ul>

      <div className="map-canvas">
        <ReactFlow
          key={`${view}-${result.periods.map((p) => p.id).join('.')}`}
          nodes={nodes}
          edges={edges}
          nodeTypes={nodeTypes}
          fitView
          fitViewOptions={{ padding: 0.1, minZoom: 0.55, maxZoom: 1 }}
          minZoom={0.2}
          maxZoom={1.6}
          nodesDraggable={false}
          nodesConnectable={false}
          zoomOnScroll={false}
          preventScrolling={false}
          onNodeClick={(_, node) => { if (node.type === 'subject') setSelected((prev) => (prev === node.id ? null : node.id)); }}
          onNodeMouseEnter={(_, node) => { if (node.type === 'subject') setHover(node.id); }}
          onNodeMouseLeave={() => setHover(null)}
          onPaneClick={() => setSelected(null)}
        >
          <Background gap={24} size={1} />
          <Controls showInteractive={false} />
          <Panel position="top-left" className="map-alerts">
            {count('danger') ? <span className="alert-chip is-danger">⛔ {count('danger')} crítica(s)</span> : null}
            {count('warning') ? <span className="alert-chip is-warning">⚠ {count('warning')} aviso(s)</span> : null}
            {!count('danger') && !count('warning') ? <span className="alert-chip is-ok">✓ Sin advertencias</span> : null}
          </Panel>
        </ReactFlow>

        {selectedSubject ? (
          <aside className="map-detail" aria-live="polite">
            <button type="button" className="map-detail-close" aria-label="Cerrar detalle" onClick={() => setSelected(null)}>×</button>
            <p className="eyebrow">{selectedSubject.code}</p>
            <h3>{selectedSubject.name}</h3>
            <dl>
              <div><dt>Estado</dt><dd>{STATUS_TEXT[selectedSubject.status]}</dd></div>
              <div><dt>Créditos · dificultad</dt><dd>{selectedSubject.credits} cr · {selectedSubject.difficulty}/5</dd></div>
              <div><dt>Semestre original</dt><dd>{selectedSubject.semester}</dd></div>
              {selectedPeriod ? <div><dt>Se cursa en</dt><dd>{selectedPeriod.calendar ?? selectedPeriod.label}{selectedSubject.delay ? ` · ${selectedSubject.delay} periodo(s) de retraso` : ''}</dd></div> : null}
              {selectedSubject.opportunity === 'repetition' || selectedSubject.opportunity === 'special' ? <div><dt>Oportunidad</dt><dd>{selectedSubject.opportunity === 'repetition' ? 'Repetición' : 'Curso especial'}</dd></div> : null}
              {selectedSubject.min_percent ? <div><dt>Requiere</dt><dd>{selectedSubject.min_percent} % de créditos</dd></div> : null}
            </dl>
            {selectedSubject.reason ? <p className="planner-warning is-danger">{selectedSubject.reason}</p> : null}
            {(warningsByCode.get(selectedSubject.code) ?? []).map((w, i) => <p key={i} className={`planner-warning is-${w.severity}`}>{w.message}</p>)}
            {requires.length ? <><h4>Requiere</h4><ul>{requires.map((r) => <li key={r.source}>{bySubject.get(r.source)?.name ?? r.source}{r.type === 'corequisite' ? ' (correquisito: acreditada o en el mismo periodo)' : ''}</li>)}</ul></> : null}
            {unlocks.length ? <><h4>Desbloquea</h4><ul>{unlocks.map((r) => <li key={r.target}>{bySubject.get(r.target)?.name ?? r.target}</li>)}</ul></> : null}
          </aside>
        ) : null}
      </div>
      <p className="data-note">Pasa el cursor sobre una materia para resaltar su cadena de requisitos. Haz clic para ver el detalle.</p>
    </section>
  );
}