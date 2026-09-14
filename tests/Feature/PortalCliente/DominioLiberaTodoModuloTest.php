<?php

namespace Tests\Feature\PortalCliente;

use App\Http\Middleware\RestringeDominioDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Toda rota do portal AUTENTICADO tem de estar na allowlist do domínio.
 *
 * ### O que já custou
 * `RestringeDominioDoPortal` responde 404 no domínio do cliente para tudo que
 * não esteja listado nome a nome — e a lista é explícita de propósito: um
 * curinga `portal/*` já deixou vazar `/portal/usuarios`, a tela ADMIN de
 * gerenciar acessos.
 *
 * O efeito colateral é que rota nova do portal nasce BLOQUEADA, e o bloqueio é
 * invisível em desenvolvimento: no localhost `portal.dominio_cliente` é vazio,
 * o middleware devolve `$next()` na primeira linha e nada acontece. Só o
 * cliente, no domínio dele, encontra o 404.
 *
 * Foi assim que cinco rotas criadas em 14/09 — snapshot, confirmação,
 * relatório, investimento e a Calculadora — chegaram a produção mortas. A
 * suíte inteira passava; o portal do cliente respondia "404 Not Found" em tudo
 * o que o negócio tinha acabado de pedir.
 *
 * ### Por que o teste é este e não um request
 * Um request por rota provaria o mesmo, mas só para as rotas que alguém
 * lembrasse de escrever. Varrer o ROUTER pega a próxima também — que é o caso
 * que este teste existe para impedir.
 */
class DominioLiberaTodoModuloTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function permitido(): array
    {
        return (new \ReflectionClass(RestringeDominioDoPortal::class))->getConstant('PERMITIDO');
    }

    /** Nomes de rota do portal que o cliente de fato acessa. */
    private function rotasDoPortalAutenticado(): array
    {
        $rotas = [];

        foreach (Route::getRoutes() as $rota) {
            $nome = $rota->getName();

            // `portal.usuarios.*` é a tela ADMIN de gerenciar acessos e mora no
            // domínio interno — é exatamente o que a allowlist específica
            // existe para NÃO deixar passar.
            if (! $nome || ! Str::startsWith($nome, ['portal.auth.', 'portal.inicio', 'portal.entrada'])) {
                continue;
            }

            $rotas[$rota->uri()] = $nome;
        }

        return $rotas;
    }

    public function test_toda_rota_do_portal_autenticado_passa_pelo_dominio_do_cliente(): void
    {
        $permitido = $this->permitido();
        $rotas = $this->rotasDoPortalAutenticado();

        $this->assertNotEmpty($rotas, 'nenhuma rota do portal autenticado — o teste ficaria vazio');

        foreach ($rotas as $uri => $nome) {
            $liberada = false;

            foreach ($permitido as $padrao) {
                if (Str::is($padrao, $uri)) {
                    $liberada = true;
                    break;
                }
            }

            $this->assertTrue(
                $liberada,
                "A rota \"{$nome}\" ({$uri}) não está na allowlist de RestringeDominioDoPortal. "
                . 'No domínio do cliente ela responde 404 — e no localhost funciona, '
                . 'porque lá `portal.dominio_cliente` é vazio e o middleware nem roda.'
            );
        }
    }

    /**
     * O mecanismo, medido de verdade: com o domínio configurado, uma rota fora
     * da lista devolve 404 e uma rota da lista NÃO devolve.
     *
     * Sem esta metade, o teste acima estaria conferindo uma lista contra outra
     * lista — e passaria intacto mesmo se o middleware parasse de ser aplicado.
     */
    public function test_o_middleware_de_fato_barra_o_que_esta_fora_e_deixa_passar_o_que_esta_dentro(): void
    {
        config(['portal.dominio_cliente' => 'cliente.teste']);

        // Fora da lista: a tela admin de acessos, que só existe no domínio
        // interno. 404 é o comportamento correto.
        $this->get('http://cliente.teste/companies')->assertNotFound();

        // Dentro da lista: o snapshot. Como é POST, um GET devolve 405 — que é
        // justamente a prova de que o middleware deixou passar e quem respondeu
        // foi o router.
        $this->get('http://cliente.teste/portal/onboarding/fotografia')
            ->assertStatus(405);
    }
}
