<?php

namespace Tests\Feature\Phase129;

use App\Jobs\BaixarPdfContratoAssinadoJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 06, Task 1 (CLICK-11, D-12/D-13/D-14) — prova que
 * `BaixarPdfContratoAssinadoJob` baixa o PDF assinado por streaming, disco
 * privado (D-13), SEMPRE reconsultando o documento para um link fresco
 * (D-12, nunca reusa link do payload), e recusa gravar o que não é um PDF
 * de verdade.
 *
 * ⚠️ `Http::fake()` aqui NÃO prova a forma real do documento Clicksign — a
 * prova ponta a ponta é o gate humano do plano 129-07. O que esta suíte
 * prova é a FIAÇÃO: reconsulta → resolve link → streaming pro disco `local`
 * → checagem `%PDF`.
 */
class DownloadPdfAssinadoTest extends TestCase
{
    use RefreshDatabase;

    private const BASE        = 'https://sandbox.clicksign.com/api/v3';
    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000040';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000041';
    private const LINK_S3     = 'https://s3.sandbox.example.com/fake-signed-url?X-Amz-Expires=300';

    private const PDF_VALIDO = "%PDF-1.4\n%conteudo fake de teste\n%%EOF";

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: 'token-falso', baseUrl: self::BASE);
    }

    private function contratoDeTeste(): ContratoAssinatura
    {
        $company = Company::factory()->create();
        $servico = Servico::create([
            'nome'          => 'Assessoria',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        return ContratoAssinatura::factory()->comSnapshot()->create([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em'            => now()->subDays(3),
            'assinado_em'           => now()->subDay(),
            'clicksign_envelope_id' => self::ENVELOPE_ID,
            'clicksign_document_id' => self::DOCUMENT_ID,
        ]);
    }

    /** URL do documento reconsultado — mesma forma que `ClicksignClient::consultarDocumento()` monta. */
    private function urlDocumento(): string
    {
        return self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents/' . self::DOCUMENT_ID;
    }

    private function fakeDocumentoComLink(string $link = self::LINK_S3): void
    {
        Http::fake([
            $this->urlDocumento() => Http::response([
                'data' => [
                    'id'         => self::DOCUMENT_ID,
                    'type'       => 'documents',
                    'attributes' => [
                        'status' => 'closed',
                        'files'  => ['signed' => $link],
                    ],
                ],
            ], 200),
            $link => Http::response(self::PDF_VALIDO, 200),
        ]);
    }

    #[Test]
    public function baixa_o_pdf_assinado_e_grava_no_disco_local(): void
    {
        Storage::fake('local');
        $contrato = $this->contratoDeTeste();
        $this->fakeDocumentoComLink();

        (new BaixarPdfContratoAssinadoJob($contrato))->handle($this->client());

        $contrato->refresh();

        $relativo = "contratos/{$contrato->id}/assinado.pdf";
        Storage::disk('local')->assertExists($relativo);
        $this->assertSame($relativo, $contrato->pdf_assinado_path);
        $this->assertNull($contrato->pdf_assinado_erro);
    }

    #[Test]
    public function disco_public_continua_vazio_prova_da_d13(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $contrato = $this->contratoDeTeste();
        $this->fakeDocumentoComLink();

        (new BaixarPdfContratoAssinadoJob($contrato))->handle($this->client());

        $arquivosNoPublico = Storage::disk('public')->allFiles();
        $this->assertEmpty($arquivosNoPublico, 'D-13: nenhum arquivo pode ir para o disco public');
    }

    #[Test]
    public function reconsulta_o_documento_sempre_prova_da_d12(): void
    {
        Storage::fake('local');
        $contrato = $this->contratoDeTeste();
        $this->fakeDocumentoComLink();

        (new BaixarPdfContratoAssinadoJob($contrato))->handle($this->client());

        // D-12: mesmo existindo um link "antigo" implícito, o job SEMPRE
        // chama consultarDocumento() antes de baixar — nunca reusa link de
        // fora deste método.
        Http::assertSent(function ($request) {
            return $request->url() === $this->urlDocumento() && $request->method() === 'GET';
        });
    }

    #[Test]
    public function resposta_que_nao_comeca_com_pdf_nao_fica_no_disco_e_exception_sobe(): void
    {
        Storage::fake('local');
        $contrato = $this->contratoDeTeste();

        // S3 com link expirado devolve XML de erro (403) em vez do PDF.
        Http::fake([
            $this->urlDocumento() => Http::response([
                'data' => [
                    'id'         => self::DOCUMENT_ID,
                    'type'       => 'documents',
                    'attributes' => [
                        'status' => 'closed',
                        'files'  => ['signed' => self::LINK_S3],
                    ],
                ],
            ], 200),
            self::LINK_S3 => Http::response('<Error><Code>AccessDenied</Code></Error>', 403),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new BaixarPdfContratoAssinadoJob($contrato))->handle($this->client());
        } finally {
            $relativo = "contratos/{$contrato->id}/assinado.pdf";
            $this->assertFalse(Storage::disk('local')->exists($relativo), 'arquivo parcial nao pode sobrar no disco');
        }
    }

    #[Test]
    public function reentrega_com_arquivo_ja_presente_nao_faz_nenhuma_chamada_http(): void
    {
        Storage::fake('local');
        $contrato = $this->contratoDeTeste();
        $this->fakeDocumentoComLink();

        (new BaixarPdfContratoAssinadoJob($contrato))->handle($this->client());
        $contrato->refresh();

        Http::fake(); // zera o histórico de chamadas antes da segunda execução

        (new BaixarPdfContratoAssinadoJob($contrato))->handle($this->client());

        Http::assertNothingSent();
    }

    #[Test]
    public function pdf_assinado_erro_e_mass_assignable(): void
    {
        // Critério de aceite da Task 1: provar por MASS ASSIGNMENT (create/
        // fill), não por leitura de $fillable — a Fase 126/127 já ensinou
        // que uma coluna nova fora de $fillable falha em SILÊNCIO (T-127-03).
        $company = Company::factory()->create();
        $servico = Servico::create([
            'nome'          => 'Assessoria (mass assignment)',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $contrato = ContratoAssinatura::create([
            'company_id'        => $company->id,
            'servico_id'        => $servico->id,
            'status'            => ContratoAssinatura::STATUS_ASSINADO,
            'pdf_assinado_erro' => 'falha de teste via mass assignment',
        ]);

        $this->assertSame(
            'falha de teste via mass assignment',
            $contrato->fresh()->pdf_assinado_erro
        );
    }
}
