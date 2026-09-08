<?php

namespace Tests\Feature\Phase140;

use App\Services\Contratos\ExtratorTextoContratoService;
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
}
