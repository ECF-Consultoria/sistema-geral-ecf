<?php

namespace Tests\Feature\Phase139;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 139 Plano 06 (Tarefa 1) — trava de contrato da UI redesenhada de
 * Fechamento (`resources/js/Pages/Admin/Financeiro.jsx`).
 *
 * Por que existe: nesta fase um dado atravessou quase todo o caminho e
 * morreu no último trecho **três vezes** (139-CONTEXT.md, D-04). O backend
 * pode estar perfeito e a prop chegar certinha — e ainda assim o componente
 * renderizar vazio em silêncio, sem nenhum erro no console.
 *
 * O projeto não tem test runner de JS (nenhum `vitest`/`jest` no
 * `package.json`), então a trava é a mesma receita de
 * `Phase137FinanceiroUiContratoTest`: ler o `.jsx` como texto puro e
 * afirmar presença/ausência de trechos-chave.
 *
 * Três frentes:
 *  (1) PROPS — a resposta HTTP de `/administrativo/financeiro` traz as
 *      chaves novas (o lado do backend do contrato).
 *  (2) ARQUIVO — o `.jsx` consome essas mesmas chaves e continua com os
 *      componentes/literais que as Fases 137/138 já entregaram (o lado do
 *      frontend do contrato, incluindo a "morte no último trecho").
 *  (3) COPY SEM JARGÃO — nenhuma das sete palavras banidas por
 *      `139-CONTEXT.md` aparece em texto que o usuário lê. Comentários e
 *      nomes de prop/variável/componente (`competenciaFechada`,
 *      `tabela_origem`, `FecharCompetenciaButton`) são código, não copy, e
 *      não podem invalidar a asserção — por isso o conteúdo é filtrado
 *      (removendo blocos de comentário `/* *\/`/`{/* *\/}` e linhas `//`)
 *      antes de procurar as palavras, com casamento por fronteira de
 *      palavra (`\b`) para não confundir "origem" dentro de "tabela_origem".
 */
class Phase139FechamentoUiContratoTest extends TestCase
{
    use RefreshDatabase;

    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    private const ARQUIVO_TABELA_FAIXAS = 'js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx';

    /**
     * As sete palavras banidas pelo `139-CONTEXT.md` — proibidas em texto
     * visível da tela de Fechamento. A correção obrigatória do
     * plan-checker (2026-09-04) ampliou de quatro para sete: faltavam
     * "competência", "origem" e "faixa piso".
     */
    private const PALAVRAS_BANIDAS = [
        'snapshot',
        'reconsolidação',
        'rollup',
        'âncora',
        'competência',
        'origem',
        'faixa piso',
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

    /**
     * Mesmo padrão de `Phase137FinanceiroUiContratoTest`: a migration
     * `2026_09_02_100003_seed_faixas_faturamento_iniciais` já semeia as
     * faixas de "Gestão".
     */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    private function lerArquivoJsx(string $caminhoRelativo): string
    {
        return file_get_contents(resource_path($caminhoRelativo));
    }

    /**
     * Remove blocos de comentário (`/* ... *\/`, o que também cobre
     * `{/* ... *\/}` do JSX) e linhas `//`, deixando só o que o React
     * efetivamente renderiza como marcação/string. Sem isso, os seis
     * comentários com "competência" e os nomes de prop com "origem"
     * invalidariam a asserção sozinhos (armadilha registrada na correção
     * obrigatória do plan-checker deste plano).
     */
    private function removerComentarios(string $conteudo): string
    {
        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);

        return preg_replace('/^[ \t]*\/\/.*$/m', '', $semBlocos);
    }

    /**
     * Casamento por fronteira de palavra (case-insensitive, unicode) — não
     * usa `assertStringNotContainsString` porque isso confundiria "origem"
     * com o miolo de `tabela_origem` (identificador legítimo de código).
     */
    private function assertPalavraAusenteComoTextoVisivel(string $palavra, string $conteudoFiltrado, string $mensagem): void
    {
        $padrao = '/(?<![\p{L}\p{N}_])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

        $this->assertDoesNotMatchRegularExpression($padrao, $conteudoFiltrado, $mensagem);
    }

    // ─── Frente 1: PROPS ──────────────────────────────────────────────────

