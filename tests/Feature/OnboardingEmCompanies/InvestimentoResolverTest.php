<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\OnboardingResolverResultado;
use App\Services\Onboarding\Resolvers\InvestimentoPublicidadeResolver;
use App\Services\Onboarding\Resolvers\InvestimentoResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Quanto o cliente pretende investir?" (§13.1) e os DOIS itens de checklist
 * que dependem da resposta.
 *
 * O que estes testes travam, e que é fácil quebrar sem perceber:
 *  - **`0` fecha o passo, `null` não.** Cliente que declara não ter caixa (ou
 *    não querer verba de anúncio) RESPONDEU a pergunta. Trocar a comparação
 *    com `null` por `empty()`/`filled()` deixa esse cliente com o passo aberto
 *    para sempre — e o teste que pega isso é o do zero, não os de valor
 *    preenchido;
 *  - os dois itens são INDEPENDENTES: informar o investimento previsto não
 *    pode fechar o de publicidade nem o contrário;
 *  - 1:1 com o onboarding — duas linhas para o mesmo onboarding fariam o
 *    resolver ler uma e a tela gravar na outra.
 */
class InvestimentoResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Onboarding mínimo para exercitar os resolvers isoladamente — mesmo molde
     * de `OnboardingResolverMetricasTest::criarOnboardingComPasso()`. Direto
     * pelo model, sem passar pelo `ContratoServicoObserver`: nenhum dos dois
     * resolvers lê a definição dos passos.
     */
    private function criarOnboarding(): Onboarding
    {
        $servico = Servico::create([
            'nome'          => 'Gestao ' . uniqid(),
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        return Onboarding::create([
            'company_id' => Company::factory()->create()->id,
            'servico_id' => $servico->id,
        ]);
    }

    private function passo(Onboarding $onboarding, string $chave, string $autoFonte): OnboardingPasso
    {
        return OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => $chave,
            'titulo'        => 'Item de checklist',
            'dono'          => OnboardingPasso::DONO_CLIENTE,
            'auto_fonte'    => $autoFonte,
        ]);
    }

    private function resolverInvestimento(Onboarding $onboarding): OnboardingResolverResultado
    {
        return (new InvestimentoResolver())->resolver(
            $onboarding,
            $this->passo($onboarding, 'investimento_alinhado', OnboardingPasso::AUTO_FONTE_INVESTIMENTO),
        );
    }

    private function resolverPublicidade(Onboarding $onboarding): OnboardingResolverResultado
    {
        return (new InvestimentoPublicidadeResolver())->resolver(
            $onboarding,
            $this->passo($onboarding, 'investimento_publicidade_alinhado', OnboardingPasso::AUTO_FONTE_INVESTIMENTO_PUBLICIDADE),
        );
    }

    // ─── Contrato do resolver ───────────────────────────────────────────────

    /** @test */
    public function as_chaves_batem_com_as_constantes_do_catalogo_e_nada_aqui_e_assincrono(): void
    {
        $previsto = new InvestimentoResolver();
        $publicidade = new InvestimentoPublicidadeResolver();

        $this->assertSame(OnboardingPasso::AUTO_FONTE_INVESTIMENTO, $previsto->chave());
        $this->assertSame(OnboardingPasso::AUTO_FONTE_INVESTIMENTO_PUBLICIDADE, $publicidade->chave());

        // Leem tabela local: nunca há rede, logo nunca há `indeterminado`.
        $this->assertFalse($previsto->assincrono());
        $this->assertFalse($publicidade->assincrono());
    }

    // ─── Sem resposta nenhuma ───────────────────────────────────────────────

    /** @test */
    public function sem_linha_de_investimento_os_dois_itens_ficam_nao_coletados(): void
    {
        $onboarding = $this->criarOnboarding();

        $previsto = $this->resolverInvestimento($onboarding);
        $publicidade = $this->resolverPublicidade($onboarding);

        $this->assertTrue($previsto->ehNaoColetado());
        $this->assertFalse($previsto->ehIndeterminado());
        $this->assertTrue($publicidade->ehNaoColetado());
        $this->assertFalse($publicidade->ehIndeterminado());
    }

    /** @test */
    public function linha_criada_mas_toda_vazia_ainda_e_nao_coletado(): void
    {
        $onboarding = $this->criarOnboarding();

        // Acontece de verdade: a tela abre o formulário e grava a linha com o
        // canal antes de o cliente digitar qualquer número.
        OnboardingInvestimento::create([
            'onboarding_id'   => $onboarding->id,
            'informado_canal' => OnboardingInvestimento::CANAL_CLIENTE_PORTAL,
        ]);

        $this->assertTrue($this->resolverInvestimento($onboarding)->ehNaoColetado());
        $this->assertTrue($this->resolverPublicidade($onboarding)->ehNaoColetado());
    }

    // ─── ZERO é resposta, não ausência ──────────────────────────────────────

    /** @test */
    public function zero_no_investimento_disponivel_fecha_o_item_de_investimento(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'           => $onboarding->id,
            'investimento_disponivel' => 0,
        ]);

        $resultado = $this->resolverInvestimento($onboarding);

        $this->assertTrue(
            $resultado->ehConcluido(),
            'Zero é resposta DADA: quem declara não ter caixa disponível alinhou o valor.',
        );
        $this->assertSame('0.00', $resultado->valor['investimento_disponivel']);
    }

    /** @test */
    public function zero_no_investimento_de_publicidade_fecha_o_item_de_publicidade(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'            => $onboarding->id,
            'investimento_publicidade' => 0,
        ]);

        $resultado = $this->resolverPublicidade($onboarding);

        $this->assertTrue(
            $resultado->ehConcluido(),
            'Nao investir em publicidade agora e decisao tomada — fecha o item.',
        );
        $this->assertSame('0.00', $resultado->valor['investimento_publicidade']);
    }

    /** @test */
    public function zero_no_mensal_previsto_fecha_mesmo_com_o_disponivel_em_branco(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'                => $onboarding->id,
            'investimento_mensal_previsto' => 0,
        ]);

        $resultado = $this->resolverInvestimento($onboarding);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertNull($resultado->valor['investimento_disponivel']);
        $this->assertSame('0.00', $resultado->valor['investimento_mensal_previsto']);
    }

    // ─── Um dos dois campos basta para o item de investimento ───────────────

    /** @test */
    public function so_o_investimento_mensal_previsto_ja_fecha_o_item(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'                => $onboarding->id,
            'investimento_mensal_previsto' => 4500.50,
            'informado_em'                 => now(),
            'informado_canal'              => OnboardingInvestimento::CANAL_INTERNO_CALL,
        ]);

        $resultado = $this->resolverInvestimento($onboarding);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame('4500.50', $resultado->valor['investimento_mensal_previsto']);
        $this->assertSame(
            OnboardingInvestimento::CANAL_INTERNO_CALL,
            $resultado->valor['informado_canal'],
            'O canal viaja no valor: conferido em call e respondido no portal nao tem a mesma confiabilidade.',
        );
        $this->assertNotNull($resultado->valor['informado_em']);
    }

    /** @test */
    public function so_o_investimento_disponivel_ja_fecha_o_item(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'           => $onboarding->id,
            'investimento_disponivel' => 30000,
        ]);

        $resultado = $this->resolverInvestimento($onboarding);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame('30000.00', $resultado->valor['investimento_disponivel']);
    }

    // ─── Os dois itens são independentes ────────────────────────────────────

    /** @test */
    public function investimento_previsto_preenchido_nao_fecha_o_item_de_publicidade(): void
    {
        $onboarding = $this->criarOnboarding();

        // `investimento_publicidade` fica NULL de propósito: o cliente ainda
        // não decidiu quanto do caixa vai para anúncio.
        OnboardingInvestimento::create([
            'onboarding_id'           => $onboarding->id,
            'investimento_disponivel' => 10000,
        ]);

        $this->assertTrue($this->resolverInvestimento($onboarding)->ehConcluido());
        $this->assertTrue($this->resolverPublicidade($onboarding)->ehNaoColetado());
    }

    /** @test */
    public function publicidade_preenchida_sozinha_nao_fecha_o_item_de_investimento(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'            => $onboarding->id,
            'investimento_publicidade' => 1500,
        ]);

        $this->assertTrue($this->resolverPublicidade($onboarding)->ehConcluido());
        $this->assertTrue($this->resolverInvestimento($onboarding)->ehNaoColetado());
    }

    // ─── 1:1 com o onboarding ───────────────────────────────────────────────

    /** @test */
    public function nao_da_para_ter_duas_linhas_de_investimento_para_o_mesmo_onboarding(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'           => $onboarding->id,
            'investimento_disponivel' => 10000,
        ]);

        $this->expectException(QueryException::class);

        OnboardingInvestimento::create([
            'onboarding_id'           => $onboarding->id,
            'investimento_disponivel' => 20000,
        ]);
    }

    /** @test */
    public function cada_onboarding_tem_a_propria_resposta(): void
    {
        $comResposta = $this->criarOnboarding();
        $semResposta = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'                => $comResposta->id,
            'investimento_mensal_previsto' => 2000,
            'investimento_publicidade'     => 500,
        ]);

        $this->assertTrue($this->resolverInvestimento($comResposta)->ehConcluido());
        $this->assertTrue($this->resolverPublicidade($comResposta)->ehConcluido());

        $this->assertTrue($this->resolverInvestimento($semResposta)->ehNaoColetado());
        $this->assertTrue($this->resolverPublicidade($semResposta)->ehNaoColetado());
    }

    /** @test */
    public function apagar_o_onboarding_leva_o_investimento_junto(): void
    {
        $onboarding = $this->criarOnboarding();

        OnboardingInvestimento::create([
            'onboarding_id'           => $onboarding->id,
            'investimento_disponivel' => 8000,
        ]);

        $onboarding->delete();

        $this->assertDatabaseEmpty('onboarding_investimentos');
    }

    // ─── Ligação com o resto do módulo ──────────────────────────────────────

    /**
     * Instanciar o resolver na mão (como os testes acima fazem) NÃO prova que
     * o engine o encontra: quem chama é o `ResolveOnboardingPassoJob`, e só
     * pela chave, via factory. Resolver escrito e não registrado — ou
     * registrado com chave que não bate com a da definição — deixa o passo
     * aberto para sempre, com toda a suíte verde.
     *
     * @test
     */
    public function o_engine_acha_os_dois_resolvers_pela_chave_do_catalogo_fechado(): void
    {
        $factory = app(OnboardingResolverFactory::class);

        $this->assertInstanceOf(
            InvestimentoResolver::class,
            $factory->for(OnboardingPasso::AUTO_FONTE_INVESTIMENTO),
        );
        $this->assertInstanceOf(
            InvestimentoPublicidadeResolver::class,
            $factory->for(OnboardingPasso::AUTO_FONTE_INVESTIMENTO_PUBLICIDADE),
        );
    }

    /**
     * A chave reservada `coleta_em_andamento` faz o engine parar o passo em
     * `aguardando_coleta` à espera de um job que aqui nunca foi despachado —
     * seria espera eterna. Resolver síncrono não pode sinalizá-la, e precisa
     * devolver o motivo, que é o texto que o passo mostra em `ultimo_erro`.
     *
     * @test
     */
    public function sem_resposta_nao_ha_coleta_em_curso_e_o_motivo_e_explicado(): void
    {
        $onboarding = $this->criarOnboarding();

        foreach ([$this->resolverInvestimento($onboarding), $this->resolverPublicidade($onboarding)] as $resultado) {
            $this->assertFalse($resultado->sinalizouColetaEmAndamento());
            $this->assertNotNull($resultado->motivo);
        }
    }

    /**
     * O seam que a tela usa de verdade: `aplicarResultado()` traduz o
     * resultado de 3 estados em status + valor gravados no passo. Sem
     * resposta o passo fica ABERTO (nunca `aguardando_coleta`, que é o estado
     * de quem espera job), e com resposta fecha guardando o número.
     *
     * @test
     */
    public function o_engine_fecha_o_passo_com_o_numero_e_deixa_aberto_quando_nao_ha_resposta(): void
    {
        $engine = app(OnboardingEngineService::class);

        $semResposta = $this->criarOnboarding();
        $passoAberto = $this->passo($semResposta, 'investimento_alinhado', OnboardingPasso::AUTO_FONTE_INVESTIMENTO);
        $engine->aplicarResultado($passoAberto, (new InvestimentoResolver())->resolver($semResposta, $passoAberto));

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passoAberto->fresh()->status);
        $this->assertNotNull($passoAberto->fresh()->ultimo_erro);

        $comResposta = $this->criarOnboarding();
        OnboardingInvestimento::create([
            'onboarding_id'           => $comResposta->id,
            'investimento_disponivel' => 7500,
        ]);
        $passoFechado = $this->passo($comResposta, 'investimento_alinhado', OnboardingPasso::AUTO_FONTE_INVESTIMENTO);
        $engine->aplicarResultado($passoFechado, (new InvestimentoResolver())->resolver($comResposta, $passoFechado));

        $passoFechado = $passoFechado->fresh();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passoFechado->status);
        $this->assertSame('7500.00', $passoFechado->valor['investimento_disponivel']);
        $this->assertArrayNotHasKey(
            OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO,
            $passoFechado->valor,
        );
    }
}
