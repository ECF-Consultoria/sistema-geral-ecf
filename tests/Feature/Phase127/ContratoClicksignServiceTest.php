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

    // ─── Quick 260901-gj7 — venda combinada Mercado Livre + Shopee vira UM contrato só ───

    /**
     * Helper: serviço Shopee configurado para compartilhar contrato com o
     * serviço `$dono` — o mecanismo genérico da quick 260901-gj7 (Tarefa 1),
     * nunca preenchido em produção por esta suíte (a migration não
     * preenche nada).
     */
    private function servicoJuntoCom(Servico $dono, array $overrides = []): Servico
    {
        return $this->servicoDeTeste(array_merge([
            'contrato_junto_com_servico_id' => $dono->id,
        ], $overrides));
    }

    #[Test]
    public function gestao_e_shopee_combinados_geram_um_unico_contrato_com_servico_id_do_dono_e_snapshot_dos_dois(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee']);

        $this->contratoServicoAtivo($company, $gestao, ['valor_contratado' => 100]);
        $this->contratoServicoAtivo($company, $shopee, ['valor_contratado' => 200]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $shopee->id)->count());

        $contrato = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $gestao->id)->firstOrFail();

        $this->assertCount(2, $contrato->servicos_snapshot);
        $nomesNoSnapshot = collect($contrato->servicos_snapshot)->pluck('servico')->all();
        $this->assertContains($gestao->nome, $nomesNoSnapshot);
        $this->assertContains($shopee->nome, $nomesNoSnapshot);

        Queue::assertPushed(GerarContratoAssinaturaJob::class, 1);
    }

    /**
     * O contrato combinado usa o MODELO DO DONO (D-21) — provado aqui pelo
     * `servico_id` gravado (é dele que `GerarContratoAssinaturaJob` resolve
     * `clicksignTemplateId()`, Fase 126/127-04), não do serviço combinado.
     */
    #[Test]
    public function contrato_combinado_usa_o_servico_id_e_o_modelo_do_dono(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads', 'clicksign_template_id' => 'modelo-gestao-uuid']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee', 'clicksign_template_id' => 'modelo-shopee-uuid']);

        $this->contratoServicoAtivo($company, $gestao);
        $this->contratoServicoAtivo($company, $shopee);

        $this->service->iniciarParaEmpresa($company);

        $contrato = ContratoAssinatura::where('company_id', $company->id)->firstOrFail();

        $this->assertSame($gestao->id, $contrato->servico_id);
        $this->assertSame('modelo-gestao-uuid', $contrato->servico->clicksignTemplateId());
    }

    /**
     * ⚠️ REGRESSÃO MAIS IMPORTANTE deste quick: Shopee SOZINHO (o dono
     * configurado NÃO está entre os serviços ativos desta empresa) continua
     * com contrato PRÓPRIO, usando o modelo de Shopee — é o caso que acabou
     * de entrar em produção (servico 9, modelo 5c2d8ad4) e não pode
     * regredir. O redirecionamento para o dono só vale quando o dono também
     * está ativo.
     */
    #[Test]
    public function shopee_sozinho_sem_o_dono_ativo_continua_com_contrato_proprio_e_modelo_proprio(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads', 'clicksign_template_id' => 'modelo-gestao-uuid']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee', 'clicksign_template_id' => 'modelo-shopee-uuid']);

        // Só Shopee ativo — Gestão (o dono configurado) NÃO tem
        // ContratoServico ativo nesta empresa.
        $this->contratoServicoAtivo($company, $shopee);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);

        $contrato = ContratoAssinatura::where('company_id', $company->id)->firstOrFail();

        $this->assertSame($shopee->id, $contrato->servico_id);
        $this->assertSame('modelo-shopee-uuid', $contrato->servico->clicksignTemplateId());
        $this->assertCount(1, $contrato->servicos_snapshot);
        $this->assertSame($shopee->nome, $contrato->servicos_snapshot[0]['servico']);
    }

    /** Gestão sozinha (sem Shopee ativo) — igual a hoje, sem qualquer efeito da configuração de combinação. */
    #[Test]
    public function gestao_sozinha_sem_shopee_ativo_gera_contrato_normal_igual_a_hoje(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        // Shopee existe no catálogo, configurado para juntar com Gestão, mas
        // não tem NENHUM ContratoServico ativo nesta empresa.
        $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee']);

        $this->contratoServicoAtivo($company, $gestao);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);

        $contrato = ContratoAssinatura::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($gestao->id, $contrato->servico_id);
        $this->assertCount(1, $contrato->servicos_snapshot);
    }

    /**
     * Gestão + Shopee (combinados) + Mentoria (serviço avulso, sem relação
     * de combinação nenhuma) → 2 contratos: o combinado (Gestão+Shopee) e o
     * de Mentoria, sozinho.
     */
    #[Test]
    public function gestao_shopee_e_mentoria_geram_dois_contratos_o_combinado_e_o_avulso(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee']);
        $mentoria = $this->servicoDeTeste(['nome' => 'Mentoria']);

        $this->contratoServicoAtivo($company, $gestao);
        $this->contratoServicoAtivo($company, $shopee);
        $this->contratoServicoAtivo($company, $mentoria);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(2, $resultado['criados']);
        $this->assertSame(2, ContratoAssinatura::where('company_id', $company->id)->count());

        $contratoCombinado = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $gestao->id)->firstOrFail();
        $this->assertCount(2, $contratoCombinado->servicos_snapshot);

        $contratoMentoria = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $mentoria->id)->firstOrFail();
        $this->assertCount(1, $contratoMentoria->servicos_snapshot);

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $shopee->id)->count());
    }

    /**
     * Se QUALQUER serviço do grupo combinado tiver ordem de fases ambígua,
     * o GRUPO INTEIRO é barrado — gerar metade de um contrato combinado
     * (só Mercado Livre, sem Shopee, ou vice-versa) é pior que não gerar.
     */
    #[Test]
    public function fase_ambigua_em_qualquer_servico_do_grupo_combinado_barra_o_grupo_inteiro(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee']);

        // Gestão: duas fases AMBÍGUAS (sem data de início nas duas).
        $this->contratoServicoAtivo($company, $gestao, ['valor_contratado' => 100, 'hubspot_line_item_id' => 'g-1']);
        $this->contratoServicoAtivo($company, $gestao, ['valor_contratado' => 150, 'hubspot_line_item_id' => 'g-2']);

        // Shopee: uma fase só, SEM ambiguidade nenhuma.
        $this->contratoServicoAtivo($company, $shopee, ['valor_contratado' => 200]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertFalse($resultado['ok']);
        $this->assertSame([], $resultado['criados']);
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());

        $puladosPorServico = collect($resultado['pulados'])->groupBy('servico_id');
        // As duas fases ambíguas de Gestão.
        $this->assertCount(2, $puladosPorServico->get($gestao->id, collect()));
        // Shopee também é barrado, mesmo sem ambiguidade própria — é membro do grupo.
        $this->assertCount(1, $puladosPorServico->get($shopee->id, collect()));
        foreach ($resultado['pulados'] as $pulado) {
            $this->assertSame('servicos_duplicados', $pulado['motivo']);
        }
    }

    /**
     * Pagamento escalonado DENTRO do combinado: as fases são ordenadas
     * POR SERVIÇO, nunca misturadas numa ordenação só entre Gestão e
     * Shopee. Prova concreta: a fase de Gestão sem data de início e a fase
     * de Shopee sem data de início SERIAM ambíguas entre si se fossem
     * ordenadas juntas (duas chaves nulas) — como a ordenação é por
     * serviço, nenhuma delas colide com a outra.
     */
    #[Test]
    public function pagamento_escalonado_dentro_do_combinado_ordena_fases_por_servico_sem_misturar(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee']);

        // Gestão: 2 fases — a primeira sem data (já em vigor), a segunda com data futura.
        $this->contratoServicoAtivo($company, $gestao, [
            'valor_contratado'       => 100,
            'hubspot_line_item_id'   => 'g-1',
            'hubspot_billing_period' => 'P2M',
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '']],
        ]);
        $this->contratoServicoAtivo($company, $gestao, [
            'valor_contratado'       => 150,
            'hubspot_line_item_id'   => 'g-2',
            'hubspot_billing_period' => 'P4M',
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '2026-12-01']],
        ]);

        // Shopee: 2 fases — MESMA estrutura (uma sem data, outra com data
        // futura DIFERENTE) — se a ordenação misturasse os dois serviços
        // numa passada só, as duas fases "sem data" (Gestão g-1 e Shopee
        // s-1) colidiriam e o grupo inteiro cairia em ordem ambígua.
        $this->contratoServicoAtivo($company, $shopee, [
            'valor_contratado'       => 50,
            'hubspot_line_item_id'   => 's-1',
            'hubspot_billing_period' => 'P3M',
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '']],
        ]);
        $this->contratoServicoAtivo($company, $shopee, [
            'valor_contratado'       => 80,
            'hubspot_line_item_id'   => 's-2',
            'hubspot_billing_period' => 'P6M',
            'hubspot_snapshot'       => ['line_item' => ['hs_recurring_billing_start_date' => '2027-01-01']],
        ]);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);

        $contrato = ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $gestao->id)->firstOrFail();
        $snapshot = $contrato->servicos_snapshot;

        $this->assertCount(4, $snapshot);

        // As fases de Gestão (o dono) vêm PRIMEIRO, na ordem correta.
        $this->assertSame($gestao->nome, $snapshot[0]['servico']);
        $this->assertEqualsWithDelta(100.0, $snapshot[0]['valor_contratado'], 0.001);
        $this->assertSame($gestao->nome, $snapshot[1]['servico']);
        $this->assertEqualsWithDelta(150.0, $snapshot[1]['valor_contratado'], 0.001);

        // Depois, as fases de Shopee, também na ordem correta.
        $this->assertSame($shopee->nome, $snapshot[2]['servico']);
        $this->assertEqualsWithDelta(50.0, $snapshot[2]['valor_contratado'], 0.001);
        $this->assertSame($shopee->nome, $snapshot[3]['servico']);
        $this->assertEqualsWithDelta(80.0, $snapshot[3]['valor_contratado'], 0.001);
    }

    /**
     * Serviço isento (Polos, `exige_contrato = false`) continua FORA, mesmo
     * quando outro grupo combinado (Gestão + Shopee) existe na mesma
     * empresa — Polos nunca é dono de ninguém, então o agrupamento por
     * dono não muda nada para ele.
     */
    #[Test]
    public function servico_isento_continua_fora_mesmo_com_um_grupo_combinado_na_mesma_empresa(): void
    {
        $company = $this->companyCompleta();
        $gestao = $this->servicoDeTeste(['nome' => 'Gestão de Ads']);
        $shopee = $this->servicoJuntoCom($gestao, ['nome' => 'Gestão de Ads Shopee']);
        $polos = $this->servicoDeTeste(['nome' => 'Polos', 'exige_contrato' => false]);

        $this->contratoServicoAtivo($company, $gestao);
        $this->contratoServicoAtivo($company, $shopee);
        $this->contratoServicoAtivo($company, $polos);

        $resultado = $this->service->iniciarParaEmpresa($company);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['criados']);
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $polos->id)->count());

        $pulados = collect($resultado['pulados']);
        $this->assertTrue($pulados->contains(fn (array $p) => $p['servico_id'] === $polos->id && $p['motivo'] === 'servico_isento'));
    }
}
