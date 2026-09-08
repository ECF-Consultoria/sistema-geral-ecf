<?php

namespace Tests\Feature\Phase140;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 03 (TAB-06) — cobre o comando `clicksign:extrair-tabelas` de
 * ponta a ponta: `Http::fake()` para listagem/documentos/download (zero
 * chamada real), `Storage::fake('local')` para os arquivos gerados,
 * `Company::factory()` para o casamento com empresa (140-CONTEXT.md D-05).
 *
 * ⚠️ Nomes de empresa fictícios (`EMPRESA FICTICIA ...`) — os mesmos das
 * fixtures de texto do plano 140-02, reaproveitados aqui como PDF via
 * `dompdf` (nenhum PDF de cliente real entra no repositório).
 */
class Phase140RelatorioTabelasTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sandbox.clicksign.com/api/v3';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clicksign.base_url'     => self::BASE,
            'services.clicksign.access_token' => 'token-falso-de-teste',
        ]);

        Storage::fake('local');
    }

    /**
     * PDF mínimo, mas legível, montado em tempo de teste via `dompdf` — o
     * mesmo recurso usado pelo Phase140ExtratorTextoTest (140-02).
     */
    private function pdfDoTexto(string $texto): string
    {
        $html = '<html><body><pre>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';

        return Pdf::loadHTML($html)->output();
    }

    private function textoFixture(string $nome): string
    {
        return file_get_contents(__DIR__ . "/fixtures/{$nome}.txt");
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $id, string $nome, string $status = 'closed'): array
    {
        return [
            'id'         => $id,
            'type'       => 'envelopes',
            'attributes' => [
                'name'        => $nome,
                'status'      => $status,
                'finished_at' => '2026-08-01T00:00:00-03:00',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoResposta(string $documentId, string $linkOriginal, string $nomeArquivo = 'contrato.pdf'): array
    {
        return [
            'data' => [
                [
                    'id'         => $documentId,
                    'type'       => 'documents',
                    'attributes' => ['filename' => $nomeArquivo],
                    'links'      => [
                        'files' => ['original' => $linkOriginal],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function roda_de_ponta_a_ponta_gera_dois_arquivos_com_resumo_e_nao_altera_o_banco(): void
    {
        // Empresa que CASA por CNPJ com o contrato de tabela (140-02 fixture) — "casaram com
        // segurança".
        $empresaCasada = Company::factory()->create([
            'cnpj'         => '12.345.678/0001-90',
            'razao_social' => null,
        ]);

        // Nenhuma empresa parecida com o contrato de valor fixo — "duvidosos" + "valor fixo".
        Company::factory()->create([
            'name'         => 'Distribuidora Completamente Diferente Zeta',
            'cnpj'         => '00.000.000/0001-00',
            'razao_social' => null,
        ]);

        $qtdFaixasAntes = EmpresaFaixaFaturamento::count();

        Http::fake([
            self::BASE . '/envelopes*' => Http::response(['data' => [
                $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - EMPRESA ENVELOPE TABELA'),
                $this->envelope('ads-2', 'Contrato Gestao de Ads ECF - EMPRESA ENVELOPE VALOR FIXO'),
                $this->envelope('ads-3', 'Contrato Gestao de Ads ECF - EMPRESA ENVELOPE ILEGIVEL'),
            ]], 200),
            self::BASE . '/envelopes/ads-1/documents' => Http::response(
                $this->documentoResposta('doc-1', 'https://s3.example.com/ads-1.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-2/documents' => Http::response(
                $this->documentoResposta('doc-2', 'https://s3.example.com/ads-2.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-3/documents' => Http::response(
                $this->documentoResposta('doc-3', 'https://s3.example.com/ads-3.pdf'),
                200
            ),
            'https://s3.example.com/ads-1.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('tabela-notacao-nova')), 200),
            'https://s3.example.com/ads-2.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
            // Ilegível de propósito — nem %PDF nem PK.
            'https://s3.example.com/ads-3.pdf*' => Http::response('isto não é um contrato legível', 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->assertExitCode(0);

        // ─── Nada mudou no banco ───
        $this->assertSame($qtdFaixasAntes, EmpresaFaixaFaturamento::count());

        // ─── Os dois arquivos existem ───
        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md  = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $csv = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.csv'));

        $this->assertNotNull($md, 'esperava um arquivo .md em storage/app/private/relatorios');
        $this->assertNotNull($csv, 'esperava um arquivo .csv em storage/app/private/relatorios');

        $conteudoMd = Storage::disk('local')->get($md);

        // ─── Resumo bate ───
        $this->assertStringContainsString('Total varrido: **3**', $conteudoMd);
        $this->assertStringContainsString('Casaram com segurança: **1**', $conteudoMd);
        $this->assertStringContainsString('Ficaram duvidosos: **1**', $conteudoMd);
        $this->assertStringContainsString('São valor fixo: **1**', $conteudoMd);
        $this->assertStringContainsString('Não deu para ler: **1**', $conteudoMd);

        // ─── Linha "casaram com segurança" traz o nome da empresa e a confiança em palavras ───
        $this->assertStringContainsString($empresaCasada->name, $conteudoMd);
        $this->assertStringContainsString('confirmado pelo CNPJ', $conteudoMd);

        // ─── Linha de valor fixo aparece como valor fixo, com o valor lido ───
        $this->assertStringContainsString('valor fixo por mês', $conteudoMd);
        $this->assertStringContainsString('R$ 3.000,00', $conteudoMd);

        // ─── Contrato ilegível vira linha com motivo, não some do relatório ───
        $this->assertStringContainsString('ads-3', $conteudoMd);
        $this->assertStringContainsString('não deu para ler', $conteudoMd);

        // ─── CSV tem cabeçalho + 3 linhas de dado ───
        $conteudoCsv = Storage::disk('local')->get($csv);
        $linhasCsv   = array_filter(explode("\n", trim($conteudoCsv)));
        $this->assertCount(4, $linhasCsv); // cabeçalho + 3 contratos
    }

    #[Test]
    public function limite_processa_no_maximo_a_quantidade_pedida(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::response(['data' => [
                $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - EMPRESA UM'),
                $this->envelope('ads-2', 'Contrato Gestao de Ads ECF - EMPRESA DOIS'),
                $this->envelope('ads-3', 'Contrato Gestao de Ads ECF - EMPRESA TRES'),
            ]], 200),
            self::BASE . '/envelopes/ads-1/documents' => Http::response(
                $this->documentoResposta('doc-1', 'https://s3.example.com/ads-1.pdf'),
                200
            ),
            'https://s3.example.com/ads-1.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--limite' => 1])
            ->assertExitCode(0);

        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md       = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $conteudo = Storage::disk('local')->get($md);

        $this->assertStringContainsString('Total varrido: **1**', $conteudo);

        // Só o documento do primeiro envelope foi consultado — o limite parou a varredura antes
        // de chegar nos outros dois.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/envelopes/ads-2/documents'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/envelopes/ads-3/documents'));
    }

    #[Test]
    public function aviso_de_nao_commitar_e_impresso_no_terminal(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->expectsOutputToContain('não commite')
            ->assertExitCode(0);
    }
}
