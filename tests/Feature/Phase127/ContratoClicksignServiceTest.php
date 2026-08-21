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
 * Fase 127 Plano 127-06 — prova do **ponto único**
 * `ContratoClicksignService::iniciarParaEmpresa()`: recusa ANTES de qualquer
 * I/O quando falta dado (Success Criteria 1, REDE-05), cria um
 * `ContratoAssinatura` por serviço ativo com `servicos_snapshot` congelado
 * (D-06/D-10) e despacha um `GerarContratoAssinaturaJob` por contrato
 * (CLICK-02), sem gravar `enviado_em` (D-02).
 *
 * `Queue::fake()` em todo teste: o job em si já tem cobertura própria no
 * plano 127-05, aqui só interessa SE e QUANTAS vezes foi despachado.
 */
class ContratoClicksignServiceTest extends TestCase
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
            // Quick 260821-cq0 — endereço em 5 campos, todos obrigatórios.
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    /**
     * `withoutEvents`: esta suíte testa `ContratoClicksignService` DIRETO
     * (`$this->service`, instanciado à mão, fora do container), sem passar
     * pelo gate. A partir da Fase 128 (plano 05),
     * `ContratoServico::create()` ganhou um Observer próprio que TAMBÉM
     * chama `GatilhoContratoAdministrativoService::dispararSeElegivel()` —
     * criaria `ContratoAssinatura` como efeito colateral do SETUP, antes da
     * chamada explícita que cada teste está medindo. A prova do disparo
     * automático via Observer é da suíte dedicada
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

    // ─── Teste 1 (Success Criteria 1, REDE-05) ───

    #[Test]
    public function empresa_sem_email_cliente_e_recusada_sem_nenhuma_chamada_http(): void
    {
        $company = $this->companyCompleta(['email_cliente' => null]);
        $this->contratoServicoAtivo($company);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertFalse($resultado['ok']);
        $campos = collect($resultado['faltando'])->pluck('campo')->all();
        $this->assertContains('email_cliente', $campos);
        $this->assertSame([], $resultado['criados']);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, ContratoAssinatura::count());
    }

    // ─── Teste 2 ───

    #[Test]
    public function empresa_com_cnpj_invalido_ou_sem_nome_contato_e_recusada(): void
    {
        $company = $this->companyCompleta(['cnpj' => '123']);
        $this->contratoServicoAtivo($company);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertFalse($resultado['ok']);
        $campos = collect($resultado['faltando'])->pluck('campo')->all();
        $this->assertContains('cnpj', $campos);

        $company2 = $this->companyCompleta(['nome_contato' => null]);
        $this->contratoServicoAtivo($company2);

        $resultado2 = $this->service->iniciarParaEmpresa($company2);

        $this->assertFalse($resultado2['ok']);
        $campos2 = collect($resultado2['faltando'])->pluck('campo')->all();
        $this->assertContains('nome_contato', $campos2);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, ContratoAssinatura::count());
    }

    // ─── Teste 3 (D-01/D-21) ───

    #[Test]
    public function empresa_completa_com_dois_servicos_ativos_gera_dois_contratos_e_dois_jobs(): void
    {
        $company = $this->companyCompleta();
        $servico1 = $this->servicoDeTeste(['nome' => 'Gestão de Tráfego']);
        $servico2 = $this->servicoDeTeste(['nome' => 'Publicação de Anúncios']);
        $this->contratoServicoAtivo($company, $servico1);
        $this->contratoServicoAtivo($company, $servico2);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(2, $resultado['criados']);
        $this->assertSame(2, ContratoAssinatura::where('company_id', $company->id)->count());
        $this->assertSame(
            2,
            ContratoAssinatura::where('company_id', $company->id)
                ->where('status', ContratoAssinatura::STATUS_RASCUNHO)
                ->count()
        );

        Queue::assertPushed(GerarContratoAssinaturaJob::class, 2);
    }

    // ─── Teste 4 (D-10) ───

    #[Test]
    public function cada_contrato_criado_tem_snapshot_congelado_com_um_unico_item_do_seu_proprio_servico(): void
    {
        $company = $this->companyCompleta();
        $servico1 = $this->servicoDeTeste(['nome' => 'Gestão de Tráfego']);
        $servico2 = $this->servicoDeTeste(['nome' => 'Publicação de Anúncios']);
        $cs1 = $this->contratoServicoAtivo($company, $servico1, ['valor_contratado' => 100]);
        $cs2 = $this->contratoServicoAtivo($company, $servico2, ['valor_contratado' => 200]);

        $this->service->iniciarParaEmpresa($company);

        $contrato1 = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico1->id)->firstOrFail();
        $contrato2 = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico2->id)->firstOrFail();

        $this->assertCount(1, $contrato1->servicos_snapshot);
        $this->assertSame($servico1->nome, $contrato1->servicos_snapshot[0]['servico']);
        $this->assertEqualsWithDelta(100.0, $contrato1->servicos_snapshot[0]['valor_contratado'], 0.001);
        $this->assertSame('2026-01-10', $contrato1->servicos_snapshot[0]['data_contratacao']);
        $this->assertSame('2027-01-10', $contrato1->servicos_snapshot[0]['data_vencimento']);

        $this->assertCount(1, $contrato2->servicos_snapshot);
        $this->assertSame($servico2->nome, $contrato2->servicos_snapshot[0]['servico']);
        $this->assertEqualsWithDelta(200.0, $contrato2->servicos_snapshot[0]['valor_contratado'], 0.001);

        // Congelamento: mudar o valor na origem DEPOIS não afeta o snapshot já gravado.
        $cs1->update(['valor_contratado' => 999999]);
        $contrato1->refresh();

        $this->assertEqualsWithDelta(100.0, $contrato1->servicos_snapshot[0]['valor_contratado'], 0.001);
    }

    // ─── Teste 4b (Quick 260819-guy) — dia_vencimento/data_primeira_parcela também congelam ───

    #[Test]
    public function snapshot_congela_dia_vencimento_e_data_primeira_parcela_do_servico(): void
    {
        $company = $this->companyCompleta();
        $servico = $this->servicoDeTeste();
        $cs = $this->contratoServicoAtivo($company, $servico, [
            'dia_vencimento'        => 15,
            'data_primeira_parcela' => '2026-02-05',
        ]);

        $this->service->iniciarParaEmpresa($company);

        $contrato = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->firstOrFail();

        $this->assertSame(15, $contrato->servicos_snapshot[0]['dia_vencimento']);
        $this->assertSame('2026-02-05', $contrato->servicos_snapshot[0]['data_primeira_parcela']);

        // Congelamento: mudar o dado na origem DEPOIS não afeta o snapshot.
        $cs->update(['dia_vencimento' => 28, 'data_primeira_parcela' => '2099-12-31']);
        $contrato->refresh();

        $this->assertSame(15, $contrato->servicos_snapshot[0]['dia_vencimento']);
        $this->assertSame('2026-02-05', $contrato->servicos_snapshot[0]['data_primeira_parcela']);
    }

    // ─── Teste 5 ───

    #[Test]
    public function contrato_servico_inativo_e_ignorado(): void
    {
        $company = $this->companyCompleta();
        $servicoAtivo = $this->servicoDeTeste(['nome' => 'Ativo']);
        $servicoInativo = $this->servicoDeTeste(['nome' => 'Inativo']);
        $this->contratoServicoAtivo($company, $servicoAtivo);
        $this->contratoServicoAtivo($company, $servicoInativo, ['ativo' => false]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);
        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->count());
        $this->assertSame(
            $servicoAtivo->id,
            ContratoAssinatura::where('company_id', $company->id)->firstOrFail()->servico_id
        );
    }

    // ─── Teste 6 (DADOS-06) ───

    #[Test]
    public function prazo_e_lembrete_sao_gravados_quando_informados_e_ficam_null_por_padrao(): void
    {
        $company = $this->companyCompleta();
        $this->contratoServicoAtivo($company);

        $this->service->iniciarParaEmpresa($company, prazoDias: 10, lembreteDias: 2);

        $contrato = ContratoAssinatura::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(10, $contrato->prazo_dias);
        $this->assertSame(2, $contrato->lembrete_dias);

        // Quick 260819-guy — precisa ser um CNPJ com dígito verificador
        // VÁLIDO: este trecho exige que a empresa passe pelo gate inteiro.
        $company2 = $this->companyCompleta(['cnpj' => '98.765.432/0001-98']);
        $this->contratoServicoAtivo($company2);

        $this->service->iniciarParaEmpresa($company2);

        $contrato2 = ContratoAssinatura::where('company_id', $company2->id)->firstOrFail();
        $this->assertNull($contrato2->prazo_dias);
        $this->assertNull($contrato2->lembrete_dias);
    }

    // ─── Teste 7 ───

    #[Test]
    public function empresa_completa_sem_nenhum_servico_ativo_e_recusada_sem_io(): void
    {
        $company = $this->companyCompleta();
        // Nenhum ContratoServico criado.

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertFalse($resultado['ok']);
        $campos = collect($resultado['faltando'])->pluck('campo')->all();
        $this->assertContains('contratos_servico', $campos);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, ContratoAssinatura::count());
    }

    // ─── Teste 8 ───

    #[Test]
    public function retorno_tem_forma_estavel_com_chaves_fixas(): void
    {
        $company = $this->companyCompleta();
        $this->contratoServicoAtivo($company);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertArrayHasKey('ok', $resultado);
        $this->assertArrayHasKey('faltando', $resultado);
        $this->assertArrayHasKey('criados', $resultado);
        $this->assertArrayHasKey('pulados', $resultado);

        $companyIncompleta = $this->companyCompleta(['cnpj' => '98.765.432/0001-10', 'email_cliente' => null]);
        $this->contratoServicoAtivo($companyIncompleta);

        $resultado2 = $this->service->iniciarParaEmpresa($companyIncompleta);

        $this->assertArrayHasKey('ok', $resultado2);
        $this->assertArrayHasKey('faltando', $resultado2);
        $this->assertArrayHasKey('criados', $resultado2);
        $this->assertArrayHasKey('pulados', $resultado2);
    }

    // ─── Teste 9 (Quick 260820-my3) ───

    /**
     * O incidente que originou o quick: o job foi para a fila `default`,
     * atrás de dezenas de `SyncMlAcervoCompanyJob` em deadlock, e o contrato
     * ficou parado mais de uma hora sem nada criado. Prova as duas coisas na
     * MESMA chamada: o job vai para a fila `high`, e o delay escalonado por
     * serviço (bucket de 1 envelope/min da Clicksign) continua intacto — a
     * fila é só POR ONDE o job entra, não tem relação com o espaçamento.
     */
    #[Test]
    public function job_e_despachado_na_fila_high_preservando_o_delay_escalonado(): void
    {
        $company = $this->companyCompleta();
        $servico1 = $this->servicoDeTeste(['nome' => 'Gestão de Tráfego']);
        $servico2 = $this->servicoDeTeste(['nome' => 'Publicação de Anúncios']);
        $this->contratoServicoAtivo($company, $servico1);
        $this->contratoServicoAtivo($company, $servico2);

        $this->service->iniciarParaEmpresa($company);

        Queue::assertPushedOn('high', GerarContratoAssinaturaJob::class);
        Queue::assertPushed(GerarContratoAssinaturaJob::class, 2);

        // Delay escalonado (`$i * 5`) preservado: o primeiro contrato do
        // laço tem delay 0s, o segundo 5s — mesma regra de sempre, só a
        // fila mudou. Ordem de push == ordem do laço, então lê direto pelo
        // índice, sem reordenar.
        $pushados = collect(Queue::pushedJobs()[GerarContratoAssinaturaJob::class] ?? [])->pluck('job');

        $this->assertCount(2, $pushados);
        $delayPrimeiro = $pushados[0]->delay;
        $delaySegundo  = $pushados[1]->delay;

        $this->assertNotNull($delayPrimeiro);
        $this->assertNotNull($delaySegundo);
        $this->assertTrue($delayPrimeiro->timestamp < $delaySegundo->timestamp);
    }
}
