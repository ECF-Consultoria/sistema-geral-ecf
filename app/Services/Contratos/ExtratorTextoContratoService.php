<?php

namespace App\Services\Contratos;

use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * ExtratorTextoContratoService — binário de contrato (PDF ou ZIP) → texto (Fase 140 Plano 02).
 *
 * Decisão de implementação (D-02 do CONTEXT, deixada em aberto pela investigação de viabilidade):
 * biblioteca PHP `smalot/pdfparser`, NÃO poppler.
 *
 * - poppler exigiria pacote de sistema instalado na VPS, e nem o executor nem os testes têm acesso
 *   a produção — a leitura só poderia ser exercitada lá, nunca no CI/local (o oposto do que esta
 *   fase precisa).
 * - `smalot/pdfparser` é PHP puro, entra pelo `composer.lock` e viaja com o deploy que já existe
 *   (`composer install --no-dev`) — nada de novo no provisionamento do servidor.
 * - `barryvdh/laravel-dompdf`, já presente no projeto, GERA PDF e não lê; não serve para este caso.
 *
 * D-02 do CONTEXT: 3 dos 14 downloads da amostra vieram como ZIP (header `PK`), não PDF — um deles
 * com 12 MB. Este serviço detecta o formato pelos primeiros bytes do binário, nunca pela extensão
 * do nome do arquivo (que nem sempre está disponível na origem, e pode mentir).
 *
 * ═══ CORREÇÃO PÓS-RODADA REAL #3 (140-02, 2026-09-08) — pacote `.docx` dentro do ZIP ═══
 *
 * A varredura completa em produção (85 contratos) achou 19 falhas com o MESMO motivo — "o pacote
 * não tem nenhum contrato em PDF" — e as 19 são justamente os contratos de 2026, os mais recentes.
 * Causa: esses envelopes foram gerados a partir do MODELO da Clicksign, e o arquivo `original` que
 * sobe é o `.docx` que a Clicksign usou para montar o PDF depois — não um PDF em si. `.docx` É um
 * ZIP (mesmo cabeçalho `PK`), mas com estrutura OOXML: `[Content_Types].xml` na raiz e o texto do
 * contrato em `word/document.xml`, dentro dos elementos `<w:t>`.
 *
 * ⚠️ Detecção por CONTEÚDO, nunca por extensão nem só pelo cabeçalho `PK` (que `.docx` e `.zip`
 * comum compartilham): a presença de `[Content_Types].xml` E `word/document.xml` no ÍNDICE do ZIP
 * (via `ZipArchive::locateName()`, sem ler conteúdo) é o que distingue um `.docx` de um `.zip`
 * comum com PDF dentro — checado ANTES da busca por `.pdf`, porque um pacote pode ter os dois tipos
 * de entrada e o `.docx` tem prioridade (é o container real do contrato nesses casos).
 *
 * Contrato de retorno: `extrair()` NUNCA lança exceção. Uma rodada de leitura de ~123 contratos não
 * pode morrer porque o arquivo 7 está corrompido — todo `\Throwable` interno vira `motivo` em
 * português, sem jargão.
 */
class ExtratorTextoContratoService
{
    /**
     * @return array{texto: ?string, motivo: ?string, formato: string} formato ∈ 'pdf'|'zip'|'docx'|'desconhecido'
     */
    public function extrair(string $binario): array
    {
        // Guarda de tamanho ANTES de qualquer processamento (T-140-06 do threat_model) — o maior
        // arquivo medido tem 12 MB; o teto existe para o caso não medido, sem tentar abrir nada.
        $tamanhoMaximo = (int) config('services.clicksign.max_leitura_bytes', 25 * 1024 * 1024);

        if (strlen($binario) > $tamanhoMaximo) {
            return $this->resultado(null, 'o arquivo deste contrato é grande demais para ler', 'desconhecido');
        }

        if (str_starts_with($binario, '%PDF')) {
            return $this->extrairDePdf($binario, 'pdf');
        }

        if (str_starts_with($binario, 'PK')) {
            return $this->extrairDeZip($binario, $tamanhoMaximo);
        }

        return $this->resultado(null, 'este arquivo não é um contrato em PDF nem um pacote reconhecido', 'desconhecido');
    }

