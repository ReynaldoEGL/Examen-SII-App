import 'server-only';
import { createHash, createHmac, randomUUID } from 'node:crypto';

export class BackendError extends Error {
  constructor(message: string, public readonly status: number) {
    super(message);
    this.name = 'BackendError';
  }
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  session?: string;
};

/**
 * Solo se puede importar desde módulos de servidor. Firma la llamada completa
 * para que Laravel acepte únicamente peticiones emitidas por Next.js.
 */
export async function callLaravel<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const base = process.env.LARAVEL_INTERNAL_URL;
  const secret = process.env.INTERNAL_SHARED_SECRET;

  if (!base || !secret || secret.length < 32) {
    throw new BackendError('La conexión interna no está configurada correctamente.', 500);
  }

  const normalizedBase = base.endsWith('/') ? base : `${base}/`;
  const url = new URL(path.replace(/^\/+/, ''), normalizedBase);
  const method = options.method ?? 'GET';
  const bodyText = options.body === undefined ? '' : (JSON.stringify(options.body) ?? '');
  const session = options.session ?? '';
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = randomUUID();
  const bodyHash = createHash('sha256').update(bodyText).digest('hex');
  const sessionHash = createHash('sha256').update(session).digest('hex');
  const canonical = [timestamp, nonce, method, url.pathname, bodyHash, sessionHash].join('\n');
  const signature = createHmac('sha256', secret).update(canonical).digest('hex');

  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'X-Service-Timestamp': timestamp,
        'X-Service-Nonce': nonce,
        'X-Service-Signature': signature,
        ...(session ? { 'X-App-Session': session } : {}),
        ...(bodyText ? { 'Content-Type': 'application/json' } : {}),
      },
      ...(bodyText ? { body: bodyText } : {}),
      cache: 'no-store',
      signal: AbortSignal.timeout(20_000),
    });
  } catch {
    throw new BackendError('No fue posible conectar con el servidor de aplicación.', 503);
  }

  const responseText = await response.text();
  let payload: unknown = null;
  if (responseText) {
    try {
      payload = JSON.parse(responseText);
    } catch {
      throw new BackendError('El servidor de aplicación devolvió una respuesta no válida.', 502);
    }
  }

  if (!response.ok) {
    const message =
      payload && typeof payload === 'object' && 'message' in payload && typeof payload.message === 'string'
        ? payload.message
        : 'No fue posible completar la operación.';
    throw new BackendError(message, response.status);
  }

  return payload as T;
}
