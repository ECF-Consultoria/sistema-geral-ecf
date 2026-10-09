<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaSugestaoDescartada;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-17 (D-28, D-30, D-31): filtro Status por avisos, resumo dos cartões,
 * ambientes de cada grupo de família e hora da carga, tudo no servidor.
 *
 * Modos de falha que estes testes impedem: o status divergir do que a página mostra
 * como aviso, a contagem por status não fechar (prontas + com_aviso = todas), o
 * resumo mudar com página/filtro/aba e os ambientes do grupo vazarem de outra família.
 */
class StatusEResumoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @return array{0: Company, 1: \App\Support\Portal\AtorDoPortal, 2: array} */
    private function cenario(): array
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $ator    = $this->atorCliente($empresa);

        return [$empresa, $ator, $this->catalogoSintetico($empresa, $ator)];
    }

    private function listar(Company $empresa, array $filtros = [], int $pagina = 1): array
    {
        return app(ListaDeSugestoes::class)->listar($empresa, $filtros, $pagina);
    }

    /** Todos os itens de todas as páginas do filtro (com os Combos de todas as famílias expandidos). */
    private function todos(Company $empresa, array $filtros = []): array
    {
        $filtros += ['combos' => $this->combosDeTodas($empresa)];
        $r      = $this->listar($empresa, $filtros);
        $itens  = $r['itens'];
        for ($p = 2; $p <= $r['paginacao']['paginas']; $p++) {
            $itens = array_merge($itens, $this->listar($empresa, $filtros, $p)['itens']);
        }

        return $itens;
    }

    private function chaves(array $itens): array
    {
        return array_column($itens, 'chave');
    }

    private function valorDaFamilia(array $r, string $nome): string
    {
        foreach ($r['familias'] as $f) {
            if ($f['nome'] === $nome) {
                return $f['valor'];
            }
        }
        $this->fail("Família {$nome} fora das opções.");
    }

    private function temAviso(array $item): bool
    {
        return $item['avisos'] !== [] || $item['sku_repetido'] === true || $item['logistica']['sem_medida'] !== [];
    }

    public function test_particao_prontas_e_com_aviso(): void
    {
        [$empresa] = $this->cenario();

        $base = $this->listar($empresa);
        $ps   = $base['por_status'];
        $this->assertSame(29, $ps['todas']);
        $this->assertSame(29, $ps['prontas'] + $ps['com_aviso']);
        $this->assertGreaterThanOrEqual(1, $ps['com_aviso']);

        $com = $this->listar($empresa, ['status' => 'com_aviso']);
        $pro = $this->listar($empresa, ['status' => 'prontas']);
        $this->assertSame($ps['com_aviso'], $com['paginacao']['total']);
        $this->assertSame($ps['prontas'], $pro['paginacao']['total']);

        $chavesCom = $this->chaves($this->todos($empresa, ['status' => 'com_aviso']));
        $chavesPro = $this->chaves($this->todos($empresa, ['status' => 'prontas']));
        $this->assertSame([], array_values(array_intersect($chavesCom, $chavesPro)));
        $this->assertCount(29, array_unique(array_merge($chavesCom, $chavesPro)));

        // Os contadores não dependem do status escolhido.
        $this->assertSame($ps, $com['por_status']);
        $this->assertSame($ps, $pro['por_status']);
    }

    public function test_mesmo_veredito_da_pagina(): void
    {
        [$empresa] = $this->cenario();

        $com = $this->todos($empresa, ['status' => 'com_aviso']);
        $this->assertNotEmpty($com);
        foreach ($com as $item) {
            $this->assertTrue($this->temAviso($item), $item['chave']);
        }

        $pro = $this->todos($empresa, ['status' => 'prontas']);
        $this->assertNotEmpty($pro);
        foreach ($pro as $item) {
            $this->assertFalse($this->temAviso($item), $item['chave']);
        }
    }

    public function test_sem_medida_entra_em_com_aviso(): void
    {
        [$empresa] = $this->cenario();

        $com = $this->todos($empresa, ['status' => 'com_aviso']);
        $this->assertSame([], array_values(array_filter(
            $this->todos($empresa, ['status' => 'prontas']),
            fn ($i) => in_array('Banco Polo', array_column($i['itens'], 'produto_nome'), true)
        )));

        $comBanco = array_filter($com, fn ($i) => in_array('Banco Polo', array_column($i['itens'], 'produto_nome'), true));
        $this->assertNotEmpty($comBanco);
        foreach ($comBanco as $i) {
            $this->assertNotSame([], $i['logistica']['sem_medida']);
        }
    }

    public function test_sku_repetido_move_a_sugestao_para_com_aviso(): void
    {
        [$empresa, $ator] = $this->cenario();

        $combo = collect($this->todos($empresa, ['status' => 'prontas']))->firstWhere('fase', 'combo');
        $this->assertNotNull($combo);

        app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => $combo['sku'], 'fase' => 'simples', 'nome' => 'Feita à mão', 'logistica' => 'mercado_envios',
        ], $ator);

        $this->assertNotContains($combo['chave'], $this->chaves($this->todos($empresa, ['status' => 'prontas'])));
        $this->assertContains($combo['chave'], $this->chaves($this->todos($empresa, ['status' => 'com_aviso'])));
    }

    public function test_titulo_longo_move_a_sugestao_para_com_aviso(): void
    {
        [$empresa] = $this->cenario();

        EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Cadeira Solo')
            ->update(['nome' => str_repeat('Cadeira Solo ', 6)]);

        $com   = $this->todos($empresa, ['status' => 'com_aviso']);
        $longo = array_filter($com, fn ($i) => in_array('titulo_longo', array_column($i['avisos'], 'codigo'), true));
        $this->assertNotEmpty($longo);
        $this->assertNotEmpty(array_filter($longo, fn ($i) => $i['fase'] === 'combo'));
    }

    public function test_combina_com_familia(): void
    {
        [$empresa] = $this->cenario();

        $polo = $this->valorDaFamilia($this->listar($empresa), 'Polo');
        $r    = $this->listar($empresa, ['familia' => $polo, 'status' => 'com_aviso']);

        $this->assertSame($r['paginacao']['total'], $r['por_fase']['todas']);
        $this->assertSame($r['por_fase']['todas'], $r['por_fase']['combo'] + $r['por_fase']['kit'] + $r['por_fase']['combit']);

        $opcao = collect($r['familias'])->firstWhere('valor', $polo);
        $this->assertSame($r['paginacao']['total'], $opcao['total']);
    }

    public function test_so_o_status_ja_conta_como_filtro_ativo(): void
    {
        [$empresa] = $this->cenario();

        $r = $this->listar($empresa, ['status' => 'prontas']);

        $this->assertNotEmpty($r['chaves_filtradas']);
        $this->assertLessThanOrEqual($r['limites']['lote'], count($r['chaves_filtradas']));
        $prontas = $this->chaves($this->todos($empresa, ['status' => 'prontas']));
        foreach ($r['chaves_filtradas'] as $c) {
            $this->assertContains($c, $prontas);
        }
    }

    public function test_status_desconhecido_vira_sem_filtro(): void
    {
        [$empresa] = $this->cenario();

        $base = $this->listar($empresa);
        $lixo = $this->listar($empresa, ['status' => 'lixo']);

        $this->assertSame(29, $lixo['paginacao']['total']);
        $this->assertSame($base['por_status'], $lixo['por_status']);
    }

    public function test_status_so_vale_na_aba_pendentes(): void
    {
        [$empresa] = $this->cenario();

        $banco = collect($this->todos($empresa))->first(fn ($i) => in_array('Banco Polo', array_column($i['itens'], 'produto_nome'), true));
        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $banco['chave'], 'fase' => $banco['fase']]);

        $sem = $this->listar($empresa, ['aba' => 'descartadas']);
        $com = $this->listar($empresa, ['aba' => 'descartadas', 'status' => 'prontas']);
        $this->assertSame($this->chaves($sem['itens']), $this->chaves($com['itens']));
        $this->assertCount(1, $com['itens']);

        $zeros = ['todas' => 0, 'prontas' => 0, 'com_aviso' => 0];
        $this->assertSame($zeros, $sem['por_status']);
        $this->assertSame($zeros, $this->listar($empresa, ['aba' => 'sem_tipo'])['por_status']);
    }

    public function test_resumo_do_conjunto_inteiro_e_igual_em_qualquer_visao(): void
    {
        [$empresa] = $this->cenario();

        $esperado = ['total' => 29, 'combo' => 15, 'kit' => 6, 'combit' => 8];
        $this->assertSame($esperado, $this->listar($empresa)['resumo']);
        $this->assertSame($esperado, $this->listar($empresa, [], 2)['resumo']);
        $this->assertSame($esperado, $this->listar($empresa, ['fase' => 'kit', 'q' => 'polo'])['resumo']);
        $this->assertSame($esperado, $this->listar($empresa, ['status' => 'com_aviso'])['resumo']);
        $this->assertSame($esperado, $this->listar($empresa, ['aba' => 'sem_tipo'])['resumo']);
        $this->assertSame($esperado, $this->listar($empresa, ['aba' => 'descartadas'])['resumo']);

        $kit = collect($this->todos($empresa, ['fase' => 'kit']))->first();
        EstruturaSugestaoDescartada::create(['company_id' => $empresa->id, 'chave' => $kit['chave'], 'fase' => 'kit']);

        $r = $this->listar($empresa);
        $this->assertSame(['total' => 28, 'combo' => 15, 'kit' => 5, 'combit' => 8], $r['resumo']);
        $this->assertSame($r['contagens']['sugestoes'], $r['resumo']['total']);
    }

    public function test_ambientes_por_familia(): void
    {
        [$empresa] = $this->cenario();

        $base = $this->listar($empresa);
        $this->assertSame(array_keys($base['familia_totais']), array_keys($base['familia_ambientes']));
        $this->assertArrayHasKey('sem', $base['familia_ambientes']);

        $polo   = $this->valorDaFamilia($base, 'Polo');
        $itens  = $this->todos($empresa, ['familia' => $polo]);
        $uniao  = [];
        foreach ($itens as $i) {
            foreach ($i['ambientes'] as $a) {
                $uniao[$a] = true;
            }
        }
        $uniao = array_keys($uniao);
        sort($uniao);

        $ambientes = $base['familia_ambientes'][$polo];
        $ordenado  = $ambientes;
        sort($ordenado);
        $this->assertSame($uniao, $ordenado);
        $this->assertContains('Quarto', $ambientes);
        $this->assertContains('Sala de jantar', $ambientes);
        $this->assertSame(count($ambientes), count(array_unique($ambientes)));

        $kit   = $this->listar($empresa, ['fase' => 'kit']);
        $dosKits = [];
        foreach ($this->todos($empresa, ['fase' => 'kit', 'familia' => $polo]) as $i) {
            foreach ($i['ambientes'] as $a) {
                $dosKits[$a] = true;
            }
        }
        foreach ($kit['familia_ambientes'][$polo] as $a) {
            $this->assertArrayHasKey($a, $dosKits);
        }
    }

    public function test_isolamento_entre_empresas(): void
    {
        $this->cenario();
        $outra = $this->empresaDoGabarito();

        $r = $this->listar($outra);

        $this->assertSame(['total' => 0, 'combo' => 0, 'kit' => 0, 'combit' => 0], $r['resumo']);
        $this->assertSame(['todas' => 0, 'prontas' => 0, 'com_aviso' => 0], $r['por_status']);
        $this->assertSame([], $r['familia_ambientes']);
    }

    public function test_hora_da_carga_em_iso_com_fuso(): void
    {
        [$empresa] = $this->cenario();

        Carbon::setTestNow(Carbon::parse('2026-10-07 14:30:00', 'America/Sao_Paulo'));
        try {
            $this->assertSame('2026-10-07T14:30:00-03:00', $this->listar($empresa)['gerado_em']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
