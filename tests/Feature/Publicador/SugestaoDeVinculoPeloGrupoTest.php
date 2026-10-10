<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Services\Publicador\SugestaoDeKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item F (decisões do usuário de 09/10/2026): a sugestão de vínculo
 * (`SugestaoDeKitService::porFatoDoPortal`) acha o base pela VARIAÇÃO do componente → produto do Portal
 * → grupo, e não só pela oferta âncora (que é uma cor); e não sugere o Combo de UMA cor como kit de um
 * base de VÁRIAS cores (a Fase N da família é um anúncio com todas as cores). Composto do Planejamento
 * nunca é base na heurística. Uma leitura por assunto para a conta inteira.
 */
class SugestaoDeVinculoPeloGrupoTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarPlanejamento();
    }

    private function sugestoes(): array
    {
        return app(SugestaoDeKitService::class)->candidatosDaConta($this->mlbP, $this->empresaP);
    }

    private function avulso(EstruturaOferta $oferta): PubProduto
    {
        return PubProduto::create(['oferta_id' => $oferta->id, 'company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id,
            'sku' => $oferta->sku, 'nome' => (string) $oferta->nome, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    public function test_combo_de_uma_cor_nao_e_sugerido_como_kit_do_grupo_de_varias_cores(): void
    {
        $grupo = $this->grupoP();
        $combos = $this->aceitarCombos(2);
        $preto = $this->avulso($combos['Preto']);   // a âncora do grupo: antes, o base era achado por ela
        $azul = $this->avulso($combos['Azul']);

        $s = $this->sugestoes();

        $this->assertArrayNotHasKey($preto->id, $s, 'o Combo 2 Preto não é o Kit 2 de uma cadeira de três cores');
        $this->assertArrayNotHasKey($azul->id, $s);
        $this->assertArrayNotHasKey($grupo->id, $s);
    }

    public function test_vinculo_acha_o_base_pela_segunda_cor(): void
    {
        $grupo = $this->grupoP();
        $combos = $this->aceitarCombos(2, ['Azul']);
        // A cor âncora (Preto) e o Branco saíram do Portal: o grupo ficou de UMA cor, sem a oferta âncora.
        foreach (['Preto', 'Branco'] as $cor) {
            DB::table('estrutura_ofertas')->where('id', $this->simplesP[$cor]->id)->delete();
            DB::table('estrutura_produto_variacoes')->where('id', $this->variacoesP[$cor]->id)->delete();
        }
        PubProduto::whereKey($grupo->id)->update(['oferta_id' => null]);
        $combo = $this->avulso($combos['Azul']);

        $s = $this->sugestoes()[$combo->id] ?? null;

        $this->assertNotNull($s, 'a oferta do componente é a 2ª cor: o base se acha pela variação');
        $this->assertSame($grupo->id, $s['base_id']);
        $this->assertSame(2, $s['quantidade']);
        $this->assertSame('portal', $s['origem']);
    }

    public function test_combo_de_produto_de_uma_cor_continua_sugerido_como_antes(): void
    {
        $puff = EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => 'PUFF', 'nome' => 'Puff']);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $puff->id, 'company_id' => $this->empresaP->id, 'ordem' => 0, 'codigo' => 'PUFF-AZ', 'eixo' => 'cor', 'valor' => 'Azul']);
        $simples = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $v->id, 'sku' => 'PUFF-AZ', 'fase' => 'simples', 'nome' => 'Puff']);
        $combo = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'PUFF-AZ-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Puff']);
        $combo->componentes()->create(['componente_id' => $simples->id, 'quantidade' => 2]);
        $grupo = PubProduto::create(['estrutura_produto_id' => $puff->id, 'oferta_id' => $simples->id, 'company_id' => $this->empresaP->id,
            'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'PUFF', 'nome' => 'Puff', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $avulso = $this->avulso($combo);

        $s = $this->sugestoes()[$avulso->id] ?? null;

        $this->assertNotNull($s);
        $this->assertSame($grupo->id, $s['base_id']);
        $this->assertSame(2, $s['quantidade']);
    }

    public function test_composto_do_planejamento_nunca_e_base_na_heuristica(): void
    {
        $kitPortal = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'KT-MESA', 'fase' => 'kit', 'nome' => 'Conjunto Mesa']);
        $kitPortal->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 1]);
        $kitPortal->componentes()->create(['componente_id' => $this->simplesP['Azul']->id, 'quantidade' => 1]);
        $this->avulso($kitPortal);
        $doComposto = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'X1', 'nome' => 'Kit 2 Conjunto Mesa',
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $base = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'MESA', 'nome' => 'Mesa Redonda',
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $doBase = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'X2', 'nome' => 'Kit 2 Mesa Redonda',
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $s = $this->sugestoes();

        $this->assertArrayNotHasKey($doComposto->id, $s, 'o Kit do Planejamento não é a Fase 1 de ninguém');
        $this->assertSame($base->id, $s[$doBase->id]['base_id'], 'a heurística segue valendo para o base de verdade');
    }

    public function test_combo_com_componente_de_outra_empresa_nao_acha_base(): void
    {
        $this->grupoP();
        $b = Company::factory()->create();
        $pb = EstruturaProduto::create(['company_id' => $b->id, 'codigo' => 'B', 'nome' => 'Alheio']);
        $vb = EstruturaProdutoVariacao::create(['produto_id' => $pb->id, 'company_id' => $b->id, 'ordem' => 0, 'codigo' => 'B-1', 'eixo' => 'cor', 'valor' => 'Preto']);
        $sb = EstruturaOferta::create(['company_id' => $b->id, 'variacao_id' => $vb->id, 'sku' => 'B-1', 'fase' => 'simples', 'nome' => 'B']);
        PubProduto::create(['estrutura_produto_id' => $pb->id, 'oferta_id' => $sb->id, 'company_id' => $b->id, 'sku' => 'B', 'nome' => 'B', 'origem' => PubProduto::ORIGEM_PORTAL]);
        // Dado inconsistente de propósito: o Combo é da A, o componente é da B.
        $combo = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'B-1-CB2', 'fase' => 'combo', 'nome' => 'Cruzado']);
        $combo->componentes()->create(['componente_id' => $sb->id, 'quantidade' => 2]);
        $avulso = $this->avulso($combo);

        $this->assertArrayNotHasKey($avulso->id, $this->sugestoes());
    }

    public function test_o_numero_de_consultas_nao_cresce_com_grupos_e_combos(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->sugestoes();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->grupoP();
        foreach ($this->aceitarCombos(2) as $o) {
            $this->avulso($o);
        }
        $antes = $contar();

        foreach (['MESA', 'SOFA'] as $codigo) {
            $p = EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => $codigo, 'nome' => $codigo]);
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresaP->id, 'ordem' => 0, 'codigo' => $codigo.'-1', 'eixo' => 'cor', 'valor' => 'Azul']);
            $s = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $v->id, 'sku' => $codigo.'-1', 'fase' => 'simples', 'nome' => $codigo]);
            PubProduto::create(['estrutura_produto_id' => $p->id, 'oferta_id' => $s->id, 'company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id,
                'sku' => $codigo, 'nome' => $codigo, 'origem' => PubProduto::ORIGEM_PORTAL]);
            $c = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => $codigo.'-1-CB2', 'fase' => 'combo', 'nome' => 'Combo '.$codigo]);
            $c->componentes()->create(['componente_id' => $s->id, 'quantidade' => 2]);
            $this->avulso($c);
        }

        $this->assertSame($antes, $contar(), 'T-175-34: uma leitura por assunto, nunca uma por produto');
    }
}
