<?php

namespace Tests\Feature\Phase141;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 06 (Tarefa 2) — trava de contrato da tela de Fechamento
 * depois da virada de regra: o paliativo do quick `260909-lge` sai do
 * caminho da regra nova (mas o comportamento com a flag desligada continua
 * byte a byte o de hoje), o estado `valor_fixo` ganha rótulo próprio, e a
 * explicação de plataforma excluída aparece.
 *
 * Mesma receita de `Phase139FechamentoUiContratoTest` (projeto sem test
 * runner de JS): ler o `.jsx` como texto puro e afirmar presença/ausência
 * de trechos-chave, mais uma frente de PROPS via requisição HTTP real.
 */
class Phase141FechamentoUiTest extends TestCase
{
    use RefreshDatabase;

    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    /**
     * Palavras banidas em texto VISÍVEL da tela de Fechamento — lista da
     * Fase 141 (amplia as sete de `139-CONTEXT.md` com "procedência" e
     * "flag", os dois termos novos que este plano introduziu no código).
     */
    private const PALAVRAS_BANIDAS = [
        'snapshot',
        'reconsolidação',
        'rollup',
        'âncora',
        'competência',
        'origem',
        'faixa piso',
        'procedência',
        'flag',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function ligarFlag(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
    }

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE, 'usa_tabela_progressiva' => true]);

        return $servico->refresh();
    }

    private function lerArquivoJsx(): string
    {
        return file_get_contents(resource_path(self::ARQUIVO_FINANCEIRO));
    }

    /**
     * Remove blocos de comentário (`/* ... *\/`, o que também cobre
     * `{/* ... *\/}` do JSX) e linhas `//`, deixando só o que o React
     * efetivamente renderiza (mesma receita de `Phase139FechamentoUiContratoTest`).
     */
    private function removerComentarios(string $conteudo): string
    {
        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);

        return preg_replace('/^[ \t]*\/\/.*$/m', '', $semBlocos);
    }

    private function assertPalavraAusenteComoTextoVisivel(string $palavra, string $conteudoFiltrado, string $mensagem): void
    {
        $padrao = '/(?<![\p{L}\p{N}_])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

        $this->assertDoesNotMatchRegularExpression($padrao, $conteudoFiltrado, $mensagem);
    }

    // ─── Frente 1: PROPS ────────────────────────────────────────────────

    // ⚠️ Dois métodos SEPARADOS (não dois `get()` no mesmo teste) — mesma
    // armadilha documentada em `Phase141ConsolidarRegraNovaTest`: ligar a
    // flag no meio do mesmo teste e requisitar de novo pode ler o valor
    // memoizado da PRIMEIRA resolução do container dentro do processo de
    // teste. Produção não tem esse problema (cada request é um processo
    // novo); aqui a disciplina é dois testes, cada um com sua própria
    // resolução de `AdminController`/`FechamentoRegraTabela`.

    #[Test]
    public function resposta_traz_regra_nova_ativa_falsa_por_padrao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-ui-141-a']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('regra_nova_ativa', $props);
        $this->assertFalse($props['regra_nova_ativa']);
    }

    #[Test]
    public function resposta_traz_regra_nova_ativa_true_quando_a_flag_esta_ligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-ui-141-b']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $this->assertTrue($response->viewData('page')['props']['regra_nova_ativa']);
    }

    // ─── Frente 2: ARQUIVO ──────────────────────────────────────────────

    #[Test]
    public function paliativo_de_composicao_da_mensalidade_nao_existe_mais_no_arquivo(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertStringNotContainsString(
            'MensalidadeComposicaoBreakdown',
            $conteudo,
            'O paliativo do quick 260909-lge precisa ter saído do arquivo por completo (nome do componente incluído).'
        );
    }

    #[Test]
    public function estado_valor_fixo_tem_vocabulario_proprio_distinto_de_a_definir(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertStringContainsString("estado === 'valor_fixo'", $conteudo);
        $this->assertStringContainsString('Valor fixo do contrato', $conteudo);
    }

    #[Test]
    public function chip_de_filtro_valor_fixo_existe_e_filtra_pelo_estado(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            "/key:\s*'valor_fixo'/",
            $conteudo,
            'Precisa existir um chip de filtro com key valor_fixo.'
        );
        $this->assertStringContainsString(
            "filtroChip === 'valor_fixo'",
            $conteudo,
            'O chip precisa de lógica de filtro correspondente.'
        );
    }

    #[Test]
    public function faturamento_combinado_breakdown_evoluido_recebe_plataformas_consideradas(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            '/function FaturamentoCombinadoBreakdown\(\{[^}]*plataformasConsideradas/s',
            $conteudo,
            'FaturamentoCombinadoBreakdown precisa ter sido EVOLUÍDO (não duplicado) para receber plataformasConsideradas.'
        );
        $this->assertStringNotContainsString(
            'function FaturamentoExcluidaAviso',
            $conteudo,
            'A explicação de plataforma excluída deve evoluir o componente existente, nunca criar um paralelo.'
        );
    }

    #[Test]
    public function grupo_servicos_divergentes_banner_troca_texto_sob_a_regra_nova(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            '/function GrupoServicosDivergentesBanner\(\{[^}]*regraNovaAtiva/s',
            $conteudo
        );
        $this->assertStringContainsString('Sem tabela cadastrada', $conteudo);
    }

    // ─── Frente 3: COPY SEM JARGÃO ──────────────────────────────────────

    #[Test]
    public function nenhuma_das_nove_palavras_banidas_aparece_como_texto_visivel(): void
    {
        $conteudoFiltrado = $this->removerComentarios($this->lerArquivoJsx());

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $this->assertPalavraAusenteComoTextoVisivel(
                $palavra,
                $conteudoFiltrado,
                "A palavra \"{$palavra}\" não pode aparecer como texto visível na tela de Fechamento (139-CONTEXT.md ampliado pela Fase 141)."
            );
        }
    }
}
