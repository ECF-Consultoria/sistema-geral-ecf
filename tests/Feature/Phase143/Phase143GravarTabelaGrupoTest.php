<?php

namespace Tests\Feature\Phase143;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\GrupoFaixaFaturamento;
use App\Models\User;
use App\Services\Fechamento\GravarTabelaGrupoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 143 Plano 03 — Tarefa 1: `GravarTabelaGrupoService`, a porta única de escrita em
 * `grupo_faixas_faturamento` e o fim do buraco de auditoria do caminho de GRUPO.
 *
 * ## O que estava quebrado
 * `TabelaEmpresaContratoController::gravarFaixasGrupo()` fazia
 * `GrupoFaixaFaturamento::where(...)->delete()` seguido de `create()` em laço, e o arquivo
 * inteiro não tinha uma única chamada a `activity()`. Delete de QUERY BUILDER não dispara eventos
 * de model, então o `LogsActivity` do `GrupoFaixaFaturamento` nunca via as linhas apagadas: **a
 * tabela anterior evaporava sem registro nenhum.** Com a árvore da Fase 143, essa tabela governa
 * a cobrança de todas as empresas abaixo do grupo — trocar dez mensalidades sem rastro.
 *
 * ⚠️ **O teste que mais importa é `substituir_tabela_existente_registra_a_tabela_antiga_no_antes`**
 * — é exatamente o registro que hoje evapora.
 *
 * Toda asserção de persistência é por RECONSULTA ao banco, nunca pelo retorno do método sozinho.
 * Molde: `Phase142GravarTabelaEmpresaTest`.
 */
class Phase143GravarTabelaGrupoTest extends TestCase
{
    use RefreshDatabase;

    private function service(): GravarTabelaGrupoService
    {
        return app(GravarTabelaGrupoService::class);
    }

    private function grupo(string $nome = 'MPozenato'): CompanyGroup
    {
        return CompanyGroup::create(['name' => $nome, 'color' => '#ffe600']);
    }

