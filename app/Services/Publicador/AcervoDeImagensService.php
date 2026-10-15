<?php

namespace App\Services\Publicador;

use App\Models\AcervoImagem;
use App\Models\PubProduto;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Database\QueryException;

/**
 * Registra a imagem DEFINITIVA no acervo da conta do Mercado Livre (v28.0, ATV-03).
 *
 * Mesma conta + mesmo sha256 = o mesmo ativo e o mesmo objeto físico: nada é gravado de novo.
 * Um ativo cujo arquivo sumiu (ou que estava saindo do acervo) volta a `disponivel` com o arquivo
 * regravado — quem chamou tem os bytes na mão.
 *
 * Grava o arquivo ANTES da linha (sem linha apontando para objeto inexistente — ARM-04) e apaga o
 * arquivo se a linha não nascer (ARM-03). Falha do storage sobe como exceção para quem chamou.
 */
class AcervoDeImagensService
{
    /**
     * @param  array{mime: string, bytes: int, largura: int, altura: int}  $meta
     * @param  array{origem?: string, modelo_ia?: ?string, criativo_id?: ?int}  $origem
     * @return ?array{ativo: AcervoImagem, novo: bool} null = produto sem conta (fica fora do acervo)
     */
    public function registrar(PubProduto $produto, string $conteudo, string $sha, array $meta, ?string $nomeOriginal, array $origem = []): ?array
    {
        $dono = AcervoImagem::donoDe($produto->mlb_empresa_id, $produto->company_id);
        if ($dono === null) {
            return null;
        }

        $existente = AcervoImagem::query()->where('dono_chave', $dono['chave'])->where('sha256', $sha)->first();
        if ($existente !== null) {
            return ['ativo' => $this->garantirArquivo($existente, $conteudo), 'novo' => false];
        }

        $caminho = AcervoImagem::caminhoPara($dono['chave'], $sha, $meta['mime']);
        ArquivosDeImagem::gravar($caminho, $conteudo);

        try {
            $ativo = AcervoImagem::create([
                'company_id' => $dono['company_id'],
                'mlb_empresa_id' => $dono['mlb_empresa_id'],
                'dono_chave' => $dono['chave'],
                'ml_user_id' => $this->mlUserId($produto),
                'disco' => ArquivosDeImagem::nomeDoDisco(),
                'caminho' => $caminho,
                'sha256' => $sha,
                'mime' => $meta['mime'],
                'bytes' => $meta['bytes'],
                'largura' => $meta['largura'] ?: null,
                'altura' => $meta['altura'] ?: null,
                'origem' => $origem['origem'] ?? AcervoImagem::ORIGEM_UPLOAD,
                'modelo_ia' => $origem['modelo_ia'] ?? null,
                'criativo_id' => $origem['criativo_id'] ?? null,
                'nome_original' => $nomeOriginal !== null ? mb_substr($nomeOriginal, 0, 255) : null,
                'produto_id' => $produto->id,
                'sku' => $produto->sku,
                'produto_nome' => $produto->nome !== null ? mb_substr((string) $produto->nome, 0, 255) : null,
                'status' => AcervoImagem::DISPONIVEL,
            ]);
        } catch (QueryException $e) {
            // Corrida no unique (dono, sha): o outro processo gravou o MESMO conteúdo no MESMO caminho.
            $ganhou = (string) $e->getCode() === '23000'
                ? AcervoImagem::query()->where('dono_chave', $dono['chave'])->where('sha256', $sha)->first()
                : null;
            if ($ganhou === null) {
                rescue(fn () => ArquivosDeImagem::apagar($caminho), report: false);

                throw $e;
            }

            return ['ativo' => $ganhou, 'novo' => false];
        }

        return ['ativo' => $ativo, 'novo' => true];
    }

    /** Desfaz um ativo recém-criado cujo uso não chegou a nascer (ARM-03). */
    public function desfazer(AcervoImagem $ativo): void
    {
        if ($ativo->usos()->exists()) {
            return;
        }
        rescue(fn () => ArquivosDeImagem::apagar($ativo->caminho), report: false);
        $ativo->delete();
    }

    private function garantirArquivo(AcervoImagem $ativo, string $conteudo): AcervoImagem
    {
        if ($ativo->status === AcervoImagem::DISPONIVEL && ArquivosDeImagem::existe($ativo->caminho)) {
            return $ativo;
        }

        ArquivosDeImagem::gravar($ativo->caminho, $conteudo);
        $ativo->update(['status' => AcervoImagem::DISPONIVEL, 'disco' => ArquivosDeImagem::nomeDoDisco(),
            'exclusao_pedida_em' => null, 'exclusao_erro' => null, 'exclusao_tentativas' => 0]);

        return $ativo;
    }

    private function mlUserId(PubProduto $produto): ?string
    {
        $conta = $produto->contaOuNula();
        $id = $conta?->mlToken?->ml_user_id;

        return $id !== null ? (string) $id : null;
    }
}
