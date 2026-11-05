'use client';

export default function StudentError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return <section className="panel error-state" role="alert">
    <p className="eyebrow">CONSULTA NO DISPONIBLE</p>
    <h1>No pudimos cargar esta sección</h1>
    <p className="muted">El servidor de aplicación o el SII no respondió correctamente. Tu contraseña no se almacena y puedes volver a intentar la consulta.</p>
    <button className="button button-primary" onClick={() => reset()}>Intentar nuevamente</button>
  </section>;
}
