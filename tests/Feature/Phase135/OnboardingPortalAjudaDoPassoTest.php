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
 * Ajuda do passo no portal do cliente — tutorial em vídeo e passo a passo em
 * texto, trazidos do desenho do portal de Polos.
 *
 * A divisão que estes testes protegem é a MESMA da instrução (ver
 * `OnboardingEtapasEInstrucoesTest`): ajuda é CONTEÚDO, resolvida por `chave`
 * na hora de montar o payload — nunca copiada para `onboarding_passos`. É isso
 * que permite corrigir um passo a passo confuso e alcançar justamente quem já
 * está travado por não tê-lo entendido.
 */
class OnboardingPortalAjudaDoPassoTest extends TestCase
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

    private function onboardingEmAndamento(Company $company): Onboarding
    {
        $engine   = app(OnboardingEngineService::class);
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $onboarding = $engine->criarParaContrato($contrato);
        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    /** @return array<string, array<string, mixed>> */
    private function payloadPorChave(Company $company): array
    {
        return collect(app(OnboardingLinkService::class)->passosDoCliente($company))
            ->keyBy('chave')
            ->all();
    }

    // ─── Shape: as duas chaves sempre presentes ─────────────────────────────

    /**
     * Presença é contrato, valor é opcional. O portal lê `passo.tutorial_url` e
     * `passo.passo_a_passo` de todo card — chave ausente viraria `undefined` e
     * o botão renderizaria sem conteúdo.
     */
    #[Test]
    public function todo_passo_do_cliente_chega_com_as_duas_chaves_de_ajuda(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        $payload = app(OnboardingLinkService::class)->passosDoCliente($company);

        $this->assertNotEmpty($payload);

        foreach ($payload as $item) {
            $this->assertArrayHasKey('tutorial_url', $item, "Passo \"{$item['chave']}\" sem a chave tutorial_url");
            $this->assertArrayHasKey('passo_a_passo', $item, "Passo \"{$item['chave']}\" sem a chave passo_a_passo");
        }
    }

    // ─── Passo a passo: conteúdo real para todo passo do cliente ────────────

    /**
     * Não é só "a chave existe": todo passo que o cliente precisa executar tem
     * de ter o detalhe disponível. Um passo do cliente sem passo a passo é
     * exatamente o que produzia a ligação para o responsável.
     */
    #[Test]
    public function todo_passo_do_cliente_tem_passo_a_passo_com_conteudo(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        foreach ($this->payloadPorChave($company) as $chave => $item) {
            $ajuda = $item['passo_a_passo'];

            $this->assertIsArray($ajuda, "O passo \"{$chave}\" chega ao cliente sem passo a passo");
            $this->assertNotSame('', trim($ajuda['titulo']), "Passo a passo de \"{$chave}\" sem título");
            $this->assertNotSame('', trim($ajuda['saudacao']), "Passo a passo de \"{$chave}\" sem saudação");
            $this->assertNotEmpty($ajuda['passos'], "Passo a passo de \"{$chave}\" sem nenhuma etapa numerada");

            foreach ($ajuda['passos'] as $i => $linha) {
                $this->assertNotSame('', trim($linha), "Etapa {$i} do passo a passo de \"{$chave}\" está vazia");
            }
        }
    }

    /**
     * A caixa âmbar existe onde há pegadinha real, e a pegadinha conhecida é
     * uma: vincular a conta ERRADA do Mercado Livre. O erro só aparece dias
     * depois, quando os dados que chegam não são os da loja do projeto — por
     * isso o alerta é obrigatório nos dois passos que fazem vínculo.
     */
    #[Test]
    public function os_passos_de_vinculo_avisam_sobre_a_conta_errada_do_mercado_livre(): void
    {
        foreach (['grant_sistema_ecf', 'grant_consultoria_adman'] as $chave) {
            $ajuda = DefinicaoOnboarding::passoAPassoDe($chave);

            $this->assertNotNull($ajuda['atencao'], "O passo \"{$chave}\" faz vínculo e não avisa nada");
            $this->assertStringContainsString('conta', mb_strtolower($ajuda['atencao']));
        }
    }

    // ─── Chave desconhecida: null, nunca exceção ───────────────────────────

    #[Test]
    public function ajuda_de_chave_desconhecida_e_null_e_nao_lanca(): void
    {
        $this->assertNull(DefinicaoOnboarding::tutorialDe('chave_que_nunca_existiu'));
        $this->assertNull(DefinicaoOnboarding::passoAPassoDe('chave_que_nunca_existiu'));
    }

    /**
     * Sem URL cadastrada o portal não pode inventar botão. `TUTORIAIS` nasce
     * vazio de propósito — este teste protege o contrato "null ⇒ sem botão",
     * não o mapa estar vazio (colar uma URL lá é a operação esperada).
     */
    #[Test]
    public function passo_sem_video_cadastrado_devolve_null_no_tutorial(): void
    {
        $company = Company::factory()->create();
        $this->onboardingEmAndamento($company);

        foreach ($this->payloadPorChave($company) as $chave => $item) {
            $url = $item['tutorial_url'];

            $this->assertTrue(
                $url === null || trim($url) !== '',
                "O passo \"{$chave}\" devolveu string vazia — o portal renderizaria um botão de vídeo sem vídeo"
            );
        }
    }

    // ─── A ajuda não congela na linha do passo ─────────────────────────────

    /**
     * Espelha `instrucao_nao_vive_em_coluna_do_passo`. Se um dia alguém copiar
     * a ajuda para o passo no nascimento, o cliente que mais precisa da
     * correção nunca a recebe.
     */
    #[Test]
    public function ajuda_nao_vive_em_coluna_do_passo(): void
    {
        $colunas = \Schema::getColumnListing('onboarding_passos');

        $this->assertNotContains('tutorial_url', $colunas);
        $this->assertNotContains('passo_a_passo', $colunas);
    }

    /**
     * Ajuda é texto, não estrutura — mexer nela não é mudança de receita e por
     * isso NÃO faz `VERSAO` subir. O onboarding que já está rodando continua
     * com os mesmos passos e recebe o texto novo na próxima abertura da tela.
     */
    #[Test]
    public function onboarding_ja_em_andamento_recebe_a_ajuda_sem_renascer(): void
    {
        $company    = Company::factory()->create();
        $onboarding = $this->onboardingEmAndamento($company);

        $versaoNoNascimento = $onboarding->definicao_versao;
        $passosNoNascimento = $onboarding->passos()->count();

        // Segunda leitura do portal, como o cliente reabrindo o link.
        $payload = $this->payloadPorChave($company);

        $this->assertNotEmpty(array_filter(array_column($payload, 'passo_a_passo')));
        $this->assertSame($versaoNoNascimento, $onboarding->fresh()->definicao_versao);
        $this->assertSame($passosNoNascimento, $onboarding->fresh()->passos()->count());
    }
}
