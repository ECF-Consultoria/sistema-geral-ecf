<?php

namespace Tests\Feature\Phase155;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 155 (ONBRD-01..04) — o onboarding passa a mover a etapa da empresa.
 *
 * ⚠️ A régua NÃO muda nesta fase. `DefinicaoOnboarding` segue na VERSAO 17, e há
 * um teste aqui que falha se alguém a reescrever junto — o learning
 * `onboarding-regua-congelada.md` explica por que isso é caro.
 *
 * Toda asserção de etapa por RECONSULTA ao banco.
 */
class OnboardingDirigeEtapaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /** O Servico "Gestão" real — o único com template publicado (D-08 da 135). */
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function empresa(?string $etapa = Company::ETAPA_AGUARDANDO_ONBOARDING): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Onb '.$n,
            'cnpj'   => "19.619.619/{$n}-61",
            'etapa'  => $etapa,
        ]);
    }

    private function vincularResponsavel(Company $c, User $u, string $role, int $servicoId): void
    {
        DB::table('company_users')->insert([
            'company_id' => $c->id, 'user_id' => $u->id, 'role' => $role,
            'servico_id' => $servicoId, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contratoDeGestao(Company $c): ContratoServico
    {
        return ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $c->id]);
    }

    /** Empresa distribuída (analista + estrategista na pivot) com onboarding em rascunho. */
    private function cenarioPronto(?string $etapa = Company::ETAPA_AGUARDANDO_ONBOARDING): array
    {
        $empresa = $this->empresa($etapa);
        $contrato = $this->contratoDeGestao($empresa);
        $servico  = $this->servicoDeGestao();

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $estrat   = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->vincularResponsavel($empresa, $analista, 'analista', $servico->id);
        $this->vincularResponsavel($empresa, $estrat, 'estrategista', $servico->id);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        return compact('empresa', 'onboarding', 'analista', 'estrat');
    }

    private function engine(): OnboardingEngineService
    {
        return app(OnboardingEngineService::class);
    }

    private function contaDeSistema(): User
    {
        $u = User::factory()->create(['name' => 'Sistema 155', 'role' => 'consultor']);
        config(['services.hubspot.webhook_user_id' => $u->id]);

        return $u;
    }

    /** Conclui todos os passos do onboarding, disparando a avaliação de conclusão. */
    private function concluirTodosOsPassos(Onboarding $onboarding, User $por): void
    {
        foreach (OnboardingPasso::where('onboarding_id', $onboarding->id)->get() as $passo) {
            if ($passo->status !== OnboardingPasso::STATUS_CONCLUIDO) {
                $this->engine()->concluirManualmente($passo, $por, true);
            }
        }
    }

    // ─── ONBRD-03 — a régua NÃO foi reescrita ───────────────────────────────

    /**
     * Guarda do escopo. Se este teste falhar, alguém mexeu na definição junto
     * com esta fase — e mudar a régua exige os comandos de realinhamento e uma
     * conversa com o negócio, não um commit de tabela.
     */
    public function test_a_regua_do_onboarding_continua_na_versao_17(): void
    {
        $this->assertSame(17, DefinicaoOnboarding::VERSAO);
    }

    public function test_nenhuma_tabela_nova_de_checklist_nasce_nesta_fase(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('onboarding_checklist_itens'),
            'ONBRD-03: o checklist continua sendo o de onboarding_passos.'
        );
    }

    // ─── ONBRD-01 — iniciar move 6→7 ────────────────────────────────────────

    public function test_iniciar_o_onboarding_move_a_empresa_para_onboarding_em_andamento(): void
    {
        $c = $this->cenarioPronto();
        $coord = User::factory()->create(['role' => 'admin']);

        $this->engine()->definirResponsaveis($c['onboarding'], $c['estrat'], $c['analista'], $coord);

        $this->assertSame(
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::findOrFail($c['empresa']->id)->etapa
        );

        $linha = CompanyEtapaTransicao::where('company_id', $c['empresa']->id)
            ->where('etapa_nova', Company::ETAPA_ONBOARDING_ANDAMENTO)
            ->firstOrFail();

        $this->assertSame($coord->id, $linha->user_id, 'o ator é quem chamou, não a conta de sistema.');
    }

    // ─── ONBRD-02 — concluir move 7→8→9, com as DUAS linhas ─────────────────

    public function test_concluir_move_para_onboarding_concluido_e_em_operacao(): void
    {
        $c = $this->cenarioPronto();
        $coord = User::factory()->create(['role' => 'admin']);
        $sistema = $this->contaDeSistema();

        $this->engine()->definirResponsaveis($c['onboarding'], $c['estrat'], $c['analista'], $coord);
        $this->concluirTodosOsPassos($c['onboarding']->fresh(), $coord);

        $this->assertSame(Company::ETAPA_EM_OPERACAO, Company::findOrFail($c['empresa']->id)->etapa);

        // D-C — as DUAS transições deixam linha própria, mesmo a etapa 8 durando
        // milissegundos. Ela é um marco, não um período.
        foreach ([Company::ETAPA_ONBOARDING_CONCLUIDO, Company::ETAPA_EM_OPERACAO] as $destino) {
            $linha = CompanyEtapaTransicao::where('company_id', $c['empresa']->id)
                ->where('etapa_nova', $destino)
                ->first();

            $this->assertNotNull($linha, "faltou a linha de histórico para {$destino}.");
            $this->assertSame($sistema->id, $linha->user_id, 'sem sessão, o ator é a conta de sistema.');
        }
    }

    // ─── D-A — empresa com N onboardings: sai no ÚLTIMO ─────────────────────

    public function test_com_dois_onboardings_a_etapa_so_avanca_quando_o_ultimo_conclui(): void
    {
        $c = $this->cenarioPronto();
        $coord = User::factory()->create(['role' => 'admin']);
        $this->contaDeSistema();

        // Segundo onboarding da MESMA empresa, criado DIRETO de propósito.
        //
        // Pelo engine não dá: `criarParaContrato()` tem guard de duplicidade por
        // empresa×serviço (D-01 da Fase 135), e hoje só "Gestão" tem template
        // publicado (D-08) — então não existe um segundo serviço que gere
        // onboarding. Criar a linha à mão é o único jeito de exercitar a régua
        // da D-A, que passa a valer no dia em que o segundo template nascer.
        $segundoContrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $c['empresa']->id]);

        $segundo = Onboarding::withoutEvents(fn () => Onboarding::create([
            'company_id'          => $c['empresa']->id,
            'servico_id'          => $this->servicoDeGestao()->id,
            'contrato_servico_id' => $segundoContrato->id,
            'definicao_versao'    => DefinicaoOnboarding::VERSAO,
            'status'              => Onboarding::STATUS_ANDAMENTO,
        ]));

        $this->engine()->definirResponsaveis($c['onboarding'], $c['estrat'], $c['analista'], $coord);
        $this->concluirTodosOsPassos($c['onboarding']->fresh(), $coord);

        // O primeiro acabou, mas o segundo continua pendente.
        $this->assertSame(
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::findOrFail($c['empresa']->id)->etapa,
            'com serviço ainda em andamento, a empresa NÃO pode aparecer como onboarding concluído.'
        );

        // Concluir o SEGUNDO é que dispara a reavaliação da empresa. Não dá para
        // reavaliar pelo primeiro: `avaliarConclusaoDoOnboarding()` sai cedo
        // quando o onboarding já está concluído, então ele nunca reexaminaria a
        // empresa. Por isso o segundo precisa de passos de verdade.
        $this->engine()->montarPassos($segundo);
        $this->concluirTodosOsPassos($segundo->fresh(), $coord);

        $this->assertSame(Company::ETAPA_EM_OPERACAO, Company::findOrFail($c['empresa']->id)->etapa);
    }

    // ─── ONBRD-04 — a trava, na leitura lenient da D-B ──────────────────────

    public function test_empresa_no_fluxo_sem_nenhum_responsavel_nao_inicia(): void
    {
        $empresa = $this->empresa();
        $contrato = $this->contratoDeGestao($empresa);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
        // De propósito: NENHUMA linha em company_users.

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/analista nem estrategista/i');

        $this->engine()->definirResponsaveis(
            $onboarding,
            User::factory()->create(),
            User::factory()->create(),
            User::factory()->create(['role' => 'admin'])
        );
    }

    public function test_empresa_com_apenas_analista_INICIA_leitura_lenient_da_d_b(): void
    {
        $empresa = $this->empresa();
        $contrato = $this->contratoDeGestao($empresa);
        $servico = $this->servicoDeGestao();
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->vincularResponsavel($empresa, $analista, 'analista', $servico->id);
        // Sem estrategista — a D-B manda deixar passar.

        $this->engine()->definirResponsaveis($onboarding, null, $analista, User::factory()->create(['role' => 'admin']));

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->fresh()->status);
        $this->assertSame(Company::ETAPA_ONBOARDING_ANDAMENTO, Company::findOrFail($empresa->id)->etapa);
    }

    /**
     * A trava vale só para empresa DENTRO do fluxo novo. Empresa legada
     * (`etapa` NULL) precede a máquina de estados e nunca passou pela
     * Coordenação — travá-la quebraria onboarding que roda hoje.
     */
    public function test_empresa_legada_sem_etapa_nao_e_travada_nem_carimbada(): void
    {
        $empresa = $this->empresa(null);
        $contrato = $this->contratoDeGestao($empresa);
        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->engine()->definirResponsaveis(
            $onboarding,
            User::factory()->create(),
            User::factory()->create(),
            User::factory()->create(['role' => 'admin'])
        );

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->fresh()->status, 'legada roda igual.');
        $this->assertNull(Company::findOrFail($empresa->id)->etapa, 'legada nunca é carimbada.');
        $this->assertSame(0, CompanyEtapaTransicao::where('company_id', $empresa->id)->count());
    }

    // ─── D-D — sem conta de sistema, a etapa fica para trás (não mente) ─────

    /**
     * Preferir a etapa atrasada a um histórico falso é decisão registrada: a
     * Fase 156 lê exatamente estas linhas para medir SLA.
     */
    public function test_sem_conta_de_sistema_a_transicao_final_e_pulada_mas_o_onboarding_conclui(): void
    {
        $c = $this->cenarioPronto();
        $coord = User::factory()->create(['role' => 'admin']);
        config(['services.hubspot.webhook_user_id' => null]);

        $this->engine()->definirResponsaveis($c['onboarding'], $c['estrat'], $c['analista'], $coord);
        $this->concluirTodosOsPassos($c['onboarding']->fresh(), $coord);

        $this->assertSame(
            Onboarding::STATUS_CONCLUIDO,
            $c['onboarding']->fresh()->status,
            'o onboarding NUNCA falha porque a etapa foi pulada.'
        );

        // A etapa ficou onde estava — sem linha inventada com ator errado.
        $this->assertSame(Company::ETAPA_ONBOARDING_ANDAMENTO, Company::findOrFail($c['empresa']->id)->etapa);
        $this->assertSame(
            0,
            CompanyEtapaTransicao::where('company_id', $c['empresa']->id)
                ->where('etapa_nova', Company::ETAPA_EM_OPERACAO)
                ->count()
        );
    }

    // ─── Nenhuma escrita direta de etapa nasceu no engine ───────────────────

    public function test_o_engine_nao_escreve_etapa_direto(): void
    {
        $fonte = file_get_contents(app_path('Services/Onboarding/OnboardingEngineService.php'));
        $semComentario = preg_replace('/^\s*(\*|\/\/|\/\*).*$/m', '', $fonte);

        $this->assertStringNotContainsString("update(['etapa'", $semComentario);

        // Atribuição, não comparação: `->etapa =` é prefixo de `->etapa ===`, e
        // o guard de empresa legada usa a comparação legitimamente. Mesma
        // armadilha que a Fase 152 registrou no seu 07-SUMMARY.
        $this->assertDoesNotMatchRegularExpression(
            '/->etapa\s*=(?!=)/',
            $semComentario,
            'nenhuma escrita direta de etapa pode nascer no engine — tudo por transicionar().'
        );

        $this->assertStringContainsString('->transicionar(', $semComentario);
    }
}
