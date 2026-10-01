<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubEixoValor;
use App\Models\PubRascunho;
use App\Models\PubVariante;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que o núcleo puro decide tem de voltar igual do banco — inclusive o que
 * NÃO pode se perder: variante órfã com os dados, valor de eixo removido que
 * uma órfã ainda usa, e o preço que a pessoa não digitou (que não é gravado).
 */
class RascunhoRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private RascunhoRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RascunhoRepository();
    }

    private function rascunho(): PubRascunho
    {
        $empresa = Company::factory()->create();
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira Executiva']);

        return $this->repo->criar($oferta, [new Alvo('gold_special', 'Cadeira Executiva ECF'), new Alvo('gold_pro', null)]);
    }

    private static function cor(array $nomes): Eixo
    {
        $ids = ['Preto' => '52049', 'Azul' => '52028', 'Branco' => '52055'];

        return new Eixo('COLOR', 'Cor', 0, true, array_map(fn ($n) => new ValorEixo($ids[$n], $n), $nomes));
    }

    public function test_nasce_com_a_variante_unica_e_os_alvos(): void
    {
        $s = $this->repo->snapshot($this->rascunho());

        $this->assertSame([ChaveCanonica::UNICA], array_map(fn ($v) => $v->chave, $s->variantes));
        $this->assertSame(['gold_special', 'gold_pro'], array_map(fn ($a) => $a->listingTypeId, $s->alvos));
        $this->assertNull($s->alvos[1]->titulo, 'título vazio herda o planejado — e não é gravado');
    }

    public function test_ida_e_volta_de_eixos_variantes_atributos_e_precos(): void
    {
        $r = $this->rascunho();
        $s = $this->repo->snapshot($r);
        $unica = $s->variantes[0]->comDados(['estoque' => 3, 'precos' => ['gold_special' => 150.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]]);

        $eixos = [self::cor(['Preto', 'Azul'])];
        $regen = RegeneradorVariantes::regenerar([$unica], $eixos);
        $this->repo->gravarVariacao($r, $eixos, $regen->variantes);
        $this->repo->gravarAtributos($r, ['BRAND' => ['value_name' => 'ECF'], 'MODEL' => ['value_name' => 'Executiva', 'origem' => 'migrated', 'revisar' => true]]);

        $volta = $this->repo->snapshot($r->fresh());

        $this->assertEquals($eixos, $volta->eixos);
        $this->assertSame(['COLOR=id:52049', 'COLOR=id:52028'], array_map(fn ($v) => $v->chave, $volta->variantes));
        $this->assertSame(['estoque' => 3, 'precos' => ['gold_special' => 150.0, 'gold_pro' => null], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]], $volta->variantes[0]->dados);
        $this->assertEquals(new ValorEixo('52049', 'Preto'), $volta->variantes[0]->valores['COLOR']);
        $this->assertTrue($volta->atributos['MODEL']['revisar']);
        $this->assertSame('migrated', $volta->atributos['MODEL']['origem']);
        $this->assertSame(0, PubVariante::where('combinacao_chave', ChaveCanonica::UNICA)->count(), 'a única foi absorvida');
    }

    public function test_preco_nao_digitado_vem_dos_efetivos_e_nao_volta_para_o_banco(): void
    {
        $r = $this->rascunho();
        $s = $this->repo->snapshot($r)->comEfetivos(['gold_pro' => 'Cadeira Planejada Premium'], ['gold_special' => 149.9, 'gold_pro' => 169.9]);

        $this->assertSame('Cadeira Executiva ECF', $s->alvos[0]->titulo, 'o digitado vence');
        $this->assertSame('Cadeira Planejada Premium', $s->alvos[1]->titulo);
        $this->assertSame(['gold_special' => 149.9, 'gold_pro' => 169.9], $s->variantes[0]->dados['precos']);

        // Os efetivos não voltam para o banco: relido, o rascunho continua sem título e sem preço digitados.
        $doBanco = $this->repo->snapshot($r->fresh());
        $this->assertNull($doBanco->alvos[1]->titulo);
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $doBanco->variantes[0]->dados['precos']);
    }

    public function test_valor_removido_vira_orfa_com_dados_e_readicionar_recupera(): void
    {
        $r = $this->rascunho();
        $eixos = [self::cor(['Preto', 'Azul'])];
        $variantes = array_map(fn ($v) => $v->comDados(['estoque' => $v->valores['COLOR']->valueName === 'Azul' ? 7 : 1]), RegeneradorVariantes::regenerar($this->repo->snapshot($r)->variantes, $eixos)->variantes);
        $this->repo->gravarVariacao($r, $eixos, $variantes);

        // Tira o Azul.
        $semAzul = [self::cor(['Preto'])];
        $regen = RegeneradorVariantes::regenerar($this->repo->snapshot($r->fresh())->variantes, $semAzul);
        $this->repo->gravarVariacao($r->fresh(), $semAzul, $regen->variantes);

        $s = $this->repo->snapshot($r->fresh());
        $azul = collect($s->variantes)->firstWhere('chave', 'COLOR=id:52028');
        $this->assertTrue($azul->orfa);
        $this->assertSame(7, $azul->dados['estoque']);
        $this->assertEquals(new ValorEixo('52028', 'Azul'), $azul->valores['COLOR'], 'a órfã ainda sabe que é Azul');
        $this->assertSame(['Preto'], array_map(fn ($v) => $v->valueName, $s->eixos[0]->valores), 'a tela não mostra o Azul');
        $this->assertTrue(PubEixoValor::where('value_name', 'Azul')->value('removido'));

        // Readiciona.
        $regen = RegeneradorVariantes::regenerar($s->variantes, $eixos);
        $this->repo->gravarVariacao($r->fresh(), $eixos, $regen->variantes);
        $azul = collect($this->repo->snapshot($r->fresh())->variantes)->firstWhere('chave', 'COLOR=id:52028');
        $this->assertFalse($azul->orfa);
        $this->assertSame(7, $azul->dados['estoque']);
        $this->assertFalse(PubEixoValor::where('value_name', 'Azul')->value('removido'));
    }

    public function test_eixo_removido_sem_orfa_some_do_banco(): void
    {
        $r = $this->rascunho();
        $eixos = [self::cor(['Preto'])];
        $this->repo->gravarVariacao($r, $eixos, RegeneradorVariantes::regenerar($this->repo->snapshot($r)->variantes, $eixos)->variantes);

        $regen = RegeneradorVariantes::regenerar($this->repo->snapshot($r->fresh())->variantes, []);
        $this->repo->gravarVariacao($r->fresh(), [], $regen->variantes);

        $this->assertSame(0, $r->eixos()->count());
        $this->assertSame([ChaveCanonica::UNICA], array_map(fn ($v) => $v->chave, $this->repo->snapshot($r->fresh())->variantes));
    }

    public function test_variante_publicada_nunca_e_apagada(): void
    {
        $r = $this->rascunho();
        $eixos = [self::cor(['Preto', 'Azul'])];
        $variantes = array_map(fn (Variante $v) => $v->valores['COLOR']->valueName === 'Azul' ? $v->comPublicada(true) : $v, RegeneradorVariantes::regenerar($this->repo->snapshot($r)->variantes, $eixos)->variantes);
        $this->repo->gravarVariacao($r, $eixos, $variantes);

        // Mesmo que alguém mande a lista sem ela, ela fica.
        $this->repo->gravarVariacao($r->fresh(), [self::cor(['Preto'])], [collect($variantes)->firstWhere('chave', 'COLOR=id:52049')]);

        $this->assertTrue(PubVariante::where('combinacao_chave', 'COLOR=id:52028')->value('publicada'));
    }

    public function test_combinacao_repetida_e_barrada_pelo_banco(): void
    {
        $r = $this->rascunho();

        $this->expectException(QueryException::class);
        $r->variantes()->create(['combinacao_chave' => ChaveCanonica::UNICA, 'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA)]);
    }

    public function test_excluir_a_oferta_leva_o_rascunho(): void
    {
        $r = $this->rascunho();

        $r->oferta->delete();

        $this->assertSame(0, PubRascunho::count());
        $this->assertSame(0, PubVariante::count());
    }

    public function test_editar_sobe_a_revisao_e_derruba_a_validacao(): void
    {
        $r = $this->rascunho();
        $r->update(['status' => PubRascunho::VALIDATED]);

        $this->repo->tocar($r);

        $this->assertSame(2, $r->fresh()->revisao);
        $this->assertSame(PubRascunho::DRAFT, $r->fresh()->status);
    }
}
