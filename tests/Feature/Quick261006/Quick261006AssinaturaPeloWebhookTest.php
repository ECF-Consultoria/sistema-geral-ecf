<?php

namespace Tests\Feature\Quick261006;

use App\Jobs\ProcessarEventoClicksignJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoSignatariosSyncService;
use App\Services\Contratos\GateLiberacaoOperacionalService;
use App\Services\Fechamento\GravarTabelaEmpresaService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261006-gf5 — a FIAÇÃO no fluxo real: quando o webhook da Clicksign faz o contrato virar
 * `assinado`, a tabela da empresa sai carimbada como confirmada por contrato na MESMA passagem.
 *
 * E o inverso, que é a borda mais importante: uma falha na gravação da tabela NUNCA derruba o
 * processamento do evento nem desfaz a liberação da empresa — mesmo espírito do try/catch que
 * protege o download do PDF assinado (D-14 da Fase 129) e do `AdmanService::syncAll()`.
 *
 * Harness (cliente/gate/router, `Http::fake` do envelope e dos eventos do documento) copiado de
 * `Tests\Feature\Phase129\DownloadPdfFalhaNaoBloqueiaTest` — mesma cadeia, mesmo job.
 *
 * ⚠️ `Http::fake()` aqui NÃO prova a forma real do payload da Clicksign; o que esta suíte prova é
 * a FIAÇÃO (assinou → carimbou; gravação falhou → liberação intacta).
 */
