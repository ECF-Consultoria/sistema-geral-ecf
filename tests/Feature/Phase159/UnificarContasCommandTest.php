<?php

namespace Tests\Feature\Phase159;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 159 Plano 159-05 (D-06/D-11) — prova de SC6 (núcleo): dry-run por
 * padrão, censo de D-11, `--apply` com backup por lote, reconsulta ao banco
 * e `--desfazer`.
 *
 * Fixture única (setUp), espelhando a descrição do <behavior> do plano:
 *  - Setor Performance com cargos analista/estrategista (reusa o setor
 *    seedado por `2026_09_10_140000_seed_setor_performance.php` — mesmo
 *    padrão de `tests/Feature/Phase159/CargosDesempenhoTest.php`).
 *  - ORIGEM: só cargo estrategista. DESTINO: cargo analista (principal).
 *  - Empresa A: só a origem é estrategista.
 *  - Empresa B: origem estrategista + destino consultor (roles diferentes,
 *    sem colisão).
 *  - Empresa C: origem E destino já são estrategista com o MESMO
 *    servico_id — colisão proposital.
 */
class UnificarContasCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $setorPerformanceId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;
    private int $servicoPerfId;

    private int $origemId;
    private int $destinoId;
    private int $companyAId;
    private int $companyBId;
    private int $companyCId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorPerformanceId = (int) (DB::table('setores')->where('slug', 'performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Performance',
                'slug'       => 'performance',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoAnalistaId = (int) (DB::table('cargos')
            ->where('setor_id', $this->setorPerformanceId)->where('slug', 'analista')->value('id')
            ?? DB::table('cargos')->insertGetId([
                'setor_id'   => $this->setorPerformanceId,
                'nome'       => 'Analista',
                'slug'       => 'analista',
                'active'     => true,
                'ordem'      => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoEstrategistaId = (int) (DB::table('cargos')
            ->where('setor_id', $this->setorPerformanceId)->where('slug', 'estrategista')->value('id')
            ?? DB::table('cargos')->insertGetId([
                'setor_id'   => $this->setorPerformanceId,
                'nome'       => 'Estrategista',
                'slug'       => 'estrategista',
                'active'     => true,
                'ordem'      => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->servicoPerfId = DB::table('servicos')->insertGetId([
            'nome'          => 'Performance Mensal',
            'valor_padrao'  => 0,
            'tipo_cobranca' => 'mensal',
            'ativo'         => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->origemId  = $this->criarUser('Origem Teste');
        $this->destinoId = $this->criarUser('Destino Teste');

        $this->vincularCargo($this->origemId, $this->cargoEstrategistaId, true);
        $this->vincularCargo($this->destinoId, $this->cargoAnalistaId, true);

        $this->companyAId = Company::factory()->create(['name' => 'Empresa A Unificacao'])->id;
        $this->companyBId = Company::factory()->create(['name' => 'Empresa B Unificacao'])->id;
        $this->companyCId = Company::factory()->create(['name' => 'Empresa C Unificacao'])->id;

        // Empresa A: só a origem é estrategista.
        $this->vincularCarteira($this->companyAId, $this->origemId, 'estrategista', $this->servicoPerfId, '2026-06-01');

        // Empresa B: origem estrategista + destino consultor — roles diferentes, sem colisão.
        $this->vincularCarteira($this->companyBId, $this->origemId, 'estrategista', $this->servicoPerfId, '2026-06-02');
        $this->vincularCarteira($this->companyBId, $this->destinoId, 'consultor', $this->servicoPerfId, '2026-05-01');

        // Empresa C: origem e destino JÁ são estrategista com o mesmo serviço — colisão.
        $this->vincularCarteira($this->companyCId, $this->origemId, 'estrategista', $this->servicoPerfId, '2026-06-03');
        $this->vincularCarteira($this->companyCId, $this->destinoId, 'estrategista', $this->servicoPerfId, '2026-04-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

    private function criarUser(string $nome, bool $active = true): int
    {
        return DB::table('users')->insertGetId([
            'name'       => $nome,
            'email'      => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@ecf.test',
            'password'   => bcrypt('senha'),
            'role'       => 'consultor',
            'active'     => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function vincularCargo(int $userId, int $cargoId, bool $principal): void
    {
        DB::table('user_setores')->insert([
            'user_id'      => $userId,
            'setor_id'     => $this->setorPerformanceId,
            'cargo_id'     => $cargoId,
            'is_principal' => $principal,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function vincularCarteira(int $companyId, int $userId, string $role, int $servicoId, string $assignedAt): int
    {
        return DB::table('company_users')->insertGetId([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'role'        => $role,
            'servico_id'  => $servicoId,
            'assigned_at' => $assignedAt,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Assinatura das tabelas do núcleo, usada para provar "dry-run não muda nada". */
    private function assinaturaNucleo(): array
    {
        return [
            'company_users'           => DB::table('company_users')->count(),
            'company_manager_history' => DB::table('company_manager_history')->count(),
            'user_setores'            => DB::table('user_setores')->count(),
            'users'                   => DB::table('users')->count(),
            'unificacao_contas_backup' => DB::table('unificacao_contas_backup')->count(),
        ];
    }

    private function chamar(array $opcoes): int
    {
        return Artisan::call('usuarios:unificar-contas', $opcoes);
    }

    private function opcoesBase(array $extra = []): array
    {
        return array_merge([
            '--de'       => $this->origemId,
            '--para'     => $this->destinoId,
            '--a-partir' => '2026-09',
        ], $extra);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 1: dry-run não grava nada
    // ═════════════════════════════════════════════════════════════════════

    public function test_dry_run_sem_apply_nao_grava_nada(): void
    {
        $antes = $this->assinaturaNucleo();

        $exit = $this->chamar($this->opcoesBase());

        $this->assertSame(0, $exit);
        $this->assertSame($antes, $this->assinaturaNucleo());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 2: --json mostra etapas/censo corretos
    // ═════════════════════════════════════════════════════════════════════

    public function test_json_mostra_chaves_etapas_na_ordem_e_censo(): void
    {
        $this->chamar($this->opcoesBase(['--json' => true]));

        $plano = json_decode(Artisan::output(), true);

        $this->assertIsArray($plano);
        foreach (['de', 'para', 'a_partir', 'bloqueios', 'avisos', 'etapas', 'censo'] as $chave) {
            $this->assertArrayHasKey($chave, $plano);
        }

        $this->assertSame([], $plano['bloqueios']);
        $this->assertSame('2026-09', $plano['a_partir']);

        // desativar_origem é SEMPRE a última etapa.
        $this->assertSame(
            ['carteira', 'historico_gestao', 'cargos', 'desativar_origem'],
            array_column($plano['etapas'], 'chave')
        );

        $etapas = collect($plano['etapas'])->keyBy('chave');

        $porAcaoCarteira = collect($etapas['carteira']['operacoes'])->countBy('acao');
        $this->assertSame(2, $porAcaoCarteira->get('update', 0));
        $this->assertSame(1, $porAcaoCarteira->get('delete', 0));

        // 3 saídas (A, B, C) + 2 entradas (A, B — não C, que foi colisão).
        $this->assertCount(5, $etapas['historico_gestao']['operacoes']);

        // Destino ganha só o cargo estrategista (já tem analista).
        $this->assertCount(1, $etapas['cargos']['operacoes']);

        // Origem está ativa nesta fixture: desativar_origem tem 1 update.
        $this->assertCount(1, $etapas['desativar_origem']['operacoes']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 3: snapshot mensal já consolidado bloqueia
    // ═════════════════════════════════════════════════════════════════════

    public function test_bloqueia_com_snapshot_mensal_ja_consolidado(): void
    {
        DesempenhoScoreSnapshot::create([
            'user_id'        => $this->origemId,
            'ref_date'       => '2026-09-01',
            'mes_referencia' => '2026-09-01',
            'score'          => 80,
            'classificacao'  => 'bom',
            'breakdown_json' => [],
        ]);

        $exit = $this->chamar($this->opcoesBase());

        $this->assertSame(1, $exit);
        $saida = Artisan::output();
        $this->assertStringContainsString('2026-09', $saida);
        $this->assertStringContainsString('consolidada', $saida);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 4: detalhe por empresa consolidado (destino) bloqueia
    // ═════════════════════════════════════════════════════════════════════

    public function test_bloqueia_com_detalhe_por_empresa_consolidado_para_o_destino(): void
    {
        DesempenhoCompanyScoreSnapshot::create([
            'user_id'        => $this->destinoId,
            'company_id'     => $this->companyAId,
            'mes_referencia' => '2026-09-01',
            'origem'         => CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);

        $exit = $this->chamar($this->opcoesBase());

        $this->assertSame(1, $exit);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 5: validações de --de/--para/--a-partir
    // ═════════════════════════════════════════════════════════════════════

    public function test_de_igual_a_para_e_recusado(): void
    {
        $exit = $this->chamar($this->opcoesBase(['--para' => $this->origemId]));

        $this->assertSame(1, $exit);
    }

    public function test_a_partir_com_formato_invalido_e_recusado(): void
    {
        $exit = $this->chamar($this->opcoesBase(['--a-partir' => '2026-9']));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('formato', Artisan::output());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 6: destino inativo bloqueia
    // ═════════════════════════════════════════════════════════════════════

    public function test_destino_inativo_bloqueia(): void
    {
        DB::table('users')->where('id', $this->destinoId)->update(['active' => false]);

        $exit = $this->chamar($this->opcoesBase());

        $this->assertSame(1, $exit);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 1 — Teste 7: censo sem_regra trava; --manter libera
    // ═════════════════════════════════════════════════════════════════════

    public function test_censo_sem_regra_com_linhas_trava_e_manter_libera(): void
    {
        DB::table('setor_lideres')->insert([
            'setor_id'    => $this->setorPerformanceId,
            'user_id'     => $this->origemId,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->chamar($this->opcoesBase(['--json' => true]));
        $plano = json_decode(Artisan::output(), true);

        $censoSetorLideres = collect($plano['censo'])
            ->first(fn ($c) => $c['tabela'] === 'setor_lideres' && $c['coluna'] === 'user_id');

        $this->assertNotNull($censoSetorLideres, 'setor_lideres.user_id deveria aparecer no censo');
        $this->assertSame('sem_regra', $censoSetorLideres['classificacao']);
        $this->assertSame(1, $censoSetorLideres['linhas_origem']);

        // company_users.user_id e user_setores.user_id são 'tratada' — não são pendência.
        $censoCarteira = collect($plano['censo'])
            ->first(fn ($c) => $c['tabela'] === 'company_users' && $c['coluna'] === 'user_id');
        $this->assertSame('tratada', $censoCarteira['classificacao']);

        $exitSemManter = $this->chamar($this->opcoesBase());
        $this->assertSame(1, $exitSemManter);

        $exitComManter = $this->chamar($this->opcoesBase(['--manter' => ['setor_lideres.user_id']]));
        $this->assertSame(0, $exitComManter);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 8: --apply move a carteira preservando linha/assigned_at
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_move_carteira_preservando_linha_e_assigned_at(): void
    {
        $linhaAId = DB::table('company_users')
            ->where('company_id', $this->companyAId)->where('user_id', $this->origemId)->value('id');
        $linhaBId = DB::table('company_users')
            ->where('company_id', $this->companyBId)->where('user_id', $this->origemId)->value('id');

        $exit = $this->chamar($this->opcoesBase(['--apply' => true]));

        $this->assertSame(0, $exit);

        $linhaA = DB::table('company_users')->where('id', $linhaAId)->first();
        $this->assertSame($this->destinoId, (int) $linhaA->user_id);
        $this->assertSame('2026-06-01', substr((string) $linhaA->assigned_at, 0, 10));

        $linhaB = DB::table('company_users')->where('id', $linhaBId)->first();
        $this->assertSame($this->destinoId, (int) $linhaB->user_id);

        $linhasC = DB::table('company_users')->where('company_id', $this->companyCId)->get();
        $this->assertCount(1, $linhasC);
        $this->assertSame($this->destinoId, (int) $linhasC->first()->user_id);

        $this->assertSame(0, DB::table('company_users')->where('user_id', $this->origemId)->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 9: histórico de gestão correto, linhas antigas intactas
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_grava_historico_de_gestao_sem_reescrever_linhas_antigas(): void
    {
        $antigoId = DB::table('company_manager_history')->insertGetId([
            'company_id' => $this->companyAId,
            'user_id'    => $this->destinoId,
            'papel'      => 'analista',
            'evento'     => 'entrada',
            'changed_by' => null,
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $this->chamar($this->opcoesBase(['--apply' => true]));

        $antigo = DB::table('company_manager_history')->where('id', $antigoId)->first();
        $this->assertSame('2026-01-01 10:00:00', $antigo->created_at);

        foreach ([$this->companyAId, $this->companyBId, $this->companyCId] as $companyId) {
            $this->assertDatabaseHas('company_manager_history', [
                'company_id' => $companyId, 'user_id' => $this->origemId,
                'papel' => 'estrategista', 'evento' => 'saida',
            ]);
        }
        foreach ([$this->companyAId, $this->companyBId] as $companyId) {
            $this->assertDatabaseHas('company_manager_history', [
                'company_id' => $companyId, 'user_id' => $this->destinoId,
                'papel' => 'estrategista', 'evento' => 'entrada',
            ]);
        }
        $this->assertDatabaseMissing('company_manager_history', [
            'company_id' => $this->companyCId, 'user_id' => $this->destinoId,
            'papel' => 'estrategista', 'evento' => 'entrada',
        ]);

        $this->assertSame(1 + 5, DB::table('company_manager_history')->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 10: cargo extra no destino, origem desativada e intacta
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_grava_cargo_extra_no_destino_e_desativa_origem_sem_apagar(): void
    {
        $this->chamar($this->opcoesBase(['--apply' => true]));

        $this->assertDatabaseHas('user_setores', [
            'user_id' => $this->destinoId, 'setor_id' => $this->setorPerformanceId,
            'cargo_id' => $this->cargoEstrategistaId, 'is_principal' => false,
        ]);
        $this->assertDatabaseHas('user_setores', [
            'user_id' => $this->destinoId, 'setor_id' => $this->setorPerformanceId,
            'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true,
        ]);

        $this->assertSame(0, (int) DB::table('users')->where('id', $this->origemId)->value('active'));

        // Linha de user_setores da ORIGEM continua intacta.
        $this->assertDatabaseHas('user_setores', [
            'user_id' => $this->origemId, 'setor_id' => $this->setorPerformanceId,
            'cargo_id' => $this->cargoEstrategistaId,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 11: backup por lote com o conteúdo esperado
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_grava_backup_com_lote_unico_e_conteudo_esperado(): void
    {
        $exit = $this->chamar($this->opcoesBase(['--apply' => true]));
        $this->assertSame(0, $exit);

        $registros = DB::table('unificacao_contas_backup')->get();
        $this->assertGreaterThan(0, $registros->count());
        $this->assertCount(1, $registros->pluck('lote')->unique());

        foreach ($registros as $registro) {
            $this->assertSame($this->origemId, (int) $registro->de_user_id);
            $this->assertSame($this->destinoId, (int) $registro->para_user_id);
            $this->assertSame('2026-09-01', substr((string) $registro->a_partir, 0, 10));
        }

        $deleteRow = $registros->first(fn ($r) => $r->acao === 'delete');
        $this->assertNotNull($deleteRow);
        $antesDelete = json_decode($deleteRow->antes, true);
        $this->assertArrayHasKey('id', $antesDelete);
        $this->assertArrayHasKey('company_id', $antesDelete);
        $this->assertSame($this->origemId, $antesDelete['user_id']);

        $usersRow = $registros->first(fn ($r) => $r->tabela === 'users');
        $this->assertNotNull($usersRow);
        $this->assertSame(['active' => true], json_decode($usersRow->antes, true));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 12: cache dos dois usuários é derrubado
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_busta_cache_dos_dois_usuarios(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15'));

        $scoreService = app(DesempenhoScoreService::class);
        $chaves = [
            $scoreService->cacheKey($this->origemId, Carbon::parse('2026-09-01')),
            $scoreService->cacheKey($this->destinoId, Carbon::parse('2026-09-01')),
            $scoreService->cacheKey($this->origemId, Carbon::now()),
            $scoreService->cacheKey($this->destinoId, Carbon::now()),
        ];

        foreach ($chaves as $chave) {
            Cache::put($chave, ['valor' => 'teste'], 600);
        }

        $this->chamar($this->opcoesBase(['--apply' => true]));

        foreach ($chaves as $chave) {
            $this->assertFalse(Cache::has($chave), "chave de cache ainda presente: {$chave}");
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 13: --apply repetido é idempotente
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_repetido_e_idempotente(): void
    {
        $this->chamar($this->opcoesBase(['--apply' => true]));
        $totalBackupAntes = DB::table('unificacao_contas_backup')->count();

        $exit = $this->chamar($this->opcoesBase(['--apply' => true]));

        $this->assertSame(0, $exit);
        $this->assertSame($totalBackupAntes, DB::table('unificacao_contas_backup')->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 14: --desfazer restaura e recusa rodar duas vezes
    // ═════════════════════════════════════════════════════════════════════

    public function test_desfazer_restaura_estado_anterior_e_recusa_lote_repetido(): void
    {
        $this->chamar($this->opcoesBase(['--apply' => true]));
        $lote = DB::table('unificacao_contas_backup')->value('lote');
        $this->assertNotNull($lote);

        // Sem --apply: nada muda.
        $assinaturaAplicada = $this->assinaturaNucleo();
        $exitDry = $this->chamar(['--desfazer' => $lote]);
        $this->assertSame(0, $exitDry);
        $this->assertSame($assinaturaAplicada, $this->assinaturaNucleo());

        $exitApply = $this->chamar(['--desfazer' => $lote, '--apply' => true]);
        $this->assertSame(0, $exitApply);

        // Carteira: empresa A e B voltam para a origem.
        $this->assertSame(0, DB::table('company_users')
            ->where('company_id', $this->companyAId)->where('user_id', $this->destinoId)->count());
        $this->assertSame(1, DB::table('company_users')
            ->where('company_id', $this->companyAId)->where('user_id', $this->origemId)->count());

        // Empresa C: linha da origem (apagada no apply) volta com o MESMO id.
        $this->assertSame(2, DB::table('company_users')->where('company_id', $this->companyCId)->count());
        $this->assertSame(1, DB::table('company_users')
            ->where('company_id', $this->companyCId)->where('user_id', $this->origemId)->where('role', 'estrategista')->count());

        // Cargo extra do destino foi removido.
        $this->assertDatabaseMissing('user_setores', [
            'user_id' => $this->destinoId, 'cargo_id' => $this->cargoEstrategistaId,
        ]);

        // Eventos de histórico inseridos pelo apply foram removidos.
        $this->assertSame(0, DB::table('company_manager_history')->count());

        // Origem volta a ficar ativa.
        $this->assertSame(1, (int) DB::table('users')->where('id', $this->origemId)->value('active'));

        $this->assertNotNull(DB::table('unificacao_contas_backup')->where('lote', $lote)->value('desfeito_em'));

        $exitRepetir = $this->chamar(['--desfazer' => $lote, '--apply' => true]);
        $this->assertSame(1, $exitRepetir);
        $this->assertStringContainsString('desfeito', Artisan::output());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Task 2 — Teste 15: --apply recusado (bloqueio/censo) não grava nada
    // ═════════════════════════════════════════════════════════════════════

    public function test_apply_recusado_por_bloqueio_ou_censo_nao_grava_nada(): void
    {
        // Caso 1: competência já consolidada (snapshot mensal).
        DesempenhoScoreSnapshot::create([
            'user_id'        => $this->origemId,
            'ref_date'       => '2026-09-01',
            'mes_referencia' => '2026-09-01',
            'score'          => 80,
            'classificacao'  => 'bom',
            'breakdown_json' => [],
        ]);

        $exit1 = $this->chamar($this->opcoesBase(['--apply' => true]));
        $this->assertSame(1, $exit1);
        $this->assertSame(0, DB::table('unificacao_contas_backup')->count());

        DesempenhoScoreSnapshot::query()->delete();

        // Caso 2: censo sem_regra sem --manter.
        DB::table('setor_lideres')->insert([
            'setor_id'    => $this->setorPerformanceId,
            'user_id'     => $this->origemId,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $exit2 = $this->chamar($this->opcoesBase(['--apply' => true]));
        $this->assertSame(1, $exit2);
        $this->assertSame(0, DB::table('unificacao_contas_backup')->count());
    }
}
