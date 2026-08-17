<?php

namespace Tests\Feature\Phase128;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano 128-03 (D-02): prova do SC3 (pendência comercial bloqueia o disparo,
 * zero I/O) e do SC0 (empresa 100% Polos nunca chega a nenhum portão) no
 * nível do fluxo — o `GatilhoContratoAdministrativoService` é o único lugar
 * que os dois pontos de entrada (plano 04) e o Observer (plano 05) vão
 * consultar.
 *
 * `Http::fake()` aqui serve SÓ para provar ausência de chamada
 * (`assertNothingSent`) — não para validar a forma de um payload real.
 */
class GatilhoContratoPendenciaTest extends TestCase
{
    use RefreshDatabase;

    private function service(): GatilhoContratoAdministrativoService
    {
        return app(GatilhoContratoAdministrativoService::class);
    }

    private function servicoQueExige(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'          => 'Serviço P128-03 '.uniqid(),
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ], $overrides));
    }

    private function polos(): Servico
    {
        $polos = Servico::where('nome', 'Polos')->first();

        // Fallback caso a suíte rode isolada sem o seed da migration 128-01.
        return $polos ?? Servico::create([
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
        ], $overrides));
    }

    /**
     * `withoutEvents`: esta suíte (128-03) testa `GatilhoContratoAdministrativoService`
     * DIRETAMENTE e de forma determinística, com a chamada explícita a
     * `dispararSeElegivel()`/`avaliar()` sendo o único disparo esperado em cada
     * cenário. A partir do plano 05, `ContratoServico::create()` passou a ter um
     * Observer próprio (`ContratoServicoGatilhoObserver::created()`) que também
     * chama o gate — o que descasaria a ordem que estes testes assumem (alguns
     * cenários criam 2 `ContratoServico` em sequência sem passar por
     * `DB::transaction()`, diferente do fluxo real dos controllers). A prova do
     * disparo automático via Observer é da suíte dedicada
     * `ReavaliacaoAutomaticaTest` (128-05) — aqui o setup permanece silencioso.
     */
    private function contratoServicoAtivo(Company $company, Servico $servico, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 150.5,
            'data_contratacao' => '2026-01-10',
            'data_vencimento'  => '2027-01-10',
            'ativo'            => true,
        ], $overrides)));
    }

    private function fakeSignatariosEcf(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Socio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Socio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ]]);
    }

    // ─── SC0: empresa só com Polos nunca é avaliada ───

    #[Test]
    public function empresa_so_com_polos_e_isenta_e_dispararseelegivel_nao_cria_nada(): void
    {
        Http::fake();
        $company = $this->companyCompleta();
        $this->contratoServicoAtivo($company, $this->polos());

        $avaliacao = $this->service()->avaliar($company);

        $this->assertSame('isento', $avaliacao['status']);
        $this->assertSame([], $avaliacao['pendencias']);

        $resultado = $this->service()->dispararSeElegivel($company);

        $this->assertSame('isento', $resultado['status']);
        $this->assertSame(0, ContratoAssinatura::count());
        Http::assertNothingSent();
    }

    // ─── Fix pós-verificação: zero ContratoServico ativo NÃO é isenção ───

    /**
     * `Collection::contains()` numa coleção vazia devolve `false` — a checagem
     * original de isenção classificava "empresa sem nenhum ContratoServico
     * ativo" como `isento`, igual a "só Polos". São casos diferentes: zero
     * serviço tem que cair no 1º portão e virar `aguardando_comercial` com a
     * pendência universal `sem_servico` (D-01), nunca `isento`. Achado
     * WARNING da revisão/verificação da Fase 128 (`GatilhoContratoAdministrativoService::avaliar()`).
     */
    #[Test]
    public function empresa_sem_nenhum_contrato_servico_ativo_fica_aguardando_comercial_nunca_isenta(): void
    {
        Http::fake();
        Bus::fake();
        $company = $this->companyCompleta();
        // Nenhum ContratoServico criado para esta empresa.

        $avaliacao = $this->service()->avaliar($company->fresh());

        $this->assertSame('aguardando_comercial', $avaliacao['status']);
        $this->assertContains('sem_servico', $avaliacao['pendencias']);

        $resultado = $this->service()->dispararSeElegivel($company->fresh());

        $this->assertSame('aguardando_comercial', $resultado['status']);
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    // ─── SC0: Gestão + Polos gera contrato só para Gestão ───

    #[Test]
    public function empresa_com_gestao_e_polos_dispara_para_gestao_e_pula_polos(): void
    {
        $this->fakeSignatariosEcf();
        Http::fake();
        Queue::fake();
        $company = $this->companyCompleta();
        $gestao = $this->servicoQueExige(['nome' => 'Gestão']);
        $polos = $this->polos();
        $this->contratoServicoAtivo($company, $gestao);
        $this->contratoServicoAtivo($company, $polos);

        $resultado = $this->service()->dispararSeElegivel($company);

        $this->assertSame('disparado', $resultado['status']);
        $this->assertTrue($resultado['resultado']['ok']);
        $pulados = collect($resultado['resultado']['pulados']);
        $this->assertTrue($pulados->contains(fn ($p) => $p['servico_id'] === $polos->id && $p['motivo'] === 'servico_isento'));
        $this->assertSame(0, ContratoAssinatura::where('servico_id', $polos->id)->count());
        $this->assertSame(1, ContratoAssinatura::where('servico_id', $gestao->id)->count());
    }

    // ─── SC3: pendência comercial bloqueia, zero I/O, zero job ───

    #[Test]
    public function empresa_sem_nome_contato_fica_aguardando_comercial_sem_contrato(): void
    {
        Http::fake();
        $company = $this->companyCompleta(['nome_contato' => null]);
        $servico = $this->servicoQueExige();
        $this->contratoServicoAtivo($company, $servico);

        $avaliacao = $this->service()->avaliar($company->fresh());

        $this->assertSame('aguardando_comercial', $avaliacao['status']);
        $this->assertContains('sem_contato', $avaliacao['pendencias']);
        $this->assertTrue($this->service()->aguardandoComercial($company->fresh()));

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    #[Test]
    public function empresa_com_pendencia_comercial_dispararseelegivel_nao_faz_nenhuma_chamada_http(): void
    {
        Bus::fake();
        Http::fake();
        $company = $this->companyCompleta(['nome_contato' => null]);
        $servico = $this->servicoQueExige();
        $this->contratoServicoAtivo($company, $servico);

        $resultado = $this->service()->dispararSeElegivel($company->fresh());

        $this->assertSame('aguardando_comercial', $resultado['status']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    // ─── Reentrância: contrato já em andamento ───

    #[Test]
    public function empresa_com_contrato_em_andamento_nao_gera_um_segundo(): void
    {
        Http::fake();
        $company = $this->companyCompleta();
        $servico = $this->servicoQueExige();
        $this->contratoServicoAtivo($company, $servico);

        ContratoAssinatura::create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $avaliacao = $this->service()->avaliar($company->fresh());
        $this->assertSame('ja_em_andamento', $avaliacao['status']);

        $resultado = $this->service()->dispararSeElegivel($company->fresh());
        $this->assertSame('ja_em_andamento', $resultado['status']);
        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    // ─── Falha no gate nunca escapa ───

    #[Test]
    public function falha_dentro_da_clicksign_devolve_status_erro_e_nao_relanca(): void
    {
        $this->fakeSignatariosEcf();
        $company = $this->companyCompleta();
        $servico = $this->servicoQueExige();
        $this->contratoServicoAtivo($company, $servico);

        $clicksignMock = \Mockery::mock(ContratoClicksignService::class);
        $clicksignMock->shouldReceive('iniciarParaEmpresa')->andThrow(new \RuntimeException('falha simulada da Clicksign'));
        $this->app->instance(ContratoClicksignService::class, $clicksignMock);

        $resultado = $this->service()->dispararSeElegivel($company->fresh());

        $this->assertSame('erro', $resultado['status']);
        $this->assertSame('falha simulada da Clicksign', $resultado['erro']);
    }
}
