<?php

namespace Tests\Feature\PortalCliente;

use App\Models\EstruturaPublicacao;
use App\Models\PubRascunho;
use App\Support\Portal\ModulosPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Critério 7 do ROADMAP da Fase 164 (D18): o Anunciar saiu do Portal do
 * Cliente, para todos os clientes. A publicação é feita pela equipe ECF no
 * Publicador interno (`/mlb/anuncios/publicador`).
 *
 * Os testes de funcionalidade que existiam aqui (`AnunciarEstruturaTest`,
 * `PortalPublicadorTest`) foram removidos junto com o código; os casos que
 * continuam valendo vivem em `Tests\Feature\Publicador\*`.
 */
class PortalSemAnunciarTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const DOMINIO = 'cliente.ecfconsultoria.com.br';

    /** @return list<array{0: string, 1: string}> método + caminho, relativos a /portal */
    private function rotasRemovidas(): array
    {
        return [
            ['GET', '/estrutura/anunciar'],
            ['GET', '/estrutura/anunciar/categorias?q=cadeira'],
            ['GET', '/estrutura/anunciar/categorias/MLB1234'],
            ['GET', '/estrutura/ofertas/1/publicacao'],
            ['PUT', '/estrutura/ofertas/1/publicacao'],
            ['POST', '/estrutura/ofertas/1/publicacao/fotos'],
            ['POST', '/estrutura/ofertas/1/publicacao/validar'],
            ['POST', '/estrutura/ofertas/1/publicacao/publicar'],
            ['GET', '/estrutura/ofertas/1/publicador'],
            ['PUT', '/estrutura/ofertas/1/publicador'],
            ['PUT', '/estrutura/ofertas/1/publicador/categoria'],
            ['PUT', '/estrutura/ofertas/1/publicador/eixos'],
            ['PUT', '/estrutura/ofertas/1/publicador/variantes'],
            ['POST', '/estrutura/ofertas/1/publicador/fotos'],
            ['PUT', '/estrutura/ofertas/1/publicador/fotos'],
            ['DELETE', '/estrutura/ofertas/1/publicador/fotos/1'],
            ['POST', '/estrutura/ofertas/1/publicador/fotos/1/reenviar'],
            ['POST', '/estrutura/ofertas/1/publicador/condicionais'],
            ['POST', '/estrutura/ofertas/1/publicador/conferir'],
            ['POST', '/estrutura/ofertas/1/publicador/publicar'],
            ['POST', '/estrutura/ofertas/1/publicador/itens/1/descricao'],
            ['GET', '/estrutura/ofertas/1/publicador/simular'],
        ];
    }

    public function test_as_tres_familias_do_anunciar_respondem_404_para_o_cliente_logado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ofertas = $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $oferta = $ofertas['CAD-01-CB3']->id;
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        foreach ($this->rotasRemovidas() as [$metodo, $caminho]) {
            $caminho = str_replace('/ofertas/1/', "/ofertas/{$oferta}/", $caminho);

            $sessao->call($metodo, '/portal'.$caminho)->assertNotFound("{$metodo} /portal{$caminho} deveria ser 404.");
        }

        // Nada foi criado por essas chamadas.
        $this->assertSame(0, PubRascunho::count());
        $this->assertSame(0, EstruturaPublicacao::count());
    }

    public function test_as_tres_familias_respondem_404_tambem_pelo_dominio_do_portal(): void
    {
        config(['portal.dominio_cliente' => self::DOMINIO]);
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        foreach ($this->rotasRemovidas() as [$metodo, $caminho]) {
            $sessao->call($metodo, 'http://'.self::DOMINIO.'/portal'.$caminho)
                ->assertNotFound("{$metodo} {$caminho} deveria ser 404 no domínio do portal.");
        }
    }

    public function test_nenhuma_rota_nem_linha_da_allowlist_do_anunciar_sobrou(): void
    {
        // Substitui `route:list --path=estrutura` (que trava nesta máquina).
        $nomes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName());

        $this->assertFalse(Route::has('portal.auth.estrutura.anunciar'));
        $this->assertFalse(Route::has('portal.auth.estrutura.publicacao.abrir'));
        $this->assertFalse(Route::has('portal.auth.publicador.abrir'));
        $this->assertSame([], $nomes->filter(fn ($n) => Str::startsWith($n, ['portal.auth.estrutura.anunciar', 'portal.auth.estrutura.publicacao', 'portal.auth.publicador']))->values()->all());

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())
            ->filter(fn ($u) => Str::startsWith($u, 'portal/estrutura'));
        $this->assertSame([], $uris->filter(fn ($u) => Str::contains($u, ['anunciar', 'publicacao', 'publicador']))->values()->all());

        $permitido = (new \ReflectionClass(\App\Http\Middleware\RestringeDominioDoPortal::class))->getConstant('PERMITIDO');
        $this->assertSame([], collect($permitido)->filter(fn ($p) => Str::contains($p, ['estrutura/anunciar', '/publicacao', '/publicador']))->values()->all());

        // O assistente antigo do admin (D22) segue vivo.
        $this->assertTrue(Route::has('mlb.anuncios.wizard'));
    }

    /** 09/10/2026: o Planejamento (chave `sugestoes`) entrou logo depois de Produtos — são sete, nenhum "anunciar". */
    public function test_o_mapeamento_estrutural_tem_sete_submodulos(): void
    {
        $this->assertSame(
            ['produtos', 'sugestoes', 'lista', 'precificacao', 'anuncios', 'planejamento', 'mapeamento'],
            array_keys((new \ReflectionClass(ModulosPortal::class))->getConstant('SUBMODULOS')[ModulosPortal::ESTRUTURA]),
        );
    }

    public function test_o_resto_do_mapeamento_estrutural_continua_abrindo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        foreach ([
            'produtos'     => 'Portal/EstruturaProdutos',
            'lista'        => 'Portal/EstruturaLista',
            'precificacao' => 'Portal/EstruturaPrecificacao',
            'anuncios'     => 'Portal/EstruturaAnuncios',
            'agenda'       => 'Portal/EstruturaAgenda',
            'mapeamento'   => 'Portal/EstruturaMapeamento',
        ] as $rota => $componente) {
            $sessao->get(route("portal.auth.estrutura.{$rota}"))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component($componente));
        }
    }
}
