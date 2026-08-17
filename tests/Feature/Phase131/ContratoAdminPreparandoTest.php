<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quick 260816-d72 (UI-06/D-05) — prova os três recortes da regra
 * `ContratoAssinatura::estaPreparando()` (status, envelope, janela) e o
 * invariante do resumo de 7 contagens (D-04) na lista (`index()`) e no
 * detalhe (`show()`) do `ContratoAdminController`.
 *
 * Mesma disciplina do resto da Fase 131: conferência por reconsulta às
 * props Inertia + banco, nunca por stdout.
 */
class ContratoAdminPreparandoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem do resto da Fase 131: sem isto, o Observer da
        // Fase 128 poderia reavaliar o gate como efeito colateral do setup
        // desta suíte (ela só chama index()/show(), nunca gerarContrato()).
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (preparando)'): Servico
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

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    /**
     * `withoutEvents`: sem isto o `ContratoServicoGatilhoObserver` (Fase
     * 128) dispara como efeito colateral do setup, antes da chamada
     * explícita que cada teste está medindo — mesmo cuidado já aplicado em
     * `ContratoAdminDetalheTest`.
     */
    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]));
    }

    /**
     * Recua `created_at` de um contrato já salvo por QUERY BUILDER, nunca
     * por `save()`/`saveQuietly()` — o plano autoriza este atalho aqui
     * porque só mexe em `created_at`; as colunas espelho
     * `company_id_em_andamento`/`servico_id_em_andamento` já foram gravadas
     * pelo hook `saving` da criação anterior e não são tocadas por este
     * update. Não replicar em código de produção (ver aviso em
     * `ContratoAssinatura::booted()`).
     */
    private function recuarCriacao(ContratoAssinatura $contrato, int $minutos): ContratoAssinatura
    {
        ContratoAssinatura::where('id', $contrato->id)->update([
            'created_at' => now()->subMinutes($minutos),
        ]);

        return $contrato->refresh();
    }

    // ─── Caso 1 — show(): rascunho, sem envelope, criado agora → preparando true ───

    public function test_show_contrato_recem_criado_sem_envelope_esta_preparando(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Preparando Recem Criada']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['contratos'][0]['preparando']);
    }

    // ─── Caso 2 — show(): rascunho, sem envelope, criado há 30min (fora da janela) → false ───

    public function test_show_contrato_rascunho_parado_fora_da_janela_nao_esta_preparando(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Rascunho Parado']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);
        $this->recuarCriacao($contrato, 30);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['contratos'][0]['preparando']);
    }

    // ─── Caso 3 — show(): rascunho COM envelope, criado agora → false (rascunho de verdade, D-02) ───

    public function test_show_contrato_rascunho_com_envelope_montado_nao_esta_preparando(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Rascunho Com Envelope']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => 'env-de-teste-abc123',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['contratos'][0]['preparando']);
    }

    // ─── Caso 4 — show(): status diferente de rascunho, criado agora → false ───

    public function test_show_contrato_em_erro_nao_esta_preparando(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Contrato Em Erro']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_ERRO,
            'clicksign_envelope_id' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['contratos'][0]['preparando']);
    }

    // ─── Caso 5 — index(): linha recém-criada preparando true, linha antiga preparando false ───

    public function test_index_marca_preparando_por_linha_conforme_a_regra(): void
    {
        $admin   = $this->admin();
        $servico = $this->servicoComContrato();

        $empresaNova = $this->empresa(['name' => 'Empresa Lista Preparando']);
        $this->vincularServico($empresaNova, $servico);
        ContratoAssinatura::factory()->create([
            'company_id'            => $empresaNova->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);

        $empresaAntiga = $this->empresa(['name' => 'Empresa Lista Rascunho Antigo']);
        $this->vincularServico($empresaAntiga, $servico);
        $contratoAntigo = ContratoAssinatura::factory()->create([
            'company_id'            => $empresaAntiga->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);
        $this->recuarCriacao($contratoAntigo, 30);

        $response = $this->actingAs($admin)->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $linhas = collect($props['linhas']['data']);

        $linhaNova = $linhas->firstWhere('company_id', $empresaNova->id);
        $linhaAntiga = $linhas->firstWhere('company_id', $empresaAntiga->id);

        $this->assertNotNull($linhaNova);
        $this->assertNotNull($linhaAntiga);
        $this->assertTrue($linhaNova['preparando']);
        $this->assertFalse($linhaAntiga['preparando']);
    }

    // ─── Caso 6 — index(): invariante D-04/D-F — resumo continua com 7 chaves, "preparando" conta em rascunho ───

    public function test_index_resumo_continua_com_7_chaves_e_preparando_conta_em_rascunho(): void
    {
        $admin   = $this->admin();
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Resumo Com Preparando']);
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(7, $props['resumo']);
        $this->assertSame(ContratoAssinatura::STATUS_TODOS, array_keys($props['resumo']));
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_RASCUNHO)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_RASCUNHO],
            '"preparando" é sub-estado de rascunho — continua contando dentro dele, nunca em chave própria.'
        );
        // sem_contrato_count não muda por causa de "preparando" — aqui não
        // há par sem contrato, então continua zero.
        $this->assertSame(0, $props['sem_contrato_count']);
    }

    // ─── Caso 7 — index(): par sem contrato traz preparando false explícito (nunca undefined) ───

    public function test_index_par_sem_contrato_traz_preparando_false(): void
    {
        $admin   = $this->admin();
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Contrato Preparando']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $linhas = collect($props['linhas']['data']);

        $linha = $linhas->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertArrayHasKey('preparando', $linha);
        $this->assertFalse($linha['preparando']);
    }
}
