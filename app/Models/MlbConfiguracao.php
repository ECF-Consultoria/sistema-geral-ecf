<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlbConfiguracao extends Model
{
    protected $table = 'mlb_configuracoes';

    protected $fillable = ['link_acesso', 'implementacao_defaults'];

    protected $casts = ['implementacao_defaults' => 'array'];

    public static function get(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    public static function implementacaoPadroes(): array
    {
        $defaults = self::get()->implementacao_defaults ?? [];
        $base = [
            'tutorial_intro' => '',
            'tutoriais' => [
                'acesso_colaborador' => '',
                'app_ecf'            => '',
                'planilha_produtos'  => '',
                'inscricao_estadual' => '',
            ],
            'links_admin_extra' => [
                'programa_decola' => '',
            ],
        ];
        return array_replace_recursive($base, $defaults);
    }
}
