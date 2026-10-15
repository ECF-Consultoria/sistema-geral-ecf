<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma imagem do acervo da conta do Mercado Livre (v28.0, ATV-01).
 *
 * O registro lógico do arquivo físico: a mesma foto (sha256) na mesma conta é UM ativo e UM objeto,
 * não importa em quantos rascunhos apareça — cada uso é uma linha de `pub_imagens` apontando para cá.
 * Só entra imagem DEFINITIVA: upload manual ou imagem de IA aprovada (D3).
 */
class AcervoImagem extends Model
{
    protected $table = 'acervo_imagens';

    public const ORIGEM_UPLOAD = 'upload';
    public const ORIGEM_IA = 'ia';

    public const DISPONIVEL = 'disponivel';
    public const EXCLUINDO = 'excluindo';
    public const EXCLUIDA = 'excluida';
    public const FALHOU = 'falhou';

    protected $guarded = ['id'];

    protected $casts = [
        'bytes' => 'integer',
        'largura' => 'integer',
        'altura' => 'integer',
        'exclusao_tentativas' => 'integer',
        'exclusao_pedida_em' => 'datetime',
        'migrado_em' => 'datetime',
        'caminhos_legados' => 'array',
    ];

    /**
     * O dono de uma imagem do produto: a conta do Mercado Livre, na ordem de
     * `PubProduto::ancoraComToken()` (MlbEmpresa primeiro). Null = produto sem âncora.
     *
     * @return ?array{chave: string, company_id: ?int, mlb_empresa_id: ?int}
     */
    public static function donoDe(?int $mlbEmpresaId, ?int $companyId): ?array
    {
        return match (true) {
            $mlbEmpresaId !== null => ['chave' => "m{$mlbEmpresaId}", 'company_id' => $companyId, 'mlb_empresa_id' => $mlbEmpresaId],
            $companyId !== null => ['chave' => "c{$companyId}", 'company_id' => $companyId, 'mlb_empresa_id' => null],
            default => null,
        };
    }

    /** A chave do objeto no disco das imagens — por conteúdo, nunca por produto ou SKU (D7). */
    public static function caminhoPara(string $donoChave, string $sha256, string $mime): string
    {
        return "imagens/{$donoChave}/{$sha256}.".($mime === 'image/png' ? 'png' : 'jpg');
    }

    public function usos(): HasMany
    {
        return $this->hasMany(PubImagem::class, 'acervo_imagem_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }
}
