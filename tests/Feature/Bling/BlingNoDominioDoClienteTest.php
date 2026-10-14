<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use App\Models\User;
use App\Services\Bling\BlingOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O fluxo do Bling vive no DOMÍNIO DO CLIENTE.
 *
 * Por que este arquivo existe: ao cadastrar o app no Bling em 14/10/2026, o
 * usuário esbarrou numa lacuna da Fase 184. O seed §7 dizia que o callback
 * público ficaria "na allowlist do domínio do cliente", e o plano 07 criou as
 * rotas sem tocar no `RestringeDominioDoPortal`.
 *
 * ⚠️ O que torna essa classe de falha cara: o middleware **não roda no
 * localhost** (lá `portal.dominio_cliente` é vazio). A suíte inteira passava, e
 * o 404 só apareceria em produção — é o mesmo sintoma que o próprio middleware
 * documenta nos comentários dele, quando as escritas do onboarding ficaram de
 * fora da lista.
 *
 * Por isso os testes de allowlist aqui vêm em PAR: a rota do Bling responde, e
 * uma rota interna continua NÃO respondendo. Sem o par, um middleware acidental-
 * mente desligado faria o teste passar sem provar nada.
 */
class BlingNoDominioDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private const DOMINIO = 'cliente.ecfconsultoria.com.br';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.dominio_cliente' => self::DOMINIO]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa Bling '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function comCredenciais(): void
    {
        config([
            'services.bling.client_id' => 'id-de-teste',
            'services.bling.client_secret' => 'segredo-de-teste',
            'services.bling.redirect' => 'https://'.self::DOMINIO.'/oauth/bling/callback',
        ]);
    }

    /** Requisição como se viesse do domínio do cliente. */
    private function noDominioDoCliente(string $uri)
    {
        return $this->get('http://'.self::DOMINIO.$uri);
    }

    // ─── A allowlist ────────────────────────────────────────────────────────

    #[Test]
    public function a_landing_do_bling_existe_no_dominio_do_cliente(): void
    {
        $empresa = $this->empresa();

        // Sem assinatura a rota recusa — mas o que importa aqui é que ela
        // RECUSA em vez de não existir. 404 significaria fora da allowlist.
        $resposta = $this->noDominioDoCliente('/bling/conectar/'.$empresa->id);

        $this->assertNotSame(404, $resposta->getStatusCode(),
            'a landing do Bling respondeu 404 no domínio do cliente: ela saiu da allowlist do RestringeDominioDoPortal');
    }

    #[Test]
    public function o_callback_do_bling_existe_no_dominio_do_cliente(): void
    {
        $resposta = $this->noDominioDoCliente('/oauth/bling/callback?state=qualquer&code=qualquer');

        $this->assertNotSame(404, $resposta->getStatusCode(),
            'o callback do Bling respondeu 404 no domínio do cliente: ele saiu da allowlist do RestringeDominioDoPortal');
    }

    /**
     * O PAR POSITIVO dos dois testes acima — sem ele, eles passariam com o
     * middleware inteiro desligado, que é justamente o estado do localhost.
     */
    #[Test]
    public function uma_rota_interna_continua_nao_existindo_no_dominio_do_cliente(): void
    {
        $resposta = $this->noDominioDoCliente('/dashboard');

        $this->assertSame(404, $resposta->getStatusCode(),
            'uma rota interna respondeu no domínio do cliente — o middleware não está agindo, '
            .'e por isso os dois testes de allowlist acima não provam nada');
    }

    // ─── O link nasce no domínio do cliente ─────────────────────────────────

    #[Test]
    public function o_link_gerado_aponta_para_o_dominio_do_cliente(): void
    {
        $this->comCredenciais();
        $empresa = $this->empresa();

        $resultado = app(BlingOAuthService::class)->gerarLinkDeAutorizacao($empresa, null);

        $this->assertStringStartsWith('http://'.self::DOMINIO.'/bling/conectar/', $resultado['url'],
            'o link do cliente não saiu no domínio dele');
        $this->assertStringContainsString('signature=', $resultado['url']);
        $this->assertStringContainsString('expires=', $resultado['url']);
    }

    /**
     * A regressão que o `absolute: false` existe para impedir: assinatura
     * ABSOLUTA cobre o host, então trocar o domínio depois daria 403 no link
     * que o cliente acabou de receber.
     */
    #[Test]
    public function o_link_no_dominio_do_cliente_abre_de_verdade(): void
    {
        $this->comCredenciais();
        $empresa = $this->empresa();

        $url = app(BlingOAuthService::class)->gerarLinkDeAutorizacao($empresa, null)['url'];

        $resposta = $this->get($url);

        $this->assertSame(200, $resposta->getStatusCode(),
            'o link assinado no domínio do cliente foi recusado — a assinatura precisa ser '
            .'RELATIVA (`absolute: false`) e a rota validar com `signed:relative`');
    }

    #[Test]
    public function assinatura_adulterada_continua_sendo_recusada(): void
    {
        $this->comCredenciais();
        $empresa = $this->empresa();

        $url = app(BlingOAuthService::class)->gerarLinkDeAutorizacao($empresa, null)['url'];

        // Troca o último caractere da assinatura: o link vira outro.
        $adulterada = substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a');

        $this->assertSame(403, $this->get($adulterada)->getStatusCode(),
            'assinatura adulterada passou — `signed:relative` ainda tem de validar a assinatura, '
            .'ele só deixa de cobrir o HOST');
    }

    /**
     * Sem domínio próprio (o ambiente local), nada muda: o helper devolve a URL
     * intacta e o link segue no host de sempre.
     */
    #[Test]
    public function sem_dominio_do_portal_o_link_segue_no_host_de_sempre(): void
    {
        config(['portal.dominio_cliente' => null]);
        $this->comCredenciais();
        $empresa = $this->empresa();

        $url = app(BlingOAuthService::class)->gerarLinkDeAutorizacao($empresa, null)['url'];

        $this->assertStringNotContainsString(self::DOMINIO, $url);
        $this->assertSame(200, $this->get($url)->getStatusCode());
    }

    /**
     * A `landing()` recusa link antigo comparando `link_url` com
     * `$request->fullUrl()`. Com o host trocado, os dois têm de continuar
     * batendo — senão TODO link válido seria lido como antigo.
     */
    #[Test]
    public function o_link_gravado_bate_com_a_url_que_o_cliente_abre(): void
    {
        $this->comCredenciais();
        $empresa = $this->empresa();

        $url = app(BlingOAuthService::class)->gerarLinkDeAutorizacao($empresa, null)['url'];

        $this->assertSame($url, BlingConexao::where('company_id', $empresa->id)->value('link_url'),
            'o que foi gravado em link_url difere do que foi devolvido ao chamador');

        $this->get($url)->assertOk();
    }

    /**
     * Regerar descarta o anterior, e o anterior cai numa tela amigável.
     *
     * ⚠️ O `travel()` NÃO é enfeite, e a primeira versão deste teste reprovou
     * por não tê-lo: duas gerações no MESMO SEGUNDO produzem a URL **idêntica**,
     * porque o `expires` é o mesmo e a assinatura é determinística sobre o mesmo
     * caminho e query. Não é defeito do produto — é o que de fato acontece, e
     * nesse caso não há "anterior" a invalidar, porque o link é o mesmo. Na
     * operação real sempre passam segundos entre um clique e outro.
     */
    #[Test]
    public function regerar_o_link_descarta_o_anterior(): void
    {
        $this->comCredenciais();
        $empresa = $this->empresa();
        $servico = app(BlingOAuthService::class);

        $primeiro = $servico->gerarLinkDeAutorizacao($empresa, null)['url'];

        $this->travel(2)->seconds();
        $segundo = $servico->gerarLinkDeAutorizacao($empresa, null)['url'];

        $this->assertNotSame($primeiro, $segundo, 'com 2 segundos de diferença o `expires` muda e a URL tem de mudar junto');

        // O novo convida; o antigo explica, sem erro cru e sem botão sem destino.
        $this->get($segundo)->assertOk()->assertSee('data-estado="convite"', false);
        $this->get($primeiro)->assertOk()->assertSee('data-estado="erro"', false);
    }

    /** O usuário administrador existe só para o container não reclamar em cenários futuros. */
    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }
}
