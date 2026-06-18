<?php

// Phase 38 — testes Feature do EcfDriveService para os novos métodos Files.
// Cobre:
//   POL-09: listFiles() dispara GET /files com os parâmetros corretos (sem cache).
//   POL-10: fileJson() cacheia a resposta por 1h (2 chamadas → apenas 1 request HTTP).

namespace Tests\Feature\Phase38;

use App\Services\EcfDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcfDriveFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Configura o EcfDriveService com URL e key de teste
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        // Limpa cache para reprodutibilidade entre testes
        Cache::flush();
    }

    // ─── POL-09: listFiles chama GET /files ──────────────────────────────────

    /**
     * POL-09: listFiles() dispara um GET para /files passando os query params
     * fornecidos (ex: search=POLOS_MENSAL) sem envolver cache.
     */
    public function test_listFiles_chama_endpoint(): void
    {
        Http::fake([
            '*/files*' => Http::response([
                'data'  => [
                    [
                        'id'            => 'abc123',
                        'filename'      => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                        'remotePath'    => '/path/to/file.csv',
                        'localPath'     => '/local/path/file.csv',
                        'sizeBytes'     => 102400,
                        'sha256'        => 'deadbeef',
                        'remoteMtime'   => '2026-06-01T00:00:00Z',
                        'downloadedAt'  => '2026-06-01T01:00:00Z',
                        'etlStatus'     => 'pending',
                        'etlError'      => null,
                        'etlProcessedAt' => null,
                        'rowsProcessed' => null,
                    ],
                ],
                'total' => 1,
                'page'  => 1,
                'limit' => 50,
            ], 200),
        ]);

        /** @var EcfDriveService $service */
        $service  = app(EcfDriveService::class);
        $resultado = $service->listFiles(['search' => 'POLOS_MENSAL']);

        // Verifica que a resposta contém os dados esperados
        $this->assertArrayHasKey('data', $resultado);
        $this->assertCount(1, $resultado['data']);
        $this->assertSame('SFTP_ECF_COMERCIO_POLOS_MENSAL.csv', $resultado['data'][0]['filename']);

        // POL-09: verifica que o GET foi disparado com /files e search=POLOS_MENSAL
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/files')
                && str_contains($req->url(), 'search=POLOS_MENSAL');
        });
    }

    // ─── POL-10: fileJson cacheia 1h ─────────────────────────────────────────

    /**
     * POL-10: fileJson() faz o GET em /files/{id}/json na 1ª chamada e usa
     * cache na 2ª — o endpoint HTTP deve ser atingido exatamente 1 vez.
     * Prova o padrão Cache::remember com TTL=3600s e cacheKey().
     */
    public function test_fileJson_cacheia_1h(): void
    {
        Http::fake([
            '*/files/abc/json*' => Http::response([
                'filename'  => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'totalRows' => null,
                'returned'  => 3664,
                'limited'   => false,
                'rows'      => [
                    ['LOCALIDADE' => 'Arapongas', 'TGMV_LC' => '29632237', 'PROGRAMA' => 'POLOS'],
                    ['LOCALIDADE' => 'Uba',        'TGMV_LC' => '18783190', 'PROGRAMA' => 'POLOS'],
                ],
            ], 200),
        ]);

        /** @var EcfDriveService $service */
        $service = app(EcfDriveService::class);

        // 1ª chamada — deve disparar o GET
        $primeira = $service->fileJson('abc', ['limit' => 5000]);

        // 2ª chamada — deve usar cache, NÃO disparar novo GET
        $segunda = $service->fileJson('abc', ['limit' => 5000]);

        // Resultados devem ser idênticos
        $this->assertSame($primeira, $segunda);
        $this->assertArrayHasKey('rows', $primeira);
        $this->assertCount(2, $primeira['rows']);

        // POL-10: o endpoint foi atingido exatamente 1 vez (cache absorveu a 2ª chamada)
        Http::assertSentCount(1);
    }
}
