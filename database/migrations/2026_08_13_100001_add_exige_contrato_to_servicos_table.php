<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano 128-01 (D-03 / FLUXO-08): dado configurável que decide se um serviço
 * do catálogo exige contrato assinado antes de liberar o operacional. É o
 * primeiro filtro do gate administrativo — sem ele, Polos entraria no fluxo
 * de contrato junto com os demais serviços.
 *
 * `default(true)` é DELIBERADO e cobre dois problemas ao mesmo tempo:
 *  - SQLite (suíte de testes) recusa `ADD COLUMN ... NOT NULL` sem default.
 *  - Segurança do catálogo: os 8 serviços que exigem contrato (`Gestão`,
 *    `Gestão de ADS Shopee`, `Mentoria`, `Publicação`, `Assessoria`,
 *    `Incubadora`, `Implantação`, `Publicidade`) nascem cobertos SEM
 *    precisar ser enumerados por nome — inclusive `Gestão de ADS Shopee`,
 *    que não tem migration de seed versionada (cadastro manual do admin) e
 *    cuja grafia real em produção não foi medida (MariaDB local fora do ar
 *    no momento da pesquisa, confiança MÉDIA). Um `whereIn` com a lista dos
 *    8 nomes correria o risco de isentar por engano justamente por causa
 *    dessa grafia não confirmada — o oposto do que a D-03 pede. Com
 *    `default(true)` + UPDATE só do nome certo, uma eventual divergência de
 *    grafia é inofensiva: o serviço continua exigindo contrato.
 *
 * Só `Polos` é isentado, por NOME exato (`where('nome', 'Polos')`) — é o
 * único nome garantido por migration versionada
 * (`2026_05_27_100001_seed_servicos_catalog.php`, linha 37). Se a linha
 * ainda não existir (ambiente novo, antes do seed rodar), o UPDATE afeta 0
 * linhas e a migration passa mesmo assim; em ambiente limpo o seed roda
 * antes na ordem cronológica de migrations, então o Polos já existe aqui.
 *
 * Nenhuma armadilha de MariaDB do projeto se aplica: não é `enum`, não cria
 * índice, não cria FK (mesma conclusão da migration-molde da Fase 127,
 * `2026_08_12_100001_add_clicksign_template_id_to_servicos_table.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->boolean('exige_contrato')->default(true)->after('clicksign_template_id');
        });

        // Polos é o único serviço isento de contrato (D-03) — isenção
        // gravada aqui, na própria migration, sem depender de ninguém
        // marcar isso depois numa tela.
        DB::table('servicos')->where('nome', 'Polos')->update(['exige_contrato' => false]);
    }

    public function down(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->dropColumn('exige_contrato');
        });
    }
};
