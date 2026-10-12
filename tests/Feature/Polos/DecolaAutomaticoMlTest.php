<?php

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PoloDecolaStatus;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TKT-0013 — "Decola: Sim" automático pelo Mercado Livre.
 *
 * O `polos:decola-status` pergunta ao ML, com o token de cada empresa dos polos que conectou
 * a conta, se o Programa Decola está ativo (type NEWBIE_GRNTEE + status ACTIVE) e promove a
 * ficha para 'Sim'.
 *
 * O que estes testes protegem (e não é óbvio no código):
 *   - só promove: programa encerrado, convite não aceito ou o outro programa do mesmo
 *     endpoint (RECOVERY_GRNTEE) não mexem na ficha — 'Sim' entra em "M1 completo";
 *   - sem resposta do ML nada é gravado, nem a leitura;
 *   - token de conta diferente do Cust ID cadastrado nem chega a consultar;
 *   - a planilha não desfaz um 'Sim' que o ML ainda confirma.
 *
 * @group polos
 */
class DecolaAutomaticoMlTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'api.mercadolibre.com/users/reputation/seller_recovery/status';

    /**
     * Empresa POLOS com ficha e conta do ML conectada (token da mesma conta do Cust ID).
     * `$opts`: empresa (attrs), ficha (false = sem ficha), token (false = sem token),
     * conta (ml_user_id do token), status_token.
     */
    private function empresa(string $cust, ?string $decola = 'Não', array $opts = []): MlbEmpresa
    {
        $empresa = MlbEmpresa::create(array_merge([
            'nome' => "Empresa {$cust}", 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => 'M1',
            'cust_id' => $cust, 'polo' => 'Arapongas', 'estagio' => 'Não Listado',
        ], $opts['empresa'] ?? []));

        if (($opts['ficha'] ?? true) !== false) {
            MlbImplementacao::create(['empresa_id' => $empresa->id, 'token' => "tok{$empresa->id}", 'decola' => $decola]);
        }

        if (($opts['token'] ?? true) !== false) {
            MlToken::create([
                'mlb_empresa_id' => $empresa->id,
                'ml_user_id'     => $opts['conta'] ?? $cust,
                'access_token'   => "APP_USR-{$cust}",
                'refresh_token'  => "TG-{$cust}",
                'expires_at'     => now()->addHours(5),
                'status'         => $opts['status_token'] ?? 'active',
            ]);
        }

        return $empresa;
    }

    /** Corpo no formato do exemplo da documentação do ML ("Recuperação de reputação"). */
    private function programa(string $status, string $type = 'NEWBIE_GRNTEE'): array
    {
        return [
            'type'              => $type,
            'status'            => $status,
            'protection_limits' => ['max_issues_allowed' => 5, 'protection_days_limit' => 365],
            'is_renewal'        => false,
        ];
    }

    /** O ML responde por conta: quem decide a resposta é o token do pedido. */
    private function mlResponde(array $porCust): void
    {
        Http::fake([
            self::URL => function ($request) use ($porCust) {
                $cust = str_replace('Bearer APP_USR-', '', $request->header('Authorization')[0] ?? '');
                $r    = $porCust[$cust] ?? 404;

                return is_int($r) ? Http::response(['message' => 'erro'], $r) : Http::response($r);
            },
        ]);
    }

    private function decolaDe(MlbEmpresa $empresa): ?string
    {
        return MlbImplementacao::where('empresa_id', $empresa->id)->value('decola');
    }

    // ─── Promoção ────────────────────────────────────────────────────────────

    public function test_programa_ativo_vira_sim_e_guarda_o_que_o_ml_respondeu(): void
    {
        $empresa = $this->empresa('111', 'Mensagem Enviada');
        $this->mlResponde(['111' => $this->programa('ACTIVE')]);

        $this->artisan('polos:decola-status', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('Sim', $this->decolaDe($empresa));

        $leitura = PoloDecolaStatus::where('mlb_empresa_id', $empresa->id)->first();
        $this->assertSame('NEWBIE_GRNTEE', $leitura->tipo);
        $this->assertSame('ACTIVE', $leitura->status);
        $this->assertTrue($leitura->ativoEFresco());

        // Fica no histórico de quem e por que mudou: aqui não há usuário, foi o ML.
        $log = Activity::where('log_name', 'implementacao')->latest('id')->first();
        $this->assertStringContainsString('Programa Decola marcado como "Sim"', $log->description);
        $this->assertSame('Mensagem Enviada', $log->properties['anterior']);
        $this->assertSame('mercado_livre', $log->properties['origem']);
    }

    public function test_verificar_do_cliente_tambem_e_promovido(): void
    {
        // 'Verificar' = o cliente disse que aderiu e a ECF ia conferir na conta: é o ML conferindo.
        $empresa = $this->empresa('112', 'Verificar');
        $this->mlResponde(['112' => $this->programa('ACTIVE')]);

        $this->artisan('polos:decola-status', ['--apply' => true]);

        $this->assertSame('Sim', $this->decolaDe($empresa));
    }

    public function test_so_promove_nunca_rebaixa_e_nao_confunde_os_programas(): void
    {
        $encerrado  = $this->empresa('201', 'Sim');               // já foi conferido à mão
        $convidado  = $this->empresa('202', 'Mensagem Enviada');  // convite ainda não aceito
        $outroProg  = $this->empresa('203', 'Não');               // Benefício de Reputação, não Decola
        $semProg    = $this->empresa('204', 'Não');

        $this->mlResponde([
            '201' => $this->programa('FINISHED_BY_DATE'),
            '202' => $this->programa('AVAILABLE'),
            '203' => $this->programa('ACTIVE', 'RECOVERY_GRNTEE'),
            '204' => $this->programa('UNAVAILABLE'),
        ]);

        $this->artisan('polos:decola-status', ['--apply' => true]);

        $this->assertSame('Sim', $this->decolaDe($encerrado), 'programa encerrado não rebaixa o Sim');
        $this->assertSame('Mensagem Enviada', $this->decolaDe($convidado));
        $this->assertSame('Não', $this->decolaDe($outroProg), 'RECOVERY_GRNTEE não é o Decola');
        $this->assertSame('Não', $this->decolaDe($semProg));

        // A leitura é guardada mesmo sem promover — e não acende o selo nem blinda nada.
        $this->assertSame(4, PoloDecolaStatus::count());
        $this->assertFalse(PoloDecolaStatus::where('mlb_empresa_id', $encerrado->id)->first()->ativoEFresco());
        $this->assertFalse(PoloDecolaStatus::where('mlb_empresa_id', $outroProg->id)->first()->ativoEFresco());
    }

    public function test_sem_resposta_do_ml_nada_e_gravado(): void
    {
        $erro     = $this->empresa('301', 'Não');
        $semCorpo = $this->empresa('302', 'Não');
        $ok       = $this->empresa('303', 'Não');

        $this->mlResponde([
            '301' => 500,
            '302' => ['mensagem' => 'formato que não conhecemos'],
            '303' => $this->programa('ACTIVE'),
        ]);

        // Uma empresa que falha não derruba as outras.
        $this->artisan('polos:decola-status', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('Não', $this->decolaDe($erro));
        $this->assertSame('Não', $this->decolaDe($semCorpo));
        $this->assertSame('Sim', $this->decolaDe($ok));
        $this->assertSame([$ok->id], PoloDecolaStatus::pluck('mlb_empresa_id')->all());
    }

    public function test_sem_apply_so_mostra_e_nao_grava(): void
    {
        $empresa = $this->empresa('401', 'Não');
        $this->mlResponde(['401' => $this->programa('ACTIVE')]);

        // Cada expectativa casa com uma linha diferente: a da empresa e a do resumo.
        $this->artisan('polos:decola-status')
            ->expectsOutputToContain('viraria Sim')
            ->expectsOutputToContain('(só leitura)')
            ->assertExitCode(0);

        $this->assertSame('Não', $this->decolaDe($empresa));
        $this->assertSame(0, PoloDecolaStatus::count());
    }

    // ─── Quem é consultado ───────────────────────────────────────────────────

    public function test_conta_conectada_diferente_do_cust_nao_e_consultada(): void
    {
        // Cust ID editado depois da autorização: o token é de OUTRA conta. Perguntar com ele
        // marcaria o Decola de um vendedor na ficha de outro.
        $empresa = $this->empresa('501', 'Não', ['conta' => '999']);
        $semCust = $this->empresa('', 'Não', ['conta' => '998']);
        $this->mlResponde(['501' => $this->programa('ACTIVE'), '' => $this->programa('ACTIVE')]);

        $this->artisan('polos:decola-status', ['--apply' => true])
            ->expectsOutputToContain('a conta conectada não é a do Cust ID cadastrado')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('Não', $this->decolaDe($empresa));
        $this->assertSame('Não', $this->decolaDe($semCust));
        $this->assertSame(0, PoloDecolaStatus::count());
    }

    public function test_so_consulta_polos_ativas_com_conta_conectada(): void
    {
        $conectada  = $this->empresa('601', 'Não');
        $semToken   = $this->empresa('602', 'Não', ['token' => false]);
        $revogado   = $this->empresa('603', 'Não', ['status_token' => 'revoked']);
        $arquivada  = $this->empresa('604', 'Não', ['empresa' => ['arquivado_em' => now()]]);
        $incubadora = $this->empresa('605', 'Não', ['empresa' => ['projeto' => 'INCUBADORA', 'tipo' => 'INCUBADORA', 'fase' => 'Incubadora']]);

        $this->mlResponde(collect(['601', '602', '603', '604', '605'])->mapWithKeys(fn ($c) => [$c => $this->programa('ACTIVE')])->all());

        $this->artisan('polos:decola-status', ['--apply' => true]);

        Http::assertSentCount(1);
        $this->assertSame('Sim', $this->decolaDe($conectada));
        foreach ([$semToken, $revogado, $arquivada, $incubadora] as $fora) {
            $this->assertSame('Não', $this->decolaDe($fora), $fora->nome);
        }
    }

    public function test_empresa_sem_ficha_guarda_a_leitura_e_nao_quebra(): void
    {
        $empresa = $this->empresa('701', null, ['ficha' => false]);
        $this->mlResponde(['701' => $this->programa('ACTIVE')]);

        $this->artisan('polos:decola-status', ['--apply' => true])->assertExitCode(0);

        // Sem ficha não há onde gravar 'Sim'; a leitura fica, e a ficha criada depois é
        // promovida na próxima rodada.
        $this->assertSame(0, MlbImplementacao::where('empresa_id', $empresa->id)->count());
        $this->assertTrue(PoloDecolaStatus::where('mlb_empresa_id', $empresa->id)->first()->ativoEFresco());
    }

    // ─── Planilha ────────────────────────────────────────────────────────────

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'polos_') . '.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, ['Cust ID', 'Loja', 'Fase', 'Polo', 'Decola']);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    public function test_planilha_nao_rebaixa_o_sim_que_o_ml_confirma(): void
    {
        $confirmado = $this->empresa('3100000801', 'Sim');
        $manual     = $this->empresa('3100000802', 'Sim');   // 'Sim' marcado à mão, sem leitura do ML
        $velho      = $this->empresa('3100000803', 'Sim');   // o ML parou de responder por esta conta
        $encerrado  = $this->empresa('3100000804', 'Sim');   // o ML diz que o programa acabou

        PoloDecolaStatus::create(['mlb_empresa_id' => $confirmado->id, 'tipo' => 'NEWBIE_GRNTEE', 'status' => 'ACTIVE', 'verificado_em' => now()->subHours(3)]);
        PoloDecolaStatus::create(['mlb_empresa_id' => $velho->id, 'tipo' => 'NEWBIE_GRNTEE', 'status' => 'ACTIVE', 'verificado_em' => now()->subHours(PoloDecolaStatus::FRESCOR_HORAS + 1)]);
        PoloDecolaStatus::create(['mlb_empresa_id' => $encerrado->id, 'tipo' => 'NEWBIE_GRNTEE', 'status' => 'FINISHED_BY_DATE', 'verificado_em' => now()->subHours(3)]);

        $file = $this->csv(collect([$confirmado, $manual, $velho, $encerrado])
            ->map(fn ($e) => [$e->cust_id, $e->nome, 'M1', 'Arapongas', 'Não'])->all());

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])->assertExitCode(0);

        $this->assertSame('Sim', $this->decolaDe($confirmado), 'o ML confirma: a planilha não rebaixa');
        // A blindagem é cirúrgica — fora dela a planilha segue mandando.
        $this->assertSame('Não', $this->decolaDe($manual));
        $this->assertSame('Não', $this->decolaDe($velho));
        $this->assertSame('Não', $this->decolaDe($encerrado));

        @unlink($file);
    }

    // ─── Tela e agendamento ──────────────────────────────────────────────────

    public function test_painel_manda_o_selo_so_com_leitura_ativa_e_fresca(): void
    {
        $ativa   = $this->empresa('901', 'Sim');
        $velha   = $this->empresa('902', 'Sim');
        $semNada = $this->empresa('903', 'Sim', ['token' => false]);

        PoloDecolaStatus::create(['mlb_empresa_id' => $ativa->id, 'tipo' => 'NEWBIE_GRNTEE', 'status' => 'ACTIVE', 'verificado_em' => now()->subHour()]);
        PoloDecolaStatus::create(['mlb_empresa_id' => $velha->id, 'tipo' => 'NEWBIE_GRNTEE', 'status' => 'ACTIVE', 'verificado_em' => now()->subDays(5)]);

        $this->withoutVite();
        $resposta = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('mlb.polos-painel'));
        $resposta->assertOk();
        $linhas = collect($resposta->viewData('page')['props']['empresas'])->keyBy('id');

        $this->assertTrue($linhas[$ativa->id]['decola_ml_ativo']);
        $this->assertFalse($linhas[$velha->id]['decola_ml_ativo']);
        $this->assertFalse($linhas[$semNada->id]['decola_ml_ativo']);
    }

    public function test_roda_todo_dia_as_0830_gravando_depois_da_renovacao_dos_tokens(): void
    {
        $eventos = collect(app(Schedule::class)->events());
        $ev      = $eventos->first(fn ($e) => str_contains((string) $e->command, 'polos:decola-status'));

        $this->assertNotNull($ev);
        $this->assertStringContainsString('--apply', (string) $ev->command);
        $this->assertSame('30 8 * * *', $ev->expression);
        $this->assertSame('America/Sao_Paulo', $ev->timezone);

        // Depois do ml:refresh-tokens (08:00): os tokens já chegam renovados.
        $refresh = $eventos->first(fn ($e) => str_contains((string) $e->command, 'ml:refresh-tokens'));
        $this->assertSame('0 8 * * *', $refresh->expression);
    }
}
