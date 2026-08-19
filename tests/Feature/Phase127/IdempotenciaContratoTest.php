<?php

namespace Tests\Feature\Phase127;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Contratos\ContratoDadosMinimosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 127 Plano 127-06 (Success Criteria 5) — a garantia de que
 * `iniciarParaEmpresa()` chamado duas vezes para a mesma empresa não cria
 * dois envelopes. A garantia REAL é a constraint composta (empresa+serviço,
 * D-06 da Fase 127-01) capturada por SQLSTATE 23000, não o guard de leitura
 * (`emAndamentoDoServico()`), que é só UX — dois workers concorrentes podem
 * passar pelo guard quase juntos.
 */
class IdempotenciaContratoTest extends TestCase
{
    use RefreshDatabase;

    private ContratoClicksignService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Signatarios fixos da ECF (D-08) configurados: sem isto a guarda de
        // configuracao acrescentada no gate do plano 127-07 bloqueia ANTES de
        // qualquer chamada — e o bloqueio e o comportamento correto, provado em
        // ConfiguracaoEcfBloqueiaTest. Aqui queremos o caminho feliz.
        config(["services.clicksign.signatarios_ecf" => [
            ["nome" => "Socio Um", "email" => "socio1@example.com", "papel" => "contratada"],
            ["nome" => "Socio Dois", "email" => "socio2@example.com", "papel" => "contratada"],
            ["nome" => "Comercial", "email" => "comercial@example.com", "papel" => "testemunha"],
        ]]);

        $this->service = new ContratoClicksignService(new ContratoDadosMinimosService());

        Http::fake();
        Queue::fake();
    }

    private function servicoDeTeste(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'          => 'Serviço de teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ], $overrides));
    }

    private function companyCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'email_cliente' => 'cliente@empresa.com.br',
            'cnpj'          => '12.345.678/0001-95',
            'nome_contato'  => 'Fulano de Tal',
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'razao_social'  => 'Fulano de Tal LTDA',
            'endereco'      => 'Rua de Teste, 123',
        ], $overrides));
    }

    /**
     * `withoutEvents`: esta suíte testa `ContratoClicksignService` DIRETO
     * (`$this->service`, instanciado à mão, fora do container), sem passar
     * pelo gate. A partir da Fase 128 (plano 05),
     * `ContratoServico::create()` ganhou um Observer próprio que TAMBÉM
     * chama `GatilhoContratoAdministrativoService::dispararSeElegivel()` —
     * criaria `ContratoAssinatura` como efeito colateral do SETUP, antes da
     * chamada explícita que cada teste está medindo (e quebraria a própria
     * garantia desta suíte de idempotência, com uma constraint violation).
     * A prova do disparo automático via Observer é da suíte dedicada
     * `ReavaliacaoAutomaticaTest` (128-05).
     */
    private function contratoServicoAtivo(Company $company, ?Servico $servico = null, array $overrides = []): ContratoServico
    {
        $servico = $servico ?? $this->servicoDeTeste();

        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 150.5,
            'data_contratacao' => '2026-01-10',
            'data_vencimento'  => '2027-01-10',
            'ativo'            => true,
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'data_primeira_parcela' => '2026-02-05',
            'dia_vencimento'        => 5,
        ], $overrides)));
    }

    // ─── Teste 9 ───

    #[Test]
    public function chamar_duas_vezes_nao_duplica_contratos_nem_jobs(): void
    {
        $company = $this->companyCompleta();
        $servico1 = $this->servicoDeTeste(['nome' => 'Serviço 1']);
        $servico2 = $this->servicoDeTeste(['nome' => 'Serviço 2']);
        $this->contratoServicoAtivo($company, $servico1);
        $this->contratoServicoAtivo($company, $servico2);

        $this->service->iniciarParaEmpresa($company);
        $resultado2 = $this->service->iniciarParaEmpresa($company);

        $this->assertSame(2, ContratoAssinatura::where('company_id', $company->id)->count());
        Queue::assertPushed(GerarContratoAssinaturaJob::class, 2);

        // Segunda chamada não lança exceção e reporta os dois como pulados.
        $this->assertTrue($resultado2['ok']);
        $this->assertCount(2, $resultado2['pulados']);
        $this->assertSame([], $resultado2['criados']);
    }

    // ─── Teste 10 ───

    #[Test]
    public function a_garantia_real_e_a_constraint_nao_o_guard_de_leitura(): void
    {
        $company = $this->companyCompleta();
        $servico = $this->servicoDeTeste();
        $this->contratoServicoAtivo($company, $servico);

        // Cria manualmente um contrato "em rascunho" para (empresa, serviço)
        // — simula o cenário que o guard de leitura deveria pegar.
        ContratoAssinatura::factory()->comSnapshot()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
        ]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertSame([], $resultado['criados']);
        $this->assertCount(1, $resultado['pulados']);
        $this->assertSame($servico->id, $resultado['pulados'][0]['servico_id']);
        $this->assertSame('ja_em_andamento', $resultado['pulados'][0]['motivo']);

        // Continua havendo só 1 contrato para (empresa, serviço) — nenhum
        // segundo foi criado.
        $this->assertSame(
            1,
            ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->count()
        );
        Queue::assertNothingPushed();
    }

    // ─── Teste 11 ───

    #[Test]
    public function o_catch_reconhece_a_violacao_pelo_sqlstate_nao_pelo_errorinfo_do_mysql(): void
    {
        $caminho = app_path('Services/Clicksign/ContratoClicksignService.php');
        $this->assertFileExists($caminho);

        $linhas = file(app_path('Services/Clicksign/ContratoClicksignService.php'));
        $codigoSemComentarios = collect($linhas)
            ->reject(fn (string $linha) => preg_match('/^\s*[\/*]/', $linha) === 1)
            ->implode('');

        $this->assertStringContainsString('23000', $codigoSemComentarios);
        $this->assertStringNotContainsString('errorInfo', $codigoSemComentarios);
        $this->assertStringContainsString('QueryException', $codigoSemComentarios);
    }
}
