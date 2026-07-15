<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick task 260715-ndo — Bug A: `NpsController::generate` só honrava o
 * `template_id` escolhido no modal "Gerar link" quando `$user->isAdmin()`
 * (linha 386, antes do fix). O modal modelo-first (Fase 81) já oferece o
 * seletor para TODOS os usuários autorizados — então o não-admin escolhia
 * "NPS Performance" e o servidor **ignorava silenciosamente**, caindo no
 * `resolveForCompany()` por priority. Resultado medido em produção: 15 links
 * Shopee gerados por não-admin, todos errados (2 já respondidos).
 *
 * Esta suite é RED antes da Tarefa 2 (fix em `NpsController::generate`) — os
 * casos "modelo escolhido é honrado" e "escopo rejeita" DEVEM falhar nesta
 * suite antes do fix. Os 3 casos de não-regressão (admin, fallback sem
 * template_id, 403 fora da carteira) já passam, pois não dependem do gate
 * removido.
 *
 * Cenário-base: 1 empresa `active=true` com DOIS contratos ativos
 * (performance + shopee) e responsáveis por-serviço distintos; 2 templates
 * `active` — "Perf" (scope = serviço performance, priority=0) e "Shopee"
 * (scope = serviço shopee, priority=10). A diferença de priority é o que faz
 * `resolveForCompany` devolver o Shopee — reprodução exata do bug: um
 * não-admin que pede explicitamente o Perf recebia o Shopee mesmo assim.
 *
 * @see app/Http/Controllers/NpsController.php::generate
 * @see .planning/quick/260715-ndo-nps-modelo-escolhido-e-migracao/260715-ndo-PLAN.md
 */
class NpsModeloEscolhidoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria um template NPS `active` com a priority informada.
     */
    private function criarTemplate(string $nome, bool $active = true, bool $isDefault = false, int $priority = 0): NpsTemplate
    {
        return NpsTemplate::factory()->create([
            'nome'       => $nome,
            'active'     => $active,
            'is_default' => $isDefault,
            'priority'   => $priority,
        ]);
    }

    /**
     * Monta o cenário-base: empresa com contratos ativos de performance E
     * shopee, responsáveis distintos por serviço, e os 2 templates
     * "Perf"/"Shopee" com scope e priority que reproduzem o bug (Shopee
     * vence o resolveForCompany por ter priority maior).
     *
     * @return array{company: Company, nonAdmin: User, servicoPerf: int,
     *   servicoShopee: int, templatePerf: NpsTemplate, templateShopee: NpsTemplate}
     */
    private function cenarioBase(): array
    {
        // criarCenarioMlComResponsaveis() já cria a empresa + servicoPerf
        // (contrato ativo) + analista/estrategista vinculados ao performance.
        $cenario     = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        // Adiciona o slot Shopee — responsável DIFERENTE do performance,
        // provando que a atribuição segue o serviço do modelo, não um
        // responsável genérico. Cria um serviço shopee ISOLADO (não reusa
        // `inserirLinhaShopee`, que reaproveita o serviço shopee global já
        // semeado pela migration 2026_07_14_100002 — reusar contaminaria o
        // teste com o template "NPS Shopee" seedado, que também teria scope
        // nesse mesmo serviço e disputaria a priority com o nosso).
        $analistaShopee = User::factory()->create();
        $servicoShopee  = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $analistaShopee->id, 'consultor', $servicoShopee);

        $templatePerf   = $this->criarTemplate('Perf', active: true, priority: 0);
        $templatePerf->serviceScopes()->attach($servicoPerf);

        $templateShopee = $this->criarTemplate('Shopee', active: true, priority: 10);
        $templateShopee->serviceScopes()->attach($servicoShopee);

        // Não-admin com a empresa na carteira (pivot do performance já
        // amarra a empresa a User::companies()).
        $nonAdmin = User::factory()->create(['role' => 'consultor']);
        $this->inserirPivot($company->id, $nonAdmin->id, 'consultor', $servicoPerf);

        return [
            'company'        => $company,
            'nonAdmin'       => $nonAdmin,
            'servicoPerf'    => $servicoPerf,
            'servicoShopee'  => $servicoShopee,
            'templatePerf'   => $templatePerf,
            'templateShopee' => $templateShopee,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // RED — o bug: modelo escolhido pelo não-admin é ignorado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nao_admin_escolhendo_template_perf_recebe_survey_com_perf_nao_shopee(): void
    {
        $c = $this->cenarioBase();

        $this->actingAs($c['nonAdmin'])->post('/nps/generate', [
            'company_id'  => $c['company']->id,
            'template_id' => $c['templatePerf']->id,
        ]);

        $survey = NpsSurvey::where('company_id', $c['company']->id)->first();

        $this->assertNotNull($survey, 'O survey deveria ter sido criado.');
        $this->assertSame(
            $c['templatePerf']->id,
            $survey->template_id,
            'O não-admin escolheu o modelo Perf, mas o survey nasceu com outro template_id '
            . '(hoje: ' . $survey->template_id . ', Shopee=' . $c['templateShopee']->id . ') — '
            . 'prova do Bug A: o gate isAdmin() ignora silenciosamente o modelo escolhido.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Escopo — rejeita template cujo único serviço coberto não tem
    // contrato ATIVO na empresa
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_escopo_rejeita_template_cujo_servico_nao_tem_contrato_ativo(): void
    {
        $c = $this->cenarioBase();

        // Serviço de publicação com contrato INATIVO na empresa — prova que
        // é o ->active() do contrato que decide, não a mera existência dele.
        $servicoPublicacao = $this->criarServico(Servico::SETOR_PUBLICACAO, true);
        $this->criarContrato($c['company']->id, $servicoPublicacao, false);

        $templateRejeitado = $this->criarTemplate('Rejeitado', active: true, priority: 5);
        $templateRejeitado->serviceScopes()->attach($servicoPublicacao);

        $response = $this->actingAs($c['nonAdmin'])->post('/nps/generate', [
            'company_id'  => $c['company']->id,
            'template_id' => $templateRejeitado->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, NpsSurvey::where('company_id', $c['company']->id)->count(), 'Nenhum survey deveria ter sido criado.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Escopo — modelo inativo é rejeitado independente do scope
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_escopo_rejeita_template_inativo(): void
    {
        $c = $this->cenarioBase();

        $templateInativo = $this->criarTemplate('Inativo', active: false, priority: 5);
        $templateInativo->serviceScopes()->attach($c['servicoPerf']);

        $response = $this->actingAs($c['nonAdmin'])->post('/nps/generate', [
            'company_id'  => $c['company']->id,
            'template_id' => $templateInativo->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, NpsSurvey::where('company_id', $c['company']->id)->count(), 'Nenhum survey deveria ter sido criado.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Escopo — fallback pivot vazio (DEC-NDO-1): modelo SEM nenhum scope
    // é aceito para qualquer empresa (espelha o NPS Padrão)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_escopo_aceita_template_sem_nenhum_scope_pivot_vazio(): void
    {
        $c = $this->cenarioBase();

        // "Padrão" sem nenhum vínculo em nps_template_service_scopes.
        $templateSemScopo = $this->criarTemplate('Padrão', active: true, priority: -1);

        $this->actingAs($c['nonAdmin'])->post('/nps/generate', [
            'company_id'  => $c['company']->id,
            'template_id' => $templateSemScopo->id,
        ]);

        $survey = NpsSurvey::where('company_id', $c['company']->id)->first();

        $this->assertNotNull($survey, 'Template sem scope deveria ser aceito (fallback DEC-NDO-1) — sem ele o NPS Padrão quebraria.');
        $this->assertSame($templateSemScopo->id, $survey->template_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Não-regressão — admin continua escolhendo o modelo igual a hoje
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_escolhendo_template_continua_funcionando(): void
    {
        $c     = $this->cenarioBase();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/nps/generate', [
            'company_id'  => $c['company']->id,
            'template_id' => $c['templatePerf']->id,
        ]);

        $survey = NpsSurvey::where('company_id', $c['company']->id)->first();

        $this->assertNotNull($survey);
        $this->assertSame($c['templatePerf']->id, $survey->template_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Não-regressão — requisição SEM template_id continua caindo no
    // resolveForCompany (fallback preservado, DEC-NDO-3)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_sem_template_id_continua_caindo_no_resolve_for_company(): void
    {
        $c = $this->cenarioBase();

        $this->actingAs($c['nonAdmin'])->post('/nps/generate', [
            'company_id' => $c['company']->id,
            // template_id ausente de propósito.
        ]);

        $survey = NpsSurvey::where('company_id', $c['company']->id)->first();

        $this->assertNotNull($survey);
        // resolveForCompany escolhe o de maior priority entre os aplicáveis
        // — Shopee (priority=10) vence Perf (priority=0) neste cenário.
        $this->assertSame(
            $c['templateShopee']->id,
            $survey->template_id,
            'Sem template_id, o fallback resolveForCompany deve continuar escolhendo por priority.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Não-regressão — autorização por empresa (403) permanece intacta
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nao_admin_com_empresa_fora_da_carteira_recebe_403(): void
    {
        $c = $this->cenarioBase();

        $outraEmpresa = Company::factory()->create();

        $response = $this->actingAs($c['nonAdmin'])->post('/nps/generate', [
            'company_id'  => $outraEmpresa->id,
            'template_id' => $c['templatePerf']->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, NpsSurvey::where('company_id', $outraEmpresa->id)->count());
    }
}
