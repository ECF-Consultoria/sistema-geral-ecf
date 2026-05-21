<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela nativa do Laravel para o canal `database` de Notifications.
 * Polimórfica via `notifiable_id`/`notifiable_type` — qualquer modelo com a trait
 * `Notifiable` persiste aqui. Parte da fundação v3.0 — Phase 8.
 *
 * Reconciliação D-05 (08-CONTEXT.md): a coluna `data` é `text` (NÃO `json`) —
 * forma canônica do stub Laravel 12 (vendor/.../notifications.stub linha 18).
 * O cast `array` do `Illuminate\Notifications\DatabaseNotification` (linhas 47-50)
 * cuida do round-trip JSON automaticamente. D-05 descreve o conteúdo como JSON
 * (que é o efeito final), mas a coluna física é `text`. Usar `json('data')`
 * quebraria SQLite in-memory dos testes e contradiz o stub do framework
 * (Pitfall 3 do 08-RESEARCH.md).
 *
 * UUID PK é gerado pelo dispatcher do Laravel via `Str::uuid()` — não tocar
 * no formato da coluna `id` (Pitfall 4).
 */
return new class extends Migration
{
    /**
     * Executa a migration: cria tabela `notifications` no schema canônico Laravel 12.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');                  // gera notifiable_id + notifiable_type + índice composto
            $table->text('data');                          // cast 'array' no DatabaseNotification faz round-trip JSON
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverte a migration: dropa a tabela.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
