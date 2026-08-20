<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `onboarding:remover-passos-fora-da-regua` — limpa dos onboardings EXISTENTES
 * os 5 passos que saíram da régua na v10.
 *
 * Por que um comando e não uma migration: a definição é copiada no nascimento
 * de propósito (o processo não muda debaixo de quem já está rodando), então
 * tirar um passo do código não o tira de ninguém. A remoção do dado é uma
 * operação separada, destrutiva, e por isso dry-run por padrão.
 *
 * O caso que estes testes protegem acima de todos: a DEPENDÊNCIA ÓRFÃ. Apagar
 * `confirmacao_pagamento` sem limpar o `depende_de` de `reuniao_realizada`
 * deixaria a reunião bloqueada para sempre, esperando um passo que não existe
 * mais no banco.
 */
class OnboardingRemoverPassosForaDaReguaTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'onboarding:remover-passos-fora-da-regua';

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /**
     * Onboarding como um pré-v10: a régua vigente + os passos removidos
     * recriados à mão, incluindo a dependência que a reunião tinha para dois
     * deles.
     */
    private function onboardingLegado(?Company $company = null): Onboarding
    {
        $company  = $company ?? Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $engine     = app(OnboardingEngineService::class);
        $onboarding = $engine->criarParaContrato($contrato);
        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        foreach ([
            ['chave' => 'mensagem_boas_vindas',      'ordem' => 2,  'etapa' => OnboardingPasso::ETAPA_ADMINISTRATIVO],
            ['chave' => 'confirmacao_pagamento',     'ordem' => 7,  'etapa' => OnboardingPasso::ETAPA_ADMINISTRATIVO],
            ['chave' => 'excluir_anuncios_inativos', 'ordem' => 10, 'etapa' => OnboardingPasso::ETAPA_MAPEAMENTO],
            ['chave' => 'grant_de_ads',              'ordem' => 12, 'etapa' => OnboardingPasso::ETAPA_MAPEAMENTO],
            ['chave' => 'relatorio_inicial',         'ordem' => 14, 'etapa' => OnboardingPasso::ETAPA_AGENDAMENTO],
        ] as $dados) {
            OnboardingPasso::create([
                'onboarding_id' => $onboarding->id,
                'ordem'         => $dados['ordem'],
                'etapa'         => $dados['etapa'],
                'chave'         => $dados['chave'],
                'titulo'        => 'Passo legado ' . $dados['chave'],
                'dono'          => OnboardingPasso::DONO_INTERNO,
                'sla_dias'      => 5,
                'status'        => OnboardingPasso::STATUS_ABERTO,
            ]);
        }

        // Como era antes da v10: a reunião dependia dos dois passos que saíram.
        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', 'reuniao_realizada')
            ->update(['depende_de' => json_encode(
                ['agendar_reuniao_onboarding', 'confirmacao_pagamento', 'relatorio_inicial']
            )]);

        return $onboarding->fresh();
    }

    private function passo(Onboarding $onboarding, string $chave): ?OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->first();
    }

    // ─── Dry-run ────────────────────────────────────────────────────────────

    #[Test]
    public function sem_apply_nao_apaga_nada(): void
    {
        $onboarding = $this->onboardingLegado();
        $antes = OnboardingPasso::where('onboarding_id', $onboarding->id)->count();

        $this->artisan(self::COMANDO)
            ->expectsOutputToContain('MODO DRY-RUN')
            ->assertExitCode(0);

        $this->assertSame($antes, OnboardingPasso::where('onboarding_id', $onboarding->id)->count());
        $this->assertNotNull($this->passo($onboarding, 'confirmacao_pagamento'));
    }

    /** O dry-run tem de ANUNCIAR a dependência órfã — é o risco da operação. */
    #[Test]
    public function dry_run_avisa_das_dependencias_que_seriam_limpas(): void
    {
        $this->onboardingLegado();

        $this->artisan(self::COMANDO)
            ->expectsOutputToContain('DEPENDEM de uma chave que vai sair')
            ->assertExitCode(0);
    }

    // ─── Apply ──────────────────────────────────────────────────────────────

    #[Test]
    public function com_apply_apaga_os_cinco_passos_fora_da_regua(): void
    {
        $onboarding = $this->onboardingLegado();

        $this->artisan(self::COMANDO, ['--apply' => true])->assertExitCode(0);

        foreach ([
            'mensagem_boas_vindas', 'confirmacao_pagamento',
            'excluir_anuncios_inativos', 'grant_de_ads', 'relatorio_inicial',
        ] as $chave) {
            $this->assertNull($this->passo($onboarding, $chave), "\"{$chave}\" continua no banco");
        }
    }

    /**
     * O coração do comando: sem esta limpeza a reunião ficaria esperando para
     * sempre um passo apagado.
     */
    #[Test]
    public function apply_limpa_a_dependencia_orfa_da_reuniao(): void
    {
        $onboarding = $this->onboardingLegado();

        $this->artisan(self::COMANDO, ['--apply' => true])->assertExitCode(0);

        $reuniao = $this->passo($onboarding, 'reuniao_realizada');

        // v14: `agendar_reuniao_onboarding` também saiu da régua (o bloco
        // "Reunião de onboarding" da tela já grava data e hora), então a
        // reunião fica SEM dependência nenhuma — e é isso que a destrava.
        // Antes da v14 este teste esperava que ela sobrasse aqui.
        $this->assertEmpty(
            $reuniao->depende_de ?? [],
            'A reunião ficou esperando um passo que não existe mais.'
        );
    }

    /**
     * Passo que fica e NÃO dependia de nada removido não pode ter o
     * `depende_de` mexido — a limpeza é cirúrgica, não um reset.
     */
    #[Test]
    public function apply_nao_mexe_em_dependencia_que_nada_tem_a_ver(): void
    {
        $onboarding = $this->onboardingLegado();
        $antes = $this->passo($onboarding, 'acesso_colaborador_ml')->depende_de;

        $this->artisan(self::COMANDO, ['--apply' => true])->assertExitCode(0);

        $this->assertSame($antes, $this->passo($onboarding, 'acesso_colaborador_ml')->depende_de);
    }

    #[Test]
    public function apply_nao_toca_nos_passos_da_regua_vigente(): void
    {
        $onboarding = $this->onboardingLegado();

        $this->artisan(self::COMANDO, ['--apply' => true])->assertExitCode(0);

        // Todos os passos da régua vigente continuam lá. A contagem sai da
        // própria definição, e não de um número escrito à mão: este teste é
        // sobre o comando NÃO remover o que está na régua, não sobre quantos
        // itens a régua tem hoje.
        $daRegua = count(DefinicaoOnboarding::paraServico($onboarding->servico));
        $this->assertSame($daRegua, OnboardingPasso::where('onboarding_id', $onboarding->id)->count());
        $this->assertNotNull($this->passo($onboarding, 'grant_sistema_ecf'));
        $this->assertNotNull($this->passo($onboarding, 'reuniao_realizada'));
    }

    // ─── Preservar histórico ────────────────────────────────────────────────

    /**
     * Apagar passo concluído leva `concluido_por`/`concluido_em` com ele. A flag
     * existe para quem preferir manter o registro de quem fez o quê.
     */
    #[Test]
    public function manter_concluidos_preserva_o_passo_ja_fechado(): void
    {
        $onboarding = $this->onboardingLegado();
        $usuario = User::factory()->create();

        $this->passo($onboarding, 'confirmacao_pagamento')->update([
            'status'    => OnboardingPasso::STATUS_CONCLUIDO,
            'feito_por' => $usuario->id,
            'feito_em'  => now(),
        ]);

        $this->artisan(self::COMANDO, ['--apply' => true, '--manter-concluidos' => true])
            ->assertExitCode(0);

        $preservado = $this->passo($onboarding, 'confirmacao_pagamento');
        $this->assertNotNull($preservado, 'O passo concluído foi apagado apesar da flag');
        $this->assertSame($usuario->id, $preservado->feito_por);
        $this->assertNotNull($preservado->feito_em);

        // Os não-concluídos saíram normalmente.
        $this->assertNull($this->passo($onboarding, 'grant_de_ads'));
    }

    // ─── Recortes e guardas ─────────────────────────────────────────────────

    #[Test]
    public function company_restringe_a_limpeza_a_uma_empresa(): void
    {
        $alvo  = $this->onboardingLegado();
        $outro = $this->onboardingLegado();

        $this->artisan(self::COMANDO, [
            '--apply'   => true,
            '--company' => $alvo->company_id,
        ])->assertExitCode(0);

        $this->assertNull($this->passo($alvo, 'grant_de_ads'));
        $this->assertNotNull($this->passo($outro, 'grant_de_ads'), 'A outra empresa não devia ter sido tocada');
    }

    #[Test]
    public function chave_restringe_a_limpeza_a_um_passo(): void
    {
        $onboarding = $this->onboardingLegado();

        $this->artisan(self::COMANDO, ['--apply' => true, '--chave' => ['grant_de_ads']])
            ->assertExitCode(0);

        $this->assertNull($this->passo($onboarding, 'grant_de_ads'));
        $this->assertNotNull($this->passo($onboarding, 'confirmacao_pagamento'));
    }

    /**
     * Guarda contra o pior erro possível aqui: apagar em massa uma chave que
     * não estava na lista de removidas.
     */
    #[Test]
    public function chave_desconhecida_falha_sem_apagar_nada(): void
    {
        $onboarding = $this->onboardingLegado();
        $antes = OnboardingPasso::where('onboarding_id', $onboarding->id)->count();

        $this->artisan(self::COMANDO, ['--apply' => true, '--chave' => ['grant_sistema_ecf']])
            ->assertExitCode(1);

        $this->assertSame($antes, OnboardingPasso::where('onboarding_id', $onboarding->id)->count());
        $this->assertNotNull($this->passo($onboarding, 'grant_sistema_ecf'));
    }

    #[Test]
    public function sem_passo_fora_da_regua_o_comando_nao_faz_nada(): void
    {
        // Onboarding nascido já na v10 — nenhum dos 5 passos existe.
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => Company::factory()->create()->id]);
        app(OnboardingEngineService::class)->criarParaContrato($contrato);

        $this->artisan(self::COMANDO, ['--apply' => true])
            ->expectsOutputToContain('nada a fazer')
            ->assertExitCode(0);
    }
}
