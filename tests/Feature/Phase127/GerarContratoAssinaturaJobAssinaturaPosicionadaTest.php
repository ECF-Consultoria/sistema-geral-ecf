<?php

namespace Tests\Feature\Phase127;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\Servico;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoVariaveisModeloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Quick 260824-ot1 (Tarefa 3) — a decisão de assinatura posicionada nasce
 * em `Servico::assinaturaPosicionada()` (já resolvido pelo
 * `GerarContratoAssinaturaJob`, junto do `$templateId`) e desce até
 * `ClicksignClient::montarEnvelopePorModelo()`.
 *
 * ⚠️ `servico_sem_a_flag_gera_contrato_identico_a_antes_desta_quick()` é o
 * teste de regressão que protege os 8 serviços sem a tag no `.docx` — o
 * `Servico::create()` de `servicoDeTeste()` abaixo NÃO informa a coluna
 * nova de propósito, para provar que o default de banco (`false`) é quem
 * decide, não um valor passado explicitamente pelo teste.
 */
class GerarContratoAssinaturaJobAssinaturaPosicionadaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN       = 'token-clicksign-falso';
    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
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
     * Mesma disciplina de `GerarContratoAssinaturaJobTest::servicoDeTeste()`
     * — `$assinaturaPosicionada = null` NÃO manda a chave no `create()`,
     * deixando o default de banco (`false`) decidir.
     */
    private function servicoDeTeste(?bool $assinaturaPosicionada = null): Servico
    {
        $atributos = [
            'nome'                  => 'Gestão de Tráfego — Mercado Livre',
            'valor_padrao'          => 0,
            'tipo_cobranca'         => Servico::TIPO_MENSAL,
            'ativo'                 => true,
            'setor'                 => Servico::SETOR_PERFORMANCE,
            'clicksign_template_id' => self::TEMPLATE_ID,
        ];

        if ($assinaturaPosicionada !== null) {
            $atributos['clicksign_assinatura_posicionada'] = $assinaturaPosicionada;
        }

        return Servico::create($atributos);
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

    private function contratoDeTeste(Servico $servico, ?Company $company = null): ContratoAssinatura
    {
        $company = $company ?? $this->companyDeTeste();

        return ContratoAssinatura::factory()
            ->comSnapshot()
            ->create([
                'company_id' => $company->id,
                'servico_id' => $servico->id,
            ]);
    }

    private function fakeSequenciaSemAtivacao(): void
    {
        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
        ]);
    }

    /**
     * ⚠️ Teste mais importante deste arquivo (regressão). Serviço sem a
     * coluna nova informada (default `false`) — o job precisa gerar o
     * envelope EXATAMENTE como antes desta quick: nenhum `rubric_field`
     * sai em nenhuma requisição, no mesmo orçamento de 18 chamadas já
     * travado por `GerarContratoAssinaturaJobTest::caminho_feliz_gasta_no_maximo_19_chamadas()`.
     */
    #[Test]
    public function servico_sem_a_flag_gera_contrato_identico_a_antes_desta_quick(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $servico  = $this->servicoDeTeste(); // sem informar a coluna nova
        $contrato = $this->contratoDeTeste($servico);

        $this->assertFalse($servico->fresh()->assinaturaPosicionada());

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        // criar (1) + anexar por modelo (1) + 4 signatários × (signer + 3
        // requisitos) (16) = 18 — mesmo orçamento medido no plano 127-05,
        // sem ativação (D-02).
        Http::assertSentCount(18);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/requirements')) {
                return true;
            }

            return ! array_key_exists('rubric_field', $request['data']['attributes'] ?? []);
        });
    }

    #[Test]
    public function servico_com_a_flag_ligada_gera_requisitos_de_rubrica_posicionada_para_contratante_e_contratada(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);
        $this->fakeSequenciaSemAtivacao();

        $servico  = $this->servicoDeTeste(assinaturaPosicionada: true);
        $contrato = $this->contratoDeTeste($servico);

        (new GerarContratoAssinaturaJob($contrato))->handle($this->client(), $this->variaveisService());

        // 18 (base sem flag) + 3 requisitos posicionados (cliente
        // contratante + 2 sócios contratada; comercial testemunha não
        // entra) = 21.
        Http::assertSentCount(21);

        $requisicoesPosicionadas = [];

        Http::assertSent(function ($request) use (&$requisicoesPosicionadas) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/requirements')) {
                return true;
            }

            $atributos = $request['data']['attributes'] ?? [];

            if (array_key_exists('rubric_field', $atributos)) {
                $requisicoesPosicionadas[] = $atributos['rubric_field'];
                $this->assertSame('manuscript', $atributos['kind'] ?? null);
            }

            return true;
        });

        sort($requisicoesPosicionadas);
        // Quick 260825-c3m — valor completo (`position_sign_<id>`), não o
        // id cru. Ver docblock de `PAPEL_PARA_POSITION_SIGN_ID`.
        $this->assertSame(['position_sign_contratada', 'position_sign_contratada', 'position_sign_contratante'], $requisicoesPosicionadas);
    }
}
