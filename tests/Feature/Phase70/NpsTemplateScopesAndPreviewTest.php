<?php

namespace Tests\Feature\Phase70;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 70 Plan 06 T4 — Suite Feature de service scopes + empresasAfetadas +
 * preview live.
 *
 * Cobre SC #4 (scopes + empresas afetadas) e SC #5 (preview live stateless) do
 * ROADMAP Phase 70. Estes 3 endpoints são o feedback visual do REQ NPS-C-05
 * (associação template ↔ serviços) e NPS-C-06 (preview live no editor).
 *
 * REQ atendidos: NPS-C-05 (service scopes + empresas afetadas), NPS-C-06 (preview).
 *
 * Setup implícito:
 *   - Catálogo de Servicos seedado pela migration 2026_05_27_100001 (6 nomes).
 *   - Seed NPS Padrão (migration 100004) presente — usado pelo resolveForCompany
 *     no fallback de empresas sem template scoped aplicável.
 *
 * Referências:
 *   - .planning/phases/70-ui-de-configuracao-admin/70-06-PLAN.md (T4)
 *   - app/Http/Controllers/NpsTemplateController.php (syncServicos, empresasAfetadas, preview)
 *   - app/Services/Nps/NpsTemplateService.php (Phase 69 — usado internamente)
 */
class NpsTemplateScopesAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * @return Collection<int, Servico>
     */
    private function servicosCatalogo(int $qtd = 3): Collection
    {
        return Servico::query()->orderBy('id')->take($qtd)->get();
    }

    /**
     * Helper: cria contrato ativo (padrão herdado das Phases 68/69).
     */
    private function contratarServico(Company $company, Servico $servico): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — sync attach: associar IDs ao template via PUT
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_sync_servicos_associa_ids_ao_template(): void
    {
        // Setup: template limpo + 3 serviços do catálogo. PUT sincroniza 2
        // dos 3 → pivot deve ter exatamente 2 rows para este template.
        $template = NpsTemplate::factory()->create(['nome' => 'Template Sync']);
        $servicos = $this->servicosCatalogo(3);

        $this->actingAs($this->admin());

        $this->put(
            route('nps.configuracao.templates.servicos.sync', $template),
            [
                'servicos' => [$servicos[0]->id, $servicos[1]->id],
            ]
        )->assertRedirect();

        $this->assertDatabaseHas('nps_template_service_scopes', [
            'template_id' => $template->id,
            'servico_id'  => $servicos[0]->id,
        ]);
        $this->assertDatabaseHas('nps_template_service_scopes', [
            'template_id' => $template->id,
            'servico_id'  => $servicos[1]->id,
        ]);
        $this->assertSame(
            2,
            DB::table('nps_template_service_scopes')
                ->where('template_id', $template->id)
                ->count(),
            'pivot deveria ter exatamente 2 rows para este template'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — sync vazio desassocia todos os serviços (payload [])
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_sync_servicos_vazio_desassocia_tudo(): void
    {
        // Setup: template já com 3 serviços no pivot. PUT com servicos:[]
        // deve limpar tudo (payload vazio = desassociação total, semanticamente
        // válido no SyncNpsTemplateScopesRequest — `servicos` sem `required`).
        $template = NpsTemplate::factory()->create(['nome' => 'Template Desassociar']);
        $servicos = $this->servicosCatalogo(3);

        $template->servicos()->attach($servicos->pluck('id')->all());
        $this->assertSame(
            3,
            DB::table('nps_template_service_scopes')
                ->where('template_id', $template->id)
                ->count(),
            'baseline: 3 rows no pivot antes do sync vazio'
        );

        $this->actingAs($this->admin());

        $this->put(
            route('nps.configuracao.templates.servicos.sync', $template),
            ['servicos' => []]
        )->assertRedirect();

        $this->assertSame(
            0,
            DB::table('nps_template_service_scopes')
                ->where('template_id', $template->id)
                ->count(),
            'pivot deveria estar vazio após sync com servicos:[]'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — empresasAfetadas retorna JSON com count + lista + metadata
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresas_afetadas_retorna_json_com_count_e_lista(): void
    {
        // Setup: template T com priority=99 scoped a servico X. Empresa Y
        // ativa com contrato ativo em X. resolveForCompany(Y) → T (priority
        // vence o fallback default). empresasAfetadas(T) deve conter Y.
        $servicoX = $this->servicosCatalogo(1)->first();

        $template = NpsTemplate::factory()->create([
            'nome'     => 'Template Alta Precedência',
            'active'   => true,
            'priority' => 99,
        ]);
        $template->servicos()->attach($servicoX->id);

        $empresaY = Company::factory()->create([
            'name'   => 'Empresa Afetada Y',
            'active' => true,
        ]);
        $this->contratarServico($empresaY, $servicoX);

        $this->actingAs($this->admin());

        $response = $this->get(
            route('nps.configuracao.templates.empresas-afetadas', $template)
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'template_id',
            'count',
            'empresas',
            'sampled_from',
            'total_ativas',
            'truncated',
        ]);

        // Empresa Y deve aparecer na lista com id + name.
        $response->assertJsonFragment([
            'id'   => $empresaY->id,
            'name' => 'Empresa Afetada Y',
        ]);
        $response->assertJson([
            'template_id' => $template->id,
            'count'       => 1,
            'truncated'   => false,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — preview retorna estrutura normalizada SEM PERSISTIR
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_preview_retorna_estrutura_normalizada_sem_persistir(): void
    {
        // Preview é pure function — nenhum INSERT/UPDATE/DELETE. Snapshot
        // pré/pós dos counts das 3 tabelas deve bater exatamente.
        $templateCountBefore = NpsTemplate::count();
        $questionCountBefore = NpsTemplateQuestion::count();
        $optionCountBefore   = NpsTemplateOption::count();

        $this->actingAs($this->admin());

        $response = $this->post(
            route('nps.configuracao.templates.preview'),
            [
                'nome'      => 'X',
                'descricao' => 'Preview de teste',
                'perguntas' => [
                    [
                        'texto'       => 'Como avalia o serviço?',
                        'tipo'        => 'escala',
                        'dimensao'    => 'empresa',
                        'obrigatoria' => true,
                        'options'     => [
                            ['label' => '1', 'peso' => 1],
                            ['label' => '2', 'peso' => 2],
                            ['label' => '3', 'peso' => 3],
                            ['label' => '4', 'peso' => 4],
                            ['label' => '5', 'peso' => 5],
                        ],
                    ],
                    [
                        'texto'    => 'Comentário adicional?',
                        'tipo'     => 'opcoes',
                        'dimensao' => 'geral',
                        'options'  => [],
                    ],
                ],
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('template.nome', 'X');
        $response->assertJsonPath('template.descricao', 'Preview de teste');
        $response->assertJsonPath('template.perguntas.0.options.0.peso', 1);
        $response->assertJsonPath('template.perguntas.0.options.4.peso', 5);
        $response->assertJsonPath('template.perguntas.0.ordem', 1);
        $response->assertJsonPath('template.perguntas.1.ordem', 2);

        // Snapshot counts pré == pós — nada foi inserido no banco.
        $this->assertSame(
            $templateCountBefore,
            NpsTemplate::count(),
            'preview NÃO deveria criar template no banco'
        );
        $this->assertSame(
            $questionCountBefore,
            NpsTemplateQuestion::count(),
            'preview NÃO deveria criar perguntas no banco'
        );
        $this->assertSame(
            $optionCountBefore,
            NpsTemplateOption::count(),
            'preview NÃO deveria criar options no banco'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — preview valida payload aninhado (PreviewNpsTemplateRequest)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_preview_valida_payload_aninhado(): void
    {
        // Preview usa PreviewNpsTemplateRequest com regras aninhadas para
        // perguntas.*.options.*.peso (1..5), perguntas.*.texto (min:3) e
        // perguntas.*.dimensao (Rule::in). Payload inválido deve retornar 422
        // com o path aninhado correto no error bag.
        $this->actingAs($this->admin());

        $urlPreview = route('nps.configuracao.templates.preview');

        // Peso 99 fora do range 1..5 → erro em perguntas.0.options.0.peso
        $this->post($urlPreview, [
            'nome'      => 'X',
            'perguntas' => [
                [
                    'texto'    => 'Pergunta válida?',
                    'tipo'     => 'escala',
                    'dimensao' => 'empresa',
                    'options'  => [
                        ['label' => 'ok', 'peso' => 99],
                    ],
                ],
            ],
        ])->assertSessionHasErrors(['perguntas.0.options.0.peso']);

        // Texto de pergunta ausente → erro em perguntas.0.texto
        $this->post($urlPreview, [
            'nome'      => 'X',
            'perguntas' => [
                [
                    'tipo'     => 'escala',
                    'dimensao' => 'empresa',
                    'options'  => [
                        ['label' => 'ok', 'peso' => 3],
                    ],
                ],
            ],
        ])->assertSessionHasErrors(['perguntas.0.texto']);

        // Dimensão inválida → erro em perguntas.0.dimensao (Rule::in bloqueia)
        $this->post($urlPreview, [
            'nome'      => 'X',
            'perguntas' => [
                [
                    'texto'    => 'Pergunta com dimensão errada?',
                    'tipo'     => 'escala',
                    'dimensao' => 'xyz', // inválido — não está em DIMENSOES
                    'options'  => [
                        ['label' => 'ok', 'peso' => 3],
                    ],
                ],
            ],
        ])->assertSessionHasErrors(['perguntas.0.dimensao']);
    }
}
