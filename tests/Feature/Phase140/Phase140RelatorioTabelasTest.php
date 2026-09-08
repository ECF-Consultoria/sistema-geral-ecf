<?php

namespace Tests\Feature\Phase140;

use App\Console\Commands\ClicksignExtrairTabelas;
use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Services\Contratos\TabelaProgressivaContratoParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fase 140 Plano 03 (TAB-06) — cobre o comando `clicksign:extrair-tabelas` de
 * ponta a ponta: `Http::fake()` para listagem/documentos/download (zero
 * chamada real), `Storage::fake('local')` para os arquivos gerados,
 * `Company::factory()` para o casamento com empresa (140-CONTEXT.md D-05).
 *
 * ⚠️ Nomes de empresa fictícios (`EMPRESA FICTICIA ...`) — os mesmos das
 * fixtures de texto do plano 140-02, reaproveitados aqui como PDF via
 * `dompdf` (nenhum PDF de cliente real entra no repositório).
 */
class Phase140RelatorioTabelasTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sandbox.clicksign.com/api/v3';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clicksign.base_url'     => self::BASE,
            'services.clicksign.access_token' => 'token-falso-de-teste',
        ]);

        Storage::fake('local');
    }

    /**
     * PDF mínimo, mas legível, montado em tempo de teste via `dompdf` — o
     * mesmo recurso usado pelo Phase140ExtratorTextoTest (140-02).
     */
    private function pdfDoTexto(string $texto): string
    {
        $html = '<html><body><pre>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';

        return Pdf::loadHTML($html)->output();
    }

    private function textoFixture(string $nome): string
    {
        return file_get_contents(__DIR__ . "/fixtures/{$nome}.txt");
    }

    /**
     * Contrato fictício onde os dígitos de valores em reais viraram espaço
     * no texto extraído — o defeito medido pelo 140-02 em contratos antigos
     * (ago/2025), que agora sai do parser como tipo `numeros_ilegiveis`
     * (correção pós-rodada real, commit `539d3731`). CNPJ e nome
     * inteiramente fictícios.
     */
    private function textoNumerosIlegiveis(): string
    {
        return <<<TXT
        CONTRATANTE: EMPRESA FICTICIA NUMEROS ILEGIVEIS LTDA, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº 44.555.666/0001-77, com sede na Rua Exemplo, 400.

        CONTRATADA: ECF NEGOCIOS DIGITAIS LTDA, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº 63.381.851/0001-41.

        CLÁUSULA 3 — DO PAGAMENTO

        O valor mensal será de R\$  .   ,   (três mil reais), conforme tabela vigente.
        TXT;
    }

    /**
     * `$created = null` simula um envelope sem NENHUM atributo de data
     * conhecido — o caso que a rodada real de 2026-09-08 revelou (coluna de
     * data vazia em todas as linhas).
     *
     * @return array<string, mixed>
     */
    private function envelope(string $id, string $nome, string $status = 'closed', ?string $created = '2026-08-01T00:00:00-03:00'): array
    {
        $atributos = [
            'name'   => $nome,
            'status' => $status,
        ];

        if ($created !== null) {
            $atributos['created'] = $created;
        }

        return [
            'id'         => $id,
            'type'       => 'envelopes',
            'attributes' => $atributos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoResposta(string $documentId, string $linkOriginal, string $nomeArquivo = 'contrato.pdf'): array
    {
        return [
            'data' => [
                [
                    'id'         => $documentId,
                    'type'       => 'documents',
                    'attributes' => ['filename' => $nomeArquivo],
                    'links'      => [
                        'files' => ['original' => $linkOriginal],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function roda_de_ponta_a_ponta_gera_dois_arquivos_com_resumo_e_nao_altera_o_banco(): void
    {
        // Empresa que CASA por CNPJ com o contrato de tabela (140-02 fixture) — "casaram com
        // segurança".
        $empresaCasada = Company::factory()->create([
            'cnpj'         => '12.345.678/0001-90',
            'razao_social' => null,
        ]);

        // Nenhuma empresa parecida com o contrato de valor fixo — "duvidosos" + "valor fixo".
        Company::factory()->create([
            'name'         => 'Distribuidora Completamente Diferente Zeta',
            'cnpj'         => '00.000.000/0001-00',
            'razao_social' => null,
        ]);

        $qtdFaixasAntes = EmpresaFaixaFaturamento::count();

        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => [
                $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - EMPRESA ENVELOPE TABELA'),
                $this->envelope('ads-2', 'Contrato Gestao de Ads ECF - EMPRESA ENVELOPE VALOR FIXO'),
                $this->envelope('ads-3', 'Contrato Gestao de Ads ECF - EMPRESA ENVELOPE ILEGIVEL'),
            ]], 200),
            self::BASE . '/envelopes/ads-1/documents' => Http::response(
                $this->documentoResposta('doc-1', 'https://s3.example.com/ads-1.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-2/documents' => Http::response(
                $this->documentoResposta('doc-2', 'https://s3.example.com/ads-2.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-3/documents' => Http::response(
                $this->documentoResposta('doc-3', 'https://s3.example.com/ads-3.pdf'),
                200
            ),
            'https://s3.example.com/ads-1.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('tabela-notacao-nova')), 200),
            'https://s3.example.com/ads-2.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
            // Ilegível de propósito — nem %PDF nem PK.
            'https://s3.example.com/ads-3.pdf*' => Http::response('isto não é um contrato legível', 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->assertExitCode(0);

        // ─── Nada mudou no banco ───
        $this->assertSame($qtdFaixasAntes, EmpresaFaixaFaturamento::count());

        // ─── Os dois arquivos existem ───
        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md  = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $csv = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.csv'));

        $this->assertNotNull($md, 'esperava um arquivo .md em storage/app/private/relatorios');
        $this->assertNotNull($csv, 'esperava um arquivo .csv em storage/app/private/relatorios');

        $conteudoMd = Storage::disk('local')->get($md);

        // ─── Resumo bate ───
        $this->assertStringContainsString('Total varrido: **3**', $conteudoMd);
        $this->assertStringContainsString('Casaram com segurança: **1**', $conteudoMd);
        $this->assertStringContainsString('Ficaram duvidosos: **1**', $conteudoMd);
        $this->assertStringContainsString('São valor fixo: **1**', $conteudoMd);
        $this->assertStringContainsString('Não deu para ler: **1**', $conteudoMd);

        // ─── Linha "casaram com segurança" traz o nome da empresa e a confiança em palavras ───
        $this->assertStringContainsString($empresaCasada->name, $conteudoMd);
        $this->assertStringContainsString('confirmado pelo CNPJ', $conteudoMd);

        // ─── Linha de valor fixo aparece como valor fixo, com o valor lido ───
        $this->assertStringContainsString('valor fixo por mês', $conteudoMd);
        $this->assertStringContainsString('R$ 3.000,00', $conteudoMd);

        // ─── Contrato ilegível vira linha com motivo, não some do relatório ───
        $this->assertStringContainsString('ads-3', $conteudoMd);
        $this->assertStringContainsString('não deu para ler', $conteudoMd);

        // ─── CSV tem cabeçalho + 3 linhas de dado ───
        $conteudoCsv = Storage::disk('local')->get($csv);
        $linhasCsv   = array_filter(explode("\n", trim($conteudoCsv)));
        $this->assertCount(4, $linhasCsv); // cabeçalho + 3 contratos
    }

    #[Test]
    public function limite_processa_no_maximo_a_quantidade_pedida(): void
    {
        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => [
                $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - EMPRESA UM'),
                $this->envelope('ads-2', 'Contrato Gestao de Ads ECF - EMPRESA DOIS'),
                $this->envelope('ads-3', 'Contrato Gestao de Ads ECF - EMPRESA TRES'),
            ]], 200),
            self::BASE . '/envelopes/ads-1/documents' => Http::response(
                $this->documentoResposta('doc-1', 'https://s3.example.com/ads-1.pdf'),
                200
            ),
            'https://s3.example.com/ads-1.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0, '--limite' => 1])
            ->assertExitCode(0);

        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md       = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $conteudo = Storage::disk('local')->get($md);

        $this->assertStringContainsString('Total varrido: **1**', $conteudo);

        // Só o documento do primeiro envelope foi consultado — o limite parou a varredura antes
        // de chegar nos outros dois.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/envelopes/ads-2/documents'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/envelopes/ads-3/documents'));
    }

    #[Test]
    public function aviso_de_nao_commitar_e_impresso_no_terminal(): void
    {
        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->expectsOutputToContain('não commite')
            ->assertExitCode(0);
    }

    /**
     * Trava de regressão do defeito relatado após a rodada real de
     * 2026-09-08: a coluna de data saiu vazia em TODAS as 10 linhas, e o
     * relatório mostrava só "-" — o mesmo traço genérico usado para
     * "campo não se aplica" em qualquer outra coluna, sem dizer que o dado
     * FALTOU. Mesma disciplina de honestidade do palpite de empresa: célula
     * vazia precisa dizer que faltou, nunca ficar muda.
     */
    #[Test]
    public function contrato_sem_data_conhecida_mostra_aviso_honesto_em_vez_de_traco_mudo(): void
    {
        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => [
                $this->envelope('ads-1', 'Contrato Gestao de Ads ECF - EMPRESA SEM DATA', 'closed', null),
            ]], 200),
            self::BASE . '/envelopes/ads-1/documents' => Http::response(
                $this->documentoResposta('doc-1', 'https://s3.example.com/ads-1.pdf'),
                200
            ),
            'https://s3.example.com/ads-1.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->assertExitCode(0);

        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md       = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $conteudo = Storage::disk('local')->get($md);

        $this->assertStringContainsString('data não informada pela Clicksign', $conteudo);
    }

    /**
     * "Considere ordenar o relatório por data — ajuda a leitura humana das
     * 123 linhas, já que a virada de dezembro/2025 fica visível de bater o
     * olho" (pedido do coordenador após a rodada real). Três envelopes fora
     * de ordem cronológica na resposta da API; o relatório precisa sair da
     * mais antiga para a mais recente.
     */
    #[Test]
    public function relatorio_sai_ordenado_da_data_mais_antiga_para_a_mais_recente(): void
    {
        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => [
                $this->envelope('ads-meio', 'Contrato Gestao de Ads ECF - EMPRESA MEIO', 'closed', '2026-03-15T00:00:00-03:00'),
                $this->envelope('ads-antigo', 'Contrato Gestao de Ads ECF - EMPRESA ANTIGA', 'closed', '2025-11-01T00:00:00-03:00'),
                $this->envelope('ads-recente', 'Contrato Gestao de Ads ECF - EMPRESA RECENTE', 'closed', '2026-08-20T00:00:00-03:00'),
            ]], 200),
            self::BASE . '/envelopes/ads-meio/documents' => Http::response(
                $this->documentoResposta('doc-meio', 'https://s3.example.com/ads-meio.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-antigo/documents' => Http::response(
                $this->documentoResposta('doc-antigo', 'https://s3.example.com/ads-antigo.pdf'),
                200
            ),
            self::BASE . '/envelopes/ads-recente/documents' => Http::response(
                $this->documentoResposta('doc-recente', 'https://s3.example.com/ads-recente.pdf'),
                200
            ),
            'https://s3.example.com/ads-meio.pdf*'    => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
            'https://s3.example.com/ads-antigo.pdf*'  => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
            'https://s3.example.com/ads-recente.pdf*' => Http::response($this->pdfDoTexto($this->textoFixture('valor-fixo')), 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->assertExitCode(0);

        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md       = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $conteudo = Storage::disk('local')->get($md);

        $posicaoAntigo  = strpos($conteudo, 'ads-antigo');
        $posicaoMeio    = strpos($conteudo, 'ads-meio');
        $posicaoRecente = strpos($conteudo, 'ads-recente');

        $this->assertNotFalse($posicaoAntigo);
        $this->assertNotFalse($posicaoMeio);
        $this->assertNotFalse($posicaoRecente);

        $this->assertLessThan($posicaoMeio, $posicaoAntigo, 'contrato mais antigo deveria vir antes do do meio');
        $this->assertLessThan($posicaoRecente, $posicaoMeio, 'contrato do meio deveria vir antes do mais recente');

        // Data formatada para leitura humana, não a string ISO crua.
        $this->assertStringContainsString('01/11/2025', $conteudo);
        $this->assertStringContainsString('20/08/2026', $conteudo);
    }

    // ─── numeros_ilegiveis (140-02, correção pós-rodada real) ───

    /**
     * Trava de regressão: o 140-02 acrescentou um tipo novo ao parser
     * (`numeros_ilegiveis`, commit `539d3731`) e o comando ficou sem
     * rótulo para ele — `TIPO_LABEL[$tipo] ?? $tipo` faria o relatório
     * imprimir o nome cru da constante. Copy sem jargão: nada de
     * "ilegível", "parser", "extração", "OCR".
     */
    #[Test]
    public function numeros_ilegiveis_tem_rotulo_sem_jargao(): void
    {
        $this->assertArrayHasKey('numeros_ilegiveis', ClicksignExtrairTabelas::TIPO_LABEL);

        $rotulo = ClicksignExtrairTabelas::TIPO_LABEL['numeros_ilegiveis'];

        foreach (['ilegível', 'ilegivel', 'parser', 'extração', 'extracao', 'ocr'] as $jargao) {
            $this->assertStringNotContainsStringIgnoringCase($jargao, $rotulo, "rótulo de numeros_ilegiveis não pode usar jargão ('{$jargao}')");
        }
    }

    /**
     * A trava pedida pelo coordenador: todo tipo que
     * `TabelaProgressivaContratoParser::analisar()` PODE emitir (lido do
     * próprio docblock de retorno do 140-02, não copiado à mão aqui) tem
     * que ter rótulo em `ClicksignExtrairTabelas::TIPO_LABEL`. Se alguém
     * acrescentar um tipo novo ao parser amanhã sem vir aqui, este teste
     * avisa — em vez do relatório imprimir o nome cru da constante em
     * produção (o padrão que já mordeu três vezes nesta linha de
     * trabalho).
     */
    #[Test]
    public function todo_tipo_que_o_parser_pode_emitir_tem_rotulo_no_comando(): void
    {
        $reflexao = new ReflectionMethod(TabelaProgressivaContratoParser::class, 'analisar');
        $docblock = (string) $reflexao->getDocComment();

        $this->assertMatchesRegularExpression(
            '/tipo:\s*(?:\'[a-z_]+\'\|?)+/',
            $docblock,
            'não achei a união de tipos no docblock de analisar() — o parser mudou de forma inesperada, confira manualmente'
        );

        preg_match('/tipo:\s*((?:\'[a-z_]+\'\|?)+)/', $docblock, $uniao);
        preg_match_all('/\'([a-z_]+)\'/', $uniao[1], $tiposEncontrados);
        $tipos = $tiposEncontrados[1];

        $this->assertNotEmpty($tipos, 'não consegui extrair nenhum tipo do docblock de analisar()');

        foreach ($tipos as $tipo) {
            $this->assertArrayHasKey(
                $tipo,
                ClicksignExtrairTabelas::TIPO_LABEL,
                "o parser pode emitir o tipo '{$tipo}', mas o comando não tem rótulo para ele em TIPO_LABEL"
            );
        }
    }

    /**
     * Ponta a ponta: um contrato com dígitos apagados no PDF não pode virar
     * "valor fixo" nem "casaram com segurança" no resumo — mesmo quando o
     * CNPJ bate com uma empresa cadastrada (a linha ainda precisa de
     * conferência manual por causa do valor, então não é "resolvida"). Tem
     * que entrar em "não deu para ler", e a coluna de faixas precisa mostrar
     * o aviso do parser, nunca um "-" mudo.
     */
    #[Test]
    public function contrato_com_numeros_ilegiveis_nao_conta_como_valor_fixo_nem_como_casaram_com_seguranca(): void
    {
        // CNPJ do fixture bate com esta empresa — prova que a exclusão é
        // deliberada, não um acaso de "não achou candidato".
        Company::factory()->create([
            'cnpj'         => '44.555.666/0001-77',
            'razao_social' => null,
        ]);

        Http::fake([
            self::BASE . '/envelopes?*' => Http::response(['data' => [
                $this->envelope('ads-numeros', 'Contrato Gestao de Ads ECF - EMPRESA NUMEROS ILEGIVEIS'),
            ]], 200),
            self::BASE . '/envelopes/ads-numeros/documents' => Http::response(
                $this->documentoResposta('doc-numeros', 'https://s3.example.com/ads-numeros.pdf'),
                200
            ),
            'https://s3.example.com/ads-numeros.pdf*' => Http::response($this->pdfDoTexto($this->textoNumerosIlegiveis()), 200),
        ]);

        $this->artisan('clicksign:extrair-tabelas', ['--pausa-ms' => 0])
            ->assertExitCode(0);

        $arquivos = Storage::disk('local')->allFiles('relatorios');
        $md       = collect($arquivos)->first(fn ($f) => str_ends_with($f, '.md'));
        $conteudo = Storage::disk('local')->get($md);

        // Resumo: nem valor fixo, nem casaram com segurança — conta como
        // "não deu para ler".
        $this->assertStringContainsString('Casaram com segurança: **0**', $conteudo);
        $this->assertStringContainsString('São valor fixo: **0**', $conteudo);
        $this->assertStringContainsString('Não deu para ler: **1**', $conteudo);

        // Rótulo sem jargão na linha, não o nome cru da constante.
        $this->assertStringNotContainsString('numeros_ilegiveis', $conteudo);
        $this->assertStringContainsString(ClicksignExtrairTabelas::TIPO_LABEL['numeros_ilegiveis'], $conteudo);

        // Coluna de faixas usa o aviso pronto do parser — não fica muda.
        $this->assertStringContainsString('precisa abrir o contrato à mão', $conteudo);
    }
}
