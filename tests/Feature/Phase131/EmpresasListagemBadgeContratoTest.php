<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 131 Plano 02 (UI-03/D-08) — badge de contrato na listagem do
 * Comercial (`ComercialController::listagem()`).
 *
 * Nasce na Task 1 (3 casos de conteúdo do badge) e é COMPLETADO na Task 3
 * (contrato mais recente + ausência de N+1) — mesmo arquivo, sem reescrita.
 *
 * Mesma disciplina do resto da fase: conferência por reconsulta ao banco, e
 * tempo fixado com `Carbon::setTestNow()` para os dias virem determinísticos.
 */
class EmpresasListagemBadgeContratoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (badge)'): Servico
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

    /** Serviço isento de contrato — caso Polos (D9 da milestone). */
    private function servicoIsento(string $nome = 'Polos (badge)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 0,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_OUTROS,
            'exige_contrato' => false,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    private function linhaDaEmpresa(array $props, int $companyId): ?array
    {
        return collect($props['companies']['data'])->firstWhere('id', $companyId);
    }

    // ─── Task 1: os três casos de conteúdo ────────────────────────────────

    public function test_empresa_com_contrato_aguardando_assinaturas_ha_6_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Badge Aguardando']);
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDays(6),
        ]);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $row['contrato_badge']['status']);
        $this->assertSame(6, $row['contrato_badge']['dias']);
    }

    public function test_empresa_com_servico_que_exige_contrato_e_sem_contrato_assinatura_mostra_aguardando_administrativo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Badge Sem Contrato']);
        $empresa->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertSame('aguardando_administrativo', $row['contrato_badge']['status']);
        $this->assertSame(3, $row['contrato_badge']['dias']);
    }

    public function test_empresa_cujo_unico_servico_ativo_e_isento_de_contrato_mostra_badge_nulo(): void
    {
        $servico = $this->servicoIsento();
        $empresa = $this->empresa(['name' => 'Empresa Badge Polos']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertNull(
            $row['contrato_badge'],
            'Empresa cujo único serviço ativo é isento (D9) nunca deve receber "aguardando_administrativo".'
        );
    }

    // ─── Task 3: contrato mais recente + ausência de N+1 ─────────────────

    public function test_empresa_com_mais_de_um_contrato_o_badge_traz_o_mais_recente(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Badge Vários Contratos']);
        $this->vincularServico($empresa, $servico);

        // Contrato mais antigo (menor id) — cancelado, não ocupa o slot "em
        // andamento", então o mais novo abaixo pode ser aguardando_assinaturas
        // sem esbarrar no índice único de (empresa+serviço) em andamento.
        $maisAntigo = ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_CANCELADO,
        ]);

        $maisRecente = ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em' => now()->subDays(5),
            'assinado_em' => now()->subDays(2),
        ]);

        $this->assertGreaterThan($maisAntigo->id, $maisRecente->id);

        $response = $this->actingAs($this->admin())->get(route('comercial.empresas.listagem'));
        $response->assertOk();

        $row = $this->linhaDaEmpresa($response->viewData('page')['props'], $empresa->id);
        $this->assertNotNull($row);
        $this->assertSame(
            ContratoAssinatura::STATUS_ASSINADO,
            $row['contrato_badge']['status'],
            'O badge deveria trazer o contrato de MAIOR id (mais recente), não o primeiro criado.'
        );
    }

    /**
     * Ausência de N+1 (D-08) — a montagem do badge não pode escalar com o
     * número de empresas. Compara dois cenários (2 vs. 6 empresas com
     * contrato) e filtra a contagem pelas queries que TOCAM
     * `contrato_assinaturas` — mesmo padrão de
     * `RelatorioBonificacaoEmpresasTest::leitura_do_detalhe_por_empresa_e_uma_query_so_com_tres_profissionais()`
     * (Phase 123), que filtra por tabela em vez de travar a contagem TOTAL
     * da resposta.
     *
     * ⚠️ Contagem TOTAL da resposta NÃO é o critério: medido nesta task, a
     * listagem já tem um N+1 PRÉ-EXISTENTE e alheio a este plano — os
     * accessors `Company::getAdmanAccountIdAttribute()`/`getMlStoreIdAttribute()`
     * (consumidos no payload por `adman_account_id`/`ml_store_id`) fazem 2
     * queries em `company_marketplaces` POR EMPRESA. Travar a asserção na
     * contagem total faria este teste falhar por um problema que não é do
     * badge de contrato — fora do escopo desta task (ver deferred-items.md
     * da Fase 131). O que esta task garante é que a QUERY DO BADGE
     * especificamente não escala.
     */
    public function test_contagem_de_queries_de_contrato_assinaturas_nao_escala_com_numero_de_empresas(): void
    {
        $servico = $this->servicoComContrato();
        $admin   = $this->admin();

        $criarEmpresaComContrato = function (string $nome) use ($servico): void {
            $empresa = $this->empresa(['name' => $nome]);
            $this->vincularServico($empresa, $servico);
            ContratoAssinatura::factory()->create([
                'company_id' => $empresa->id,
                'servico_id' => $servico->id,
                'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
                'enviado_em' => now()->subDay(),
            ]);
        };

        // Cenário 1: 2 empresas com contrato.
        $criarEmpresaComContrato('Empresa N+1 A0');
        $criarEmpresaComContrato('Empresa N+1 A1');

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('comercial.empresas.listagem'))->assertOk();
        $queriesDaTabelaCom2 = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'contrato_assinaturas'))
            ->count();
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Cenário 2: mais 4 empresas com contrato (total 6).
        $criarEmpresaComContrato('Empresa N+1 B0');
        $criarEmpresaComContrato('Empresa N+1 B1');
        $criarEmpresaComContrato('Empresa N+1 B2');
        $criarEmpresaComContrato('Empresa N+1 B3');

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('comercial.empresas.listagem'))->assertOk();
        $queriesDaTabelaCom6 = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'contrato_assinaturas'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $queriesDaTabelaCom2,
            'A montagem do badge deveria ser 1 única query em contrato_assinaturas com 2 empresas.'
        );
        $this->assertSame(
            $queriesDaTabelaCom2,
            $queriesDaTabelaCom6,
            'A contagem de queries em contrato_assinaturas não deveria escalar com o número de empresas com contrato — a montagem do badge deve ser uma query única por página, não uma por linha.'
        );
    }
}
