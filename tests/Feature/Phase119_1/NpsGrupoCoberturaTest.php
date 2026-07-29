<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsGrupoCoberturaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 05 (Task 2) — Prova de `NpsGrupoCoberturaService::calcular()`:
 * quem entra na replicação de grupo, quem fica de fora e por quê, com a
 * REFERÊNCIA escolhida pela MAIORIA (nunca pelo menor id).
 *
 * Caso âncora do usuário: grupo "Camillo Parts", Luis (estrategista) e Ana
 * Julia (analista) cuidam de todas as empresas.
 *
 * Decisões travadas exercitadas aqui (119.1-CONTEXT.md):
 *  - D3 — comparação só nos serviços cobertos pelo modelo.
 *  - D4 — replicação parcial é regra, não erro.
 *  - D6 — estrategista E analista precisam bater, sempre.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see app/Services/Nps/NpsGrupoCoberturaService.php
 */
class NpsGrupoCoberturaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): NpsGrupoCoberturaService
    {
        return app(NpsGrupoCoberturaService::class);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════════

    /** Cria um modelo NPS cobrindo os serviços informados. */
    private function criarTemplateCobrindo(array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(['active' => true]);
        $template->serviceScopes()->attach($servicoIds);

        return $template;
    }

    /**
     * Cria uma empresa do grupo, com contrato ativo no serviço informado e
     * os responsáveis (estrategista/analista) atribuídos NAQUELE serviço.
     * `servicoIdPivot` permite testar o slot consolidado (NULL).
     */
    private function criarEmpresaDoGrupo(
        CompanyGroup $grupo,
        int $servicoId,
        ?User $estrategista,
        ?User $analista = null,
        bool $active = true,
        ?int $servicoIdPivot = null,
    ): Company {
        $empresa = Company::factory()->create([
            'active'           => $active,
            'company_group_id' => $grupo->id,
            'name'             => 'Empresa ' . uniqid(),
        ]);

        $this->criarContrato($empresa->id, $servicoId, true);

        $servicoPivot = $servicoIdPivot ?? $servicoId;

        if ($estrategista) {
            $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPivot);
        }
        if ($analista) {
            $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPivot);
        }

        return $empresa;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Caso base — todas concordam, ninguém excluído
    // ═══════════════════════════════════════════════════════════════════

    public function test_grupo_com_mesmo_estrategista_e_analista_todas_entram(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e3 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $this->assertCount(3, $resultado['incluidas']);
        $this->assertCount(0, $resultado['excluidas']);
        $this->assertContains($resultado['referencia'], [$e1->id, $e2->id, $e3->id]);

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id, $e3->id])->sort()->values()->all(), $idsIncluidas);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. O caso obrigatório: 3 contra 1, divergente com MENOR id
    // ═══════════════════════════════════════════════════════════════════

    public function test_maioria_vence_mesmo_quando_divergente_tem_menor_id(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outroEstrategista = User::factory()->create(['name' => 'Outro Estrategista']);

        // A divergente é criada PRIMEIRO — ganha o MENOR id por construção.
        // Se a referência fosse escolhida por id, o resultado seria o
        // OPOSTO do pedido: excluiria as 3 que concordam.
        $divergente = $this->criarEmpresaDoGrupo($grupo, $servico, $outroEstrategista, $anaJulia);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e3 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $this->assertLessThan($e1->id, $divergente->id, 'Pré-condição do cenário: divergente precisa ter o MENOR id.');

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id, $e3->id])->sort()->values()->all(), $idsIncluidas, 'As 3 que concordam devem entrar.');

        $this->assertNotSame($divergente->id, $resultado['referencia'], 'Referência NÃO pode ser a empresa divergente.');
        $this->assertContains($resultado['referencia'], [$e1->id, $e2->id, $e3->id], 'Referência deve ser uma das 3 que concordam (maioria).');

        $this->assertCount(1, $resultado['excluidas']);
        $this->assertSame($divergente->id, $resultado['excluidas'][0]['company_id']);
        $this->assertSame('responsavel_diferente', $resultado['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. ESTRATEGISTA diferente exclui, com detalhe do serviço e de quem cuida
    // ═══════════════════════════════════════════════════════════════════

    public function test_estrategista_diferente_exclui_com_motivo_e_detalhe(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outroEstrategista = User::factory()->create(['name' => 'Marcos']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $divergente = $this->criarEmpresaDoGrupo($grupo, $servico, $outroEstrategista, $anaJulia);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas);

        $this->assertCount(1, $resultado['excluidas']);
        $linha = $resultado['excluidas'][0];
        $this->assertSame($divergente->id, $linha['company_id']);
        $this->assertSame('responsavel_diferente', $linha['motivo']);
        $this->assertSame('estrategista', $linha['papel']);
        $this->assertNotNull($linha['servico']);
        $this->assertSame('Marcos', $linha['quem_cuida']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. ANALISTA diferente (D6) também exclui — não basta bater o estrategista
    // ═══════════════════════════════════════════════════════════════════

    public function test_analista_diferente_tambem_exclui_d6(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outraAnalista = User::factory()->create(['name' => 'Beatriz']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $divergente = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $outraAnalista);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas);

        $this->assertCount(1, $resultado['excluidas']);
        $linha = $resultado['excluidas'][0];
        $this->assertSame($divergente->id, $linha['company_id']);
        $this->assertSame('responsavel_diferente', $linha['motivo']);
        $this->assertSame('analista', $linha['papel'], 'D6 — papel "consultor" precisa ser traduzido para "analista" na tela.');
        $this->assertSame('Beatriz', $linha['quem_cuida']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. Empate de tamanho — vence a classe da empresa de MENOR id, estável
    // ═══════════════════════════════════════════════════════════════════

    public function test_empate_de_classes_vence_menor_id_e_e_estavel_entre_execucoes(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Empate']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $responsavelA = User::factory()->create(['name' => 'Responsavel A']);
        $responsavelB = User::factory()->create(['name' => 'Responsavel B']);

        $menor = $this->criarEmpresaDoGrupo($grupo, $servico, $responsavelA);
        $maior = $this->criarEmpresaDoGrupo($grupo, $servico, $responsavelB);

        $this->assertLessThan($maior->id, $menor->id);

        $resultado1 = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));
        $resultado2 = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $this->assertSame($menor->id, $resultado1['referencia'], 'Empate de tamanho 1x1 — vence a empresa de MENOR id.');
        $this->assertSame($resultado1, $resultado2, 'Resultado precisa ser idêntico entre execuções (desempate determinístico).');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. Sem contrato ativo em serviço coberto
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_contrato_ativo_em_servico_coberto_e_excluida(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $outroServico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        // Empresa do grupo com contrato só no serviço NÃO coberto pelo modelo.
        $semServicoCoberto = Company::factory()->create([
            'active' => true, 'company_group_id' => $grupo->id, 'name' => 'Sem Servico Coberto',
        ]);
        $this->criarContrato($semServicoCoberto->id, $outroServico, true);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->all();
        $this->assertSame([$e1->id], $idsIncluidas);

        $this->assertCount(1, $resultado['excluidas']);
        $this->assertSame($semServicoCoberto->id, $resultado['excluidas'][0]['company_id']);
        $this->assertSame('sem_servico_contratado', $resultado['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7. Sem interseção de serviços aplicáveis com a referência
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_servico_em_comum_com_referencia_e_excluida(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servicoA = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoB = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servicoA, $servicoB]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outro = User::factory()->create(['name' => 'Outro']);

        // Maioria (2) só com contrato no serviço A, mesmos responsáveis.
        $e1 = $this->criarEmpresaDoGrupo($grupo, $servicoA, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servicoA, $luis, $anaJulia);

        // Candidata só com contrato no serviço B — não intersecta a referência.
        $semComum = $this->criarEmpresaDoGrupo($grupo, $servicoB, $outro, $outro);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas);

        $this->assertCount(1, $resultado['excluidas']);
        $this->assertSame($semComum->id, $resultado['excluidas'][0]['company_id']);
        $this->assertSame('sem_servico_em_comum', $resultado['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8. Já tem link individual no mês — fica de fora, não recebe 2 links
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_com_link_individual_no_mes_e_excluida(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $jaTemLink = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $mes = Carbon::create(2026, 7, 1);
        NpsSurvey::factory()->for($jaTemLink)->create([
            'template_id'     => $template->id,
            'month_reference' => $mes->copy()->startOfMonth(),
        ]);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, $mes);

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->all();
        $this->assertSame([$e1->id], $idsIncluidas);

        $this->assertCount(1, $resultado['excluidas']);
        $this->assertSame($jaTemLink->id, $resultado['excluidas'][0]['company_id']);
        $this->assertSame('ja_tem_link', $resultado['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 9. Empresa inativa é excluída
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_inativa_e_excluida(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $inativa = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia, active: false);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->all();
        $this->assertSame([$e1->id], $idsIncluidas);

        $this->assertCount(1, $resultado['excluidas']);
        $this->assertSame($inativa->id, $resultado['excluidas'][0]['company_id']);
        $this->assertSame('empresa_inativa', $resultado['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 10. Divergência em serviço NÃO coberto pelo modelo não exclui ninguém (D3)
    // ═══════════════════════════════════════════════════════════════════

    public function test_divergencia_em_servico_nao_coberto_pelo_modelo_nao_exclui_ninguem(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servicoCoberto = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoNaoCoberto = $this->criarServico(Servico::SETOR_SHOPEE, true);
        // O modelo só cobre $servicoCoberto — $servicoNaoCoberto fica de fora.
        $template = $this->criarTemplateCobrindo([$servicoCoberto]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outroAnalistaShopee = User::factory()->create(['name' => 'Outro Shopee']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servicoCoberto, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servicoCoberto, $luis, $anaJulia);

        // e2 tem um responsável DIFERENTE no serviço Shopee — mas o modelo
        // não cobre Shopee, então isso não pode excluir e2 (D3).
        $this->criarContrato($e2->id, $servicoNaoCoberto, true);
        $this->inserirPivot($e2->id, $outroAnalistaShopee->id, 'consultor', $servicoNaoCoberto);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas);
        $this->assertCount(0, $resultado['excluidas']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 11. Slot consolidado (servico_id NULL) resolve corretamente
    // ═══════════════════════════════════════════════════════════════════

    public function test_responsavel_no_slot_consolidado_bate_com_slot_especifico_da_mesma_pessoa(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        // e1: atribuição ESPECÍFICA no serviço coberto.
        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        // e2: mesma dupla, mas no slot CONSOLIDADO (servico_id NULL) —
        // responsavelDoServicoOuConsolidado() precisa resolver como a MESMA pessoa.
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia, servicoIdPivot: null);

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $idsIncluidas = collect($resultado['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas, 'Slot consolidado e slot específico da mesma pessoa devem ser compatíveis.');
        $this->assertCount(0, $resultado['excluidas']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 12. Nenhuma empresa se qualifica — incluidas vazio, todas excluídas com motivo
    // ═══════════════════════════════════════════════════════════════════

    public function test_grupo_onde_nenhuma_empresa_se_qualifica(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Vazio']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, null, active: false);
        $e2 = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
        // e2 sem NENHUM contrato ativo.

        $resultado = $this->service()->calcular($grupo->fresh(), $template, Carbon::create(2026, 7, 1));

        $this->assertCount(0, $resultado['incluidas']);
        $this->assertNull($resultado['referencia']);
        $this->assertCount(2, $resultado['excluidas']);

        $motivos = collect($resultado['excluidas'])->pluck('motivo', 'company_id');
        $this->assertSame('empresa_inativa', $motivos[$e1->id]);
        $this->assertSame('sem_servico_contratado', $motivos[$e2->id]);
    }
}
