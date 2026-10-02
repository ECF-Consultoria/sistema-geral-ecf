<?php

namespace App\Services\Creative;

use App\Models\MlAnuncioCriativo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ReferenciaEfemeraService — staging da foto de referência do produto em
 * disco privado (D-02, FOTO-02/03).
 *
 * Cada criativo tem DIRETÓRIO PRÓPRIO (`creative-referencias/{token}/`) —
 * nenhum caminho é compartilhado entre dois registros. Isto é a defesa
 * direta contra o incidente já vivido neste projeto (ECF Drive, 2026-09-14):
 * lá a causa foi (a) todas as versões gravando no MESMO `local_path`
 * compartilhado e (b) a exclusão decidindo por idade do ARQUIVO enquanto o
 * consumidor decidia pelo BANCO. Aqui a exclusão (`apagar()`, chamada pela
 * aprovação em 160-03 e pela varredura diária em 160-04) e o consumo
 * (`bytesDe()`) leem a MESMA fonte — a linha da tabela — então nunca existe
 * o descompasso que apagou o arquivo vivo lá.
 *
 * `guardar()` devolve metadados (path/mime/bytes/nome/hash) — NUNCA os bytes
 * nem base64, que não podem entrar no banco nem em log (FOTO-01/GEN-05).
 */
class ReferenciaEfemeraService
{
    /** Teto de referências por criativo — mesmo limite do DTO de geração do spike (CTX-03). */
    public const MAX_REFERENCIAS = 14;

    /**
     * Grava cada arquivo enviado em disco privado sob
     * `creative-referencias/{token}/{indice}.{ext}` e devolve os metadados.
     *
     * @param  UploadedFile[]  $arquivos
     * @return array<int, array{indice:int, path:string, mime:string, bytes:int, nome:string, hash:string}>
     */
    public function guardar(MlAnuncioCriativo $criativo, array $arquivos): array
    {
        $disco       = Storage::disk('local');
        $referencias = [];

        foreach (array_values($arquivos) as $indice => $arquivo) {
            // Extensão derivada da extensão real do arquivo validado (a regra
            // `image` do controller já conferiu o conteúdo) — o NOME original do
            // cliente nunca entra no caminho (T-160-03), só no metadado `nome`.
            $extensao = $arquivo->getClientOriginalExtension() ?: $arquivo->extension() ?: 'jpg';
            $caminho  = "creative-referencias/{$criativo->token}/{$indice}.{$extensao}";

            $disco->put($caminho, file_get_contents($arquivo->getRealPath()));

            $referencias[] = [
                'indice' => $indice,
                'path'   => $caminho,
                'mime'   => $arquivo->getMimeType() ?? $arquivo->getClientMimeType(),
                'bytes'  => $arquivo->getSize() ?? $disco->size($caminho),
                'nome'   => $arquivo->getClientOriginalName(),
                'hash'   => sha1_file($arquivo->getRealPath()) ?: '',
            ];
        }

        return $referencias;
    }

    /**
     * Bytes crus de cada referência viva, lidos do disco — é o que CTX-03
     * consome na Fase 160-02 (o DTO de geração exige bytes, não path).
     * Teto de MAX_REFERENCIAS itens (limite do DTO do spike).
     *
     * @return array<int, array{mime:string, bytes:string}>
     */
    public function bytesDe(MlAnuncioCriativo $criativo): array
    {
        $disco = Storage::disk('local');

        return collect($criativo->referenciasVivas())
            ->take(self::MAX_REFERENCIAS)
            ->filter(fn ($ref) => $disco->exists($ref['path'] ?? ''))
            ->map(fn ($ref) => [
                'mime'  => $ref['mime'] ?? 'image/jpeg',
                'bytes' => $disco->get($ref['path']),
            ])
            ->values()
            ->all();
    }

    /**
     * Remove o DIRETÓRIO do criativo (nunca um arquivo compartilhado — ver
     * docblock da classe) e marca `referencias_apagadas_em`. Idempotente:
     * diretório já ausente conta como apagado, não lança.
     */
    public function apagar(MlAnuncioCriativo $criativo): int
    {
        $disco     = Storage::disk('local');
        $diretorio = "creative-referencias/{$criativo->token}";

        $removidos = 0;
        if ($disco->exists($diretorio)) {
            $removidos = count($disco->files($diretorio));
            $disco->deleteDirectory($diretorio);
        }

        if ($criativo->referencias_apagadas_em === null) {
            $criativo->update(['referencias_apagadas_em' => now()]);
        }

        return $removidos;
    }
}
