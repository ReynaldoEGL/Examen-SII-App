import { loginAdmin } from '@/app/actions';

const errors: Record<string, string> = {
  fields: 'Captura el correo y la contraseña administrativa.',
  credentials: 'Credenciales administrativas incorrectas.',
  unavailable: 'No fue posible conectar con el servidor de aplicación.',
  limited: 'Se alcanzó el límite de intentos. Espera un minuto antes de volver a intentarlo.',
  session: 'La sesión administrativa terminó. Inicia sesión nuevamente.',
};

export default async function AdminLoginPage({ searchParams }: { searchParams: Promise<{ error?: string }> }) {
  const params = await searchParams;
  const error = params.error ? errors[params.error] ?? params.error : '';

  return <main className="auth-page admin-auth-page"><section className="auth-card">
    <div className="brand-mark brand-mark-admin">ADM</div>
    <p className="eyebrow">GESTIÓN DEL CATÁLOGO</p>
    <h1>Administración</h1>
    <p className="muted">Acceso exclusivo para personal autorizado.</p>
    {error ? <div className="alert alert-error" role="alert">{error}</div> : null}
    <form action={loginAdmin} className="form-stack">
      <label htmlFor="email">Correo administrativo</label>
      <input id="email" name="email" type="email" autoComplete="username" required maxLength={190} />
      <label htmlFor="password">Contraseña</label>
      <input id="password" name="password" type="password" autoComplete="current-password" required maxLength={1024} />
      <button className="button button-primary" type="submit">Entrar al panel</button>
    </form>
    <a href="/login" className="subtle-link">Volver al portal académico</a>
  </section></main>;
}
