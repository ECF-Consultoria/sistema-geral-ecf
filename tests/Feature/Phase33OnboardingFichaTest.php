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
     * Os campos criáveis não são mais enum fechado (ver test_blocos_aceitam_valor_criado_fora_do_catalogo),
     * mas continuam LIMITADOS: texto acima do máximo é rejeitado.
     */
    public function test_patch_bloco_produtos_valor_longo_demais_e_rejeitado(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.produtos', $impl), [
                'listagem' => str_repeat('a', 151),
                'decola'   => str_repeat('b', 61),
            ])
            ->assertSessionHasErrors(['listagem', 'decola']);
    }

    /**
     * Idem para o bloco Logística.
     */
    public function test_patch_bloco_logistica_valor_longo_demais_e_rejeitado(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.logistica', $impl), [
                'me1' => str_repeat('a', 151),
            ])
            ->assertSessionHasErrors(['me1']);
    }

    /**
     * grupo_whatsapp (bloco acessos) aceita booleano e persiste.
     */
    public function test_grupo_whatsapp_persiste_como_boolean(): void
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
    }

    /**
     * link_whatsapp (bloco acessos, quick 260810-dv6) — coluna "Link do Whats" do Painel
     * Polos. Guarda o texto COMO FOI DIGITADO (convite, wa.me ou sem protocolo: a UI
     * normaliza só o href), aceita limpar com null e rejeita acima de 255.
     */
    public function test_link_whatsapp_persiste_como_texto(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        foreach (['https://chat.whatsapp.com/ABC123xyz', 'chat.whatsapp.com/SemProtocolo'] as $valor) {
            $this->actingAs($admin)
                ->patch(route('mlb.implementacao.bloco.acessos', $impl), ['link_whatsapp' => $valor])
                ->assertRedirect();

            $impl->refresh();
            $this->assertSame($valor, $impl->link_whatsapp);
        }

        // Limpar o campo (célula esvaziada no painel manda null).
        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.acessos', $impl), ['link_whatsapp' => null])
            ->assertRedirect();
        $this->assertNull($impl->refresh()->link_whatsapp);

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.acessos', $impl), [
                'link_whatsapp' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors(['link_whatsapp']);
    }

    /**
     * decola virou TEXTO (era boolean): aceita o catálogo ONB_DECOLA_OPCOES, incluindo o
     * terceiro estado "Mensagem Enviada".
     */
    public function test_decola_persiste_como_texto(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        foreach (MlbImplementacao::ONB_DECOLA_OPCOES as $valor) {
            $this->actingAs($admin)
                ->patch(route('mlb.implementacao.bloco.produtos', $impl), [
                    'decola' => $valor,
                ])
                ->assertRedirect();

            $impl->refresh();
            $this->assertSame($valor, $impl->decola);
        }
    }

    /**
     * "＋ Criar novo valor" do Painel Polos: os selects do onboarding aceitam valor FORA do
     * catálogo. Antes os blocos validavam com Rule::in(ONB_*_OPCOES) e devolviam 422 — o
     * valor criado inline nunca persistia (e o Inertia não mostrava erro na grade).
     */
    public function test_blocos_aceitam_valor_criado_fora_do_catalogo(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.produtos', $impl), [
                'planilha_produtos' => 'Enviada pela metade',
                'listagem'          => 'Listando aos poucos',
                'publicacao'        => 'Em revisão do cliente',
                'decola'            => 'Aguardando ML',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.logistica', $impl), [
                'me1'         => 'Coleta agendada',
                'integradora' => 'Transportadora do cliente',
                'places'      => 'Analisando praça',
                'erp'         => 'ERP proprietário',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.acessos', $impl), [
                'acesso_colaborador' => 'Acesso parcial',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $impl->refresh();
        $this->assertSame('Enviada pela metade', $impl->planilha_produtos);
        $this->assertSame('Listando aos poucos', $impl->listagem);
        $this->assertSame('Em revisão do cliente', $impl->publicacao);
        $this->assertSame('Aguardando ML', $impl->decola);
        $this->assertSame('Coleta agendada', $impl->me1);
        $this->assertSame('Transportadora do cliente', $impl->integradora);
        $this->assertSame('Analisando praça', $impl->places);
        $this->assertSame('ERP proprietário', $impl->erp);
        $this->assertSame('Acesso parcial', $impl->acesso_colaborador);
    }

    /**
     * `fase` continua sendo domínio FECHADO: alimenta MlbEmpresa::FASE_PARA_PROJETO e uma
     * fase inventada tiraria a empresa do projeto POLOS sem aviso.
     */
    public function test_fase_rejeita_valor_fora_do_catalogo(): void
    {
        $admin = $this->criarAdmin();
        [$_empresa, $impl] = $this->criarEmpresaComImpl();

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.identificacao', $impl), [
                'fase' => 'M9 turbinado',
            ])
            ->assertSessionHasErrors('fase');
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
                'decola'            => 'Não',
            ])
            ->assertRedirect();

        $impl->refresh();
        $this->assertEquals('Já enviado', $impl->planilha_produtos);
        $this->assertEquals('Já listado', $impl->listagem);
        $this->assertEquals('Concluído', $impl->publicacao);
        $this->assertSame('Não', $impl->decola);
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
