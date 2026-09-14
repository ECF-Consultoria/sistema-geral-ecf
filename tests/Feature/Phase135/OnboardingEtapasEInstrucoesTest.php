<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v6 da definição — os 4 acessos viram do cliente, cada passo ganha `etapa`
 * (bloco) e o portal passa a ter instrução por chave.
 *
 * A divisão que estes testes protegem: `etapa` é ESTRUTURA e por isso é
 * COPIADA para a linha do passo (congela no nascimento); `instrucao` é TEXTO
 * e por isso é lida do código na hora de montar o payload (correção alcança
 * quem já está travado).
 */
class OnboardingEtapasEInstrucoesTest extends TestCase
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

    private function engine(): OnboardingEngineService
    {
        return app(OnboardingEngineService::class);
    }

    private function onboardingEmAndamento(Company $company): Onboarding
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $onboarding = $this->engine()->criarParaContrato($contrato);
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    // ─── Etapa: estrutura, copiada no nascimento ────────────────────────────

    #[Test]
    public function todo_passo_da_definicao_declara_uma_etapa_do_catalogo_fechado(): void
    {
        $passos = DefinicaoOnboarding::paraServico($this->servicoDeGestao());

        $this->assertNotEmpty($passos);

        foreach ($passos as $passo) {
            $this->assertArrayHasKey('etapa', $passo, "Passo {$passo['chave']} sem etapa");
            $this->assertContains(
                $passo['etapa'],
                OnboardingPasso::ETAPAS,
                "Passo {$passo['chave']} com etapa fora do catálogo: {$passo['etapa']}"
            );
        }
    }

    #[Test]
    public function etapa_e_copiada_para_a_linha_do_passo_no_nascimento(): void
    {
        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());

        $semEtapa = $onboarding->passos()->whereNull('etapa')->count();
        $this->assertSame(0, $semEtapa, 'Todo passo nasce com etapa gravada na linha');

        $this->assertSame(
            OnboardingPasso::ETAPA_ACESSOS,
            $onboarding->passos()->where('chave', 'grant_sistema_ecf')->value('etapa')
        );
        $this->assertSame(
            OnboardingPasso::ETAPA_MAPEAMENTO,
            $onboarding->passos()->where('chave', 'metricas_da_conta')->value('etapa')
        );
        $this->assertSame(
            OnboardingPasso::ETAPA_AGENDAMENTO,
            $onboarding->passos()->where('chave', 'reuniao_realizada')->value('etapa')
        );
        // v10: `confirmacao_pagamento` era o exemplo de ETAPA_ADMINISTRATIVO e
        // saiu da régua junto com os outros quatro passos internos. Nenhum
        // passo vigente é administrativo — a etapa continua no catálogo (o
        // portal do cliente ainda a ordena) e volta a ter passo no dia em que
        // o negócio pedir um.
        $this->assertNotContains(
            OnboardingPasso::ETAPA_ADMINISTRATIVO,
            $onboarding->passos()->pluck('etapa')->all(),
        );
    }

    /**
     * O congelamento vale para `etapa` como já valia para `dono`: mudar a
     * receita em código não pode reorganizar a tela de quem já está rodando.
     */
    #[Test]
    public function etapa_gravada_na_linha_nao_muda_quando_a_linha_e_reavaliada(): void
    {
        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());

        $passo = $onboarding->passos()->where('chave', 'custos_app_ecf')->firstOrFail();
        $passo->update(['etapa' => 'administrativo']);

        $this->engine()->reavaliar($onboarding->fresh());

        $this->assertSame(
            'administrativo',
            $onboarding->passos()->where('chave', 'custos_app_ecf')->value('etapa'),
            'reavaliar() não pode reescrever a etapa congelada no nascimento'
        );
    }

    // ─── Os acessos são do cliente (v6) ─────────────────────────────────────

    #[Test]
    public function os_acessos_sao_dono_cliente_na_v6(): void
    {
        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());

        // v16 — `custos_app_ecf` entrou aqui, vindo de `mapeamento`. Era o
        // único passo `dono=cliente` daquela etapa, e no portal ele abria um
        // bloco "Mapeamento da conta" logo acima da ficha automática de mesmo
        // nome. Aqui ele fecha a sequência ao lado de `planilha_custos_adman`,
        // que é a outra metade do mesmo assunto.
        $acessos = [
            'grant_sistema_ecf',
            'acesso_colaborador_ml',
            'planilha_custos_adman',
            'grant_consultoria_adman',
            'custos_app_ecf',
        ];

        foreach ($acessos as $chave) {
            $this->assertSame(
                OnboardingPasso::DONO_CLIENTE,
                $onboarding->passos()->where('chave', $chave)->value('dono'),
                "{$chave} precisa ser dono=cliente na v6"
            );
        }

        $this->assertSame(
            $acessos,
            $onboarding->passos()
                ->where('etapa', OnboardingPasso::ETAPA_ACESSOS)
                ->orderBy('ordem')
                ->pluck('chave')
                ->all(),
            'A etapa de acessos é exatamente esses passos, nesta ordem'
        );
    }

    /**
     * A etapa `mapeamento` não pede mais NADA do cliente.
     *
     * Desde a v16 ela é só o que o sistema apura sozinho (`metricas_da_conta`,
     * `anuncios_ativos_inativos`) mais a ficha do `MapeamentoInicial`. Se um
     * passo `dono=cliente` voltar para cá, o portal volta a mostrar dois
     * blocos chamados "Mapeamento da conta" — um pedindo trabalho do cliente e
     * outro só exibindo a apuração.
     */
    #[Test]
    public function etapa_de_mapeamento_nao_tem_passo_do_cliente(): void
    {
        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());

        $this->assertSame(
            [],
            $onboarding->passos()
                ->where('etapa', OnboardingPasso::ETAPA_MAPEAMENTO)
                ->where('dono', OnboardingPasso::DONO_CLIENTE)
                ->pluck('chave')
                ->all(),
            'A etapa de mapeamento é apuração automática — nenhum passo do cliente'
        );
    }

    /**
     * D-19: mudar `dono` NÃO mexe em `auto_fonte`. O sistema continua
     * detectando sozinho — o que mudou foi quem vê e quem é cobrado.
     */
    #[Test]
    public function mudar_dono_dos_acessos_nao_removeu_o_auto_fonte_deles(): void
    {
        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());

        $this->assertSame(
            OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
            $onboarding->passos()->where('chave', 'planilha_custos_adman')->value('auto_fonte')
        );
        $this->assertSame(
            OnboardingPasso::AUTO_FONTE_ADMAN_GRANT,
            $onboarding->passos()->where('chave', 'grant_consultoria_adman')->value('auto_fonte')
        );
    }

    #[Test]
    public function a_versao_da_definicao_acompanha_a_receita_vigente(): void
    {
        $this->assertSame(18, DefinicaoOnboarding::VERSAO);

        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());
        $this->assertSame(DefinicaoOnboarding::VERSAO, $onboarding->definicao_versao);
    }

    // ─── Instrução: texto, lido do código ───────────────────────────────────

    #[Test]
    public function todo_passo_do_cliente_chega_ao_portal_com_instrucao_preenchida(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $payload = app(OnboardingLinkService::class)->passosDoPortal($company);

        $this->assertNotEmpty($payload);

        // 14/09 — o portão vale para o passo em que o cliente precisa AGIR.
        // Os itens que entraram no portal para serem CONDUZIDOS na reunião
        // (`confirmar`) e os de acompanhamento (`nenhuma`) não pedem instrução
        // de autoatendimento: ninguém está sozinho na tela quando eles são
        // tratados. Afrouxar aqui seria esconder falta de texto; o critério é
        // a ação, e ele continua exigindo texto de todos os que a têm.
        $exigemInstrucao = collect($payload)->reject(
            fn (array $item) => in_array($item['acao'], [
                OnboardingLinkService::ACAO_CONFIRMAR,
                OnboardingLinkService::ACAO_NENHUMA,
            ], true)
        );

        $this->assertNotEmpty($exigemInstrucao, 'nenhum passo acionável chegou — o portão ficaria vazio');

        foreach ($exigemInstrucao as $item) {
            $this->assertNotNull(
                $item['instrucao'],
                "O passo \"{$item['chave']}\" chega ao cliente sem instrução nenhuma"
            );
            $this->assertNotSame('', trim($item['instrucao']));
        }
    }

    #[Test]
    public function instrucao_de_chave_desconhecida_e_null_e_nao_lanca(): void
    {
        $this->assertNull(DefinicaoOnboarding::instrucaoDe('chave_que_nunca_existiu'));
    }

    /**
     * A instrução NÃO é copiada para a linha: ela é resolvida por `chave` na
     * hora de montar o payload. É isso que permite corrigir um texto confuso
     * e alcançar quem já está no meio do onboarding.
     */
    #[Test]
    public function instrucao_nao_vive_em_coluna_do_passo(): void
    {
        $this->assertNotContains(
            'instrucao',
            \Schema::getColumnListing('onboarding_passos'),
            'Instrução é texto e mora no código — congelá-la impediria corrigir quem já está travado'
        );
    }

    // ─── Ação do cliente para os passos da Adman ────────────────────────────

    /**
     * 14/09 — os três da ADMAN SAÍRAM do portal.
     *
     * O teste anterior provava que eles ofereciam ação de `instrucao` em vez de
     * "você não precisa fazer nada". A regra de mapeamento continua existindo e
     * continua correta; o que mudou é que não há mais card deles na frente do
     * cliente. O negócio não sabe dizer do que cada um trata — se é link, se é
     * explicação nossa na reunião — e pediu que ficassem fora até confirmar.
     *
     * A ausência fica coberta aqui para ninguém os devolver ao portal sem essa
     * conversa ter acontecido.
     */
    #[Test]
    public function os_tres_itens_da_adman_ficaram_fora_do_portal(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $chaves = collect(app(OnboardingLinkService::class)->passosDoPortal($company))
            ->pluck('chave')
            ->all();

        $this->assertNotContains('planilha_custos_adman', $chaves);
        $this->assertNotContains('grant_consultoria_adman', $chaves);
        $this->assertNotContains('custos_app_ecf', $chaves);

        // Os que o negócio pediu continuam lá — a remoção foi cirúrgica.
        $this->assertContains('grant_sistema_ecf', $chaves);
        $this->assertContains('acesso_colaborador_ml', $chaves);
    }

    /**
     * D-19 com a linha no lugar certo.
     *
     * A regra original barrava o cliente em QUALQUER passo automático. Só que
     * os passos da Adman são `instrucao`: a ação acontece fora do nosso
     * alcance e, sem cadastro Adman, o sistema NUNCA vai detectar. O cliente
     * lia "detectamos automaticamente" e ficava preso para sempre.
     *
     * A linha correta não é "tem auto_fonte", é "o sistema consegue confirmar
     * isto sozinho de forma confiável". Ele pode DECLARAR o que fez fora
     * daqui — e a declaração fica marcada como declaração.
     */
    #[Test]
    public function cliente_declara_o_passo_da_adman_e_fica_registrado_como_declaracao(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);
        $link = app(OnboardingLinkService::class)->paraEmpresa($company);

        $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'planilha_custos_adman'])
            ->assertSessionHasNoErrors();

        $passo = OnboardingPasso::where('chave', 'planilha_custos_adman')->firstOrFail();

        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
        $this->assertTrue($passo->valor['declarado_pelo_cliente'] ?? false);
        $this->assertTrue($passo->valor['concluido_manualmente'] ?? false);
    }

    /**
     * O que a D-19 protege de verdade continua protegido: o OAuth do Mercado
     * Livre o sistema CONSEGUE confirmar (o token aparece em `ml_tokens`), e
     * por isso o cliente não fecha esse na mão.
     */
    #[Test]
    public function cliente_nao_marca_o_passo_de_oauth_que_o_sistema_confirma(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);
        $link = app(OnboardingLinkService::class)->paraEmpresa($company);

        $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'grant_sistema_ecf'])
            ->assertSessionHasErrors('chave');

        $this->assertNotSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            OnboardingPasso::where('chave', 'grant_sistema_ecf')->value('status')
        );
    }

    /** O caminho de volta no portal: o cliente desfaz o que ele marcou. */
    #[Test]
    public function cliente_desmarca_o_que_marcou_no_portal(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);
        $link = app(OnboardingLinkService::class)->paraEmpresa($company);

        $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'planilha_custos_adman'])
            ->assertSessionHasNoErrors();
        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            OnboardingPasso::where('chave', 'planilha_custos_adman')->value('status')
        );

        $this->patch(route('onboarding.publico.passo.desmarcar', $link->token), ['chave' => 'planilha_custos_adman'])
            ->assertSessionHasNoErrors();

        $passo = OnboardingPasso::where('chave', 'planilha_custos_adman')->firstOrFail();
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->status);
        $this->assertNull($passo->feito_em);
        $this->assertArrayNotHasKey('declarado_pelo_cliente', $passo->valor ?? []);
    }

    /** O que o SISTEMA confirma o cliente não desmarca — o resolver refecharia. */
    #[Test]
    public function cliente_nao_desmarca_passo_confirmado_pelo_sistema(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);
        $link = app(OnboardingLinkService::class)->paraEmpresa($company);

        $this->patch(route('onboarding.publico.passo.desmarcar', $link->token), ['chave' => 'grant_sistema_ecf'])
            ->assertSessionHasErrors('chave');
    }

    // ─── Ordem e explicação do cadeado ──────────────────────────────────────

    #[Test]
    public function payload_do_cliente_vem_ordenado_pela_ordem_do_passo(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $chaves = collect(app(OnboardingLinkService::class)->passosDoPortal($company))
            ->pluck('chave')
            ->all();

        // Ordem = `ordem` do passo, que é a do catálogo. A lista mudou em
        // 14/09 com `CHAVES_NO_PORTAL`: saíram os dois de cadastro de pessoas
        // e entraram o retrato da conta e os "explicados".
        $this->assertSame(
            [
                'grant_sistema_ecf',
                'acesso_colaborador_ml',
                'metricas_da_conta',
                'anuncios_ativos_inativos',
                'publicidade_processo_explicado',
                'publicidade_investimento_explicado',
                'publicidade_responsabilidades_alinhadas',
                'adman_uso_explicado',
                'adman_responsabilidades_alinhadas',
            ],
            $chaves
        );
    }

    /**
     * v17 — nenhum passo do cliente depende mais de outro passo VISÍVEL a ele:
     * os dois grants perderam a dependência do OAuth. O mecanismo continua no
     * código (`passosDoPortal()` traduz `depende_de` em `depende_de_titulo`)
     * e vale para a próxima régua que o use, então o caso é MONTADO aqui em
     * vez de a cobertura ser apagada junto com a dependência.
     */
    #[Test]
    public function passo_bloqueado_diz_qual_passo_visivel_o_libera(): void
    {
        $company = Company::factory()->create();
        $onboarding = $this->onboardingEmAndamento($company);

        // 14/09 — o portal passou a ter lista FECHADA de chaves, então não dá
        // mais para montar o caso com uma chave inventada: ela simplesmente
        // não apareceria. O caso é montado sobre um passo REAL do portal,
        // que é mais fiel de qualquer forma.
        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', 'acesso_colaborador_ml')
            ->update([
                'depende_de' => json_encode(['grant_sistema_ecf']),
                'status'     => OnboardingPasso::STATUS_BLOQUEADO,
            ]);

        $payload = collect(app(OnboardingLinkService::class)->passosDoPortal($company))
            ->keyBy('chave');

        $this->assertSame(
            OnboardingPasso::STATUS_BLOQUEADO,
            $payload['acesso_colaborador_ml']['status']
        );
        $this->assertSame(
            'Grant com o Sistema ECF (OAuth)',
            $payload['acesso_colaborador_ml']['depende_de_titulo'],
            'O cliente precisa saber QUAL item libera o que está cadeado.'
        );
    }

    /**
     * T-135-11-02 — o portal não revela operação interna. Dependência de passo
     * que o cliente não vê cai na frase genérica, nunca no título do passo
     * interno.
     */
    #[Test]
    public function dependencia_de_passo_interno_nao_vaza_titulo_para_o_cliente(): void
    {
        $company = Company::factory()->create();
        $onboarding = $this->onboardingEmAndamento($company);

        // `acesso_colaborador_ml` é do cliente e não depende de nada; passa a
        // depender de um passo INTERNO para provar que o título dele não escapa.
        // (Era `custos_app_ecf`, que saiu do portal em 14/09.)
        $onboarding->passos()
            ->where('chave', 'acesso_colaborador_ml')
            ->update(['depende_de' => json_encode(['confirmacao_pagamento'])]);

        $payload = collect(app(OnboardingLinkService::class)->passosDoPortal($company))
            ->keyBy('chave');

        $this->assertNull($payload['acesso_colaborador_ml']['depende_de_titulo']);
    }
}
