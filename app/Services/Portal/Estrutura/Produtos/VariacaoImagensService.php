<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A galeria de imagens de cada variação (cor) do produto: guarda, ordena, remove e
 * entrega os arquivos.
 *
 * ### Disco PRIVADO
 * Os arquivos vão para o disco `local` (`storage/app/private`), NUNCA para o `public`:
 * o navegador só alcança uma imagem pela rota autenticada do portal, que confere a empresa.
 * Caminho: `estrutura/{company_id}/produtos/{produto_id}/variacoes/{variacao_id}/{uuid}.{ext}`.
 * O nome em disco é um UUID gerado aqui (o nome que o cliente deu fica só na coluna
 * `nome_original`, para exibir) — nenhum pedaço do caminho vem do cliente.
 *
 * ### Imagens CRUAS
 * Nada é redimensionado ou recomprimido: o arquivo é guardado como veio. O tratamento e o
 * envio para qualquer destino são de outra etapa.
 *
 * ### Ordem
 * `ordem` 0 é a capa. Ao excluir ou reordenar, a numeração é refeita de 0 a N-1 sem buracos.
 *
 * ### Empresa
 * Quem chama entrega a variação JÁ resolvida dentro da empresa da sessão; toda consulta
 * daqui ainda filtra `company_id`.
 */
class VariacaoImagensService
{
    public const DISCO = 'local';

    /** O que o cliente lê quando o envio estoura o limite do PHP (`post_max_size` / `upload_max_filesize`). */
    public const MENSAGEM_GRANDE_DEMAIS = 'A imagem é grande demais para enviar; tente uma menor.';

    /** MIME detectado no conteúdo (não o que o navegador disse) => extensão gravada. */
    private const EXTENSAO_POR_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public static function maxPorVariacao(): int
    {
        return (int) config('estrutura_produtos.imagens.max_por_variacao', 12);
    }

    /** A pasta de UMA variação no disco privado (sem barra no fim). */
    public static function pasta(int $empresaId, int $produtoId, int $variacaoId): string
    {
        return "estrutura/{$empresaId}/produtos/{$produtoId}/variacoes/{$variacaoId}";
    }

