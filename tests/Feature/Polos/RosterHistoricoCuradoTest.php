<?php

// Roster de mês FECHADO: o CSV da Comercial lista toda a base de sellers, não só quem
// entrou no programa Polos. O roster real é curado à mão no MlbEmpresa (Kanban).
//
// Incidente (02/09/2026): agosto reconstruía 185 ativos contra os 133 da planilha. As 40
// excedentes eram apelidos crus do ML ('PISA20240413123113', 'DACA20240228101723') que
// nunca foram onboardadas, nunca tiveram snapshot de faturamento e por isso entravam no
// gráfico como "Não vendeu" — derrubando o "no alvo" de 57,7% para 43,8%. Elas não
// venderam zero: nunca foram medidas.

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** @group polos */
class RosterHistoricoCuradoTest extends TestCase
{
    use RefreshDatabase;

    /** Reconstrução de mês fechado: $parcial = false. */
    private function rosterFechado(array $linhas): array
    {
        $m = new ReflectionMethod(PolosController::class, 'montarAtivosDoMes');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), '202608', false, $linhas);
    }

    /** MESES_NO_PROGRAMA: 1→M2, 2→M3, 3→M4. */
    private function linha(string $cust, int $mesesNoPrograma, string $nick = 'Loja'): array
    {
        return [
            'TIM_MONTH_ID'      => '202608',
            'CUS_CUST_ID_SEL'   => $cust,
            'MESES_NO_PROGRAMA' => (string) $mesesNoPrograma,
            'CUS_NICKNAME'      => $nick,
            'LOCALIDADE'        => 'Arapongas',
        ];
    }

    private function empresaPolos(string $cust, string $nome, ?string $arquivadoEm = null): MlbEmpresa
    {
        return MlbEmpresa::create([
            'nome' => $nome, 'cust_id' => $cust, 'fase' => 'M2', 'projeto' => 'POLOS',
            'polo' => 'Arapongas', 'tipo' => 'POLO', 'arquivado_em' => $arquivadoEm,
        ]);
    }

    private function custs(array $roster): array
    {
        return array_column($roster, 'cust_id');
    }

    public function test_seller_do_csv_fora_do_cadastro_nao_entra_no_roster(): void
    {
        // O caso 'PISA20240413123113': está no CSV da Comercial, nunca entrou no programa.
        $this->empresaPolos('1111111111', 'Empresa Curada');

        $roster = $this->rosterFechado([
            $this->linha('1111111111', 1, 'Empresa Curada'),
            $this->linha('9999999999', 2, 'PISA20240413123113'),
        ]);

        $this->assertSame(['1111111111'], $this->custs($roster),
            'Seller que não está no MlbEmpresa não pode virar "ativo" — ele nunca foi '
            .'medido e entraria no gráfico como "Não vendeu".');
    }

    public function test_empresa_arquivada_hoje_continua_no_roster_do_mes_passado(): void
    {
        // Arquivar é evento de HOJE; não apaga o fato de a empresa ter sido ativa no mês.
        // (Spinella Decor: arquivada por engano em 18/07 faturando ~R$ 900 mil/mês.)
        $this->empresaPolos('2222222222', 'Arquivada Depois', '2026-09-01 10:00:00');

        $roster = $this->rosterFechado([$this->linha('2222222222', 3, 'Arquivada Depois')]);

        $this->assertSame(['2222222222'], $this->custs($roster),
            'Filtrar por arquivado_em aqui reabriria o buraco que sumiu com a Spinella.');
    }

    public function test_empresa_de_outro_projeto_nao_entra(): void
    {
        // Escopo rígido: o painel de Polos nunca conta MLB de publicador.
        MlbEmpresa::create([
            'nome' => 'Assessoria X', 'cust_id' => '3333333333', 'fase' => 'M2',
            'projeto' => 'Assessoria', 'polo' => 'Arapongas', 'tipo' => 'POLO',
        ]);

        $this->assertSame([], $this->custs($this->rosterFechado([
            $this->linha('3333333333', 1, 'Assessoria X'),
        ])));
    }

    public function test_fase_vem_do_csv_e_nao_do_cadastro_atual(): void
    {
        // A fase gravada é a de HOJE (o time avança todas na virada do mês). No histórico
        // vale o MESES_NO_PROGRAMA daquele mês — senão agosto é lido com a régua de setembro.
        $this->empresaPolos('4444444444', 'Subiu De Fase'); // gravada como M2

        $roster = $this->rosterFechado([$this->linha('4444444444', 3, 'Subiu De Fase')]);

        $this->assertSame('M4', $roster[0]['fase'],
            'MESES_NO_PROGRAMA=3 é M4 naquele mês, independente da fase atual do cadastro.');
    }

    public function test_meses_fora_da_faixa_continuam_excluidos(): void
    {
        // 0 = M1 (tem coorte própria); >=4 = já saiu do programa.
        $this->empresaPolos('5555555555', 'Onboarding');
        $this->empresaPolos('6666666666', 'Graduada');

        $this->assertSame([], $this->custs($this->rosterFechado([
            $this->linha('5555555555', 0, 'Onboarding'),
            $this->linha('6666666666', 4, 'Graduada'),
        ])));
    }
}
