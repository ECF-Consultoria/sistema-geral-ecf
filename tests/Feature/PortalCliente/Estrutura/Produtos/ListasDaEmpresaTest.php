<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaProduto;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-03: família e ambiente como listas da empresa.
 *
 * Modos de falha que estes testes impedem: "Sala estar" e "Sala Estar" virando
 * dois itens (o SQLite é binário, o MariaDB não); a lista de uma empresa
 * vazando para a de outra; item em uso sumindo e deixando produto sem família.
 */
class ListasDaEmpresaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function svc(): ListasDaEmpresaService
    {
        return app(ListasDaEmpresaService::class);
    }

    public function test_criar_reaproveita_o_mesmo_nome_por_caixa_acento_e_espacos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        [$a, $criadoA] = $this->svc()->criar($empresa, 'familia', 'Farmhouse', $ator);
        [$b, $criadoB] = $this->svc()->criar($empresa, 'familia', ' farmhouse ', $ator);

        $this->assertTrue($criadoA);
        $this->assertFalse($criadoB);
        $this->assertSame($a->id, $b->id);

        // Par por caixa e espaços; par acentuado.
        [$s1] = $this->svc()->criar($empresa, 'ambiente', 'Sala estar', $ator);
        [$s2, $criado2] = $this->svc()->criar($empresa, 'ambiente', 'Sala Estar', $ator);
        [$s3, $criado3] = $this->svc()->criar($empresa, 'ambiente', 'Sála  estar', $ator);
        [$c1] = $this->svc()->criar($empresa, 'ambiente', 'Cômoda', $ator);
        [$c2, $criadoC] = $this->svc()->criar($empresa, 'ambiente', 'Comoda', $ator);

        $this->assertSame($s1->id, $s2->id);
        $this->assertSame($s1->id, $s3->id);
        $this->assertFalse($criado2 || $criado3);
        $this->assertSame($c1->id, $c2->id);
        $this->assertFalse($criadoC);
        $this->assertSame(2, EstruturaAmbiente::where('company_id', $empresa->id)->count());
    }

    public function test_nome_invalido_e_recusado_com_a_mensagem_certa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        foreach (['Sala/Estar', 'Sala, Estar', 'Sala|Estar'] as $nome) {
            try {
                $this->svc()->criar($empresa, 'ambiente', $nome, $ator);
                $this->fail("Deveria recusar {$nome}");
            } catch (ValidationException $e) {
                $this->assertSame('Não use / , | no nome. Escolha um nome simples.', $e->errors()['nome'][0]);
            }
        }

        try {
            $this->svc()->criar($empresa, 'familia', '   ', $ator);
            $this->fail('Vazio deveria ser recusado');
        } catch (ValidationException $e) {
            $this->assertSame('Informe o nome.', $e->errors()['nome'][0]);
        }

        $this->expectException(ValidationException::class);
        $this->svc()->criar($empresa, 'familia', str_repeat('a', 81), $ator);
    }

    public function test_renomear_mantem_os_produtos_e_recusa_nome_ja_existente(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        [$fam] = $this->svc()->criar($empresa, 'familia', 'Farmhouse', $ator);
        $this->svc()->criar($empresa, 'familia', 'Moderna', $ator);
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Mesa', 'familia_id' => $fam->id]);

        $this->svc()->renomear($empresa, 'familia', $fam->id, 'Farm House', $ator);

        $this->assertSame($fam->id, $produto->fresh()->familia_id);
        $this->assertSame('Farm House', $fam->fresh()->nome);

        try {
            $this->svc()->renomear($empresa, 'familia', $fam->id, 'MODERNA', $ator);
            $this->fail('Deveria recusar nome existente');
        } catch (ValidationException $e) {
            $this->assertSame('Já existe “Moderna”.', $e->errors()['nome'][0]);
        }

        // Mudar só a caixa do próprio nome é permitido.
        $this->svc()->renomear($empresa, 'familia', $fam->id, 'farm house', $ator);
        $this->assertSame('farm house', $fam->fresh()->nome);
    }

    public function test_item_em_uso_nao_sai_e_item_livre_some(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        [$fam] = $this->svc()->criar($empresa, 'familia', 'Farmhouse', $ator);
        EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Mesa', 'familia_id' => $fam->id]);
        EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Cadeira', 'familia_id' => $fam->id]);

        try {
            $this->svc()->excluir($empresa, 'familia', $fam->id, $ator);
            $this->fail('Deveria recusar item em uso');
        } catch (ValidationException $e) {
            $this->assertSame('Em uso em 2 produtos. Troque nos produtos para poder excluir.', $e->errors()['nome'][0]);
        }
        $this->assertNotNull(EstruturaFamilia::find($fam->id));
        $this->assertSame(2, $this->svc()->lista($empresa, 'familia')[0]['em_uso']);

        [$amb] = $this->svc()->criar($empresa, 'ambiente', 'Cozinha', $ator);
        $produto = EstruturaProduto::first();
        $produto->ambientes()->attach($amb->id);

        try {
            $this->svc()->excluir($empresa, 'ambiente', $amb->id, $ator);
            $this->fail('Ambiente em uso deveria ser recusado');
        } catch (ValidationException $e) {
            $this->assertSame('Em uso em 1 produto. Troque nos produtos para poder excluir.', $e->errors()['nome'][0]);
        }

        [$livre] = $this->svc()->criar($empresa, 'ambiente', 'Quintal', $ator);
        $this->svc()->excluir($empresa, 'ambiente', $livre->id, $ator);
        $this->assertNull(EstruturaAmbiente::find($livre->id));
    }

    public function test_uma_empresa_nao_enxerga_nem_altera_a_lista_da_outra(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        $atorA = $this->atorCliente($a);
        $atorB = $this->atorCliente($b);

        [$famB] = $this->svc()->criar($b, 'familia', 'Farmhouse', $atorB);
        // Mesmo nome em outra empresa é item próprio.
        [$famA, $criado] = $this->svc()->criar($a, 'familia', 'Farmhouse', $atorA);
        $this->assertTrue($criado);
        $this->assertNotSame($famA->id, $famB->id);
        $this->assertCount(1, $this->svc()->lista($a, 'familia'));

        try {
            $this->svc()->renomear($a, 'familia', $famB->id, 'Invadida', $atorA);
            $this->fail('Deveria dar 404');
        } catch (ModelNotFoundException) {
            // esperado
        }

        try {
            $this->svc()->excluir($a, 'familia', $famB->id, $atorA);
            $this->fail('Deveria dar 404');
        } catch (ModelNotFoundException) {
            // esperado
        }

        $this->assertSame('Farmhouse', $famB->fresh()->nome);
    }

    public function test_resolver_nomes_simula_sem_ator_e_cria_com_ator(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        $sim = $this->svc()->resolverNomes($empresa, 'ambiente', ['Sala Jantar', 'sala jantar', 'Sala Estar'], null);

        $this->assertSame(['Sala Jantar', 'Sala Estar'], $sim['novos']);
        $this->assertSame([], $sim['ids']);
        $this->assertSame(0, EstruturaAmbiente::count());

        $real = $this->svc()->resolverNomes($empresa, 'ambiente', ['Sala Jantar', 'sala jantar', 'Sala Estar'], $ator);

        $this->assertCount(2, $real['ids']);
        $this->assertSame(2, EstruturaAmbiente::count());

        $deNovo = $this->svc()->resolverNomes($empresa, 'ambiente', ['SALA ESTAR'], $ator);
        $this->assertSame([], $deNovo['novos']);
        $this->assertCount(1, $deNovo['ids']);
        $this->assertSame(2, EstruturaAmbiente::count());
    }

    /** BE-WR-07: a lista sai com o uso de cada item numa consulta só, e resolver nomes lê a lista uma vez. */
    public function test_lista_e_resolver_nomes_nao_fazem_uma_consulta_por_item(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P1', 'nome' => 'P1']);
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $n) {
            [$f] = $this->svc()->criar($empresa, 'familia', "Familia {$n}", $ator);
            [$a] = $this->svc()->criar($empresa, 'ambiente', "Ambiente {$n}", $ator);
            if ($i < 2) {
                $produto->update(['familia_id' => $f->id]);
                $produto->ambientes()->attach($a->id);
            }
        }

        $consultas = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $familias = $this->svc()->lista($empresa, 'familia');
        $ambientes = $this->svc()->lista($empresa, 'ambiente');
        $this->assertSame(2, $consultas, 'uma consulta por tipo de lista, com o uso junto');
        $this->assertSame([0, 1, 0, 0, 0, 0], array_column($familias, 'em_uso'));
        $this->assertSame([1, 1, 0, 0, 0, 0], array_column($ambientes, 'em_uso'));

        $consultas = 0;
        $r = $this->svc()->resolverNomes($empresa, 'ambiente', ['ambiente a', 'AMBIENTE B', 'Ambiente C', 'Ambiente Novo'], null);
        $this->assertSame(1, $consultas, 'a lista é lida uma vez para todos os nomes');
        $this->assertCount(3, $r['ids']);
        $this->assertSame(['Ambiente Novo'], $r['novos']);
    }

    public function test_tipo_desconhecido_e_recusado(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->lista($empresa, 'cor');
    }

    public function test_toda_escrita_registra_a_origem_cliente_ou_interno(): void
    {
        $empresa = $this->empresaDoGabarito();
        $cliente = $this->atorCliente($empresa);
        $interno = AtorDoPortal::daEquipe(User::factory()->create());

        [$item] = $this->svc()->criar($empresa, 'familia', 'Farmhouse', $cliente);
        $this->svc()->renomear($empresa, 'familia', $item->id, 'Farm House', $interno);
        $this->svc()->excluir($empresa, 'familia', $item->id, $interno);

        $logs = Activity::where('log_name', 'portal')->orderBy('id')->get();
        $this->assertSame(['lista_criada', 'lista_renomeada', 'lista_excluida'], $logs->map(fn ($l) => $l->getExtraProperty('evento'))->all());
        $this->assertSame(['cliente', 'interno', 'interno'], $logs->map(fn ($l) => $l->getExtraProperty('origem'))->all());
    }
}
