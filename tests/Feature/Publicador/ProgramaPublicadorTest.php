<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fase 160 / 160-03: programa derivado da MlbEmpresa (D13) e contas liberadas por âncora (D21). */
class ProgramaPublicadorTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(array $attrs): MlbEmpresa
    {
        return MlbEmpresa::create($attrs + ['nome' => 'E'.uniqid()])->fresh();
    }

    public function test_matriz_de_polos(): void
    {
        $casos = [
            ['projeto' => 'POLOS'],
            ['fase' => 'M2'],
            ['fase' => 'Encaminhar Comercial'],
            ['fase' => 'Aceite no Projeto'],
        ];
        foreach ($casos as $c) {
            $e = $this->empresa($c);
            $this->assertSame('polos', $e->programaPublicador(), json_encode($c));
        }
        $this->assertCount(4, MlbEmpresa::programa('polos')->get());
        $this->assertCount(0, MlbEmpresa::programa('incubadora')->get());
    }

    public function test_matriz_de_incubadora(): void
    {
        $casos = [
            ['projeto' => 'Incubadora'],
            ['fase' => 'Incubadora'],
            ['tipo' => 'INCUBADORA'],
            ['tipo' => 'INCUBADORA', 'fase' => 'M1'],
        ];
        foreach ($casos as $c) {
            $e = $this->empresa($c);
            $this->assertSame('incubadora', $e->programaPublicador(), json_encode($c));
        }
        $this->assertCount(4, MlbEmpresa::programa('incubadora')->get());
        $this->assertCount(0, MlbEmpresa::programa('polos')->get());
    }

    public function test_sem_programa(): void
    {
        $this->empresa(['projeto' => 'Assessoria']);
        $this->empresa(['projeto' => 'Implantação']);
        $this->empresa([]);

        foreach (MlbEmpresa::all() as $e) {
            $this->assertNull($e->programaPublicador());
        }
        $this->assertCount(0, MlbEmpresa::programa('polos')->get());
        $this->assertCount(0, MlbEmpresa::programa('incubadora')->get());
        $this->assertCount(0, MlbEmpresa::programa('xyz')->get());
    }

    public function test_scope_e_metodo_concordam_e_nao_se_sobrepoem(): void
    {
        foreach ([
            ['projeto' => 'POLOS'], ['projeto' => 'Incubadora'], ['fase' => 'M2'], ['fase' => 'Incubadora'],
            ['tipo' => 'INCUBADORA'], ['tipo' => 'INCUBADORA', 'fase' => 'M1'], ['projeto' => 'Assessoria'],
            ['fase' => 'M3', 'tipo' => 'POLOS'], [],
        ] as $c) {
            $this->empresa($c);
        }

        $polos = MlbEmpresa::programa('polos')->pluck('id')->all();
        $inc = MlbEmpresa::programa('incubadora')->pluck('id')->all();
        $this->assertSame([], array_intersect($polos, $inc));

        foreach (MlbEmpresa::all() as $e) {
            $this->assertSame(
                $e->programaPublicador(),
                in_array($e->id, $polos, true) ? 'polos' : (in_array($e->id, $inc, true) ? 'incubadora' : null),
            );
        }
    }

    /**
     * WR-B05: `projeto` é texto livre. Caixa e espaços nas pontas não mudam o programa — nem no
     * SQL (que no MariaDB já comparava sem caixa) nem no PHP (que era estrito e dava 404 ao abrir).
     */
    public function test_wr_b05_caixa_e_espacos_nas_pontas_dao_o_mesmo_programa_no_sql_e_no_php(): void
    {
        $esperado = [
            'polos' => [['projeto' => 'Polos'], ['projeto' => ' POLOS '], ['projeto' => 'polos  '],
                ['projeto' => '  ', 'fase' => 'm2'], ['projeto' => null, 'fase' => ' Encaminhar comercial ']],
            'incubadora' => [['projeto' => 'INCUBADORA'], ['projeto' => ' incubadora'], ['projeto' => null, 'fase' => 'incubadora '],
                ['projeto' => null, 'tipo' => 'Incubadora'], ['projeto' => null, 'fase' => 'M1', 'tipo' => ' incubadora ']],
            'nenhum' => [['projeto' => 'Polos Sul'], ['projeto' => ' assessoria '], ['projeto' => null, 'fase' => 'M9']],
        ];
        $ids = [];
        foreach ($esperado as $programa => $casos) {
            foreach ($casos as $c) {
                $e = $this->empresa($c);
                $this->assertSame($programa === 'nenhum' ? null : $programa, $e->programaPublicador(), json_encode($c));
                $ids[$programa][] = $e->id;
            }
        }

        $this->assertEqualsCanonicalizing($ids['polos'], MlbEmpresa::programa('polos')->pluck('id')->all());
        $this->assertEqualsCanonicalizing($ids['incubadora'], MlbEmpresa::programa('incubadora')->pluck('id')->all());
    }

    public function test_libera_por_ancora(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [5], 'mlb_empresas' => [9]]]);

        $c5 = new Company();
        $c5->id = 5;
        $c6 = new Company();
        $c6->id = 6;
        $e5 = new MlbEmpresa();
        $e5->id = 5;
        $e9 = new MlbEmpresa();
        $e9->id = 9;

        $this->assertTrue(ContasLiberadas::libera($c5));
        $this->assertFalse(ContasLiberadas::libera($c6));
        $this->assertTrue(ContasLiberadas::libera($e9));
        $this->assertFalse(ContasLiberadas::libera($e5), 'Company 5 liberada não libera MlbEmpresa 5');
        $this->assertFalse(ContasLiberadas::libera(null));
    }

    public function test_listas_vazias_nao_liberam_ninguem(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $c = new Company();
        $c->id = 459;
        $e = new MlbEmpresa();
        $e->id = 459;

        $this->assertFalse(ContasLiberadas::libera($c));
        $this->assertFalse(ContasLiberadas::libera($e));
    }

    public function test_exigir_lanca_conta_lib(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $c = new Company();
        $c->id = 1;

        try {
            ContasLiberadas::exigir($c);
            $this->fail('Deveria lançar RegraViolada');
        } catch (RegraViolada $e) {
            $this->assertSame('CONTA-LIB', $e->regra);
            $this->assertStringContainsString('ainda não foi liberada', $e->getMessage());
        }
    }

    public function test_config_default(): void
    {
        $this->assertSame([459], config('publicador.contas_liberadas.companies'));
        $this->assertSame([], config('publicador.contas_liberadas.mlb_empresas'));
    }
}