    /**
     * Lê um binário de PDF já identificado pelo cabeçalho `%PDF`. Qualquer falha do parser
     * (arquivo corrompido, PDF protegido, estrutura inesperada) vira motivo, nunca exceção
     * (T-140-07 do threat_model).
     *
     * @return array{texto: ?string, motivo: ?string, formato: string}
     */
    private function extrairDePdf(string $binario, string $formato): array
    {
        try {
            $documento = (new PdfParser())->parseContent($binario);
            $texto     = trim($documento->getText());

            if ($texto === '') {
                return $this->resultado(null, 'não deu para ler o arquivo deste contrato (PDF sem texto extraível)', $formato);
            }

            return $this->resultado($texto, null, $formato);
        } catch (Throwable $e) {
            return $this->resultado(null, 'não deu para ler o arquivo deste contrato', $formato);
        }
    }

    /**
     * Abre um ZIP e lê a PRIMEIRA entrada cujo nome termina em `.pdf`, em memória, com
     * `getFromName()`. ⚠️ Proibido usar o método de extração-para-disco do `ZipArchive` nas
     * entradas: extrair entrada de ZIP de terceiro para disco é caminho de zip-slip (entrada com
     * `../` sobrescreve arquivo do servidor) — T-140-05 do threat_model. O binário do ZIP em si
     * precisa de um caminho de arquivo para o `ZipArchive` abrir (limitação da extensão), então
     * grava só o pacote inteiro num temporário e apaga no `finally`; as entradas de DENTRO do
     * pacote nunca tocam o disco.
     *
     * @return array{texto: ?string, motivo: ?string, formato: string}
     */
    private function extrairDeZip(string $binario, int $tamanhoMaximo): array
    {
        $temporario = tempnam(sys_get_temp_dir(), 'clicksign_zip_');

        if ($temporario === false) {
            return $this->resultado(null, 'não deu para abrir o pacote deste contrato', 'zip');
        }

        try {
            file_put_contents($temporario, $binario);

            $zip = new ZipArchive();

            if ($zip->open($temporario) !== true) {
                return $this->resultado(null, 'não deu para abrir o pacote deste contrato', 'zip');
            }

            try {
                // Correção pós-rodada real #3: `.docx` tem prioridade sobre a busca por `.pdf` —
                // detecção por CONTEÚDO (índice do ZIP), não por extensão. Só verifica presença via
                // `locateName()`, que consulta o diretório central do ZIP sem ler nenhum conteúdo.
                if ($zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false) {
                    return $this->extrairDeDocx($zip, $tamanhoMaximo);
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $nome = $zip->getNameIndex($i);

                    if ($nome === false || !str_ends_with(strtolower($nome), '.pdf')) {
                        continue;
                    }

                    // Teto do tamanho descomprimido da entrada (T-140-06) — um ZIP pequeno não pode
                    // estourar a memória ao abrir uma entrada gigante lá dentro.
                    $stat = $zip->statIndex($i);

                    if ($stat !== false && $stat['size'] > $tamanhoMaximo) {
                        return $this->resultado(null, 'o contrato dentro do pacote é grande demais para ler', 'zip');
                    }

                    $conteudo = $zip->getFromName($nome);

                    if ($conteudo === false) {
                        return $this->resultado(null, 'não deu para ler o contrato de dentro do pacote', 'zip');
                    }

                    return $this->extrairDePdf($conteudo, 'zip');
                }

                return $this->resultado(null, 'o pacote não tem nenhum contrato em PDF', 'zip');
            } finally {
                $zip->close();
            }
        } catch (Throwable $e) {
            return $this->resultado(null, 'não deu para ler o pacote deste contrato', 'zip');
        } finally {
            @unlink($temporario);
        }
    }

    /**
     * Lê o texto de um `.docx` (Word, formato OOXML) já identificado dentro do ZIP pela presença de
     * `[Content_Types].xml` e `word/document.xml`. Lê só a ENTRADA que interessa
     * (`word/document.xml`, tipicamente 194 KB–251 KB nos contratos medidos), nunca o pacote de
     * mídia/imagens embutido — é o que mantém o consumo de memória baixo mesmo no pacote de 12 MB.
     *
     * @return array{texto: ?string, motivo: ?string, formato: string}
     */
    private function extrairDeDocx(ZipArchive $zip, int $tamanhoMaximo): array
    {
        $indice = $zip->locateName('word/document.xml');

        if ($indice === false) {
            return $this->resultado(null, 'não deu para ler o contrato de dentro do pacote', 'docx');
        }

        // Mesmo teto do tamanho descomprimido da entrada usado para PDF dentro de ZIP (T-140-06).
        $stat = $zip->statIndex($indice);

        if ($stat !== false && $stat['size'] > $tamanhoMaximo) {
            return $this->resultado(null, 'o contrato dentro do pacote é grande demais para ler', 'docx');
        }

        $xml = $zip->getFromName('word/document.xml');

        if ($xml === false) {
            return $this->resultado(null, 'não deu para ler o contrato de dentro do pacote', 'docx');
        }

        try {
            $texto = trim($this->textoDeDocumentXml($xml));
        } catch (Throwable $e) {
            return $this->resultado(null, 'não deu para ler o arquivo deste contrato', 'docx');
        }

        if ($texto === '') {
            return $this->resultado(null, 'não deu para ler o arquivo deste contrato (docx sem texto extraível)', 'docx');
        }

        return $this->resultado($texto, null, 'docx');
    }

