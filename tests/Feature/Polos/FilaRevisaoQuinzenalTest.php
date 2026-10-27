<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PoloAdsBonificacao;
use App\Models\PolosAgendaRevisao;
use App\Models\PolosComentario;
use App\Models\PolosRevisao;
use App\Models\User;
use App\Services\Polos\FilaRevisaoPolos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TKT-0027 (DEV-53) — fila de revisão quinzenal dos Polos.
 *
 * Cada empresa M1–M4 é revisada pelo responsável a cada 10 dias úteis, num dia fixo do
 * ciclo (Seg A … Sex B, Seg A = semana de 26/10/2026). O que os testes protegem:
 *   - a conta do dia do ciclo (A/B alternando, fim de semana sem fila);
 *   - quem entra na fila: agenda de hoje, atrasada desde o primeiro dia perdido, sem agenda;
 *   - a ordem: atrasadas (mais atrasada primeiro), sinais vermelhos, menor % da meta;
 *   - "Salvar e marcar feito" grava o comentário no Faturamento Polos e a revisão;
 *   - cada analista só mexe na própria carteira; admin troca o analista.
 *
 * Hoje fixado em quarta, 28/10/2026 = Qua A (dia 2).
 *
 * @group polos
 */
class FilaRevisaoQuinzenalTest extends TestCase
{
    use RefreshDatabase;

    private User $jhonatan;
    private User $elvis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00', 'America/Sao_Paulo'));