    /**
     * @return array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool}>
     */
    private function faixas(float $valor = 9_500.00): array
    {
        return [
            ['ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => $valor, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => $valor * 2, 'valor_e_piso' => true],
        ];
    }

    private function criarLinhaExistente(CompanyGroup $grupo, float $valor = 21_000.00, int $ordem = 1, ?float $teto = null): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id,
            'ordem'            => $ordem,
            'limite_superior'  => $teto,
            'valor'            => $valor,
            'valor_e_piso'     => false,
        ]);
    }

    private function ultimaActivity(CompanyGroup $grupo): ?Activity
    {
        return Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)
            ->where('subject_type', CompanyGroup::class)
            ->where('subject_id', $grupo->id)
            ->latest('id')
            ->first();
    }

    // ── 1. gravar numa tabela vazia ──────────────────────────────────────

    #[Test]
    public function gravar_num_grupo_sem_tabela_cria_as_linhas(): void
    {
        $grupo = $this->grupo();

        $resultado = $this->service()->gravar($grupo, $this->faixas());

        $this->assertFalse($resultado['substituiu']);
        $this->assertSame(0, $resultado['quantidade_anterior']);
        $this->assertSame(2, $resultado['quantidade']);

        $linhas = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->ordenadas()->get();
        $this->assertCount(2, $linhas);
        $this->assertEqualsWithDelta(9_500.00, (float) $linhas[0]->valor, 0.01);
        $this->assertEqualsWithDelta(300_000.00, (float) $linhas[0]->limite_superior, 0.01);
        $this->assertNull($linhas[1]->limite_superior);
        $this->assertTrue((bool) $linhas[1]->valor_e_piso);
    }

    #[Test]
    public function gravacao_cria_exatamente_uma_entrada_de_auditoria_com_antes_vazio(): void
    {
        $grupo = $this->grupo();
        $user  = User::factory()->create();

        $antes = Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->count();

        $this->service()->gravar($grupo, $this->faixas(), $user, 'contrato_ficha');

        $this->assertSame(
            $antes + 1,
            Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->count(),
            'Cada gravação precisa deixar UMA entrada — não uma por linha.'
        );

        $activity = $this->ultimaActivity($grupo);
        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);

        $props = $activity->properties->toArray();
        $this->assertSame([], $props['antes']);
        $this->assertCount(2, $props['depois']);
        $this->assertSame('contrato_ficha', $props['feito_de']);
    }

    // ── 2. ⚠️ substituir: o `antes` traz a tabela ANTIGA ─────────────────

    #[Test]
    public function substituir_tabela_existente_registra_a_tabela_antiga_no_antes(): void
    {
        $grupo = $this->grupo();

        // A tabela ANTIGA — três faixas, com valores que não existem na nova.
        $this->criarLinhaExistente($grupo, 21_000.00, 1, 5_000_000.00);
        $this->criarLinhaExistente($grupo, 27_000.00, 2, 15_000_000.00);
        $this->criarLinhaExistente($grupo, 33_000.00, 3, null);

        $resultado = $this->service()->gravar($grupo, $this->faixas(9_500.00));

        $this->assertTrue($resultado['substituiu']);
        $this->assertSame(3, $resultado['quantidade_anterior']);

        $props = $this->ultimaActivity($grupo)->properties->toArray();

        // É ISTO que evaporava antes desta classe existir.
        $this->assertCount(3, $props['antes'], 'O `antes` precisa trazer a tabela ANTIGA inteira — era ela que sumia sem rastro.');
        $this->assertEqualsWithDelta(21_000.00, (float) $props['antes'][0]['valor'], 0.01);
        $this->assertEqualsWithDelta(27_000.00, (float) $props['antes'][1]['valor'], 0.01);
        $this->assertEqualsWithDelta(33_000.00, (float) $props['antes'][2]['valor'], 0.01);
        $this->assertEqualsWithDelta(5_000_000.00, (float) $props['antes'][0]['limite_superior'], 0.01);
        $this->assertNull($props['antes'][2]['limite_superior']);

        $this->assertCount(2, $props['depois']);
        $this->assertEqualsWithDelta(9_500.00, (float) $props['depois'][0]['valor'], 0.01);
        $this->assertEqualsWithDelta(19_000.00, (float) $props['depois'][1]['valor'], 0.01);

        // E o banco ficou só com a tabela nova (substituição inteira, nunca linha a linha).
        $linhas = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->ordenadas()->get();
        $this->assertCount(2, $linhas);
    }

    // ── 3. remover registra `depois = []` ────────────────────────────────

    #[Test]
    public function remover_apaga_a_tabela_e_registra_depois_vazio(): void
    {
        $grupo = $this->grupo();
        $user  = User::factory()->create();
        $this->criarLinhaExistente($grupo, 12_000.00, 1, null);

        $resultado = $this->service()->remover($grupo, $user);

        $this->assertSame(1, $resultado['quantidade_anterior']);
        $this->assertSame(0, GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->count());

        $props = $this->ultimaActivity($grupo)->properties->toArray();
        $this->assertCount(1, $props['antes']);
        $this->assertEqualsWithDelta(12_000.00, (float) $props['antes'][0]['valor'], 0.01);
        $this->assertSame([], $props['depois']);
    }

    // ── 4. array vazio é recusado (all-or-nothing) ───────────────────────

    #[Test]
    public function gravar_com_array_de_faixas_vazio_lanca_excecao(): void
    {
        $grupo = $this->grupo();

        $this->expectException(\RuntimeException::class);

        $this->service()->gravar($grupo, []);
    }

    #[Test]
    public function gravar_com_array_vazio_nao_altera_a_tabela_nem_deixa_trilha(): void
    {
        $grupo = $this->grupo();
        $this->criarLinhaExistente($grupo, 21_000.00, 1, null);

        $trilhaAntes = Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->count();

        try {
            $this->service()->gravar($grupo, []);
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $linhas = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->get();
        $this->assertCount(1, $linhas);
        $this->assertEqualsWithDelta(21_000.00, (float) $linhas[0]->valor, 0.01);
        $this->assertSame($trilhaAntes, Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->count());
    }

    // ── 5. a trilha diz o TAMANHO da decisão ─────────────────────────────

    #[Test]
    public function a_trilha_conta_as_empresas_do_grupo_e_dos_grupos_que_fazem_parte_dele(): void
    {
        $pai = $this->grupo('MPozenato');
        $sub = CompanyGroup::create(['name' => 'DRossi', 'color' => '#ffffff', 'parent_id' => $pai->id]);

        Company::factory()->count(2)->create(['company_group_id' => $pai->id]);
        Company::factory()->count(4)->create(['company_group_id' => $sub->id]);
        // Empresa de outro grupo — não pode entrar na conta.
        Company::factory()->create(['company_group_id' => $this->grupo('Outro Cliente')->id]);

        $resultado = $this->service()->gravar($pai, $this->faixas());

        $this->assertSame(6, $resultado['empresas_governadas']);

        $props = $this->ultimaActivity($pai)->properties->toArray();
        $this->assertSame(6, $props['empresas_governadas']);
        $this->assertStringContainsString('6 empresa(s)', $this->ultimaActivity($pai)->description);
    }

    #[Test]
    public function gravar_num_grupo_que_faz_parte_de_outro_conta_so_as_empresas_dele(): void
    {
        $pai = $this->grupo('MPozenato');
        $sub = CompanyGroup::create(['name' => 'DRossi', 'color' => '#ffffff', 'parent_id' => $pai->id]);

        Company::factory()->count(2)->create(['company_group_id' => $pai->id]);
        Company::factory()->count(4)->create(['company_group_id' => $sub->id]);

        $resultado = $this->service()->gravar($sub, $this->faixas());

        $this->assertSame(4, $resultado['empresas_governadas']);
    }

    // ── 6. o sujeito da trilha é o grupo ─────────────────────────────────

    #[Test]
    public function o_sujeito_da_trilha_e_o_grupo_e_o_log_name_e_proprio(): void
    {
        $grupo = $this->grupo();

        $this->service()->gravar($grupo, $this->faixas());

        $activity = $this->ultimaActivity($grupo);
        $this->assertNotNull($activity);
        $this->assertSame(CompanyGroup::class, $activity->subject_type);
        $this->assertSame($grupo->id, $activity->subject_id);
        $this->assertSame('faixa_faturamento_tabela_grupo', $activity->log_name);
        $this->assertStringContainsString('MPozenato', $activity->description);
    }
}
