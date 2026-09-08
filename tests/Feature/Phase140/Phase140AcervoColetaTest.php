<?php

namespace Tests\Feature\Phase140;

use App\Services\Clicksign\AcervoContratosClicksignService;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plan 140-01 (TAB-01) — cobre o `AcervoContratosClicksignService`:
 * varredura paginada do acervo Clicksign, filtro local de gestão de ADS
 * (D-01 de `140-CONTEXT.md`) e download imediato do arquivo (D-02, janela de
 * ~299s). Nenhum teste chama a rede de verdade — `Http::fake()` em todos; o
 * serviço nasce com `pausaMs: 0` nos testes para não pagar a pausa real de
 * 3500ms pensada para a janela medida de 20 chamadas/min da conta.
 */
class Phase140AcervoColetaTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000002';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    private function servico(int $pausaMs = 0): AcervoContratosClicksignService
    {
        return new AcervoContratosClicksignService($this->client(), $pausaMs);
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $id, string $nome, string $status = 'closed', ?string $finishedAt = null): array
    {
        return [
            'id'         => $id,
            'type'       => 'envelopes',
            'attributes' => [
                'name'        => $nome,
                'status'      => $status,
                'finished_at' => $finishedAt,
            ],
        ];
    }

    // ─── envelopesDeGestaoDeAds() ───

    #[Test]
    public function pagina_ate_a_pagina_curta_filtra_gestao_de_ads_e_descarta_funcionario_e_locacao(): void
    {
        // Página 1: 100 itens (página CHEIA — força ir buscar a página 2,
        // sem depender de nenhum contador total, que 'enviar()' descarta).
        $pagina1 = [];
        for ($i = 1; $i <= 97; $i++) {
            $pagina1[] = $this->envelope("outro-{$i}", "Locação de Auditório {$i}");
        }
        $pagina1[] = $this->envelope('mentoria-1', 'Contrato de Mentoria — Sócios');
        $pagina1[] = $this->envelope('funcionario-1', 'CONTRATO PRESTAÇÃO DE SERVIÇOS - Jessica De Oliveira');
        $pagina1[] = $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - Empresa Um', 'closed', '2026-01-10T00:00:00-03:00');
        $this->assertCount(100, $pagina1);

        // Página 2: 2 itens (CURTA — sinal de fim da varredura).
        $pagina2 = [
            $this->envelope('ads-2', 'Contrato Gestão de ADS _ ECF - Empresa Dois', 'closed', '2026-02-01T00:00:00-03:00'),
            $this->envelope('ads-3-draft', 'contrato_gestao_ads_meli_EMPRESA_TRES', 'draft'),
        ];

        Http::fake([
            self::BASE . '/envelopes*' => Http::sequence()
                ->push(['data' => $pagina1], 200)
                ->push(['data' => $pagina2], 200),
        ]);

        $resultado = $this->servico()->envelopesDeGestaoDeAds();

        // Só 2 requisições — página 2 veio curta e a varredura parou; não
        // buscou uma página 3 "só para conferir".
        Http::assertSentCount(2);

        $ids = array_column($resultado, 'id');
        sort($ids);
        $this->assertSame(['ads-1', 'ads-2'], $ids);

        // funcionário, locação e mentoria nunca aparecem — o filtro mira
        // gestão de ADS, não "qualquer contrato".
        $this->assertNotContains('funcionario-1', $ids);
        $this->assertNotContains('mentoria-1', $ids);
        $this->assertNotContains('outro-1', $ids);

        // situação default é só 'closed' — o draft de gestão de ADS fica
        // fora sem pedir explicitamente.
        $this->assertNotContains('ads-3-draft', $ids);

        $ads1 = collect($resultado)->firstWhere('id', 'ads-1');
        $this->assertSame('Contrato Gestao de Ads ECF - Empresa Um', $ads1['nome']);
        $this->assertSame('closed', $ads1['situacao']);
        $this->assertSame('2026-01-10T00:00:00-03:00', $ads1['data']);
    }

    #[Test]
    public function situacoes_customizadas_incluem_o_draft_de_gestao_de_ads(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::response([
                'data' => [
                    $this->envelope('ads-fechado', 'Contrato Gestao de Ads ECF - Empresa Um', 'closed'),
                    $this->envelope('ads-rascunho', 'Contrato Gestao de Ads ECF - Empresa Dois', 'draft'),
                ],
            ], 200),
        ]);

        $resultado = $this->servico()->envelopesDeGestaoDeAds(situacoes: ['closed', 'draft']);

        $ids = array_column($resultado, 'id');
        sort($ids);
        $this->assertSame(['ads-fechado', 'ads-rascunho'], $ids);
    }

    #[Test]
    public function limite_para_a_varredura_assim_que_atinge_a_quantidade_pedida(): void
    {
        $pagina1 = [
            $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - Empresa Um', 'closed'),
            $this->envelope('ads-2', 'Contrato Gestao de Ads ECF - Empresa Dois', 'closed'),
            $this->envelope('ads-3', 'Contrato Gestao de Ads ECF - Empresa Três', 'closed'),
        ];

        Http::fake([
            self::BASE . '/envelopes*' => Http::response(['data' => $pagina1], 200),
        ]);

        $resultado = $this->servico()->envelopesDeGestaoDeAds(limite: 2);

        $this->assertCount(2, $resultado);
    }

    // ─── pareceGestaoDeAds() ───

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function nomesEExpectativa(): array
    {
        return [
            'padrão antigo (underscore)'      => ['contrato_gestao_ads_meli_WEHOUSE_SERVICOS_DIGITAIS_LTDA', true],
            'padrão intermediário (hífen)'     => ['Contrato Gestao de Ads ECF - KAITON COMERCIO LTDA', true],
            'padrão novo (acento + underscore)' => ['Contrato Gestão de ADS _ ECF - ALUMEN COMERCIO', true],
            'funcionário — prestação de serviços' => ['CONTRATO PRESTAÇÃO DE SERVIÇOS - Jessica De Oliveira', false],
            'locação de auditório'             => ['Locação de Auditório — Reunião Anual', false],
            'mentoria'                          => ['Contrato de Mentoria — Sócios', false],
            'memorando'                          => ['Memorando de Entendimento — Parceria X', false],
        ];
    }

    #[Test]
    #[DataProvider('nomesEExpectativa')]
    public function parece_gestao_de_ads_reconhece_os_padroes_medidos_e_rejeita_o_resto(string $nome, bool $esperado): void
    {
        $this->assertSame($esperado, $this->servico()->pareceGestaoDeAds($nome));
    }

    // ─── baixarArquivo() ───

    private function caminhoTemporario(): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'phase140-');
        // tempnam já cria o arquivo vazio; o teste confere só depois do
        // download, então apagar agora prova que quem cria o conteúdo é o
        // serviço, não um resquício do tempnam.
        @unlink($caminho);

        return $caminho;
    }

    #[Test]
    public function baixar_arquivo_lista_documentos_e_baixa_pelo_link_original_na_sequencia_imediata(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response([
                'data' => [
                    [
                        'id'         => self::DOCUMENT_ID,
                        'type'       => 'documents',
                        'attributes' => [
                            'filename' => 'contrato.pdf',
                        ],
                        'links' => [
                            'files' => [
                                'original' => 'https://s3.example.com/original.pdf?X-Amz-Expires=299',
                                'signed'   => 'https://s3.example.com/signed.pdf?X-Amz-Expires=299',
                                'ziped'    => 'https://s3.example.com/ziped.zip?X-Amz-Expires=299',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://s3.example.com/original.pdf*' => Http::response('%PDF-conteudo-fake-de-teste', 200),
        ]);

        $caminho = $this->caminhoTemporario();

        $resultado = $this->servico()->baixarArquivo(self::ENVELOPE_ID, $caminho);

        $this->assertTrue($resultado['ok']);
        $this->assertNull($resultado['motivo']);
        $this->assertSame('contrato.pdf', $resultado['nome_arquivo']);

        $this->assertFileExists($caminho);
        $this->assertSame('%PDF-conteudo-fake-de-teste', file_get_contents($caminho));

        // O link 'original' é preferido — signed/ziped nunca chegam a ser
        // baixados quando original está presente.
        Http::assertSent(fn ($request) => $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://s3.example.com/original.pdf'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://s3.example.com/signed.pdf'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://s3.example.com/ziped.zip'));

        @unlink($caminho);
    }

    #[Test]
    public function baixar_arquivo_sem_bloco_de_arquivo_devolve_motivo_sem_lancar_excecao(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response([
                'data' => [
                    [
                        'id'         => self::DOCUMENT_ID,
                        'type'       => 'documents',
                        'attributes' => ['filename' => 'contrato.pdf'],
                        // sem 'links'/'files' — envelope ainda não
                        // materializou o arquivo (ex.: em draft).
                    ],
                ],
            ], 200),
        ]);

        $caminho = $this->caminhoTemporario();

        $resultado = $this->servico()->baixarArquivo(self::ENVELOPE_ID, $caminho);

        $this->assertFalse($resultado['ok']);
        $this->assertNotNull($resultado['motivo']);
        $this->assertNull($resultado['nome_arquivo']);
        $this->assertFileDoesNotExist($caminho);
    }

    #[Test]
    public function baixar_arquivo_sem_nenhum_documento_devolve_motivo_sem_lancar_excecao(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(['data' => []], 200),
        ]);

        $caminho = $this->caminhoTemporario();

        $resultado = $this->servico()->baixarArquivo(self::ENVELOPE_ID, $caminho);

        $this->assertFalse($resultado['ok']);
        $this->assertNotNull($resultado['motivo']);
        $this->assertNull($resultado['nome_arquivo']);
    }

    // ─── pausa entre chamadas ───

    #[Test]
    public function com_pausa_zero_a_varredura_de_duas_paginas_nao_espera(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::sequence()
                ->push(['data' => array_fill(0, 100, $this->envelope('x', 'Locação de Auditório'))], 200)
                ->push(['data' => []], 200),
        ]);

        $inicio = hrtime(true);
        $this->servico(pausaMs: 0)->envelopesDeGestaoDeAds();
        $duracaoMs = (hrtime(true) - $inicio) / 1_000_000;

        // Threshold generoso — só precisa provar que NÃO houve os 2×3500ms
        // que a pausa padrão causaria.
        $this->assertLessThan(1000, $duracaoMs);
    }
}
