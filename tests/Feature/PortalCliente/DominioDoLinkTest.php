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
 * O link do portal precisa apontar para o dominio DO CLIENTE.
 *
 * ### O defeito que isto fecha
 * `route('portal.inicio', $token)` monta a URL com o host da requisicao, e quem
 * gera o link esta sempre logado no admin. Medido em producao em 25/08/2026,
 * com o isolamento de dominio ja no ar: o link entregue ao cliente era
 * `admin.ecfconsultoria.com.br/portal-cliente/…`.
 *
 * O efeito nao e cosmetico. O `RestringeDominioDoPortal` protege o endereco do
 * cliente — so as rotas do portal existem la. Mandando o cliente para o
 * endereco do admin, ele nunca chega na parte protegida.
 *
 * ### E os links ja entregues?
 * Estao no WhatsApp dos clientes e nao ha como recolher. Por isso o dominio
 * antigo REDIRECIONA em vez de bloquear.
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

    private function link(?Company $empresa = null): OnboardingLink
    {
        return OnboardingLink::create([
            'company_id' => ($empresa ?? $this->empresa())->id,
            'token' => Str::random(48),
        ]);
    }

    // ─── A URL gerada ───────────────────────────────────────────────────

    #[Test]
    public function o_link_sai_no_dominio_do_cliente(): void
    {
        $this->comDominio();

        $url = UrlDoPortal::para('portal.inicio', 'abc123');

        $this->assertStringContainsString(self::DOMINIO, $url);
        $this->assertStringNotContainsString('admin.', $url);
        $this->assertStringEndsWith('/portal-cliente/abc123', $url);
    }

    /** Sem dominio configurado (local), nada muda. */
    #[Test]
    public function sem_dominio_configurado_a_url_fica_como_estava(): void
    {
        config(['portal.dominio_cliente' => null]);

        $this->assertSame(route('portal.inicio', 'abc123'), UrlDoPortal::para('portal.inicio', 'abc123'));
    }

    /** Caminho e query string sobrevivem — so o host muda. */
    #[Test]
    public function so_o_host_e_trocado(): void
    {
        $this->comDominio();

        $trocada = UrlDoPortal::noDominioDoCliente('https://admin.exemplo.com/portal-cliente/xyz?code=123&state=abc');

        $this->assertSame('https://'.self::DOMINIO.'/portal-cliente/xyz?code=123&state=abc', $trocada);
    }

    // ─── O redirecionamento dos links antigos ───────────────────────────

    /**
     * Link antigo, apontando para o host do admin, leva a pessoa ao dominio
     * certo — em vez de 404 ou de servir o portal no lugar errado.
     */
    #[Test]
    public function link_no_host_do_admin_redireciona_para_o_do_cliente(): void
    {
        $this->comDominio();
        $link = $this->link();

        $this->get('http://admin.ecfconsultoria.com.br/portal-cliente/'.$link->token)
            ->assertRedirect('http://'.self::DOMINIO.'/portal-cliente/'.$link->token);
    }

    /** E o prefixo legado tambem — ele esta no WhatsApp de clientes antigos. */
    #[Test]
    public function o_prefixo_legado_tambem_e_levado(): void
    {
        $this->comDominio();
        $link = $this->link();

        $this->get('http://admin.ecfconsultoria.com.br/onboarding-cliente/'.$link->token)
            ->assertRedirect('http://'.self::DOMINIO.'/onboarding-cliente/'.$link->token);
    }

    /**
     * No dominio certo, NAO redireciona.
     *
     * E o teste que impede o laco infinito: se a condicao olhasse so "existe
     * dominio configurado", toda requisicao se redirecionaria para si mesma.
     */
    #[Test]
    public function no_dominio_do_cliente_nao_ha_redirecionamento(): void
    {
        $this->comDominio();
        $link = $this->link();

        $this->get('http://'.self::DOMINIO.'/portal-cliente/'.$link->token)
            ->assertOk();
    }

    /** A query string atravessa o redirecionamento — o OAuth do ML depende disso. */
    #[Test]
    public function a_query_string_sobrevive_ao_redirecionamento(): void
    {
        $this->comDominio();
        $link = $this->link();

        $this->get('http://admin.ecfconsultoria.com.br/portal-cliente/'.$link->token.'?code=SEGREDO&state=XYZ')
            ->assertRedirect('http://'.self::DOMINIO.'/portal-cliente/'.$link->token.'?code=SEGREDO&state=XYZ');
    }

    /** Sem dominio configurado, o portal responde no host que for. */
    #[Test]
    public function sem_dominio_configurado_nao_redireciona_nada(): void
    {
        config(['portal.dominio_cliente' => null]);
        $link = $this->link();

        $this->get('http://qualquer-host.test/portal-cliente/'.$link->token)->assertOk();
    }

    // ─── A visao na tela do onboarding ──────────────────────────────────

    /**
     * A tela /onboarding/{id} mostra quem tem LOGIN, nao so o link.
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
