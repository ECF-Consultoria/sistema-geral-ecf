<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 (plano 02, ETAPA-01) — coluna `companies.etapa`: a etapa do fluxo
 * de entrada de novas empresas (§10 do PDF v23.0), separada de `status`
 * (contrato ativo/inativo, string livre, escrita por `ComercialController`).
 *
 * Puramente ADITIVA — só a coluna nova + índice. `status` não é tocado.
 * `nullable()`, SEM `default()` (D-03): `NULL` significa "empresa legada,
 * resolve pelo fallback derivado" (`CompanyController.php`). Um default
 * `aguardando_administrativo` colocaria centenas de empresas legadas na
 * etapa 1, o que é falso e inundaria a listagem da Fase 138. O backfill
 * real (dois baldes, D-04/D-05) é o comando Artisan do plano 137-05 — não
 * esta migration.
 *
 * `down()` derruba só `etapa` e nada mais (D-07). O anti-padrão a NÃO
 * repetir está no próprio repositório:
 * `2026_05_25_100001_add_status_to_companies.php`, que mistura a criação da
 * coluna `status` com um rename de `service_type` no mesmo `up()`/`down()` —
 * reverter uma parte ali obriga reverter as duas.
 *
 * Timestamp fixo `110000`: garante ordem determinística contra as
 * migrations dos planos 137-03 (`120000`) e 137-04 (`130000`), que rodam em
 * paralelo na wave seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('etapa', 40)->nullable()->after('status');
            $table->index('etapa'); // WHERE etapa = ? no filtro do plano 137-07
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('etapa');
        });
    }
};
