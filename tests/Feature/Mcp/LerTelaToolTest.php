<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mcp\Telas\CatalogoDeTelas;
use App\Mcp\Telas\LeitorDeDados;
use App\Models\McpAcesso;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `listar_telas` + `ler_tela`: qualquer tela de leitura, com os MESMOS dados
 * que a tela entrega ao navegador e o mesmo recorte de perfil.
 */
class LerTelaToolTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->withoutVite();
    }

    /** `ler_tela` responde só em texto (JSON) — sem structuredContent. */
    private function lerTela(User $u, array $args): array
    {
        $r = $this->rpc($u, 'tools/call', ['name' => 'ler_tela', 'arguments' => (object) $args])->assertOk()->json('result');
        $this->assertFalse($r['isError'] ?? false, 'ler_tela deu erro: '.($r['content'][0]['text'] ?? ''));

        return json_decode($r['content'][0]['text'], true);
    }

    private function erroLerTela(User $u, array $args): string
    {
        return $this->erroDaFerramenta($u, 'ler_tela', $args);
    }

    // ═══ Catálogo ═══

    public function test_catalogo_tem_as_telas_e_nao_tem_o_que_grava_ou_baixa(): void
    {
        $nomes = collect($this->ferramenta($this->admin(), 'listar_telas', ['limite' => 100])['itens'])->pluck('tela');
        $todas = app(CatalogoDeTelas::class)->todas()->pluck('tela');

        $this->assertContains('companies.index', $todas);
        $this->assertContains('companies.show', $todas);
        $this->assertContains('mlb.polos-painel', $todas);
        $this->assertContains('dashboard', $todas);
        $this->assertContains('companies.index', $nomes);

        foreach ($todas as $tela) {
            $this->assertFalse(CatalogoDeTelas::bloqueada($tela), "{$tela} está bloqueada e não podia estar no catálogo");
        }
        // As que gravam ao abrir (varredura de 05/10/2026) ficam fora.
        foreach (['admin.contratos.show', 'comercial.entrada.show', 'chamados.show', 'agenda.eventos', 'mlb.anuncios.publicador.alavancas.panorama'] as $grava) {
            $this->assertNotContains($grava, $todas);
        }
        // E as liberadas de propósito continuam.
        foreach (['mlb.implementacao.index', 'mlb.polos-painel', 'performance.index', 'nps.index'] as $liberada) {
            $this->assertContains($liberada, $todas);
        }
        foreach ($todas as $tela) {
            $this->assertDoesNotMatchRegularExpression('/(export|download|pdf|oauth|callback|logout|portal)/i', $tela);
        }
    }

    public function test_toda_rota_bloqueada_existe_de_verdade(): void
    {
        // Se alguém renomear a rota, o bloqueio pararia de valer calado.
        $nomes = collect(Route::getRoutes()->getRoutes())->map->getName()->filter();
        foreach (CatalogoDeTelas::BLOQUEADAS as $regra) {
            $existe = str_ends_with($regra, '.*')
                ? $nomes->contains(fn ($n) => str_starts_with($n, substr($regra, 0, -1)))
                : Route::has($regra);
            $this->assertTrue($existe, "Rota bloqueada {$regra} não existe mais — atualize CatalogoDeTelas::BLOQUEADAS");
        }
    }

    public function test_catalogo_ja_esconde_tela_que_o_perfil_nao_abre(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $telas = collect($this->ferramenta($consultor, 'listar_telas', ['busca' => 'painel-executivo'])['itens'])->pluck('tela');

        $this->assertNotContains('painel-executivo.index', $telas); // rota role:admin
        $this->assertStringContainsString('não está no seu perfil', $this->erroLerTela($consultor, ['tela' => 'painel-executivo.index']));
    }

    // ═══ Paridade com a tela ═══

    public function test_ler_tela_devolve_o_mesmo_que_a_tela_entrega_ao_navegador(): void
    {
        $analista = $this->comCargo($this->comPermissoes(['core.empresas']), 'analista');
        $minha    = $this->empresaPerformance(['name' => 'Loja do Analista']);
        $this->empresaPerformance(['name' => 'Loja de Outro']);
        $this->vincular($minha, $analista, 'consultor');

        $tela = $this->actingAs($analista)->get(route('companies.index'))->assertOk()->viewData('page')['props']['companies'];
        $mcp  = $this->lerTela($analista, ['tela' => 'companies.index', 'campo' => 'companies']);

        $this->assertSame('Companies/Index', $mcp['componente']);
        $this->assertSame(count($tela), $mcp['total']);
        $this->assertSame(collect($tela)->pluck('name')->all(), collect($mcp['itens'])->pluck('name')->all());
        $this->assertSame(['Loja do Analista'], collect($mcp['itens'])->pluck('name')->all());
    }

    public function test_resumo_sem_campo_tira_os_props_compartilhados_e_aponta_os_grandes(): void
    {
        $admin = $this->admin();
        foreach (range(1, 12) as $i) {
            $this->empresaPerformance();
        }

        $resumo = $this->lerTela($admin, ['tela' => 'companies.index']);

        foreach (['auth', 'flash', 'csrf_token', 'sugadores_pendentes'] as $compartilhado) {
            $this->assertArrayNotHasKey($compartilhado, $resumo['dados']);
            $this->assertArrayNotHasKey($compartilhado, $resumo['campos_grandes'] ?? []);
        }
        $this->assertArrayHasKey('companies', $resumo['campos_grandes']);
        $this->assertSame('lista', $resumo['campos_grandes']['companies']['tipo']);
        $this->assertSame(12, $resumo['campos_grandes']['companies']['itens']);
    }

    public function test_campo_com_caminho_e_paginacao(): void
    {
        $admin = $this->admin();
        $this->empresaPerformance(['name' => 'Alfa']);
        $this->empresaPerformance(['name' => 'Beta']);

        $p1 = $this->lerTela($admin, ['tela' => 'companies.index', 'campo' => 'companies', 'limite' => 1]);
        $this->assertCount(1, $p1['itens']);
        $this->assertNotNull($p1['proximo_cursor']);

        $p2 = $this->lerTela($admin, ['tela' => 'companies.index', 'campo' => 'companies', 'limite' => 1, 'cursor' => $p1['proximo_cursor']]);
        $this->assertNotSame($p1['itens'][0]['name'], $p2['itens'][0]['name']);

        $nome = $this->lerTela($admin, ['tela' => 'companies.index', 'campo' => 'companies.0.name']);
        $this->assertSame($p1['itens'][0]['name'], $nome['valor']);

        $this->assertStringContainsString('não existe', $this->erroLerTela($admin, ['tela' => 'companies.index', 'campo' => 'nao_tem']));
    }

    public function test_tela_com_parametro(): void
    {
        $empresa = $this->empresaPerformance(['name' => 'Ficha Completa']);
        $admin   = $this->admin();

        $this->assertStringContainsString('precisa de: company', $this->erroLerTela($admin, ['tela' => 'companies.show']));

        $ficha = $this->lerTela($admin, ['tela' => 'companies.show', 'parametros' => ['company' => $empresa->id], 'campo' => 'company.name']);
        $this->assertSame('Ficha Completa', $ficha['valor']);
        $this->assertSame('/companies/'.$empresa->id, $ficha['endereco']);
    }

    public function test_trava_dentro_do_controller_continua_valendo(): void
    {
        // Sem core.empresas e sem vínculo, o detalhe da empresa dá 403 na tela —
        // e pelo MCP também.
        $alheia    = $this->empresaPerformance();
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($consultor)->get(route('companies.show', $alheia))->assertForbidden();
        $this->assertStringContainsString('não tem acesso', $this->erroLerTela($consultor, ['tela' => 'companies.show', 'parametros' => ['company' => $alheia->id]]));
    }

    public function test_segue_o_redirecionamento_da_tela_quando_o_destino_tambem_e_tela(): void
    {
        // Membro do setor Polos que abre /dashboard é levado ao Painel Polos.
        $setor = Setor::firstOrCreate(['slug' => 'polos'], ['nome' => 'Polos', 'active' => true]);
        $membro = $this->comPermissoes(['mlb.projetos']);
        $setor->membros()->attach($membro->id, ['is_principal' => false, 'assigned_at' => now()]);

        $tela = $this->lerTela($membro->fresh(), ['tela' => 'dashboard']);

        $this->assertSame('mlb.polos-painel', $tela['tela']);
        $this->assertSame('Polos/Painel', $tela['componente']);
    }

    public function test_filtro_obrigatorio_que_faltou_vira_mensagem_com_os_campos(): void
    {
        // A prévia da hierarquia de grupos exige grupo_ids: sem ele a validação
        // "volta para a página anterior". O MCP pede de novo como JSON e
        // mostra o campo que falta.
        $erro = $this->erroLerTela($this->admin(), ['tela' => 'admin.contratos.grupos.hierarquia.previa']);

        $this->assertStringContainsString('recusou os filtros', $erro);
        $this->assertStringContainsString('grupo_ids', $erro);
    }

    public function test_log_registra_a_chamada_com_o_ip_da_chamada_e_nao_o_da_tela(): void
    {
        $admin = $this->admin();
        $this->lerTela($admin, ['tela' => 'companies.index', 'campo' => 'companies']);

        $log = McpAcesso::latest('id')->first();
        $this->assertSame('ler_tela', $log->ferramenta);
        $this->assertSame(['tela' => 'companies.index', 'campo' => 'companies'], $log->argumentos);
        $this->assertTrue($log->sucesso);
        $this->assertSame('127.0.0.1', $log->ip);
    }

    // ═══ Leitor ═══

    public function test_credencial_sai_oculta_e_dado_comum_sai_inteiro(): void
    {
        $leitor = new LeitorDeDados;
        $dados = $leitor->ocultarCredenciais([
            'empresa' => ['email_cliente' => 'dono@loja.com', 'telefone' => '1199', 'token' => 'link-do-cliente'],
            'conta'   => ['access_token' => 'APP_USR-123', 'refresh_token' => 'TG-1', 'client_secret' => 'x', 'api_key' => 'k'],
            'usuarios' => [['password' => 'hash', 'name' => 'Fulano']],
        ]);

        // Decisão do usuário: dado pessoal e link vêm como a tela mostra.
        $this->assertSame('dono@loja.com', $dados['empresa']['email_cliente']);
        $this->assertSame('link-do-cliente', $dados['empresa']['token']);
        // Credencial, não.
        $this->assertSame(['access_token' => '[oculto]', 'refresh_token' => '[oculto]', 'client_secret' => '[oculto]', 'api_key' => '[oculto]'], $dados['conta']);
        $this->assertSame(['password' => '[oculto]', 'name' => 'Fulano'], $dados['usuarios'][0]);
    }

    public function test_lista_que_nao_cabe_encolhe_a_pagina_em_vez_de_estourar(): void
    {
        $leitor = new LeitorDeDados;
        $grande = array_fill(0, 50, ['texto' => str_repeat('x', 5_000)]);

        $fatia = $leitor->fatiar($grande, 0, 50);

        $this->assertLessThan(50, $fatia['limite_usado']);
        $this->assertLessThanOrEqual(LeitorDeDados::BYTES_MAX, $leitor->bytes($fatia['itens']));
        $this->assertSame($fatia['limite_usado'], $fatia['proximo_deslocamento']);
    }

    public function test_lista_de_compartilhados_cobre_o_que_o_middleware_compartilha(): void
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session')->driver('array'));
        $request->setUserResolver(fn () => null);

        $chaves = array_keys(app(HandleInertiaRequests::class)->share($request));

        $this->assertSame([], array_values(array_diff($chaves, LeitorDeDados::COMPARTILHADOS)),
            'HandleInertiaRequests ganhou prop compartilhado novo — acrescente em LeitorDeDados::COMPARTILHADOS');
    }
}
