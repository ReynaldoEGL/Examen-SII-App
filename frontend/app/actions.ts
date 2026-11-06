'use server';

import { BackendError, callLaravel } from '@/lib/api';
import { adminRequest, clearAdminToken, clearStudentToken, readAdminToken, readStudentToken, saveAdminToken, saveStudentToken } from '@/lib/session';
import { redirect } from 'next/navigation';
import { revalidatePath } from 'next/cache';

import type { PlannerActionState, PlannerInput, PlannerResult } from '@/lib/planner-types';

function rawValue(formData: FormData, key: string): string {
  const entry = formData.get(key);
  return typeof entry === 'string' ? entry : '';
}

function textValue(formData: FormData, key: string): string {
  return rawValue(formData, key).trim();
}

export async function loginStudent(formData: FormData): Promise<void> {
  const email = textValue(formData, 'email');
  // Nunca recortar la contraseña: los espacios pueden formar parte de ella.
  const password = rawValue(formData, 'password');
  if (!email || password.length === 0) redirect('/login?error=fields');

  let payload: { session_token: string; expires_at: string };
  try {
    payload = await callLaravel<{ session_token: string; expires_at: string }>('student/login', {
      method: 'POST',
      body: { email, password },
    });
  } catch (error) {
    const status = error instanceof BackendError ? error.status : 0;
    const code = status === 401 ? 'credentials' : status === 422 ? 'fields' : status === 429 ? 'limited' : 'unavailable';
    redirect(`/login?error=${code}`);
  }

  if (!payload?.session_token || !payload?.expires_at) redirect('/login?error=unavailable');
  try {
    await saveStudentToken(payload.session_token, payload.expires_at);
  } catch {
    redirect('/login?error=unavailable');
  }
  redirect('/dashboard');
}

export async function logoutStudent(): Promise<void> {
  const token = await readStudentToken();
  if (token) {
    try {
      await callLaravel('student/logout', { method: 'POST', session: token });
    } catch {
      // La cookie local se elimina incluso si Laravel no está disponible.
    }
  }
  await clearStudentToken();
  redirect('/login');
}

export async function loginAdmin(formData: FormData): Promise<void> {
  const email = textValue(formData, 'email');
  const password = rawValue(formData, 'password');
  if (!email || password.length === 0) redirect('/admin/login?error=fields');

  let payload: { session_token: string };
  try {
    payload = await callLaravel<{ session_token: string }>('admin/login', {
      method: 'POST',
      body: { email, password },
    });
  } catch (error) {
    const status = error instanceof BackendError ? error.status : 0;
    const code = status === 401 || status === 422 ? 'credentials' : status === 429 ? 'limited' : 'unavailable';
    redirect(`/admin/login?error=${code}`);
  }

  if (!payload?.session_token) redirect('/admin/login?error=unavailable');
  await saveAdminToken(payload.session_token);
  redirect('/admin');
}

export async function logoutAdmin(): Promise<void> {
  const token = await readAdminToken();
  if (token) {
    try {
      await callLaravel('admin/logout', { method: 'POST', session: token });
    } catch {
      // La sesión local se elimina aunque el backend esté fuera de servicio.
    }
  }
  await clearAdminToken();
  redirect('/admin/login');
}

export async function createSubject(formData: FormData): Promise<void> {
  const prerequisites = textValue(formData, 'prerequisite_codes')
    .split(',')
    .map((code) => code.trim().toUpperCase())
    .filter(Boolean);

  try {
    await adminRequest('subjects', {
      method: 'POST',
      body: {
        code: textValue(formData, 'code').toUpperCase(),
        name: textValue(formData, 'name'),
        credits: Number(textValue(formData, 'credits')),
        suggested_semester: Number(textValue(formData, 'suggested_semester')),
        difficulty: Number(textValue(formData, 'difficulty')),
        prerequisite_codes: prerequisites,
      },
    });
  } catch (error) {
    const message = error instanceof BackendError ? error.message : 'No se pudo guardar la materia.';
    redirect(`/admin?error=${encodeURIComponent(message)}`);
  }

  revalidatePath('/admin');
  redirect('/admin?status=created');
}

export async function updateSubject(formData: FormData): Promise<void> {
  const id = textValue(formData, 'id');
  if (!/^\d+$/.test(id)) redirect('/admin?error=id');
  const prerequisites = textValue(formData, 'prerequisite_codes')
    .split(',')
    .map((code) => code.trim().toUpperCase())
    .filter(Boolean);

  try {
    await adminRequest(`subjects/${id}`, {
      method: 'PUT',
      body: {
        code: textValue(formData, 'code').toUpperCase(),
        name: textValue(formData, 'name'),
        credits: Number(textValue(formData, 'credits')),
        suggested_semester: Number(textValue(formData, 'suggested_semester')),
        difficulty: Number(textValue(formData, 'difficulty')),
        active: textValue(formData, 'active') === 'on',
        prerequisite_codes: prerequisites,
      },
    });
  } catch (error) {
    const message = error instanceof BackendError ? error.message : 'No se pudo actualizar la materia.';
    redirect(`/admin?error=${encodeURIComponent(message)}`);
  }

  revalidatePath('/admin');
  redirect('/admin?status=updated');
}

