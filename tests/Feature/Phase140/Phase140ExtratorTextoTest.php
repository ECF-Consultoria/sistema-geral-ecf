<?php

namespace Tests\Feature\Phase140;

use App\Services\Contratos\ExtratorTextoContratoService;
use App\Services\Contratos\TabelaProgressivaContratoParser;
use Barryvdh\DomPDF\Facade\Pdf;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Fase 140 Plano 02 — Tarefa 1: ExtratorTextoContratoService.
 *
 * Todos os binários usados aqui são montados EM TEMPO DE TESTE (PDF mínimo via `dompdf`, que já
 * está no projeto, e ZIP via `ZipArchive` num arquivo temporário) — nenhum PDF de cliente real
 * entra no repositório (T-140-08 do threat_model da Fase 140).
 */
class Phase140ExtratorTextoTest extends TestCase
{
    /**
     * Gera um PDF mínimo, mas legível, via dompdf (já é dependência do projeto).
     */
    private function pdfMinimo(string $texto = 'Contrato de teste — Fase 140'): string
    {
        return Pdf::loadHTML('<html><body><p>' . $texto . '</p></body></html>')->output();
    }

    /**
     * Monta um ZIP em disco (arquivo temporário) contendo as entradas informadas
     * (nome => conteúdo), devolve o binário do ZIP e apaga o temporário.
     *
     * @param  array<string, string>  $entradas
     */
    private function zipComEntradas(array $entradas): string
    {
        $temporario = tempnam(sys_get_temp_dir(), 'phase140_zip_teste_');
        // ZipArchive::open exige que o arquivo NÃO exista ainda quando OVERWRITE não é usado —
        // apagamos o que o tempnam() criou e recriamos como ZIP.
        @unlink($temporario);

        $zip = new ZipArchive();
        $zip->open($temporario, ZipArchive::CREATE);

        foreach ($entradas as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }

        $zip->close();

        $binario = file_get_contents($temporario);
        @unlink($temporario);

        return $binario;
    }

    /**
     * Monta um `.docx` MÍNIMO em memória: um ZIP com `[Content_Types].xml` (o que faz o pacote ser
     * reconhecido como OOXML) e `word/document.xml` com o conteúdo informado — é o suficiente para
     * o extrator reconhecer e ler, sem precisar de um `.docx` real de cliente no repositório
     * (T-140-08 do threat_model).
     */
    private function docxComDocumentXml(string $documentXml): string
    {
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>';

        return $this->zipComEntradas([
            '[Content_Types].xml' => $contentTypes,
            'word/document.xml'   => $documentXml,
        ]);
    }

    /**
     * `word/document.xml` fictício com qualificação (CONTRATANTE/CONTRATADA, CNPJs fictícios) e uma
     * tabela progressiva na NOTAÇÃO NOVA (4 faixas — o suficiente para bater o mínimo de 3 marcos do
     * parser), estruturada como tabela de verdade do Word (`<w:tbl>`/`<w:tr>`/`<w:tc>`) — é essa
     * estrutura que prova que `</w:tc>`/`</w:tr>` viram separador/quebra corretos, não `</w:p>`.
     */
    private function documentXmlComTabelaFicticia(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
        <w:body>
        <w:p><w:r><w:t>CONTRATANTE: EMPRESA FICTICIA DOCX LTDA, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº 12.345.678/0001-90.</w:t></w:r></w:p>
        <w:p><w:r><w:t>CONTRATADA: ECF FICTICIA HOLDING LTDA, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº 99.888.777/0001-11.</w:t></w:r></w:p>
        <w:tbl>
        <w:tr><w:tc><w:p><w:r><w:t>Até R$500.000,00</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>R$ 3.000,00</w:t></w:r></w:p></w:tc></w:tr>
        <w:tr><w:tc><w:p><w:r><w:t>A partir de R$ 500.000,00</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>R$ 4.500,00</w:t></w:r></w:p></w:tc></w:tr>
        <w:tr><w:tc><w:p><w:r><w:t>A partir de R$ 1.000.000,00</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>R$ 6.000,00</w:t></w:r></w:p></w:tc></w:tr>
        <w:tr><w:tc><w:p><w:r><w:t>Acima de R$ 5.000.000,00</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>a partir de R$ 12.000,00</w:t></w:r></w:p></w:tc></w:tr>
        </w:tbl>
        </w:body>
        </w:document>
        XML;
    }

