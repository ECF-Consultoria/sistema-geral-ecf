<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\Resolvers\ConfirmacaoResolver;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os sete itens de confirmação Sim / Não / Pendente (§17 publicidade e §18
 * ADMAN).
 *
 * O teste que mais importa aqui é o do "Não": o catálogo de status do passo
 * não tem esse valor, e o único candidato aparente (`nao_aplicavel`) CONTA
 * COMO RESOLVIDO na conclusão do onboarding. Se a resposta negativa vazasse
 * para lá, o onboarding se daria por concluído com a publicidade nunca
 * explicada ao cliente — e ninguém perceberia, porque a tela mostraria tudo
 * verde. Por isso o teste não se contenta em conferir o estado devolvido pelo
 * resolver: ele aplica o resultado pelo engine e cobra que o ONBOARDING
 * continue em andamento.
 */
class ConfirmacaoResolverTest extends TestCase
{
    use RefreshDatabase;

    /** Um dos sete itens de §17 — vale por todos, o resolver é o mesmo. */
    private const CHAVE = 'publicidade_processo_explicado';

    private function company(): Company
    {
        return Company::create([
            'name'   => 'Empresa Confirmação '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ]);
    }

    /**
     * Onboarding montado À MÃO, com UM passo só — o de confirmação.
     *
     * Deliberadamente não nasce de um `ContratoServico`: com um passo só, "o
     * onboarding concluiu?" vira uma pergunta sobre ESTE item e nada mais, que
     * é exatamente o que precisa ser provado. Nascendo da definição, os outros
     * passos abertos mascarariam a regressão.
     */
    private function onboardingComPassoDeConfirmacao(?Company $company = null, ?Servico $servico = null): Onboarding
    {
        $company ??= $this->company();
        $servico ??= Servico::where('ativo', true)->firstOrFail();

        $onboarding = Onboarding::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'definicao_versao' => DefinicaoOnboarding::VERSAO,
            'status'           => Onboarding::STATUS_ANDAMENTO,
        ]);

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'etapa'         => OnboardingPasso::ETAPA_PUBLICIDADE,
            'natureza'      => OnboardingPasso::NATUREZA_PERGUNTA,
            'chave'         => self::CHAVE,
            'titulo'        => 'Processo de publicidade explicado',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
            'status'        => OnboardingPasso::STATUS_ABERTO,
        ]);

        return $onboarding;
    }

    private function passoDe(Onboarding $onboarding): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', self::CHAVE)
            ->firstOrFail();
    }

    private function usuario(): User
    {
        return User::create([
            'name'     => 'Analista '.uniqid(),
            'email'    => 'analista.conf.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
    }

    private function resolver(): ConfirmacaoResolver
    {
        return app(ConfirmacaoResolver::class);
    }

    /** @test */
    public function o_resolver_declara_a_chave_do_catalogo_e_e_sincrono(): void
    {
        $this->assertSame(OnboardingPasso::AUTO_FONTE_CONFIRMACAO, $this->resolver()->chave());
        $this->assertContains($this->resolver()->chave(), OnboardingPasso::AUTO_FONTES);
        $this->assertFalse($this->resolver()->assincrono());
    }

    /** @test */
    public function sem_resposta_o_passo_nao_fecha(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        $resultado = $this->resolver()->resolver($onboarding, $this->passoDe($onboarding));

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehConcluido());
        $this->assertNotNull($resultado->motivo);

        // Sem coleta assíncrona envolvida: o passo segue aberto, não vai para
        // `aguardando_coleta`.
        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
    }

    /** @test */
    public function pendente_com_observacao_nao_fecha_mas_leva_o_recado_no_motivo(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_PENDENTE,
            'observacoes'   => 'Cliente pediu para retomar na call da semana que vem.',
        ]);

        $resultado = $this->resolver()->resolver($onboarding, $this->passoDe($onboarding));

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('semana que vem', $resultado->motivo);
    }

    /**
     * "Respondeu que não" e "ninguém respondeu" são dois problemas com donos e
     * prazos diferentes — a tela precisa poder dizer qual dos dois é.
     *
     * @test
     */
    public function resposta_nao_tem_motivo_proprio_diferente_do_de_sem_resposta(): void
    {
        $semResposta = $this->onboardingComPassoDeConfirmacao();
        $motivoSemResposta = $this->resolver()
            ->resolver($semResposta, $this->passoDe($semResposta))
            ->motivo;

        $negado = $this->onboardingComPassoDeConfirmacao();
        OnboardingConfirmacao::create([
            'onboarding_id'  => $negado->id,
            'chave'          => self::CHAVE,
            'resposta'       => OnboardingConfirmacao::RESPOSTA_NAO,
            'respondido_em'  => now(),
            'respondido_por' => $this->usuario()->id,
        ]);

        $resultado = $this->resolver()->resolver($negado, $this->passoDe($negado));

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertNotSame(
            $motivoSemResposta,
            $resultado->motivo,
            'O motivo de "respondeu Não" não pode ser igual ao de "ninguém respondeu".'
        );
    }

    /**
     * A regressão cara: "Não" NÃO pode se comportar como `nao_aplicavel`. Com
     * um passo só no onboarding, se o "Não" vazasse para lá o onboarding
     * inteiro se daria por concluído.
     *
     * @test
     */
    public function resposta_nao_mantem_o_passo_aberto_e_o_onboarding_em_andamento(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_NAO,
            'observacoes'   => 'Ainda não passamos o processo de publicidade para o cliente.',
        ]);

        $passo = $this->passoDe($onboarding);
        $engine = app(OnboardingEngineService::class);

        $engine->aplicarResultado($passo, $this->resolver()->resolver($onboarding, $passo));

        $passo->refresh();

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->status);
        $this->assertNotSame(OnboardingPasso::STATUS_NAO_APLICAVEL, $passo->status);
        $this->assertNotSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);

        $this->assertSame(
            Onboarding::STATUS_ANDAMENTO,
            $onboarding->fresh()->status,
            'Onboarding não pode concluir com uma confirmação respondida "Não".'
        );
    }

    /** @test */
    public function resposta_sim_fecha_o_passo_e_leva_a_observacao(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();
        $usuario = $this->usuario();

        OnboardingConfirmacao::create([
            'onboarding_id'  => $onboarding->id,
            'chave'          => self::CHAVE,
            'resposta'       => OnboardingConfirmacao::RESPOSTA_SIM,
            'observacoes'    => 'Explicado na reunião de kickoff, com o dono presente.',
            'respondido_em'  => now(),
            'respondido_por' => $usuario->id,
        ]);

        $passo = $this->passoDe($onboarding);
        $resultado = $this->resolver()->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame(OnboardingConfirmacao::RESPOSTA_SIM, $resultado->valor['resposta']);
        $this->assertSame(
            'Explicado na reunião de kickoff, com o dono presente.',
            $resultado->valor['observacoes'] ?? null
        );
        $this->assertSame($usuario->id, $resultado->valor['respondido_por'] ?? null);

        app(OnboardingEngineService::class)->aplicarResultado($passo, $resultado);

        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->refresh()->status);
    }

    /** @test */
    public function sim_sem_observacao_nao_inventa_a_chave_no_valor(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_SIM,
        ]);

        $resultado = $this->resolver()->resolver($onboarding, $this->passoDe($onboarding));

        $this->assertTrue($resultado->ehConcluido());
        $this->assertArrayNotHasKey('observacoes', $resultado->valor);
    }

    /**
     * Duas linhas para o mesmo item deixariam o resolver escolhendo em
     * silêncio qual das duas vale — a trava é de banco, não de aplicação.
     *
     * @test
     */
    public function unique_impede_duas_respostas_para_a_mesma_chave_no_mesmo_onboarding(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_NAO,
        ]);

        $this->expectException(QueryException::class);

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_SIM,
        ]);
    }

    /**
     * A resposta é por ONBOARDING, não por empresa: a mesma empresa pode ter
     * Gestão e outro serviço rodando, e ter explicado a publicidade num não
     * significa ter explicado no outro.
     *
     * @test
     */
    public function a_resposta_e_por_onboarding_e_nao_vaza_entre_dois_da_mesma_empresa(): void
    {
        $company = $this->company();

        $servicos = Servico::where('ativo', true)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $servicos, 'O catálogo precisa de dois serviços ativos para este cenário.');

        $primeiro = $this->onboardingComPassoDeConfirmacao($company, $servicos[0]);
        $segundo = $this->onboardingComPassoDeConfirmacao($company, $servicos[1]);

        OnboardingConfirmacao::create([
            'onboarding_id' => $primeiro->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_SIM,
        ]);

        $this->assertTrue(
            $this->resolver()->resolver($primeiro, $this->passoDe($primeiro))->ehConcluido()
        );

        $this->assertTrue(
            $this->resolver()->resolver($segundo, $this->passoDe($segundo))->ehNaoColetado(),
            'A confirmação de um onboarding não pode fechar o passo do outro.'
        );
    }

    /**
     * O resolver só roda de verdade se estiver no registry — `OnboardingReavaliarPassos`
     * e `ResolveOnboardingPassoJob` chegam nele por `factory->for($passo->auto_fonte)`.
     * Sem esta trava, esquecer a linha no `AppServiceProvider` não quebra teste
     * nenhum: quebra em produção, com os dez passos de confirmação presos em
     * `aberto` para sempre e uma `RuntimeException` por passada no log.
     *
     * @test
     */
    public function o_resolver_esta_registrado_no_catalogo_fechado(): void
    {
        $registrado = app(OnboardingResolverFactory::class)
            ->for(OnboardingPasso::AUTO_FONTE_CONFIRMACAO);

        $this->assertInstanceOf(ConfirmacaoResolver::class, $registrado);
    }

    /**
     * A `resposta` é varchar + constante em PHP (nunca enum — learnings §6), então
     * o banco aceita qualquer texto. Um valor fora do catálogo tem de cair no lado
     * SEGURO: passo aberto. Fechar por resposta que o código não reconhece seria
     * dar a confirmação por dada sem ninguém ter confirmado nada.
     *
     * @test
     */
    public function resposta_fora_do_catalogo_nunca_fecha_o_passo(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            // Nem 'sim', nem 'nao', nem 'pendente' — e 'Sim' com maiúscula cai
            // aqui do mesmo jeito, que é o ponto.
            'resposta'      => 'Sim',
        ]);

        $resultado = $this->resolver()->resolver($onboarding, $this->passoDe($onboarding));

        $this->assertTrue(
            $resultado->ehNaoColetado(),
            'Resposta fora do catálogo não pode ser lida como confirmação.'
        );
    }

    /**
     * `onboarding_passos.ultimo_erro` é varchar(255) e o MariaDB trunca em
     * SILÊNCIO (learnings §6) — o SQLite destes testes não trunca nada, então sem
     * esta asserção a regressão só apareceria em produção, com o motivo cortado no
     * meio e a frase base perdida. A observação é do tamanho máximo que o
     * controller aceita (`max:2000`).
     *
     * @test
     */
    public function o_motivo_cabe_em_ultimo_erro_mesmo_com_a_maior_observacao_aceita(): void
    {
        $onboarding = $this->onboardingComPassoDeConfirmacao();

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => self::CHAVE,
            'resposta'      => OnboardingConfirmacao::RESPOSTA_NAO,
            'observacoes'   => str_repeat('detalhe do que ficou pendente, ', 70),
        ]);

        $passo = $this->passoDe($onboarding);
        $resultado = $this->resolver()->resolver($onboarding, $passo);

        $this->assertLessThanOrEqual(
            255,
            mb_strlen($resultado->motivo),
            'Motivo maior que 255 chars é truncado em silêncio pelo MariaDB em onboarding_passos.ultimo_erro.'
        );

        // O corte tem de sacrificar a observação, nunca a frase que diz QUAL é o
        // problema — é ela que a tela usa para separar "respondeu Não" de
        // "ninguém respondeu".
        $this->assertStringStartsWith('Respondido "Não"', $resultado->motivo);

        app(OnboardingEngineService::class)->aplicarResultado($passo, $resultado);

        $this->assertSame($resultado->motivo, $passo->refresh()->ultimo_erro);
    }
}
