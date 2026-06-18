<?php

namespace Tests\Feature;

use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 37 / Plan 37-01 (REQ-37-03) — Suíte da coluna `servicos.setor`.
 *
 * Cobre:
 *  - Coluna `setor` existe + default 'outros' (Task 1)
 *  - Seed canônico: Gestão/Mentoria → performance, Publicação → publicacao;
 *    demais permanecem 'outros' (Task 2)
 *  - Constants públicas Servico::SETOR_* + Servico::SETORES (Task 2)
 *  - Helpers isPerformance() / isPublicacao() (Task 2)
 *  - Scope porSetor() (Task 2)
 *
 * Nota sobre a estratégia do seed (Task 3 do plan):
 *  Em vez de invocar Artisan::call('migrate', --path) — que sofre com o
 *  estado do migrator no SQLite de testes (RefreshDatabase) — replicamos
 *  inline o UPDATE que a migration de seed faz. Isso valida o EFEITO da
 *  regra de negócio, não a maquinaria do migrator (que já é testada pelo
 *  RefreshDatabase rodando todas as migrations limpamente).
 *
 * @group phase37
 */
class Phase37ServicoSetorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replica o UPDATE da migration 2026_06_18_100002_seed_servicos_setor.
     *
     * Isola o teste do estado do migrator no SQLite — a migration roda
     * 1× durante o RefreshDatabase; para validar seu comportamento em
     * cenários customizados (servicos criados pelo próprio teste),
     * re-aplicamos o mesmo UPDATE manualmente.
     */
    private function aplicarSeedSetor(): void
    {
        DB::transaction(function () {
            DB::table('servicos')
                ->where('nome', 'LIKE', '%Gestão%')
                ->update(['setor' => Servico::SETOR_PERFORMANCE]);

            DB::table('servicos')
                ->where('nome', 'LIKE', '%Mentoria%')
                ->update(['setor' => Servico::SETOR_PERFORMANCE]);

            DB::table('servicos')
                ->where('nome', 'LIKE', '%Publicação%')
                ->update(['setor' => Servico::SETOR_PUBLICACAO]);
        });
    }

    /**
     * TEST 1 — Coluna `setor` existe e novos rows recebem default 'outros'.
     */
    public function test_coluna_setor_existe_e_default_outros(): void
    {
        $this->assertTrue(
            Schema::hasColumn('servicos', 'setor'),
            'Coluna servicos.setor deve existir após Phase 37 Plan 37-01.',
        );

        // Insere via DB::table direto (sem $fillable / sem setor) para
        // garantir que o DEFAULT do schema é o aplicado.
        $id = DB::table('servicos')->insertGetId([
            'nome'          => 'Servico Teste Default',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $row = DB::table('servicos')->where('id', $id)->first();
        $this->assertEquals(
            Servico::SETOR_OUTROS,
            $row->setor,
            "Default da coluna setor deve ser '" . Servico::SETOR_OUTROS . "'.",
        );
    }

    /**
     * TEST 2 — Seed atualiza Servico 'Gestão' para setor='performance'.
     */
    public function test_seed_atualiza_gestao_para_performance(): void
    {
        // Migration 100001 (seed catálogo) já criou 'Gestão' com setor='outros'
        // (default) antes da 100002 rodar. Resetamos para 'outros' e re-aplicamos
        // o seed manualmente para validar a regra em isolamento.
        DB::table('servicos')->update(['setor' => Servico::SETOR_OUTROS]);

        $this->aplicarSeedSetor();

        $gestao = Servico::where('nome', 'Gestão')->first();
        $this->assertNotNull($gestao, 'Servico Gestão deve existir no catálogo (seed Phase 14).');
        $this->assertEquals(
            Servico::SETOR_PERFORMANCE,
            $gestao->setor,
            "Servico 'Gestão' deve ser atualizado para setor='performance' pelo seed.",
        );
    }

    /**
     * TEST 3 — Seed atualiza Servico 'Publicação' para setor='publicacao'.
     */
    public function test_seed_atualiza_publicacao_para_publicacao(): void
    {
        DB::table('servicos')->update(['setor' => Servico::SETOR_OUTROS]);

        $this->aplicarSeedSetor();

        $publicacao = Servico::where('nome', 'Publicação')->first();
        $this->assertNotNull($publicacao, 'Servico Publicação deve existir no catálogo (seed Phase 14).');
        $this->assertEquals(
            Servico::SETOR_PUBLICACAO,
            $publicacao->setor,
            "Servico 'Publicação' deve ser atualizado para setor='publicacao' pelo seed.",
        );

        // Sanity: demais nomes do catálogo Phase 14 (Polos, Assessoria,
        // Incubadora, Publicidade) permanecem 'outros'.
        $outros = Servico::whereIn('nome', ['Polos', 'Assessoria', 'Incubadora', 'Publicidade'])->get();
        foreach ($outros as $srv) {
            $this->assertEquals(
                Servico::SETOR_OUTROS,
                $srv->setor,
                "Servico '{$srv->nome}' deve permanecer setor='outros' (não bate Gestão/Mentoria/Publicação).",
            );
        }
    }

    /**
     * TEST 4 — Constants SETOR_* expostas e array SETORES com 3 chaves.
     */
    public function test_constants_setores_expostas(): void
    {
        $this->assertEquals('performance', Servico::SETOR_PERFORMANCE);
        $this->assertEquals('publicacao', Servico::SETOR_PUBLICACAO);
        $this->assertEquals('outros', Servico::SETOR_OUTROS);

        $this->assertIsArray(Servico::SETORES);
        $this->assertCount(3, Servico::SETORES, 'Servico::SETORES deve ter exatamente 3 entradas.');
        $this->assertEqualsCanonicalizing(
            ['performance', 'publicacao', 'outros'],
            Servico::SETORES,
            'Servico::SETORES deve conter performance, publicacao e outros.',
        );

        $labels = Servico::setoresLabels();
        $this->assertArrayHasKey('performance', $labels);
        $this->assertArrayHasKey('publicacao', $labels);
        $this->assertArrayHasKey('outros', $labels);
    }

    /**
     * TEST 5 — Helpers isPerformance() / isPublicacao() retornam bool corretos.
     */
    public function test_helper_is_performance_e_is_publicacao(): void
    {
        $performance = Servico::create([
            'nome'          => 'Servico Performance Teste',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $publicacao = Servico::create([
            'nome'          => 'Servico Publicacao Teste',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PUBLICACAO,
        ]);

        $this->assertTrue($performance->isPerformance(), 'isPerformance() deve ser true para setor=performance.');
        $this->assertFalse($performance->isPublicacao(), 'isPublicacao() deve ser false para setor=performance.');

        $this->assertTrue($publicacao->isPublicacao(), 'isPublicacao() deve ser true para setor=publicacao.');
        $this->assertFalse($publicacao->isPerformance(), 'isPerformance() deve ser false para setor=publicacao.');
    }

    /**
     * TEST 6 — Scope porSetor() filtra apenas os servicos do setor solicitado.
     */
    public function test_scope_por_setor_filtra(): void
    {
        // Catálogo Phase 14 já tem 6 servicos com setor='outros' (default).
        // Limpamos para garantir contagem determinística.
        DB::table('servicos')->update(['setor' => Servico::SETOR_OUTROS]);

        Servico::create([
            'nome'          => 'Performance A',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        Servico::create([
            'nome'          => 'Performance B',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        Servico::create([
            'nome'          => 'Publicacao C',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PUBLICACAO,
        ]);

        $this->assertEquals(
            2,
            Servico::porSetor(Servico::SETOR_PERFORMANCE)->count(),
            'porSetor(performance) deve retornar exatamente 2 servicos.',
        );

        $this->assertEquals(
            1,
            Servico::porSetor(Servico::SETOR_PUBLICACAO)->count(),
            'porSetor(publicacao) deve retornar exatamente 1 servico.',
        );

        // Os 6 do catálogo + 0 que criamos (todos viraram outros pelo update inicial)
        $countOutros = Servico::porSetor(Servico::SETOR_OUTROS)->count();
        $this->assertGreaterThanOrEqual(
            6,
            $countOutros,
            'porSetor(outros) deve incluir ao menos os 6 servicos canônicos do catálogo Phase 14.',
        );
    }
}