        config(['services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1', 'services.ecf.key' => 'test-key']);
        Cache::flush();
        // ECF Drive sem o arquivo: a fila abre sem os números do mês (testados à parte).
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0], 200)]);
        $this->withoutVite();

        $this->jhonatan = $this->userComPermissao('Jhonatan');
        $this->elvis    = $this->userComPermissao('Elvis');
    }

    // ─── Apoio ──────────────────────────────────────────────────────────────

    private function userComPermissao(string $nome): User
    {
        $slug    = 'setor-' . Str::random(6);
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor ' . $slug, 'slug' => $slug, 'active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('setor_permissoes')->insert([
            'setor_id' => $setorId, 'permission_key' => 'mlb.faturamento_polos', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role' => 'consultor', 'name' => $nome]);
        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId, 'cargo_id' => null, 'is_principal' => true,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    /** Empresa POLOS com ficha (o responsável do Painel mora na ficha). */
    private function empresa(string $nome, ?User $resp, array $opts = [], ?string $places = 'Ativo'): MlbEmpresa
    {
        $e = MlbEmpresa::create(array_merge([
            'nome' => $nome, 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => 'M2',
            'cust_id' => (string) random_int(100000000, 999999999), 'polo' => 'Arapongas', 'estagio' => 'Não Listado',
        ], $opts));

        MlbImplementacao::create([
            'empresa_id'     => $e->id,
            'token'          => 'tok-' . Str::random(12),
            'responsavel_id' => $resp?->id,
            'places'         => $places,
        ]);

        return $e->fresh();
    }

    private function agenda(MlbEmpresa $e, int $dia): void
    {
        PolosAgendaRevisao::create(['mlb_empresa_id' => $e->id, 'dia_ciclo' => $dia]);
    }

    private function revisada(MlbEmpresa $e, string $dia, ?User $por = null): void
    {
        PolosRevisao::create([
            'mlb_empresa_id' => $e->id, 'user_id' => ($por ?? $this->jhonatan)->id,
            'data' => $dia, 'revisado_em' => Carbon::parse("{$dia} 09:30", 'America/Sao_Paulo'),
        ]);
    }

    private function bonus(MlbEmpresa $e, float $valor, float $saldo, string $vence = '2026-11-06'): void
    {
        PoloAdsBonificacao::create([
            'cust_id' => $e->cust_id, 'saldo' => $saldo, 'vence_em' => $vence, 'verificado_em' => now()->subHour(),
            'itens'   => [['nivel' => 'account', 'campanha' => null, 'programa' => 'manual-credits',
                'valor' => $valor, 'saldo' => $saldo, 'moeda' => 'BRL', 'vence_em' => $vence]],
        ]);
    }

    private function comentario(MlbEmpresa $e, string $texto, string $mes = '202610'): PolosComentario
    {
        return PolosComentario::create([
            'cust_id' => $e->cust_id, 'mes' => $mes, 'user_id' => $this->jhonatan->id,
            'autor_nome' => 'Jhonatan', 'texto' => $texto,
        ]);
    }

    private function fila(): FilaRevisaoPolos
    {
        return app(FilaRevisaoPolos::class);
    }

    // ─── Ciclo ──────────────────────────────────────────────────────────────

    public function test_dia_do_ciclo_alterna_semana_a_e_b_a_partir_de_26_10(): void
    {
        $dia = fn (string $d) => FilaRevisaoPolos::diaDoCiclo(Carbon::parse($d));

        $this->assertSame(0, $dia('2026-10-26'), 'Segunda da semana de referência = Seg A');
        $this->assertSame(2, $dia('2026-10-28'));
        $this->assertSame(4, $dia('2026-10-30'), 'Sex A');
        $this->assertNull($dia('2026-10-31'), 'Sábado não tem fila');
        $this->assertNull($dia('2026-11-01'), 'Domingo não tem fila');
        $this->assertSame(5, $dia('2026-11-02'), 'Seg B');
        $this->assertSame(9, $dia('2026-11-06'), 'Sex B');
        $this->assertSame(0, $dia('2026-11-09'), 'Volta para Seg A depois de 10 dias úteis');
        $this->assertSame(9, $dia('2026-10-23'), 'Antes da referência também alterna: semana anterior é B');
        $this->assertSame(0, $dia('2026-10-12'));

        $this->assertSame('Qua · Semana A', FilaRevisaoPolos::rotuloDoDia(2));
        $this->assertSame('Seg · Semana B', FilaRevisaoPolos::rotuloDoDia(5));
    }

    public function test_ciclo_inicio_configuravel_e_sempre_ancorado_numa_segunda(): void
    {
        Configuracao::set('polos_ciclo_inicio', '2026-11-04');

        $this->assertSame('2026-11-02', FilaRevisaoPolos::cicloInicio()->toDateString());
        $this->assertSame(0, FilaRevisaoPolos::diaDoCiclo(Carbon::parse('2026-11-02')));
    }

    public function test_situacao_agenda_atrasada_feita_e_sem_agenda(): void
    {
        $hoje = Carbon::parse('2026-10-28');
        $f    = $this->fila();

        $this->assertSame(['situacao' => 'agenda_hoje', 'dias_atraso' => null], $f->situacao(2, $hoje));
        $this->assertSame(['situacao' => 'sem_agenda', 'dias_atraso' => null], $f->situacao(null, $hoje));
        // Seg A (26/10) passou sem revisão.
        $this->assertSame(['situacao' => 'atrasada', 'dias_atraso' => 2], $f->situacao(0, $hoje));
        // Revisada na própria Seg A: só volta na Seg A do ciclo seguinte (09/11).
        $this->assertNull($f->situacao(0, $hoje, null, '2026-10-26'));
        // Última revisão em 09/10: perdeu a Seg A de 12/10 e a de 26/10 — conta desde 12/10.
        $this->assertSame(['situacao' => 'atrasada', 'dias_atraso' => 16], $f->situacao(0, $hoje, null, '2026-10-09'));
        // Fim de semana: ninguém entra.
        $this->assertNull($f->situacao(null, Carbon::parse('2026-10-31')));
    }

    public function test_so_atrasa_quando_o_dia_passou_neste_ciclo_ou_a_revisao_tem_mais_de_15_dias(): void
    {
        $terA = Carbon::parse('2026-10-27'); // Ter A (dia 1)
        $f    = $this->fila();

        // Qui A ainda vem neste ciclo (29/10): a Qui A do ciclo anterior (15/10) não atrasa —
        // é o caso de quem acabou de ganhar dia na agenda (visto na conferência de 27/10).
        $this->assertNull($f->situacao(3, $terA));
        $this->assertNull($f->situacao(7, $terA), 'Qua B passou no ciclo anterior, sem revisão nenhuma: espera o dia dela.');
        // Mas revisão com mais de 15 dias atrasa: última em 07/10, perdeu a Qua B de 21/10.
        $this->assertSame(['situacao' => 'atrasada', 'dias_atraso' => 6], $f->situacao(7, $terA, null, '2026-10-07'));
        // Revisada há 15 dias exatos (12/10) ainda não está velha.
        $this->assertNull($f->situacao(7, $terA, null, '2026-10-12'));
        // Seg A passou ontem neste ciclo: atrasada.
        $this->assertSame(['situacao' => 'atrasada', 'dias_atraso' => 1], $f->situacao(0, $terA));
    }

    // ─── A fila ─────────────────────────────────────────────────────────────

    public function test_fila_do_analista_traz_agenda_atrasadas_e_sem_agenda_na_ordem_da_spec(): void
    {
        $agendaHoje = $this->empresa('Agenda Hoje', $this->jhonatan, [], 'Solicitado');
        $atrasada   = $this->empresa('Atrasada Recente', $this->jhonatan);
        $antiga     = $this->empresa('Atrasada Antiga', $this->jhonatan, ['fase' => 'M3']);
        $emDia      = $this->empresa('Feita no Ciclo', $this->jhonatan);
        $semAgenda  = $this->empresa('Empresa Sem Agenda', $this->jhonatan, ['fase' => 'M4']);
        $feitaHoje  = $this->empresa('Revisada Hoje', $this->jhonatan);
        $this->empresa('Em Churn', $this->jhonatan, ['fase' => 'Churn']);
        $this->empresa('Arquivada', $this->jhonatan, ['arquivado_em' => now()->subDay()]);
        $this->empresa('Do Elvis', $this->elvis, ['fase' => 'M3']);
        // Sem ficha: vale o responsável da empresa (mesma regra do Painel).
        $m1 = MlbEmpresa::create(['nome' => 'Kappa M1', 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => 'M1',
            'cust_id' => '777777777', 'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'responsavel_id' => $this->jhonatan->id]);

        $this->agenda($agendaHoje, 2);
        $this->agenda($atrasada, 0);
        $this->agenda($antiga, 0);
        $this->revisada($antiga, '2026-10-09');
        $this->agenda($emDia, 0);
        $this->revisada($emDia, '2026-10-26');
        $this->agenda($feitaHoje, 2);
        $this->revisada($feitaHoje, '2026-10-28');

        $this->bonus($agendaHoje, 3000, 2900);
        $this->comentario($agendaHoje, 'Seller está SEM BÔNUS este mês');

        $fat = [$agendaHoje->id => ['faturamento' => 700.0, 'meta' => 1000.0, 'pct' => 70.0, 'ads' => 100.0, 'polo' => 'Arapongas']];
        $r   = $this->fila()->montar($this->jhonatan->id, now(), $fat);

        $this->assertSame(
            ['Atrasada Antiga', 'Atrasada Recente', 'Agenda Hoje', 'Empresa Sem Agenda', 'Kappa M1'],
            array_column($r['pendentes'], 'nome'),
            'Atrasadas primeiro (mais atrasada antes), depois quem tem mais sinais vermelhos.',
        );
        $this->assertSame([16, 2], array_column(array_slice($r['pendentes'], 0, 2), 'dias_atraso'));
        $this->assertSame(['Revisada Hoje'], array_column($r['feitas'], 'nome'));
        // Visão "Todas da carteira": quem não está na fila de hoje vem em em_dia, com a próxima data.
        $this->assertSame(['Feita no Ciclo'], array_column($r['em_dia'], 'nome'));
        $this->assertSame('em_dia', $r['em_dia'][0]['situacao']);
        $this->assertSame('09/11', $r['em_dia'][0]['proxima'], 'Seg A do ciclo seguinte.');
        $this->assertSame('11/11', $r['feitas'][0]['revisao']['proxima'], 'Qua A seguinte, 14 dias depois.');

        $this->assertSame(5, $r['resumo']['para_revisar']);
        $this->assertSame(2, $r['resumo']['atrasadas']);
        $this->assertSame(1, $r['resumo']['agenda_hoje']);
        $this->assertSame(2, $r['resumo']['sem_agenda']);
        $this->assertSame(1, $r['resumo']['feitas']);
        $this->assertSame(6, $r['resumo']['total_dia']);
        $this->assertEquals(2900, $r['resumo']['bonus_parado']);
        $this->assertSame(1, $r['resumo']['comentarios_a_atualizar']);
        $this->assertSame(['data' => '06/11', 'dias' => 10], $r['resumo']['bonus_vence']);
        $this->assertSame('Qua · Semana A', $r['hoje']['rotulo']);

        $card = collect($r['pendentes'])->firstWhere('nome', 'Agenda Hoje');
        $this->assertSame('agenda_hoje', $card['situacao']);
        $this->assertTrue($card['desatualizado'], '"sem bônus" no último comentário, mas a Adman mostra saldo.');
        $this->assertSame(['bonus_parado', 'places', 'faltam'], array_column($card['sinais'], 'chave'));
        $this->assertSame('Faltam R$ 300,00', collect($card['sinais'])->firstWhere('chave', 'faltam')['texto']);
        $this->assertSame('Parado: precisa de R$ 290,00/dia até 06/11', $card['bonus']['ritmo']['texto']);
    }

    public function test_fim_de_semana_nao_tem_fila(): void
    {
        $e = $this->empresa('Qualquer', $this->jhonatan);
        $this->agenda($e, 4);

        $r = $this->fila()->montar($this->jhonatan->id, Carbon::parse('2026-10-31'));

        $this->assertSame([], $r['pendentes']);
        $this->assertNull($r['hoje']['rotulo']);
        // A carteira continua visível em "Todas", com a próxima revisão (Sex A seguinte: 06/11 é Sex B).
        $this->assertSame(['Qualquer'], array_column($r['em_dia'], 'nome'));
        $this->assertSame('13/11', $r['em_dia'][0]['proxima']);
    }

    // ─── Bônus e sinais ─────────────────────────────────────────────────────

    public function test_ritmo_do_bonus_parado_esgotado_ritmo_e_sem_bonus(): void
    {
        $hoje = Carbon::parse('2026-10-28');
        $leitura = fn (float $saldo) => new PoloAdsBonificacao(['itens' => [[
            'nivel' => 'account', 'programa' => 'manual-credits', 'valor' => 3000, 'saldo' => $saldo, 'vence_em' => '2026-11-06',
        ]]]);

        $parado = FilaRevisaoPolos::bonusDoMes($leitura(2500), $hoje);
        $this->assertSame('parado', $parado['ritmo']['tipo'], '500 de 3.000 = 16,7% usado');
        $this->assertSame(500.0, $parado['usado']);

        $this->assertSame('esgotado', FilaRevisaoPolos::bonusDoMes($leitura(40), $hoje)['ritmo']['tipo']);

        $ritmo = FilaRevisaoPolos::bonusDoMes($leitura(1000), $hoje);
        $this->assertSame('Ritmo ideal: R$ 100,00/dia até 06/11', $ritmo['ritmo']['texto']);

        // Só o bônus de R$ 3.000 (manual-credits da conta) conta; o de startup não.
        $startup = new PoloAdsBonificacao(['itens' => [['nivel' => 'account', 'programa' => 'seller-startup-program', 'valor' => 250, 'saldo' => 250, 'vence_em' => '2027-07-27']]]);
        $this->assertNull(FilaRevisaoPolos::bonusDoMes($startup, $hoje));
        $this->assertNull(FilaRevisaoPolos::bonusDoMes(null, $hoje));
    }

    public function test_sinais_campanha_desligada_ads_sem_acordo_e_no_alvo(): void
    {
        $base = ['bonus' => null, 'campanha' => null, 'pct' => null, 'meta' => null, 'faturamento' => null];
        $chaves = fn (array $card, ?string $places = 'Ativo', array $coms = []) => array_column(FilaRevisaoPolos::sinais(array_merge($base, $card), $places, $coms), 'chave');

        $bonusVivo = ['saldo' => 500.0, 'pct_usado' => 83.0];
        $this->assertSame(['campanha_desligada'], $chaves(['bonus' => $bonusVivo, 'campanha' => 'desligada']));
        $this->assertSame(['ads_sem_acordo'], $chaves(['campanha' => 'ativa']));
        $this->assertSame([], $chaves(['campanha' => 'ativa'], 'Ativo', ['Acordo com o seller: R$ 20/dia']), 'Comentário do mês com acordo apaga o sinal.');
        $this->assertSame(['no_alvo'], $chaves(['pct' => 140.0, 'meta' => 1000.0, 'faturamento' => 1400.0]));
        $this->assertSame([], $chaves(['pct' => 40.0, 'meta' => 1000.0, 'faturamento' => 400.0]), 'Abaixo de 60% não ganha chip verde.');
        $this->assertSame([], $chaves([], 'Não é necessário'));
        $this->assertSame([], $chaves([], null), 'Places não informado não acusa nada.');
    }

    // ─── Marcar e desmarcar ─────────────────────────────────────────────────

    public function test_salvar_e_marcar_feito_grava_comentario_no_faturamento_polos_e_a_revisao(): void
    {
        $e = $this->empresa('Loja Alfa', $this->jhonatan);
        $this->comentario($e, 'sem bônus em outubro');
        $this->bonus($e, 3000, 1500);

        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.revisoes.marcar'), ['empresa_id' => $e->id, 'comentario' => '  Liguei a campanha nova  '])
            ->assertRedirect();

        $c = PolosComentario::latest('id')->first();
        $this->assertSame('Liguei a campanha nova', $c->texto);
        $this->assertSame('202610', $c->mes);
        $this->assertSame((string) $e->cust_id, $c->cust_id);
        $this->assertSame($this->jhonatan->id, $c->user_id);

        $rev = PolosRevisao::where('mlb_empresa_id', $e->id)->first();
        $this->assertSame('2026-10-28', $rev->data->toDateString());
        $this->assertSame($c->id, $rev->polos_comentario_id);
        $this->assertSame($this->jhonatan->id, $rev->user_id);

        $this->assertTrue(Activity::where('subject_type', MlbEmpresa::class)->where('subject_id', $e->id)
            ->where('description', 'like', '%Revisão quinzenal feita%')->where('causer_id', $this->jhonatan->id)->exists());

        $r = $this->fila()->montar($this->jhonatan->id, now());
        $this->assertSame([], $r['pendentes']);
        $this->assertSame('Liguei a campanha nova', $r['feitas'][0]['revisao']['comentario']);
        $this->assertFalse($r['feitas'][0]['desatualizado'], 'O comentário novo tira o aviso de desatualizado.');
    }

    public function test_marcar_sem_texto_grava_so_a_revisao_e_desmarcar_desfaz_o_dia(): void
    {
        $e = $this->empresa('Loja Beta', $this->jhonatan);

        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.revisoes.marcar'), ['empresa_id' => $e->id])
            ->assertRedirect();
        // Marcar de novo no mesmo dia não duplica.
        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.revisoes.marcar'), ['empresa_id' => $e->id, 'comentario' => ''])
            ->assertRedirect();

        $this->assertSame(0, PolosComentario::count(), 'Sem texto não grava comentário padrão.');
        $this->assertSame(1, PolosRevisao::where('mlb_empresa_id', $e->id)->count());
        $this->assertNull(PolosRevisao::first()->polos_comentario_id);

        $this->actingAs($this->jhonatan)
            ->delete(route('mlb.polos-painel.fila.revisoes.desmarcar', $e->id))
            ->assertRedirect();

        $this->assertSame(0, PolosRevisao::count());
        $this->assertSame('sem_agenda', $this->fila()->montar($this->jhonatan->id, now())['pendentes'][0]['situacao']);
    }

    public function test_analista_so_mexe_na_propria_carteira_e_admin_em_todas(): void
    {
        $e = $this->empresa('Do Jhonatan', $this->jhonatan);

        $this->actingAs($this->elvis)
            ->post(route('mlb.polos-painel.fila.revisoes.marcar'), ['empresa_id' => $e->id])
            ->assertForbidden();
        $this->actingAs($this->elvis)
            ->delete(route('mlb.polos-painel.fila.revisoes.desmarcar', $e->id))
            ->assertForbidden();
        $this->assertSame(0, PolosRevisao::count());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->post(route('mlb.polos-painel.fila.revisoes.marcar'), ['empresa_id' => $e->id])
            ->assertRedirect();
        $this->assertSame($admin->id, PolosRevisao::first()->user_id);
    }

    // ─── Agenda ─────────────────────────────────────────────────────────────

    public function test_salvar_agenda_mexe_so_na_carteira_do_analista_e_null_limpa(): void
    {
        $a = $this->empresa('Alfa', $this->jhonatan);
        $b = $this->empresa('Beta', $this->jhonatan);
        $doElvis = $this->empresa('Gama', $this->elvis);
        $this->agenda($a, 0);

        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.agenda.salvar'), [
                'analista_id' => $this->jhonatan->id,
                'dias'        => [$a->id => 5, $b->id => 1, $doElvis->id => 3],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Agenda salva: 2 empresa(s) alterada(s).');

        $this->assertSame(5, PolosAgendaRevisao::where('mlb_empresa_id', $a->id)->value('dia_ciclo'));
        $this->assertSame(1, PolosAgendaRevisao::where('mlb_empresa_id', $b->id)->value('dia_ciclo'));
        $this->assertNull(PolosAgendaRevisao::where('mlb_empresa_id', $doElvis->id)->first(), 'Empresa de outra carteira é ignorada.');
        $this->assertSame($this->jhonatan->id, PolosAgendaRevisao::where('mlb_empresa_id', $b->id)->value('definido_por'));

        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.agenda.salvar'), ['analista_id' => $this->jhonatan->id, 'dias' => [$a->id => null]])
            ->assertRedirect();
        $this->assertNull(PolosAgendaRevisao::where('mlb_empresa_id', $a->id)->first());

        // Dia fora de 0–9 é recusado; agenda de outro analista, só admin.
        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.agenda.salvar'), ['analista_id' => $this->jhonatan->id, 'dias' => [$b->id => 10]])
            ->assertSessionHasErrors('dias.' . $b->id);
        $this->actingAs($this->jhonatan)
            ->post(route('mlb.polos-painel.fila.agenda.salvar'), ['analista_id' => $this->elvis->id, 'dias' => [$doElvis->id => 3]])
            ->assertForbidden();
    }

    // ─── Telas ──────────────────────────────────────────────────────────────

    public function test_tela_abre_na_fila_do_proprio_analista_e_admin_troca(): void
    {
        $this->empresa('Do Jhonatan', $this->jhonatan);
        $this->empresa('Do Elvis', $this->elvis);

        // Analista pedindo a fila de outro continua vendo a própria.
        $this->actingAs($this->jhonatan)
            ->get(route('mlb.polos-painel.fila', ['analista' => $this->elvis->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polos/Fila/Index', false)
                ->where('analistaId', $this->jhonatan->id)
                ->where('podeTrocar', false)
                ->has('analistas', 1)
                ->where('fila.pendentes.0.nome', 'Do Jhonatan')
                ->where('avisoFaturamento', fn ($v) => $v !== null));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get(route('mlb.polos-painel.fila', ['analista' => $this->elvis->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('analistaId', $this->elvis->id)->has('analistas', 2)
                ->where('fila.pendentes.0.nome', 'Do Elvis'));

        $this->actingAs($this->jhonatan)
            ->get(route('mlb.polos-painel.fila.agenda'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polos/Fila/Agenda', false)
                ->where('empresas.0.nome', 'Do Jhonatan')
                ->has('dias', 10));

        $semPermissao = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($semPermissao)->get(route('mlb.polos-painel.fila'))->assertForbidden();
    }

    public function test_faturamento_polos_traz_o_responsavel_de_cada_empresa(): void
    {
        $e = $this->empresa('Com Responsável', $this->jhonatan);

        $m = new ReflectionMethod(PolosController::class, 'linhasDaVisaoCompleta');
        $m->setAccessible(true);
        $linhas = collect($m->invoke(app(PolosController::class), [
            'parcial' => false,
            'polos'   => [['polo' => 'Arapongas', 'empresas' => [
                ['mlb_empresa_id' => $e->id, 'cust_id' => $e->cust_id, 'faturamento' => 10.0],
                ['mlb_empresa_id' => null, 'cust_id' => '1', 'faturamento' => 5.0],
            ]]],
        ]))->keyBy('cust_id');

        $this->assertSame('Jhonatan', $linhas[$e->cust_id]['responsavel']);
        $this->assertSame($this->jhonatan->id, $linhas[$e->cust_id]['responsavel_id']);
        $this->assertNull($linhas['1']['responsavel']);
    }
}
