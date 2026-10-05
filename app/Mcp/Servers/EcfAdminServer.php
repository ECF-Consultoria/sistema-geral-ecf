<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AlertasEstrategicosTool;
use App\Mcp\Tools\DemandasDevTool;
use App\Mcp\Tools\LerTelaTool;
use App\Mcp\Tools\ListarEmpresasTool;
use App\Mcp\Tools\ListarTelasTool;
use App\Mcp\Tools\OnboardingPolosTool;
use App\Mcp\Tools\PainelExecutivoTool;
use App\Mcp\Tools\PpaTool;
use App\Mcp\Tools\SugadoresTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Servidor MCP do ECF Admin — remoto (`/mcp`), SÓ LEITURA.
 *
 * Especificação: "Especificação — MCP do ECF Admin" (Erlon, 05/10/2026).
 * Duas camadas:
 *  - 7 ferramentas ESPECÍFICAS (filtros próprios, resposta enxuta) para as
 *    consultas mais usadas;
 *  - `listar_telas` + `ler_tela`, que abrem QUALQUER tela de leitura do Admin
 *    como o usuário (pedido de 05/10/2026: "tudo que tiver no sistema tem que
 *    ter no MCP").
 *
 * Toda ferramenta estende {@see \App\Mcp\Tools\FerramentaEcf}: perfil, log de
 * acesso e erro legível ficam lá.
 */
#[Name('ECF Admin')]
#[Version('1.0.0')]
#[Instructions(<<<'MD'
Servidor só de leitura do ECF Admin, o sistema interno da ECF Consultoria (consultoria de e-commerce em Mercado Livre). Cada ferramenta devolve os mesmos números da tela correspondente do Admin, já com o recorte do perfil de quem conectou: o que a pessoa não vê no Admin, ela também não vê aqui.

Ferramentas e telas:
- listar_empresas → /companies (carteira de Performance: Gestão e Mentoria)
- sugadores → /sugadores (ADS gastando sem retorno)
- demandas_dev → /dev/demandas (tarefas do time de desenvolvimento)
- onboarding_polos → /mlb/implementacao (onboarding das empresas dos Polos, checklist de 17 itens)
- ppa → /ppa e /mlb/polos-ppa (planos de ação)
- alertas_estrategicos → /alertas-estrategicos (alertas diários do ECF Drive)
- painel_executivo → /painel-executivo (carteira inteira no ECF Drive, só admin)
- listar_telas + ler_tela → QUALQUER outra tela do Admin (NPS, contratos, onboarding, painel dos Polos, detalhe de empresa, dashboard, desempenho, metas, comercial, MLB, agenda, tickets...). Prefira as ferramentas específicas quando elas respondem a pergunta; para o resto, ache a tela com listar_telas e abra com ler_tela — primeiro sem "campo" (resumo), depois com o "campo" que interessa.

Cada fonte conta empresas de um universo diferente: o Cadastro (/companies) conta só Performance sem Polos; o Painel Executivo conta todos os sellers da carteira no ECF Drive, inclusive Polos. Não compare esses totais como se fossem o mesmo número. Diga ao usuário de qual tela o número veio.

As listas são paginadas: quando `proximo_cursor` vier preenchido, há mais itens. Repita a chamada com o mesmo filtro e esse cursor. Os dados de Adman e ECF Drive mudam uma vez por dia (importações das 11h às 12h45); sugadores são recalculados às 12h.

Nenhuma ferramenta altera dados. Para marcar como visto, mudar status ou editar, a pessoa precisa usar a tela do Admin.
MD)]
class EcfAdminServer extends Server
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        ListarEmpresasTool::class,
        SugadoresTool::class,
        DemandasDevTool::class,
        OnboardingPolosTool::class,
        PpaTool::class,
        AlertasEstrategicosTool::class,
        PainelExecutivoTool::class,
        ListarTelasTool::class,
        LerTelaTool::class,
    ];
}
