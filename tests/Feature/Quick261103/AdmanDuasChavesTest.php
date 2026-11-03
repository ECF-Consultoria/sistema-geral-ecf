<?php

namespace Tests\Feature\Quick261103;

use App\Services\AdmanService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Desde 03/11/2026 a Adman separa as contas da ECF em duas chaves de
 * integrador sem sobreposição: a principal (Performance) e a dos Polos. Com a
 * chave errada ela responde HTTP 500 "Customer not found". O falso abaixo imita
 * isso: cada conta só responde pela chave que a enxerga.
 */
class AdmanDuasChavesTest extends TestCase
{
    private const PRINCIPAL = 'CHAVE-PRINCIPAL';
    private const POLOS     = 'CHAVE-POLOS';

    /** Conta de Performance (só a chave principal enxerga). */
    private const CONTA_PERFORMANCE = '267873368';

    /** Contas dos Polos (só a chave dos Polos enxerga), em duas páginas de /accounts. */
    private const CONTAS_POLOS_PAGINAS = [['3397869845', '3165006797'], ['1044728385']];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.adman.base_url'      => 'https://adman.test/v1',
            'services.adman.api_key'       => self::PRINCIPAL,
            'services.adman.polos_api_key' => self::POLOS,
        ]);
    }

    /** Falso da Adman: /accounts por chave (paginado) e /performance só pela chave dona da conta. */
    private function fakeAdman(int $statusContasPolos = 200, ?array $paginasPolos = null): void
    {
        $paginasPolos ??= self::CONTAS_POLOS_PAGINAS;
        $contasPolos  = array_merge(...$paginasPolos);

        Http::fake(function (Request $request) use ($statusContasPolos, $paginasPolos, $contasPolos) {
            $chave = $request->header('integrator-api-key')[0] ?? '';
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($request->url(), '/meli/accounts')) {
                if ($chave === self::POLOS && $statusContasPolos !== 200) {
                    return Http::response(['message' => 'erro'], $statusContasPolos);
                }
                $pagina  = (int) ($query['page'] ?? 1);
                $paginas = $chave === self::POLOS ? $paginasPolos : [[self::CONTA_PERFORMANCE]];
                $contas  = $paginas[$pagina - 1] ?? [];

                return Http::response([
                    'accounts'   => array_map(fn ($c) => ['custId' => (int) $c, 'linked' => true], $contas),
                    'page'       => $pagina,
                    'totalPages' => count($paginas),
                ], 200);
            }

            preg_match('#/performance/([^/?]+)#', $request->url(), $m);
            $conta = $m[1] ?? '';
            $dona  = in_array($conta, $contasPolos, true) ? self::POLOS : self::PRINCIPAL;

            return $chave === $dona
                ? Http::response(['summarizedData' => ['grossBilling' => ['value' => 100.0]], 'items' => []], 200)
                : Http::response(['message' => 'Customer not found'], 500);
        });
    }

    private function chamadasDeContasDaChavePolos(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), '/meli/accounts')
            && ($r->header('integrator-api-key')[0] ?? '') === self::POLOS));
    }

    public function test_conta_dos_polos_vai_pela_chave_dos_polos_e_performance_pela_principal(): void
    {
        $this->fakeAdman();
        $adman = new AdmanService();

        $polos = $adman->fetchPerformance('3397869845', '2026-11-02', '2026-11-02');
        $perf  = $adman->fetchPerformance(self::CONTA_PERFORMANCE, '2026-11-02', '2026-11-02');

        $this->assertEquals(100, $polos['summarizedData']['grossBilling']['value']);
        $this->assertEquals(100, $perf['summarizedData']['grossBilling']['value']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/performance/3397869845')
            && $r->header('integrator-api-key')[0] === self::POLOS);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/performance/' . self::CONTA_PERFORMANCE)
            && $r->header('integrator-api-key')[0] === self::PRINCIPAL);
    }

    public function test_le_todas_as_paginas_da_lista_da_chave_dos_polos(): void
    {
        $this->fakeAdman();
        $adman = new AdmanService();

        // 1044728385 está só na 2ª página da lista.
        $adman->fetchPerformance('1044728385', '2026-11-02', '2026-11-02');

        $this->assertSame(2, $this->chamadasDeContasDaChavePolos());
        $this->assertTrue($adman->contaEstaNaChavePolos('1044728385'));
    }

    public function test_cust_id_com_formatacao_e_reconhecido(): void
    {
        $this->fakeAdman();

        $this->assertTrue((new AdmanService())->contaEstaNaChavePolos(' 3.397.869.845 '));
    }

    public function test_lista_fica_em_cache_e_nao_e_relida_a_cada_instancia(): void
    {
        $this->fakeAdman();

        (new AdmanService())->fetchPerformance('3397869845', '2026-11-02', '2026-11-02');
        (new AdmanService())->fetchPerformance('3165006797', '2026-11-02', '2026-11-02');
        (new AdmanService())->fetchPerformance(self::CONTA_PERFORMANCE, '2026-11-02', '2026-11-02');

        $this->assertSame(2, $this->chamadasDeContasDaChavePolos()); // 2 páginas, uma vez só
    }

    public function test_sem_chave_dos_polos_tudo_vai_pela_principal_sem_consultar_lista(): void
    {
        config(['services.adman.polos_api_key' => '']);
        $this->fakeAdman();
        $adman = new AdmanService();

        $adman->fetchPerformance(self::CONTA_PERFORMANCE, '2026-11-02', '2026-11-02');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/meli/accounts'));
        Http::assertSent(fn (Request $r) => $r->header('integrator-api-key')[0] === self::PRINCIPAL);
        $this->assertFalse($adman->contaEstaNaChavePolos('3397869845'));
    }

    public function test_falha_ao_reler_mantem_a_ultima_lista_boa(): void
    {
        // Lista de 7h atrás (velha) e a Adman falhando ao reler.
        Cache::forever(AdmanService::CACHE_CONTAS_CHAVE_POLOS, [
            'contas'  => ['3397869845'],
            'lido_em' => now()->subHours(7)->toIso8601String(),
        ]);
        $this->fakeAdman(statusContasPolos: 429);

        $resposta = (new AdmanService())->fetchPerformance('3397869845', '2026-11-02', '2026-11-02');

        $this->assertEquals(100, $resposta['summarizedData']['grossBilling']['value']);
        $this->assertSame(1, $this->chamadasDeContasDaChavePolos());
        $this->assertSame(['3397869845'], Cache::get(AdmanService::CACHE_CONTAS_CHAVE_POLOS)['contas']);
    }

    public function test_depois_de_uma_falha_nao_retenta_por_5_minutos(): void
    {
        $this->fakeAdman(statusContasPolos: 500);

        (new AdmanService())->contaEstaNaChavePolos('3397869845');
        (new AdmanService())->contaEstaNaChavePolos('3397869845');
        $this->assertSame(1, $this->chamadasDeContasDaChavePolos());

        $this->travel(6)->minutes();
        (new AdmanService())->contaEstaNaChavePolos('3397869845');
        $this->assertSame(2, $this->chamadasDeContasDaChavePolos());
    }

    public function test_lista_vazia_nao_apaga_a_anterior(): void
    {
        Cache::forever(AdmanService::CACHE_CONTAS_CHAVE_POLOS, [
            'contas'  => ['3397869845'],
            'lido_em' => now()->subHours(7)->toIso8601String(),
        ]);
        $this->fakeAdman(paginasPolos: [[]]);

        $this->assertNull((new AdmanService())->atualizarContasDaChavePolos());
        $this->assertSame(['3397869845'], Cache::get(AdmanService::CACHE_CONTAS_CHAVE_POLOS)['contas']);
    }

    public function test_lista_velha_e_relida_e_pega_conta_nova(): void
    {
        Cache::forever(AdmanService::CACHE_CONTAS_CHAVE_POLOS, [
            'contas'  => ['3397869845'],
            'lido_em' => now()->subHours(7)->toIso8601String(),
        ]);
        $this->fakeAdman();

        $this->assertTrue((new AdmanService())->contaEstaNaChavePolos('1044728385'));
    }

    public function test_comando_mostra_por_qual_chave_cada_conta_vai(): void
    {
        $this->fakeAdman();

        $this->artisan('adman:chaves', ['--conta' => ['3397869845', self::CONTA_PERFORMANCE]])
            ->expectsOutputToContain('Chave dos Polos: 3 contas')
            ->expectsOutputToContain('3397869845: chave dos Polos')
            ->expectsOutputToContain(self::CONTA_PERFORMANCE . ': chave principal')
            ->assertExitCode(0);
    }

    public function test_comando_sem_chave_dos_polos_avisa(): void
    {
        config(['services.adman.polos_api_key' => '']);
        $this->fakeAdman();

        $this->artisan('adman:chaves')
            ->expectsOutputToContain('ADMAN_POLOS_API_KEY não configurada')
            ->assertExitCode(0);
    }
}
