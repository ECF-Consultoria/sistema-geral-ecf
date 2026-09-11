<?php

namespace Tests\Feature\Quick260911;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Quick 260911-kio (T2) — as empresas sem integração saem da lista
 * principal para uma seção recolhida no fim.
 *
 * Medição em produção (2026-09-11): das 81 empresas cobradas que faturaram
 * menos de R$ 50 mil em agosto, 54 não têm integração nenhuma (cadastro
 * novo, sem token e sem id) e 25 são lojas pequenas de verdade. Só as
 * primeiras saem da lista.
 *
 * ⚠️ A trava que importa: essas 54 somam R$ 168 mil de cobrança. O "Total a
 * receber" continua contando todas — é dinheiro a receber de verdade —, e a
 * seção recolhida diz quanto do total vem dali, para a conta não parecer
 * mentirosa. Recorte de EXIBIÇÃO, nunca de dado.
 */
class SemIntegracaoRecolhidaTest extends TestCase
{
    use RefreshDatabase;

    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function lerArquivoJsx(): string
    {
        return file_get_contents(resource_path(self::ARQUIVO_FINANCEIRO));
    }

    // ─── A TRAVA DO DINHEIRO (backend) ──────────────────────────────────

    public function test_empresa_sem_integracao_continua_na_resposta_e_dentro_do_total_a_receber(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = User::factory()->create(['role' => 'admin']);
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        // Sem `adman_account_id` e sem Shopee: é exatamente o cadastro novo
        // das 54 — e ainda assim cobrado.
        $company = Company::factory()->create(['adman_account_id' => null]);
        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 3_000.00,
            'data_contratacao' => '2026-01-10',
            'ativo'            => true,
        ]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props  = $response->viewData('page')['props'];
        $linhas = collect($props['companies']);
        $linha  = $linhas->firstWhere('id', $company->id);

        $this->assertNotNull($linha, 'A empresa sem integração NUNCA some da resposta — o recolhimento é só de exibição.');
        $this->assertSame(FechamentoSnapshot::ESTADO_SEM_INTEGRACAO, $linha['estado']);
        $this->assertEqualsWithDelta(3_000.00, $linha['cobranca_mensal'], 0.01);

        $this->assertEqualsWithDelta(
            3_000.00,
            $props['totais']['total_a_receber'],
            0.01,
            'O "Total a receber" precisa continuar contando a empresa recolhida — ela é dinheiro a receber de verdade.'
        );
    }

    // ─── O RECOLHIMENTO (tela) ──────────────────────────────────────────

    public function test_a_secao_recolhida_existe_e_soma_a_cobranca_das_linhas_que_ela_esconde(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertStringContainsString('function SemIntegracaoRecolhidas', $conteudo);
        $this->assertMatchesRegularExpression(
            '/function SemIntegracaoRecolhidas.*?cobranca_mensal != null/s',
            $conteudo,
            'O rótulo precisa somar `cobranca_mensal` das próprias linhas que a seção esconde — nunca uma chave que o backend não emite.'
        );
        $this->assertStringContainsString(
            'já contados no total acima',
            $conteudo,
            'Sem dizer que o valor já está no total, a conta do topo parece mentirosa.'
        );
    }

    public function test_o_corte_e_por_estado_sem_integracao_e_nunca_por_has_adman(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            "/principais:\s*filtradas\.filter\(e => e\.estado !== 'sem_integracao'\)/",
            $conteudo,
            'Cortar por has_adman pegaria junto as empresas só de Shopee, que TÊM integração e TÊM faturamento.'
        );
        $this->assertMatchesRegularExpression(
            "/recolhidas:\s*filtradas\.filter\(e => e\.estado === 'sem_integracao'\)/",
            $conteudo
        );
    }

    public function test_com_o_chip_sem_integracao_ligado_nada_e_recolhido(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            "/if \(filtroChip === 'sem_integracao'\) \{\s*return \{ principais: filtradas, recolhidas: \[\] \};/s",
            $conteudo,
            'Quem escolheu ver essas empresas precisa vê-las na lista, não dentro de uma gaveta fechada.'
        );
    }

    public function test_a_lista_recebe_as_duas_metades_e_a_linha_e_a_mesma_nos_dois_lugares(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            '/<FechamentoList\s+empresas=\{principais\}\s+recolhidas=\{recolhidas\}/s',
            $conteudo,
            'A lista precisa receber as duas metades separadas.'
        );
        // Uma única função de linha para os dois lugares — duplicar a
        // renderização é como os dois lados passam a divergir em silêncio.
        $this->assertStringContainsString('function renderLinha(empresa)', $conteudo);
        $this->assertStringContainsString('{empresas.map(renderLinha)}', $conteudo);
        $this->assertStringContainsString('{recolhidas.map(renderLinha)}', $conteudo);
    }

    public function test_nenhuma_empresa_encontrada_so_aparece_quando_a_gaveta_tambem_esta_vazia(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertMatchesRegularExpression(
            '/if \(empresas\.length === 0 && recolhidas\.length === 0\)/',
            $conteudo,
            'Dizer "nenhuma empresa" com linhas guardadas na gaveta logo abaixo seria a própria mentira que este quick evita.'
        );
    }

    public function test_a_linha_da_listagem_continua_sendo_div_com_acessibilidade_reposta_a_mao(): void
    {
        $conteudo = $this->lerArquivoJsx();

        // Regressão do quick 260911-exe: o nome da empresa é uma âncora, e
        // âncora dentro de <button> é HTML inválido.
        $this->assertMatchesRegularExpression(
            '/function FechamentoRow.*?<div\s+role="button"\s+tabIndex=\{0\}\s+aria-expanded=\{expandida\}/s',
            $conteudo,
            'A linha não pode voltar a ser <button> — o nome da empresa é uma âncora.'
        );
        $this->assertMatchesRegularExpression(
            '/function FechamentoRow.*?if \(e\.target !== e\.currentTarget\) return;/s',
            $conteudo,
            'O guard do onKeyDown precisa continuar lá, senão o Enter sobre o link navega E expande a linha.'
        );
    }
}
