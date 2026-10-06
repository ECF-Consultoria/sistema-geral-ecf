<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Acoes\CatalogoDeAcoes;
use App\Models\Chamado;
use App\Models\DevDemanda;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\DemandasDev\LiberaModulosDev;
use Tests\TestCase;

/**
 * `listar_acoes` + `enviar_formulario`: qualquer formulário do Admin pelo MCP
 * (decisão de 06/10/2026: "pode editar o que quiser", gravando direto), com a
 * validação, a permissão e o controller da própria tela.
 */
class EnviarFormularioToolTest extends TestCase
{
    use ChamaMcp, LiberaModulosDev, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Notification::fake();
        $this->withoutVite();
        $this->liberarModulosDev();
    }

    /** `enviar_formulario` responde só em texto (JSON). */
    private function enviar(User $u, array $args): array
    {
        $r = $this->rpc($u, 'tools/call', ['name' => 'enviar_formulario', 'arguments' => (object) $args])->assertOk()->json('result');
        $this->assertFalse($r['isError'] ?? false, 'enviar_formulario deu erro: '.($r['content'][0]['text'] ?? ''));

        return json_decode($r['content'][0]['text'], true);
    }

    private function novaDemanda(array $extra = []): array
    {
        return $extra + ['prefixo' => 'DEV', 'titulo' => 'Pelo formulário genérico', 'prioridade' => 2, 'data_entrada' => '2026-10-06'];
    }

    // ═══ Catálogo ═══

    public function test_catalogo_tem_os_formularios_e_deixa_de_fora_o_bloqueado(): void
    {
        $acoes = app(CatalogoDeAcoes::class)->todas()->pluck('acao');

        foreach (['chamados.store', 'dev.demandas.store', 'dev.demandas.update', 'users.destroy'] as $existe) {
            $this->assertContains($existe, $acoes);
        }
        foreach (['logout', 'password.update', 'profile.update', 'profile.destroy', 'google.disconnect', 'ml.oauth.disconnect', 'shopee.oauth.disconnect'] as $fora) {
            $this->assertNotContains($fora, $acoes, "{$fora} não podia estar no MCP");
        }
        foreach ($acoes as $acao) {
            $this->assertStringStartsNotWith('mlb.anuncios.', $acao, 'anúncio do ML não passa pelo MCP');
            $this->assertDoesNotMatchRegularExpression('/(oauth|mcp|export)/i', $acao);
        }
    }

    public function test_todo_bloqueio_aponta_para_rota_que_existe(): void
    {
        // Se alguém renomear a rota, o bloqueio pararia de valer calado.
        $nomes = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->values();

        foreach (CatalogoDeAcoes::BLOQUEADAS as $regra) {
            $existe = str_ends_with($regra, '.*')
                ? $nomes->contains(fn ($n) => str_starts_with($n, substr($regra, 0, -1)))
                : $nomes->contains($regra);
            $this->assertTrue($existe, "Bloqueio \"{$regra}\" não casa com nenhuma rota");
        }
    }

    public function test_listar_acoes_filtra_e_mostra_os_campos_da_validacao(): void
    {
        $admin = $this->admin();

        $itens = collect($this->ferramenta($admin, 'listar_acoes', ['busca' => 'dev/demandas', 'limite' => 100])['itens']);
        $this->assertContains('dev.demandas.store', $itens->pluck('acao'));

        // Validação inline no método.
        $detalhe = $this->ferramenta($admin, 'listar_acoes', ['acao' => 'chamados.store']);
        $this->assertSame('POST', $detalhe['metodo']);
        $this->assertStringContainsString("'titulo'", $detalhe['campos']);
        $this->assertStringContainsString("'descricao'", $detalhe['campos']);

        // Validação num método auxiliar ($this->validarDemanda()).
        $detalhe = $this->ferramenta($admin, 'listar_acoes', ['acao' => 'dev.demandas.update']);
        $this->assertSame(['demanda'], $detalhe['parametros']);
        $this->assertStringContainsString("'prioridade'", $detalhe['campos']);

        // DELETE avisa da confirmação.
        $this->assertStringContainsString('confirmo_exclusao', $this->ferramenta($admin, 'listar_acoes', ['acao' => 'users.destroy'])['como_usar']);
    }

    // ═══ Envio ═══

    public function test_grava_como_o_usuario_pelo_formulario_da_tela(): void
    {
        $admin = $this->admin();

        $r = $this->enviar($admin, ['acao' => 'dev.demandas.store', 'dados' => $this->novaDemanda()]);

        $demanda = DevDemanda::sole();
        $this->assertSame($admin->id, $demanda->criado_por);
        $this->assertSame('POST', $r['metodo']);
        $this->assertStringContainsString($demanda->codigo, $r['mensagem']);

        // Edição pelo PUT, com parâmetro de endereço.
        $this->enviar($admin, [
            'acao'       => 'dev.demandas.update',
            'parametros' => ['demanda' => $demanda->id],
            'dados'      => ['titulo' => 'Título novo', 'prioridade' => 1, 'data_entrada' => '2026-10-06'],
        ]);
        $this->assertSame('Título novo', $demanda->fresh()->titulo);
    }

    public function test_validacao_da_tela_volta_com_os_campos(): void
    {
        $erro = $this->erroDaFerramenta($this->admin(), 'enviar_formulario', ['acao' => 'dev.demandas.store', 'dados' => ['prefixo' => 'DEV']]);

        $this->assertStringContainsString('titulo', $erro);
        $this->assertSame(0, DevDemanda::count());
    }

    public function test_perfil_sem_permissao_na_tela_nao_grava(): void
    {
        // Módulo liberado (a prévia deixa passar), mas o controller só aceita admin.
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $erro = $this->erroDaFerramenta($consultor, 'enviar_formulario', ['acao' => 'dev.demandas.store', 'dados' => $this->novaDemanda()]);

        $this->assertStringContainsString('não pode', $erro);
        $this->assertSame(0, DevDemanda::count());
    }

    public function test_acao_bloqueada_nao_existe_para_o_mcp(): void
    {
        $admin = $this->admin();

        foreach (['logout', 'profile.destroy', 'password.update', 'mlb.anuncios.publicar'] as $acao) {
            $erro = $this->erroDaFerramenta($admin, 'enviar_formulario', ['acao' => $acao]);
            $this->assertStringContainsString('fora do MCP', $erro, $acao);
        }
        $this->assertNotNull($admin->fresh(), 'a conta continua lá');
    }

    public function test_exclusao_so_com_confirmacao_e_recusa_da_tela_vira_erro(): void
    {
        $admin = $this->admin();
        $alvo  = User::factory()->create(['name' => 'Conta de teste', 'active' => true]);

        $erro = $this->erroDaFerramenta($admin, 'enviar_formulario', ['acao' => 'users.destroy', 'parametros' => ['user' => $alvo->id]]);
        $this->assertStringContainsString('confirmo_exclusao', $erro);
        $this->assertNotSoftDeleted($alvo);

        $r = $this->enviar($admin, ['acao' => 'users.destroy', 'parametros' => ['user' => $alvo->id], 'confirmo_exclusao' => true]);
        $this->assertSoftDeleted($alvo);
        $this->assertStringContainsString('excluído', $r['mensagem']);

        // A tela recusa excluir a própria conta com `withErrors` — tem de
        // chegar como erro, não como "feito".
        $erro = $this->erroDaFerramenta($admin, 'enviar_formulario', ['acao' => 'users.destroy', 'parametros' => ['user' => $admin->id], 'confirmo_exclusao' => true]);
        $this->assertStringContainsString('própria conta', $erro);
        $this->assertNotSoftDeleted($admin);
    }

    public function test_tela_lida_logo_depois_de_gravar_ja_mostra_o_que_foi_gravado(): void
    {
        $admin = $this->admin();
        $ler   = fn () => json_decode($this->rpc($admin, 'tools/call', ['name' => 'ler_tela', 'arguments' => (object) [
            'tela' => 'dev.demandas.index', 'campo' => 'demandas',
        ]])->assertOk()->json('result.content.0.text'), true)['total'];

        $this->assertSame(0, $ler());

        $this->enviar($admin, ['acao' => 'dev.demandas.store', 'dados' => $this->novaDemanda()]);

        // Sem a troca de versão, viria o cache de 2 minutos com zero.
        $this->assertSame(1, $ler());
    }

    public function test_csrf_de_verdade_continua_valendo_e_o_mcp_passa_por_ele(): void
    {
        // Nos testes o Laravel pula o CSRF; aqui ele vale como em produção.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $gestor  = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $chamado = ['tipo' => 'outro', 'titulo' => 'Pelo navegador', 'descricao' => 'Sem token', 'impacto' => 'normal'];

        // Prova de que a checagem está ligada: formulário sem token → 419.
        $this->actingAs($gestor)->post('/tickets', $chamado)->assertStatus(419);
        $this->assertSame(0, Chamado::count());

        // Pelo MCP passa — com o token da sessão em memória, não com exceção.
        // Sessão em banco como em produção: a navegação troca para memória e
        // não deixa linha em `sessions`.
        config(['session.driver' => 'database']);
        $this->ferramenta($gestor, 'abrir_ticket', ['titulo' => 'Pelo MCP', 'descricao' => 'Com token']);
        $this->assertSame('Pelo MCP', Chamado::sole()->titulo);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('sessions')->count());
        $this->assertSame('database', config('session.driver'), 'o driver volta ao fim da navegação');
    }
}
