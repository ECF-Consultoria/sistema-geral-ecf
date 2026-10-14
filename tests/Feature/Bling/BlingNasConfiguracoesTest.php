<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\ContasLiberadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 184, Plano 11 (BLING-20, BLING-27, BLING-31) — o lado servidor da tela de
 * Configurações da loja: o bloco `bling` em `PainelVisaoGeralService::integracoes()`
 * (Task 1, fonte única do estado) e `MlbPublicadorBlingController` (Task 2, as sete
 * ações autenticadas).
 *
 * ⚠️ Risco central da fase (aviso da sessão da Fase 180, 13/10/2026): o estado da
 * conexão nasce numa chave IRMÃ `bling`, e a chave `erp` de `integracoes()` fica
 * intacta — os três gates do ERP da v26.0 (CabecalhoDoPublicadorTest,
 * CockpitDaContaTest, publicador-visao-geral-acoes.test.js) continuam verdes porque
 * nenhum deles lê a chave `bling`.
 */
class BlingNasConfiguracoesTest extends TestCase
{
    use RefreshDatabase;

    /** Regex de sigilo — nenhum texto do bloco `bling` cita a plataforma de venda (CONTEXT §4.6). */
    private const PROIBIDO = '/mercado\s*livre|mercadolivre|\bml\b|shopee|magalu|amazon/i';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.client_id' => 'client-id-de-teste',
            'services.bling.client_secret' => 'client-secret-de-teste',
            'services.bling.redirect' => 'https://portal.exemplo.com.br/bling/callback',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function painel(): PainelVisaoGeralService
    {
        return app(PainelVisaoGeralService::class);
    }

