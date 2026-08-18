<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grupo de WhatsApp e Boas-vindas — os DOIS passos saíram do onboarding.
 *
 * `grupo_criado` saiu na v7 e `mensagem_boas_vindas` na v10, ambos por decisão
 * do negócio: não fazem parte do onboarding. O arquivo mudou de propósito —
 * antes protegia o comportamento dos passos, agora protege a REMOÇÃO deles.
 *
 * Por que continua existindo: os dois nasceram de um pedido explícito (PDF §7)
 * e foram retirados depois. Sem este teste, a próxima leitura daquele documento
 * os reintroduz sem ninguém perceber que já foram removidos duas vezes.
 */
class OnboardingGrupoBoasVindasTest extends TestCase
{
    use RefreshDatabase;

    /** As duas chaves que não devem voltar à régua. */
    private const CHAVES_REMOVIDAS = ['grupo_criado', 'mensagem_boas_vindas'];

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function onboardingEmAndamento(): Onboarding
    {
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $engine = app(OnboardingEngineService::class);
        $onboarding = $engine->criarParaContrato($contrato);
        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    #[Test]
    public function os_dois_passos_nao_existem_mais_na_definicao(): void
    {
        $chaves = collect(DefinicaoOnboarding::paraServico($this->servicoDeGestao()))
            ->pluck('chave')
            ->all();

        foreach (self::CHAVES_REMOVIDAS as $chave) {
            $this->assertNotContains($chave, $chaves, "\"{$chave}\" foi removido da régua e não deve voltar");
        }
    }

    #[Test]
    public function onboarding_novo_nasce_sem_os_dois_passos(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        foreach (self::CHAVES_REMOVIDAS as $chave) {
            $this->assertNull(
                $onboarding->passos()->where('chave', $chave)->first(),
                "\"{$chave}\" nasceu num onboarding novo"
            );
        }
    }

    /**
     * Nenhum passo pode DEPENDER de uma chave removida: a dependência aponta
     * para algo que nunca nasce, e o passo dependente fica bloqueado para
     * sempre. Foi o que aconteceria com `reuniao_realizada` na v10 se a
     * remoção não tivesse ajustado o `depende_de` dela.
     */
    #[Test]
    public function nenhum_passo_depende_de_uma_chave_removida(): void
    {
        foreach (DefinicaoOnboarding::paraServico($this->servicoDeGestao()) as $passo) {
            foreach ($passo['depende_de'] ?? [] as $dependencia) {
                $this->assertNotContains(
                    $dependencia,
                    self::CHAVES_REMOVIDAS,
                    "\"{$passo['chave']}\" depende de \"{$dependencia}\", que não nasce mais — ficaria bloqueado para sempre"
                );
            }
        }
    }

    #[Test]
    public function o_cliente_nunca_viu_nenhum_dos_dois(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $chaves = collect(app(OnboardingLinkService::class)->passosDoCliente($onboarding->company))
            ->pluck('chave')
            ->all();

        foreach (self::CHAVES_REMOVIDAS as $chave) {
            $this->assertNotContains($chave, $chaves);
        }
    }

    #[Test]
    public function definicao_subiu_de_versao_e_onboarding_novo_nasce_com_ela(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertSame(DefinicaoOnboarding::VERSAO, $onboarding->definicao_versao);
        $this->assertSame(
            count(DefinicaoOnboarding::paraServico($this->servicoDeGestao())),
            $onboarding->passos()->count(),
        );
    }
}
