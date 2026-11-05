import 'server-only';
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { BackendError, callLaravel } from '@/lib/api';

function cookieName(kind: 'student' | 'admin'): string {
  const prefix = process.env.SESSION_COOKIE_SECURE === 'true' ? '__Host-' : '';
  return `${prefix}sii_${kind}_session`;
}

export async function readStudentToken(): Promise<string | undefined> {
  return (await cookies()).get(cookieName('student'))?.value;
}

export async function readAdminToken(): Promise<string | undefined> {
  return (await cookies()).get(cookieName('admin'))?.value;
}

export async function saveStudentToken(token: string, expiresAt: string): Promise<void> {
  const expiration = Date.parse(expiresAt);
  const maxAge = Math.min(8 * 60 * 60, Math.floor((expiration - Date.now()) / 1000));

  if (!Number.isFinite(expiration) || maxAge <= 0 || token.length < 48) {
    throw new Error('El servidor devolvió una sesión institucional inválida o vencida.');
  }

  const secure = process.env.SESSION_COOKIE_SECURE === 'true';
  (await cookies()).set(cookieName('student'), token, {
    httpOnly: true,
    secure,
    sameSite: 'lax',
    path: '/',
    maxAge,
    expires: new Date(expiration),
  });
}

export async function saveAdminToken(token: string): Promise<void> {
  const secure = process.env.SESSION_COOKIE_SECURE === 'true';
  (await cookies()).set(cookieName('admin'), token, {
    httpOnly: true,
    secure,
    sameSite: 'lax',
    path: '/',
    maxAge: 4 * 60 * 60,
  });
}

export async function clearStudentToken(): Promise<void> {
  (await cookies()).delete(cookieName('student'));
}

export async function clearAdminToken(): Promise<void> {
  (await cookies()).delete(cookieName('admin'));
}

export async function studentRequest<T>(path: string, options: { method?: 'GET' | 'POST'; body?: unknown } = {}): Promise<T> {
  const token = await readStudentToken();
  if (!token) redirect('/login');

  try {
    return await callLaravel<T>(`student/${path}`, { ...options, session: token });
  } catch (error) {
    if (error instanceof BackendError && (error.status === 401 || error.status === 403)) {
      await clearStudentToken();
      redirect('/login?error=session');
    }
    throw error;
  }
}

export async function adminRequest<T>(path: string, options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown } = {}): Promise<T> {
  const token = await readAdminToken();
  if (!token) redirect('/admin/login');

  try {
    return await callLaravel<T>(`admin/${path}`, { ...options, session: token });
  } catch (error) {
    if (error instanceof BackendError && (error.status === 401 || error.status === 403)) {
      await clearAdminToken();
      redirect('/admin/login?error=session');
    }
    throw error;
  }
}