    /** Remove a pasta inteira da variação (chamado quando a variação é excluída). */
    public static function apagarPasta(int $empresaId, int $produtoId, int $variacaoId): void
    {
        try {
            Storage::disk(self::DISCO)->deleteDirectory(self::pasta($empresaId, $produtoId, $variacaoId));
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Imagens] pasta da variação não pôde ser removida', ['variacao' => $variacaoId, 'erro' => $e->getMessage()]);
        }
    }

    /** A URL que a tela usa no `<img src>`: relativa (vale em qualquer domínio do portal) e autenticada. */
    public static function url(int $variacaoId, int $imagemId): string
    {
        return route('portal.auth.estrutura.produtos.imagens.ver', ['variacao' => $variacaoId, 'imagem' => $imagemId], false);
    }

    // ═══ Leitura ════════════════════════════════════════════════════════════

    /**
     * A galeria de uma variação, na ordem.
     *
     * @return list<array{id: int, url: string, nome_original: string, ordem: int, capa: bool, mime: string, tamanho: int, largura: ?int, altura: ?int}>
     */
    public function listar(Company $empresa, EstruturaProdutoVariacao $variacao): array
    {
        return $this->listarDasVariacoes($empresa, [(int) $variacao->id])[(int) $variacao->id] ?? [];
    }

    /**
     * A galeria de várias variações numa consulta só (a ficha do produto).
     *
     * @param  array<int, int>  $variacaoIds
     * @return array<int, list<array>> variacao_id => lista (variação sem imagem não aparece)
     */
    public function listarDasVariacoes(Company $empresa, array $variacaoIds): array
    {
        if ($variacaoIds === []) {
            return [];
        }

        $porVariacao = [];
        EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->whereIn('variacao_id', $variacaoIds)
            ->orderBy('variacao_id')->orderBy('ordem')->orderBy('id')
            ->get()
            ->each(function (EstruturaProdutoVariacaoImagem $i) use (&$porVariacao) {
                $porVariacao[(int) $i->variacao_id][] = [
                    'id'            => (int) $i->id,
                    'url'           => self::url((int) $i->variacao_id, (int) $i->id),
                    'nome_original' => $i->nome_original,
                    'ordem'         => (int) $i->ordem,
                    'capa'          => (int) $i->ordem === 0,
                    'mime'          => $i->mime,
                    'tamanho'       => (int) $i->tamanho,
                    'largura'       => $i->largura,
                    'altura'        => $i->altura,
                ];
            });

        return $porVariacao;
    }

    /** O arquivo existe no disco? (linha sem arquivo vira 404, nunca 500). */
    public function existe(EstruturaProdutoVariacaoImagem $imagem): bool
    {
        return Storage::disk(self::DISCO)->exists($imagem->caminho);
    }

    // ═══ Escrita ════════════════════════════════════════════════════════════

    /**
     * Guarda uma ou mais imagens no fim da galeria. Tudo ou nada: se não couberem todas
     * (limite por variação), NENHUMA é guardada e a mensagem diz quantas ainda cabem.
     *
     * @param  array<int, UploadedFile>  $arquivos  já validados (formato e tamanho) por quem chama
     * @return list<array>  a galeria atualizada da variação
     *
     * @throws ValidationException
     */
    public function enviar(Company $empresa, EstruturaProdutoVariacao $variacao, array $arquivos, AtorDoPortal $ator): array
    {
        $guardados = [];

        try {
            DB::transaction(function () use ($empresa, $variacao, $arquivos, &$guardados) {
                // Trava a variação: dois envios ao mesmo tempo não furam o teto nem repetem a ordem.
                EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->whereKey($variacao->id)->lockForUpdate()->first();

                $existentes = EstruturaProdutoVariacaoImagem::query()
                    ->where('company_id', $empresa->id)->where('variacao_id', $variacao->id);
                $quantas = (clone $existentes)->count();
                $proxima = $quantas === 0 ? 0 : ((int) (clone $existentes)->max('ordem')) + 1;

                $max = self::maxPorVariacao();
                if ($quantas + count($arquivos) > $max) {
                    $cabem = max(0, $max - $quantas);
                    throw ValidationException::withMessages(['imagens' => $cabem === 0
                        ? "Esta variação já tem as {$max} imagens permitidas. Exclua uma para enviar outra."
                        : "Cada variação aceita até {$max} imagens. Esta já tem {$quantas}; envie no máximo {$cabem}."]);
                }

                $pasta = self::pasta((int) $empresa->id, (int) $variacao->produto_id, (int) $variacao->id);

                foreach ($arquivos as $arquivo) {
                    $mime = (string) $arquivo->getMimeType();
                    $extensao = self::EXTENSAO_POR_MIME[$mime] ?? null;
                    if ($extensao === null) {
                        throw ValidationException::withMessages(['imagens' => 'Formato de imagem não aceito. Envie JPG, PNG ou WebP.']);
                    }

                    [$largura, $altura] = self::dimensoes($arquivo);
                    $nome = Str::uuid().'.'.$extensao;

                    $caminho = Storage::disk(self::DISCO)->putFileAs($pasta, $arquivo, $nome);
                    if ($caminho === false) {
                        throw new \RuntimeException('Não foi possível gravar a imagem no disco.');
                    }
                    $guardados[] = $caminho;

                    EstruturaProdutoVariacaoImagem::create([
                        'company_id'    => $empresa->id,
                        'produto_id'    => $variacao->produto_id,
                        'variacao_id'   => $variacao->id,
                        'caminho'       => $caminho,
                        'nome_original' => self::nomeLimpo($arquivo),
                        'mime'          => $mime,
                        'tamanho'       => (int) $arquivo->getSize(),
                        'largura'       => $largura,
                        'altura'        => $altura,
                        'ordem'         => $proxima++,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Arquivo gravado cuja linha não ficou: sai do disco (nada órfão).
            foreach ($guardados as $caminho) {
                Storage::disk(self::DISCO)->delete($caminho);
            }

            throw $e;
        }

        RegistroEstrutura::registrar($ator, $empresa, null, 'imagens_variacao_enviadas',
            "Imagens da variação {$variacao->codigo} enviadas", ['variacao_id' => (int) $variacao->id, 'produto_id' => (int) $variacao->produto_id, 'quantidade' => count($arquivos)]);

        return $this->listar($empresa, $variacao);
    }

    /**
     * Remove a linha e o arquivo; as que sobram são renumeradas (a próxima vira capa se a capa saiu).
     *
     * @return list<array>  a galeria atualizada
     */
    public function excluir(Company $empresa, EstruturaProdutoVariacao $variacao, EstruturaProdutoVariacaoImagem $imagem, AtorDoPortal $ator): array
    {
        $caminho = $imagem->caminho;

        DB::transaction(function () use ($empresa, $variacao, $imagem) {
            $imagem->delete();
            $this->renumerar($empresa, $variacao, []);
        });

        // Depois do commit: se o banco desfizer, o arquivo continua lá para a linha que sobrou.
        try {
            Storage::disk(self::DISCO)->delete($caminho);
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Imagens] arquivo não pôde ser removido do disco', ['caminho' => $caminho, 'erro' => $e->getMessage()]);
        }

        RegistroEstrutura::registrar($ator, $empresa, null, 'imagem_variacao_excluida',
            "Imagem da variação {$variacao->codigo} excluída", ['variacao_id' => (int) $variacao->id, 'produto_id' => (int) $variacao->produto_id]);

        return $this->listar($empresa, $variacao);
    }

    /**
     * Regrava a ordem. `$ids` na ordem desejada: só valem os que são da variação (os de fora
     * são ignorados, sem erro e sem efeito) e repetidos contam uma vez. As imagens que a lista
     * não citou ficam depois, na ordem em que já estavam.
     *
     * @param  array<int, mixed>  $ids
     * @return list<array>  a galeria atualizada
     */
    public function reordenar(Company $empresa, EstruturaProdutoVariacao $variacao, array $ids, AtorDoPortal $ator): array
    {
        DB::transaction(fn () => $this->renumerar($empresa, $variacao, $ids));

        RegistroEstrutura::registrar($ator, $empresa, null, 'imagens_variacao_reordenadas',
            "Imagens da variação {$variacao->codigo} reordenadas", ['variacao_id' => (int) $variacao->id, 'produto_id' => (int) $variacao->produto_id]);

        return $this->listar($empresa, $variacao);
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** Refaz `ordem` 0..N-1: primeiro os ids pedidos (se forem da variação), depois o resto como estava. */
    private function renumerar(Company $empresa, EstruturaProdutoVariacao $variacao, array $primeiros): void
    {
        $atuais = EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)->where('variacao_id', $variacao->id)
            ->orderBy('ordem')->orderBy('id')
            ->get(['id', 'ordem'])
            ->keyBy('id');

        $final = [];
        foreach ($primeiros as $id) {
            if (is_numeric($id) && $atuais->has((int) $id)) {
                $final[(int) $id] = true;
            }
        }
        foreach ($atuais->keys() as $id) {
            $final[(int) $id] = true;
        }

        $posicao = 0;
        foreach (array_keys($final) as $id) {
            if ((int) $atuais[$id]->ordem !== $posicao) {
                EstruturaProdutoVariacaoImagem::query()->where('company_id', $empresa->id)->whereKey($id)->update(['ordem' => $posicao]);
            }
            $posicao++;
        }
    }

    /** @return array{0: ?int, 1: ?int} largura e altura, ou nulos se o arquivo não deixar ler */
    private static function dimensoes(UploadedFile $arquivo): array
    {
        $info = @getimagesize((string) $arquivo->getRealPath());

        return is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0
            ? [(int) $info[0], (int) $info[1]]
            : [null, null];
    }

    /** O nome que o cliente deu, só para exibir: sem pasta, sem caractere de controle, até 255. */
    private static function nomeLimpo(UploadedFile $arquivo): string
    {
        $nome = str_replace('\\', '/', (string) $arquivo->getClientOriginalName());
        $nome = basename($nome);
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $nome));

        return mb_substr($nome !== '' ? $nome : 'imagem', 0, 255);
    }
}
