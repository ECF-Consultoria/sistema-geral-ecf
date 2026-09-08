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
 * Contrato de retorno: `extrair()` NUNCA lança exceção. Uma rodada de leitura de ~123 contratos não
 * pode morrer porque o arquivo 7 está corrompido — todo `\Throwable` interno vira `motivo` em
 * português, sem jargão.
 */
class ExtratorTextoContratoService
{
    /**
     * @return array{texto: ?string, motivo: ?string, formato: string} formato ∈ 'pdf'|'zip'|'desconhecido'
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
