# SII Académico — Next.js + Laravel + Supabase

Aplicación académica que consume los endpoints reales del SII ITC. El navegador solo habla con Next.js. Next.js, ejecutándose en el servidor, habla con Laravel. Laravel es el único componente que consume el SII y accede a Supabase.

## Arquitectura y límites de confianza

```text
NAVEGADOR
   │ HTTPS: páginas, navegación y formularios de servidor
   ▼
NEXT.JS (React Server Components + Server Actions)
   │ HTTPS + firma HMAC + sesión opaca (solo servidor)
   ▼
LARAVEL (API privada de aplicación)
   ├── HTTPS + JWT del alumno ──► SII ITC
   └── PostgreSQL privado ──────► Supabase
```

- El navegador no conoce la URL interna de Laravel, el JWT del SII, la contraseña del alumno ni las credenciales de Supabase.
- No hay `fetch()` del navegador hacia Laravel, ni llamadas del navegador a Supabase.
- Next.js renderiza los datos en el servidor. Las Server Actions reciben los formularios en Next.js y desde el servidor llaman a Laravel.
- En desarrollo, Docker Compose coloca ambos servicios en la misma red. Laravel no publica ningún puerto en el host; únicamente Next.js puede alcanzarlo dentro de la red Docker.
- En despliegue separado (por ejemplo, Vercel + Render), Laravel requiere HTTPS y una firma HMAC servidor-a-servidor. No se debe poner `INTERNAL_SHARED_SECRET` en una variable `NEXT_PUBLIC_*`.
- La cookie del navegador contiene una sesión opaca aleatoria. Laravel almacena solamente su hash y el JWT del SII cifrado con `APP_KEY`.
- Las sesiones de alumnos y administradores son distintas. No existe registro público de administradores.

> Precisión importante: el navegador necesariamente solicita páginas y envía formularios al servidor web que las entrega (Next.js). Lo que se evita es que el JavaScript del navegador llame al backend de negocio Laravel, al SII o a Supabase. Si por “frontend” se entiende también el servidor Next.js, entonces ningún dato dinámico podría llegar a una página sin comunicación entre servidores.

## Requisitos en Windows

- Docker Desktop con WSL 2 habilitado.
- Git.
- No necesitas instalar PHP, Composer, Apache ni PostgreSQL en Windows.
- Node.js tampoco es necesario en Windows para este flujo: el contenedor `frontend` usa Node.

## Preparación local

1. Copia las plantillas de entorno desde PowerShell:

   ```powershell
   Copy-Item .\backend\.env.example .\backend\.env
   Copy-Item .\frontend\.env.local.example .\frontend\.env.local
   ```

   Laravel lee `backend/.env` desde el volumen montado. Compose no inyecta ese archivo como variables del contenedor, porque `APP_KEY` debe poder actualizarse con `php artisan key:generate`. Next.js sí recibe `frontend/.env.local` como configuración de proceso del contenedor.
2. Genera un secreto aleatorio largo y coloca el mismo valor en `backend/.env` (`INTERNAL_SHARED_SECRET`) y `frontend/.env.local` (`INTERNAL_SHARED_SECRET`). Ejemplo en PowerShell:

   ```powershell
   $secret = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLower()
   $secret
   ```

3. En `backend/.env`, configura las credenciales PostgreSQL de Supabase. Usa el **Session Pooler** de Supabase, puerto 5432, SSL requerido. No subas este archivo a GitHub.
4. En `backend/.env`, define `ADMIN_INITIAL_EMAIL` y `ADMIN_INITIAL_PASSWORD` con credenciales fuertes para el primer administrador. Estas variables solo se usan al ejecutar el seeder; no son credenciales del SII.
5. Inicia ambos servicios:

   ```powershell
   docker compose up -d --build
   ```

   El contenedor instala Composer y las dependencias Node al arrancar. La imagen de PHP incluye `pdo_sqlite`/`sqlite3` para que las pruebas automatizadas se ejecuten con una base SQLite en memoria sin tocar Supabase. Comprueba que Laravel haya terminado su instalación:

   ```powershell
   docker compose logs -f backend
   ```

   Cuando Apache indique que está escuchando, presiona `Ctrl+C` para salir del seguimiento de logs (esto no detiene el contenedor).

