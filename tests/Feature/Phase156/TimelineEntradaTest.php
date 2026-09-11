<?php

namespace Tests\Feature\Phase156;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use App\Services\FluxoEntrada\TimelineEntradaService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 156 (HIST-01/02/03) — a timeline do fluxo de entrada e o tempo por etapa.
 *
 * Esta fase **não grava nada**: tudo o que a timeline mostra já é registrado
 * pelas Fases 150-155. Por isso os cenários aqui percorrem o fluxo de verdade,
 * pelos serviços reais, em vez de inserir linhas à mão — se um evento do §12
 * sumir, o teste acusa a fase que devia tê-lo gravado.
 */
class TimelineEntradaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    private function svc(): TimelineEntradaService
    {
        return app(TimelineEntradaService::class);
    }

    private function etapas(): EtapaTransicaoService
    {
        return app(EtapaTransicaoService::class);
    }

    private function empresa(?string $etapa = null): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Timeline '.$n,
            'cnpj'   => "20.720.720/{$n}-71",
            'etapa'  => $etapa,
        ]);
    }

    private function servico(): Servico
    {
        return Servico::create([
            'nome' => 'Publicação 156 '.(++self::$seq), 'valor_padrao' => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
            'setor' => 'publicacao', 'exige_contrato' => true,
        ]);
    }

    /** @return array{acoes: array<int,string>, eventos: array, empresa: Company} */
    private function fluxoCompleto(): array
    {
        $empresa = $this->empresa();
        $servico = $this->servico();
        $sistema = User::factory()->create(['name' => 'Sistema HubSpot']);
        $admin   = User::factory()->create(['name' => 'Admin Operador', 'role' => 'admin']);
        $coord   = User::factory()->create(['name' => 'Coordenador', 'role' => 'admin']);

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'valor_contratado' => 100, 'data_contratacao' => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento' => 10, 'ativo' => true,
        ]));

        // 1 — nascimento (recebida no fluxo).
        $this->etapas()->transicionar($empresa, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $sistema);

        // 2 e 3 — envelope enviado e assinado.
        ContratoAssinatura::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'status' => ContratoAssinatura::STATUS_ASSINADO,
            'enviado_em' => now()->subDays(3), 'assinado_em' => now()->subDays(2),
        ]);

        // 4 — administrativo concluído.
        foreach ([
            Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
            Company::ETAPA_AGUARDANDO_ASSINATURA,
            Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
            Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ] as $destino) {
            $this->etapas()->transicionar($empresa->fresh(), $destino, $admin);
        }

        // 5 e 6 — analista e estrategista definidos.
        foreach (['analista', 'estrategista'] as $role) {
            DB::table('company_users')->insert([
                'company_id' => $empresa->id,
                'user_id'    => User::factory()->create(['name' => ucfirst($role).' Resp'])->id,
                'role'       => $role, 'servico_id' => $servico->id,
                'assigned_at' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // 7 — enviada para onboarding.
        $this->etapas()->transicionar($empresa->fresh(), Company::ETAPA_AGUARDANDO_ONBOARDING, $coord);

        $eventos = $this->svc()->paraEmpresa($empresa->fresh());

        return [
            'acoes'   => array_column($eventos, 'acao'),
            'eventos' => $eventos,
            'empresa' => $empresa->fresh(),
        ];
    }

    // ─── HIST-02 — os 7 eventos do §12 ──────────────────────────────────────

    public function test_a_timeline_traz_os_sete_eventos_do_paragrafo_12(): void
    {
        $r = $this->fluxoCompleto();
        $acoes = $r['acoes'];

        $this->assertContains('Empresa recebida no fluxo de entrada', $acoes, '§12.1');
        $this->assertContains('Contrato enviado para assinatura', $acoes, '§12.2');
        $this->assertContains('Contrato assinado', $acoes, '§12.3');
        $this->assertContains('Analista definido', $acoes, '§12.5');
        $this->assertContains('Estrategista definido', $acoes, '§12.6');

        // §12.4 e §12.7 são transições nomeadas pelo rótulo da etapa.
        $this->assertContains('Avançou para Administrativo Concluído', $acoes, '§12.4');
        $this->assertContains('Avançou para Aguardando Onboarding', $acoes, '§12.7');
    }

    // ─── HIST-01 — data, hora, ação e usuário ───────────────────────────────

    public function test_cada_evento_tem_instante_acao_e_a_marca_de_autoria(): void
    {
        $r = $this->fluxoCompleto();

        foreach ($r['eventos'] as $e) {
            $this->assertNotEmpty($e['em'], 'todo evento precisa de instante.');
            $this->assertNotEmpty($e['acao'], 'todo evento precisa de ação.');
            $this->assertArrayHasKey('usuario', $e);
            $this->assertIsBool($e['autoria_registrada']);
        }
    }

    public function test_a_timeline_vem_em_ordem_cronologica(): void
    {
        $instantes = array_column($this->fluxoCompleto()['eventos'], 'em');
        $ordenados = $instantes;
        sort($ordenados);

        $this->assertSame($ordenados, $instantes);
    }

    public function test_transicao_mostra_quem_agiu(): void
    {
        $r = $this->fluxoCompleto();

        $onboarding = collect($r['eventos'])->firstWhere('acao', 'Avançou para Aguardando Onboarding');

        $this->assertTrue($onboarding['autoria_registrada']);
        $this->assertSame('Coordenador', $onboarding['usuario']);
    }

    // ─── D-C — autoria que a origem não guarda fica EXPLÍCITA ───────────────

    /**
     * `company_users` não tem coluna de "quem atribuiu". Inventar o coordenador
     * mais próximo seria histórico falso — e existem 287 vínculos legados que
     * não nasceram de distribuição nenhuma.
     */
    public function test_analista_definido_declara_que_a_autoria_nao_foi_registrada(): void
    {
        $r = $this->fluxoCompleto();

        $evento = collect($r['eventos'])->firstWhere('acao', 'Analista definido');

        $this->assertNotNull($evento);
        $this->assertFalse($evento['autoria_registrada'], 'a origem não guarda quem atribuiu — a tela precisa dizer isso.');
        $this->assertNull($evento['usuario']);
        // Mas o RESPONSÁVEL escolhido aparece no detalhe.
        $this->assertStringContainsString('Analista Resp', $evento['detalhe']);
    }

    // ─── D-B — a segunda fonte do "contrato assinado" (D-16 da Fase 152) ────

    /**
     * Empresa liberada manualmente não tem `assinado_em`. Ler só o envelope a
     * faria aparecer sem o evento 3, e o SLA a mostraria presa numa etapa que
     * ela já venceu.
     */
    public function test_liberacao_manual_aparece_mesmo_sem_envelope_assinado(): void
    {
        $empresa = $this->empresa();
        $servico = $this->servico();
        $quemLiberou = User::factory()->create(['name' => 'Quem Liberou', 'role' => 'admin']);

        ContratoLiberacao::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'via' => ContratoLiberacao::VIA_MANUAL,
            'liberado_por_user_id' => $quemLiberou->id,
            'motivo' => 'webhook nao chegou nesta empresa',
            'motivo_slug' => 'webhook_nao_chegou',
            'liberado_em' => now(),
        ]);

        $eventos = $this->svc()->paraEmpresa($empresa->fresh());
        $evento = collect($eventos)->firstWhere('acao', 'Contrato liberado manualmente');

        $this->assertNotNull($evento, 'a D-16 da Fase 152 é a segunda fonte do "contrato assinado".');
        $this->assertTrue($evento['autoria_registrada']);
        $this->assertSame('Quem Liberou', $evento['usuario']);
    }

    // ─── HIST-03 — duração por etapa ────────────────────────────────────────

    public function test_duracao_por_etapa_cobre_todas_as_transicoes_e_deixa_a_ultima_em_aberto(): void
    {
        $r = $this->fluxoCompleto();

        $duracoes = $this->svc()->duracaoPorEtapa($r['empresa']);

        $this->assertNotEmpty($duracoes);

        // A última etapa vivida está em aberto; todas as anteriores, fechadas.
        $ultima = end($duracoes);
        $this->assertTrue($ultima['em_aberto']);
        $this->assertNull($ultima['saiu_em']);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ONBOARDING, $ultima['etapa']);
        $this->assertSame('Aguardando Onboarding', $ultima['etapa_label']);

        foreach (array_slice($duracoes, 0, -1) as $d) {
            $this->assertFalse($d['em_aberto']);
            $this->assertNotNull($d['saiu_em']);
        }
    }

    public function test_duracao_mede_o_intervalo_ate_a_transicao_seguinte(): void
    {
        $empresa = $this->empresa();
        $ator = User::factory()->create(['role' => 'admin']);

        $this->etapas()->transicionar($empresa, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $ator);
        // Envelhece a primeira transição em 5 horas.
        DB::table('company_etapa_transicoes')
            ->where('company_id', $empresa->id)
            ->update(['created_at' => now()->subHours(5)]);

        $this->etapas()->transicionar($empresa->fresh(), Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $ator);

        $duracoes = $this->svc()->duracaoPorEtapa($empresa->fresh());

        $this->assertEqualsWithDelta(5.0, $duracoes[0]['horas'], 0.1, 'a 1ª etapa durou ~5h até a transição seguinte.');
    }

    /**
     * Empresa legada nunca teve transição — devolver vazio é honesto. O
     * backfill da Fase 150 gravou a etapa SEM histórico exatamente para não
     * fabricar duração fictícia.
     */
    public function test_empresa_legada_sem_transicao_nao_inventa_duracao(): void
    {
        $empresa = $this->empresa(null);

        $this->assertSame([], $this->svc()->duracaoPorEtapa($empresa));
        $this->assertSame([], $this->svc()->paraEmpresa($empresa));
    }

    // ─── D-E — chega no payload da ficha, para os dois perfis ───────────────

    private function userComPermissaoViaSetor(string $key): User
    {
        // `setores.nome` também é UNIQUE, não só o slug — dois setores com o
        // mesmo nome quebram numa falha que nada tem a ver com o que se mede.
        $setor = Setor::firstOrCreate(
            ['slug' => 'timeline-156-'.$key],
            ['nome' => 'Timeline 156 '.$key, 'active' => true]
        );
        SetorPermissao::firstOrCreate(['setor_id' => $setor->id, 'permission_key' => $key]);
        $u = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($u->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $u;
    }

    public function test_a_timeline_chega_no_payload_da_ficha_para_os_dois_perfis(): void
    {
        $r = $this->fluxoCompleto();

        foreach ([Permissions::ADMIN_CONTRATOS, Permissions::COMERCIAL_ENTRADA] as $chave) {
            $props = $this->actingAs($this->userComPermissaoViaSetor($chave))
                ->get(route('admin.contratos.show', $r['empresa']))
                ->assertOk()
                ->viewData('page')['props'];

            $this->assertArrayHasKey('timeline', $props, "perfil {$chave} precisa receber a timeline.");
            $this->assertArrayHasKey('duracao_por_etapa', $props);
            $this->assertNotEmpty($props['timeline']);
        }
    }

    // ─── D-A — o activity_log fica de fora ──────────────────────────────────

    /**
     * Guarda da decisão: o log é podado em 365 dias, e usá-lo faria a timeline
     * de uma empresa antiga mudar sozinha. Se alguém o acrescentar, este teste
     * cai e a conversa acontece antes do merge, não depois da poda.
     */
    public function test_o_service_nao_le_o_activity_log(): void
    {
        $fonte = file_get_contents(app_path('Services/FluxoEntrada/TimelineEntradaService.php'));
        $semComentario = preg_replace('/^\s*(\*|\/\/|\/\*).*$/m', '', $fonte);

        $this->assertStringNotContainsString('Activity::', $semComentario);
        $this->assertStringNotContainsString("table('activity_log", $semComentario);
        $this->assertStringNotContainsString('activities()', $semComentario);
    }
}
