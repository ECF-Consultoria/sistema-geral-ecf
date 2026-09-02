<?php

// Roster congelado por mês — `polos:congelar-roster` e a leitura no PolosController.
//
// Por que existe (incidente 02/09/2026): o /polos já congelava o FATURAMENTO por mês, mas
// o ROSTER era sempre inferido. Nenhuma das duas fontes descreve o passado — o cadastro ao
// vivo já avançou de fase na virada (324 mudanças em 01/09) e o CSV da Comercial lista toda
// a base de sellers, não só o programa. Agosto foi lido de três jeitos diferentes e deu
// R$ 2,74 mi, R$ 4,73 mi e 43,8% / 57,7% de "no alvo" conforme a fonte.

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use App\Models\PoloRosterSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** @group polos */
class CongelarRosterPolosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `created_at` não é fillable — o Eloquent carimba now() e ignora o valor passado.
     * Como o backfill descarta empresa criada depois do mês, a data precisa ser gravada
     * à parte, senão todo cadastro de teste "nasce" hoje e some do roster do mês passado.
     */
    private function empresa(array $attrs = []): MlbEmpresa
    {
        $criadaEm = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $e = MlbEmpresa::create(array_merge([
            'nome' => 'Loja Teste', 'cust_id' => '1000000001', 'fase' => 'M2',
            'projeto' => 'POLOS', 'polo' => 'Arapongas', 'tipo' => 'POLO',
        ], $attrs));

        if ($criadaEm !== null) {
            DB::table('mlb_empresas')->where('id', $e->id)->update(['created_at' => $criadaEm]);
            $e->refresh();
        }

        return $e;
    }

    /** Evento de activity_log como o Spatie grava (old → attributes). */
    private function log(int $empresaId, array $old, array $novo, string $quando): void
    {
        DB::table('activity_log')->insert([
            'log_name'     => 'default',
            'description'  => 'Empresa MLB atualizada',
            'subject_type' => MlbEmpresa::class,
            'subject_id'   => $empresaId,
            'properties'   => json_encode(['old' => $old, 'attributes' => $novo]),
            'created_at'   => $quando,
            'updated_at'   => $quando,
        ]);
    }

    private function rosterDoMes(string $mes): array
    {
        $m = new ReflectionMethod(PolosController::class, 'montarAtivosDoMes');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $mes, false, []);
    }

    public function test_congela_o_roster_ao_vivo_do_mes_corrente(): void
    {
        $this->empresa(['cust_id' => '1000000001', 'nome' => 'Ativa', 'fase' => 'M3']);
        $this->empresa(['cust_id' => '1000000002', 'nome' => 'Churn', 'fase' => 'Churn']);
        $this->empresa(['cust_id' => '1000000003', 'nome' => 'Outro Projeto', 'projeto' => 'Assessoria']);

        $mes = now()->format('Ym');
        $this->artisan('polos:congelar-roster', ['--apply' => true])->assertExitCode(0);

        $snap = PoloRosterSnapshot::where('mes', $mes)->get();
        $this->assertSame(['1000000001'], $snap->pluck('cust_id')->all(),
            'Só M2/M3/M4/Fechamento de projeto POLOS entram no roster.');
        $this->assertSame('M3', $snap->first()->fase);
        $this->assertSame(PoloRosterSnapshot::ORIGEM_VIVO, $snap->first()->origem);
    }

    public function test_dry_run_nao_grava(): void
    {
        $this->empresa();

        $this->artisan('polos:congelar-roster')->assertExitCode(0);

        $this->assertSame(0, PoloRosterSnapshot::count(), 'Sem --apply nada pode ser gravado.');
    }

    public function test_backfill_pelo_log_desfaz_a_cascata_de_fases(): void
    {
        // O caso real: a empresa era M4 em agosto e foi para Encerrado na virada.
        // Ler o cadastro de hoje a tiraria do roster de agosto — que é o bug.
        $e = $this->empresa([
            'cust_id' => '2000000001', 'nome' => 'Graduou Na Virada', 'fase' => 'Encerrado',
            'created_at' => '2026-05-01 10:00:00',
        ]);
        $this->log($e->id, ['fase' => 'M4'], ['fase' => 'Encerrado'], '2026-09-01 08:32:10');

        $this->artisan('polos:congelar-roster', ['--mes' => '202608', '--do-log' => true, '--apply' => true])
            ->assertExitCode(0);

        $snap = PoloRosterSnapshot::where('mes', '202608')->first();
        $this->assertNotNull($snap, 'A empresa era M4 em agosto — tem de entrar no roster do mês.');
        $this->assertSame('M4', $snap->fase, 'A fase congelada é a de agosto, não a de hoje.');
        $this->assertSame(PoloRosterSnapshot::ORIGEM_LOG, $snap->origem);
    }

    public function test_backfill_ignora_evento_anterior_ao_corte(): void
    {
        // Só eventos POSTERIORES ao fim do mês são desfeitos; o que aconteceu dentro do
        // mês já está refletido no estado que se quer reconstruir.
        $e = $this->empresa([
            'cust_id' => '2000000002', 'fase' => 'M3', 'created_at' => '2026-05-01 10:00:00',
        ]);
        $this->log($e->id, ['fase' => 'M2'], ['fase' => 'M3'], '2026-08-15 09:00:00');

        $this->artisan('polos:congelar-roster', ['--mes' => '202608', '--do-log' => true, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('M3', PoloRosterSnapshot::where('mes', '202608')->first()->fase);
    }

    public function test_backfill_desfaz_arquivamento_posterior(): void
    {
        // Arquivar é evento de HOJE e não apaga o mês passado — foi assim que a Spinella
        // Decor (R$ 900 mil/mês) sumiu do painel por 6 semanas.
        $e = $this->empresa([
            'cust_id' => '2000000003', 'fase' => 'M4', 'created_at' => '2026-05-01 10:00:00',
            'arquivado_em' => '2026-09-01 12:00:00',
        ]);
        $this->log($e->id, ['arquivado_em' => null], ['arquivado_em' => '2026-09-01 12:00:00'], '2026-09-01 12:00:00');

        $this->artisan('polos:congelar-roster', ['--mes' => '202608', '--do-log' => true, '--apply' => true])
            ->assertExitCode(0);

        $this->assertNotNull(PoloRosterSnapshot::where('mes', '202608')->first(),
            'Empresa arquivada DEPOIS do mês continua no roster daquele mês.');
    }

    public function test_backfill_exclui_empresa_criada_depois_do_mes(): void
    {
        $this->empresa(['cust_id' => '2000000004', 'fase' => 'M2', 'created_at' => '2026-09-01 09:00:00']);

        $this->artisan('polos:congelar-roster', ['--mes' => '202608', '--do-log' => true, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(0, PoloRosterSnapshot::where('mes', '202608')->count(),
            'Cadastrada em setembro não fazia parte de agosto.');
    }

    public function test_controller_prefere_o_roster_congelado(): void
    {
        // Cadastro de HOJE diz Encerrado; o congelado de agosto diz M4. Vale o congelado.
        $e = $this->empresa(['cust_id' => '3000000001', 'nome' => 'Hoje Encerrada', 'fase' => 'Encerrado']);
        PoloRosterSnapshot::create([
            'mes' => '202608', 'cust_id' => '3000000001', 'mlb_empresa_id' => $e->id,
            'nome' => 'Era M4 Em Agosto', 'fase' => 'M4', 'polo' => 'São Bento do Sul',
            'origem' => PoloRosterSnapshot::ORIGEM_LOG, 'congelado_em' => now(),
        ]);

        $roster = $this->rosterDoMes('202608');

        $this->assertCount(1, $roster);
        $this->assertSame('M4', $roster[0]['fase']);
        $this->assertSame('Era M4 Em Agosto', $roster[0]['nome']);
    }

    public function test_mes_sem_congelamento_cai_no_fallback(): void
    {
        // Histórico antigo, anterior ao congelamento, não pode virar tela vazia.
        $this->empresa(['cust_id' => '4000000001', 'fase' => 'M2']);

        $this->assertSame([], $this->rosterDoMes('202001'),
            'Sem snapshot e sem linhas de CSV o fallback devolve vazio — mas não quebra.');
    }

    public function test_congelar_de_novo_atualiza_em_vez_de_duplicar(): void
    {
        // O agendamento roda todo dia sobre o mês corrente.
        $this->empresa(['cust_id' => '5000000001', 'fase' => 'M2']);
        $mes = now()->format('Ym');

        $this->artisan('polos:congelar-roster', ['--apply' => true])->assertExitCode(0);
        MlbEmpresa::where('cust_id', '5000000001')->update(['fase' => 'M3']);
        $this->artisan('polos:congelar-roster', ['--apply' => true])->assertExitCode(0);

        $this->assertSame(1, PoloRosterSnapshot::where('mes', $mes)->count());
        $this->assertSame('M3', PoloRosterSnapshot::where('mes', $mes)->first()->fase);
    }

    public function test_mes_invalido_falha_sem_gravar(): void
    {
        $this->artisan('polos:congelar-roster', ['--mes' => 'agosto', '--apply' => true])
            ->assertExitCode(1);

        $this->assertSame(0, PoloRosterSnapshot::count());
    }
}
