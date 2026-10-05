<?php

namespace App\Mcp\Tools;

use App\Mcp\Telas\CatalogoDeTelas;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `listar_telas` — o índice de tudo que `ler_tela` consegue abrir para este
 * usuário (ver {@see CatalogoDeTelas}).
 */
#[Name('listar_telas')]
#[Title('Listar telas do ECF Admin')]
#[Description(<<<'TXT'
Lista as telas do ECF Admin que você pode abrir com ler_tela: o nome da tela (use em ler_tela), o endereço, o módulo e os parâmetros obrigatórios (ex.: "company" = id da empresa). Use quando nenhuma ferramenta específica (listar_empresas, sugadores, demandas_dev, onboarding_polos, ppa, alertas_estrategicos, painel_executivo) responder a pergunta.
Filtre por `busca` (parte do nome ou do endereço, ex.: "nps", "contrato", "polos") ou por `modulo` (primeiro trecho do endereço, ex.: "mlb", "nps", "administrativo").
TXT)]
class ListarTelasTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'busca' => $schema->string()
                ->description('Parte do nome da tela ou do endereço, ex.: "nps", "contratos", "polos-painel".'),
            'modulo' => $schema->string()
                ->description('Primeiro trecho do endereço, ex.: "mlb", "nps", "administrativo", "desempenho".'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $busca  = $this->texto($request, 'busca');
        $modulo = $this->texto($request, 'modulo');

        $telas = app(CatalogoDeTelas::class)->paraUsuario($usuario)
            ->when($modulo, fn ($c) => $c->filter(fn ($t) => Str::lower($t['modulo']) === Str::lower($modulo)))
            ->when($busca, fn ($c) => $c->filter(fn ($t) => Str::contains(Str::lower($t['tela'].' '.$t['endereco']), Str::lower($busca))))
            ->map(fn ($t) => [
                'tela'       => $t['tela'],
                'endereco'   => $t['endereco'],
                'modulo'     => $t['modulo'],
                'parametros' => $t['parametros'],
            ]);

        return [
            ...$this->paginar($telas, $request),
            'modulos' => app(CatalogoDeTelas::class)->paraUsuario($usuario)->pluck('modulo')->unique()->sort()->values()->all(),
            'como_usar' => 'Abra com ler_tela {"tela": "<nome>", "parametros": {...}}. Sem "campo", vem o resumo da tela; depois peça o campo que interessa.',
        ];
    }
}
