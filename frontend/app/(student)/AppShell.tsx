'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { usePathname } from 'next/navigation';

export default function AppShell({
  sidebar,
  children,
}: Readonly<{ sidebar: ReactNode; children: ReactNode }>) {
  const pathname = usePathname();
  const [isMobile, setIsMobile] = useState(false);
  const [collapsed, setCollapsed] = useState(false); // escritorio
  const [mobileOpen, setMobileOpen] = useState(false); // móvil

  // Detectar móvil
  useEffect(() => {
    const mq = window.matchMedia('(max-width: 760px)');
    const update = () => setIsMobile(mq.matches);
    update();
    mq.addEventListener('change', update);
    return () => mq.removeEventListener('change', update);
  }, []);

  // Recordar preferencia en escritorio (se lee después de hidratar)
  useEffect(() => {
    setCollapsed(localStorage.getItem('sidebar') === 'closed');
  }, []);

  // Cerrar el cajón al navegar
  useEffect(() => {
    setMobileOpen(false);
  }, [pathname]);

  // Cerrar con Escape
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setMobileOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  const expanded = isMobile ? mobileOpen : !collapsed;

  function toggle() {
    if (isMobile) {
      setMobileOpen((o) => !o);
    } else {
      setCollapsed((c) => {
        localStorage.setItem('sidebar', c ? 'open' : 'closed');
        return !c;
      });
    }
  }

  const cls = ['app-shell', collapsed && 'sidebar-collapsed', mobileOpen && 'sidebar-open']
    .filter(Boolean)
    .join(' ');

  return (
  <div className={cls}>
    <aside className="sidebar">{sidebar}</aside>
    <div className="sidebar-backdrop" onClick={() => setMobileOpen(false)} />

    <button
      type="button"
      className="menu-toggle menu-toggle-float"
      aria-label="Menú"
      aria-expanded={expanded}
      onClick={toggle}
    >
      <span />
      <span />
      <span />
    </button>

    <main className="main-area">
      <div className="page-content">{children}</div>
    </main>
  </div>
);
}