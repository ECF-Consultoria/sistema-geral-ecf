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
        $this->assertSame(
            OnboardingPasso::ETAPA_ADMINISTRATIVO,
            $onboarding->passos()->where('chave', 'confirmacao_pagamento')->value('etapa')
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

    // ─── Os 4 acessos são do cliente (v6) ───────────────────────────────────

    #[Test]
    public function os_quatro_acessos_sao_dono_cliente_na_v6(): void
    {
        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());

        $acessos = ['grant_sistema_ecf', 'acesso_colaborador_ml', 'planilha_custos_adman', 'grant_consultoria_adman'];

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
            'A etapa de acessos é exatamente esses 4 passos, nesta ordem'
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
        $this->assertSame(7, DefinicaoOnboarding::VERSAO);

        $onboarding = $this->onboardingEmAndamento(Company::factory()->create());
        $this->assertSame(7, $onboarding->definicao_versao);
    }

    // ─── Instrução: texto, lido do código ───────────────────────────────────

    #[Test]
    public function todo_passo_do_cliente_chega_ao_portal_com_instrucao_preenchida(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $payload = app(OnboardingLinkService::class)->passosDoCliente($company);

        $this->assertNotEmpty($payload);

        foreach ($payload as $item) {
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

    #[Test]
    public function passos_da_adman_oferecem_acao_de_instrucao_nunca_nenhuma(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $payload = collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->keyBy('chave');

        $this->assertSame(OnboardingLinkService::ACAO_INSTRUCAO, $payload['planilha_custos_adman']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_INSTRUCAO, $payload['grant_consultoria_adman']['acao']);

        // Os outros três não regrediram.
        $this->assertSame(OnboardingLinkService::ACAO_OAUTH_ML, $payload['grant_sistema_ecf']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_MARCAR, $payload['acesso_colaborador_ml']['acao']);
        $this->assertSame(OnboardingLinkService::ACAO_MARCAR, $payload['custos_app_ecf']['acao']);
    }

    /**
     * D-19 segue valendo: `instrucao` não é licença para fechar na mão um
     * passo que só o resolver confirma.
     */
    #[Test]
    public function cliente_nao_consegue_marcar_como_feito_um_passo_da_adman(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);
        $link = app(OnboardingLinkService::class)->paraEmpresa($company);

        $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'grant_consultoria_adman'])
            ->assertSessionHasErrors('chave');

        $this->assertNotSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            OnboardingPasso::where('chave', 'grant_consultoria_adman')->value('status')
        );
    }

    // ─── Ordem e explicação do cadeado ──────────────────────────────────────

    #[Test]
    public function payload_do_cliente_vem_ordenado_pela_ordem_do_passo(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $chaves = collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->pluck('chave')
            ->all();

        $this->assertSame(
            ['grant_sistema_ecf', 'acesso_colaborador_ml', 'planilha_custos_adman', 'grant_consultoria_adman', 'custos_app_ecf'],
            $chaves,
            'O cliente precisa receber "autorize o acesso" antes do que depende dele'
        );
    }

    #[Test]
    public function passo_bloqueado_diz_qual_passo_visivel_o_libera(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $payload = collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->keyBy('chave');

        $this->assertSame(
            OnboardingPasso::STATUS_BLOQUEADO,
            $payload['acesso_colaborador_ml']['status'],
            'Pré-condição: este passo depende do grant e nasce bloqueado'
        );
        $this->assertSame(
            'Grant com o Sistema ECF (OAuth)',
            $payload['acesso_colaborador_ml']['depende_de_titulo']
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

        // `custos_app_ecf` é do cliente e não depende de nada; passa a depender
        // de um passo INTERNO para provar que o título dele não escapa.
        $onboarding->passos()
            ->where('chave', 'custos_app_ecf')
            ->update(['depende_de' => json_encode(['confirmacao_pagamento'])]);

        $payload = collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->keyBy('chave');

        $this->assertNull($payload['custos_app_ecf']['depende_de_titulo']);
    }
}
