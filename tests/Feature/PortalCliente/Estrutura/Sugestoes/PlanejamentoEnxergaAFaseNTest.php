<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\Geracao\RetratoDoCatalogo;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item E (decisões do usuário de 09/10/2026): o Planejamento do Portal enxerga a
 * Fase N do Publicador. O kit de N unidades de um base agrupado já É o Combo N de cada cor do grupo, então
 * `v{cor}*N` entra nas composições existentes e a sugestão some — só leitura das tabelas do Publicador,
 * escopada pela empresa, com número fixo de consultas.
 */
class PlanejamentoEnxergaAFaseNTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarPlanejamento();
    }

    /** @return list<string> as chaves das sugestões de Combo da empresa */
    private function combosSugeridos(?Company $empresa = null): array
    {
        $chaves = [];
        foreach (app(SugestoesService::class)->gerar($empresa ?? $this->empresaP)['sugestoes'] as $s) {
            if ($s['fase'] === 'combo') {
                $chaves[] = $s['chave'];
            }
        }
        sort($chaves);

        return $chaves;
    }

    private function chave(string $cor, int $n): string
    {
        return 'v'.$this->variacoesP[$cor]->id.'*'.$n;
    }

    public function test_sem_kit_o_planejamento_sugere_o_combo_2_de_cada_cor(): void
    {
        $sugeridos = $this->combosSugeridos();

        foreach (['Preto', 'Azul', 'Branco'] as $cor) {
            $this->assertContains($this->chave($cor, 2), $sugeridos);
        }
    }

    public function test_o_kit_2_da_familia_tira_o_combo_2_de_cada_cor_das_sugestoes(): void
    {
        $this->kitAntigo($this->grupoComRascunho(), 2);

        $sugeridos = $this->combosSugeridos();
        $existentes = app(RetratoDoCatalogo::class)->daEmpresa($this->empresaP)['existentes'];

        foreach (['Preto', 'Azul', 'Branco'] as $cor) {
            $this->assertNotContains($this->chave($cor, 2), $sugeridos, "o Combo 2 {$cor} já é a Fase 2");
            $this->assertArrayHasKey($this->chave($cor, 2), $existentes);
            $this->assertContains($this->chave($cor, 4), $sugeridos, 'o Combo 4 segue sugerido: a família não tem Kit 4');
        }
        $this->assertSame(0, EstruturaOferta::where('fase', 'combo')->count(), 'só leitura: nada foi criado no Portal');
    }

    public function test_kit_de_base_que_nao_e_agrupado_nao_muda_o_planejamento(): void
    {
        $antes = $this->combosSugeridos();
        $manual = PubProduto::create(['company_id' => $this->empresaP->id, 'sku' => 'MANUAL', 'nome' => 'Manual', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        PubProduto::create(['company_id' => $this->empresaP->id, 'sku' => 'MANUAL-KIT2', 'nome' => 'Kit 2 Manual', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $manual->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $this->assertSame($antes, $this->combosSugeridos());
    }

    public function test_a_variacao_que_fica_fora_do_grupo_continua_sugerida(): void
    {
        $repetida = EstruturaProdutoVariacao::create(['produto_id' => $this->produtoP->id, 'company_id' => $this->empresaP->id, 'ordem' => 9,
            'codigo' => 'CAD-PT2', 'eixo' => 'cor', 'valor' => 'Preto']);
        EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $repetida->id, 'sku' => 'CAD-PT2', 'fase' => 'simples', 'nome' => 'Repetida']);
        $this->kitAntigo($this->grupoComRascunho(), 2);

        $this->assertContains('v'.$repetida->id.'*2', $this->combosSugeridos(), 'ela vira produto separado no Publicador, não cor do kit');
    }

    public function test_kit_de_uma_empresa_nao_mexe_no_planejamento_da_outra(): void
    {
        $this->kitAntigo($this->grupoComRascunho(), 2);
        $a = $this->empresaP;
        $variacoesA = $this->variacoesP;

        $this->montarPlanejamento(null, 'BBB');
        $b = $this->empresaP;

        $sugeridosB = $this->combosSugeridos($b);
        foreach (['Preto', 'Azul', 'Branco'] as $cor) {
            $this->assertContains('v'.$this->variacoesP[$cor]->id.'*2', $sugeridosB, 'a B não tem Kit 2');
        }
        $existentesB = app(RetratoDoCatalogo::class)->daEmpresa($b)['existentes'];
        foreach ($variacoesA as $v) {
            $this->assertArrayNotHasKey('v'.$v->id.'*2', $existentesB);
        }
        $this->assertNotContains('v'.$variacoesA['Preto']->id.'*2', $this->combosSugeridos($a));
    }

    public function test_o_numero_de_consultas_nao_cresce_com_os_kits(): void
    {
        $grupo = $this->grupoComRascunho();
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(RetratoDoCatalogo::class)->daEmpresa($this->empresaP);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->kitAntigo($grupo, 2);
        $comUm = $contar();
        $this->kitAntigo($grupo, 4);
        $this->kitAntigo($grupo, 6);

        $this->assertSame($comUm, $contar());
    }
}
