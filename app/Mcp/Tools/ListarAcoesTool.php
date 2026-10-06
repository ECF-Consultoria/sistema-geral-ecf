<?php

namespace App\Mcp\Tools;

use App\Mcp\Acoes\CatalogoDeAcoes;
use App\Mcp\Acoes\RegrasDaAcao;
use App\Mcp\ErroDaFerramenta;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `listar_acoes` — o índice do que `enviar_formulario` consegue acionar para
 * este usuário ({@see CatalogoDeAcoes}) e, para UMA ação, os campos que o
 * formulário aceita ({@see RegrasDaAcao}). Só lê.
 */
#[Name('listar_acoes')]
#[Title('Listar ações de gravação do ECF Admin')]
#[Description(<<<'TXT'
Lista os formulários e botões do ECF Admin que você pode acionar com enviar_formulario (criar, editar, mudar status, excluir...): o nome da ação, o método (POST/PUT/PATCH/DELETE), o endereço e os parâmetros do endereço.
Filtre por "busca" (ex.: "ppa", "contrato", "nps", "onboarding") ou "modulo" (primeiro trecho do endereço, ex.: "mlb", "administrativo", "companies").
Com "acao" (o nome exato), devolve o detalhe: os campos que o formulário aceita, como estão na validação do sistema (obrigatório, tipo, tamanho, valores permitidos). As opções de cada campo (ids, listas) a tela mostra — abra com ler_tela.
Para ticket e demanda dev prefira as ferramentas próprias (abrir_ticket, atuar_no_ticket, salvar_demanda, registrar_atualizacao_demanda).
TXT)]
class ListarAcoesTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        return FerramentaDeEscrita::escritaLigada();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'busca' => $schema->string()->description('Parte do nome da ação ou do endereço, ex.: "ppa", "contratos", "nps".'),
            'modulo' => $schema->string()->description('Primeiro trecho do endereço, ex.: "mlb", "administrativo", "companies", "nps".'),
            'acao' => $schema->string()->description('Nome exato de uma ação: devolve os campos que ela aceita.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $catalogo = app(CatalogoDeAcoes::class);

        if (($nome = $this->texto($request, 'acao')) !== null) {
            $entrada = $catalogo->achar($usuario, $nome)
                ?? throw new ErroDaFerramenta("Ação \"{$nome}\" não existe, está fora do MCP ou não está no seu perfil.");
            $rota   = $catalogo->rota($nome);
            $regras = $rota ? app(RegrasDaAcao::class)->para($rota) : null;

            return [
                'acao'       => $entrada['acao'],
                'metodo'     => $entrada['metodo'],
                'endereco'   => $entrada['endereco'],
                'parametros' => $entrada['parametros'],
                'campos'     => $regras ?? 'O sistema não declara a validação desta ação no controller. Veja na tela (ler_tela) quais campos ela usa.',
                'como_usar'  => 'enviar_formulario {"acao": "'.$entrada['acao'].'"'
                    .($entrada['parametros'] ? ', "parametros": {"'.$entrada['parametros'][0].'": <id>}' : '')
                    .', "dados": {...}}'
                    .($entrada['metodo'] === 'DELETE' ? ' + "confirmo_exclusao": true (EXCLUI — confirme com a pessoa antes).' : '.'),
            ];
        }

        $busca  = $this->texto($request, 'busca');
        $modulo = $this->texto($request, 'modulo');
        $todas  = $catalogo->paraUsuario($usuario);

        $acoes = $todas
            ->when($modulo, fn ($c) => $c->filter(fn ($a) => Str::lower($a['modulo']) === Str::lower($modulo)))
            ->when($busca, fn ($c) => $c->filter(fn ($a) => Str::contains(Str::lower($a['acao'].' '.$a['endereco']), Str::lower($busca))))
            ->map(fn ($a) => [
                'acao'       => $a['acao'],
                'metodo'     => $a['metodo'],
                'endereco'   => $a['endereco'],
                'parametros' => $a['parametros'],
            ]);

        return [
            ...$this->paginar($acoes, $request),
            'modulos'   => $todas->pluck('modulo')->unique()->sort()->values()->all(),
            'como_usar' => 'Veja os campos com listar_acoes {"acao": "<nome>"} e envie com enviar_formulario.',
        ];
    }
}
