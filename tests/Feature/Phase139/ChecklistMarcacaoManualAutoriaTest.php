<?php

namespace Tests\Feature\Phase139;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 139 Plano 05 (ADMIN-04, D-11, D-13, D-14) —
 * ChecklistAdministrativoService::concluirManualmente()/reabrirItem()/gerarConexaoEcf().
 *
 * Autoria gravada e limpa SEMPRE em par, item automático recusa marcação
 * manual, chave desconhecida recusa antes de escrever, autoria sobrevive ao
 * desligamento do autor, e a conexão ECF é gerada de forma idempotente sem
 * marcar o item por uma segunda fonte de verdade.
 */
class ChecklistMarcacaoManualAutoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem de ContratoAdminDetalheTest (Fase 131).
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function servicoIsento(): Servico
    {
        return Servico::create([
            'nome'           => 'Polos (marcação 139-05)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_POLOS,
            'exige_contrato' => false,
        ]);
    }

    private function servicoComContrato(): Servico
    {
        return Servico::create([
            'nome'           => 'Gestão de Tráfego (marcação 139-05)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresaCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
            'razao_social'  => 'Contato de Teste LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'            => $c->id,
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'ativo'                 => true,
        ], $overrides)));
    }

    private function service(): ChecklistAdministrativoService
    {
        return app(ChecklistAdministrativoService::class);
    }

    // ─── Caso 1 — marcar item manual: autoria gravada, por reconsulta ao banco ──

    public function test_marcar_item_manual_grava_status_concluido_e_autoria(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->service()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);

        $linha = ChecklistAdministrativoItem::where('company_id', $empresa->id)
            ->where('chave', 'grupo_whatsapp_criado')
            ->first();

        $this->assertNotNull($linha);
        $this->assertSame(ChecklistAdministrativoItem::STATUS_CONCLUIDO, $linha->status);
        $this->assertSame($usuario->id, $linha->feito_por);
        $this->assertNotNull($linha->feito_em);
    }

    // ─── Caso 2 — desmarcar o mesmo item: os dois campos limpos juntos ──────

    public function test_desmarcar_item_manual_limpa_status_e_os_dois_campos_de_autoria_juntos(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->service()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);
        $this->service()->reabrirItem($empresa, 'grupo_whatsapp_criado', $usuario);

        $linha = ChecklistAdministrativoItem::where('company_id', $empresa->id)
            ->where('chave', 'grupo_whatsapp_criado')
            ->first();

        $this->assertNotNull($linha);
        $this->assertSame(ChecklistAdministrativoItem::STATUS_ABERTO, $linha->status);
        $this->assertNull($linha->feito_por);
        $this->assertNull($linha->feito_em);
    }

    // ─── Caso 3 — marcar item automático lança DomainException, nada é gravado ──

    public function test_marcar_item_automatico_a_mao_lanca_domain_exception_e_nao_grava_nada(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoComContrato());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->expectException(\DomainException::class);

        try {
            $this->service()->concluirManualmente($empresa, 'contrato_enviado', $usuario);
        } finally {
            $this->assertDatabaseMissing('checklist_administrativo_itens', [
                'company_id' => $empresa->id,
                'chave'      => 'contrato_enviado',
                'feito_por'  => $usuario->id,
            ]);
        }
    }

    // ─── Caso 4 — item do grupo Contrato numa empresa isenta lança DomainException (D-07) ──

    public function test_marcar_item_do_grupo_contrato_em_empresa_isenta_lanca_domain_exception(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->expectException(\DomainException::class);

        $this->service()->concluirManualmente($empresa, 'contrato_revisado', $usuario);
    }

    // ─── Caso 5 — chave inexistente lança DomainException, nada é gravado ──

    public function test_marcar_chave_inexistente_lanca_domain_exception_e_nao_grava_nada(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->expectException(\DomainException::class);

        try {
            $this->service()->concluirManualmente($empresa, 'chave_inexistente', $usuario);
        } finally {
            $this->assertDatabaseMissing('checklist_administrativo_itens', [
                'company_id' => $empresa->id,
                'chave'      => 'chave_inexistente',
            ]);
        }
    }

    // ─── Caso 6 — autoria sobrevive ao desligamento do autor (D-11, ponto 1) ──

    public function test_autoria_sobrevive_ao_soft_delete_do_usuario(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin', 'name' => 'Analista Desligado']);

        $this->service()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);
        $usuario->delete();

        $payload = $this->service()->paraEmpresa($empresa->fresh());

        $item = collect($payload['grupos']['entrada']['itens'])
            ->firstWhere('chave', 'grupo_whatsapp_criado');

        $this->assertNotNull($item);
        $this->assertSame('Analista Desligado', $item['feito_por_nome']);
    }

    // ─── Caso 7 — gerarConexaoEcf() é idempotente e não marca o item por segunda fonte (D-14) ──

    public function test_gerar_conexao_ecf_duas_vezes_cria_uma_unica_linha_e_fecha_o_item_8(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->service()->gerarConexaoEcf($empresa, $usuario);
        $this->service()->gerarConexaoEcf($empresa, $usuario);

        $this->assertDatabaseCount('onboarding_links', 1);
        $this->assertSame(1, OnboardingLink::where('company_id', $empresa->id)->count());

        $payload = $this->service()->paraEmpresa($empresa->fresh());

        $item = collect($payload['grupos']['entrada']['itens'])
            ->firstWhere('chave', 'conexao_ecf_gerada');

        $this->assertNotNull($item);
        $this->assertSame(ChecklistAdministrativoItem::STATUS_CONCLUIDO, $item['status']);
    }
}
