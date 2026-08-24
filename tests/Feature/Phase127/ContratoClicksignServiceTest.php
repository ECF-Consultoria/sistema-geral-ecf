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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    // ─── Testes 12+ (quick 260821-l8n) — serviço duplicado para de perder dado ───

    /**
     * Incidente Mons Bike (deal HubSpot 63836845208): dois `ContratoServico`
     * ativos do MESMO serviço. Antes da correção, o primeiro congelava
     * sozinho seu valor e o segundo caía em `ja_em_andamento` sem aviso —
     * `ok: true`, dado perdido em silêncio.
     */
    #[Test]
    public function dois_contratos_servico_do_mesmo_servico_nao_geram_nenhum_contrato_e_ok_deixa_de_ser_true(): void
    {
        Log::spy();

        $company = $this->companyCompleta();
        $servico = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $cs1 = $this->contratoServicoAtivo($company, $servico, ['valor_contratado' => 5500, 'hubspot_line_item_id' => 'li-1']);
        $cs2 = $this->contratoServicoAtivo($company, $servico, ['valor_contratado' => 6000, 'hubspot_line_item_id' => 'li-2']);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertFalse($resultado['ok']);
        $this->assertSame([], $resultado['criados']);
        $this->assertCount(2, $resultado['pulados']);
        foreach ($resultado['pulados'] as $pulado) {
            $this->assertSame($servico->id, $pulado['servico_id']);
            $this->assertSame('servicos_duplicados', $pulado['motivo']);
        }
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $mensagem, array $contexto) use ($company, $servico, $cs1, $cs2) {
                return str_contains($mensagem, '[Clicksign]')
                    && str_contains($mensagem, 'duplicado')
                    && ($contexto['company_id'] ?? null) === $company->id
                    && ($contexto['servico_id'] ?? null) === $servico->id
                    && ($contexto['quantidade'] ?? null) === 2
                    && in_array('li-1', $contexto['hubspot_line_item_id'] ?? [], true)
                    && in_array('li-2', $contexto['hubspot_line_item_id'] ?? [], true);
            });
    }

    /**
     * Regressão zero: um serviço duplicado e OUTRO serviço normal na mesma
     * empresa — o normal continua gerando contrato, só o duplicado é
     * barrado. `ok` continua `true` porque algo FOI criado.
     */
    #[Test]
    public function servico_duplicado_nao_impede_a_criacao_do_contrato_de_outro_servico_da_mesma_empresa(): void
    {
        $company = $this->companyCompleta();
        $servicoDuplicado = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $servicoNormal = $this->servicoDeTeste(['nome' => 'Publicação de Anúncios']);
        $this->contratoServicoAtivo($company, $servicoDuplicado, ['valor_contratado' => 5500]);
        $this->contratoServicoAtivo($company, $servicoDuplicado, ['valor_contratado' => 6000]);
        $this->contratoServicoAtivo($company, $servicoNormal);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);
        $this->assertSame(
            $servicoNormal->id,
            ContratoAssinatura::where('company_id', $company->id)->firstOrFail()->servico_id
        );

        $pulados = collect($resultado['pulados']);
        $this->assertCount(2, $pulados->where('motivo', 'servicos_duplicados'));
    }

    // ─── Quick 260824-bte — pagamento escalonado: as fases viram UM contrato só ───

    /**
     * Reproduz a Mons Bike (company_id=431 em produção, medido em
     * 2026-08-24): 2 `ContratoServico` do MESMO serviço, 3 parcelas de
     * R$ 5.500 (`P3M`, sem data de início — a fase já em vigor) + 9 de
     * R$ 6.000 (`P9M`, início `2026-12-01`). A ORDEM da entrada não importa
     * (helper cria a fase P9M primeiro, de propósito) — quem ordena é a
     * data de início.
     */
    #[Test]
    public function duas_fases_do_mesmo_servico_com_ordem_derivavel_geram_um_unico_contrato_com_as_duas_fases_no_snapshot(): void
    {
        $company = $this->companyCompleta();
        $servico = $this->servicoDeTeste(['nome' => 'Gestão de Ads (Mons Bike)']);

        // Ordem de entrada INVERTIDA de propósito: a fase de 9 parcelas
        // (com data de início) é criada ANTES da fase de 3 parcelas (sem
        // data de início) — a ordem no snapshot final tem que sair certa
        // mesmo assim.
        $this->contratoServicoAtivo($company, $servico, [
            'valor_contratado'          => 6000,
            'hubspot_line_item_id'      => '58210340910',
            'hubspot_billing_period'    => 'P9M',
            'hubspot_snapshot'          => ['line_item' => ['hs_recurring_billing_start_date' => '2026-12-01']],
        ]);
        $this->contratoServicoAtivo($company, $servico, [
            'valor_contratado'          => 5500,
            'hubspot_line_item_id'      => '57973834627',
            'hubspot_billing_period'    => 'P3M',
            'hubspot_snapshot'          => ['line_item' => ['hs_recurring_billing_start_date' => '']],
        ]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);
        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->count());

        $contrato = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->firstOrFail();

        $this->assertCount(2, $contrato->servicos_snapshot);
        // Primeira fase é a de 3 parcelas de R$ 5.500 (sem data de início),
        // mesmo tendo sido criada DEPOIS no banco.
        $this->assertEqualsWithDelta(5500.0, $contrato->servicos_snapshot[0]['valor_contratado'], 0.001);
        $this->assertSame(3, $contrato->servicos_snapshot[0]['parcelas']);
        $this->assertEqualsWithDelta(6000.0, $contrato->servicos_snapshot[1]['valor_contratado'], 0.001);
        $this->assertSame(9, $contrato->servicos_snapshot[1]['parcelas']);

        Queue::assertPushed(GerarContratoAssinaturaJob::class, 1);
    }

    /**
     * Última fase SEM `hs_recurring_billing_period` (período não definido
     * no HubSpot — "as demais voltam à faixa apurada") continua congelando
     * `parcelas: null` nessa fase, sem quebrar a criação do contrato.
     */
    #[Test]
    public function ultima_fase_sem_periodo_definido_congela_parcelas_nulo_sem_quebrar(): void
    {
        $company = $this->companyCompleta();
        $servico = $this->servicoDeTeste(['nome' => 'Gestão com faixa aberta']);

        $this->contratoServicoAtivo($company, $servico, [
            'valor_contratado'       => 2250,
            'hubspot_line_item_id'   => 'li-fase-1',
            'hubspot_billing_period' => 'P2M',
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '']],
        ]);
        $this->contratoServicoAtivo($company, $servico, [
            'valor_contratado'       => 3500,
            'hubspot_line_item_id'   => 'li-fase-2',
            'hubspot_billing_period' => null,
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '2026-10-01']],
        ]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $contrato = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->firstOrFail();

        $this->assertCount(2, $contrato->servicos_snapshot);
        $this->assertSame(2, $contrato->servicos_snapshot[0]['parcelas']);
        $this->assertNull($contrato->servicos_snapshot[1]['parcelas']);
    }

    /**
     * Caminho AUTOMÁTICO (Observer, quick 260821-l8n/260824-bte): o mesmo
     * consolidado acontece sem chamada manual a `iniciarParaEmpresa()` —
     * `ContratoServico::create()` SEM `withoutEvents()` dispara
     * `ContratoServicoGatilhoObserver`, que delega para
     * `GatilhoContratoAdministrativoService::dispararSeElegivel()`, que
     * chama este MESMO serviço internamente (ponto único).
     */
    #[Test]
    public function caminho_automatico_do_observer_tambem_consolida_as_fases_num_unico_contrato(): void
    {
        $company = $this->companyCompleta();
        $servico = $this->servicoDeTeste(['nome' => 'Gestão via Observer']);

        // Os dois itens de linha nascem juntos, SEM `withoutEvents`, DENTRO
        // de um único `DB::transaction()` — exatamente como
        // `HubspotWebhookController::persistirContratos()` cria hoje. É essa
        // fronteira de commit que garante que o `created()` do PRIMEIRO
        // item, ao rodar via `DB::afterCommit()`
        // (`ContratoServicoGatilhoObserver`), já enxergue o SEGUNDO item
        // também commitado — sem o `DB::transaction()` explícito aqui, os
        // dois `create()` ficariam em transações/commits separados e o
        // teste não reproduziria o cenário real (molde idêntico ao de
        // `ReavaliacaoAutomaticaTest::servico_duplicado_criado_pelo_observer_tambem_nao_gera_contrato`,
        // Fase 128).
        DB::transaction(function () use ($company, $servico) {
            ContratoServico::create([
                'company_id'             => $company->id,
                'servico_id'             => $servico->id,
                'valor_contratado'       => 5500,
                'data_contratacao'       => '2026-01-10',
                'data_vencimento'        => '2027-01-10',
                'ativo'                  => true,
                'data_primeira_parcela'  => '2026-02-05',
                'dia_vencimento'         => 5,
                'hubspot_billing_period' => 'P3M',
                'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '']],
            ]);
            ContratoServico::create([
                'company_id'             => $company->id,
                'servico_id'             => $servico->id,
                'valor_contratado'       => 6000,
                'data_contratacao'       => '2026-01-10',
                'data_vencimento'        => '2027-01-10',
                'ativo'                  => true,
                'data_primeira_parcela'  => '2026-02-05',
                'dia_vencimento'         => 5,
                'hubspot_billing_period' => 'P9M',
                'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '2026-12-01']],
            ]);
        });

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->count());

        $contrato = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $servico->id)->firstOrFail();
        $this->assertCount(2, $contrato->servicos_snapshot);
    }
}
