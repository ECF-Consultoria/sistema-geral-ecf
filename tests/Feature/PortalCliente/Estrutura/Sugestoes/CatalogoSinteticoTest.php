<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-08: prova a FORMA do catálogo sintético antes de existir o retrato.
 *
 * Modo de falha que impede: os planos 168-10, 168-11 e 168-13 reutilizam este
 * catálogo; se ele sair diferente do gabarito, todos eles testam a coisa errada.
 */
class CatalogoSinteticoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    public function test_a_empresa_tem_dez_produtos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $mapa = $this->catalogoSintetico($empresa, $this->atorCliente($empresa));

        $this->assertSame(10, EstruturaProduto::where('company_id', $empresa->id)->count());
        $this->assertCount(10, $mapa['produtos']);
        $this->assertCount(14, $mapa['variacoes']);
    }

    public function test_cada_variacao_tem_oferta_simples_ligada_menos_a_v203(): void
    {
        $empresa = $this->empresaDoGabarito();
        $mapa = $this->catalogoSintetico($empresa, $this->atorCliente($empresa));

        foreach ($mapa['variacoes'] as $codigo => $variacaoId) {
            $ofertas = EstruturaOferta::where('company_id', $empresa->id)->where('variacao_id', $variacaoId)->get();

            if ($codigo === 'V203') {
                $this->assertCount(0, $ofertas, 'v203 não tem oferta');
                continue;
            }

            $this->assertCount(1, $ofertas, "oferta de {$codigo}");
            $this->assertSame(EstruturaOferta::FASE_SIMPLES, $ofertas->first()->fase);
        }

        $this->assertCount(13, $mapa['ofertas']);
    }

    public function test_os_quatro_pares_do_teste_apontam_para_o_lado_certo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->catalogoSintetico($empresa, $this->atorCliente($empresa));

        $slugs = EstruturaTipoProduto::pluck('slug', 'id');
        $this->assertSame(4, EstruturaTipoPar::count());

        $esperado = [
            'cadeira|mesa'        => 'cadeira',
            'banco|mesa'          => 'banco',
            'banqueta|mesa'       => 'banqueta',
            'cama|criado-mudo'    => 'criado-mudo',
        ];

        foreach (EstruturaTipoPar::all() as $par) {
            $this->assertLessThanOrEqual($par->tipo_b_id, $par->tipo_a_id);

            $dupla = [$slugs[$par->tipo_a_id], $slugs[$par->tipo_b_id]];
            sort($dupla);
            $chave = implode('|', $dupla);
            $this->assertArrayHasKey($chave, $esperado);

            $repetido = $par->combit_repete === 'a' ? $slugs[$par->tipo_a_id] : $slugs[$par->tipo_b_id];
            $this->assertSame($esperado[$chave], $repetido, $chave);
        }
    }

    public function test_quantidades_e_ajuste_do_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $mapa = $this->catalogoSintetico($empresa, $this->atorCliente($empresa));

        $cadeira = EstruturaTipoProduto::where('slug', 'cadeira')->first();
        $this->assertSame('2, 4, 6', $cadeira->qtd_combo);
        $this->assertSame('2, 4, 6', $cadeira->qtd_combit);

        $ajuste = EstruturaProdutoGeracao::find($mapa['produtos']['Criado-mudo Polo']);
        $this->assertSame('2', $ajuste->qtd_combit);
    }

    public function test_p3_sem_volumes_e_p7_com_categoria_decoracao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $mapa = $this->catalogoSintetico($empresa, $this->atorCliente($empresa));

        $p3 = EstruturaProduto::with('variacoes.volumes')->find($mapa['produtos']['Banco Polo']);
        $this->assertSame(0, $p3->variacoes->sum(fn ($v) => $v->volumes->count()));

        $p1 = EstruturaProduto::with('variacoes.volumes')->find($mapa['produtos']['Mesa Polo']);
        $this->assertGreaterThan(0, $p1->variacoes->first()->volumes->count());

        $this->assertSame('Decoração', EstruturaProduto::find($mapa['produtos']['Peça Decorativa Polo'])->categoria_ml_nome);
    }

    public function test_uma_segunda_empresa_nao_mexe_nos_produtos_da_primeira(): void
    {
        $a = $this->empresaDoGabarito();
        $this->catalogoSintetico($a, $this->atorCliente($a));
        $antes = EstruturaProduto::where('company_id', $a->id)->orderBy('id')->pluck('id')->all();

        $b = $this->empresaDoGabarito();
        $this->catalogoSintetico($b, $this->atorCliente($b));

        $this->assertSame($antes, EstruturaProduto::where('company_id', $a->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame(10, EstruturaProduto::where('company_id', $b->id)->count());
        $this->assertSame(14, EstruturaProdutoVariacao::where('company_id', $b->id)->count());
        $this->assertSame(4, EstruturaTipoPar::count(), 'os pares são globais e não duplicam');
    }
}
