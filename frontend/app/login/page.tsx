import { loginStudent } from '@/app/actions';

const errors: Record<string, string> = {
  fields: 'Captura tu correo institucional y contraseña.',
  credentials: 'El correo o la contraseña no son correctos.',
  unavailable: 'No fue posible conectar con el SII o validar su respuesta. Intenta nuevamente más tarde.',
  limited: 'Se alcanzó el límite de intentos. Espera un minuto antes de volver a intentarlo.',
  session: 'Tu sesión terminó. Inicia sesión nuevamente.',
};

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ error?: string }> }) {
  const params = await searchParams;
  const error = params.error ? errors[params.error] ?? 'No fue posible iniciar sesión.' : '';

  return (
    <main className="auth-page">
      <section className="auth-card">
        <div className="brand-mark">ITC</div>
        <p className="eyebrow">Instituto Tecnológico de Celaya</p>
        <h1>Portal académico</h1>
        <p className="muted">Inicia sesión con las mismas credenciales que utilizas en el SII ITC.</p>
        {error ? <div className="alert alert-error" role="alert">{error}</div> : null}
        <form action={loginStudent} className="form-stack">
          <label htmlFor="email">Correo institucional</label>
          <input id="email" name="email" type="email" autoComplete="username" placeholder="usuario@celaya.tecnm.mx" required maxLength={190} />
          <label htmlFor="password">Contraseña</label>
          <input id="password" name="password" type="password" autoComplete="current-password" required maxLength={1024} />
          <button className="button button-primary" type="submit">Iniciar sesión</button>
        </form>
        <a className="subtle-link" href="/admin/login">Acceso administrativo</a>
      </section>
    </main>
  );
}