export async function deleteSubject(formData: FormData): Promise<void> {
  const id = textValue(formData, 'id');
  if (!/^\d+$/.test(id)) redirect('/admin?error=id');
  try {
    await adminRequest(`subjects/${id}`, { method: 'DELETE' });
  } catch (error) {
    const message = error instanceof BackendError ? error.message : 'No se pudo eliminar la materia.';
    redirect(`/admin?error=${encodeURIComponent(message)}`);
  }
  revalidatePath('/admin');
  redirect('/admin?status=deleted');
}

export async function importSubjectsCsv(formData: FormData): Promise<void> {
  const csv = textValue(formData, 'csv');
  if (!csv) redirect('/admin?error=csv');
  try {
    await adminRequest('subjects/import', { method: 'POST', body: { csv } });
  } catch (error) {
    const message = error instanceof BackendError ? error.message : 'No se pudo importar el CSV.';
    redirect(`/admin?error=${encodeURIComponent(message)}`);
  }
  revalidatePath('/admin');
  redirect('/admin?status=imported');
}

export type PlannerActionState = {
  data?: {
    periods: Array<{ number: number; label: string; total_credits: number; subjects: Array<{ code: string; name: string; credits: number; suggested_semester: number; difficulty: number }> }>;
    warnings: string[];
    completed_count: number;
    currently_studying_count?: number;
    remaining_catalog_count: number;
    unassigned_subjects?: Array<{ code: string; name: string }>;
  };
  error?: string;
};

/*export async function generatePlannerPreview(_previous: PlannerActionState, formData: FormData): Promise<PlannerActionState> {
  const maxCredits = Number(textValue(formData, 'max_credits'));
  const maxPeriods = Number(textValue(formData, 'max_periods'));
  if (!Number.isInteger(maxCredits) || maxCredits < 10 || maxCredits > 40 || !Number.isInteger(maxPeriods) || maxPeriods < 1 || maxPeriods > 12) {
    return { error: 'Indica entre 10 y 40 créditos por periodo y entre 1 y 12 periodos de simulación.' };
  }

  try {
    const result = await fetchPlannerPreview<{
      data: NonNullable<PlannerActionState['data']>;
    }>(maxCredits, maxPeriods);
    return { data: result.data };
  } catch (error) {
    if (error instanceof BackendError && (error.status === 401 || error.status === 403)) {
      await clearStudentToken();
      redirect('/login?error=session');
    }
    return { error: error instanceof Error ? error.message : 'No se pudo generar la planeación.' };
  }
}*/

const SUBJECT_CODE = /^[A-Z]{3}-\d{4}$/;

function boundedInt(value: unknown, min: number, max: number): number | null {
  const number = Number(value);
  return Number.isInteger(number) && number >= min && number <= max ? number : null;
}

function sanitizePlannerInput(input: PlannerInput): PlannerInput {
  const code = (value: unknown) => String(value ?? '').trim().toUpperCase();

  const summer = (input?.summer ?? []).flatMap((item) => {
    const c = code(item?.code);
    const after = boundedInt(item?.after, 1, 8);
    return SUBJECT_CODE.test(c) && after !== null ? [{ code: c, after }] : [];
  }).slice(0, 6);

  const deferred = (input?.deferred ?? []).flatMap((item) => {
    const c = code(item?.code);
    const periods = boundedInt(item?.periods, 1, 4);
    return SUBJECT_CODE.test(c) && periods !== null ? [{ code: c, periods }] : [];
  }).slice(0, 6);

  const leave = Array.from(new Set((input?.leave ?? []).flatMap((value) => {
    const n = boundedInt(value, 1, 8);
    return n !== null ? [n] : [];
  }))).slice(0, 6);

  const fail = Array.from(new Set((input?.fail ?? []).map(code).filter((c) => SUBJECT_CODE.test(c)))).slice(0, 5);

  return { summer, deferred, leave, fail };
}

export async function generatePlannerPreview(input: PlannerInput): Promise<PlannerActionState> {
  const body = sanitizePlannerInput(input);
  const token = await readStudentToken();
  if (!token) redirect('/login?error=session');

  try {
    const result = await callLaravel<{ data: PlannerResult }>('student/planner/preview', {
      method: 'POST',
      session: token,
      body,
    });
    return { data: result.data };
  } catch (error) {
    if (error instanceof BackendError && (error.status === 401 || error.status === 403)) {
      await clearStudentToken();
      redirect('/login?error=session');
    }
    return { error: error instanceof Error ? error.message : 'No se pudo generar la planeación.' };
  }
}

async function fetchPlannerPreview<T>(maxCredits: number, maxPeriods: number): Promise<T> {
  const token = await readStudentToken();
  if (!token) throw new Error('La sesión expiró. Inicia sesión nuevamente.');
  return callLaravel<T>('student/planner/preview', {
    method: 'POST',
    session: token,
    body: { max_credits: maxCredits, max_periods: maxPeriods },
  });
}
