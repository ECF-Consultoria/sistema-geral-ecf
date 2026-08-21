<?php

namespace Tests\Feature\Phase128;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Comercial\PendenciasComerciaisService;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 128 Plano 05 (D-04) — prova de que a empresa que ficou
 * `aguardando_comercial` é reavaliada SOZINHA quando a pendência some, e de
 * que a proteção contra laço (4 camadas, ver docblocks dos dois Observers)
 * segura o número de invocações do gate num teto finito e pequeno.
 *
 * Diferente de `GatilhoContratoPendenciaTest` (128-03): aquela suíte testa
 * `GatilhoContratoAdministrativoService` isoladamente, com o setup usando
 * `withoutEvents()` de propósito. Aqui os dois Observers ficam LIGADOS —
 * é exatamente o que está sendo provado.
 */
class ReavaliacaoAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spy sempre instalado: delega 100% do comportamento para a
        // implementação real (nunca substitui), só incrementa um contador
        // estático. Os testes que não olham a contagem não são afetados.
        $this->app->bind(GatilhoContratoAdministrativoService::class, function ($app) {
            return new SpyGatilhoContratoAdministrativoService(
                $app->make(PendenciasComerciaisService::class),
                $app->make(ContratoClicksignService::class),
                // Fase 132: o interruptor de emissão (D-07) passou a ser
                // checado também neste caminho — o gatilho automático gerava
                // contrato por fora dele (medido em produção em 2026-08-18).
                $app->make(\App\Services\Clicksign\CongelamentoEmissaoService::class),
            );
        });

        SpyGatilhoContratoAdministrativoService::$invocacoes = 0;
    }

    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function fakeSignatariosEcf(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Socio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Socio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ]]);
    }

    private function servicoQueExige(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'           => 'Serviço P128-05 '.uniqid(),
            'valor_padrao'   => 1000,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ], $overrides));
    }

    private function polos(): Servico
    {
        return Servico::where('nome', 'Polos')->first() ?? Servico::create([
            'nome'           => 'Polos',
            'valor_padrao'   => 0,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_OUTROS,
            'exige_contrato' => false,
        ]);
    }

    private function companyCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'email_cliente' => 'cliente@empresa.com.br',
            'cnpj'          => '12.345.678/0001-95',
            'nome_contato'  => 'Fulano de Tal',
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'razao_social'  => 'Fulano de Tal LTDA',
            // Quick 260821-cq0 — endereço em 5 campos, todos obrigatórios.
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    private function contratoServicoAtivo(Company $company, Servico $servico, array $overrides = []): ContratoServico
    {
        return ContratoServico::create(array_merge([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 150.5,
            'data_contratacao' => '2026-01-10',
            'data_vencimento'  => '2027-01-10',
            'ativo'            => true,
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'data_primeira_parcela' => '2026-02-05',
            'dia_vencimento'        => 5,
        ], $overrides));
    }

    // ─── Reavaliação automática ao completar o dado que faltava ───────────

    #[Test]
    public function completar_nome_contato_dispara_contrato_sem_chamada_manual_ao_gate(): void
    {
        $this->fakeSignatariosEcf();
        Http::fake();
        Queue::fake();

        $company = $this->companyCompleta(['nome_contato' => null]);
        $servico = $this->servicoQueExige();
        $this->contratoServicoAtivo($company, $servico);

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());

        // Comercial corrige o dado — nenhuma chamada manual ao gate aqui.
        $company->update(['nome_contato' => 'Fulano de Tal']);

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->count());
    }

    // ─── Replay de webhook (hubspot_notas/hubspot_snapshot) não reavalia ──

    #[Test]
    public function editar_hubspot_notas_e_snapshot_nao_chama_o_gate(): void
    {
        $company = $this->companyCompleta();

        SpyGatilhoContratoAdministrativoService::$invocacoes = 0;

        $company->update([
            'hubspot_notas'    => [['id' => 1, 'body' => 'nota de teste', 'timestamp' => now()->toIso8601String()]],
            'hubspot_snapshot' => ['deal' => ['algum_campo' => 'valor']],
        ]);

        $this->assertSame(0, SpyGatilhoContratoAdministrativoService::$invocacoes);
    }

    // ─── Contrato já em andamento: reavaliação não duplica ─────────────────

    #[Test]
    public function empresa_com_contrato_em_andamento_gravar_email_de_novo_nao_duplica(): void
    {
        $this->fakeSignatariosEcf();
        Http::fake();
        Queue::fake();

        $company = $this->companyCompleta();
        $servico = $this->servicoQueExige();
        $this->contratoServicoAtivo($company, $servico);

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->count());

        $company->update(['email_cliente' => 'novo-email@empresa.com.br']);

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    // ─── Serviço novo numa empresa já completa gera contrato só para ele ──

    #[Test]
    public function adicionar_servico_novo_a_empresa_ja_completa_gera_contrato_so_para_ele(): void
    {
        $this->fakeSignatariosEcf();
        Http::fake();
        Queue::fake();

        $company = $this->companyCompleta();
        $polos = $this->polos();
        $this->contratoServicoAtivo($company, $polos);

        // Empresa só com Polos: isenta, zero contrato até aqui.
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());

        $gestao = $this->servicoQueExige(['nome' => 'Gestão']);
        $this->contratoServicoAtivo($company, $gestao);

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $gestao->id)->count());
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $polos->id)->count());
    }

    // ─── Empresa só com Polos: nenhuma edição de campo-gatilho gera contrato ─

    #[Test]
    public function empresa_so_com_polos_edicao_de_campo_gatilho_nunca_gera_contrato(): void
    {
        Http::fake();

        $company = $this->companyCompleta(['nome_contato' => null]);
        $this->contratoServicoAtivo($company, $this->polos());

        $company->update(['nome_contato' => 'Fulano de Tal']);
        $company->update(['email_cliente' => 'outro@empresa.com.br']);
        $company->update(['cnpj' => '98.765.432/0001-10']);

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
        Http::assertNothingSent();
    }

    // ─── Anti-laço: teto finito de invocações num fluxo completo real ─────

    /**
     * Camadas de proteção contra o laço Observer → disparo → gravação →
     * Observer, para diagnóstico caso este teto estoure:
     * 1. `CompanyGatilhoContratoObserver::updated()` — `wasChanged()` restrito
     *    à lista fixa de campos (não entra em jogo neste teste: `store()`
     *    só faz `Company::create()`, que dispara `created`, não `updated`).
     * 2. `ContratoServicoGatilhoObserver::created()` — `DB::afterCommit()`,
     *    UMA reavaliação só, depois que a transação do `store()` fecha.
     * 3. A chamada explícita do próprio `ComercialController::store()`
     *    (plano 04), fora da transação.
     * 4. `GatilhoContratoAdministrativoService::avaliar()` —
     *    `ContratoAssinatura::emAndamentoDaEmpresa()` corta qualquer
     *    disparo redundante ANTES de criar um segundo contrato.
     *
     * Resultado esperado: exatamente 2 invocações (a do Observer via
     * afterCommit + a explícita do controller) — nunca crescente.
     */
    #[Test]
    public function fluxo_completo_de_cadastro_pelo_comercial_conta_invocacoes_finitas(): void
    {
        $this->fakeSignatariosEcf();
        Bus::fake();

        $servico = $this->servicoQueExige(['nome' => 'Gestão Anti-Laço']);

        $response = $this->actingAs($this->userAdmin())->post('/comercial/empresas', [
            'nome'          => 'Empresa Anti Laco 128-05',
            'cnpj'          => '11.222.333/0001-44',
            'email_cliente' => 'anti-laco@empresagate128.com.br',
            'nome_contato'  => 'Fulano de Tal',
            'servicos'      => [
                ['servico_id' => $servico->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Anti Laco 128-05')->firstOrFail();

        // Quick 260819-guy — desde 2026-08-19, razão social/endereço (por
        // empresa) e data da 1ª parcela/dia de vencimento (por serviço) são
        // OBRIGATÓRIOS em ContratoDadosMinimosService::faltantes(). O
        // Comercial (`ComercialController::store()`) nunca coleta esses 4
        // campos — são território exclusivo do Administrativo (ADM-01). O
        // gate ainda É chamado (é o que este teste mede, ver assert abaixo),
        // só que agora sempre recusa no 2º portão por dado faltando — zero
        // ContratoAssinatura nasce até o Administrativo completar o
        // cadastro. O teto de invocações continua o ponto principal desta
        // suíte, não mudou.
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, SpyGatilhoContratoAdministrativoService::$invocacoes);
        $this->assertLessThanOrEqual(2, SpyGatilhoContratoAdministrativoService::$invocacoes);
    }

    // ─── Serviço duplicado também barra o caminho AUTOMÁTICO (quick 260821-l8n) ───

    /**
     * Incidente Mons Bike (deal HubSpot 63836845208) reproduzido pelo
     * caminho automático: os dois `ContratoServico` do mesmo serviço nascem
     * SEM chamada manual nenhuma — é o `ContratoServicoGatilhoObserver`
     * quem dispara. Antes da correção, o `created()` do primeiro item
     * congelava sozinho o valor errado; a guarda em
     * `ContratoClicksignService::iniciarParaEmpresa()` precisa valer
     * também aqui, não só quando alguém aperta o botão da tela.
     */
    #[Test]
    public function servico_duplicado_criado_pelo_observer_tambem_nao_gera_contrato(): void
    {
        $this->fakeSignatariosEcf();
        Http::fake();
        Queue::fake();

        $company = $this->companyCompleta();
        $servico = $this->servicoQueExige(['nome' => 'Gestão de Ads Duplicado']);

        // Os dois itens de linha (pagamento escalonado) nascem juntos, sem
        // `withoutEvents` — exatamente como `HubspotWebhookController::
        // persistirContratos()` cria hoje (dentro de um `DB::transaction()`
        // único, ver linha 525 do controller). É essa fronteira de commit
        // que garante que o `created()` do PRIMEIRO item, ao rodar via
        // `DB::afterCommit()`, já enxergue o SEGUNDO item também commitado —
        // sem o `DB::transaction()` explícito aqui, os dois `create()`
        // ficariam em transações/commits separados e o teste não reproduziria
        // o incidente real.
        DB::transaction(function () use ($company, $servico) {
            $this->contratoServicoAtivo($company, $servico, ['valor_contratado' => 5500]);
            $this->contratoServicoAtivo($company, $servico, ['valor_contratado' => 6000]);
        });

        $this->assertSame(
            0,
            ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->count()
        );
    }
}

/**
 * Spy que DELEGA 100% do comportamento para a implementação real — nunca
 * substitui. Usado só para provar, por contagem, que a proteção contra laço
 * segura o número de invocações num teto finito (T-128-11).
 */
class SpyGatilhoContratoAdministrativoService extends GatilhoContratoAdministrativoService
{
    public static int $invocacoes = 0;

    public function dispararSeElegivel(Company $company): array
    {
        self::$invocacoes++;

        return parent::dispararSeElegivel($company);
    }
}