6. Genera la clave de cifrado de Laravel:

   ```powershell
   docker compose exec backend php artisan key:generate
   ```

7. Crea las tablas y el administrador inicial:

   ```powershell
   docker compose exec backend php artisan migrate --seed
   ```

8. Comprueba que Next.js esté listo y abre `http://localhost:3000`:

   ```powershell
   docker compose logs -f frontend
   ```

   Cuando Next.js muestre que está listo, presiona `Ctrl+C` para salir del seguimiento de logs.

Laravel no se expone al navegador ni al host. Su health check se ejecuta desde Docker; Next.js lo consume por el nombre interno `backend`.

## Variables importantes

### `backend/.env`

- `APP_KEY`: se genera con `php artisan key:generate` dentro del contenedor.
- `INTERNAL_SHARED_SECRET`: secreto compartido con Next.js para verificar HMAC.
- `SII_API_BASE_URL`: `https://sii.celaya.tecnm.mx/api`.
- `DB_*`: credenciales PostgreSQL de Supabase. No uses credenciales `anon` en este servicio. Evita usar `postgres` como rol de ejecución permanente en producción; consulta la nota de RLS.
- `ADMIN_INITIAL_*`: credenciales para crear el administrador inicial.

### `frontend/.env.local`

- `LARAVEL_INTERNAL_URL`: URL servidor-a-servidor que termina en `/api/internal`.
- `INTERNAL_SHARED_SECRET`: debe coincidir exactamente con el de Laravel.
- `SESSION_COOKIE_SECURE`: `false` en localhost; `true` con HTTPS en producción.

Ninguna de estas variables debe tener el prefijo `NEXT_PUBLIC_`.

## Cómo se demuestra el consumo real del SII

El navegador no hace solicitudes a Laravel ni al SII. Al enviar el formulario de login, el navegador invoca una Server Action de Next.js; esa función se ejecuta en el servidor Next.js y llama al endpoint interno Laravel con HMAC. Laravel ejecuta el `Http::post()` del login y, si recibe el JWT, ejecuta un `Http::withToken($jwt)->get()` al perfil. Después cada pantalla SSR invoca a Laravel, y Laravel consume el endpoint institucional correspondiente con `Authorization: Bearer`. El módulo `backend/tests/Feature/SiiServiceContractTest.php` incluye pruebas con `Http::fake()` que comprueban las URLs, métodos, credenciales JSON, encabezado Bearer y la envoltura 401 dentro de un HTTP 200.

Para la demostración, la evidencia más clara es abrir el código de `backend/app/Services/SiiService.php` y ejecutar las pruebas anteriores. En el navegador solo se observarán solicitudes a Next.js; eso es intencional y forma parte de la separación de seguridad.

## Funcionalidad implementada

- Inicio/cierre de sesión de alumnos a través de `POST https://sii.celaya.tecnm.mx/api/login` del SII. Laravel envía JSON `{email, password}`, extrae el JWT de `message.login.token`, valida el tipo `estudiante` y comprueba el token haciendo `GET /api/movil/estudiante` antes de crear la sesión local.
- Perfil, calificaciones actuales, kardex y horario consumidos en tiempo real desde los endpoints del SII.
- Exploración y filtro local de calificaciones; no dispara solicitudes API desde el navegador.
- Panel de administración con autenticación separada, catálogo de materias y prerrequisitos, y carga CSV.
- Asistente determinista de planeación: simula periodos sugeridos desde el kardex y el catálogo que administra el equipo. Asume que las materias actualmente cursadas se acreditan; muestra advertencias y no sustituye a Servicios Escolares.
- No se persisten calificaciones ni el perfil completo del alumno en Supabase.

## Contrato real del SII observado en las respuestas compartidas

### Autenticación

Éxito: `responseCodeTxt = "200 OK"`, `message.login.token` contiene el JWT, `status = 200`, `flag = "success"`, `data = 200` y `type = "estudiante"`. Laravel lee el token exactamente de `message.login.token`.

