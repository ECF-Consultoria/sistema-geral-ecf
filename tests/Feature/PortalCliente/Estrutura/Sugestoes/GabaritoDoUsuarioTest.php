<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaSugestaoDescartada;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Gabarito do USUÁRIO (teste dele em 08/10): mesa e cadeira de uma família, com cores
 * que não se repetem entre elas, e gabinete + espelho + lixeira de banheiro de outra.
 * Antes, a 1ª página do Planejamento só tinha Combos de cadeira: o banheiro caía em
 * "Sem tipo", mesa x cadeira dava zero Kit e o Combo vinha antes do Kit.
 *
 * Desde 08/10 os Combos de cada família chegam RECOLHIDOS ("Ver N combos"), para os Kits e
 * Combits de todas as famílias caberem na 1ª página mesmo com a mesa em 3 cores.
 *
 * Usa os tipos e pares SEMEADOS (sem ajuste de teste). Catálogo fictício com a forma
 * do teste do usuário; nenhum dado de produção.
 */
class GabaritoDoUsuarioTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @param list<array{0:string,1:string}> $coresDaMesa */
    private function catalogo(array $coresDaMesa): Company
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $ator    = $this->atorCliente($empresa);

        $volumes = [['c' => 60, 'l' => 50, 'a' => 20, 'kg' => 8]];
        $linhas = [];
        $produto = function (string $grupo, string $nome, string $familia, string $ambiente, ?string $categoria, array $cores) use (&$linhas, $volumes) {
            foreach ($cores as $i => [$codigo, $cor]) {
                $linha = [
                    'grupo' => $grupo, 'codigo' => $codigo, 'nome' => $nome,
                    'eixo' => $cor !== null ? 'cor' : null, 'valor' => $cor, 'volumes' => $volumes, 'custo' => 100,
                ];
                if ($i === 0) {
                    $linha += ['familia' => $familia, 'ambientes' => [$ambiente]] + ($categoria !== null ? ['categoria_texto' => $categoria] : []);
                }
                $linhas[] = array_filter($linha, fn ($v) => $v !== null);
            }
        };

        $produto('M', 'Mesa de Jantar Farmhouse 160cm', 'Farmhouse', 'Sala de Jantar', 'Mesas de Jantar e Cozinha', $coresDaMesa);
        $produto('C', 'Cadeira Farmhouse Estofada', 'Farmhouse', 'Sala de Jantar', 'Cadeiras de Jantar', [['C-LB', 'Linho Bege'], ['C-LC', 'Linho Cinza']]);
        // Banheiro sem categoria: o tipo sai do núcleo do nome (o nome tem dois tipos).
        $produto('G', 'Gabinete Armário Banheiro Ripado Nature com Nichos', 'Ripado Nature', 'Banheiro', null, [['G-BR', 'Branco'], ['G-NT', 'Natural']]);
        $produto('E', 'Espelho Ripado Nature com Prateleira', 'Ripado Nature', 'Banheiro', null, [['E-1', null]]);
        $produto('L', 'Lixeira Ripado Nature 5L', 'Ripado Nature', 'Banheiro', null, [['L-1', null]]);

        $r = app(ProdutoCadastroService::class)->gravarLinhas($empresa, $linhas, $ator);
        $this->assertSame([], $r['erros']);

        return $empresa;
    }

    /** O que a 1ª página precisa ter, nas listas da página. */
    private function conferirPrimeiraPagina(array $lista): void
    {
        $this->assertSame(0, $lista['contagens']['sem_tipo'], 'nada do teste do usuário fica sem tipo');

        $itens = $lista['itens'];
        $temTipos = fn (array $s, array $tipos) => array_values(array_unique(array_column($s['itens'], 'tipo'))) === $tipos;

        $this->assertNotEmpty(array_filter($itens, fn ($s) => $s['fase'] === 'kit' && $temTipos($s, ['mesa', 'cadeira'])), 'Kit mesa + cadeira na 1ª página');
        $this->assertNotEmpty(array_filter($itens, fn ($s) => $s['fase'] === 'combit' && $temTipos($s, ['mesa', 'cadeira'])), 'Combit mesa + N cadeiras na 1ª página');
        $this->assertNotEmpty(array_filter($itens, fn ($s) => $s['fase'] === 'kit' && count($s['itens']) === 2 && $s['familia']['nome'] === 'Ripado Nature'), 'Kit de 2 do banheiro na 1ª página');
        $this->assertNotEmpty(array_filter($itens, fn ($s) => $s['fase'] === 'kit' && $temTipos($s, ['gabinete', 'espelho', 'lixeira'])), 'Kit de 3 do banheiro na 1ª página');

        // A página não começa pelos Combos, e eles vêm recolhidos por família.
        $this->assertSame('kit', $itens[0]['fase']);
        $this->assertNotContains('combo', array_column($itens, 'fase'));
        $blocos = array_filter($lista['grupos'], fn ($g) => $g['combos'] !== null);
        $this->assertSame(['Farmhouse', 'Ripado Nature'], array_values(array_column($blocos, 'nome')));
    }

    public function test_a_primeira_pagina_tem_kits_de_mesa_e_cadeira_e_do_banheiro(): void
    {
        $empresa = $this->catalogo([['M-FR', 'Freijó'], ['M-OW', 'Off White']]);

        $lista = app(ListaDeSugestoes::class)->listar($empresa, [], 1);
        $this->conferirPrimeiraPagina($lista);

        // Mesa Freijó casa com a 1ª cor da cadeira, Off White com a 2ª (por posição, sem cartesiano).
        $nomes = array_column(array_filter($lista['itens'], fn ($s) => $s['fase'] === 'kit' && $s['familia']['nome'] === 'Farmhouse'), 'nome');
        sort($nomes);
        $this->assertSame([
            'Mesa de Jantar Farmhouse 160cm + Cadeira Farmhouse Estofada — Freijó / Linho Bege',
            'Mesa de Jantar Farmhouse 160cm + Cadeira Farmhouse Estofada — Off White / Linho Cinza',
        ], $nomes);
    }

    /** Com a mesa em 3 cores, sem o recolhimento o banheiro ia para a página 2. */
    public function test_mesa_em_3_cores_ainda_mostra_o_banheiro_na_pagina_1(): void
    {
        $empresa = $this->catalogo([['M-FR', 'Freijó'], ['M-OW', 'Off White'], ['M-PR', 'Preto']]);

        $lista = app(ListaDeSugestoes::class)->listar($empresa, [], 1);

        $this->conferirPrimeiraPagina($lista);
        $this->assertGreaterThan($lista['paginacao']['por_pagina'], $lista['paginacao']['total'], 'há mais sugestões que uma página');
        $this->assertSame(1, $lista['paginacao']['paginas'], 'mas todas cabem em uma página de linhas');
    }

    /** Ponta a ponta pela porta HTTP do portal: abrir a família, aceitar em lote e descartar Combos. */
    public function test_combos_recolhidos_abrem_aceitam_e_descartam_pela_tela(): void
    {
        $empresa = $this->catalogo([['M-FR', 'Freijó'], ['M-OW', 'Off White'], ['M-PR', 'Preto']]);
        $sessao  = $this->withoutVite()->entrarNoPortal($empresa);

        $props = fn (array $q = []) => $sessao->get(route('portal.auth.estrutura.sugestoes', $q))->assertOk()->viewData('page')['props'];

        $p = $props();
        $farm = collect($p['sugestoes']['grupos'])->firstWhere('nome', 'Farmhouse');
        $this->assertSame(['total' => 8, 'expandido' => false, 'mostrando' => 0], $farm['combos'], 'cadeira 2 cores x 2/4/6/8');
        $this->assertSame([], $p['filtros']['combos']);

        // Abrir a Farmhouse: os 8 Combos chegam na mesma página, com logística.
        $aberta = $props(['combos' => $farm['chave']]);
        $this->assertSame([(string) $farm['chave']], $aberta['filtros']['combos']);
        $combos = array_values(array_filter($aberta['sugestoes']['itens'], fn ($i) => $i['fase'] === 'combo'));
        $this->assertCount(8, $combos);
        $this->assertNotNull($combos[0]['logistica']);
        $kit = collect($aberta['sugestoes']['itens'])->firstWhere('fase', 'kit');

        // Aceitar em lote um Kit e um Combo aberto; descartar outro Combo.
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => [
            ['chave' => $kit['chave']], ['chave' => $combos[0]['chave']],
        ]])->assertOk()->assertJsonCount(2, 'criadas');
        $sessao->postJson(route('portal.auth.estrutura.sugestoes.descartar'), ['chaves' => [$combos[1]['chave']]])
            ->assertOk()->assertJson(['descartadas' => 1]);

        $this->assertTrue(EstruturaOferta::where('company_id', $empresa->id)->where('sku', $combos[0]['sku'])->where('fase', 'combo')->exists());
        $this->assertTrue(EstruturaSugestaoDescartada::where('company_id', $empresa->id)->where('chave', $combos[1]['chave'])->exists());

        // O bloco recolhido conta 6, e o resumo do conjunto acompanha.
        $depois = $props();
        $this->assertSame(6, collect($depois['sugestoes']['grupos'])->firstWhere('nome', 'Farmhouse')['combos']['total']);
        $this->assertSame($p['sugestoes']['resumo']['combo'] - 2, $depois['sugestoes']['resumo']['combo']);
        $this->assertNotContains($kit['chave'], array_column($depois['sugestoes']['itens'], 'chave'));
    }
}
