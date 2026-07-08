<?php

namespace Tests\Feature\Phase69;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Services\Nps\NpsTemplateService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 69 Plan 01 — Suite Feature RED do NpsTemplateService::resolveForCompany.
 *
 * Valida os 4 casos canônicos de precedência do research §4 (+ regressão active=false):
 *   1. Múltiplos templates aplicáveis  → maior priority vence
 *   2. Empate de priority              → menor id (determinístico)
 *   3. Nenhum scope aplicável          → fallback is_default=true (seed NPS Padrão)
 *   4. Templates com active=false      → ignorados mesmo se scope bate
 *   5. Sem default disponível          → RuntimeException com mensagem acionável
 *
 * REQ atendido: NPS-B-01.
 *
 * Setup implícito via RefreshDatabase:
 *   - Migration 100001 cria as 5 tabelas nps_*
 *   - Migration 2026_05_27_100001 seeda o catálogo Servicos (6 nomes canônicos)
 *   - Migration 100004 seeda o "NPS Padrão" (is_default=true)
 * Tudo isso já roda no in-memory SQLite antes de cada test — sem beforeEach manual.
 *
 * @see .planning/research/v15-nps-templates-schema.md §4 (regra determinística)
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md
 * @see app/Models/NpsTemplate.php (scopes active/default + relação serviceScopes)
 * @see app/Models/ContratoServico.php linhas 74-77 (scope active — coluna `ativo`)
 */
class NpsTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante que FKs SQLite estão ativas em cada teste — mesma prática da
     * suite Phase68/NpsSchemaTest para evitar surpresa se o driver mudar.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Helper: retorna o service resolvido pelo container Laravel (DI-friendly).
     * Consumidores futuros (Plan 69-04/05) usarão a mesma resolução.
     */
    private function service(): NpsTemplateService
    {
        return app(NpsTemplateService::class);
    }

    /**
     * Helper: retorna N serviços seedados do catálogo (Migration 100001).
     * Evita depender de nomes específicos e mantém os tests robustos a
     * ajustes futuros de valor_padrao/setor.
     *
     * @return Collection<int, Servico>
     */
    private function servicosCatalogo(int $qtd = 3): Collection
    {
        return Servico::query()->orderBy('id')->take($qtd)->get();
    }

    /**
     * Helper: cria contrato ativo entre empresa e serviço.
     * Não há factory pra ContratoServico — criamos direto para manter os
     * defaults mínimos (ativo=true, data_contratacao=hoje).
     */
    private function contratarServico(Company $company, Servico $servico): ContratoServico
    {
        return ContratoServico::create([
            'company_id'        => $company->id,
            'servico_id'        => $servico->id,
            'valor_contratado'  => 0,
            'data_contratacao'  => now()->toDateString(),
            'ativo'             => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 1 — priority DESC vence quando múltiplos templates aplicáveis
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resolveForCompany_retorna_template_de_maior_priority_quando_multiplos_aplicaveis(): void
    {
        // Setup: empresa com 2 contratos ativos (Servico A e Servico B).
        // Template T1 (priority=10) scoped a A; Template T2 (priority=20) scoped a B.
        // Ambos active=true. Espera-se T2 (maior priority) — research §4 regra 1.
        $company                = Company::factory()->create();
        [$servicoA, $servicoB]  = $this->servicosCatalogo(2);

        $this->contratarServico($company, $servicoA);
        $this->contratarServico($company, $servicoB);

        $t1 = NpsTemplate::factory()->create([
            'nome'     => 'Template A (priority baixa)',
            'active'   => true,
            'priority' => 10,
        ]);
        $t1->serviceScopes()->attach($servicoA->id);

        $t2 = NpsTemplate::factory()->create([
            'nome'     => 'Template B (priority alta)',
            'active'   => true,
            'priority' => 20,
        ]);
        $t2->serviceScopes()->attach($servicoB->id);

        $resolvido = $this->service()->resolveForCompany($company);

        $this->assertEquals(
            $t2->id,
            $resolvido->id,
            'template com priority=20 deveria vencer priority=10'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 2 — empate de priority → menor id vence (determinístico)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resolveForCompany_desempata_por_menor_id_quando_priority_igual(): void
    {
        // Setup: 2 templates com priority=5, ambos scoped ao MESMO serviço
        // que a empresa possui. Sem ordenação secundária, MySQL/SQLite
        // poderiam retornar qualquer um — o `->orderBy('id')` garante o
        // primeiro criado (menor id) vence, comportamento reprodutível.
        $company  = Company::factory()->create();
        $servicoA = $this->servicosCatalogo(1)->first();
        $this->contratarServico($company, $servicoA);

        // Primeiro criado → menor id.
        $tA = NpsTemplate::factory()->create([
            'nome'     => 'Template A (primeiro criado)',
            'active'   => true,
            'priority' => 5,
        ]);
        $tA->serviceScopes()->attach($servicoA->id);

        $tB = NpsTemplate::factory()->create([
            'nome'     => 'Template B (segundo criado)',
            'active'   => true,
            'priority' => 5,
        ]);
        $tB->serviceScopes()->attach($servicoA->id);

        $resolvido = $this->service()->resolveForCompany($company);

        $this->assertEquals(
            $tA->id,
            $resolvido->id,
            'empate de priority deveria ser resolvido pelo menor id (determinístico)'
        );
        $this->assertLessThan($tB->id, $resolvido->id, 'sanity: id de tA é menor que id de tB');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 3 — nenhum template aplicável → fallback is_default (padrão)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resolveForCompany_cai_no_default_quando_nenhum_template_aplicavel(): void
    {
        // Setup: empresa contrata Servico Z. Template X (priority=99) é
        // scoped a Servico W (empresa NÃO possui). Nenhum match → fallback
        // no seed "NPS Padrão" (migration 100004) via is_default=true.
        $servicos = $this->servicosCatalogo(2);
        $servicoZ = $servicos[0]; // empresa contrata
        $servicoW = $servicos[1]; // template scoped, empresa NÃO contrata

        $company = Company::factory()->create();
        $this->contratarServico($company, $servicoZ);

        $tX = NpsTemplate::factory()->create([
            'nome'     => 'Template X (não aplicável)',
            'active'   => true,
            'priority' => 99,
        ]);
        $tX->serviceScopes()->attach($servicoW->id);

        // Seed NPS Padrão deve estar presente antes do fallback funcionar.
        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão (migration 100004) deveria existir');

        $resolvido = $this->service()->resolveForCompany($company);

        $this->assertEquals(
            $padrao->id,
            $resolvido->id,
            'sem scope aplicável, deve cair no template is_default=true'
        );
        $this->assertNotEquals(
            $tX->id,
            $resolvido->id,
            'template scoped a serviço não contratado NÃO deve ser retornado'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 4 — regressão: templates active=false são ignorados
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resolveForCompany_ignora_templates_active_false(): void
    {
        // Setup: template Z tem priority=100 (alta), scope bate no serviço
        // da empresa — MAS active=false. Deve ser ignorado; fallback vai
        // pro padrão. Cobre bug clássico de esquecer o `->where('active', true)`.
        $company  = Company::factory()->create();
        $servicoA = $this->servicosCatalogo(1)->first();
        $this->contratarServico($company, $servicoA);

        $tZ = NpsTemplate::factory()->active(false)->create([
            'nome'     => 'Template Z desativado (mas com scope + priority alta)',
            'priority' => 100,
        ]);
        $tZ->serviceScopes()->attach($servicoA->id);

        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão deveria existir');

        $resolvido = $this->service()->resolveForCompany($company);

        $this->assertEquals(
            $padrao->id,
            $resolvido->id,
            'template active=false NUNCA deve ser retornado, mesmo com priority alta'
        );
        $this->assertNotEquals(
            $tZ->id,
            $resolvido->id,
            'template desativado precisa ser filtrado no where(active,true)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 5 — sem template is_default → RuntimeException clara
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resolveForCompany_dispara_runtime_exception_quando_sem_default(): void
    {
        // Setup: empresa sem contratos ativos + apagamos o seed NPS Padrão.
        // Esse estado NUNCA deveria acontecer em prod (migration 100004
        // garante 1 default sempre), mas o service precisa falhar CEDO
        // com mensagem acionável em pt-BR pra facilitar troubleshoot em CI.
        $company = Company::factory()->create();

        // Zera qualquer template is_default=true (elimina o seed).
        NpsTemplate::where('is_default', true)->delete();
        $this->assertEquals(0, NpsTemplate::where('is_default', true)->count(), 'baseline: zero defaults');

        $this->expectException(RuntimeException::class);
        // A mensagem precisa mencionar "is_default" pra desenvolvedor
        // localizar rapidamente o que quebrou (seed retro Plan 68-03).
        $this->expectExceptionMessageMatches('/is_default|template padrão/i');

        $this->service()->resolveForCompany($company);
    }
}
