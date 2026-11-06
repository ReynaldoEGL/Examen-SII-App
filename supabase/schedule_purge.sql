-- Ejecutar después de habilitar la extensión pg_cron en Supabase.
-- Reemplaza una programación anterior con el mismo nombre para ser re-ejecutable.
do $$
declare
  existing_job_id bigint;
begin
  select jobid
    into existing_job_id
    from cron.job
   where jobname = 'purge-app-sessions';

  if existing_job_id is not null then
    perform cron.unschedule(existing_job_id);
  end if;

  perform cron.schedule(
    'purge-app-sessions',
    '*/30 * * * *',
    'select public.purge_expired_sessions();'
  );
end $$;
