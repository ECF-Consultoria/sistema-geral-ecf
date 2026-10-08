<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\Quantidades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-13: a porta HTTP da tela de sugestões no Portal — quem entra, a
 * empresa sempre da sessão (id/chave de outra empresa não vaza nem cria nada),
 * os limites de validação, o throttle isolado e a allowlist do domínio do
 * cliente linha a linha.
 */
class AcessoAsSugestoesTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** As 6 rotas do contrato (nome => [método, prefixo do throttle]). */
    private const ROTAS = [
        'portal.auth.estrutura.sugestoes'           => ['GET', 'estrutura.sugestoes'],
        'portal.auth.estrutura.sugestoes.aceitar'   => ['POST', 'estrutura.sugestoes.aceitar'],
        'portal.auth.estrutura.sugestoes.descartar' => ['POST', 'estrutura.sugestoes.descartar'],
        'portal.auth.estrutura.sugestoes.restaurar' => ['POST', 'estrutura.sugestoes.restaurar'],
        'portal.auth.estrutura.sugestoes.geracao'   => ['PUT', 'estrutura.sugestoes.geracao'],
        'portal.auth.estrutura.sugestoes.frete'     => ['POST', 'estrutura.sugestoes.frete'],
    ];

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function entrarComoEquipe(User $membro, Company $empresa): static
    {
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]));

        return $this;
    }

    /** Chave da composição pelos códigos de variação do catálogo sintético. */
    private function chave(array $cat, array $porCodigo): string
    {
        $mapa = [];
        foreach ($porCodigo as $codigo => $q) {
            $mapa[$cat['variacoes'][$codigo]] = $q;
        }

        return ChaveDeComposicao::de($mapa);
    }

    /** @return array{0: Company, 1: array} */
    private function cenario(): array
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();

        return [$empresa, $this->catalogoSintetico($empresa, $this->atorCliente($empresa))];
    }

    // ─── Sessão e página ────────────────────────────────────────────────────

    public function test_sem_sessao_a_pagina_e_o_aceite_mandam_para_a_entrada(): void
    {
        $this->get(route('portal.auth.estrutura.sugestoes'))->assertRedirect();
        $this->post(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => [['chave' => 'v1*1+v2*1']]])->assertRedirect();
    }

    public function test_o_cliente_abre_a_pagina_com_as_props_do_contrato(): void
    {
        [$empresa] = $this->cenario();

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.sugestoes'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaSugestoes', false)
                ->has('sugestoes.contagens')
                ->has('sugestoes.limites')
                ->has('sugestoes.resumo')
                ->has('sugestoes.por_status')
                ->has('sugestoes.familia_ambientes')
                ->has('sugestoes.gerado_em')
                ->where('filtros.aba', 'sugestoes')
                ->where('ml_conectado', false)
                ->has('frete_tabela')
                ->where('vocabulario.fases.combit', 'Combit')
                ->has('vocabulario.logisticas')
                ->where('modulos', fn ($m) => collect(collect($m)->firstWhere('chave', 'estrutura')['submodulos'])->firstWhere('ativo', true)['chave'] === 'produtos')
            );
    }

    public function test_os_filtros_chegam_normalizados_e_aba_desconhecida_vira_sugestoes(): void
    {
        [$empresa] = $this->cenario();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura.sugestoes', ['aba' => 'descartadas', 'fase' => 'kit', 'familia' => 'sem', 'tipo' => 'cadeira', 'status' => 'com_aviso', 'q' => 'x']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaSugestoes', false)
                ->where('filtros', ['aba' => 'descartadas', 'fase' => 'kit', 'familia' => 'sem', 'tipo' => 'cadeira', 'status' => 'com_aviso', 'q' => 'x', 'combos' => []]));

        // 08/10: famílias com Combos expandidos, "12,sem"; lixo fica de fora.
        $sessao->get(route('portal.auth.estrutura.sugestoes', ['combos' => '12,sem,../x,9999999999999']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filtros.combos', ['12', 'sem']));

        $sessao->get(route('portal.auth.estrutura.sugestoes', ['aba' => 'lixo', 'fase' => 'zzz', 'tipo' => 'Inválido!', 'status' => 'lixo']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaSugestoes', false)
                ->where('filtros.aba', 'sugestoes')
                ->where('filtros.fase', null)
                ->where('filtros.tipo', null)
                ->where('filtros.status', null));

        $sessao->get(route('portal.auth.estrutura.sugestoes'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filtros.status', null));
    }

    public function test_a_equipe_abre_a_pagina_e_aceita_com_origem_interno(): void
    {
        [$empresa, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);

        $sessao = $this->withoutVite()->entrarComoEquipe($this->admin(), $empresa);
        $sessao->get(route('portal.auth.estrutura.sugestoes'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaSugestoes', false));

        $sessao->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => [['chave' => $chave]]])
            ->assertOk()->assertJsonCount(1, 'criadas');

        $log = Activity::query()->where('properties->evento', 'sugestoes_aceitas')->latest('id')->firstOrFail();
        $this->assertSame('interno', $log->properties['origem']);
    }

    public function test_o_cliente_aceita_com_origem_cliente(): void
    {
        [$empresa, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);

        $this->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), ['sugestoes' => [['chave' => $chave]]])
            ->assertOk()->assertJsonStructure(['criadas', 'ja_existiam', 'erros']);

        $log = Activity::query()->where('properties->evento', 'sugestoes_aceitas')->latest('id')->firstOrFail();
        $this->assertSame('cliente', $log->properties['origem']);
    }

    // ─── Empresa sempre da sessão ───────────────────────────────────────────

    public function test_componentes_do_corpo_sao_ignorados_e_chave_de_outra_empresa_nao_cria(): void
    {
        [$minha, $cat] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $catAlheio = $this->catalogoSintetico($outra, $this->atorCliente($outra));
        $chaveMinha = $this->chave($cat, ['V101' => 1, 'V201' => 4]);
        $chaveAlheia = $this->chave($catAlheio, ['V101' => 1, 'V201' => 4]);
        $ofertaAlheia = $catAlheio['ofertas']['V101'];
        $antesDaOutra = EstruturaOferta::where('company_id', $outra->id)->count();

        $r = $this->entrarNoPortal($minha)->postJson(route('portal.auth.estrutura.sugestoes.aceitar'), [
            'company_id' => $outra->id,
            'sugestoes'  => [
                ['chave' => $chaveMinha, 'componentes' => [['id' => $ofertaAlheia, 'quantidade' => 9]]],
                ['chave' => $chaveAlheia],
            ],
        ])->assertOk();

        $r->assertJsonCount(1, 'criadas');
        $this->assertSame([$chaveAlheia], $r->json('ja_existiam'));

        $oferta = EstruturaOferta::findOrFail($r->json('criadas.0.oferta_id'));
        $this->assertSame($minha->id, (int) $oferta->company_id);
        $this->assertSame(
            [$cat['ofertas']['V101'] => 1, $cat['ofertas']['V201'] => 4],
            $oferta->componentes()->pluck('quantidade', 'componente_id')->all()
        );
        $this->assertSame($antesDaOutra, EstruturaOferta::where('company_id', $outra->id)->count());
    }

    public function test_geracao_de_produto_de_outra_empresa_ou_invalido_responde_404(): void
    {
        [$minha] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $this->catalogoSintetico($outra, $this->atorCliente($outra));
        $alheio = EstruturaProduto::where('company_id', $outra->id)->firstOrFail();

        $sessao = $this->entrarNoPortal($minha);

        $sessao->putJson(route('portal.auth.estrutura.sugestoes.geracao', ['produto' => $alheio->id]), ['qtd_combo' => '2'])->assertNotFound();
        $sessao->putJson('/portal/estrutura/sugestoes/produtos/abc/geracao', ['qtd_combo' => '2'])->assertNotFound();
        $this->assertDatabaseMissing('estrutura_produto_geracao', ['produto_id' => $alheio->id]);
    }

    public function test_geracao_do_meu_produto_grava_e_quantidade_invalida_da_422(): void
    {
        [$minha] = $this->cenario();
        $meu = EstruturaProduto::where('company_id', $minha->id)->firstOrFail();
        $sessao = $this->entrarNoPortal($minha);

        $sessao->putJson(route('portal.auth.estrutura.sugestoes.geracao', ['produto' => $meu->id]), ['qtd_combo' => '2, 4', 'qtd_combit' => '0'])
            ->assertOk()
            ->assertJson(['produto_id' => $meu->id, 'qtd_combo' => '2, 4', 'qtd_combit' => '0']);

        $sessao->putJson(route('portal.auth.estrutura.sugestoes.geracao', ['produto' => $meu->id]), ['qtd_combo' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qtd_combo' => Quantidades::MENSAGEM]);
    }

    // ─── Descartar e restaurar ──────────────────────────────────────────────

    public function test_descartar_e_restaurar_pela_sessao(): void
    {
        [$minha, $cat] = $this->cenario();
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);
        $sessao = $this->entrarNoPortal($minha);

        $sessao->postJson(route('portal.auth.estrutura.sugestoes.descartar'), ['chaves' => [$chave]])
            ->assertOk()->assertJson(['descartadas' => 1]);
        $this->assertSame(1, EstruturaSugestaoDescartada::where('company_id', $minha->id)->count());

        $sessao->postJson(route('portal.auth.estrutura.sugestoes.restaurar'), ['chaves' => [$chave]])
            ->assertOk()->assertJsonStructure(['restauradas', 'ja_existem']);
        $this->assertSame(0, EstruturaSugestaoDescartada::where('company_id', $minha->id)->count());
    }

    // ─── Validação ──────────────────────────────────────────────────────────

    public function test_validacao_do_aceite(): void
    {
        [$minha, $cat] = $this->cenario();
        $sessao = $this->entrarNoPortal($minha);
        $rota = route('portal.auth.estrutura.sugestoes.aceitar');
        $chave = $this->chave($cat, ['V101' => 1, 'V201' => 4]);

        $cento_e_uma = array_fill(0, 101, ['chave' => $chave]);
        $sessao->postJson($rota, ['sugestoes' => $cento_e_uma])->assertStatus(422)->assertJsonValidationErrors('sugestoes');
        $sessao->postJson($rota, ['sugestoes' => []])->assertStatus(422);
        $sessao->postJson($rota, ['sugestoes' => [['chave' => 'abc']]])->assertStatus(422)->assertJsonValidationErrors('sugestoes.0.chave');
        $sessao->postJson($rota, ['sugestoes' => [['chave' => $chave.'+v9*1+v8*1']]])->assertStatus(422);
        $sessao->postJson($rota, ['sugestoes' => [['chave' => $chave, 'nome' => str_repeat('n', 256)]]])->assertStatus(422)->assertJsonValidationErrors('sugestoes.0.nome');
        $sessao->postJson($rota, ['sugestoes' => [['chave' => $chave, 'sku' => str_repeat('s', 256)]]])->assertStatus(422)->assertJsonValidationErrors('sugestoes.0.sku');
        $this->assertSame(0, EstruturaOferta::where('company_id', $minha->id)->where('fase', 'combit')->count());
    }

    public function test_validacao_de_descartar_restaurar_e_frete(): void
    {
        [$minha] = $this->cenario();
        $sessao = $this->entrarNoPortal($minha);

        foreach (['descartar', 'restaurar'] as $acao) {
            $rota = route('portal.auth.estrutura.sugestoes.'.$acao);
            $sessao->postJson($rota, ['chaves' => []])->assertStatus(422);
            $sessao->postJson($rota, ['chaves' => ['lixo']])->assertStatus(422);
            $sessao->postJson($rota, ['chaves' => array_fill(0, 101, 'v1*1+v2*1')])->assertStatus(422);
        }

        $frete = route('portal.auth.estrutura.sugestoes.frete');
        $sessao->postJson($frete, ['chaves' => []])->assertStatus(422);
        $sessao->postJson($frete, ['chaves' => array_fill(0, 21, 'v1*1+v2*1')])->assertStatus(422);
    }

    // ─── Mercado Livre: nada sai ────────────────────────────────────────────

    public function test_a_pagina_nao_faz_nenhuma_requisicao_ao_mercado_livre(): void
    {
        [$minha] = $this->cenario();

        $this->withoutVite()->entrarNoPortal($minha)
            ->get(route('portal.auth.estrutura.sugestoes'))->assertOk();

        $this->assertSame([], $this->enviadasAoMercadoLivre(), 'a página enviou requisição ao Mercado Livre');
    }

    public function test_o_frete_sem_conta_conectada_nao_envia_nada(): void
    {
        [$minha, $cat] = $this->cenario();

        $this->entrarNoPortal($minha)
            ->postJson(route('portal.auth.estrutura.sugestoes.frete'), ['chaves' => [$this->chave($cat, ['V101' => 1, 'V201' => 4])]])
            ->assertOk()
            ->assertJson(['conectado' => false])
            ->assertJsonStructure(['fretes', 'conectado', 'falhou', 'pendentes']);

        $this->assertSame([], $this->enviadasAoMercadoLivre(), 'o frete sem conta enviou requisição ao Mercado Livre');
    }

    /**
     * URLs enviadas ao ML. O contexto compartilhado do portal consulta o ECF Drive
     * (sinais) em toda página; isso não é o ML e fica de fora da conta.
     *
     * @return list<string>
     */
    private function enviadasAoMercadoLivre(): array
    {
        $urls = [];
        foreach (Http::recorded() as [$requisicao]) {
            if (preg_match('/mercadoli(vre|bre)\.com/i', $requisicao->url()) === 1) {
                $urls[] = $requisicao->method().' '.$requisicao->url();
            }
        }

        return $urls;
    }

    // ─── Rotas, throttle e allowlist ────────────────────────────────────────

    public function test_as_6_rotas_estao_no_portal_auth_com_throttle_de_prefixo_proprio(): void
    {
        $contagemDePrefixos = [];
        foreach (Route::getRoutes() as $rota) {
            $t = collect($rota->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            if ($t !== null && count($p = explode(',', substr($t, 9))) === 3) {
                $contagemDePrefixos[$p[2]] = ($contagemDePrefixos[$p[2]] ?? 0) + 1;
            }
        }

        foreach (self::ROTAS as $nome => [$metodo, $prefixo]) {
            $rota = Route::getRoutes()->getByName($nome);
            $this->assertNotNull($rota, "{$nome} não existe");
            $this->assertContains($metodo, $rota->methods(), "{$nome} com método errado");
            $this->assertContains('portal.auth', $rota->gatherMiddleware(), "{$nome} fora do portal.auth");

            $throttle = collect($rota->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertNotNull($throttle, "{$nome} sem throttle");
            $this->assertSame($prefixo, explode(',', substr($throttle, 9))[2] ?? null, "{$nome} com prefixo trocado");
            $this->assertSame(1, $contagemDePrefixos[$prefixo], "{$nome}: o prefixo '{$prefixo}' não é único na aplicação");
        }
    }

    public function test_a_allowlist_libera_cada_rota_e_nega_o_resto(): void
    {
        foreach ([
            'portal/estrutura/sugestoes', 'portal/estrutura/sugestoes/aceitar', 'portal/estrutura/sugestoes/descartar',
            'portal/estrutura/sugestoes/restaurar', 'portal/estrutura/sugestoes/frete',
            'portal/estrutura/sugestoes/produtos/12/geracao',
        ] as $caminho) {
            $this->assertTrue(RestringeDominioDoPortal::liberado($caminho), "{$caminho} deveria estar liberado");
        }

        foreach ([
            'portal/estrutura/sugestoes/qualquer', 'portal/estrutura/sugestoes/produtos/abc/geracao',
            'portal/estrutura/sugestoes/produtos/1/2/geracao', 'portal/estrutura/sugestoes/produtos/12',
            'portal/estrutura/sugestoes/produtos/12/geracao/x', 'portal/estrutura/sugestoes/aceitar/x',
        ] as $caminho) {
            $this->assertFalse(RestringeDominioDoPortal::liberado($caminho), "{$caminho} deveria estar barrado");
        }
    }

    public function test_a_allowlist_nao_tem_curinga_sobre_as_sugestoes(): void
    {
        $permitido = (new \ReflectionClass(RestringeDominioDoPortal::class))->getConstant('PERMITIDO');
        $this->assertNotContains('portal/estrutura/sugestoes/*', $permitido);
        $this->assertCount(5, array_filter($permitido, fn ($p) => str_starts_with($p, 'portal/estrutura/sugestoes')));
        $this->assertContains(
            'portal/estrutura/sugestoes/produtos/{id}/geracao',
            (new \ReflectionClass(RestringeDominioDoPortal::class))->getConstant('PERMITIDO_COM_ID')
        );
    }
}
