<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O conjunto de parâmetros de preço da EMPRESA — comissão de cada tipo,
 * imposto, MC, LL e acréscimo —, em ponto percentual. Uma linha por empresa,
 * criada no primeiro "salvar"; sem ela, valem os {@see self::PADROES}.
 */
class EstruturaPrecificacaoParametros extends Model
{
    protected $table = 'estrutura_precificacao_parametros';

    /**
     * Os padrões da Calculadora de Custo (`Calculadora.jsx`: TIERS,
     * IMPOSTO_PADRAO, ACRESCIMO_PADRAO), medidos em `MlbImplementacao` e no
     * portal de Polos. São também os defaults das colunas na migration.
     */
    public const PADROES = [
        'comissao_classico'   => 11.5,
        'comissao_premium'    => 16.5,
        'imposto'             => 19.0,
        'margem_contribuicao' => 0.0,
        'lucro_liquido'       => 0.0,
        'acrescimo'           => 20.0,
    ];

    protected $fillable = ['company_id', 'comissao_classico', 'comissao_premium', 'imposto', 'margem_contribuicao', 'lucro_liquido', 'acrescimo'];

    protected $casts = [
        'comissao_classico'   => 'float',
        'comissao_premium'    => 'float',
        'imposto'             => 'float',
        'margem_contribuicao' => 'float',
        'lucro_liquido'       => 'float',
        'acrescimo'           => 'float',
    ];

    /** @return array<string, float> os da empresa, ou os padrões */
    public static function daEmpresa(int $companyId): array
    {
        $linha = self::where('company_id', $companyId)->first();

        return $linha
            ? array_map('floatval', $linha->only(array_keys(self::PADROES)))
            : self::PADROES;
    }
}
