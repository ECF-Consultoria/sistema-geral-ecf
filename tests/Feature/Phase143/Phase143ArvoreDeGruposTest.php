<?php

namespace Tests\Feature\Phase143;

use App\Models\Company;
use App\Models\CompanyGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 01 — Tarefas 1 e 2: a coluna `company_groups.parent_id` e a
 * trava de UM nível da árvore de grupos.
 *
 * A trava não é enfeite (143-CONTEXT, D-05): sem ela a agregação do
 * fechamento vira caminhada de profundidade desconhecida, e um ciclo
 * `A→B→A` trava o laço de consolidação para sempre.
 */
class Phase143ArvoreDeGruposTest extends TestCase
{
    use RefreshDatabase;

    // ─── T1 — a coluna ────────────────────────────────────────────────────

    #[Test]
    public function company_groups_ganhou_parent_id_e_ele_nasce_nulo(): void
    {
        $this->assertTrue(
            Schema::hasColumn('company_groups', 'parent_id'),
            'A migration da Fase 143 precisa ter criado company_groups.parent_id.'
        );

        $grupo = CompanyGroup::create(['name' => 'Grupo Solto', 'color' => '#000']);

        $this->assertNull(
            DB::table('company_groups')->where('id', $grupo->id)->value('parent_id'),
            'A coluna NASCE NULA — nesta entrega ninguém ganha pai, e é isso que garante regressão zero.'
        );
    }

    #[Test]
    public function excluir_o_grupo_pai_nao_apaga_o_subgrupo_apenas_o_desvincula(): void
    {
        $raiz = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'Sub', 'color' => '#000', 'parent_id' => $raiz->id]);

        $raiz->delete();

        $sub->refresh();

        $this->assertNotNull($sub, 'nullOnDelete() desvincula, nunca apaga em cascata.');
        $this->assertNull($sub->parent_id, 'Sem pai, o subgrupo volta a ser raiz de si mesmo.');
        $this->assertSame($sub->id, $sub->raizId());
    }

    // ─── T2 — relações e raiz ─────────────────────────────────────────────

    #[Test]
    public function grupo_sem_pai_e_a_propria_raiz(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Sozinho', 'color' => '#000']);

        $this->assertNull($grupo->pai);
        $this->assertSame($grupo->id, $grupo->raizId());
        $this->assertTrue($grupo->raiz()->is($grupo));
        $this->assertFalse($grupo->ehSubgrupo());
    }

    #[Test]
    public function subgrupo_aponta_para_a_raiz_nos_dois_sentidos(): void
    {
        $raiz = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $raiz->id]);

        $this->assertTrue($sub->pai->is($raiz));
        $this->assertSame($raiz->id, $sub->raizId());
        $this->assertTrue($sub->raiz()->is($raiz));
        $this->assertTrue($sub->ehSubgrupo());

        $this->assertTrue($raiz->subgrupos->contains($sub));
        $this->assertSame($raiz->id, $raiz->raizId(), 'A raiz continua sendo raiz de si mesma.');
    }

    // ─── T2 — a trava de um nível, nos três sentidos ──────────────────────

    #[Test]
    public function grupo_nao_pode_ser_o_proprio_pai(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Ciclo Curto', 'color' => '#000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('não pode ser o próprio grupo-pai');

        $grupo->update(['parent_id' => $grupo->id]);
    }

    #[Test]
    public function nao_da_para_pendurar_num_grupo_que_ja_tem_pai(): void
    {
        $raiz = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'Sub', 'color' => '#000', 'parent_id' => $raiz->id]);
        $neto = CompanyGroup::create(['name' => 'Neto', 'color' => '#000']);

        try {
            $neto->update(['parent_id' => $sub->id]);
            $this->fail('Pendurar no subgrupo criaria um terceiro nível — tinha de ser recusado.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('um nível só', $e->getMessage());
        }

        $this->assertNull(
            $neto->fresh()->parent_id,
            'A recusa é no `saving`: nada pode ter sido gravado.'
        );
    }

    #[Test]
    public function grupo_que_ja_e_pai_nao_pode_ganhar_pai(): void
    {
        $raiz  = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);
        CompanyGroup::create(['name' => 'Sub', 'color' => '#000', 'parent_id' => $raiz->id]);
        $outra = CompanyGroup::create(['name' => 'Outra Raiz', 'color' => '#000']);

        try {
            $raiz->update(['parent_id' => $outra->id]);
            $this->fail('A raiz já é pai de alguém — ganhar pai criaria um terceiro nível.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('um nível só', $e->getMessage());
        }

        $this->assertNull($raiz->fresh()->parent_id);
    }

    #[Test]
    public function ciclo_a_para_b_para_a_e_impossivel(): void
    {
        $a = CompanyGroup::create(['name' => 'A', 'color' => '#000']);
        $b = CompanyGroup::create(['name' => 'B', 'color' => '#000']);

        // Primeiro lado do ciclo: legítimo (B vira subgrupo de A).
        $b->update(['parent_id' => $a->id]);

        // Fechar o ciclo esbarra em DUAS travas de uma vez (B já tem pai, e
        // A já é pai de alguém) — basta uma para o fechamento nunca entrar
        // num laço infinito.
        $this->expectException(\InvalidArgumentException::class);

        $a->update(['parent_id' => $b->id]);
    }

    // ─── T2 — a promessa de desempenho de raizId() ────────────────────────

    #[Test]
    public function raiz_id_nao_consulta_o_banco_dentro_do_laco_de_empresas(): void
    {
        $raiz = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'Sub', 'color' => '#000', 'parent_id' => $raiz->id]);

        // 10 empresas: metade direto na raiz, metade no subgrupo — o
        // recorte do caso MPozenato em miniatura.
        foreach (range(1, 5) as $i) {
            Company::factory()->create(['company_group_id' => $raiz->id]);
            Company::factory()->create(['company_group_id' => $sub->id]);
        }

        // Mesmo eager loading do `fechamento:consolidar-mes`.
        $companies = Company::whereNotNull('company_group_id')->with('grupo.pai')->get();

        $this->assertCount(10, $companies);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $raizes = $companies->map(fn (Company $c) => $c->grupo->raizId())->unique()->values();
        // `raiz()` também é chamado no Passo 5 — entra na mesma promessa.
        $companies->each(fn (Company $c) => $c->grupo->raiz());

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $consultas,
            'raizId()/raiz() rodam dentro do laço de ~200 empresas: uma query por empresa seriam 200 consultas por consolidação.'
        );

        $this->assertSame([$raiz->id], $raizes->all(), 'As 10 empresas convergem para UMA raiz só.');
    }
}
