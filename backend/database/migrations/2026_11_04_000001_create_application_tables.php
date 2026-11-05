<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('role', 30)->default('editor');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type', 20)->index();
            $table->string('principal_id', 40)->index();
            $table->char('token_hash', 64)->unique();
            $table->text('sii_token')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 180);
            $table->unsignedTinyInteger('credits');
            $table->unsignedTinyInteger('suggested_semester');
            $table->unsignedTinyInteger('difficulty')->default(3);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('subject_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('related_subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->string('relation_type', 20)->default('prerequisite');
            $table->timestamps();
            $table->unique(['subject_id', 'related_subject_id', 'relation_type']);
        });

        Schema::create('planning_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->string('student_number', 40)->index();
            $table->jsonb('parameters');
            $table->jsonb('result');
            $table->timestamps();
        });

        // En PostgreSQL (Supabase), habilitar RLS sin políticas públicas.
        // SQLite se utiliza únicamente en pruebas automatizadas y no implementa RLS.
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['cache', 'cache_locks', 'admins', 'app_sessions', 'subjects', 'subject_relations', 'planning_scenarios'] as $table) {
                DB::statement('ALTER TABLE "'.$table.'" ENABLE ROW LEVEL SECURITY');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_scenarios');
        Schema::dropIfExists('subject_relations');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('app_sessions');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
