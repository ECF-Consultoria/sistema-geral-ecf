<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingSituacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Os quatro blocos que o detalhe ganhou em 20/08 ao virar cockpit:
 * `proxima_acao`, `responsabilidades`, `linha_do_tempo` e `atividade`.
 *
 * O teste que mais importa é o de PARIDADE da próxima ação: ela precisa ser o
 * MESMO passo que `passoQueTrava()` escolhe para a listagem. No dia em que
 * alguém recalcular "o que trava" só de um lado, a lista vai apontar um passo
 * e o detalhe outro — e essa é exatamente a divergência silenciosa que a
 * extração do `OnboardingSituacaoService` foi feita para impedir.
 */
class CockpitDetalheTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Cockpit '.uniqid(),
            'email'    => 'admin.cock.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    /** @return array{0:Company,1:Onboarding} */
    private function empresaComOnboarding(): array
    {
        $servico = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();

        $company = Company::create([
            'name'              => 'Empresa Cockpit '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => 'colab.'.uniqid().'@ecf.test',
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        return [$company, $onboarding];
    }

    /**
     * Tira do rascunho pelo ENGINE, nunca por `update(['status' => ...])`.
     *
     * Trocar a coluna na mão deixa todos os passos em `bloqueado`: quem os
     * abre é `reavaliar()`, disparado por `confirmarResponsavel()`. Um teste
     * que force a coluna testa um estado que a aplicação nunca produz.
     */
    private function iniciar(Onboarding $onboarding): Onboarding
    {
        app(OnboardingEngineService::class)->confirmarResponsavel(
            $onboarding,
            User::create([
                'name'     => 'Responsavel '.uniqid(),
                'email'    => 'resp.'.uniqid().'@ecf.test',
                'password' => bcrypt('senha'),
                'role'     => 'consultor',
                'active'   => true,
            ]),
        );

        return $onboarding->fresh();
    }

    private function props(Onboarding $onboarding): array
    {
        $response = $this->get(route('onboarding.painel.show', $onboarding->id));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * PARIDADE — a próxima ação do detalhe é o passo que a régua da listagem
     * elege. Sem isto, as duas telas cobram coisas diferentes.
     *
     * @test
     */
    public function proxima_acao_e_o_mesmo_passo_que_trava_na_listagem(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        // Sai do rascunho: em rascunho não corre SLA e a leitura é outra.
        $onboarding = $this->iniciar($onboarding);

        $daRegua = app(OnboardingSituacaoService::class)
            ->passoQueTrava($onboarding->fresh()->passos);

        $this->assertNotNull($daRegua, 'A fixture precisa ter ao menos um passo aberto.');

        $props = $this->props($onboarding);

        $this->assertNotNull($props['proxima_acao']);
        $this->assertSame($daRegua->id, $props['proxima_acao']['id']);
        $this->assertSame($daRegua->titulo, $props['proxima_acao']['titulo']);
        $this->assertSame($daRegua->dono, $props['proxima_acao']['dono']);
    }

    /**
     * Onboarding concluído não tem "próxima ação". Devolver um passo residual
     * ali faria a tela pedir trabalho de quem já terminou.
     *
     * @test
     */
    public function onboarding_concluido_nao_tem_proxima_acao(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        $onboarding = $this->iniciar($onboarding);
        $onboarding->update(['status' => Onboarding::STATUS_CONCLUIDO, 'concluido_em' => now()]);

        $props = $this->props($onboarding);

        $this->assertNull($props['proxima_acao']);
    }

    /**
     * Os três donos são EXCLUSIVOS e somam o total de pendências — é o que
     * permite exibi-los lado a lado sem que os números pareçam errados.
     * `na_reuniao` é subconjunto e por isso NÃO entra na soma.
     *
     * @test
     */
    public function donos_somam_o_total_de_pendencias_e_reuniao_e_subconjunto(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        $onboarding = $this->iniciar($onboarding);

        $abertos = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('status', OnboardingPasso::STATUS_ABERTO)
            ->count();

        $r = $this->props($onboarding)['responsabilidades'];

        $this->assertSame(
            $abertos,
            $r['cliente'] + $r['interno'] + $r['sistema'],
            'Os três donos precisam cobrir todos os passos abertos, sem sobra nem dupla contagem.'
        );

        $naReuniao = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('status', OnboardingPasso::STATUS_ABERTO)
            ->where('natureza', OnboardingPasso::NATUREZA_REUNIAO)
            ->count();

        $this->assertSame($naReuniao, $r['na_reuniao']);
        $this->assertLessThanOrEqual($abertos, $r['na_reuniao'], 'Subconjunto não pode passar do total.');
    }

    /**
     * A linha do tempo tem os TRÊS marcos que o banco registra — nem mais, nem
     * menos. Um quarto marco decorativo ficaria cinza para sempre.
     *
     * @test
     */
    public function linha_do_tempo_tem_os_tres_marcos_reais(): void
    {
        $this->admin();
        [, $onboarding] = $this->empresaComOnboarding();

        $marcos = $this->props($onboarding)['linha_do_tempo'];

        $this->assertCount(3, $marcos);
        $this->assertSame(
            ['chegou', 'iniciado', 'concluido'],
            array_column($marcos, 'chave')
        );

        // Rascunho: chegou já aconteceu, iniciar é o passo atual.
        $this->assertSame('feito', $marcos[0]['estado']);
        $this->assertSame('atual', $marcos[1]['estado']);
        $this->assertNotNull($marcos[0]['data']);
        $this->assertNull($marcos[2]['data']);
    }

    /**
     * O feed distingue o que o SISTEMA fechou do que uma pessoa fechou — é a
     * diferença que muda o quanto se confia na informação.
     *
     * @test
     */
    public function atividade_distingue_conclusao_automatica_de_humana(): void
    {
        $this->admin();
        $user = User::create([
            'name'     => 'Fulano Que Fez',
            'email'    => 'fez.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        [, $onboarding] = $this->empresaComOnboarding();

        $passos = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->orderBy('ordem')->limit(2)->get();

        $passos[0]->update([
            'status'    => OnboardingPasso::STATUS_CONCLUIDO,
            'feito_em'  => now()->subHours(2),
            'feito_por' => $user->id,
            'auto_em'   => null,
        ]);

        $passos[1]->update([
            'status'   => OnboardingPasso::STATUS_CONCLUIDO,
            'feito_em' => now()->subHour(),
            'auto_em'  => now()->subHour(),
        ]);

        $atividade = collect($this->props($onboarding)['atividade']);

        $humano = $atividade->firstWhere('titulo', $passos[0]->titulo);
        $this->assertNotNull($humano, 'O passo fechado à mão sumiu do feed.');
        $this->assertFalse($humano['automatico']);
        $this->assertSame('Fulano Que Fez', $humano['quem']);

        $robo = $atividade->firstWhere('titulo', $passos[1]->titulo);
        $this->assertNotNull($robo, 'O passo fechado pelo resolver sumiu do feed.');
        $this->assertTrue($robo['automatico']);
        $this->assertSame('Sistema', $robo['quem']);

        // Mais recente primeiro: o feed é lido de cima. `$passos[1]` fechou
        // há 1h e `$passos[0]` há 2h, então o índice do primeiro é menor.
        $this->assertLessThan(
            $atividade->search(fn (array $e) => $e['titulo'] === $passos[0]->titulo),
            $atividade->search(fn (array $e) => $e['titulo'] === $passos[1]->titulo),
            'O feed não está em ordem decrescente de data.'
        );
    }
}
