<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model de configurações chave/valor genérico para flags e preferências do painel.
 * Permite persistir qualquer valor de configuração sem criar tabelas específicas.
 * Exemplos de uso: destinatários de email, datas de último envio, feature flags.
 */
class Configuracao extends Model
{
    // Evita que o Laravel pluralize para "configuracaos"
    protected $table = 'configuracoes';

    protected $fillable = ['chave', 'valor'];

    /**
     * Retorna o valor de uma configuração pelo nome da chave.
     * Retorna $default caso a chave não exista ou seja null.
     */
    public static function get(string $chave, $default = null): mixed
    {
        return static::where('chave', $chave)->value('valor') ?? $default;
    }

    /**
     * Persiste ou atualiza o valor de uma configuração pela chave.
     */
    public static function set(string $chave, $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }
}
