import { studentRequest } from '@/lib/session';

type DayKey = 'lunes' | 'martes' | 'miercoles' | 'jueves' | 'viernes' | 'sabado';
type ScheduleSubject = {
  nombre_materia: string;
  clave_materia: string;
  letra_grupo: string;
} & Partial<Record<DayKey, string | null>> & Partial<Record<`${DayKey}_clave_salon`, string | null>>;

type SchedulePeriod = {
  periodo?: { descripcion_periodo?: string; clave_periodo?: string };
  horario?: ScheduleSubject[];
};

const days: Array<{ key: DayKey; label: string }> = [
  { key: 'lunes', label: 'Lunes' },
  { key: 'martes', label: 'Martes' },
  { key: 'miercoles', label: 'Miércoles' },
  { key: 'jueves', label: 'Jueves' },
  { key: 'viernes', label: 'Viernes' },
  { key: 'sabado', label: 'Sábado' },
];

export default async function SchedulePage() {
  const response = await studentRequest<{ data: SchedulePeriod[] }>('schedule');
  const period = response.data?.[0];
  const entries = (period?.horario ?? []).flatMap((subject) => days.flatMap((day) => {
    const time = subject[day.key];
    if (!time) return [];
    const room = subject[`${day.key}_clave_salon`] ?? 'Salón no especificado';
    return [{ day: day.label, time, room, subject: subject.nombre_materia, code: subject.clave_materia, group: subject.letra_grupo }];
  })).sort((a, b) => days.findIndex((day) => day.label === a.day) - days.findIndex((day) => day.label === b.day) || a.time.localeCompare(b.time));

  return <>
    <div className="page-heading"><div><p className="eyebrow">ORGANIZACIÓN</p><h1>Horario</h1><p className="muted">Tus clases registradas para el periodo actual.</p></div><div className="heading-stat"><strong>{entries.length}</strong><span>Bloques semanales</span></div></div>
    <section className="schedule-grid">
      {days.map((day) => {
        const dayEntries = entries.filter((entry) => entry.day === day.label);
        return <article className="panel day-panel" key={day.key}>
          <div className="day-heading"><h2>{day.label}</h2><span>{dayEntries.length} clases</span></div>
          {dayEntries.length ? dayEntries.map((entry, index) => <div className="class-card" key={`${entry.code}-${index}`}>
            <strong className="class-time">{entry.time}</strong>
            <span className="class-subject">{entry.subject}</span>
            <span className="class-meta">{entry.code} · Grupo {entry.group}</span>
            <span className="class-room">Salón {entry.room}</span>
          </div>) : <p className="day-empty">Sin clases registradas</p>}
        </article>;
      })}
    </section>
    <p className="data-note">{period?.periodo?.descripcion_periodo ?? period?.periodo?.clave_periodo ?? 'Periodo no identificado'}. Los salones y bloques se presentan tal como los entrega el SII.</p>
  </>;
}
