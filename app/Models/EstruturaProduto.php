<?php

namespace App\Models;

use App\Support\TituloDoProduto;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto do cliente no Mapeamento Estrutural: uma linha da aba "Produtos" da
 * planilha 3Planejamento (ADR PORTAL-01, Fase 167). Produto -> variações -> volumes.
 * Nome distinto de `pub_produtos` (D-07): aquele é do Publicador.
 *
 * A autoria das mudanças é do `RegistroEstrutura`, como no resto do `estrutura_*`.
 */
class EstruturaProduto extends Model
{
    public const CATEGORIA_CONFIRMADA   = 'confirmada';
    public const CATEGORIA_NAO_VALIDADA = 'nao_validada';
    public const CATEGORIA_A_CONFIRMAR  = 'a_confirmar';
    public const CATEGORIA_VAZIA        = 'vazia';

    protected $table = 'estrutura_produtos';

    protected $fillable = [
        'company_id', 'codigo', 'nome', 'titulo', 'familia_id',
        'categoria_ml_id', 'categoria_ml_nome', 'categoria_ml_caminho', 'descricao',
    ];

    /**
     * Título de venda (19/10/2026): separado do nome, montado pelo cliente com os termos da
     * categoria. Grava-se sempre com a 1ª letra maiúscula (`TituloDoProduto`); vazio é nulo.
     */
    protected function titulo(): Attribute
    {
        return Attribute::make(set: fn (?string $valor) => trim((string) $valor) === '' ? null : TituloDoProduto::capitalizar(trim((string) $valor)));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function familia(): BelongsTo
    {
        return $this->belongsTo(EstruturaFamilia::class, 'familia_id');
    }

    public function ambientes(): BelongsToMany
    {
        return $this->belongsToMany(EstruturaAmbiente::class, 'estrutura_produto_ambiente', 'produto_id', 'ambiente_id');
    }

    /** Ficha técnica: os campos da categoria que o cliente preencheu. */
    public function atributos(): HasMany
    {
        return $this->hasMany(EstruturaProdutoAtributo::class, 'produto_id')->orderBy('id');
    }

    public function variacoes(): HasMany
    {
        return $this->hasMany(EstruturaProdutoVariacao::class, 'produto_id')->orderBy('ordem')->orderBy('id');
    }

    /**
     * Estado da categoria do ML (D-06): id + nome = confirmada; id sem nome = não
     * validada (o ML não respondeu ao validar); sem id com nome = a confirmar (texto
     * colado/importado, nunca vira id sozinho); nada = vazia.
     */
    public function estadoCategoria(): string
    {
        $temId   = trim((string) $this->categoria_ml_id) !== '';
        $temNome = trim((string) $this->categoria_ml_nome) !== '';

        return match (true) {
            $temId && $temNome => self::CATEGORIA_CONFIRMADA,
            $temId             => self::CATEGORIA_NAO_VALIDADA,
            $temNome           => self::CATEGORIA_A_CONFIRMAR,
            default            => self::CATEGORIA_VAZIA,
        };
    }
}
