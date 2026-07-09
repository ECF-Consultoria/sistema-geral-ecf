<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 74 — Cria a tabela `bonus_faixas`, fonte de verdade da régua de bônus
 * do módulo Desempenho.
 *
 * Contexto de negócio (74-SPEC.md DESEMP-07 + 74-CONTEXT.md D-14):
 *   A régua de bonificação da equipe Performance (diretoria/gestão em 2026-07-09)
 *   viraliza 4 faixas categóricas (sem_bonus / básico / intermediário / máximo),
 *   com limites `nota_min`/`nota_max` inclusivos em [0.00, 5.00]. A régua PRECISA
 *   ser editável pelo admin (Plan 74-05 UI + Plan 74-07 REST) e SINCRONIZADA em
 *   tempo real com o artigo dinâmico do Manual (Plan 74-08). Não usar
 *   `configuracoes` key-value — a UI de CRUD e a query dinâmica exigem tabela
 *   dedicada com colunas fortemente tipadas.
 *
 * Schema (D-14):
 *   - id            bigInteger PK
 *   - slug          varchar(50) UNIQUE   — chave de código estável (sem_bonus, ...)
 *   - nome          varchar(100)         — label visível editável pelo admin
 *   - descricao     text NULL            — texto explicativo do Manual dinâmico
 *   - nota_min      DECIMAL(3,2)         — piso inclusivo em [0.00, 5.00]
 *   - nota_max      DECIMAL(3,2)         — teto inclusivo em [0.00, 5.00]
 *   - ordem         unsignedSmallInteger — ordenação ascendente na UI
 *   - ativo         boolean default true — faixa participa da classificação
 *   - timestamps
 *
 * Índice composto `(ativo, ordem)`: acelera a query padrão
 * `where('ativo', true)->orderBy('ordem')` executada pelo
 * `BonusFaixa::classificar()`, pela UI de `Desempenho/Configuracao.jsx`
 * (Plan 74-07) e pelo artigo dinâmico do Manual (Plan 74-08).
 *
 * Migration idempotente: guard `Schema::hasTable` evita recriação em rerun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bonus_faixas')) {
            return;
        }

        Schema::create('bonus_faixas', function (Blueprint $table) {
            $table->id();

            // Chave estável usada em código (constants no Service, comparações
            // em teste). NÃO editável pela UI — só o `nome` visual é.
            $table->string('slug', 50)->unique();

            // Label da UI editável pelo admin (mantém `slug` invariante).
            $table->string('nome', 100);

            // Texto explicativo renderizado no artigo /manual/desempenho-bonificacao
            // (Plan 74-08). Opcional para faixas customizadas.
            $table->text('descricao')->nullable();

            // Limites inclusivos [0.00, 5.00]. DECIMAL(3,2) suporta o range
            // completo (000.00 até 9.99) — mais que suficiente para nota final.
            $table->decimal('nota_min', 3, 2);
            $table->decimal('nota_max', 3, 2);

            // Ordenação ascendente para lista da UI e do Manual.
            $table->unsignedSmallInteger('ordem')->default(0);

            // Faixa desativada deixa de participar da classificação, mas
            // permanece na tabela para auditoria (LogsActivity no Model).
            $table->boolean('ativo')->default(true);

            $table->timestamps();

            // Query default do módulo — `WHERE ativo = 1 ORDER BY ordem ASC`.
            $table->index(['ativo', 'ordem'], 'bonus_faixas_ativo_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_faixas');
    }
};
