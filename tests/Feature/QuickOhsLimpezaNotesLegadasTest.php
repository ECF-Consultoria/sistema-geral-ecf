<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quick task 260805-ohs — cobre a migration que limpa de `companies.notes` as
 * linhas escritas automaticamente pelo webhook do HubSpot.
 *
 * A migration ja rodou (vazia) no boot do RefreshDatabase; aqui ela e
 * re-instanciada e executada A MAO depois de semear dado sujo, que e a unica
 * forma de exercitar a transformacao com dados.
 *
 * Cobre:
 *  T1. Linha legada unica → notes vira NULL (nao string vazia)
 *  T2. Texto humano misturado com linha legada → so a legada some
 *  T3. Texto 100% humano → intocado
 *  T4. Idempotencia — rodar duas vezes nao causa dano
 *  T5. Limpeza NAO gera entrada no activity_log nem mexe em updated_at
 */
class QuickOhsLimpezaNotesLegadasTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__
        . '/../../database/migrations/2026_08_05_140000_limpa_linhas_legadas_hubspot_de_companies_notes.php';

    /**
     * Roda a migration de limpeza manualmente (o arquivo devolve a classe anonima).
     */
    private function rodaMigration(): void
    {
        $migration = require self::MIGRATION;
        $migration->up();
    }

    /**
     * Cria a empresa e grava `notes` por DB::table — evita que o proprio setup
     * do teste polua o activity_log, que e justamente o que T5 mede.
     */
    private function empresaComNotes(string $nome, ?string $notes): Company
    {
        $company = Company::create(['name' => $nome, 'active' => true]);

        DB::table('companies')->where('id', $company->id)->update(['notes' => $notes]);

        return $company->refresh();
    }

    public function test_linha_legada_unica_vira_null(): void
    {
        $company = $this->empresaComNotes('Empresa Suja', 'Contato (HubSpot): Pagina MAG 3');

        $this->rodaMigration();

        $this->assertNull(
            $company->refresh()->notes,
            'Sobrando nada, notes deve virar NULL — nunca string vazia'
        );
    }

    public function test_linha_de_servico_legada_tambem_e_removida(): void
    {
        $company = $this->empresaComNotes('Empresa Servico', "Serviço (HubSpot): Polos SP");

        $this->rodaMigration();

        $this->assertNull($company->refresh()->notes);
    }

    public function test_variante_sem_acento_tambem_e_removida(): void
    {
        $company = $this->empresaComNotes('Empresa Sem Acento', 'Servico (HubSpot): Assessoria');

        $this->rodaMigration();

        $this->assertNull($company->refresh()->notes);
    }

    public function test_texto_humano_misturado_sobrevive(): void
    {
        $company = $this->empresaComNotes(
            'Empresa Mista',
            "Contato (HubSpot): Ana Costa\n3 CNPJS ATIVOS\nServiço (HubSpot): Polos SP"
        );

        $this->rodaMigration();

        $this->assertSame(
            '3 CNPJS ATIVOS',
            $company->refresh()->notes,
            'Texto digitado por humano NUNCA pode ser perdido na limpeza'
        );
    }

    public function test_texto_puramente_humano_fica_intocado(): void
    {
        $company = $this->empresaComNotes('Vitrine do Couro - Principal', '3 CNPJS ATIVOS');

        $this->rodaMigration();

        $this->assertSame('3 CNPJS ATIVOS', $company->refresh()->notes);
    }

    public function test_migration_e_idempotente(): void
    {
        $sujaA  = $this->empresaComNotes('Suja A', 'Contato (HubSpot): Fulano');
        $humana = $this->empresaComNotes('Humana', "3 CNPJS ATIVOS\nCliente exige NF em separado");

        $this->rodaMigration();
        $this->rodaMigration();

        $this->assertNull($sujaA->refresh()->notes);
        $this->assertSame(
            "3 CNPJS ATIVOS\nCliente exige NF em separado",
            $humana->refresh()->notes,
            'Segunda passada nao pode alterar nada'
        );
    }

    public function test_limpeza_nao_gera_activity_log_nem_toca_updated_at(): void
    {
        $company = $this->empresaComNotes('Empresa Auditada', 'Contato (HubSpot): Beltrano');

        $updatedAtAntes = DB::table('companies')->where('id', $company->id)->value('updated_at');
        $logsAntes      = DB::table('activity_log')->count();

        $this->rodaMigration();

        $this->assertSame(
            $logsAntes,
            DB::table('activity_log')->count(),
            'Limpeza via DB::table nao pode gerar entrada de auditoria como se fosse edicao humana'
        );
        $this->assertSame(
            $updatedAtAntes,
            DB::table('companies')->where('id', $company->id)->value('updated_at'),
            'Faxina de dado derivado nao deve remarcar updated_at'
        );
    }
}
