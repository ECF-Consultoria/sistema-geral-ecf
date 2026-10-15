<?php

namespace App\Support\Publicador\Imagem;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O ÚNICO caminho para o arquivo de uma foto definitiva do Publicador (`pub_imagens.caminho`)
 * — v28.0, Fase 196 (ARM-01/ARM-02).
 *
 * - **Grava** sempre no disco `filesystems.imagens` (`IMAGENS_DISK`: `local` por padrão,
 *   `r2` em produção depois da virada).
 * - **Lê, confere e serve** primeiro desse disco e, se o objeto não estiver lá, do `local`:
 *   as fotos guardadas antes da virada continuam no disco da VPS até a migração (Fase 200).
 * - **Apaga** dos dois, para não sobrar cópia no local depois de migrada.
 *
 * O caminho vem sempre do banco, nunca da requisição. Com `IMAGENS_DISK=local` os dois discos
 * são o mesmo e o comportamento é exatamente o de antes.
 *
 * NÃO é para a foto de referência do Gemini (`creative-referencias/`) nem para a geração ainda
 * não aprovada (`creative-geradas/`): essas são transitórias e ficam no `local` (D3/D8).
 */
final class ArquivosDeImagem
{
    private const LEGADO = 'local';

    public static function nomeDoDisco(): string
    {
        return (string) config('filesystems.imagens', self::LEGADO);
    }

    public static function disco(): Filesystem
    {
        return Storage::disk(self::nomeDoDisco());
    }

    /**
     * Grava no disco das imagens. Lança se não gravou — com o R2 (`throw => true`) a falha de
     * rede já vira exceção; com o local, um `false` vira exceção aqui. Quem chama decide o
     * que desfazer (ARM-04).
     */
    public static function gravar(string $caminho, string $conteudo): void
    {
        if (self::disco()->put($caminho, $conteudo) === false) {
            throw new \RuntimeException("Não foi possível gravar a foto [{$caminho}].");
        }
    }

    /** Os bytes do arquivo, ou null se ele não está em nenhum dos discos (ou o disco não respondeu). */
    public static function ler(string $caminho): ?string
    {
        foreach (self::discos() as $nome) {
            try {
                $disco = Storage::disk($nome);
                if ($disco->exists($caminho)) {
                    $conteudo = $disco->get($caminho);
                    if ($conteudo !== null) {
                        return $conteudo;
                    }
                }
            } catch (\Throwable) {
                // Disco fora do ar: tenta o próximo; nenhum respondeu = null.
            }
        }

        return null;
    }

    public static function existe(?string $caminho): bool
    {
        return $caminho !== null && self::discoDe($caminho) !== null;
    }

    /** A resposta HTTP que entrega o arquivo ao navegador, ou null se ele não está em lugar nenhum. */
    public static function resposta(string $caminho, array $cabecalhos = []): ?StreamedResponse
    {
        $nome = self::discoDe($caminho);

        return $nome === null ? null : Storage::disk($nome)->response($caminho, null, $cabecalhos);
    }

    /** Apaga dos dois discos. Lança se o disco das imagens falhar (quem chama já usa `rescue`). */
    public static function apagar(string $caminho): void
    {
        foreach (self::discos() as $nome) {
            Storage::disk($nome)->delete($caminho);
        }
    }

    /** Remove a pasta nos discos onde ela ficou vazia. */
    public static function apagarPastaSeVazia(string $pasta): void
    {
        foreach (self::discos() as $nome) {
            $disco = Storage::disk($nome);
            if ($disco->allFiles($pasta) === [] && $disco->directoryExists($pasta)) {
                $disco->deleteDirectory($pasta);
            }
        }
    }

    /**
     * O disco das imagens primeiro, o legado depois — e o R2 por último quando ele está configurado
     * (bucket no .env), mesmo com `IMAGENS_DISK=local`: é o ROLLBACK da Fase 200. Voltar a chave para
     * `local` muda só onde as fotos NOVAS são gravadas; as já migradas seguem legíveis (e apagáveis).
     *
     * @return list<string>
     */
    private static function discos(): array
    {
        $r2 = filled(config('filesystems.disks.r2.bucket')) ? ['r2'] : [];

        return array_values(array_unique([self::nomeDoDisco(), self::LEGADO, ...$r2]));
    }

    /** Em qual disco o arquivo está agora (o das imagens primeiro), ou null. */
    public static function discoDe(string $caminho): ?string
    {
        foreach (self::discos() as $nome) {
            try {
                if (Storage::disk($nome)->exists($caminho)) {
                    return $nome;
                }
            } catch (\Throwable) {
                // idem `ler()`
            }
        }

        return null;
    }
}
