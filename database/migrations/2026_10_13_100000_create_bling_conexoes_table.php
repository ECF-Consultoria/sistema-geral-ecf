<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 184 (BLING-01) — uma linha de conexão por empresa com o Bling ERP.
 *
 * Decisões de schema:
 *  - **Uma conexão por empresa** (`unique('company_id')`): a FK já é para
 *    `companies`, nunca `MlbEmpresa` — conta sem `Company` não conecta
 *    (CONTEXT §4.3, "nunca inventar Company").
 *  - **Seis valores de `status`** (string, nunca `enum` — o CHECK do SQLite
 *    quebraria os Feature tests): `a_conectar`, `aguardando_cliente`, `ativa`,
 *    `divergente`, `revogada`, `erro`. O `aguardando_cliente` existe por causa
 *    da D-01 (o link foi gerado, o cliente ainda não autorizou) — o seed §7
 *    só previa os quatro últimos.
 *  - `access_token`/`refresh_token` em **TEXT, nunca `string`**: o JWT do
 *    Bling tem de 1.500 a 3.000 caracteres (RESEARCH §2.1). O cast
 *    `encrypted` + `$hidden` fica no model (`BlingConexao`, Task 3).
 *  - `link_url`/`link_gerado_em`: o link assinado que a equipe manda ao
 *    cliente mora AQUI, não em colunas novas de `companies` (mesmo padrão já
 *    existente para outras integrações, mas isolado por tabela própria).
 *  - `dono_estoque_ml` nasce aqui com default `'nenhum'`, mas **nenhuma
 *    tarefa desta fase a lê** — é preparo para a Fase 3 do seed
 *    (`261010-integracao-bling-erp.md`).
 *  - `conectado_por`: `->nullable()` **antes** de `->nullOnDelete()` — sem
 *    isso o MariaDB recusa com o erro 1830 (o SQLite dos testes não pega).
 *  - Índices com nome explícito e curto (< 64 caracteres): nome de índice
 *    longo dá 1059 no MariaDB e deixa a tabela criada SEM o índice, com a
 *    migration marcada `Pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bling_conexoes')) {
            Schema::create('bling_conexoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('bling_empresa_id', 64)->nullable();
                $table->string('bling_cnpj', 20)->nullable();
                $table->string('bling_nome', 255)->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('last_refreshed_at')->nullable();
                $table->dateTime('connected_at')->nullable();
                $table->string('status', 20)->default('a_conectar');
                $table->text('last_error')->nullable();
                $table->dateTime('last_error_at')->nullable();
                $table->text('link_url')->nullable();
                $table->dateTime('link_gerado_em')->nullable();
                $table->text('depositos')->nullable();
                $table->string('saldo', 10)->default('virtual');
                $table->boolean('estoque_ativo')->default(true);
                $table->string('dono_estoque_ml', 10)->default('nenhum');
                $table->foreignId('conectado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('ultima_importacao_em')->nullable();
                $table->dateTime('ultimo_estoque_em')->nullable();
                $table->timestamps();

                $table->unique('company_id', 'blcon_company_uq');
                $table->index('bling_empresa_id', 'blcon_bling_emp_idx');
                $table->index('status', 'blcon_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bling_conexoes');
    }
};
