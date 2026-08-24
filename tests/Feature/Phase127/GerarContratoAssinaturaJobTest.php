<?php

namespace Tests\Feature\Phase127;

use App\Exceptions\ClicksignException;
use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\Servico;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoVariaveisModeloService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 127 Plano 127-05 (CLICK-02, CLICK-08, DADOS-06) — prova o
 * `GerarContratoAssinaturaJob`: monta o envelope inteiro e PARA no rascunho
 * (D-02), com prazo/lembrete definidos na CRIAÇÃO (D-03), sem nunca gravar
 * `enviado_em`, dentro do orçamento medido de 19 chamadas (D-01/Pitfall 4 —
 * quick `260824-mv3` acrescentou a rubrica, eram 15), e libera a empresa
 * (D-06) quando falha definitivamente (Pitfall 3), sem vazar PII no
 * `erro_mensagem` (WR-11).
 *
 * Todas as fixtures de payload usadas aqui já existem em
 * `ClicksignSandboxFixtures` (Fase 126/127-02) — ENTRADA MEDIDA em
 * 11/08/2026 (D-03, `127-CONTEXT.md`), SAÍDA copiada do sandbox. Nenhuma
 * fixture nova de forma de payload nasce neste plano.
 */
class GerarContratoAssinaturaJobTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN       = 'token-clicksign-falso';
    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const TEMPLATE_ID = '00000000-0000-4000-8000-000000000008';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    private function variaveisService(): ContratoVariaveisModeloService
    {
        return app(ContratoVariaveisModeloService::class);
    }

    /**
     * Os 3 signatários fixos da ECF (D-08), mesmo molde dos testes da Fase
     * 126/127-02.
     *
     * @return array<int, array{nome: string, email: string, papel: string}>
     */
    private function signatariosEcfDeTeste(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA],
        ];
    }

    /**
     * Sequência feliz até a última chamada ANTES da ativação (D-02): criar
     * envelope, anexar documento por modelo, signatário e requisito. Sem
     * entrada para `PATCH /envelopes/{id}` de ativação — se o job chamar
     * ativação por engano, o fake devolve 404 (nenhuma rota casada) e o
     * teste falha alto e claro, em vez de silenciosamente "passar".
     */
    private function fakeSequenciaSemAtivacao(): void
    {
        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
        ]);
    }

    private function servicoDeTeste(?string $templateId = self::TEMPLATE_ID): Servico
    {
        return Servico::create([
            'nome'                  => 'Gestão de Tráfego — Mercado Livre',
            'valor_padrao'          => 0,
            'tipo_cobranca'         => Servico::TIPO_MENSAL,
            'ativo'                 => true,
            'setor'                 => Servico::SETOR_PERFORMANCE,
            'clicksign_template_id' => $templateId,
        ]);
    }

    private function companyDeTeste(): Company
    {
        return Company::factory()->create([
            'name'          => 'Empresa Teste LTDA',
            'cnpj'          => '11.222.333/0001-99',
            'nome_contato'  => 'Cliente Teste',
            'email_cliente' => 'cliente@example.com',
        ]);
    }

    /**
     * Contrato válido, com `servicos_snapshot` congelado (D-04/D-10) —
     * `ContratoVariaveisModeloService::montar()` exige isso (é
     * `ContratoPdfService::montarDados()` por baixo). Fora deste job, quem
     * grava o snapshot é o orquestrador do plano 127-06; aqui simulamos o
     * contrato já pronto para a montagem.
     */
    private function contratoDeTeste(?Servico $servico = null, ?Company $company = null, array $overrides = []): ContratoAssinatura
    {
        $company = $company ?? $this->companyDeTeste();
        $servico = $servico ?? $this->servicoDeTeste();

        return ContratoAssinatura::factory()
            ->comSnapshot()
            ->create(array_merge([
                'company_id' => $company->id,
                'servico_id' => $servico->id,
            ], $overrides));
    }

    private function identificaAtivacao($request): bool
    {
        $atributos = $request['data']['attributes'] ?? [];

        return $request->method() === 'PATCH'
            && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID
            && ($atributos['status'] ?? null) === 'running';
    }

    // ─── Teste 1 (CLICK-02) ───

    #[Test]
    public function job_grava_envelope_id_e_document_id_e_mantem_status_rascunho(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste();

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        $contrato->refresh();

        $this->assertSame(self::ENVELOPE_ID, $contrato->clicksign_envelope_id);
        $this->assertNotEmpty($contrato->clicksign_document_id);
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $contrato->status);
    }

    // ─── Teste 2 (D-02) ───

    #[Test]
    public function job_nao_manda_a_chamada_de_ativacao(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste();

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertNotSent(fn ($request) => $this->identificaAtivacao($request));
    }

    // ─── Teste 3 (D-02, consequência) ───

    #[Test]
    public function job_nao_grava_enviado_em(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste();

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        $contrato->refresh();

        $this->assertNull($contrato->enviado_em);
    }

    // ─── Teste 4 (CLICK-08) ───

    #[Test]
    public function payload_de_criacao_do_envelope_contem_remind_interval_do_contrato(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste(overrides: ['lembrete_dias' => 5]);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE . '/envelopes'
                && data_get($request->data(), 'data.attributes.remind_interval') === 5;
        });
    }

    // ─── Teste 5 (DADOS-06) ───
    // ⚠️ Prova aqui: prazo definido na CRIAÇÃO chega no payload de
    // `POST /envelopes` (D-03, MEDIDO em 11/08/2026). O que NÃO é medido
    // — e fica fora deste teste — é se esse prazo SOBREVIVE a uma ativação
    // feita pela interface web da Clicksign; isso é o gate do plano 127-07.

    #[Test]
    public function deadline_at_usa_prazo_dias_da_coluna_quando_preenchido(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste(overrides: ['prazo_dias' => 10]);
        $esperado = now()->addDays(10);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertSent(function ($request) use ($esperado) {
            if ($request->method() !== 'POST' || $request->url() !== self::BASE . '/envelopes') {
                return false;
            }

            $deadline = Carbon::parse(data_get($request->data(), 'data.attributes.deadline_at'));

            return $deadline->isSameDay($esperado);
        });
    }

    #[Test]
    public function deadline_at_usa_padrao_da_config_quando_prazo_dias_e_null(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        config(['services.clicksign.prazo_dias_padrao' => 30]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste(overrides: ['prazo_dias' => null]);
        $esperado = now()->addDays(30);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertSent(function ($request) use ($esperado) {
            if ($request->method() !== 'POST' || $request->url() !== self::BASE . '/envelopes') {
                return false;
            }

            $deadline = Carbon::parse(data_get($request->data(), 'data.attributes.deadline_at'));

            return $deadline->isSameDay($esperado);
        });
    }

    // ─── Teste 6 (orçamento — Pitfall 4) ───

    #[Test]
    public function caminho_feliz_gasta_no_maximo_19_chamadas(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste();

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        // criar envelope (1) + anexar por modelo (1) + 4 signatários × (signer
        // + 3 requisitos: qualificação, autenticação, rubrica) (16) = 18 — 19
        // do caminho feliz medido (Fase 126 + rubrica do quick 260824-mv3)
        // MENOS a ativação que a D-02 remove. Nenhuma reconsulta extra.
        Http::assertSentCount(18);
    }

    // ─── Teste 7 (D-01) ───

    #[Test]
    public function middleware_aplica_without_overlapping_e_rate_limited_clicksign_envelope(): void
    {
        $contrato = $this->contratoDeTeste();
        $job      = new GerarContratoAssinaturaJob($contrato);

        $middlewares = $job->middleware();

        $this->assertCount(2, $middlewares);
        $this->assertInstanceOf(WithoutOverlapping::class, $middlewares[0]);
        $this->assertInstanceOf(RateLimited::class, $middlewares[1]);

        $this->assertNotNull(RateLimiter::limiter('clicksign-envelope'));
    }

    // ─── Teste 8 (Pitfall 3) ───

    #[Test]
    public function failed_grava_status_erro_e_libera_a_empresa_para_tentar_de_novo(): void
    {
        $company  = $this->companyDeTeste();
        $servico  = $this->servicoDeTeste();
        $contrato = $this->contratoDeTeste($servico, $company);

        $this->assertNotNull(ContratoAssinatura::emAndamentoDoServico($company->id, $servico->id));

        (new GerarContratoAssinaturaJob($contrato))->failed(new \RuntimeException('Falha simulada de teste'));

        $contrato->refresh();

        $this->assertSame(ContratoAssinatura::STATUS_ERRO, $contrato->status);
        $this->assertNotEmpty($contrato->erro_mensagem);
        $this->assertNull(ContratoAssinatura::emAndamentoDoServico($company->id, $servico->id));
    }

    // ─── Teste 9 (PII) ───

    #[Test]
    public function failed_poda_email_da_mensagem_de_erro(): void
    {
        $contrato = $this->contratoDeTeste();

        (new GerarContratoAssinaturaJob($contrato))->failed(
            new \RuntimeException('Falha ao processar signatário cliente@example.com (Cliente Teste)')
        );

        $contrato->refresh();

        $this->assertStringNotContainsString('cliente@example.com', $contrato->erro_mensagem);
    }

    // ─── Teste 10 (D-04) ───

    #[Test]
    public function falha_no_meio_da_montagem_propaga_excecao_original_sem_duplicar_rollback(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'role inválido']]],
                422
            ),
            self::BASE . '/envelopes/*'               => Http::response('', ClicksignSandboxFixtures::envelopeDescartadoStatusHttp()),
        ]);

        $contrato = $this->contratoDeTeste();

        try {
            (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());
            $this->fail('Esperava a ClicksignException original propagada, não a de cancelamento.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID;
        });

        // criar envelope (1) + anexar por modelo (1) + 1º signatário (1) +
        // 1º requisito, que falha (1) + cancelamento (1) = 5 — o job não
        // duplica o rollback, que já vive dentro do ClicksignClient.
        Http::assertSentCount(5);

        $contrato->refresh();
        $this->assertNull($contrato->clicksign_envelope_id);
    }

    // ─── Teste 11 ───

    #[Test]
    public function servico_sem_modelo_falha_antes_de_qualquer_requisicao(): void
    {
        config(['services.clicksign.template_id' => null]);
        Http::fake();

        $servico  = $this->servicoDeTeste(templateId: null);
        $contrato = $this->contratoDeTeste($servico);

        try {
            (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());
            $this->fail('Esperava RuntimeException por falta de modelo Clicksign.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($servico->nome, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ─── Teste 12 (Quick 260819-guy) ───

    /**
     * `endereco` (dado de EMPRESA, lido ao vivo) e `dia_vencimento`/
     * `data_primeira_parcela` (dado de SERVIÇO, lido do snapshot congelado)
     * chegam com valor REAL no payload de `POST /envelopes/{id}/documents`
     * — não mais "A DEFINIR" fixo.
     */
    #[Test]
    public function endereco_dia_vencimento_e_data_primeira_parcela_chegam_com_valor_real_no_payload_do_documento(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $company = Company::factory()->create([
            'name'          => 'Empresa Teste LTDA',
            'cnpj'          => '11.222.333/0001-99',
            'nome_contato'  => 'Cliente Teste',
            'email_cliente' => 'cliente@example.com',
            'razao_social'  => 'Empresa Teste Comércio e Serviços LTDA',
            'endereco'      => 'Rua Exemplo, 123 - São Paulo/SP',
        ]);

        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([[
                'servico'               => 'Gestão de Tráfego — Mercado Livre',
                'valor_contratado'      => 1847.32,
                'data_contratacao'      => '2026-01-15',
                'data_vencimento'       => '2027-01-15',
                'dia_vencimento'        => 10,
                'data_primeira_parcela' => '2026-02-05',
            ]])
            ->create([
                'company_id' => $company->id,
                'servico_id' => $this->servicoDeTeste()->id,
            ]);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || $request->url() !== self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents') {
                return false;
            }

            $data = data_get($request->data(), 'data.attributes.template.data');

            return $data['razao_social']          === 'Empresa Teste Comércio e Serviços LTDA'
                && $data['endereco']               === 'Rua Exemplo, 123 - São Paulo/SP'
                && $data['dia_vencimento']          === '10'
                && $data['data_primeira_parcela']   === '05/02/2026';
        });
    }

    // ─── Teste 13 (Quick 260819-guy) ───

    /**
     * Contrato sem os complementos (snapshot antigo, sem dia_vencimento/
     * data_primeira_parcela, e empresa sem endereco) ainda monta o
     * envelope com sucesso — os campos caem em `campos_pendentes`
     * internamente (só a QUANTIDADE é logada), nunca quebram o job.
     */
    #[Test]
    public function ausencia_de_endereco_e_datas_de_pagamento_nao_quebra_o_job(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $contrato = $this->contratoDeTeste();

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        $contrato->refresh();
        $this->assertNotEmpty($contrato->clicksign_envelope_id);
    }

    // ─── Teste 14 (Quick 260819-guy, Tarefa 6) ───

    /**
     * Caso real medido no plano: `"Embralumi - Novo(a) Deal"` (razão social)
     * + `"Gestão"` (serviço) produz `Embralumi-Novo-a-Deal-Gestao.docx` — só
     * `[A-Za-z0-9_-]` antes do `.docx`, acento transliterado, espaço/
     * parêntese viram hífen, hífens repetidos colapsados.
     */
    #[Test]
    public function nome_do_arquivo_usa_slug_ascii_conservador_de_razao_social_e_servico(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $company = Company::factory()->create([
            'name'          => 'Embralumi',
            'cnpj'          => '11.222.333/0001-99',
            'nome_contato'  => 'Cliente Teste',
            'email_cliente' => 'cliente@example.com',
            'razao_social'  => 'Embralumi - Novo(a) Deal',
        ]);

        $servico  = $this->servicoDeTeste();
        $servico->forceFill(['nome' => 'Gestão'])->save();

        $contrato = $this->contratoDeTeste($servico, $company);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || $request->url() !== self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents') {
                return false;
            }

            $filename = data_get($request->data(), 'data.attributes.filename');

            return $filename === 'Embralumi-Novo-a-Deal-Gestao.docx'
                && preg_match('/^[A-Za-z0-9_-]+\.docx$/', $filename) === 1;
        });
    }

    // ─── Teste 15 (Quick 260819-guy, Tarefa 6) ───

    /**
     * Sem razão social preenchida (empresa antiga), cai no fallback
     * `company->name` — o nome do arquivo continua identificável, nunca
     * volta ao genérico `contrato-{id}.docx` só por falta desse campo.
     */
    #[Test]
    public function nome_do_arquivo_cai_no_fallback_de_company_name_sem_razao_social(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $company = Company::factory()->create([
            'name'          => 'Empresa Sem Razao Social',
            'cnpj'          => '11.222.333/0001-99',
            'nome_contato'  => 'Cliente Teste',
            'email_cliente' => 'cliente@example.com',
            'razao_social'  => null,
        ]);

        $contrato = $this->contratoDeTeste(company: $company);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || $request->url() !== self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents') {
                return false;
            }

            $filename = data_get($request->data(), 'data.attributes.filename');

            return str_starts_with($filename, 'Empresa-Sem-Razao-Social-')
                && str_ends_with($filename, '.docx');
        });
    }
}
