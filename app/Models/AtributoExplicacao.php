<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A explicação curta de um atributo de categoria, guardada uma vez por `atributo_id` (global, sem
 * empresa). Quem lê e grava é o `ExplicacaoDeAtributos`; a IA preenche pelo
 * `GerarExplicacoesDeAtributosJob`.
 */
class AtributoExplicacao extends Model
{
    public const ORIGEM_ML = 'ml';
    public const ORIGEM_GLOSSARIO = 'glossario';
    public const ORIGEM_IA = 'ia';

    protected $table = 'atributo_explicacoes';

    protected $fillable = ['atributo_id', 'nome', 'texto', 'origem', 'modelo'];
}
