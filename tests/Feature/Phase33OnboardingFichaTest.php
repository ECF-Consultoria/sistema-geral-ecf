<?php

namespace Tests\Feature;

use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Suíte de testes para o backend da Ficha de Onboarding (Phase 33 Plan 02).
 *
 * Cobre: ONB-01 (lista + filtros), ONB-04 (validação de enum), ONB-05 (props da ficha),
 * ONB-06 (save por bloco) e controle de acesso (T-33-04).
 *
 * @group phase33
 */
class Phase33OnboardingFichaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarConsultorSemPublicacao(): User
    {
        return User::factory()->create([
            'role'                    => 'consultor',
            'publication_role'        => null,
            'publication_permissions' => null,
        ]);
    }

    private function criarEmpresaComImpl(array $empresaOpts = [], array $implOpts = []): array
    {
        $empresa = MlbEmpresa::create(array_merge([
            'nome'       => 'Empresa Teste ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => 1,
        ], $empresaOpts));

        $impl = MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ], $implOpts));

        return [$empresa, $impl];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TASK 1 — ficha() + filtros Polo/Fase no index() + rotas
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET ficha retorna 200 com props impl, empresa e opcoes.
     */
    public function test_ficha_retorna_props_impl_empresa_e_opcoes(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl([
            'nome'    => 'Loja Arapongas',
            'cust_id' => 'CUST-001',
            'polo'    => 'Arapongas',
            'fase'    => 'M2',
        ], [
            'data_solicitacao'   => '2026-05-01',
            'acesso_colaborador' => 'Com acesso',
        ]);

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.ficha', $impl))
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/OnboardingFicha')
                ->has('impl', fn(Assert $prop) => $prop
                    ->has('id')
                    ->has('acesso_colaborador')
                    ->has('gmail_colaborador')
                    ->has('grupo_whatsapp')
                    ->has('planilha_produtos')
                    ->has('listagem')
                    ->has('publicacao')
                    ->has('decola')
                    ->has('contextos_logistica')
                    ->has('me1')
                    ->has('integradora')
                    ->has('places')
                    ->has('erp')
                    ->etc()
                )
                ->has('empresa', fn(Assert $prop) => $prop
                    ->where('nome', 'Loja Arapongas')
                    ->where('cust_id', 'CUST-001')
                    ->where('polo', 'Arapongas')
                    ->where('fase', 'M2')
                    ->has('data_solicitacao')
                    ->etc()
                )
                ->has('opcoes', fn(Assert $prop) => $prop
                    ->has('polo')
                    ->has('fase')
                    ->has('acesso_colaborador')
                    ->has('planilha_produtos')
                    ->has('listagem')
                    ->has('publicacao')
                    ->has('me1')
                    ->has('integradora')
                    ->has('places')
                    ->has('erp')
                    ->etc()
                )
            );
    }

    /**
     * GET index com filtro polo retorna apenas empresas do polo informado.
     */
    public function test_index_filtra_por_polo(): void
    {
        $admin = $this->criarAdmin();

        [$emp1] = $this->criarEmpresaComImpl(['polo' => 'Arapongas',       'nome' => 'Emp Arapongas']);
        [$emp2] = $this->criarEmpresaComImpl(['polo' => 'S. J. Rio Preto', 'nome' => 'Emp RioPreto']);

        $response = $this->actingAs($admin)
            ->get(route('mlb.implementacao.index', ['polo' => 'Arapongas']));

        $response->assertInertia(fn(Assert $page) => $page
            ->component('Mlb/Implementacao')
            ->has('empresas', 1)                       // só 1 empresa com polo Arapongas
            ->where('empresas.0.nome', 'Emp Arapongas')
        );
    }

    /**
     * GET index com filtro fase retorna apenas empresas da fase informada.
     */
    public function test_index_filtra_por_fase(): void
    {
        $admin = $this->criarAdmin();

        [$emp1] = $this->criarEmpresaComImpl(['fase' => 'M2', 'nome' => 'Emp M2']);
        [$emp2] = $this->criarEmpresaComImpl(['fase' => 'M3', 'nome' => 'Emp M3']);

        $this->actingAs($admin)
            ->get(route('mlb.implementacao.index', ['fase' => 'M2']))
            ->assertInertia(fn(Assert $page) => $page
                ->component('Mlb/Implementacao')
                ->has('empresas', 1)                   // só 1 empresa com fase M2
                ->where('empresas.0.nome', 'Emp M2')
            );
    }

    /**
     * Usuário consultor sem publication_role recebe 403 na ficha.
     */
    public function test_consultor_sem_publication_role_recebe_403_na_ficha(): void
    {
        $consultor = $this->criarConsultorSemPublicacao();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($consultor)
            ->get(route('mlb.implementacao.ficha', $impl))
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TASK 2 — 4 saves por bloco
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * PATCH bloco/acessos persiste acesso_colaborador sem alterar campos de outros blocos.
     */
    public function test_patch_bloco_acessos_persiste_subconjunto(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl([], [
            'me1' => 'Ativo',  // campo de outro bloco — deve permanecer intacto
        ]);

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.acessos', $impl), [
                'acesso_colaborador' => 'Com acesso',
                'gmail_colaborador'  => 'colab@ecf.com',
                'grupo_whatsapp'     => true,
            ])
            ->assertRedirect();

        $impl->refresh();
        $this->assertEquals('Com acesso', $impl->acesso_colaborador);
        $this->assertEquals('colab@ecf.com', $impl->gmail_colaborador);
        $this->assertTrue((bool) $impl->grupo_whatsapp);
        // campo de outro bloco não alterado
        $this->assertEquals('Ativo', $impl->me1);
    }

    /**
     * PATCH bloco/identificacao grava fase e polo em mlb_empresas (NÃO em mlb_implementacoes).
     */
    public function test_patch_bloco_identificacao_grava_fase_polo_em_empresa(): void
    {
        $admin = $this->criarAdmin();
        [$empresa, $impl] = $this->criarEmpresaComImpl(['fase' => 'M1', 'polo' => 'Arapongas']);

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.identificacao', $impl), [
                'polo'             => 'S. J. Rio Preto',
                'fase'             => 'M3',
                'data_solicitacao' => '2026-06-01',
            ])
            ->assertRedirect();

        // Verifica em mlb_empresas
        $empresa->refresh();
        $this->assertEquals('M3', $empresa->fase);
        $this->assertEquals('S. J. Rio Preto', $empresa->polo);

        // Verifica data_solicitacao na implementação
        $impl->refresh();
        $this->assertEquals('2026-06-01', $impl->data_solicitacao->format('Y-m-d'));
    }

    /**
     * PATCH bloco/produtos com listagem fora do enum retorna erro de validação.
     * Inertia retorna 302 (redirect de volta) com errors na sessão — não 422.
     */
    public function test_patch_bloco_produtos_valor_invalido_retorna_422(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.produtos', $impl), [
                'listagem' => 'valor_invalido_que_nao_existe',
            ])
            ->assertSessionHasErrors(['listagem']);
    }

    /**
     * PATCH bloco/logistica com me1 fora do enum retorna erro de validação.
     */
    public function test_patch_bloco_logistica_me1_invalido_retorna_422(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.logistica', $impl), [
                'me1' => 'opcao_inexistente',
            ])
            ->assertSessionHasErrors(['me1']);
    }

    /**
     * grupo_whatsapp (bloco acessos) e decola (bloco produtos) aceitam booleano e persistem.
     */
    public function test_grupo_whatsapp_e_decola_persistem_como_boolean(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        // Testa grupo_whatsapp via bloco acessos — string "1" deve virar boolean true
        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.acessos', $impl), [
                'grupo_whatsapp' => '1',
            ])
            ->assertRedirect();

        $impl->refresh();
        $this->assertTrue((bool) $impl->grupo_whatsapp);

        // Testa decola via bloco produtos — string "true" deve virar boolean true
        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.produtos', $impl), [
                'decola' => 'true',
            ])
            ->assertRedirect();

        $impl->refresh();
        $this->assertTrue((bool) $impl->decola);
    }

    /**
     * PATCH bloco/logistica com valores válidos persiste corretamente.
     */
    public function test_patch_bloco_logistica_persiste_campos(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.logistica', $impl), [
                'me1'                 => 'Ativo',
                'integradora'         => 'Frenet',
                'places'              => 'Não',
                'erp'                 => 'Bling',
                'contextos_logistica' => 'Texto de contexto livre',
            ])
            ->assertRedirect();

        $impl->refresh();
        $this->assertEquals('Ativo', $impl->me1);
        $this->assertEquals('Frenet', $impl->integradora);
        $this->assertEquals('Não', $impl->places);
        $this->assertEquals('Bling', $impl->erp);
        $this->assertEquals('Texto de contexto livre', $impl->contextos_logistica);
    }

    /**
     * PATCH bloco/produtos persiste campos corretamente.
     */
    public function test_patch_bloco_produtos_persiste_campos(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.produtos', $impl), [
                'planilha_produtos' => 'Já enviado',
                'listagem'          => 'Já listado',
                'publicacao'        => 'Concluído',
                'decola'            => false,
            ])
            ->assertRedirect();

        $impl->refresh();
        $this->assertEquals('Já enviado', $impl->planilha_produtos);
        $this->assertEquals('Já listado', $impl->listagem);
        $this->assertEquals('Concluído', $impl->publicacao);
        $this->assertFalse((bool) $impl->decola);
    }

    /**
     * Padrões Globais expõem mensagem de boas-vindas e grants por polo por padrão.
     */
    public function test_padroes_expoem_mensagem_e_grants_padrao(): void
    {
        $p = MlbConfiguracao::implementacaoPadroes();

        $this->assertStringContainsString('{link_formulario}', $p['mensagem_boas_vindas']);
        $this->assertStringContainsString('{link_grant}', $p['mensagem_boas_vindas']);
        $this->assertStringContainsString('{projeto_grant}', $p['mensagem_boas_vindas']);

        // Os 4 polos de ONB_POLO_OPCOES têm Grant configurado com url + nome
        foreach (MlbImplementacao::ONB_POLO_OPCOES as $polo) {
            $this->assertArrayHasKey($polo, $p['grants_por_polo']);
            $this->assertNotEmpty($p['grants_por_polo'][$polo]['url']);
            $this->assertNotEmpty($p['grants_por_polo'][$polo]['nome']);
        }
        // Bento Gonçalves carrega o Grant da Serra Gaúcha (sem renomear o polo)
        $this->assertEquals('Projeto Polos - Serra Gaúcha', $p['grants_por_polo']['Bento Gonçalves']['nome']);
    }

    /**
     * POST padrões persiste mensagem de boas-vindas e grants por polo editados.
     */
    public function test_salvar_padroes_persiste_mensagem_e_grants(): void
    {
        $admin = $this->criarAdmin();

        $this->actingAs($admin)
            ->post(route('mlb.implementacao.padroes'), [
                'mensagem_boas_vindas' => 'Olá {empresa}! Formulário: {link_formulario} — Grant: {link_grant}',
                'grants_por_polo'      => [
                    'Arapongas' => ['url' => 'https://partners.mercadolivre.com.br/auth/NOVO', 'nome' => 'Grant Arapongas Editado'],
                ],
            ])
            ->assertRedirect();

        $p = MlbConfiguracao::implementacaoPadroes();
        $this->assertStringContainsString('Olá {empresa}!', $p['mensagem_boas_vindas']);
        $this->assertEquals('https://partners.mercadolivre.com.br/auth/NOVO', $p['grants_por_polo']['Arapongas']['url']);
        $this->assertEquals('Grant Arapongas Editado', $p['grants_por_polo']['Arapongas']['nome']);
        // Polos não enviados mantêm o default via merge recursivo do base
        $this->assertEquals('Projeto Polos - Serra Gaúcha', $p['grants_por_polo']['Bento Gonçalves']['nome']);
    }
}
