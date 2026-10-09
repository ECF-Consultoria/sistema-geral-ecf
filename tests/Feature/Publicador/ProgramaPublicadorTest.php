<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 164 / 164-03: programa derivado da MlbEmpresa (D13) e contas liberadas por âncora (D21). */
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

    // ═══════════════════════════════════════════════════════════════════════
    // Quick 261009-t01 — as duas chaves ADITIVAS da linha da tela A:
    // `erp` (o ERP DECLARADO no onboarding — decisão 8 do handoff: nunca
    // "conectado" nem "sincronizado") e `fases.kits` (quantos produtos da
    // empresa são kit, `quantidade_kit >= 2`). Nenhuma chave antiga muda de
    // nome nem de valor, e nenhuma das duas pode custar consulta por empresa.
    // ═══════════════════════════════════════════════════════════════════════

    /** Empresa de Polos com token ativo — é o que entra em `empresas('polos')`. */
    private function empresaComToken(string $nome, array $attrs = []): MlbEmpresa
    {
        $e = MlbEmpresa::create($attrs + ['nome' => $nome, 'projeto' => 'POLOS'])->fresh();
        MlToken::create([
            'mlb_empresa_id' => $e->id,
            'ml_user_id' => (string) random_int(1000, 99999999),
            'access_token' => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
        ]);

        return $e;
    }

    private function linhaDe(string $nome): array
    {
        $linha = app(ProgramasPublicadorService::class)->empresas('polos')->firstWhere('nome', $nome);
        $this->assertNotNull($linha, "linha de {$nome} não veio em empresas('polos')");

        return $linha;
    }

    public function test_erp_declarado_vem_do_onboarding_e_sem_declaracao_e_nulo(): void
    {
        $comErp = $this->empresaComToken('Com ERP');
        MlbImplementacao::create([
            'empresa_id' => $comErp->id,
            'token' => (string) Str::uuid(),
            'dados' => ['itens' => ['erp' => ['valor' => 'Bling', 'outro' => '', 'acesso' => '', 'feito' => true]]],
        ]);

        $this->empresaComToken('Sem ERP');

        $this->assertSame(['nome' => 'Bling'], $this->linhaDe('Com ERP')['erp']);
        $this->assertSame(['nome' => null], $this->linhaDe('Sem ERP')['erp']);
    }

    /** "---" é o valor de partida da ficha de onboarding — não é ERP declarado. */
    public function test_erp_placeholder_da_ficha_nao_conta_como_declarado(): void
    {
        $e = $this->empresaComToken('Ficha Em Branco');
        MlbImplementacao::create([
            'empresa_id' => $e->id,
            'token' => (string) Str::uuid(),
            'dados' => ['itens' => ['erp' => ['valor' => '---', 'outro' => '', 'acesso' => '', 'feito' => false]]],
        ]);

        $this->assertNull($this->linhaDe('Ficha Em Branco')['erp']['nome']);
    }

    public function test_erp_outro_usa_o_texto_livre_e_a_coluna_da_planilha_e_o_segundo_caminho(): void
    {
        $outro = $this->empresaComToken('ERP Outro');
        MlbImplementacao::create([
            'empresa_id' => $outro->id,
            'token' => (string) Str::uuid(),
            'dados' => ['itens' => ['erp' => ['valor' => 'Outro', 'outro' => 'ERP Caseiro', 'acesso' => '', 'feito' => true]]],
        ]);

        // Ficha sem o item no JSON, mas com a coluna `erp` preenchida pelo sync da planilha de Polos.
        $planilha = $this->empresaComToken('ERP Da Planilha');
        MlbImplementacao::create([
            'empresa_id' => $planilha->id,
            'token' => (string) Str::uuid(),
            'erp' => 'Tiny',
        ]);

        $this->assertSame('ERP Caseiro', $this->linhaDe('ERP Outro')['erp']['nome']);
        $this->assertSame('Tiny', $this->linhaDe('ERP Da Planilha')['erp']['nome']);
    }

    public function test_fases_conta_os_produtos_que_sao_kit(): void
    {
        $comKits = $this->empresaComToken('Com Kits');
        $base = PubProduto::create(['mlb_empresa_id' => $comKits->id, 'sku' => 'BASE', 'nome' => 'Base']);
        PubProduto::create(['mlb_empresa_id' => $comKits->id, 'sku' => 'BASE-KIT2', 'nome' => 'Kit 2',
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        PubProduto::create(['mlb_empresa_id' => $comKits->id, 'sku' => 'BASE-KIT3', 'nome' => 'Kit 3',
            'produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 3]);

        $semKit = $this->empresaComToken('Sem Kit');
        PubProduto::create(['mlb_empresa_id' => $semKit->id, 'sku' => 'SO-BASE', 'nome' => 'Só base']);

        $this->assertSame(['kits' => 2], $this->linhaDe('Com Kits')['fases']);
        $this->assertSame(['kits' => 0], $this->linhaDe('Sem Kit')['fases']);
    }

    /** Gate de forma: as chaves antigas continuam todas lá, com os mesmos nomes. */
    public function test_as_chaves_antigas_da_linha_continuam_todas(): void
    {
        $this->empresaComToken('Forma');

        $linha = $this->linhaDe('Forma');

        foreach ([
            'chave', 'tipo', 'id', 'nome', 'identificador', 'company_id', 'tem_token', 'token_expirado',
            'token', 'link_reconexao', 'portal', 'produtos', 'publicados', 'prontos', 'liberada', 'publicados_mes',
        ] as $chave) {
            $this->assertArrayHasKey($chave, $linha, $chave);
        }
        $this->assertSame(['situacao', 'novas', 'sincronizado_em'], array_keys($linha['portal']));
        $this->assertArrayHasKey('erp', $linha);
        $this->assertArrayHasKey('fases', $linha);
    }

    /** ERP e kits entram EM LOTE: 30 empresas não podem custar mais consultas que 3. */
    public function test_erp_e_fases_nao_geram_consulta_por_empresa(): void
    {
        $cenario = function (int $n, string $prefixo) {
            for ($i = 0; $i < $n; $i++) {
                $e = $this->empresaComToken("{$prefixo} {$i}", ['company_id' => Company::factory()->create()->id]);
                MlbImplementacao::create([
                    'empresa_id' => $e->id,
                    'token' => (string) Str::uuid(),
                    'dados' => ['itens' => ['erp' => ['valor' => 'Bling', 'outro' => '', 'acesso' => '', 'feito' => true]]],
                ]);
                $base = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => "B{$prefixo}{$i}", 'nome' => 'B']);
                PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => "K{$prefixo}{$i}", 'nome' => 'K',
                    'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
            }
        };
        $consultas = function (): int {
            $servico = app(ProgramasPublicadorService::class);
            $servico->empresas('polos'); // aquece cache de schema/boot — não conta
            DB::flushQueryLog();
            DB::enableQueryLog();
            $servico->empresas('polos');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $cenario(3, 'Pequena');
        $com3 = $consultas();
        $cenario(27, 'Grande');
        $com30 = $consultas();

        $this->assertSame($com3, $com30, "3 empresas: {$com3} consultas; 30 empresas: {$com30}");
    }
}
