<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 135 Plano 09 (Task 2) — payload do painel operacional (`index()`/
 * `show()`). SC-11 é uma rejeição explícita do `feitos/total` do Polos: os
 * testes aqui provam "o que trava / há quantos dias / de quem é a bola",
 * nunca uma porcentagem.
 *
 * A tela React chega no Plano 12 — aqui a verificação é de props via
 * `assertInertia`, nunca de render no navegador (mesmo padrão do
 * do versionamento, removido junto com as tabelas de template).
 */
class OnboardingPainelPropsTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function engine(): OnboardingEngineService
    {
        return app(OnboardingEngineService::class);
    }

    /** Onboarding em `andamento`, 14 passos montados e `reavaliar()` já rodado (via `confirmarResponsavel`). */
    private function onboardingEmAndamento(?Company $company = null): Onboarding
    {
        $servico = $this->servicoDeGestao();
        $engine = $this->engine();

        $company ??= Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);
        $onboarding = $engine->criarParaContrato($contrato);

        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', $chave)->firstOrFail();
    }

    /** Concede `core.onboarding` via setor e devolve um user membro dele (mesmo padrão de OnboardingPainelAcoesTest). */
    private function userComPermissaoPainel(): User
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Coordenação Onboarding 135-09 Props',
            'slug'       => 'coordenacao-onboarding-135-09-props',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => 'core.onboarding',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor']);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user->fresh();
    }

    /** Varre recursivamente as CHAVES do payload por "pct"/"percent"/"progresso" (SC-11). */
    private function assertSemChaveDePorcentagem(array $props): void
    {
        $encontrada = $this->primeiraChaveProibida($props);

        $this->assertNull($encontrada, "Chave de porcentagem encontrada no payload: \"{$encontrada}\"");
    }

    private function primeiraChaveProibida(array $valor): ?string
    {
        foreach ($valor as $chave => $item) {
            if (is_string($chave)) {
                foreach (['pct', 'percent', 'progresso'] as $proibida) {
                    if (str_contains(strtolower($chave), $proibida)) {
                        return $chave;
                    }
                }
            }

            if (is_array($item)) {
                $achado = $this->primeiraChaveProibida($item);
                if ($achado !== null) {
                    return $achado;
                }
            }
        }

        return null;
    }

    // ─── index() — agrupamento por empresa ─────────────────────────────────

    #[Test]
    public function lista_agrupa_por_empresa_uma_empresa_com_2_onboardings_aparece_1_vez(): void
    {
        $this->withoutVite();
        $servicoGestao = $this->servicoDeGestao();

        // 2º serviço só pra empresa acumular 2 onboardings (D-01). Ele não tem
        // definição em código — só Gestão tem —, então o 2º onboarding é criado
        // à mão. O que este teste prova é o AGRUPAMENTO do painel, não a
        // criação, e criar direto mantém o foco onde importa.
        $servico2 = Servico::create([
            'nome' => 'Serviço Auxiliar 135-09', 'valor_padrao' => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE,
        ]);

        $company = Company::factory()->create();
        $engine = $this->engine();

        $engine->criarParaContrato(
            ContratoServico::factory()->paraServico($servicoGestao)->create(['company_id' => $company->id])
        );

        $onboarding2 = Onboarding::create([
            'company_id' => $company->id,
            'servico_id' => $servico2->id,
        ]);
        OnboardingPasso::create([
            'onboarding_id' => $onboarding2->id,
            'ordem'         => 1,
            'chave'         => 'passo_unico_auxiliar',
            'titulo'        => 'Passo único',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'sla_dias'      => 5,
        ]);

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->has('empresas', 1)
            ->has('empresas.0.onboardings', 2)
        );
    }

    // ─── show() — rascunho não corre SLA ───────────────────────────────────

    #[Test]
    public function onboarding_em_rascunho_tem_situacao_rascunho_e_todos_os_passos_sem_dias_parado(): void
    {
        $this->withoutVite();
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);
        $onboarding = $this->engine()->criarParaContrato($contrato);

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.show', $onboarding));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Detalhe')
            ->where('onboarding.situacao', 'rascunho')
            ->has('passos', $this->totalDePassosDaDefinicao())
            ->has('passos', fn ($passos) => $passos->each(
                fn ($passo) => $passo->where('dias_parado', null)->etc()
            ))
        );
    }

    /**
     * Data de chegada da empresa ao onboarding, nas DUAS telas.
     *
     * O cenário é o rascunho de propósito: é ali que `iniciado_em` ainda é
     * null, e é justamente a empresa recém-chegada e ainda não confirmada que
     * se quer enxergar. Um `chegou_em` amarrado a `iniciado_em` passaria no
     * onboarding em andamento e devolveria null exatamente no caso que motivou
     * o campo.
     */
    #[Test]
    public function painel_e_detalhe_expoem_a_data_de_chegada_mesmo_em_rascunho(): void
    {
        $this->withoutVite();
        $servico = $this->servicoDeGestao();
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);
        $onboarding = $this->engine()->criarParaContrato($contrato);

        $this->assertNull($onboarding->iniciado_em, 'pré-condição: rascunho não tem iniciado_em');

        $esperado = $onboarding->fresh()->created_at->toISOString();

        $painel = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));
        $painel->assertOk();
        $painel->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->where('empresas.0.onboardings.0.chegou_em', $esperado)
        );

        $detalhe = $this->actingAs($this->admin())->get(route('onboarding.painel.show', $onboarding));
        $detalhe->assertOk();
        $detalhe->assertInertia(fn ($page) => $page
            ->component('Onboarding/Detalhe')
            ->where('onboarding.chegou_em', $esperado)
        );
    }

    // ─── index() — passo_que_trava / vencido ───────────────────────────────

    #[Test]
    public function passo_aberto_vencido_produz_passo_que_trava_e_situacao_vencido(): void
    {
        $this->withoutVite();
        $onboarding = $this->onboardingEmAndamento();

        // 'custos_app_ecf': sla_dias=5, sem depende_de — já nasce aberto pelo
        // reavaliar() de confirmarResponsavel(); força 6 dias parado (> SLA).
        $this->passo($onboarding, 'custos_app_ecf')->update([
            'status'        => OnboardingPasso::STATUS_ABERTO,
            'disponivel_em' => now()->subDays(6),
        ]);

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->where('empresas.0.onboardings.0.passo_que_trava.chave', 'custos_app_ecf')
            ->where('empresas.0.onboardings.0.passo_que_trava.dias_parado', 6)
            ->where('empresas.0.onboardings.0.passo_que_trava.vencido', true)
            ->where('empresas.0.onboardings.0.situacao', 'vencido')
        );
    }

    #[Test]
    public function passo_que_trava_e_o_de_maior_dias_parado_nao_o_de_menor_ordem(): void
    {
        $this->withoutVite();
        $onboarding = $this->onboardingEmAndamento();

        // 'planilha_custos_adman' (ordem 3) fica com MENOS dias parado que
        // 'custos_app_ecf' (ordem 10) — prova que ordem não decide o empate.
        $this->passo($onboarding, 'planilha_custos_adman')->update([
            'status' => OnboardingPasso::STATUS_ABERTO, 'disponivel_em' => now()->subDays(2),
        ]);
        $this->passo($onboarding, 'custos_app_ecf')->update([
            'status' => OnboardingPasso::STATUS_ABERTO, 'disponivel_em' => now()->subDays(4),
        ]);

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->where('empresas.0.onboardings.0.passo_que_trava.chave', 'custos_app_ecf')
            ->where('empresas.0.onboardings.0.passo_que_trava.dias_parado', 4)
        );
    }

    // ─── aguardando_coleta — D-11 ───────────────────────────────────────────

    #[Test]
    public function passo_em_aguardando_coleta_nao_e_passo_que_trava_e_nao_expoe_valor_numerico(): void
    {
        $this->withoutVite();
        $onboarding = $this->onboardingEmAndamento();

        // Valor "sujo" propositalmente gravado na fixture — prova que o
        // filtro do controller é EXPLÍCITO (D-11), não "acontece de estar vazio".
        $this->passo($onboarding, 'anuncios_ativos_inativos')->update([
            'status'             => OnboardingPasso::STATUS_AGUARDANDO_COLETA,
            'coleta_iniciada_em' => now()->subMinutes(45),
            'valor'              => ['ativos' => 3, 'inativos' => 2],
        ]);

        $indexResponse = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->where('empresas.0.onboardings.0.contadores.aguardando_coleta', 1)
            ->where('empresas.0.onboardings.0.passo_que_trava.chave', fn ($chave) => $chave !== 'anuncios_ativos_inativos')
        );

        $showResponse = $this->actingAs($this->admin())->get(route('onboarding.painel.show', $onboarding));
        $showResponse->assertOk();
        // Localiza o passo pela CHAVE, não pela posição: acrescentar um passo à
        // definição não pode quebrar um teste que fala de outro passo.
        $passos = $showResponse->viewData('page')['props']['passos'];
        $indice = collect($passos)->search(fn ($p) => $p['chave'] === 'anuncios_ativos_inativos');
        $this->assertNotFalse($indice, 'Passo anuncios_ativos_inativos não veio no payload.');

        $showResponse->assertInertia(fn ($page) => $page
            ->component('Onboarding/Detalhe')
            ->where("passos.{$indice}.chave", 'anuncios_ativos_inativos')
            ->where("passos.{$indice}.status", OnboardingPasso::STATUS_AGUARDANDO_COLETA)
            ->where("passos.{$indice}.coleta_demorando", true)
            ->missing("passos.{$indice}.valor")
        );
    }

    // ─── D-15 — pronto_para_concluir não se confunde com aguardando_interno ─

    /**
     * v10 tirou `confirmacao_pagamento` da régua, mas a regra D-15 continua
     * viva para os onboardings que nasceram ANTES (cada um carrega a definição
     * do seu nascimento). O passo é criado à mão aqui exatamente por isso: o
     * cenário sob teste é o do onboarding legado, o único em que a situação
     * `pronto_para_concluir` ainda pode aparecer.
     */
    #[Test]
    public function tudo_concluido_menos_confirmacao_pagamento_produz_pronto_para_concluir(): void
    {
        $this->withoutVite();
        $onboarding = $this->onboardingEmAndamento();

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 7,
            'etapa'         => OnboardingPasso::ETAPA_ADMINISTRATIVO,
            'chave'         => 'confirmacao_pagamento',
            'titulo'        => 'Confirmação de pagamento',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'sla_dias'      => 5,
            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
        ]);

        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', '!=', 'confirmacao_pagamento')
            ->update(['status' => OnboardingPasso::STATUS_CONCLUIDO, 'auto_em' => now()]);

        // Vencido de sobra (20 dias > sla_dias=5) — mesmo assim não pode
        // rotular o onboarding como "vencido"/"aguardando_interno" (D-15).
        $this->passo($onboarding, 'confirmacao_pagamento')->update([
            'status' => OnboardingPasso::STATUS_ABERTO, 'disponivel_em' => now()->subDays(20),
        ]);

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->where('empresas.0.onboardings.0.situacao', 'pronto_para_concluir')
        );
    }

    // ─── show() — depende_de/condicao legíveis ─────────────────────────────

    #[Test]
    public function show_traz_depende_de_como_titulos_e_condicao_como_texto_pt_br(): void
    {
        $this->withoutVite();
        $onboarding = $this->onboardingEmAndamento();

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.show', $onboarding));

        $response->assertOk();

        // Por CHAVE, nunca por índice — a definição pode ganhar passos no meio.
        $passos = collect($response->viewData('page')['props']['passos'])->keyBy('chave');

        // v9 — o grant com a Consultoria depende do grant com o SISTEMA, que
        // é a ordem real do processo. Antes dependia da planilha de custos,
        // pondo um passo de cadastro no meio de dois passos de acesso.
        $this->assertSame(
            ['Grant com o Sistema ECF (OAuth)'],
            $passos['grant_consultoria_adman']['depende_de'],
        );
        // v10 — nenhum passo da régua tem `condicao`: o único condicional era
        // `excluir_anuncios_inativos`, que saiu. A TRADUÇÃO segue no controller
        // (onboarding legado ainda carrega o passo), então o que se mede aqui é
        // ela, sobre um passo condicional criado à mão.
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 99,
            'etapa'         => OnboardingPasso::ETAPA_MAPEAMENTO,
            'chave'         => 'passo_condicional_legado',
            'titulo'        => 'Condicional de onboarding legado',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'condicao'      => ['tipo' => OnboardingPasso::CONDICAO_ANUNCIOS_INATIVOS],
            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
        ]);

        $passosComCondicional = collect(
            $this->actingAs($this->admin())
                ->get(route('onboarding.painel.show', $onboarding))
                ->viewData('page')['props']['passos']
        )->keyBy('chave');

        $this->assertSame(
            'Só se aplica quando há anúncios inativos',
            $passosComCondicional['passo_condicional_legado']['condicao'],
        );
    }

    // ─── Escopo por carteira (T-135-09-02) ─────────────────────────────────

    #[Test]
    public function nao_admin_com_permission_so_ve_onboardings_da_propria_carteira(): void
    {
        $this->withoutVite();
        $companyDaCarteira = Company::factory()->create();
        $companyForaDaCarteira = Company::factory()->create();

        $this->onboardingEmAndamento($companyDaCarteira);
        $this->onboardingEmAndamento($companyForaDaCarteira);

        $user = $this->userComPermissaoPainel();
        DB::table('company_users')->insert([
            'company_id' => $companyDaCarteira->id,
            'user_id'    => $user->id,
            'role'       => 'consultor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('onboarding.painel.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->has('empresas', 1)
            ->where('empresas.0.empresa.id', $companyDaCarteira->id)
        );
    }

    // ─── SC-11 — nenhuma porcentagem no payload ────────────────────────────

    #[Test]
    public function nenhuma_chave_de_porcentagem_nas_props_do_index_e_do_show(): void
    {
        $this->withoutVite();
        $onboarding = $this->onboardingEmAndamento();

        $indexResponse = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));
        $indexResponse->assertOk();
        $this->assertSemChaveDePorcentagem($indexResponse->viewData('page')['props']);

        $showResponse = $this->actingAs($this->admin())->get(route('onboarding.painel.show', $onboarding));
        $showResponse->assertOk();
        $this->assertSemChaveDePorcentagem($showResponse->viewData('page')['props']);
    }

    /**
     * Quantos passos a definição de Gestão tem AGORA. Lido da própria fonte —
     * um passo novo não pode quebrar testes que não falam sobre contagem.
     */
    private function totalDePassosDaDefinicao(): int
    {
        return count(\App\Support\Onboarding\DefinicaoOnboarding::paraServico($this->servicoDeGestao()));
    }
}
