import { adminRequest } from '@/lib/session';
import { logoutAdmin, createSubject, updateSubject, deleteSubject, importSubjectsCsv } from '@/app/actions';

export const dynamic = 'force-dynamic';

type Subject = {
  id: number;
  code: string;
  name: string;
  credits: number;
  suggested_semester: number;
  difficulty: number;
  active: boolean;
  prerequisites?: Array<{ id: number; code: string; name: string }>;
};

type Admin = { name: string; email: string; role: string };

export default async function AdminPage({ searchParams }: { searchParams: Promise<{ status?: string; error?: string }> }) {
  const params = await searchParams;
  const [adminResponse, subjectResponse] = await Promise.all([
    adminRequest<{ data: Admin }>('me'),
    adminRequest<{ data: Subject[] }>('subjects'),
  ]);
  const admin = adminResponse.data;
  const subjects = subjectResponse.data;

  return <main className="admin-page">
    <header className="admin-topbar">
      <a href="/admin" className="sidebar-brand"><span className="brand-mark brand-mark-small brand-mark-admin">ADM</span><span><strong>Panel de administración</strong><small>Catálogo académico</small></span></a>
      <div className="admin-user"><span>{admin.name} · {admin.role}</span><form action={logoutAdmin}><button className="button button-ghost" type="submit">Cerrar sesión</button></form></div>
    </header>
    <div className="admin-content">
      <div className="page-heading"><div><p className="eyebrow">CONFIGURACIÓN</p><h1>Catálogo de materias</h1><p className="muted">Administra las materias y sus prerrequisitos para la planeación académica.</p></div><div className="heading-stat"><strong>{subjects.length}</strong><span>Materias registradas</span></div></div>
      {params.status ? <div className="alert alert-success">{params.status === 'created' ? 'Materia creada.' : params.status === 'updated' ? 'Materia actualizada.' : params.status === 'deleted' ? 'Materia eliminada.' : 'CSV importado.'}</div> : null}
      {params.error ? <div className="alert alert-error">{params.error}</div> : null}

      <section className="admin-grid">
        <article className="panel admin-form-panel">
          <p className="eyebrow">NUEVO REGISTRO</p><h2>Agregar materia</h2>
          <form action={createSubject} className="form-stack">
            <label htmlFor="code">Clave de materia</label><input id="code" name="code" placeholder="Ej. ACF-0901" required maxLength={30} />
            <label htmlFor="name">Nombre</label><input id="name" name="name" placeholder="Nombre oficial de la asignatura" required maxLength={180} />
            <div className="form-two"><div><label htmlFor="credits">Créditos</label><input id="credits" name="credits" type="number" min="1" max="20" defaultValue="5" required /></div><div><label htmlFor="suggested_semester">Semestre sugerido</label><input id="suggested_semester" name="suggested_semester" type="number" min="1" max="20" defaultValue="1" required /></div></div>
            <label htmlFor="difficulty">Dificultad (1–5)</label><input id="difficulty" name="difficulty" type="number" min="1" max="5" defaultValue="3" required />
            <label htmlFor="prerequisite_codes">Claves de prerrequisitos</label><input id="prerequisite_codes" name="prerequisite_codes" placeholder="ACF-0901, ACF-0902" />
            <small className="field-hint">Separar por comas. Las materias requeridas deben existir previamente en el catálogo.</small>
            <button className="button button-primary" type="submit">Guardar materia</button>
          </form>
        </article>

        <article className="panel admin-form-panel">
          <p className="eyebrow">CARGA MASIVA</p><h2>Importar catálogo CSV</h2>
          <p className="muted">Encabezados: <code>code,name,credits,suggested_semester,difficulty</code></p>
          <form action={importSubjectsCsv} className="form-stack">
            <label htmlFor="csv">Contenido CSV</label>
            <textarea id="csv" name="csv" rows={10} placeholder={'code,name,credits,suggested_semester,difficulty\nACF-0901,Cálculo Diferencial,5,1,3'} required />
            <button className="button button-secondary" type="submit">Importar registros</button>
          </form>
          <p className="field-hint">La importación actualiza materias por clave. Los prerrequisitos se agregan desde el formulario individual.</p>
        </article>
      </section>

      <section className="panel table-panel admin-table-panel">
        <div className="panel-heading"><div><h2>Materias configuradas</h2><p className="muted">Datos propios del proyecto, no descargados del SII.</p></div></div>
        <div className="table-wrap"><table className="data-table"><thead><tr><th>Clave</th><th>Materia</th><th>Créditos</th><th>Semestre</th><th>Dificultad</th><th>Prerrequisitos</th><th>Estado</th><th></th></tr></thead>
          <tbody>{subjects.map((subject) => <tr key={subject.id}>
            <td><span className="code-text">{subject.code}</span></td><td><strong>{subject.name}</strong></td><td>{subject.credits}</td><td>{subject.suggested_semester}</td><td>{subject.difficulty}/5</td><td>{subject.prerequisites?.map((item) => item.code).join(', ') || '—'}</td><td>{subject.active ? 'Activa' : 'Inactiva'}</td>
            <td><details className="row-actions"><summary>Editar</summary><form action={updateSubject} className="row-edit-form"><input type="hidden" name="id" value={subject.id} /><label>Clave<input name="code" defaultValue={subject.code} required maxLength={30} /></label><label>Nombre<input name="name" defaultValue={subject.name} required maxLength={180} /></label><div className="form-two"><label>Créditos<input name="credits" type="number" min="1" max="20" defaultValue={subject.credits} required /></label><label>Semestre<input name="suggested_semester" type="number" min="1" max="20" defaultValue={subject.suggested_semester} required /></label></div><label>Dificultad<input name="difficulty" type="number" min="1" max="5" defaultValue={subject.difficulty} required /></label><label>Prerrequisitos<input name="prerequisite_codes" defaultValue={subject.prerequisites?.map((item) => item.code).join(', ') ?? ''} /></label><label className="check-label"><input type="checkbox" name="active" defaultChecked={subject.active} /> Materia activa</label><button className="button button-secondary button-small" type="submit">Guardar cambios</button></form></details><form action={deleteSubject}><input type="hidden" name="id" value={subject.id} /><button className="button button-danger button-small" type="submit">Eliminar</button></form></td>
          </tr>)}{subjects.length === 0 ? <tr><td colSpan={8} className="empty-state">El catálogo está vacío. Carga materias oficiales antes de usar el asistente.</td></tr> : null}</tbody>
        </table></div>
      </section>
      <p className="data-note">Solo los administradores autorizados pueden modificar el catálogo. La autorización y validación se aplican en Laravel; ocultar controles en React no sería suficiente.</p>
    </div>
  </main>;
}
