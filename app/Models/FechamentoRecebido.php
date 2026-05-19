<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FechamentoRecebido extends Model
{
    public $timestamps = false;

    protected $fillable = ['company_id', 'mes', 'recebido_em'];

    protected $casts = ['recebido_em' => 'datetime'];
}
