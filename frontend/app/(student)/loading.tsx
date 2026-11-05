export default function StudentLoading() {
  return <section className="loading-state" aria-live="polite" aria-busy="true">
    <span className="loading-spinner" />
    <strong>Consultando el SII</strong>
    <p>Estamos preparando tu información académica.</p>
  </section>;
}
