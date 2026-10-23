<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuração e controle do Agente ECF Publicador de UMA conta do Mercado Livre (AG-01, 20/10/2026).
 *
 * Uma por conta canônica (`conta_chave` unique). Nasce DESLIGADA. `versao` sobe a cada gravação — de
 * configuração ou de controle — e cada versão fica em `pub_agente_configuracao_versoes`, para que uma execução
 * antiga explique com que regras foi admitida.
 *
 * A aprovação humana NÃO é configurável aqui: é invariante do portão, não uma opção da loja.
 */
class PubAgenteConfiguracao extends Model
{
    protected $table = 'pub_agente_configuracoes';

    public const ATIVO = 'ativo';
    public const PAUSADO = 'pausado';
    public const SUSPENSO = 'suspenso';

    public const CONTROLES = [self::ATIVO, self::PAUSADO, self::SUSPENSO];

    /** As chaves que explicam uma decisão — o que vai para o snapshot de cada versão. */
    public const CHAVES_DA_VERSAO = [
        'habilitado', 'controle', 'modalidade', 'estoque_minimo', 'limite_diario', 'teto_fila',
        // ⚠️ `dias_semana` entrou em 23/10/2026 (E1) e **tem de estar aqui**: sem ele, o snapshot de
        // uma versão não explicaria por que uma execução foi recusada por `AG-01-JANELA` num domingo.
        'janela_inicio', 'janela_fim', 'dias_semana', 'escopo',
    ];

    /** Os dias da semana em **ISO-8601**: 1 = segunda … 7 = domingo. */
    public const DIAS_ISO = [1, 2, 3, 4, 5, 6, 7];

    /**
     * ⚠️ Nome curto de cada dia, em pt-BR, para a TELA.
     *
     * ⚠️ **ISO, nunca o `dayOfWeek` do Carbon (0 = domingo).** O 0-based é a armadilha de um-a-menos
     * aqui: `0` é *falsy* em PHP e em JS, então domingo marcado desapareceria em qualquer
     * `array_filter`, `if ($dia)` ou `.filter(Boolean)`. Com ISO não existe dia `0`.
     */
    public const ROTULO_DO_DIA = [
        1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'habilitado' => 'boolean',
        'versao' => 'integer',
        'estoque_minimo' => 'integer',
        'limite_diario' => 'integer',
        'teto_fila' => 'integer',
        'escopo' => 'array',
        'dias_semana' => 'array',
    ];

    public function versoes(): HasMany
    {
        return $this->hasMany(PubAgenteConfiguracaoVersao::class, 'configuracao_id')->orderBy('versao');
    }

    public function execucoes(): HasMany
    {
        return $this->hasMany(PubAgenteExecucao::class, 'configuracao_id');
    }

    /** O que vale nesta versão, como vai para o snapshot. */
    public function dadosDaVersao(): array
    {
        $dados = [];
        foreach (self::CHAVES_DA_VERSAO as $chave) {
            $dados[$chave] = $this->{$chave};
        }

        return $dados;
    }
}