    #[Test]
    public function le_o_texto_de_um_pdf_valido(): void
    {
        $pdf = $this->pdfMinimo('Texto exclusivo do teste de PDF válido');

        $resultado = (new ExtratorTextoContratoService())->extrair($pdf);

        $this->assertSame('pdf', $resultado['formato']);
        $this->assertNull($resultado['motivo']);
        $this->assertNotNull($resultado['texto']);
        $this->assertStringContainsString('Texto exclusivo do teste de PDF válido', $resultado['texto']);
    }

    #[Test]
    public function le_o_texto_do_primeiro_pdf_de_dentro_de_um_zip(): void
    {
        $pdf = $this->pdfMinimo('Texto exclusivo do teste de ZIP com PDF');
        $zip = $this->zipComEntradas(['contrato.pdf' => $pdf]);

        $this->assertStringStartsWith('PK', $zip);

        $resultado = (new ExtratorTextoContratoService())->extrair($zip);

        $this->assertSame('zip', $resultado['formato']);
        $this->assertNull($resultado['motivo']);
        $this->assertNotNull($resultado['texto']);
        $this->assertStringContainsString('Texto exclusivo do teste de ZIP com PDF', $resultado['texto']);
    }

    #[Test]
    public function zip_sem_nenhum_pdf_dentro_devolve_motivo_sem_excecao(): void
    {
        $zip = $this->zipComEntradas(['leiame.txt' => 'não é um contrato']);

        $resultado = (new ExtratorTextoContratoService())->extrair($zip);

        $this->assertSame('zip', $resultado['formato']);
        $this->assertNull($resultado['texto']);
        $this->assertSame('o pacote não tem nenhum contrato em PDF', $resultado['motivo']);
    }

    #[Test]
    public function lixo_com_formato_desconhecido_devolve_motivo_sem_excecao(): void
    {
        $resultado = (new ExtratorTextoContratoService())->extrair('isto não é PDF nem ZIP, é lixo qualquer');

        $this->assertSame('desconhecido', $resultado['formato']);
        $this->assertNull($resultado['texto']);
        $this->assertNotNull($resultado['motivo']);
    }

    #[Test]
    public function arquivo_acima_do_teto_devolve_motivo_sem_tentar_abrir(): void
    {
        config(['services.clicksign.max_leitura_bytes' => 10]);

        $pdf = $this->pdfMinimo(); // certamente maior que 10 bytes

        $resultado = (new ExtratorTextoContratoService())->extrair($pdf);

        $this->assertNull($resultado['texto']);
        $this->assertNotNull($resultado['motivo']);
    }

    #[Test]
    public function pdf_corrompido_devolve_motivo_em_vez_de_derrubar_quem_chamou(): void
    {
        // Cabeçalho válido de PDF, mas conteúdo em seguida é lixo — o parser precisa
        // capturar o Throwable e devolver motivo, nunca propagar a exceção.
        $resultado = (new ExtratorTextoContratoService())->extrair('%PDF-1.4 isto não é uma estrutura de PDF válida, é lixo');

        $this->assertSame('pdf', $resultado['formato']);
        $this->assertNull($resultado['texto']);
        $this->assertNotNull($resultado['motivo']);
    }

    #[Test]
    public function zip_com_entrada_grande_demais_devolve_motivo_pelo_tamanho_descomprimido(): void
    {
        $pdf = $this->pdfMinimo('Texto de sobra para ocupar espaço no PDF gerado por este teste específico.');
        $zip = $this->zipComEntradas(['contrato.pdf' => $pdf]);

        // Teto deliberadamente ENTRE o tamanho do ZIP (comprimido) e o tamanho do PDF
        // descomprimido dentro dele — só o guard de entrada (statIndex) pega isso; o guard do
        // binário externo (bullet 1 da Tarefa 1) deixaria passar, porque o ZIP em si é menor.
        $teto = (int) floor((strlen($zip) + strlen($pdf)) / 2);
        $this->assertGreaterThanOrEqual(strlen($zip), $teto, 'pré-condição do teste');
        $this->assertLessThan(strlen($pdf), $teto, 'pré-condição do teste');

        config(['services.clicksign.max_leitura_bytes' => $teto]);

        $resultado = (new ExtratorTextoContratoService())->extrair($zip);

        $this->assertNull($resultado['texto']);
        $this->assertSame('o contrato dentro do pacote é grande demais para ler', $resultado['motivo']);
    }

    // ═══ CORREÇÃO PÓS-RODADA REAL #3 (2026-09-08) — `.docx` dentro do ZIP ═══
    //
    // Varredura completa em produção (85 contratos, deploy anterior): 19 falhas, todas com o mesmo
    // motivo "o pacote não tem nenhum contrato em PDF" — e são justamente os contratos de 2026, os
    // mais recentes. Causa: esses envelopes foram gerados a partir do MODELO da Clicksign, então o
    // arquivo `original` que sobe é o `.docx` (OOXML) que ela usou para montar o PDF depois, não um
    // PDF em si. `.docx` tem o MESMO cabeçalho `PK` de qualquer ZIP — a diferença está no conteúdo:
    // `[Content_Types].xml` + `word/document.xml`.