class Quick261006AssinaturaPeloWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000070';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000071';
    private const SIGNER_KEY  = '00000000-0000-4000-8000-000000000072';

    private function urlEnvelope(): string
    {
        return self::BASE.'/envelopes/'.self::ENVELOPE_ID;
    }

    private function urlEventosDocumento(): string
    {
        return self::BASE.'/envelopes/'.self::ENVELOPE_ID.'/documents/'.self::DOCUMENT_ID.'/events';
    }

    private function urlDocumento(): string
    {
        return self::BASE.'/envelopes/'.self::ENVELOPE_ID.'/documents/'.self::DOCUMENT_ID;
    }

    private function servicoComTabela(): Servico
    {
        $servico = Servico::create([
            'nome'          => 'Gestão '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);

        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false,
        ]);
        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 2, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true,
        ]);

        return $servico;
    }

    /** Contrato aguardando assinatura, com o signatário CONTRATANTE pendente. */
    private function contratoPendente(Servico $servico): ContratoAssinatura
    {
        $company = Company::factory()->create();

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'clicksign_document_id' => self::DOCUMENT_ID,
            'servicos_snapshot'     => [[
                'servico'          => $servico->nome,
                'valor_contratado' => 1_847.32,
                'data_contratacao' => '2026-01-15',
                'data_vencimento'  => '2027-01-15',
            ]],
        ]);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'papel'                  => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
            'clicksign_signer_key'   => self::SIGNER_KEY,
        ]);

        return $contrato;
    }

    private function fakeEnvelopeFechado(): void
    {
        Http::fake([
            $this->urlEnvelope() => Http::response([
                'data' => [
                    'id'         => self::ENVELOPE_ID,
                    'type'       => 'envelopes',
                    'attributes' => ['status' => 'closed'],
                ],
            ], 200),
            $this->urlEventosDocumento() => Http::response([
                'data' => [[
                    'attributes' => [
                        'name'    => 'sign',
                        'created' => now()->toIso8601String(),
                        'data'    => [
                            'signer' => [
                                'key'     => self::SIGNER_KEY,
                                'email'   => 'cliente.quick261006@example.com',
                                'address' => '203.0.113.11',
                                'auths'   => ['email'],
                            ],
                        ],
                    ],
                ]],
            ], 200),
            // Documento sem link assinado — o download falha sozinho e isso é irrelevante aqui
            // (já coberto pela Fase 129); o que importa é a tabela.
            $this->urlDocumento() => Http::response([
                'data' => [
                    'id'         => self::DOCUMENT_ID,
                    'type'       => 'documents',
                    'attributes' => ['status' => 'closed', 'files' => []],
                ],
            ], 200),
        ]);
    }

    private function processar(ContratoAssinatura $contrato): void
    {
        $rawBody = json_encode(['event' => ['name' => 'sign']]);

        $evento = ContratoAssinaturaEvento::create([
            'contrato_assinatura_id' => $contrato->id,
            'clicksign_envelope_id'  => $contrato->clicksign_envelope_id,
            'name'                   => 'sign',
            'signature_valid'        => true,
            'payload'                => ['event' => ['name' => 'sign']],
            'payload_hash'           => hash('sha256', $rawBody.uniqid('', true)),
            'raw_body'               => $rawBody,
            'raw_truncado'           => false,
            'origem'                 => ContratoAssinaturaEvento::ORIGEM_WEBHOOK,
            'status'                 => ContratoAssinaturaEvento::STATUS_RECEBIDO,
            'ip_address'             => '203.0.113.11',
        ]);

        // Quatro argumentos de propósito: prova que o parâmetro novo do `handle()` é opcional e
        // resolvido pelo container — a suíte histórica da Fase 129 chama exatamente assim.
        (new ProcessarEventoClicksignJob($evento))->handle(
            new ClicksignClient(token: 'token-falso', baseUrl: self::BASE),
            new ContratoSignatariosSyncService(),
            new GateLiberacaoOperacionalService(),
            app(EmpresaOperacionalRouter::class),
        );
    }

    // ── 1. assinou pelo webhook → tabela carimbada ───────────────────────

    #[Test]
    public function webhook_que_assina_o_contrato_carimba_a_tabela_como_confirmada(): void
    {
        Storage::fake('local');

        $servico  = $this->servicoComTabela();
        $contrato = $this->contratoPendente($servico);
        $this->fakeEnvelopeFechado();

        $this->processar($contrato);

        $contrato->refresh();
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);

        $linhas = DB::table('empresa_faixas_faturamento')
            ->where('company_id', $contrato->company_id)
            ->orderBy('ordem')
            ->get();

        $this->assertCount(2, $linhas, 'A assinatura pelo webhook tem que gravar a tabela da empresa');
        foreach ($linhas as $linha) {
            $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $linha->origem);
            $this->assertSame($servico->id, (int) $linha->servico_origem_id);
        }
        $this->assertSame(1_500.00, (float) $linhas[0]->valor);
        $this->assertSame(3_000.00, (float) $linhas[1]->valor);
    }

    // ── 7. falha na gravação não derruba o evento do Clicksign ───────────

    #[Test]
    public function falha_ao_gravar_a_tabela_nao_derruba_o_evento_nem_a_liberacao(): void
    {
        Storage::fake('local');

        // Porta única de escrita trocada por uma que SEMPRE lança — qualquer causa real
        // (constraint, trava de precedência, banco fora) chega ao chamador do mesmo jeito.
        $this->app->bind(GravarTabelaEmpresaService::class, fn () => new class extends GravarTabelaEmpresaService
        {
            public function gravar(
                Company $company,
                array $faixas,
                string $origem,
                ?int $servicoOrigemId = null,
                ?\App\Models\User $por = null,
                string $feitoDe = 'contrato_ficha',
            ): array {
                throw new \RuntimeException('falha simulada na gravacao da tabela');
            }
        });

        $servico  = $this->servicoComTabela();
        $contrato = $this->contratoPendente($servico);
        $this->fakeEnvelopeFechado();

        // Não deve propagar exceção nenhuma.
        $this->processar($contrato);

        $contrato->refresh();

        // 1. O contrato continua assinado — a assinatura é fato do cliente, não do nosso carimbo.
        $this->assertSame(ContratoAssinatura::STATUS_ASSINADO, $contrato->status);
        $this->assertNotNull($contrato->assinado_em);

        // 2. A liberação da empresa aconteceu e não foi desfeita.
        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $contrato->company_id)
                ->where('servico_id', $servico->id)
                ->where('via', ContratoLiberacao::VIA_WEBHOOK)
                ->count(),
            'Falha ao carimbar a tabela nunca pode impedir a liberacao da empresa'
        );

        // 3. O evento do webhook foi processado, não ficou preso para retry.
        $this->assertSame(
            ContratoAssinaturaEvento::STATUS_PROCESSADO,
            ContratoAssinaturaEvento::where('contrato_assinatura_id', $contrato->id)->value('status')
        );

        // 4. E nenhuma tabela meia-gravada ficou para trás.
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $contrato->company_id)->count());
    }
}
