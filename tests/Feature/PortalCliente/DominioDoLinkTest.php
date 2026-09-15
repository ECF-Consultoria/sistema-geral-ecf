<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\OnboardingLink;
use App\Models\PortalUsuario;
use App\Support\Portal\UrlDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O endereço do portal precisa apontar para o domínio DO CLIENTE.
 *
 * ### O defeito que isto fecha
 * `route()` monta a URL com o host da requisição, e quem copia o endereço está
 * sempre logado no admin. Medido em produção em 25/08/2026, com o isolamento de
 * domínio já no ar: o endereço entregue ao cliente era
 * `admin.ecfconsultoria.com.br/…`.
 *
 * O efeito não é cosmético. O `RestringeDominioDoPortal` protege o endereço do
 * cliente — só as rotas do portal existem lá. Mandando o cliente para o
 * endereço do admin, ele nunca chega na parte protegida.
 *
 * ### E os links por token já entregues?
 * Estão no WhatsApp dos clientes e não há como recolher. Até 15/09/2026 eles
 * abriam o portal; desde então levam ao LOGIN, no domínio do cliente, sem
 * carregar o token nem a query string adiante. A varredura completa — todas as
 * rotas antigas, nos dois hosts, com token real e inventado — está em
 * `PortalSemTokenTest`.
 */
class DominioDoLinkTest extends TestCase
{
    use RefreshDatabase;

    private const DOMINIO = 'cliente.ecfconsultoria.com.br';

    private function comDominio(): void
    {
        config(['portal.dominio_cliente' => self::DOMINIO]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    // ─── A URL gerada ───────────────────────────────────────────────────

    #[Test]
    public function o_endereco_de_login_sai_no_dominio_do_cliente(): void
    {
        $this->comDominio();

        $url = UrlDoPortal::para('portal.entrada');

        $this->assertStringContainsString(self::DOMINIO, $url);
        $this->assertStringNotContainsString('admin.', $url);
        $this->assertStringEndsWith('/entrar', $url);
    }

    /** Sem dominio configurado (local), nada muda. */
    #[Test]
    public function sem_dominio_configurado_a_url_fica_como_estava(): void
    {
        config(['portal.dominio_cliente' => null]);

        $this->assertSame(route('portal.entrada'), UrlDoPortal::para('portal.entrada'));
    }

    /** Caminho e query string sobrevivem — so o host muda. */
    #[Test]
    public function so_o_host_e_trocado(): void
    {
        $this->comDominio();

        $trocada = UrlDoPortal::noDominioDoCliente('https://admin.exemplo.com/portal-cliente/xyz?code=123&state=abc');

        $this->assertSame('https://'.self::DOMINIO.'/portal-cliente/xyz?code=123&state=abc', $trocada);
    }

    // ─── Os links antigos ───────────────────────────────────────────────

    /**
     * Link antigo no host do admin leva ao login no domínio do cliente — e só
     * isso. Nem o token nem a query string seguem adiante: um `?code=` do
     * Mercado Livre colado num link velho não pode reaparecer em outro
     * endereço, nem o token servir de prova de nada do outro lado.
     */
    #[Test]
    public function link_antigo_no_host_do_admin_leva_ao_login_do_cliente_sem_carregar_nada(): void
    {
        $this->comDominio();
        $link = OnboardingLink::create([
            'company_id' => $this->empresa()->id,
            'token'      => Str::random(48),
        ]);

        foreach (['/portal-cliente/', '/onboarding-cliente/'] as $prefixo) {
            $resposta = $this->get('http://admin.ecfconsultoria.com.br'.$prefixo.$link->token.'?code=SEGREDO&state=XYZ');

            $resposta->assertRedirect('http://'.self::DOMINIO.'/entrar');
            $this->assertStringNotContainsString($link->token, $resposta->headers->get('Location'));
            $this->assertStringNotContainsString('SEGREDO', $resposta->headers->get('Location'));
        }
    }

    // ─── A visao na tela do onboarding ──────────────────────────────────

    /**
     * A tela /onboarding/{id} mostra quem tem LOGIN — que desde 15/09/2026 é a
     * única forma de o cliente entrar.
     *
     * "Link aberto em tal dia" nao diz quem abriu; "Fulano entrou ontem" diz.
     * Sao respostas diferentes para a mesma pergunta na hora de cobrar.
     */
    #[Test]
    public function a_tela_do_onboarding_lista_quem_tem_login(): void
    {
        $empresa = $this->empresa();

        $pessoa = PortalUsuario::create([
            'nome' => 'Gestor do Cliente', 'email' => 'g.'.uniqid().'@empresa.test', 'ativo' => true,
        ]);
        $pessoa->empresas()->attach($empresa->id, ['principal' => true]);

        // Alguem de outra empresa nao pode aparecer nesta tela.
        $outra = PortalUsuario::create([
            'nome' => 'De Outra Empresa', 'email' => 'o.'.uniqid().'@empresa.test', 'ativo' => true,
        ]);
        $outra->empresas()->attach($this->empresa()->id, ['principal' => true]);

        $onboarding = \App\Models\Onboarding::create([
            'company_id' => $empresa->id,
            'servico_id' => \App\Models\Servico::create([
                'nome' => 'S '.uniqid(), 'valor_padrao' => 0,
                'tipo_cobranca' => \App\Models\Servico::TIPO_MENSAL,
                'ativo' => true, 'setor' => \App\Models\Servico::SETOR_OUTROS,
            ])->id,
            'status' => \App\Models\Onboarding::STATUS_ANDAMENTO,
            'iniciado_em' => now(),
        ]);

        $admin = \App\Models\User::create([
            'name' => 'Admin', 'email' => 'a.'.uniqid().'@ecf.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('link.acessos', 1)
                ->where('link.acessos.0.nome', 'Gestor do Cliente')
                ->where('link.acessos.0.nunca_entrou', true)
                ->where('link.pode_entrar', true)
            );
    }
}
