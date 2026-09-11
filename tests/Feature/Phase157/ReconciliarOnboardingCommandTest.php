<?php

namespace Tests\Feature\Phase157;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `fluxo:reconciliar-onboarding` — o acervo que ficou atrás de D-157-B.
 */
class ReconciliarOnboardingCommandTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    /**
     * Reproduz a empresa 428: etapa 6, onboarding correndo há semanas, e a
     * pivot apontando para outra pessoa.
     *
     * @return array{empresa: Company, onboarding: Onboarding, analista: User, estrategista: User, antigo: User, ator: User}
     */
    private function empresaPresa(): array
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Presa '.$n,
            'cnpj'   => "19.191.919/{$n}-91",
            'etapa'  => Company::ETAPA_AGUARDANDO_ONBOARDING,
        ]);

        $servico = Servico::create([
            'nome'          => 'Serviço Presa '.$n,
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Gustavo '.$n]);
        $estrategista = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Luiz '.$n]);
        $antigo       = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Danilo '.$n]);

        foreach ([[$analista->id, 'analista'], [$estrategista->id, 'estrategista']] as [$uid, $role]) {
            DB::table('company_users')->insert([
                'company_id' => $empresa->id, 'user_id' => $uid, 'role' => $role,
                'servico_id' => $servico->id, 'assigned_at' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $onboarding = Onboarding::create([
            'company_id'              => $empresa->id,
            'servico_id'              => $servico->id,
            'status'                  => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em'             => now()->subWeeks(3),
            'responsavel_analista_id' => $antigo->id,
            'responsavel_id'          => $antigo->id,
        ]);

        return [
            'empresa'      => $empresa->fresh(),
            'onboarding'   => $onboarding,
            'analista'     => $analista,
            'estrategista' => $estrategista,
            'antigo'       => $antigo,
            'ator'         => User::factory()->create(['role' => 'admin', 'active' => true]),
        ];
    }

    public function test_sem_apply_nao_grava_nada(): void
    {
        $c = $this->empresaPresa();

        $this->artisan('fluxo:reconciliar-onboarding', ['--company' => $c['empresa']->id])
            ->assertExitCode(0);

        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::findOrFail($c['empresa']->id)->etapa
        );
        $this->assertSame(
            $c['antigo']->id,
            Onboarding::findOrFail($c['onboarding']->id)->responsavel_analista_id
        );
    }

    public function test_apply_sem_por_falha_sem_gravar(): void
    {
        $c = $this->empresaPresa();

        $this->artisan('fluxo:reconciliar-onboarding', [
            '--company' => $c['empresa']->id,
            '--apply'   => true,
        ])->assertExitCode(1);

        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::findOrFail($c['empresa']->id)->etapa,
            'sem ator não há histórico honesto — o comando tem de recusar antes de escrever.'
        );
    }

    public function test_apply_move_a_etapa_e_copia_os_donos_da_distribuicao(): void
    {
        $c = $this->empresaPresa();

        $this->artisan('fluxo:reconciliar-onboarding', [
            '--company' => $c['empresa']->id,
            '--apply'   => true,
            '--por'     => $c['ator']->id,
        ])->assertExitCode(0);

        $this->assertSame(
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::findOrFail($c['empresa']->id)->etapa
        );

        $onboarding = Onboarding::findOrFail($c['onboarding']->id);
        $this->assertSame($c['analista']->id, $onboarding->responsavel_analista_id);
        $this->assertSame($c['estrategista']->id, $onboarding->responsavel_estrategista_id);

        // A autoria da transição é o ator informado, não o dono da empresa.
        $this->assertDatabaseHas('company_etapa_transicoes', [
            'company_id' => $c['empresa']->id,
            'etapa_nova' => Company::ETAPA_ONBOARDING_ANDAMENTO,
            'user_id'    => $c['ator']->id,
        ]);
    }

    public function test_rodar_duas_vezes_nao_faz_nada_na_segunda(): void
    {
        $c = $this->empresaPresa();

        $args = [
            '--company' => $c['empresa']->id,
            '--apply'   => true,
            '--por'     => $c['ator']->id,
        ];

        $this->artisan('fluxo:reconciliar-onboarding', $args)->assertExitCode(0);
        $this->artisan('fluxo:reconciliar-onboarding', $args)->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('company_etapa_transicoes')
                ->where('company_id', $c['empresa']->id)
                ->where('etapa_nova', Company::ETAPA_ONBOARDING_ANDAMENTO)
                ->count(),
            'a segunda passada não pode inventar uma transição nova.'
        );
    }

    /**
     * Etapa 6 sem onboarding correndo é repouso legítimo — a empresa está
     * esperando o onboarding começar, e o comando não tem nada a dizer sobre
     * ela.
     */
    public function test_empresa_em_6_sem_onboarding_correndo_e_deixada_em_paz(): void
    {
        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Repouso legítimo',
            'cnpj'   => '19.191.919/9999-91',
            'etapa'  => Company::ETAPA_AGUARDANDO_ONBOARDING,
        ]);
        $ator = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->artisan('fluxo:reconciliar-onboarding', [
            '--company' => $empresa->id,
            '--apply'   => true,
            '--por'     => $ator->id,
        ])->assertExitCode(0);

        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::findOrFail($empresa->id)->etapa
        );
    }
}
