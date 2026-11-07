import { studentRequest } from '@/lib/session';
import { BackendError } from '@/lib/api';
import { redirect } from 'next/navigation';

type Profile = {
  numero_control: string;
  persona: string;
  email: string;
  semestre: number | string;
  creditos_acumulados: number | string;
  promedio_ponderado: number | string;
  promedio_aritmetico: number | string;
  materias_cursadas: number | string;
  materias_aprobadas: number | string;
  materias_reprobadas: number | string;
  porcentaje_avance: number | string;
  creditos_complementarios: number | string;
};

function numeric(value: number | string | null | undefined): number {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function metric(value: number | string | null | undefined, digits = 0): string {
  return numeric(value).toLocaleString('es-MX', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

export default async function DashboardPage() {
  let profile: Profile;
  try {
    const response = await studentRequest<{ data: Profile }>('profile');
    profile = response.data;
  } catch (error) {
    if (error instanceof BackendError && (error.status === 401 || error.status === 403)) redirect('/login?error=session');
    throw error;
  }

  const progress = Math.max(0, Math.min(100, numeric(profile.porcentaje_avance)));
  const name = (profile.persona || 'Estudiante').trim();

  return (
    <>
      <section className="welcome-row">
        <div>
          <p className="eyebrow">PANEL DEL ESTUDIANTE</p>
          <h1>Hola, {name.split(' ')[0].toLocaleLowerCase('es-MX').replace(/^\p{L}/u, (letter) => letter.toLocaleUpperCase('es-MX'))}</h1>
          <p className="muted">Información académica.</p>
        </div>
        <div className="student-ident"><span className="avatar">{initials(name)}</span><span><strong>{name}</strong><small>{profile.numero_control}</small></span></div>
      </section>

      <section className="metric-grid" aria-label="Indicadores académicos">
        
        <Metric label="Créditos acumulados" value={metric(profile.creditos_acumulados)} detail="Créditos registrados" accent="violet" />
        <Metric label="Promedio ponderado" value={metric(profile.promedio_ponderado, 2)} detail="Indicador del SII" accent="green" />
        <Metric label="Semestre" value={metric(profile.semestre)} detail="Semestre reportado" accent="amber" />
      </section>

      <section className="content-grid">
        <article className="panel progress-panel">
          <div className="panel-heading"><div><p className="eyebrow">PROGRESO</p><h2>Avance académico</h2></div><span className="status-pill">En curso</span></div>
          <div className="progress-value"><strong>{metric(profile.porcentaje_avance)}%</strong><span>de avance reportado por el perfil</span></div>
          <div className="progress-track"><span style={{ width: `${progress}%` }} /></div>
          <div className="progress-foot"><span>0%</span><span>100%</span></div>
          <div className="mini-stats">
            <div><strong>{metric(profile.materias_aprobadas)}</strong><span>Materias aprobadas</span></div>
            <div><strong>{metric(profile.materias_cursadas)}</strong><span>Materias cursadas</span></div>
            <div><strong>{metric(profile.materias_reprobadas)}</strong><span>Materias reprobadas</span></div>
          </div>
        </article>
        <article className="panel profile-panel">
          <p className="eyebrow">DATOS INSTITUCIONALES</p>
          <h2>Perfil del estudiante</h2>
          <Info label="Nombre completo" value={profile.persona} />
          <Info label="Número de control" value={profile.numero_control} />
          <Info label="Correo institucional" value={profile.email} />
          <Info label="Promedio aritmético" value={metric(profile.promedio_aritmetico, 2)} />
          <Info label="Créditos complementarios" value={metric(profile.creditos_complementarios)} last />
        </article>
      </section>
      
    </>
  );
}

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function Metric({ label, value, detail, accent }: { label: string; value: string; detail: string; accent: string }) {
  return <article className={`metric-card metric-${accent}`}><span className="metric-label">{label}</span><strong>{value}</strong><span className="metric-detail">{detail}</span></article>;
}

function Info({ label, value, last = false }: { label: string; value: string | number; last?: boolean }) {
  return <div className={`info-line ${last ? 'info-line-last' : ''}`}><span>{label}</span><strong>{value ?? '—'}</strong></div>;
}
