<?php

namespace Tests\Feature\Phase152;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 152 Plano 05 (ADMIN-01, D-07) — ChecklistAdministrativoService::paraEmpresa().
 *
 * Prova a montagem condicional: 9 itens com contrato, 6 sem — o grupo
 * Contrato AUSENTE do payload para empresa isenta, nunca vazio nem marcado
 * como "não aplicável" (D-02) — e a persistência LAZY dos itens manuais.
 */
class ChecklistContagemItensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem de ContratoAdminDetalheTest (Fase 131) — sem isto
        // o Observer de gatilho de contrato pode reagir como efeito
        // colateral do setUp de cada teste.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers (copiados de ContratoAdminDetalheTest — Fase 131) ─────────

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (checklist 152-05)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function servicoIsento(string $nome = 'Polos (checklist 152-05)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_POLOS,
            'exige_contrato' => false,
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

    /**
     * `withoutEvents`: sem isto, `ContratoServico::create()` dispara o
     * Observer de gatilho de contrato como efeito colateral do SETUP, antes
     * da chamada explícita que cada teste está medindo.
     */
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

    // ─── Caso 1 — empresa com contrato: 9 itens, os dois grupos presentes ──

    public function test_empresa_com_servico_que_exige_contrato_monta_9_itens_com_os_dois_grupos(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoComContrato());

        $payload = $this->service()->paraEmpresa($empresa->fresh());

        $this->assertTrue($payload['exige_contrato']);
        $this->assertArrayHasKey('contrato', $payload['grupos']);
        $this->assertArrayHasKey('entrada', $payload['grupos']);

        $total = count($payload['grupos']['contrato']['itens']) + count($payload['grupos']['entrada']['itens']);
        $this->assertSame(9, $total);
    }

    // ─── Caso 2 — empresa com serviço isento: 6 itens, grupo Contrato ausente ──

    public function test_empresa_com_servico_isento_monta_6_itens_sem_o_grupo_contrato(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());

        $payload = $this->service()->paraEmpresa($empresa->fresh());

        $this->assertFalse($payload['exige_contrato']);
        $this->assertArrayNotHasKey('contrato', $payload['grupos']);
        $this->assertArrayHasKey('entrada', $payload['grupos']);
        $this->assertSame(6, count($payload['grupos']['entrada']['itens']));
    }

    // ─── Caso 3 — os dois tipos de serviço ativos ao mesmo tempo: conta como "exige contrato" ──

    public function test_empresa_com_servico_com_contrato_e_servico_isento_ao_mesmo_tempo_conta_9_itens(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoComContrato());
        $this->vincularServico($empresa, $this->servicoIsento());

        $payload = $this->service()->paraEmpresa($empresa->fresh());

        $this->assertTrue($payload['exige_contrato']);
        $this->assertArrayHasKey('contrato', $payload['grupos']);

        $total = count($payload['grupos']['contrato']['itens']) + count($payload['grupos']['entrada']['itens']);
        $this->assertSame(9, $total);
    }

    // ─── Caso 4 — ordem dos itens dentro de cada grupo segue a ordem do catálogo ──

    public function test_ordem_dos_itens_dentro_de_cada_grupo_segue_a_ordem_do_catalogo(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoComContrato());

        $payload = $this->service()->paraEmpresa($empresa->fresh());

        $chavesContrato = array_column($payload['grupos']['contrato']['itens'], 'chave');
        $chavesEntrada = array_column($payload['grupos']['entrada']['itens'], 'chave');

        $this->assertSame(['contrato_revisado', 'contrato_enviado', 'contrato_assinado'], $chavesContrato);
        $this->assertSame(
            [
                'grupo_whatsapp_criado',
                'email_colaborador_criado',
                'link_adman_entregue',
                'grant_consultoria_ml',
                'conexao_ecf_gerada',
                'boas_vindas_enviada',
            ],
            $chavesEntrada
        );
    }

    // ─── Caso 5 — item manual nunca gera linha só por chamar paraEmpresa() (persistência lazy) ──

    public function test_nenhuma_linha_e_criada_para_item_manual_so_por_chamar_para_empresa(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoComContrato());

        $this->service()->paraEmpresa($empresa->fresh());

        $chavesManuais = collect(ChecklistAdministrativoDefinicao::itens(true))
            ->where('natureza', ChecklistAdministrativoDefinicao::NATUREZA_MANUAL)
            ->pluck('chave');

        // A fixture precisa ter pelo menos um item manual para o caso fazer sentido.
        $this->assertGreaterThan(0, $chavesManuais->count());

        $this->assertSame(
            0,
            ChecklistAdministrativoItem::where('company_id', $empresa->id)
                ->whereIn('chave', $chavesManuais)
                ->count()
        );
    }
}
