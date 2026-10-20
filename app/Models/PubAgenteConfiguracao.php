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
        'janela_inicio', 'janela_fim', 'escopo',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'habilitado' => 'boolean',
        'versao' => 'integer',
        'estoque_minimo' => 'integer',
        'limite_diario' => 'integer',
        'teto_fila' => 'integer',
        'escopo' => 'array',
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
