<?php

namespace Tests\Feature\Trilha;

use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Trilha\Ondas\ItemDaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A régua inversa (pub_produto → item da trilha) concorda com o agrupamento da trilha (item → pub_produtos): com os
 * mesmos dados, cada pub_produto do Portal cai num item que existe em `TrilhaDaEmpresa::itens()` — é o que deixa a
 * trava do agente e a tela falarem da mesma oferta.
 */
class ItemDaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    public function test_cada_pub_produto_do_portal_cai_no_item_da_trilha_que_o_representa(): void
    {
        Http::preventStrayRequests();
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => []]);
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($empresa, 'Banqueta Beta', 'BB-1');
        $pa = $a['produto']->id;

        $grupo = $this->grupoNoPublicador($empresa, $a);
        $legado = $this->ofertaNoPublicador($empresa, $a['simples'][1]);
        $kitN = PubProduto::create(['company_id' => $empresa->id, 'produto_base_id' => $grupo->id, 'quantidade_kit' => 2,
            'sku' => 'VA-1-CB2', 'nome' => 'Kit 2', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $combo3 = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-1-CB3', 'Combo 3 Vaso Alfa Azul', [[$a['simples'][0], 3]]);
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, 'KT-VA-1-BB-1', 'Kit Alfa e Beta', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);
        $combit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBIT, 'CT4-VA-1-BB-1', 'Combit', [[$a['simples'][0], 1], [$b['simples'][0], 4]]);
        // O combo de 2 componentes não é o Combo N do produto: é um item próprio.
        $comboDuplo = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'CB-DUPLO', 'Combo duplo', [[$a['simples'][0], 2], [$b['simples'][0], 2]]);
        $semProduto = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => 'AVULSO-1', 'fase' => 'simples', 'nome' => 'Avulso']);
        $doPublicador = PubProduto::create(['company_id' => $empresa->id, 'sku' => 'PUB-1', 'nome' => 'Criado no Publicador', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $esperado = [
            [$grupo, "s-{$pa}", false],
            [$legado, "s-{$pa}", false],
            [$kitN, "c-{$pa}-2", false],
            [$this->ofertaNoPublicador($empresa, $combo3), "c-{$pa}-3", false],
            [$this->ofertaNoPublicador($empresa, $kit), "o-{$kit->id}", false],
            [$this->ofertaNoPublicador($empresa, $combit), "o-{$combit->id}", false],
            [$this->ofertaNoPublicador($empresa, $comboDuplo), "o-{$comboDuplo->id}", false],
            [$this->ofertaNoPublicador($empresa, $semProduto), "o-{$semProduto->id}", true],
        ];

        $itens = TrilhaDaEmpresa::itens($empresa);
        foreach ($esperado as [$pub, $chave, $semProdutoEsperado]) {
            $item = ItemDaTrilha::doPub($pub);
            $this->assertSame(['chave' => $chave, 'sem_produto' => $semProdutoEsperado], $item, "pub {$pub->id} ({$pub->nome})");
            // O Kit 2 (fase N) não tem oferta no Portal: o item c-A-2 só existe quando o Combo 2 é aceito lá.
            if ($chave !== "c-{$pa}-2") {
                $this->assertArrayHasKey($chave, $itens, "o item {$chave} existe na trilha");
                $this->assertSame($semProdutoEsperado, $itens[$chave]['sem_produto']);
            }
        }
        $this->assertNull(ItemDaTrilha::doPub($doPublicador), 'produto criado no Publicador não é item da trilha');
    }

    public function test_os_produtos_de_cada_item_e_o_andou_da_mesma_regua_da_tela(): void
    {
        Http::preventStrayRequests();
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => []]);
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($empresa, 'Banqueta Beta', 'BB-1');
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, 'KT-VA-1-BB-1', 'Kit Alfa e Beta', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);
        // A memória das imagens sozinha (a cota de texto acabou) já é "andou".
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $b), PubRascunho::DRAFT, ['criativos_auto' => ['adiado' => ['em' => now()->toIso8601String()]]]);

        $itens = TrilhaDaEmpresa::itens($empresa);

        $this->assertSame([(int) $a['produto']->id], $itens["s-{$a['produto']->id}"]['produtos']);
        $this->assertEqualsCanonicalizing([(int) $a['produto']->id, (int) $b['produto']->id], $itens["o-{$kit->id}"]['produtos']);
        $this->assertFalse($itens["s-{$a['produto']->id}"]['andou']);
        $this->assertTrue($itens["s-{$b['produto']->id}"]['andou']);
    }
}
