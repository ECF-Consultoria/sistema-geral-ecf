<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 97 (Fundação do Module Registry) — tabela `modules`.
 *
 * Registro explícito dos módulos do sistema + estágio de maturidade (eixo
 * ORTOGONAL ao RBAC existente — não substitui role/permission_key, adiciona
 * "está maduro o suficiente pra aparecer?" em cima de "pode acessar?").
 *
 * `stage` (enum, default 'backlog'): ideia -> backlog -> em_desenvolvimento
 * -> homologacao -> producao -> arquivado. Segue o padrão comprovado em
 * `2026_06_18_100001_add_setor_to_servicos_table.php` (enum roda cross-driver,
 * inclusive no SQLite in-memory da suíte de testes).
 *
 * `metadata` (json) é ponto de extensão deliberado — tags/checklist/links
 * sem exigir migration futura (YAGNI: tabelas dedicadas só se isso não bastar).
 *
 * Nesta fase a tabela existe mas NADA a consome ainda (zero mudança de tela/
 * rota/menu) — o catálogo estático + ModuleRegistry chegam no Plano 97-03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 150);
            $table->string('grupo', 100)->nullable();
            $table->enum('stage', [
                'ideia', 'backlog', 'em_desenvolvimento', 'homologacao', 'producao', 'arquivado',
            ])->default('backlog')->index();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->enum('prioridade', ['p0', 'p1', 'p2', 'p3'])->nullable();
            $table->unsignedTinyInteger('progresso')->nullable();
            $table->boolean('bloqueado')->default(false);
            $table->string('bloqueio_motivo')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route_prefix')->nullable();
            $table->string('permission_key')->nullable();
            $table->text('notas')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('promovido_prod_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