    private function programas(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    /** @return array{mlb_empresa: ?MlbEmpresa, company: ?Company, programa: string, chave: string} */
    private function alvoDaCompany(Company $company): array
    {
        return ['mlb_empresa' => null, 'company' => $company, 'programa' => 'gestao', 'chave' => $company->chaveContaMl()];
    }

    /** Conta `empresa-N` de Polos, de propósito SEM Company ligada. */
    private function alvoSemCompany(): array
    {
        $empresa = MlbEmpresa::create(['nome' => 'Polo sem empresa', 'projeto' => 'POLOS'])->fresh();

        return ['mlb_empresa' => $empresa, 'company' => null, 'programa' => 'polos', 'chave' => $empresa->chaveContaMl()];
    }

    private function integracoes(array $alvo): array
    {
        $empresaParaTela = $this->programas()->empresaParaTela($alvo);

        return $this->painel()->integracoes($alvo, $empresaParaTela);
    }

    private function bling(array $alvo): array
    {
        return $this->integracoes($alvo)['bling'];
    }

    private function assertSemOrigem(string $texto, string $onde): void
    {
        $texto = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $texto);
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $texto, "a origem vazou em {$onde}: {$texto}");
    }

    // ═══ Task 1 — o bloco bling em integracoes() ═════════════════════════

    public function test_company_sem_linha_de_conexao_fica_a_conectar(): void
    {
        $company = Company::factory()->create();

        $bling = $this->bling($this->alvoDaCompany($company));

        $this->assertSame('a_conectar', $bling['estado']);
        $this->assertTrue($bling['disponivel']);
        $this->assertNull($bling['motivo']);
    }

    public function test_sem_company_fica_sem_company_e_nao_inventa_nenhuma(): void
    {
        $antes = Company::count();

        $bling = $this->bling($this->alvoSemCompany());

        $this->assertSame('sem_company', $bling['estado']);
        $this->assertFalse($bling['disponivel']);
        $this->assertNotEmpty($bling['motivo']);
        $this->assertStringContainsString('empresa', $bling['motivo']);

        // Asserção NEGATIVA: a tela nunca pode inventar uma Company para ter o que mostrar.
        $this->assertDatabaseCount('companies', $antes);
    }

    public function test_env_vazio_fica_nao_configurado(): void
    {
        config([
            'services.bling.client_id' => null,
            'services.bling.client_secret' => null,
            'services.bling.redirect' => null,
        ]);

        $company = Company::factory()->create();
        $bling = $this->bling($this->alvoDaCompany($company));

        $this->assertSame('nao_configurado', $bling['estado']);
        $this->assertFalse($bling['disponivel']);
    }

    public function test_conexao_aguardando_cliente_traz_link_e_prazo_de_7_dias(): void
    {
        $company = Company::factory()->create();
        // `datetime` sem microssegundos: truncar aqui para a asserção bater com
        // o que o banco de fato grava e devolve.
        $geradoEm = Carbon::parse(now()->subDays(2)->format('Y-m-d H:i:s'));

        BlingConexao::create([
            'company_id' => $company->id,
            'status' => BlingConexao::STATUS_AGUARDANDO_CLIENTE,
            'link_url' => 'https://portal.exemplo.com.br/bling/landing/assinado',
            'link_gerado_em' => $geradoEm,
        ]);

        $bling = $this->bling($this->alvoDaCompany($company));

        $this->assertSame('aguardando_cliente', $bling['estado']);
        $this->assertTrue($bling['disponivel']);
        $this->assertSame('https://portal.exemplo.com.br/bling/landing/assinado', $bling['link_url']);
        $this->assertSame($geradoEm->toISOString(), $bling['link_gerado_em']);
        $this->assertSame($geradoEm->copy()->addDays(7)->toISOString(), $bling['link_expira_em']);
    }

    public function test_conexao_ativa_fica_conectado_com_os_dados_da_conta(): void
    {
        $company = Company::factory()->create();
        $conectadoEm = Carbon::parse(now()->subDays(10)->format('Y-m-d H:i:s'));

        $conexao = BlingConexao::create([
            'company_id' => $company->id,
            'status' => BlingConexao::STATUS_ATIVA,
            'bling_nome' => 'Loja Teste LTDA',
            'bling_cnpj' => '12.345.678/0001-90',
            'access_token' => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'connected_at' => $conectadoEm,
            'ultima_importacao_em' => now()->subDay(),
            'ultimo_estoque_em' => now()->subHours(3),
            'estoque_ativo' => true,
        ]);

        BlingVinculo::create([
            'company_id' => $company->id, 'bling_produto_id' => '1', 'situacao' => BlingVinculo::SITUACAO_VINCULADO,
        ]);
        BlingVinculo::create([
            'company_id' => $company->id, 'bling_produto_id' => '2', 'situacao' => BlingVinculo::SITUACAO_VINCULADO,
        ]);
        BlingVinculo::create([
            'company_id' => $company->id, 'bling_produto_id' => '3', 'situacao' => BlingVinculo::SITUACAO_IGNORADO,
        ]);

        $bling = $this->bling($this->alvoDaCompany($company));

        $this->assertSame('conectado', $bling['estado']);
        $this->assertTrue($bling['disponivel']);
        $this->assertSame('Loja Teste LTDA', $bling['bling_nome']);
        $this->assertSame('12.345.678/0001-90', $bling['bling_cnpj']);
        $this->assertSame($conectadoEm->toISOString(), $bling['conectado_em']);
        $this->assertSame($conexao->ultima_importacao_em->toISOString(), $bling['ultima_importacao_em']);
        $this->assertSame($conexao->ultimo_estoque_em->toISOString(), $bling['ultimo_estoque_em']);
        $this->assertTrue($bling['estoque_ativo']);
        $this->assertSame(2, $bling['produtos_vinculados']);
    }

    public function test_conexao_divergente_traz_o_motivo_gravado_no_callback(): void
    {
        $company = Company::factory()->create();

        BlingConexao::create([
            'company_id' => $company->id,
            'status' => BlingConexao::STATUS_DIVERGENTE,
            'last_error' => 'A conta autorizada no Bling é de outra empresa.',
            'last_error_at' => now(),
        ]);

        $bling = $this->bling($this->alvoDaCompany($company));

        $this->assertSame('divergente', $bling['estado']);
        $this->assertFalse($bling['disponivel']);
        $this->assertSame('A conta autorizada no Bling é de outra empresa.', $bling['motivo']);
    }

    public function test_conexao_com_erro_traz_ultimo_erro_e_quando_aconteceu(): void
    {
        $company = Company::factory()->create();
        $erroEm = Carbon::parse(now()->subMinutes(5)->format('Y-m-d H:i:s'));

        BlingConexao::create([
            'company_id' => $company->id,
            'status' => BlingConexao::STATUS_ERRO,
            'last_error' => 'HTTP 503',
            'last_error_at' => $erroEm,
        ]);

        $bling = $this->bling($this->alvoDaCompany($company));

        $this->assertSame('erro', $bling['estado']);
        $this->assertFalse($bling['disponivel']);
        $this->assertSame('HTTP 503', $bling['ultimo_erro']);
        $this->assertSame($erroEm->toISOString(), $bling['ultimo_erro_em']);
    }

    public function test_teto_conta_as_conexoes_ativas_da_base_inteira(): void
    {
        $alvoDaConsulta = $this->alvoDaCompany(Company::factory()->create());

        BlingConexao::create(['company_id' => Company::factory()->create()->id, 'status' => BlingConexao::STATUS_ATIVA]);
        BlingConexao::create(['company_id' => Company::factory()->create()->id, 'status' => BlingConexao::STATUS_ATIVA]);
        BlingConexao::create(['company_id' => Company::factory()->create()->id, 'status' => BlingConexao::STATUS_AGUARDANDO_CLIENTE]);

        $bling = $this->bling($alvoDaConsulta);

        $this->assertSame(['limite' => 10, 'conectadas' => 2], $bling['teto']);
    }

    public function test_cinco_chaves_antigas_de_integracoes_continuam_intactas(): void
    {
        $company = Company::factory()->create();
        $alvo = $this->alvoDaCompany($company);
        $empresaParaTela = $this->programas()->empresaParaTela($alvo);

        $r = $this->painel()->integracoes($alvo, $empresaParaTela);

        // Snapshot independente: as mesmas primitivas que o método usa internamente,
        // chamadas aqui de fora — se `integracoes()` tivesse mudado o cálculo das
        // cinco chaves antigas, este teste discordaria do resultado.
        $ancora = \App\Models\PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company']);
        $nomeErpEsperado = ProgramasPublicadorService::erpDeclarado($alvo['mlb_empresa']?->implementacao);

        $this->assertSame(['token' => $empresaParaTela['token']], $r['mercado_livre']);
        $this->assertSame(ContasLiberadas::libera($ancora), $r['publicacao_liberada']);
        $this->assertSame(AlavancasLiberadas::libera($ancora), $r['alavancas_liberada']);
        $this->assertSame($empresaParaTela['portal'], $r['portal']);
        $this->assertSame(['valor' => $nomeErpEsperado, 'rotulo' => $nomeErpEsperado ?? 'Não informado'], $r['erp']);
    }

    public function test_nenhum_motivo_do_bloco_bling_cita_a_plataforma_de_venda(): void
    {
        $semCompany = $this->bling($this->alvoSemCompany());
        $this->assertSemOrigem((string) $semCompany['motivo'], 'sem_company');

        config(['services.bling.client_id' => null, 'services.bling.client_secret' => null, 'services.bling.redirect' => null]);
        $naoConfigurado = $this->bling($this->alvoDaCompany(Company::factory()->create()));
        $this->assertSemOrigem((string) $naoConfigurado['motivo'], 'nao_configurado');
    }
}
