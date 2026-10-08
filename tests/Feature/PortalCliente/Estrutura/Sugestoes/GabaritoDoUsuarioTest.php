<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

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
 * Usa os tipos e pares SEMEADOS (sem ajuste de teste). Catálogo fictício com a forma
 * do teste do usuário; nenhum dado de produção.
 */
class GabaritoDoUsuarioTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    public function test_a_primeira_pagina_tem_kits_de_mesa_e_cadeira_e_do_banheiro(): void
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

        $produto('M', 'Mesa de Jantar Farmhouse 160cm', 'Farmhouse', 'Sala de Jantar', 'Mesas de Jantar e Cozinha', [['M-FR', 'Freijó'], ['M-OW', 'Off White']]);
        $produto('C', 'Cadeira Farmhouse Estofada', 'Farmhouse', 'Sala de Jantar', 'Cadeiras de Jantar', [['C-LB', 'Linho Bege'], ['C-LC', 'Linho Cinza']]);
        // Banheiro sem categoria: o tipo sai do núcleo do nome (o nome tem dois tipos).
        $produto('G', 'Gabinete Armário Banheiro Ripado Nature com Nichos', 'Ripado Nature', 'Banheiro', null, [['G-BR', 'Branco'], ['G-NT', 'Natural']]);
        $produto('E', 'Espelho Ripado Nature com Prateleira', 'Ripado Nature', 'Banheiro', null, [['E-1', null]]);
        $produto('L', 'Lixeira Ripado Nature 5L', 'Ripado Nature', 'Banheiro', null, [['L-1', null]]);

        $r = app(ProdutoCadastroService::class)->gravarLinhas($empresa, $linhas, $ator);
        $this->assertSame([], $r['erros']);

        $lista = app(ListaDeSugestoes::class)->listar($empresa, [], 1);

        $this->assertSame(0, $lista['contagens']['sem_tipo'], 'nada do teste do usuário fica sem tipo');
        $this->assertGreaterThan($lista['paginacao']['por_pagina'], $lista['paginacao']['total'], 'há mais de uma página');

        $itens = $lista['itens'];
        $temTipos = fn (array $s, array $tipos) => array_values(array_unique(array_column($s['itens'], 'tipo'))) === $tipos;

        $kitMesaCadeira = array_filter($itens, fn ($s) => $s['fase'] === 'kit' && $temTipos($s, ['mesa', 'cadeira']));
        $combit         = array_filter($itens, fn ($s) => $s['fase'] === 'combit' && $temTipos($s, ['mesa', 'cadeira']));
        $kitBanheiro2   = array_filter($itens, fn ($s) => $s['fase'] === 'kit' && count($s['itens']) === 2 && $s['familia']['nome'] === 'Ripado Nature');
        $kitBanheiro3   = array_filter($itens, fn ($s) => $s['fase'] === 'kit' && $temTipos($s, ['gabinete', 'espelho', 'lixeira']));

        $this->assertNotEmpty($kitMesaCadeira, 'Kit mesa + cadeira na 1ª página');
        $this->assertNotEmpty($combit, 'Combit mesa + N cadeiras na 1ª página');
        $this->assertNotEmpty($kitBanheiro2, 'Kit de 2 do banheiro na 1ª página');
        $this->assertNotEmpty($kitBanheiro3, 'Kit de 3 do banheiro na 1ª página');

        // A página não começa mais pelos Combos.
        $this->assertSame('kit', $itens[0]['fase']);

        // Mesa Freijó casa com a 1ª cor da cadeira, Off White com a 2ª (por posição, sem cartesiano).
        $nomes = array_column($kitMesaCadeira, 'nome');
        sort($nomes);
        $this->assertSame([
            'Mesa de Jantar Farmhouse 160cm + Cadeira Farmhouse Estofada — Freijó / Linho Bege',
            'Mesa de Jantar Farmhouse 160cm + Cadeira Farmhouse Estofada — Off White / Linho Cinza',
        ], $nomes);
    }
}
