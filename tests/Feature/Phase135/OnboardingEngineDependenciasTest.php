<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingResolverResultado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 Plano 04 (Task 3) — cobre `reavaliar()`, `aplicarResultado()`,
 * `avaliarCondicao()` e `concluirManualmente()`: as 11 regras do motor
 * (1 a 10b) que destravam, carimbam e pulam passo sozinhos.
 */
class OnboardingEngineDependenciasTest extends TestCase
{
    use RefreshDatabase;

    /** O Servico "Gestão" real, publicado pelas migrations do catálogo. */
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Onboarding de Gestão em `andamento` (14 passos ainda bloqueado). */
    private function criarOnboardingDeGestaoEmAndamento(): Onboarding
    {

        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);
        $onboarding->update(['status' => Onboarding::STATUS_ANDAMENTO, 'iniciado_em' => now()]);

        return $onboarding->fresh();
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->firstOrFail();
    }

    /**
     * Fixture sintética (fora do template de Gestão) para provar a regra
     * genérica "um passo nao_aplicavel não trava quem depende dele" — no
     * template real nada depende de `excluir_anuncios_inativos`, então essa
     * regra só é observável com uma cadeia construída para o teste.
     *
     * @return array{0: Onboarding, 1: string, 2: string, 3: string} onboarding + as 3 chaves (âncora/condicional/dependente)
     */
    private function criarOnboardingSinteticoComCadeiaCondicional(): array
    {
        $servico = Servico::create([
            'nome'          => 'Serviço sintético ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        $company = Company::factory()->create();
        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'status'      => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em' => now(),
        ]);

        // Cadeia montada à mão: `montarPassos()` só sabe montar a definição em
        // código do serviço, e este serviço é sintético de propósito. Como o
        // passo agora CARREGA a definição, criar as linhas direto é suficiente
        // — não existe mais tabela de template para popular antes.
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => 'anuncios_ativos_inativos',
            'titulo'        => 'Âncora (mesma chave que o motor procura na condição)',
            'dono'          => OnboardingPasso::DONO_SISTEMA,
            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
        ]);
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 2,
            'chave'         => 'passo_condicional',
            'titulo'        => 'Condicional',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'depende_de'    => ['anuncios_ativos_inativos'],
            'condicao'      => ['tipo' => OnboardingPasso::CONDICAO_ANUNCIOS_INATIVOS],
            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
        ]);
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 3,
            'chave'         => 'passo_dependente',
            'titulo'        => 'Dependente do condicional',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'depende_de'    => ['passo_condicional'],
            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
        ]);

        return [$onboarding, 'anuncios_ativos_inativos', 'passo_condicional', 'passo_dependente'];
    }

    // ─── Regra 1: rascunho não corre SLA ─────────────────────────────────

    /** @test */
    public function reavaliar_em_rascunho_nao_destrava_nada_e_disponivel_em_fica_nulo(): void
    {
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);
        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);

        (new OnboardingEngineService())->reavaliar($onboarding);

        $passos = OnboardingPasso::where('onboarding_id', $onboarding->id)->get();
        $this->assertCount($this->totalDePassosDaDefinicao(), $passos);
        foreach ($passos as $passo) {
            $this->assertSame(OnboardingPasso::STATUS_BLOQUEADO, $passo->status);
            $this->assertNull($passo->disponivel_em);
        }
    }

    // ─── Regras 2 e 3: destravar por dependência ─────────────────────────

    /** @test */
    public function andamento_destrava_passos_sem_dependencia_com_disponivel_em_os_demais_seguem_bloqueados(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        (new OnboardingEngineService())->reavaliar($onboarding);

        // v10 — saíram daqui `mensagem_boas_vindas` e `confirmacao_pagamento`,
        // os dois passos sem dependência que o negócio removeu da régua.
        //
        // v13 — `agendar_reuniao_onboarding` passou a não depender de nada: o
        // negócio inverteu quem conduz (nós marcamos a data e cobramos o
        // cliente para ela, em vez de esperar o grant).
        //
        // v14 — ele saiu da régua por completo, junto de `gravacao_informada`:
        // o bloco "Reunião de onboarding" da tela já grava data e hora, e um
        // item pedindo para agendar ao lado do formulário que agenda cobrava a
        // mesma coisa duas vezes. `reuniao_realizada` herdou o lugar dele aqui
        // — é ele que agora nasce SEM dependência.
        $semDependencia = [
            'grant_sistema_ecf',
            'planilha_custos_adman',
            'custos_app_ecf',
            'reuniao_realizada',
        ];
        foreach ($semDependencia as $chave) {
            $passo = $this->passo($onboarding, $chave);
            $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->status, "chave={$chave}");
            $this->assertNotNull($passo->disponivel_em, "chave={$chave}");
        }

        // v10 — `excluir_anuncios_inativos` e `grant_de_ads` também saíram.
        $comDependencia = [
            'acesso_colaborador_ml',
            'grant_consultoria_adman',
            'metricas_da_conta',
            'anuncios_ativos_inativos',
        ];
        foreach ($comDependencia as $chave) {
            $passo = $this->passo($onboarding, $chave);
            $this->assertSame(OnboardingPasso::STATUS_BLOQUEADO, $passo->status, "chave={$chave}");
        }
    }

    /**
     * A corrente foi INVERTIDA: o grant com o Sistema ECF é a primeira ação do
     * cliente, e o acesso de colaborador vem depois. Antes era o contrário.
     *
     * @test
     */
    public function concluir_grant_sistema_ecf_destrava_acesso_colaborador_ml_com_disponivel_em(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        // O grant tem auto_fonte — quem o fecha é o resolver, não a mão.
        $engine->aplicarResultado(
            $this->passo($onboarding, 'grant_sistema_ecf'),
            OnboardingResolverResultado::concluido(['ml_token' => 'active']),
        );

        $passoAcesso = $this->passo($onboarding, 'acesso_colaborador_ml');
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passoAcesso->status);
        $this->assertNotNull($passoAcesso->disponivel_em);
    }

    // ─── Regra 4: disponivel_em gravado uma única vez ────────────────────

    /** @test */
    public function reavaliar_duas_vezes_nao_altera_o_disponivel_em_ja_gravado(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        // v10: era `mensagem_boas_vindas`, que saiu da régua. Qualquer passo sem
        // dependência serve — o que se mede é o carimbo não ser reescrito.
        $passo = $this->passo($onboarding, 'grant_sistema_ecf');
        $carimboOriginal = $passo->disponivel_em;
        $this->assertNotNull($carimboOriginal);

        $engine->reavaliar($onboarding);

        $passo->refresh();
        $this->assertTrue($carimboOriginal->equalTo($passo->disponivel_em));
    }

    // ─── Regras 5 e 6: passo condicional ──────────────────────────────────
    //
    // v10: `excluir_anuncios_inativos` era o ÚNICO passo condicional do
    // template de Gestão e saiu da régua. O mecanismo de condição
    // (`CONDICAO_ANUNCIOS_INATIVOS` no model, `avaliarCondicao()` no engine)
    // continua no código, então os três casos seguem cobertos — agora sobre a
    // fixture sintética, que já existia neste arquivo justamente para exercitar
    // a regra genérica sem depender de um passo específico do template.

    /** @test */
    public function passo_condicional_fica_nao_aplicavel_quando_a_ancora_apura_zero_inativos(): void
    {
        [$onboarding, $ancora, $condicional] = $this->criarOnboardingSinteticoComCadeiaCondicional();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $engine->aplicarResultado(
            $this->passo($onboarding, $ancora),
            OnboardingResolverResultado::concluido(['inativos' => 0]),
        );

        $passo = $this->passo($onboarding, $condicional);
        $this->assertSame(OnboardingPasso::STATUS_NAO_APLICAVEL, $passo->status);
        $this->assertNotNull($passo->auto_em);
    }

    /** @test */
    public function passo_condicional_fica_aberto_quando_a_ancora_apura_inativos_maior_que_zero(): void
    {
        [$onboarding, $ancora, $condicional] = $this->criarOnboardingSinteticoComCadeiaCondicional();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $engine->aplicarResultado(
            $this->passo($onboarding, $ancora),
            OnboardingResolverResultado::concluido(['inativos' => 12]),
        );

        $passo = $this->passo($onboarding, $condicional);
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->status);
        $this->assertNotNull($passo->disponivel_em);
    }

    /** @test */
    public function passo_condicional_permanece_bloqueado_enquanto_a_ancora_nao_concluiu(): void
    {
        [$onboarding, $ancora, $condicional] = $this->criarOnboardingSinteticoComCadeiaCondicional();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        // A âncora não tem dependência: já está aberta, mas NÃO foi concluída.
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $this->passo($onboarding, $ancora)->status);

        $passo = $this->passo($onboarding, $condicional);
        $this->assertSame(OnboardingPasso::STATUS_BLOQUEADO, $passo->status);

        // Também via chamada direta (defensiva — regra 6 não decide por omissão).
        $this->assertNull((new OnboardingEngineService())->avaliarCondicao($passo->fresh()));
    }

    /** @test */
    public function passo_nao_aplicavel_nao_impede_que_quem_depende_dele_destrave(): void
    {
        [$onboarding] = $this->criarOnboardingSinteticoComCadeiaCondicional();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $ancora = $this->passo($onboarding, 'anuncios_ativos_inativos');
        $engine->aplicarResultado($ancora, OnboardingResolverResultado::concluido(['inativos' => 0]));

        $condicional = $this->passo($onboarding, 'passo_condicional');
        $this->assertSame(OnboardingPasso::STATUS_NAO_APLICAVEL, $condicional->status);

        $dependente = $this->passo($onboarding, 'passo_dependente');
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $dependente->status);
        $this->assertNotNull($dependente->disponivel_em);
    }

    // ─── aplicarResultado(): indeterminado ────────────────────────────────

    /** @test */
    public function aplicar_resultado_indeterminado_deixa_valor_inalterado_e_incrementa_tentativas(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $passo = $this->passo($onboarding, 'planilha_custos_adman');
        $this->assertNull($passo->valor);
        $this->assertSame(0, $passo->tentativas);

        $engine->aplicarResultado($passo, OnboardingResolverResultado::indeterminado('Adman 429'));

        $passo->refresh();
        $this->assertSame(OnboardingPasso::STATUS_INDETERMINADO, $passo->status);
        $this->assertNull($passo->valor);
        $this->assertSame(1, $passo->tentativas);
        $this->assertSame('Adman 429', $passo->ultimo_erro);
    }

    // ─── aplicarResultado(): nao_coletado (regras 10 e 10b) ───────────────

    /** @test */
    public function aplicar_resultado_nao_coletado_sem_chave_reservada_mantem_aberto(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $passo = $this->passo($onboarding, 'planilha_custos_adman');

        $engine->aplicarResultado($passo, OnboardingResolverResultado::naoColetado('cust_id vazio'));

        $passo->refresh();
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->status);
        $this->assertNull($passo->valor);
        $this->assertSame('cust_id vazio', $passo->ultimo_erro);
    }

    /** @test */
    public function aplicar_resultado_nao_coletado_com_coleta_em_andamento_vira_aguardando_coleta_com_carimbo(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $passo = $this->passo($onboarding, 'planilha_custos_adman');

        $engine->aplicarResultado($passo, OnboardingResolverResultado::naoColetado(
            'sync disparado',
            [OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true]
        ));

        $passo->refresh();
        $this->assertSame(OnboardingPasso::STATUS_AGUARDANDO_COLETA, $passo->status);
        $this->assertNotNull($passo->coleta_iniciada_em);
        $this->assertNull($passo->valor);
    }

    /** @test */
    public function segunda_chamada_com_coleta_em_andamento_preserva_o_carimbo_original(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $passo = $this->passo($onboarding, 'planilha_custos_adman');
        $engine->aplicarResultado($passo, OnboardingResolverResultado::naoColetado(
            'sync disparado',
            [OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true]
        ));

        $passo->refresh();
        $carimboOriginal = $passo->coleta_iniciada_em;
        $this->assertNotNull($carimboOriginal);

        $engine->aplicarResultado($passo, OnboardingResolverResultado::naoColetado(
            'ainda em curso',
            [OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true]
        ));

        $passo->refresh();
        $this->assertSame(OnboardingPasso::STATUS_AGUARDANDO_COLETA, $passo->status);
        $this->assertTrue($carimboOriginal->equalTo($passo->coleta_iniciada_em));
    }

    /** @test */
    public function nenhum_caso_de_nao_coletado_grava_a_chave_reservada_em_valor(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $passo = $this->passo($onboarding, 'planilha_custos_adman');

        $engine->aplicarResultado($passo, OnboardingResolverResultado::naoColetado(
            'sync disparado',
            [OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true]
        ));
        $passo->refresh();
        $this->assertNull($passo->valor);

        $engine->aplicarResultado($passo, OnboardingResolverResultado::naoColetado('sem coleta em curso'));
        $passo->refresh();
        $this->assertNull($passo->valor);
    }

    /** @test */
    public function engine_nunca_discrimina_por_assincrono(): void
    {
        $conteudo = file_get_contents(app_path('Services/Onboarding/OnboardingEngineService.php'));

        $this->assertSame(0, preg_match('/assincrono\(\)/', $conteudo));
    }

    // ─── concluirManualmente(): D-19 ───────────────────────────────────────

    /** @test */
    public function concluir_manualmente_lanca_domain_exception_para_passo_com_auto_fonte(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $passo = $this->passo($onboarding, 'planilha_custos_adman');
        $usuario = User::factory()->create();

        $this->expectException(\DomainException::class);
        $engine->concluirManualmente($passo, $usuario);
    }

    // ─── avaliarCondicao(): catálogo fechado (D-09/D-12) ──────────────────

    /** @test */
    public function avaliar_condicao_com_tipo_desconhecido_lanca_runtime_exception(): void
    {
        [$onboarding] = $this->criarOnboardingSinteticoComCadeiaCondicional();
        $passo = $this->passo($onboarding, 'passo_condicional');
        // A condição mora no próprio passo agora — não há mais linha de
        // template para atualizar por trás dele.
        $passo->update(['condicao' => ['tipo' => 'condicao_inexistente']]);

        $this->expectException(\RuntimeException::class);
        (new OnboardingEngineService())->avaliarCondicao($passo->fresh());
    }

    // ─── Regra 8: conclusão do onboarding ──────────────────────────────────

    /**
     * v10 — eram 12 passos obrigatórios + 1 condicional; a régua agora tem 9 e
     * nenhum condicional. Os passos com `auto_fonte` fecham por
     * `aplicarResultado()`, nunca na mão.
     *
     * @test
     */
    public function concluir_todos_os_passos_da_regua_fecha_o_onboarding(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $usuario = User::factory()->create();

        $engine->aplicarResultado($this->passo($onboarding, 'grant_sistema_ecf'), OnboardingResolverResultado::concluido(['token' => 'ativo']));
        $engine->aplicarResultado($this->passo($onboarding, 'planilha_custos_adman'), OnboardingResolverResultado::concluido(['adman_account_id' => '123']));
        $engine->aplicarResultado($this->passo($onboarding, 'grant_consultoria_adman'), OnboardingResolverResultado::concluido(['grant' => 'ativo']));

        $engine->concluirManualmente($this->passo($onboarding, 'acesso_colaborador_ml'), $usuario);
        $engine->concluirManualmente($this->passo($onboarding, 'custos_app_ecf'), $usuario);

        $engine->aplicarResultado($this->passo($onboarding, 'metricas_da_conta'), OnboardingResolverResultado::concluido(['faturamento' => 1000]));
        $engine->aplicarResultado($this->passo($onboarding, 'anuncios_ativos_inativos'), OnboardingResolverResultado::concluido(['inativos' => 0]));

        // v14: `agendar_reuniao_onboarding` saiu da régua — a reunião fechou de
        // ser o primeiro passo do bloco de agendamento.
        $engine->concluirManualmente($this->passo($onboarding, 'reuniao_realizada'), $usuario);

        // Os itens do fluxo de 19/08 (confirmações, investimento, contatos,
        // agenda) também precisam fechar — este teste é sobre a TRANSIÇÃO do
        // onboarding quando o último passo resolve, não sobre quais passos a
        // régua tem. Fechar por lista fixa quebraria a cada item novo.
        $onboarding->refresh();
        foreach ($onboarding->passos()->where('status', '!=', OnboardingPasso::STATUS_CONCLUIDO)->get() as $restante) {
            $engine->concluirManualmente($restante, $usuario, forcar: true);
        }

        $onboarding->refresh();
        $this->assertSame(Onboarding::STATUS_CONCLUIDO, $onboarding->status);
        $this->assertNotNull($onboarding->concluido_em);
    }

    /**
     * Regra 8 pelo lado negativo: falta UM passo obrigatório e o onboarding não
     * fecha.
     *
     * v10 — este teste provava D-15 ("pagamento trava a conclusão"), regra que
     * morreu junto com `confirmacao_pagamento`. O que ela protegia em geral
     * continua valendo e agora é medido com `reuniao_realizada` em aberto.
     *
     * @test
     */
    public function falta_um_passo_obrigatorio_e_o_onboarding_nao_fecha(): void
    {
        $onboarding = $this->criarOnboardingDeGestaoEmAndamento();
        $engine = new OnboardingEngineService();
        $engine->reavaliar($onboarding);

        $usuario = User::factory()->create();

        $engine->aplicarResultado($this->passo($onboarding, 'grant_sistema_ecf'), OnboardingResolverResultado::concluido(['token' => 'ativo']));
        $engine->aplicarResultado($this->passo($onboarding, 'planilha_custos_adman'), OnboardingResolverResultado::concluido(['adman_account_id' => '123']));
        $engine->aplicarResultado($this->passo($onboarding, 'grant_consultoria_adman'), OnboardingResolverResultado::concluido(['grant' => 'ativo']));

        $engine->concluirManualmente($this->passo($onboarding, 'acesso_colaborador_ml'), $usuario);
        $engine->concluirManualmente($this->passo($onboarding, 'custos_app_ecf'), $usuario);

        $engine->aplicarResultado($this->passo($onboarding, 'metricas_da_conta'), OnboardingResolverResultado::concluido(['faturamento' => 1000]));
        $engine->aplicarResultado($this->passo($onboarding, 'anuncios_ativos_inativos'), OnboardingResolverResultado::concluido(['inativos' => 0]));
        // v14: `agendar_reuniao_onboarding` saiu da régua — a reunião passou a
        // nascer sem dependência nenhuma, aberta desde o início do andamento.
        // `reuniao_realizada` é deliberadamente NUNCA concluída neste teste.
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $this->passo($onboarding, 'reuniao_realizada')->status);

        $onboarding->refresh();
        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertNull($onboarding->concluido_em);
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
