<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link aberto do Planejador FULL para o cliente (15/10/2026).
 *
 * Decisão do usuário: o cliente entra por um LINK, sem login, vê só a empresa dele e opera
 * tudo (lead times, galpão, envios). Só existe link para a empresa que a equipe liberar.
 *
 * Decisões de schema (escritas na conversa ANTES desta migration — CLAUDE.md):
 *
 *  - Uma linha por empresa (`company_id` único). Gerar de novo TROCA o token na mesma linha:
 *    o link antigo para de funcionar na hora — é a resposta para link vazado, o motivo de o
 *    token do Portal ter sido aposentado em 15/09.
 *  - O token não fica em texto puro: `token_hash` (SHA-256) é o que a rota procura;
 *    `token_cifrado` (Crypt, APP_KEY) existe só para a equipe copiar o link de novo.
 *    Revogar zera os dois e carimba `revogado_em`.
 *  - `token_hash` único e anulável (revogado = nulo); nome de índice curto (limite de 64).
 *  - `criado_por` nullable + nullOnDelete (erro 1830 do MariaDB sem nullable).
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('full_compartilhamentos')) {
            return;
        }

        Schema::create('full_compartilhamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('token_hash', 64)->nullable();
            $table->text('token_cifrado')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('gerado_em')->nullable();
            $table->dateTime('revogado_em')->nullable();
            $table->dateTime('ultimo_acesso_em')->nullable();
            $table->unsignedInteger('acessos')->default(0);
            $table->timestamps();

            $table->unique('token_hash', 'full_compart_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('full_compartilhamentos');
    }
};