    #[Test]
    public function resposta_traz_totais_com_as_doze_chaves_do_plano_02_e_do_quick_260904_kwz(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Financeiro')
            ->has('totais', fn (Assert $totais) => $totais
                ->has('total_a_receber')
                ->has('total_e_piso')
                ->has('empresas_com_cobranca')
                ->has('empresas_sem_valor_definido')
                ->has('faturamento_gerado')
                ->has('mes_anterior_fechado')
                ->has('mes_anterior_total')
                ->has('variacao')
                ->has('upgrades_quantidade')
                ->has('upgrades_ganho_total')
                ->has('upgrades_ganho_parcial')
                // Quick 260904-kwz — contador de tabelas presumidas do
                // serviço, sem cadastro manual nem contrato assinado.
                ->has('tabelas_assumidas')
            )
        );
    }

    #[Test]
    public function toda_linha_de_companies_traz_as_quatro_chaves_de_comparacao_com_a_faixa_anterior(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = collect($response->viewData('page')['props']['companies']);

        $this->assertGreaterThan(0, $companies->count());

        foreach ($companies as $linha) {
            $this->assertArrayHasKey('faixa_ordem_anterior', $linha, 'Sem esta chave o widget "Subiram de faixa" não sabe escrever "Faixa 2 → 3".');
            $this->assertArrayHasKey('valor_faixa_anterior', $linha, 'Sem esta chave não dá para calcular o "+R$ X/mês" do upgrade.');
            $this->assertArrayHasKey('subiu_de_faixa', $linha, 'Sem esta chave a lista não sabe destacar a linha de quem subiu.');
            $this->assertArrayHasKey('ganho_faixa', $linha, 'Sem esta chave o card de upgrades não sabe somar o ganho.');
        }
    }

    // ─── Frente 2: ARQUIVO ──────────────────────────────────────────────────

    /**
     * A asserção central desta fase: mesmo que o backend emita as quatro
     * chaves novas e `totais` perfeitamente, se o JSX não referenciar essas
     * chaves em lugar nenhum, o dado morre no último trecho — exatamente
     * como já aconteceu três vezes (139-CONTEXT.md D-04).
     */
    #[Test]
    public function financeiro_jsx_consome_totais_e_as_quatro_chaves_novas_de_comparacao(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        foreach (['totais', 'faixa_ordem_anterior', 'valor_faixa_anterior', 'subiu_de_faixa', 'ganho_faixa', 'upgrades_ganho_total', 'mes_anterior_total'] as $chave) {
            $this->assertStringContainsString($chave, $conteudo, "Financeiro.jsx precisa referenciar `{$chave}` — sem isso o backend pode estar perfeito e a tela continuar sem o widget.");
        }
    }

    #[Test]
    public function financeiro_jsx_contem_os_tres_componentes_novos_do_topo(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        $this->assertStringContainsString('function TotalAReceberCard', $conteudo);
        $this->assertStringContainsString('function SubiramDeFaixaCard', $conteudo);
        $this->assertStringContainsString('function ServicosContratadosBar', $conteudo);
    }

    #[Test]
    public function financeiro_jsx_nao_contem_os_widgets_removidos_nem_recharts(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        foreach (['GraficoCobranca', 'GraficoFaixas', 'MiniPie', 'TotalConsolidado', 'recharts', 'cobranca_mensal_grupo'] as $trecho) {
            $this->assertStringNotContainsString($trecho, $conteudo, "`{$trecho}` deveria ter sido removido do redesenho (D-01/D-04).");
        }
    }

    #[Test]
    public function financeiro_jsx_continua_reaproveitando_os_componentes_das_fases_137_138(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        foreach ([
            'function FechamentoRow',
            'function ProgressaoModal',
            'function EvolucaoBadge',
            'function FechamentoAccordion',
            'function RefazerFechamentoDialog',
            'function FecharCompetenciaButton',
        ] as $trecho) {
            $this->assertStringContainsString($trecho, $conteudo, "`{$trecho}` é reaproveitado das Fases 137/138 — recriar do zero ou apagar é regressão de escopo (D-05).");
        }
    }

    #[Test]
    public function financeiro_jsx_preserva_os_literais_de_estado_e_composicao_das_fases_anteriores(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        foreach ([
            'A DEFINIR',
            'Sem faturamento neste mês',
            'Faturamento do mês',
            'Em aberto',
            'Refazer fechamento',
            'Motivo do reprocessamento',
            'Mercado Livre',
            'Shopee',
            'a partir de',
        ] as $literal) {
            $this->assertStringContainsString($literal, $conteudo, "O literal \"{$literal}\" precisa continuar na tela (D-05) — regressão do redesenho.");
        }
    }

    #[Test]
    public function financeiro_jsx_nao_reintroduz_acumulado_nem_a_forma_abreviada_fat_do_mes(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        $this->assertStringNotContainsStringIgnoringCase('acumulado', $conteudo, '"Acumulado" já foi banido por teste em fase anterior — não pode voltar no redesenho.');
        $this->assertStringNotContainsStringIgnoringCase('Fat. do mês', $conteudo, 'A forma abreviada é proibida por Phase137CompetenciaUiTest — o redesenho não pode reintroduzi-la.');
    }

    #[Test]
    public function financeiro_jsx_nao_introduz_instrument_sans_nem_jetbrains_mono(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        foreach (['Instrument Sans', 'JetBrains', 'fonts.googleapis'] as $trecho) {
            $this->assertStringNotContainsString($trecho, $conteudo, "D-02 mantém a tipografia do ECF Admin — \"{$trecho}\" foi oferecida e recusada pelo usuário.");
        }
    }

    // ─── Frente 3: COPY SEM JARGÃO ──────────────────────────────────────────

    /**
     * As sete palavras de `139-CONTEXT.md`, restritas ao texto que o
     * usuário lê. Cobre os dois arquivos que compõem a tela redesenhada.
     */
    #[Test]
    public function nenhuma_das_sete_palavras_banidas_aparece_como_texto_visivel(): void
    {
        foreach ([self::ARQUIVO_FINANCEIRO, self::ARQUIVO_TABELA_FAIXAS] as $arquivo) {
            $conteudoFiltrado = $this->removerComentarios($this->lerArquivoJsx($arquivo));

            foreach (self::PALAVRAS_BANIDAS as $palavra) {
                $this->assertPalavraAusenteComoTextoVisivel(
                    $palavra,
                    $conteudoFiltrado,
                    "\"{$palavra}\" é jargão banido por 139-CONTEXT.md e não pode aparecer como texto visível em {$arquivo} — o time Administrativo não entende o termo."
                );
            }
        }
    }
}
