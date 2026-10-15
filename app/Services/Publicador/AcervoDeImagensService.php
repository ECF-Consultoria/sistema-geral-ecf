<?php

namespace App\Services\Publicador;

use App\Models\AcervoImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
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

    public const POR_PAGINA = 24;

    /** Lado maior da miniatura da grade, em px (ACV-03). */
    private const LADO_MINIATURA = 360;

    /**
     * A página do acervo da conta do rascunho atual (ACV-01/02/06): só ativos `disponivel`, mais
     * recentes primeiro. Escopo SEMPRE pelo dono do produto autorizado — nunca por id da requisição.
     *
     * @param  array{origem?: ?string, so_este_produto?: bool, busca?: ?string, de?: ?string, ate?: ?string, ordem?: ?string, pagina?: int}  $filtros
     * @return array{itens: list<array>, total: int, pagina: int, paginas: int, por_pagina: int}
     */
    public function listar(PubRascunho $atual, array $filtros): array
    {
        $produto = $atual->produto;
        $dono = AcervoImagem::donoDe($produto->mlb_empresa_id, $produto->company_id);
        if ($dono === null) {
            return ['itens' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => self::POR_PAGINA];
        }

        $query = AcervoImagem::query()->where('dono_chave', $dono['chave'])->where('status', AcervoImagem::DISPONIVEL);

        if (in_array($filtros['origem'] ?? null, [AcervoImagem::ORIGEM_UPLOAD, AcervoImagem::ORIGEM_IA], true)) {
            $query->where('origem', $filtros['origem']);
        }
        if (! empty($filtros['so_este_produto'])) {
            $query->where('produto_id', $produto->id);
        }
        if (($busca = trim((string) ($filtros['busca'] ?? ''))) !== '') {
            $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $busca).'%';
            $query->where(fn ($q) => $q->where('sku', 'like', $termo)->orWhere('produto_nome', 'like', $termo)->orWhere('nome_original', 'like', $termo));
        }
        if (! empty($filtros['de'])) {
            $query->where('created_at', '>=', $filtros['de'].' 00:00:00');
        }
        if (! empty($filtros['ate'])) {
            $query->where('created_at', '<=', $filtros['ate'].' 23:59:59');
        }

        $total = (clone $query)->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, (int) ($filtros['pagina'] ?? 1)), $paginas);

        $ativos = $query
            ->withCount('usos')
            ->withExists(['usos as no_rascunho_atual' => fn ($q) => $q->where('rascunho_id', $atual->id)])
            ->orderBy('created_at', ($filtros['ordem'] ?? null) === 'antigas' ? 'asc' : 'desc')->orderBy('id', 'desc')
            ->forPage($pagina, self::POR_PAGINA)
            ->get();

        return [
            'itens' => $ativos->map(fn (AcervoImagem $a) => $this->paraItem($a, $atual))->values()->all(),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => self::POR_PAGINA,
        ];
    }

    /** O ativo, se ele é da conta do produto autorizado e está disponível; senão null (o controller dá 404). */
    public function ativoDaConta(PubRascunho $atual, int $id): ?AcervoImagem
    {
        $produto = $atual->produto;
        $dono = AcervoImagem::donoDe($produto->mlb_empresa_id, $produto->company_id);

        return $dono === null ? null : AcervoImagem::query()->whereKey($id)
            ->where('dono_chave', $dono['chave'])->where('status', AcervoImagem::DISPONIVEL)->first();
    }

    /**
     * O caminho da miniatura WebP do ativo, gerada na primeira vez que alguém pede e guardada no disco
     * das imagens (ACV-03). Sem GD/WebP, ou se a imagem não decodificar, devolve null e quem chama
     * serve o original.
     */
    public function miniatura(AcervoImagem $ativo): ?string
    {
        if ($ativo->miniatura_caminho !== null && ArquivosDeImagem::existe($ativo->miniatura_caminho)) {
            return $ativo->miniatura_caminho;
        }
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $conteudo = ArquivosDeImagem::ler($ativo->caminho);
        $origem = $conteudo === null ? false : @imagecreatefromstring($conteudo);
        if ($origem === false) {
            return null;
        }

        try {
            [$largura, $altura] = [imagesx($origem), imagesy($origem)];
            $escala = min(1, self::LADO_MINIATURA / max($largura, $altura));
            $destino = imagecreatetruecolor(max(1, (int) round($largura * $escala)), max(1, (int) round($altura * $escala)));
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagecopyresampled($destino, $origem, 0, 0, 0, 0, imagesx($destino), imagesy($destino), $largura, $altura);

            ob_start();
            imagewebp($destino, null, 75);
            $webp = (string) ob_get_clean();
            imagedestroy($destino);
        } finally {
            imagedestroy($origem);
        }

        if ($webp === '') {
            return null;
        }

        $caminho = "miniaturas/{$ativo->dono_chave}/{$ativo->sha256}.webp";
        ArquivosDeImagem::gravar($caminho, $webp);
        $ativo->update(['miniatura_caminho' => $caminho]);

        return $caminho;
    }

    /** @return array<string, mixed> */
    private function paraItem(AcervoImagem $a, PubRascunho $atual): array
    {
        $rota = fn (string $nome) => route("mlb.anuncios.publicador.acervo.ativos.{$nome}", ['produto' => $atual->produto_id, 'ativo' => $a->id]);

        return [
            'id' => $a->id,
            'miniatura_url' => $rota('miniatura'),
            'imagem_url' => $rota('original'),
            'origem' => $a->origem,
            'rotulo' => $a->origem === AcervoImagem::ORIGEM_IA ? 'Gerada por IA' : 'Enviada',
            'criado_em' => $a->created_at?->format('d/m/Y'),
            'produto_nome' => $a->produto_nome,
            'sku' => $a->sku,
            'do_produto_atual' => $a->produto_id !== null && (int) $a->produto_id === (int) $atual->produto_id,
            'em_uso' => (int) ($a->usos_count ?? 0),
            'no_rascunho_atual' => (bool) ($a->no_rascunho_atual ?? false),
            'largura' => $a->largura,
            'altura' => $a->altura,
        ];
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
