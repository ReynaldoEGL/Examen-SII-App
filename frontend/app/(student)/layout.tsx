import type { ReactNode } from 'react';
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { logoutStudent } from '@/app/actions';

export const dynamic = 'force-dynamic';

const links = [
  { href: '/dashboard', label: 'Resumen', icon: '▦' },
  { href: '/grades', label: 'Calificaciones', icon: '▤' },
  { href: '/kardex', label: 'Kardex', icon: '◷' },
  { href: '/schedule', label: 'Horario', icon: '▦' },
  { href: '/planner', label: 'Planeación', icon: '⌁' },
];

export default async function StudentLayout({ children }: Readonly<{ children: ReactNode }>) {
  const jar = await cookies();
  const secure = process.env.SESSION_COOKIE_SECURE === 'true';
  const cookieName = `${secure ? '__Host-' : ''}sii_student_session`;
  if (!jar.has(cookieName)) redirect('/login');

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="sidebar-brand" href="/dashboard">
          <span className="brand-mark brand-mark-small">ITC</span>
          <span><strong>SII Académico</strong><small>Instituto Tecnológico de Celaya</small></span>
        </a>
        <p className="nav-caption">CONSULTA</p>
        <nav className="side-nav" aria-label="Navegación principal">
          {links.map((link) => (
            <a href={link.href} key={link.href}><span className="nav-icon">{link.icon}</span>{link.label}</a>
          ))}
        </nav>
        <div className="sidebar-bottom">
          
          <form action={logoutStudent}><button className="button button-ghost button-full" type="submit">Cerrar sesión <span aria-hidden="true">↗</span></button></form>
        </div>
      </aside>
      <main className="main-area">
        <header className="topbar">
          
          
        </header>
        <div className="page-content">{children}</div>
      </main>
    </div>
  );
}
