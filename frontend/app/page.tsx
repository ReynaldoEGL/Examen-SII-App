import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';

export const dynamic = 'force-dynamic';

export default async function HomePage() {
  const jar = await cookies();
  const secure = process.env.SESSION_COOKIE_SECURE === 'true';
  const cookieName = `${secure ? '__Host-' : ''}sii_student_session`;
  redirect(jar.has(cookieName) ? '/dashboard' : '/login');
}
