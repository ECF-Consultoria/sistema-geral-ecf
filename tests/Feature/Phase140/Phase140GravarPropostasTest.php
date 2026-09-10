<?php

namespace Tests\Feature\Phase140;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 04 (TAB-07) — cobre a opção `--gravar` do comando `clicksign:extrair-tabelas`:
 * sem a opção nada muda no banco (comportamento herdado do 140-03), com a opção cada contrato
 * lido vira/atualiza uma `ContratoTabelaProposta` `pendente`, re-rodar não duplica e nunca
 * sobrescreve uma proposta já `confirmada`/`descartada` (T-140-14, T-140-15).
 *
 * ⚠️ Nomes fictícios, `Http::fake()` (zero chamada real), `Storage::fake('local')`.
 */
class Phase140GravarPropostasTest extends TestCase
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
                'name'    => $nome,
                'status'  => $status,
                'created' => '2026-08-01T00:00:00-03:00',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoResposta(string $documentId, string $linkOriginal): array
    {
        return [
            'data' => [
                [
                    'id'         => $documentId,
                    'type'       => 'documents',
                    'attributes' => ['filename' => 'contrato.pdf'],
                    'links'      => ['files' => ['original' => $linkOriginal]],
                ],
            ],
        ];
    }

    private function fakeDoisContratos(): void
    {
        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => [
                $this->envelope('ads-tabela', 'Contrato Gestão de ADS _ ECF - EMPRESA TABELA'),
                $this->envelope('ads-fixo', 'Contrato Gestão de ADS _ ECF - EMPRESA FIXO'),
            ]], 200),
            self::BASE . '/envelopes/ads-tabela/documents' => Http::response(
                $this->documentoResposta('doc-tabela', 'https://s3.example.com/ads-tabela.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-fixo/documents' => Http::response(
                $this->documentoResposta('doc-fixo', 'https://s3.example.com/ads-fixo.pdf'),
                200
            ),
            'https://s3.example.com/ads-tabela.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('tabela-notacao-nova')), 200),
            'https://s3.example.com/ads-fixo.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
        ]);
    }

    #[Test]
    public function sem_gravar_nada_e_criado(): void
    {
        $this->fakeDoisContratos();

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->assertExitCode(0);

        $this->assertSame(0, ContratoTabelaProposta::count());
    }

    #[Test]
    public function com_gravar_as_propostas_aparecem_pendentes(): void
    {
        $this->fakeDoisContratos();

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])
            ->assertExitCode(0);

        $this->assertSame(2, ContratoTabelaProposta::count());

        $tabela = ContratoTabelaProposta::where('clicksign_envelope_id', 'ads-tabela')->first();
        $this->assertNotNull($tabela);
        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $tabela->situacao);
        $this->assertSame(ContratoTabelaProposta::TIPO_TABELA, $tabela->tipo_cobranca);
        $this->assertIsArray($tabela->faixas);
        $this->assertNotEmpty($tabela->faixas);

        $fixo = ContratoTabelaProposta::where('clicksign_envelope_id', 'ads-fixo')->first();
        $this->assertNotNull($fixo);
        $this->assertSame(ContratoTabelaProposta::TIPO_VALOR_FIXO, $fixo->tipo_cobranca);
        $this->assertEqualsWithDelta(3000.00, (float) $fixo->valor_fixo, 0.001);
    }

    #[Test]
    public function rodar_duas_vezes_seguidas_mantem_uma_proposta_por_envelope(): void
    {
        $this->fakeDoisContratos();

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])->assertExitCode(0);
        $this->fakeDoisContratos();
        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])->assertExitCode(0);

        $this->assertSame(2, ContratoTabelaProposta::count());
    }

    #[Test]
    public function proposta_confirmada_nao_e_sobrescrita_por_nova_rodada(): void
    {
        $this->fakeDoisContratos();

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])->assertExitCode(0);

        $proposta = ContratoTabelaProposta::where('clicksign_envelope_id', 'ads-tabela')->firstOrFail();
        $company  = Company::factory()->create();

        $proposta->update([
            'situacao'       => ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            'company_id'     => $company->id,
            'confirmado_em'  => now(),
        ]);

        $this->fakeDoisContratos();
        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])->assertExitCode(0);

        // Reconsulta direta ao banco — nunca confiar no model já carregado.
        $depois = ContratoTabelaProposta::where('clicksign_envelope_id', 'ads-tabela')->firstOrFail();
        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $depois->situacao);
        $this->assertSame($company->id, $depois->company_id);

        $this->assertSame(2, ContratoTabelaProposta::count(), 'Não pode ter criado uma segunda linha para o mesmo envelope.');
    }

    #[Test]
    public function gravar_nao_altera_a_contagem_de_faixas_de_empresa(): void
    {
        $this->fakeDoisContratos();

        $antes = EmpresaFaixaFaturamento::count();

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])
            ->assertExitCode(0);

        $this->assertSame($antes, EmpresaFaixaFaturamento::count());
    }

    #[Test]
    public function relatorio_continua_sendo_gerado_com_gravar_ligado(): void
    {
        $this->fakeDoisContratos();

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--gravar' => true])
            ->assertExitCode(0);

        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $this->assertNotNull($md, 'o relatório .md continua sendo gerado com --gravar.');
    }
}
