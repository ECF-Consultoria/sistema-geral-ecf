<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Mcp\Telas\CatalogoDeTelas;
use App\Mcp\Telas\LeitorDeDados;
use App\Mcp\Telas\NavegadorDeTelas;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `ler_tela` — abre QUALQUER tela de leitura do Admin como o usuário e devolve
 * os dados que a tela recebe (decisão de 05/10/2026: "tudo que tiver no
 * sistema tem que ter no MCP").
 *
 * Quem garante o recorte é a própria tela: {@see NavegadorDeTelas} faz uma
 * navegação interna pelo kernel HTTP, com os middlewares e o controller de
 * sempre. Abrir pelo MCP equivale a abrir no navegador — inclusive o
 * aquecimento de cache que algumas telas disparam (Desempenho "calculando…"),
 * aceito pelo usuário na mesma decisão. Nada de negócio é gravado: o catálogo
 * só tem GET, e as rotas GET que gravam estão em
 * {@see CatalogoDeTelas::BLOQUEADAS}.
 */
#[Name('ler_tela')]
#[Title('Ler uma tela do ECF Admin')]
#[Description(<<<'TXT'
Abre qualquer tela do ECF Admin (as mesmas do navegador, com o recorte do seu perfil) e devolve os dados que a tela recebe. Use para tudo que as ferramentas específicas não cobrem: NPS, contratos, onboarding, painel dos Polos, detalhe de uma empresa, dashboard, desempenho/ranking, metas, comercial, MLB/anúncios, agenda, tickets etc. Descubra o nome da tela com listar_telas.
Como usar:
1. Chame sem "campo": vem o resumo da tela — valores pequenos inteiros e, para os grandes, o tipo e o tamanho.
2. Peça o que interessa com "campo" (caminho com pontos, ex.: "companies", "stats.total_revenue", "empresas.0.contratos"). Listas vêm paginadas (limite/cursor).
Telas com lista paginada pelo servidor (campo do tipo "lista paginada pela tela") mudam de página com filtros {"page": N}. Os filtros da URL da tela (ex.: {"mes": "2026-09"}, {"period": "30"}) vão em "filtros".
Os números são os da tela no momento da consulta (cache de 2 minutos por tela).
TXT)]
class LerTelaTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        // A trava é a da própria tela, aplicada ao abri-la.
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tela' => $schema->string()
                ->description('Nome da tela, como listar_telas devolve (ex.: "companies.show", "nps.index", "mlb.polos-painel").')
                ->required(),
            'parametros' => $schema->object()
                ->description('Parâmetros do endereço, quando a tela pede (ex.: {"company": 123}, {"user": 24}).'),
            'filtros' => $schema->object()
                ->description('Filtros da URL da tela (query string), ex.: {"mes": "2026-09"}, {"period": "30"}, {"page": 2}.'),
            'campo' => $schema->string()
                ->description('Caminho do dado dentro da tela, com pontos (ex.: "companies", "stats", "empresas.3.contratos"). Sem ele, vem o resumo.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $nome = $this->texto($request, 'tela')
            ?? throw new ErroDaFerramenta('Informe a tela (use listar_telas para ver os nomes).');

        $entrada = app(CatalogoDeTelas::class)->achar($usuario, $nome)
            ?? throw new ErroDaFerramenta("Tela \"{$nome}\" não existe ou não está no seu perfil. Use listar_telas para ver as disponíveis.");

        $parametros = $this->objeto($request, 'parametros');
        $faltando   = array_values(array_diff($entrada['parametros'], array_keys($parametros)));
        if ($faltando !== []) {
            throw new ErroDaFerramenta('Esta tela precisa de: '.implode(', ', $faltando).'. Ex.: {"parametros": {"'.$faltando[0].'": 123}}.');
        }

        // Só os parâmetros do endereço vão para route(); o resto seria
        // anexado como query e mudaria a tela sem o usuário pedir.
        $caminho = route($nome, array_intersect_key($parametros, array_flip($entrada['parametros'])), false);
        $filtros = $this->objeto($request, 'filtros');

        $navegador = app(NavegadorDeTelas::class);
        $leitor    = app(LeitorDeDados::class);

        $pagina = $navegador->abrir($usuario, $nome, $caminho, $filtros);
        $dados  = is_array($pagina['dados']) ? $pagina['dados'] : ['valor' => $pagina['dados']];
        if ($pagina['tipo'] === 'tela') {
            $dados = $leitor->semCompartilhados($dados);
        }

        $base = array_filter([
            'tela'       => $pagina['tela'],
            'endereco'   => $pagina['endereco'],
            'componente' => $pagina['componente'],
            'filtros'    => $filtros ?: null,
        ], fn ($v) => $v !== null);

        $campo = $this->texto($request, 'campo');
        if ($campo === null) {
            $resumo = $leitor->resumir($leitor->ocultarCredenciais($dados));

            return [
                ...$base,
                'dados'           => $resumo['valores'],
                'campos_grandes'  => $resumo['grandes'] ?: null,
                'como_continuar'  => $resumo['grandes']
                    ? 'Peça um dos campos_grandes com "campo" (ex.: "'.array_key_first($resumo['grandes']).'").'
                    : 'A tela inteira coube nesta resposta.',
            ];
        }

        // Prop opcional/lazy não vem na abertura normal — o navegador da tela
        // o busca com recarregamento parcial. Faz-se o mesmo aqui.
        $topo = Str::before($campo, '.');
        if ($pagina['tipo'] === 'tela' && ! array_key_exists($topo, $dados) && $pagina['componente']) {
            $parcial = $navegador->abrir($usuario, $nome, $caminho, $filtros, $pagina['componente'], $topo);
            // O Inertia devolve `null` para chave pedida que a tela não tem —
            // isso é "não existe", não "existe e está vazio".
            if (is_array($parcial['dados']) && ($parcial['dados'][$topo] ?? null) !== null) {
                $dados[$topo] = $parcial['dados'][$topo];
            }
        }

        $valor = $leitor->ocultarCredenciais($leitor->navegar($dados, $campo));

        if (is_array($valor) && array_is_list($valor)) {
            [$deslocamento, $limite] = $this->janela($request);
            $fatia = $leitor->fatiar($valor, $deslocamento, $limite);

            return [
                ...$base,
                'campo'          => $campo,
                'total'          => $fatia['total'],
                'itens'          => $fatia['itens'],
                'limite_usado'   => $fatia['limite_usado'],
                'proximo_cursor' => $fatia['proximo_deslocamento'] !== null ? $this->cursorPara($fatia['proximo_deslocamento']) : null,
            ];
        }

        if ($leitor->bytes($valor) > LeitorDeDados::BYTES_MAX && is_array($valor)) {
            $resumo = $leitor->resumir($valor);

            return [
                ...$base,
                'campo'          => $campo,
                'dados'          => $resumo['valores'],
                'campos_grandes' => $resumo['grandes'],
                'como_continuar' => 'Este campo é grande: peça um pedaço dele, ex.: "'.$campo.'.'.array_key_first($resumo['grandes']).'".',
            ];
        }

        return [...$base, 'campo' => $campo, 'valor' => $valor];
    }

    /** Resposta só em texto: tela grande não pode ir duas vezes (texto + estruturado). */
    protected function responder(array $dados): Response|ResponseFactory
    {
        return Response::json($dados);
    }

    /** @return array<string, mixed> */
    private function objeto(Request $request, string $chave): array
    {
        $valor = $request->get($chave);
        if ($valor === null || $valor === '') {
            return [];
        }
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }
        if (is_object($valor)) {
            $valor = (array) $valor;
        }
        if (! is_array($valor)) {
            throw new ErroDaFerramenta("\"{$chave}\" precisa ser um objeto, ex.: {\"mes\": \"2026-09\"}.");
        }

        return $valor;
    }
}