    #[Test]
    public function reconhece_docx_pelo_conteudo_extrai_texto_e_a_tabela_e_lida_pelo_parser(): void
    {
        $docx = $this->docxComDocumentXml($this->documentXmlComTabelaFicticia());

        $this->assertStringStartsWith('PK', $docx, 'pré-condição: .docx tem o mesmo cabeçalho de qualquer ZIP');

        $resultado = (new ExtratorTextoContratoService())->extrair($docx);

        $this->assertSame('docx', $resultado['formato']);
        $this->assertNull($resultado['motivo']);
        $this->assertNotNull($resultado['texto']);
        $this->assertStringContainsString('CONTRATANTE: EMPRESA FICTICIA DOCX LTDA', $resultado['texto']);
        // A marcação do Word (`<w:tc>`, `<w:tr>`, `<w:p>`...) não pode vazar pro texto — só o
        // conteúdo visível dos `<w:t>`.
        $this->assertStringNotContainsString('<w:', $resultado['texto']);

        // Prova de ponta a ponta que o coordenador pediu: o texto extraído do .docx alimenta o
        // parser de tabela normalmente, sem tradutor no meio.
        $analise = (new TabelaProgressivaContratoParser())->analisar($resultado['texto']);

        $this->assertSame('tabela', $analise['tipo']);
        $this->assertCount(4, $analise['faixas']);
        $this->assertSame(500_000.0, $analise['faixas'][0]['limite_superior']);
        $this->assertSame(3_000.0, $analise['faixas'][0]['valor']);
        $this->assertNull($analise['faixas'][3]['limite_superior']);
        $this->assertSame(12_000.0, $analise['faixas'][3]['valor']);
        $this->assertTrue($analise['faixas'][3]['valor_e_piso']);
        $this->assertSame('12345678000190', $analise['cnpj']);
        $this->assertSame('EMPRESA FICTICIA DOCX LTDA', $analise['razao_social']);
    }

    #[Test]
    public function zip_com_content_types_mas_sem_word_document_xml_nao_e_tratado_como_docx(): void
    {
        // Guarda de precisão: um `.xlsx`/`.pptx` (outro formato OOXML) também tem
        // `[Content_Types].xml`, mas NÃO tem `word/document.xml` — não pode ser confundido com
        // `.docx`; deve cair no caminho normal de busca por `.pdf` dentro do ZIP.
        $zip = $this->zipComEntradas([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'xl/workbook.xml'     => '<workbook/>',
        ]);

        $resultado = (new ExtratorTextoContratoService())->extrair($zip);

        $this->assertSame('zip', $resultado['formato']);
        $this->assertSame('o pacote não tem nenhum contrato em PDF', $resultado['motivo']);
    }

    #[Test]
    public function docx_com_entrada_grande_demais_devolve_motivo_pelo_tamanho_descomprimido(): void
    {
        $xml  = $this->documentXmlComTabelaFicticia();
        $docx = $this->docxComDocumentXml($xml);

        // Mesma lógica do teste equivalente de ZIP-com-PDF: teto deliberadamente ENTRE o tamanho do
        // pacote (comprimido) e o tamanho do `word/document.xml` descomprimido dentro dele.
        $teto = (int) floor((strlen($docx) + strlen($xml)) / 2);
        $this->assertGreaterThanOrEqual(strlen($docx), $teto, 'pré-condição do teste');
        $this->assertLessThan(strlen($xml), $teto, 'pré-condição do teste');

        config(['services.clicksign.max_leitura_bytes' => $teto]);

        $resultado = (new ExtratorTextoContratoService())->extrair($docx);

        $this->assertNull($resultado['texto']);
        $this->assertSame('o contrato dentro do pacote é grande demais para ler', $resultado['motivo']);
    }

    #[Test]
    public function docx_sem_word_document_xml_legivel_devolve_motivo_sem_excecao(): void
    {
        // [Content_Types].xml presente, mas word/document.xml está corrompido/vazio — não pode
        // travar quem chamou.
        $docx = $this->docxComDocumentXml('');

        $resultado = (new ExtratorTextoContratoService())->extrair($docx);

        $this->assertSame('docx', $resultado['formato']);
        $this->assertNull($resultado['texto']);
        $this->assertNotNull($resultado['motivo']);
    }
}
