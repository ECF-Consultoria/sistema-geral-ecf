<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoManual;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Faturamento lançado à mão no Faturamento Polos (pedido de 22/10/2026).
 *
 * Três decisões do usuário que estes testes travam:
 *  - quem lança: SÓ o cargo Dev, a conta "Admin" e a Débora Lima — não qualquer admin;
 *  - a Adman prevalece: o manual só vale onde ela não tem dado no mês (snapshot ausente,
 *    ou coluna da métrica vigente NULL). R$ 0 da Adman é dado e não é sobrescrito;
 *  - qualquer mês até o corrente.
 *
 * @group polos
 */
class FaturamentoManualTest extends TestCase
{
    use RefreshDatabase;

    private const CUST = '3323737411';

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function empresa(array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => 'Loja ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'cust_id' => self::CUST,
        ], $opts));
    }

    /** Usuário com o e-mail dado; reaproveita se o seed já criou (a conta "Admin"). */
    private function usuario(string $email, array $attrs = []): User
    {
        $user = User::where('email', $email)->first() ?? User::factory()->create(['email' => $email]);
        $user->forceFill(array_merge(['role' => 'admin', 'is_dev' => false, 'email_verified_at' => now()], $attrs))->save();

        return $user->refresh();
    }

    private function debora(): User
    {
        return $this->usuario('consultor.04@ecfconsultoria.com.br');
    }

    private function adminComum(): User
    {
        return $this->usuario('comercial@ecfconsultoria.com.br');
    }

    private function snapshot(string $mes, ?float $gross, ?float $moveis, string $cust = self::CUST): void
    {
        PoloFaturamentoSnapshot::create([
            'mes' => $mes, 'cust_id' => $cust,
            'faturamento' => $gross, 'faturamento_moveis' => $moveis,
            'ads' => 0, 'synced_at' => now(),
        ]);
    }

    private function lancar(User $user, array $dados)
    {
        return $this->actingAs($user)->post(route('polos.faturamento-manual.salvar'), $dados + [
            'cust_id' => self::CUST,
            'mes'     => '202609',
        ]);
    }

    private function invoca(string $metodo, ...$args)
    {
        $m = new ReflectionMethod(PolosController::class, $metodo);
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), ...$args);
    }

    // ─── Quem pode ──────────────────────────────────────────────────────────

    public function test_debora_lanca_e_o_registro_guarda_quem_e_de_onde_veio(): void
    {
        $this->empresa(['nome' => 'Mabile']);
        $debora = $this->debora();

        $this->lancar($debora, ['valor' => 8688.5, 'observacao' => '  planilha de Evolução  '])
            ->assertRedirect()
            ->assertSessionHas('success');

        $l = PoloFaturamentoManual::sole();
        $this->assertSame(self::CUST, $l->cust_id);
        $this->assertSame('202609', $l->mes);
        $this->assertSame(8688.5, $l->valor);
        $this->assertSame('planilha de Evolução', $l->observacao);
        $this->assertSame($debora->id, $l->user_id);
        $this->assertSame($debora->name, $l->lancado_por_nome);
    }

    public function test_conta_admin_e_cargo_dev_tambem_lancam(): void
    {
        $this->empresa();

        $this->lancar($this->usuario('admin@ecfconsultoria.com.br'), ['valor' => 100])->assertSessionHasNoErrors();
        $this->lancar($this->usuario('dev.99@ecfconsultoria.com.br', ['is_dev' => true]), ['valor' => 200, 'mes' => '202608'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PoloFaturamentoManual::count());
    }

    public function test_admin_comum_nao_lanca_nem_remove(): void
    {
        // Ser admin NÃO basta: em produção há 12 admins e o pedido foi restringir a 4 pessoas.
        $this->empresa();
        $comum = $this->adminComum();

        $this->lancar($comum, ['valor' => 100])->assertForbidden();
        $this->assertSame(0, PoloFaturamentoManual::count());

        $this->lancar($this->debora(), ['valor' => 100]);
        $l = PoloFaturamentoManual::sole();

        $this->actingAs($comum)->delete(route('polos.faturamento-manual.remover', $l))->assertForbidden();
        $this->assertSame(1, PoloFaturamentoManual::count());
    }

    public function test_e_mail_e_comparado_sem_diferenciar_maiusculas(): void
    {
        $this->empresa();
        $this->lancar($this->usuario('Consultor.04@ECFconsultoria.com.br'), ['valor' => 50])->assertSessionHasNoErrors();

        $this->assertSame(1, PoloFaturamentoManual::count());
    }

    // ─── Validações ─────────────────────────────────────────────────────────

    public function test_recusa_quando_a_adman_ja_tem_dado_no_mes(): void
    {
        $this->empresa(['nome' => 'Loja Medida']);
        $this->snapshot('202609', 5000.0, 4200.0);

        $this->lancar($this->debora(), ['valor' => 9999])
            ->assertSessionHasErrors(['valor' => 'A Adman já tem o faturamento de Loja Medida em Setembro/2026 (R$ 4.200,00). O lançamento manual só vale para empresa sem dado da Adman.']);

        $this->assertSame(0, PoloFaturamentoManual::count());
    }

    public function test_dados_invalidos_nao_gravam(): void
    {
        $this->empresa();
        $debora = $this->debora();

        $this->lancar($debora, ['valor' => -1])->assertSessionHasErrors('valor');
        $this->lancar($debora, ['valor' => 'abc'])->assertSessionHasErrors('valor');
        $this->lancar($debora, ['valor' => 10, 'mes' => '2026-09'])->assertSessionHasErrors('mes');
        $this->lancar($debora, ['valor' => 10, 'mes' => '202613'])->assertSessionHasErrors('mes');
        // Mês que ainda não começou.
        $this->lancar($debora, ['valor' => 10, 'mes' => now()->addMonthNoOverflow()->format('Ym')])->assertSessionHasErrors('mes');
        // Cust que não é de empresa Polos.
        $this->lancar($debora, ['valor' => 10, 'cust_id' => '1111111111'])->assertSessionHasErrors('cust_id');

        $this->assertSame(0, PoloFaturamentoManual::count());
    }

    public function test_mes_fechado_e_empresa_arquivada_aceitam(): void
    {
        // Mês fechado conta quem foi arquivada depois (roster congelado/CSV inclui arquivadas).
        $this->empresa(['arquivado_em' => now()]);

        $this->lancar($this->debora(), ['valor' => 300, 'mes' => '202601'])->assertSessionHasNoErrors();

        $this->assertSame('202601', PoloFaturamentoManual::sole()->mes);
    }

    // ─── Corrigir e remover ─────────────────────────────────────────────────

    public function test_lancar_de_novo_corrige_a_mesma_linha_e_o_historico_fica_no_log(): void
    {
        $this->empresa();
        $debora = $this->debora();

        // Formato cru do CSV ("<id>,0") cai na mesma empresa.
        $this->lancar($debora, ['valor' => 1000, 'cust_id' => self::CUST . ',0']);
        $this->lancar($debora, ['valor' => 1500]);
        $this->lancar($debora, ['valor' => 1500]); // repetir não gera log novo

        $this->assertSame(1500.0, PoloFaturamentoManual::sole()->valor);

        $logs = Activity::where('log_name', 'polos')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->properties['antes']);
        $this->assertEquals(1000, $logs[1]->properties['antes']);
        $this->assertEquals(1500, $logs[1]->properties['depois']);
        $this->assertSame($debora->id, $logs[1]->causer_id);
    }

    public function test_remover_apaga_e_registra(): void
    {
        $this->empresa();
        $debora = $this->debora();
        $this->lancar($debora, ['valor' => 700]);

        $this->actingAs($debora)
            ->delete(route('polos.faturamento-manual.remover', PoloFaturamentoManual::sole()))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, PoloFaturamentoManual::count());
        $ultimo = Activity::where('log_name', 'polos')->latest('id')->first();
        $this->assertEquals(700, $ultimo->properties['antes']);
        $this->assertNull($ultimo->properties['depois']);
    }

    // ─── A regra de leitura: Adman prevalece ────────────────────────────────

    private function lancamento(float $valor, string $mes = '202609', string $cust = self::CUST): PoloFaturamentoManual
    {
        return PoloFaturamentoManual::create([
            'cust_id' => $cust, 'mes' => $mes, 'valor' => $valor,
            'lancado_por_nome' => 'Débora Lima',
        ]);
    }

    public function test_manual_preenche_empresa_sem_snapshot(): void
    {
        $this->lancamento(8688.0);

        $v = $this->invoca('faturamentoVigenteDoMes', [['cust_id' => self::CUST]], '202609');

        $this->assertSame(8688.0, $v['valores'][self::CUST]);
        $this->assertTrue($v['manuais'][self::CUST]['aplicado']);
        $this->assertSame('Débora Lima', $v['manuais'][self::CUST]['por']);
    }

    public function test_adman_prevalece_e_o_manual_fica_registrado_como_nao_aplicado(): void
    {
        $this->snapshot('202609', 5000.0, 4200.0);
        $this->lancamento(9999.0);

        $v = $this->invoca('faturamentoVigenteDoMes', [['cust_id' => self::CUST]], '202609');

        $this->assertSame(4200.0, $v['valores'][self::CUST]);
        $this->assertFalse($v['manuais'][self::CUST]['aplicado']);
    }

    public function test_zero_da_adman_e_dado_e_nao_e_sobrescrito(): void
    {
        $this->snapshot('202609', 0.0, 0.0);
        $this->lancamento(1234.0);

        $v = $this->invoca('faturamentoVigenteDoMes', [['cust_id' => self::CUST]], '202609');

        $this->assertSame(0.0, $v['valores'][self::CUST]);
        $this->assertFalse($v['manuais'][self::CUST]['aplicado']);
    }

    public function test_coluna_da_metrica_vazia_conta_como_sem_dado(): void
    {
        // Snapshot anterior a 07/07/2026 tem faturamento_moveis NULL: no modo 'moveis' a
        // empresa já sumia do mapa (FaturamentoMetricaTest) — então o manual vale.
        $this->snapshot('202606', 48000.0, null);
        $this->lancamento(40000.0, '202606');

        $moveis = $this->invoca('faturamentoVigenteDoMes', [['cust_id' => self::CUST]], '202606');
        $this->assertSame(40000.0, $moveis['valores'][self::CUST]);

        // Em gross a Adman tem o número — e prevalece.
        Configuracao::set('polo_metrica_faturamento', 'gross');
        $gross = $this->invoca('faturamentoVigenteDoMes', [['cust_id' => self::CUST]], '202606');
        $this->assertSame(48000.0, $gross['valores'][self::CUST]);
    }

    public function test_lancamento_de_outro_mes_nao_vaza(): void
    {
        $this->lancamento(500.0, '202608');

        $v = $this->invoca('faturamentoVigenteDoMes', [['cust_id' => self::CUST]], '202609');

        $this->assertSame([], $v['valores']);
        $this->assertSame([], $v['manuais']);
    }

    public function test_leitura_crua_da_adman_nao_enxerga_o_manual(): void
    {
        // faturamentoAdmanDoMes segue sendo SÓ a Adman: a auditoria (polos:audit-faturamento)
        // compara Adman × CSV e não pode misturar número digitado.
        $this->lancamento(500.0);

        $this->assertSame([], $this->invoca('faturamentoAdmanDoMes', [['cust_id' => self::CUST]], '202609'));
    }

    public function test_status_meta_e_fonte_usam_o_manual(): void
    {
        $this->lancamento(1500.0);                    // M2 sem Adman → manual
        $this->snapshot('202609', 900.0, 800.0, '2000000002'); // M2 com Adman
        // 3000000003: M2 sem nada

        $ativos = [
            ['id' => 1, 'cust_id' => self::CUST,   'nome' => 'Manual', 'polo' => 'Arapongas', 'fase' => 'M2', 'problema' => false],
            ['id' => 2, 'cust_id' => '2000000002', 'nome' => 'Adman',  'polo' => 'Arapongas', 'fase' => 'M2', 'problema' => false],
            ['id' => 3, 'cust_id' => '3000000003', 'nome' => 'Nada',   'polo' => 'Arapongas', 'fase' => 'M2', 'problema' => false],
        ];

        [$fat, , $manuais] = $this->invoca('faturamentoDoMes', '202609', false, $ativos, []);
        $polos = $this->invoca('agregarPorPolo', $ativos, [], ['M2' => 1000.0], $fat, [], $manuais);

        $this->assertCount(1, $polos);
        $this->assertSame(2300.0, $polos[0]['faturamento'], '1500 manual + 800 Adman + 0');

        $porNome = collect($polos[0]['empresas'])->keyBy('nome');
        $this->assertSame('manual', $porNome['Manual']['faturamento_fonte']);
        $this->assertSame('Sim', $porNome['Manual']['status']);
        $this->assertSame(1500.0, $porNome['Manual']['faturamento_manual']['valor']);
        $this->assertSame('adman', $porNome['Adman']['faturamento_fonte']);
        $this->assertNull($porNome['Adman']['faturamento_manual']);
        $this->assertSame('sem_dado', $porNome['Nada']['faturamento_fonte']);
        $this->assertSame('Não', $porNome['Nada']['status']);
    }

    public function test_planilha_diz_a_fonte_de_cada_numero(): void
    {
        $manual = ['faturamento_fonte' => 'manual', 'faturamento_manual' => ['por' => 'Débora Lima', 'em' => '22/10/2026 14:30']];

        $this->assertSame('Manual (Débora Lima, 22/10/2026 14:30)', $this->invoca('fonteFaturamentoParaCelula', $manual));
        $this->assertSame('Adman', $this->invoca('fonteFaturamentoParaCelula', ['faturamento_fonte' => 'adman']));
        $this->assertSame('Sem dado da Adman', $this->invoca('fonteFaturamentoParaCelula', ['faturamento_fonte' => 'sem_dado']));
    }

    // ─── Tela ───────────────────────────────────────────────────────────────

    public function test_tela_libera_o_lapis_so_para_quem_pode_lancar(): void
    {
        config(['services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1', 'services.ecf.key' => 'test-key']);
        Cache::flush();
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0], 200)]);
        $this->withoutVite();

        $this->actingAs($this->debora())
            ->get(route('polos.empresas'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polos/Empresas', false)->where('podeLancarFaturamento', true));

        $this->actingAs($this->adminComum())
            ->get(route('polos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polos/Index', false)->where('podeLancarFaturamento', false));
    }
}