Credenciales incorrectas: el SII puede devolver HTTP 200 y, dentro del JSON, `responseCodeTxt = "401 Unauthorized"`, `status = 401`, `flag = null`, `message = null` y `data = 401`. Por eso `SiiService` no se basa únicamente en `Response::successful()`: también interpreta `status`, `code`, `responseCodeTxt` y, en la envoltura de login, el valor numérico de `data`. Este caso se convierte en HTTP 401 de nuestro backend y la interfaz muestra el mensaje de credenciales incorrectas.

Los cuatro endpoints de estudiante devuelven el contenido útil bajo `data`. La API de calificaciones usa la propiedad literal `calificaiones` (con esa ortografía) y las evaluaciones pendientes pueden ser `null`; el cliente conserva ese nombre y no convierte los valores pendientes en cero. El endpoint de calificaciones compartido no contiene un campo de calificación final: la interfaz muestra los cuatro parciales disponibles y explica esa limitación, sin calcular una nota final no proporcionada por el SII.

El JWT compartido de ejemplo no se incluye en este repositorio, pruebas, variables de entorno ni registros. El JWT debe tratarse como secreto temporal; solo se conserva cifrado en la tabla `app_sessions` y se elimina de la sesión al recibir una respuesta institucional 401/403. La sesión local se limita al `exp` del JWT (máximo ocho horas; si no se puede leer el claim, una hora como límite conservador).

## Comandos útiles

```powershell
# Ver logs
  docker compose logs -f frontend backend

# Ejecutar migraciones
  docker compose exec backend php artisan migrate

# Ejecutar pruebas de contrato del API SII (usan Http::fake; no necesitan credenciales reales)
  docker compose exec backend php artisan test --filter=SiiServiceContractTest

# Ejecutar todas las pruebas Laravel
  docker compose exec backend php artisan test

# Detener servicios
  docker compose down

# Detener y eliminar volúmenes (¡esto elimina vendor y node_modules del contenedor!)
  docker compose down -v
```

## Auditoría de RLS y credenciales de Supabase

La migración habilita RLS en las tablas de la aplicación y no crea políticas para los roles públicos `anon`/`authenticated`. Esto impide que esos roles accedan a las tablas mediante la Data API de Supabase. **No debe confundirse con una barrera frente a una filtración de las credenciales PostgreSQL del backend**: las conexiones hechas con el propietario/rol privilegiado `postgres` pueden omitir RLS. La defensa principal es que la contraseña de PostgreSQL solo exista en Laravel, nunca en Next.js ni en el navegador, y que Laravel autorice cada operación. Para endurecimiento adicional, se recomienda separar el rol de migración/propietario de un rol de ejecución con privilegios SQL mínimos y `NOBYPASSRLS`, con políticas específicas para ese rol; no reutilizar la contraseña `postgres` en servicios distintos.

## Despliegue

- Frontend: desplegar `frontend/` como aplicación Next.js con Node.js 22 o superior. Definir `LARAVEL_INTERNAL_URL`, `INTERNAL_SHARED_SECRET` y `SESSION_COOKIE_SECURE=true` como variables de entorno **del servidor**.
- Backend: desplegar `backend/` como contenedor Docker con HTTPS. Definir todas las variables de `backend/.env` en el proveedor. Ejecutar `php artisan key:generate` solo una vez por entorno persistente, `php artisan migrate --force` y crear el administrador mediante un seeder controlado. Mantener `APP_DEBUG=false`.
- Vercel y Render no comparten la red privada de Docker Compose. En producción, la URL de Laravel debe ser alcanzable por el servidor de Next.js y estar protegida por HTTPS, firma HMAC, rate limiting y reglas de red disponibles en el proveedor.
- No despliegues esta aplicación con `APP_DEBUG=true`. Mantén `APP_DEBUG=false` y rota ambos secretos si sospechas que fueron expuestos. En un despliegue Next.js autogestionado con varias réplicas, configura además una `NEXT_SERVER_ACTIONS_ENCRYPTION_KEY` persistente y compartida entre instancias; Vercel administra ese aspecto por ti.

