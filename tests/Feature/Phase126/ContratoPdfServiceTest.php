<?php

namespace Tests\Feature\Phase126;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Services\ContratoPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ContratoPdfServiceTest — Fase 126, plano 05 (PDF-01/PDF-02/PDF-03).
 *
 * Régua de três camadas (ver `<como_testar_pdf>` do 126-05-PLAN.md) — o binário do
 * Dompdf é comprimido, então asserção de string sobre ele não é confiável:
 *
 * 1. HTML renderizado (`view('contratos.pdf', ...)->render()`) — onde se assere
 *    conteúdo: razão social, CNPJ, contato, cada serviço, valores, vigência, placeholders.
 * 2. CSS da view (leitura do arquivo) — onde se assere `DejaVu Sans`,
 *    `page-break-inside: avoid` e a quebra de palavra longa.
 * 3. Binário (`ContratoPdfService::gerar()`) — assere apenas que começa com `%PDF-`,
 *    tem tamanho plausível, e que o caso de nome extremo não estoura nem lança exceção.
 */
class ContratoPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $caminhoClausulas;

    private string $conteudoOriginalClausulas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caminhoClausulas = resource_path('views/contratos/clausulas.blade.php');
        $this->conteudoOriginalClausulas = file_get_contents($this->caminhoClausulas);
    }

    /**
     * Restaura a view de cláusulas MESMO SE o teste falhar no meio — um teste que
     * deixa a view do contrato corrompida no repositório é pior do que não existir
     * (T-126-24 do threat model do plano 126-05).
     */
    protected function tearDown(): void
    {
        if (file_exists($this->caminhoClausulas)) {
            file_put_contents($this->caminhoClausulas, $this->conteudoOriginalClausulas);
        }
        Artisan::call('view:clear');

        parent::tearDown();
    }

    /**
     * Monta um contrato com snapshot de 3 serviços e contato já preenchido —
     * mesmo helper usado em ContratoPdfDadosTest (plano 126-04), para isolar os
     * testes de conteúdo do que cada um realmente quer provar.
     */
    private function contratoComSnapshot(array $atributosCompany = []): ContratoAssinatura
    {
        $atributosPadrao = [
            'nome_contato'  => 'Contato Padrão do Teste',
            'email_cliente' => 'contato@empresa-teste.com.br',
            'telefone'      => '(11) 4000-0000',
        ];

        return ContratoAssinatura::factory()
            ->comSnapshot()
            ->for(Company::factory()->state(array_merge($atributosPadrao, $atributosCompany)), 'company')
            ->create();
    }

    // ═══ PDF-01 — conteúdo ═══

    #[Test]
    public function html_renderizado_contem_razao_social_cnpj_contato_servicos_total_e_vigencia(): void
    {
        $contrato = $this->contratoComSnapshot([
            'name' => 'Empresa Exemplo Comércio Ltda',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $dados = (new ContratoPdfService())->montarDados($contrato, [
            'dia_vencimento'  => 'Todo dia 10',
            'forma_pagamento' => 'Boleto bancário',
            'endereco'        => 'Rua Exemplo, 123 - São Paulo/SP',
        ]);

        $html = view('contratos.pdf', ['dados' => $dados])->render();

        $this->assertStringContainsString('Empresa Exemplo Comércio Ltda', $html, 'PDF-01: razão social ausente do HTML.');
        $this->assertStringContainsString('12.345.678/0001-90', $html, 'PDF-01: CNPJ ausente do HTML.');
        $this->assertStringContainsString($dados['contato']['nome'], $html, 'PDF-01: nome do contato ausente do HTML.');
        $this->assertStringContainsString($dados['contato']['email'], $html, 'PDF-01: e-mail do contato ausente do HTML.');
        $this->assertStringContainsString($dados['contato']['telefone'], $html, 'PDF-01: telefone do contato ausente do HTML.');

        foreach ($dados['servicos'] as $servico) {
            $this->assertStringContainsString($servico['servico'], $html, 'PDF-01: nome do serviço ausente do HTML.');
            $this->assertStringContainsString($servico['valor_formatado'], $html, 'PDF-01: valor formatado do serviço ausente do HTML.');
        }

        $this->assertStringContainsString($dados['totais']['valor_mensal_formatado'], $html, 'PDF-01: total mensal ausente do HTML.');
        $this->assertStringContainsString($dados['vigencia']['inicio'], $html, 'PDF-01: data de início da vigência ausente do HTML.');
        $this->assertStringContainsString($dados['vigencia']['fim'], $html, 'PDF-01: data de fim da vigência ausente do HTML.');
    }

    #[Test]
    public function sem_complementos_o_html_mostra_a_definir_visivel_nos_tres_campos_pendentes(): void
    {
        $contrato = $this->contratoComSnapshot();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $html = view('contratos.pdf', ['dados' => $dados])->render();

        // >= 3, não ===: endereco é citado tanto no resumo da qualificação (pdf.blade.php)
        // quanto na cláusula de qualificação completa das partes (clausulas.blade.php) —
        // repetição legítima do mesmo dado pendente, não bug.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, ContratoPdfService::PLACEHOLDER),
            'PDF-01: esperava pelo menos 3 placeholders "A DEFINIR" visíveis no HTML (dia_vencimento, forma_pagamento, endereco) — documento jurídico não pode ter campo em branco silencioso.'
        );
    }

    // ═══ PDF-02 — independência entre texto jurídico e dados ═══

    #[Test]
    public function editar_clausulas_blade_nao_altera_o_array_de_montardados(): void
    {
        $contrato = $this->contratoComSnapshot();
        $service = new ContratoPdfService();

        $dadosAntes = $service->montarDados($contrato);

        file_put_contents(
            $this->caminhoClausulas,
            '<div class="section"><p>Texto jurídico temporário alterado pelo teste.</p></div>'
        );
        Artisan::call('view:clear');

        $dadosDepois = $service->montarDados($contrato);

        $this->assertSame($dadosAntes, $dadosDepois, 'PDF-02: alterar o texto jurídico não pode mudar um dado sequer.');

        // A view continua renderizando com o texto alterado — prova que o @include
        // realmente troca de conteúdo sem afetar montarDados().
        $html = view('contratos.pdf', ['dados' => $dadosDepois])->render();
        $this->assertStringContainsString('Texto jurídico temporário alterado pelo teste.', $html);
    }

    #[Test]
    public function pdf_blade_inclui_a_view_de_clausulas(): void
    {
        $conteudoLayout = file_get_contents(resource_path('views/contratos/pdf.blade.php'));

        $this->assertMatchesRegularExpression("/@include\\('contratos\\.clausulas'/", $conteudoLayout, 'PDF-02: pdf.blade.php precisa incluir contratos.clausulas.');
    }

    #[Test]
    public function clausulas_blade_nao_acessa_banco_nem_usa_raw_html(): void
    {
        $conteudo = file_get_contents($this->caminhoClausulas);

        $this->assertStringNotContainsString('App\\Models', $conteudo, 'PDF-02: cláusulas não podem referenciar Model.');
        $this->assertStringNotContainsString('::where', $conteudo, 'PDF-02: cláusulas não podem consultar o banco.');
        $this->assertStringNotContainsString('DB::', $conteudo, 'PDF-02: cláusulas não podem acessar DB:: diretamente.');
        $this->assertStringNotContainsString('->get(', $conteudo, 'PDF-02: cláusulas não podem chamar ->get() de query.');
        $this->assertStringNotContainsString('{!!', $conteudo, 'PDF-02: cláusulas não podem usar {!! !!} (dado de banco não pode injetar markup).');
    }

    // ═══ PDF-03 — acentuação, nome extremo e quebra de página ═══

    #[Test]
    public function html_preserva_acentuacao_utf8_e_css_declara_dejavu_sans(): void
    {
        $contrato = $this->contratoComSnapshot([
            'name' => 'Distribuição e Comércio Ção-Ão Ltda',
        ]);

        $dados = (new ContratoPdfService())->montarDados($contrato);
        $html = view('contratos.pdf', ['dados' => $dados])->render();

        $this->assertStringContainsString('Distribuição e Comércio Ção-Ão Ltda', $html, 'PDF-03: acentuação pt-BR não preservada no HTML.');

        $css = file_get_contents(resource_path('views/contratos/pdf.blade.php'));
        $this->assertStringContainsString('DejaVu Sans', $css, 'PDF-03: CSS precisa declarar DejaVu Sans.');
    }

    #[Test]
    public function nome_de_empresa_extremo_gera_binario_pdf_valido_sem_excecao(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comEmpresaDeNomeExtremo()
            ->comSnapshot()
            ->create();

        $binario = (new ContratoPdfService())->gerar($contrato);

        $this->assertStringStartsWith('%PDF-', $binario, 'PDF-03: binário não começa com %PDF- para nome de empresa extremo.');
        $this->assertGreaterThan(1024, strlen($binario), 'PDF-03: binário do PDF com tamanho implausível.');

        // PDF concreto salvo em storage/app/contratos/ (fora do Storage::fake) — insumo
        // para a inspeção visual humana do checkpoint do plano 126-06.
        Storage::disk('local')->put('contratos/contrato-nome-extremo-inspecao.pdf', $binario);
        $this->assertTrue(
            Storage::disk('local')->exists('contratos/contrato-nome-extremo-inspecao.pdf'),
            'PDF-03: PDF de inspeção do nome extremo não foi gravado para o checkpoint 126-06.'
        );
    }

    #[Test]
    public function css_aplica_quebra_de_palavra_nas_celulas_de_dados_variaveis(): void
    {
        $css = file_get_contents(resource_path('views/contratos/pdf.blade.php'));

        $this->assertStringContainsString('word-wrap: break-word', $css, 'PDF-03: falta word-wrap: break-word no CSS.');
        $this->assertStringContainsString('overflow-wrap: break-word', $css, 'PDF-03: falta overflow-wrap: break-word no CSS.');
        $this->assertStringContainsString('table-layout: fixed', $css, 'PDF-03: falta table-layout: fixed nas tabelas de dados variáveis.');
    }

    #[Test]
    public function todo_bloco_logico_do_layout_e_das_clausulas_esta_dentro_de_page_break_inside_avoid(): void
    {
        $css = file_get_contents(resource_path('views/contratos/pdf.blade.php'));
        $this->assertStringContainsString('page-break-inside: avoid', $css, 'PDF-03: falta page-break-inside: avoid no CSS.');

        // Cada bloco lógico do layout usa a classe .section, que carrega a regra acima.
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($css, 'class="section'),
            'PDF-03: esperava pelo menos 5 blocos .section no layout (qualificação, objeto, serviços, vigência, pagamento).'
        );

        // As cláusulas (texto jurídico) também são blocos .section — cada cláusula
        // isolada, para nenhuma ser cortada no meio de uma página.
        $clausulas = file_get_contents($this->caminhoClausulas);
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($clausulas, 'class="section'),
            'PDF-03: esperava pelo menos 5 cláusulas isoladas em blocos .section.'
        );
    }

    // ═══ D-06 — arquivo salvo em disco privado ═══

    #[Test]
    public function gerar_e_salvar_grava_em_disco_privado_e_anota_pdf_path(): void
    {
        Storage::fake('local');

        $contrato = $this->contratoComSnapshot();

        $caminho = (new ContratoPdfService())->gerarESalvar($contrato);

        $this->assertSame("contratos/contrato-{$contrato->id}.pdf", $caminho, 'D-06: caminho relativo devolvido não segue o padrão contratos/contrato-{id}.pdf.');
        Storage::disk('local')->assertExists($caminho);

        $contrato->refresh();
        $this->assertSame($caminho, $contrato->pdf_path, 'D-06: pdf_path do contrato não foi atualizado.');
    }

    #[Test]
    public function gerar_e_salvar_nao_escreve_em_disco_publico(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $contrato = $this->contratoComSnapshot();

        (new ContratoPdfService())->gerarESalvar($contrato);

        $this->assertSame([], Storage::disk('public')->allFiles(), 'D-06/T-126-20: nada pode ser escrito no disco público — o PDF traz CNPJ, contato e valores.');
    }

    #[Test]
    public function regerar_o_mesmo_contrato_sobrescreve_o_arquivo_e_mantem_pdf_path_coerente(): void
    {
        Storage::fake('local');

        $contrato = $this->contratoComSnapshot();
        $service = new ContratoPdfService();

        $caminho1 = $service->gerarESalvar($contrato);
        $caminho2 = $service->gerarESalvar($contrato);

        $this->assertSame($caminho1, $caminho2, 'D-06: regerar o mesmo contrato deveria manter o mesmo caminho.');
        Storage::disk('local')->assertExists($caminho2);

        $contrato->refresh();
        $this->assertSame($caminho2, $contrato->pdf_path);
    }

    #[Test]
    public function service_nao_usa_disco_publico_nem_public_path(): void
    {
        $codigo = file_get_contents(app_path('Services/ContratoPdfService.php'));

        $this->assertDoesNotMatchRegularExpression("/disk\\('public'\\)/", $codigo, 'T-126-20: service não pode usar disco público.');
        $this->assertDoesNotMatchRegularExpression('/public_path\(/', $codigo, 'T-126-20: service não pode usar public_path().');
    }
}