    /**
     * Concatena o texto corrido de `word/document.xml` (OOXML): o texto visível de um `.docx` vive
     * dentro dos elementos `<w:t>` (runs de texto), espalhados por parágrafos (`<w:p>`) e, quando o
     * contrato tem tabela, por linhas de tabela (`<w:tr>`) e células (`<w:tc>`). O parser de tabela
     * progressiva (`TabelaProgressivaContratoParser`) lê linha a linha, e cada CÉLULA de uma tabela
     * do Word é o seu PRÓPRIO parágrafo — se `</w:p>` sempre virasse quebra de linha, uma linha de
     * tabela como "Até R$500.000,00 | R$ 3.000,00" sairia partida em duas linhas (limiar numa linha,
     * valor na outra), e o reconhecedor de marcos nunca associaria os dois.
     *
     * Por isso o estado "dentro de `<w:tbl>`" é rastreado: dentro de tabela, só `</w:tr>` fecha
     * linha (fim de linha da tabela) e `</w:tc>` vira separador (tab) entre células da MESMA linha;
     * fora de tabela, `</w:p>` fecha linha normalmente (cada parágrafo de qualificação —
     * CONTRATANTE, CONTRATADA — é mesmo uma linha própria).
     *
     * Varre o XML numa única passada, na ORDEM em que os elementos aparecem — concatenar por
     * `preg_match_all()` simples sem essa varredura em ordem perderia as quebras/separadores, porque
     * eles ficam FORA dos grupos capturados pelo `<w:t>`.
     */
    private function textoDeDocumentXml(string $xml): string
    {
        // ⚠️ `\b` depois de cada nome de tag é essencial: `w:t` é PREFIXO de `w:tc`, `w:tr`, `w:tbl`
        // e `w:tab` — sem a fronteira de palavra, `<w:t[^>]*>` casava "<w:tc>", "<w:tr>" etc. por
        // engano (bug encontrado escrevendo o teste desta correção) e a marcação inteira da tabela
        // vazava pro texto em vez de virar quebra/separador de linha.
        $padrao = '/<w:tbl\b[^>]*>|<\/w:tbl>|<w:t\b[^>]*\/>|<w:t\b[^>]*>(.*?)<\/w:t>|<w:tab\b[^>]*\/>|<w:br\b[^>]*\/>|<\/w:tc>|<\/w:p>|<\/w:tr>/su';

        if (!preg_match_all($padrao, $xml, $matches, PREG_SET_ORDER)) {
            return '';
        }

        $texto          = '';
        $dentroDeTabela = 0;

        foreach ($matches as $match) {
            $bruto = $match[0];

            if (str_starts_with($bruto, '<w:tbl')) {
                $dentroDeTabela++;

                continue;
            }

            if ($bruto === '</w:tbl>') {
                $dentroDeTabela = max(0, $dentroDeTabela - 1);

                continue;
            }

            if (str_starts_with($bruto, '<w:t')) {
                $texto .= html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');

                continue;
            }

            if (str_starts_with($bruto, '<w:tab')) {
                $texto .= "\t";

                continue;
            }

            if ($bruto === '</w:tc>') {
                // Fim de CÉLULA — separa da próxima célula na MESMA linha; quem fecha a linha de
                // verdade é `</w:tr>`, não o parágrafo interno da célula.
                $texto .= "\t";

                continue;
            }

            if ($bruto === '</w:tr>') {
                $texto .= "\n";

                continue;
            }

            // `</w:p>` dentro de tabela é o parágrafo interno de uma célula — não fecha linha aqui
            // (o `</w:tc>` já separou); fora de tabela, todo `</w:p>`/`<w:br/>` fecha linha.
            if ($bruto === '</w:p>' && $dentroDeTabela > 0) {
                continue;
            }

            $texto .= "\n";
        }

        return $texto;
    }

    /**
     * @return array{texto: ?string, motivo: ?string, formato: string}
     */
    private function resultado(?string $texto, ?string $motivo, string $formato): array
    {
        return [
            'texto'   => $texto,
            'motivo'  => $motivo,
            'formato' => $formato,
        ];
    }
}
