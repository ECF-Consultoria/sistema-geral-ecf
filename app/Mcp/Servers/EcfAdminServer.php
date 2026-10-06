<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AbrirTicketTool;
use App\Mcp\Tools\AlertasEstrategicosTool;
use App\Mcp\Tools\AtuarNoTicketTool;
use App\Mcp\Tools\DemandasDevTool;
use App\Mcp\Tools\EnviarFormularioTool;
use App\Mcp\Tools\LerTelaTool;
use App\Mcp\Tools\LerTicketTool;
use App\Mcp\Tools\ListarAcoesTool;
use App\Mcp\Tools\ListarEmpresasTool;
use App\Mcp\Tools\ListarTelasTool;
use App\Mcp\Tools\OnboardingPolosTool;
use App\Mcp\Tools\PainelExecutivoTool;
use App\Mcp\Tools\PpaTool;
use App\Mcp\Tools\RegistrarAtualizacaoDemandaTool;
use App\Mcp\Tools\SalvarDemandaTool;
use App\Mcp\Tools\SugadoresTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Servidor MCP do ECF Admin — remoto (`/mcp`).
 *
 * Especificação: "Especificação — MCP do ECF Admin" (Erlon, 05/10/2026).
 * Leitura em duas camadas:
 *  - 7 ferramentas ESPECÍFICAS (filtros próprios, resposta enxuta) para as
 *    consultas mais usadas;
 *  - `listar_telas` + `ler_tela`, que abrem QUALQUER tela de leitura do Admin
 *    como o usuário (pedido de 05/10/2026: "tudo que tiver no sistema tem que
 *    ter no MCP").
 * Gravação (pedido de 06/10/2026: "para quem tiver conectado poder alterar e
 * preencher coisas"), pelo formulário da própria tela, também em duas camadas:
 *  - ticket e demanda dev com ferramentas próprias;
 *  - `listar_acoes` + `enviar_formulario` para qualquer outro formulário.
 *
 * Toda ferramenta estende {@see \App\Mcp\Tools\FerramentaEcf}: perfil, log de
 * acesso e erro legível ficam lá; as que gravam passam por
 * {@see \App\Mcp\Tools\FerramentaDeEscrita}.
 */
#[Name('ECF Admin')]
#[Version('1.1.0')]
#[Instructions(<<<'MD'
Servidor do ECF Admin, o sistema interno da ECF Consultoria (consultoria de e-commerce em Mercado Livre). Cada ferramenta de leitura devolve os mesmos números da tela correspondente do Admin, e cada ferramenta de gravação usa o mesmo formulário da tela — sempre com o recorte e as permissões de quem conectou: o que a pessoa não vê ou não pode fazer no Admin, ela também não vê nem faz aqui.

Ferramentas e telas:
- listar_empresas → /companies (carteira de Performance: Gestão e Mentoria)
- sugadores → /sugadores (ADS gastando sem retorno)
- demandas_dev → /dev/demandas (tarefas do time de desenvolvimento)
- onboarding_polos → /mlb/implementacao (onboarding das empresas dos Polos, checklist de 17 itens)
- ppa → /ppa e /mlb/polos-ppa (planos de ação)
- alertas_estrategicos → /alertas-estrategicos (alertas diários do ECF Drive)
- painel_executivo → /painel-executivo (carteira inteira no ECF Drive, só admin)
- ler_ticket → tickets do time de desenvolvimento: a lista (equipe dev: a caixa da equipe; demais: os que a pessoa abriu) e o detalhe com descrição, mensagens e os prints anexados
- listar_telas + ler_tela → QUALQUER outra tela do Admin (NPS, contratos, onboarding, painel dos Polos, detalhe de empresa, dashboard, desempenho, metas, comercial, MLB, agenda, tickets...). Prefira as ferramentas específicas quando elas respondem a pergunta; para o resto, ache a tela com listar_telas e abra com ler_tela — primeiro sem "campo" (resumo), depois com o "campo" que interessa.

Cada fonte conta empresas de um universo diferente: o Cadastro (/companies) conta só Performance sem Polos; o Painel Executivo conta todos os sellers da carteira no ECF Drive, inclusive Polos. Não compare esses totais como se fossem o mesmo número. Diga ao usuário de qual tela o número veio.

As listas são paginadas: quando `proximo_cursor` vier preenchido, há mais itens. Repita a chamada com o mesmo filtro e esse cursor. Os dados de Adman e ECF Drive mudam uma vez por dia (importações das 11h às 12h45); sugadores são recalculados às 12h.

Gravação (grava direto, em nome de quem conectou):
- abrir_ticket → abre ticket para o time de desenvolvimento (/tickets)
- atuar_no_ticket → responder, mudar status, transferir, resolver, cancelar ou reabrir um ticket
- salvar_demanda → cadastrar ou editar demanda do time dev (/dev/demandas, só admin)
- registrar_atualizacao_demanda → andamento de uma demanda dev (status, feito, prazo, bloqueio)
- listar_acoes + enviar_formulario → QUALQUER outro formulário do Admin (PPA, onboarding, contratos, NPS, Polos...). Veja os campos com listar_acoes {"acao"} antes de enviar.
Ao gravar: use o que a pessoa disse; se faltar algo obrigatório que você não sabe, pergunte em vez de inventar. Exclusão (DELETE) só depois de a pessoa confirmar. Depois de gravar, diga o que foi gravado (código, link).
MD)]
class EcfAdminServer extends Server
{
    /**
     * `tools/list` do pacote pagina de 15 em 15 por padrão. Com mais de 15
     * ferramentas, as últimas iriam para uma 2ª página que nem todo cliente
     * busca — a ferramenta simplesmente sumiria da conversa. Uma página só.
     */
    public int $defaultPaginationLength = 50;

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
        LerTicketTool::class,
        // Gravação
        AbrirTicketTool::class,
        AtuarNoTicketTool::class,
        SalvarDemandaTool::class,
        RegistrarAtualizacaoDemandaTool::class,
        ListarAcoesTool::class,
        EnviarFormularioTool::class,
    ];
}
