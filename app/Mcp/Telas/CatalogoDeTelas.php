<?php

namespace App\Mcp\Telas;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Quais telas do Admin a ferramenta genérica `ler_tela` pode abrir.
 *
 * Entra: rota GET com nome, atrás de login (`auth`). Sai:
 *  - o que não é tela de leitura: arquivo/PDF/planilha/imagem, OAuth e
 *    callbacks, login/senha/verificação, o portal do cliente e o próprio MCP;
 *  - a lista {@see BLOQUEADAS}: GET que GRAVA ao ser aberto (medido rota a
 *    rota na varredura de 05/10/2026 — ver o docblock da constante).
 *
 * O filtro por perfil aqui é só uma prévia (middleware `role:`,
 * `permission:` e `modulo:` da rota) para a lista não oferecer o que vai dar
 * 403. Quem decide de verdade é a própria tela, ao ser aberta: o controller
 * roda inteiro, com as travas dele.
 */
final class CatalogoDeTelas
{
    /**
     * Padrões de nome/endereço que nunca são "tela de leitura".
     */
    private const PADRAO_FORA = '/(export|download|baixar|pdf|xlsx|csv|planilha-modelo|imprimir|print|imagem|foto|thumb|logo'
        .'|arquivo|anexo|preview|callback|oauth|connect|conectar|logout|login|password|senha|verify|verification'
        .'|confirm|portal|impersonat|debug|sanctum|_ignition|storage|mcp)/i';

    /**
     * GET que GRAVA ao ser aberto — fora, mesmo que o nome pareça de leitura.
     * Levantado rota a rota em 05/10/2026 (varredura das 197 GET autenticadas:
     * 111 limpas, 42 só aquecem cache, 6 arquivos, 37 gravam). Rota GET nova que
     * grave tem que entrar aqui — o certo é ela virar POST.
     *
     * Termina em `.*` = bloqueia o prefixo inteiro.
     *
     * Ficaram LIBERADAS de propósito: mlb.implementacao.index, mlb.polos-painel e
     * mlb.treinamentos (só `MlbConfiguracao::firstOrCreate(['id'=>1])`, que em
     * produção já existe), e as telas que só aquecem cache (decisão do usuário).
     */
    public const BLOQUEADAS = [
        // Dado de negócio gravado ao abrir
        'admin.contratos.show',               // sincroniza etapa (cria CompanyEtapaTransicao) e checklist administrativo
        'comercial.entrada.show',             // updateOrCreate do checklist automático a cada abertura + etapa
        'chamados.show',                      // marca as notificações do ticket como lidas
        'companies.portal.abrir',             // cria ticket de acesso e entra no Portal do Cliente como a empresa
        'mlb.anuncios.publicador.abrir',      // cria rascunho na 1ª visita / migra o antigo
        'mlb.anuncios.criativo.status',       // encerra criativo travado (grava status=erro)
        'mlb.anuncios.criativo.kit.status',   // idem, kit
        'mlb.anuncios.ia.analise.status',     // encerra análise de IA travada
        'mlb.anuncios.wizard',                // idem, última análise de IA
        'grants.sync.status',                 // reescreve o arquivo de status da sync
        'mlb.empresas.debug-sync',            // ferramenta de depuração de sync

        // Token OAuth guardado (Google / Mercado Livre): renova, e no Google
        // APAGA o token em invalid_grant. Duas renovações do ML em sequência
        // podem revogar a conexão do cliente.
        'google.connect',
        'google.callback',
        'agenda.eventos',                     // sincroniza eventos com o Google (update/cancelamento)
        'onboarding.agenda.eventos',          // idem, com o token do DONO do evento
        'onboarding.agenda.disponibilidade',  // lê a agenda do analista com o token DELE
        'meetings.index',
        'mlb.anuncios.publicador.termos',
        'mlb.anuncios.publicador.frete',
        'mlb.anuncios.publicador.simular',
        'mlb.anuncios.publicador.alavancas.*',
        'mlb.anuncios.rascunho.frete',
        'mlb.anuncios.rascunho.grades',
        'sugadores.mlbs-metrics-ml',

        // Arquivo / HTML de impressão que o padrão de nome não pega
        'mlb.anuncios.criativo.referencia.ver',
        'admin.financeiro.relatorio.geral',
        'admin.financeiro.relatorio',
    ];

    public function __construct(private Router $router) {}

    /**
     * Todas as telas do catálogo, sem filtro de perfil.
     *
     * @return Collection<int, array{tela:string, endereco:string, parametros:array<int,string>, modulo:string, middleware:array<int,string>}>
     */
    public function todas(): Collection
    {
        return collect($this->router->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => $this->ehTelaDeLeitura($r))
            ->map(fn (Route $r) => [
                'tela'       => $r->getName(),
                'endereco'   => '/'.ltrim($r->uri(), '/'),
                'parametros' => $r->parameterNames(),
                'modulo'     => Str::before(ltrim($r->uri(), '/'), '/') ?: 'inicio',
                'middleware' => $r->gatherMiddleware(),
            ])
            ->sortBy('endereco')
            ->values();
    }

    /** Telas que este usuário, pela prévia de middleware, pode abrir. */
    public function paraUsuario(User $usuario): Collection
    {
        return $this->todas()
            ->filter(fn (array $t) => PreviaDePerfil::permite($usuario, $t['middleware']))
            ->values();
    }

    /** A tela pelo nome da rota, se estiver no catálogo deste usuário. */
    public function achar(User $usuario, string $tela): ?array
    {
        return $this->paraUsuario($usuario)->firstWhere('tela', $tela);
    }

    /** O nome da rota GET que casa com um caminho interno (para seguir redirecionamento). */
    public function telaDoCaminho(string $caminho): ?string
    {
        try {
            $rota = $this->router->getRoutes()->match(\Illuminate\Http\Request::create($caminho, 'GET'));
        } catch (\Throwable) {
            return null;
        }

        return $this->ehTelaDeLeitura($rota) ? $rota->getName() : null;
    }

    public static function bloqueada(string $nome): bool
    {
        foreach (self::BLOQUEADAS as $regra) {
            if ($regra === $nome || (str_ends_with($regra, '.*') && str_starts_with($nome, substr($regra, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private function ehTelaDeLeitura(Route $r): bool
    {
        $nome = $r->getName();
        if (! $nome || ! in_array('GET', $r->methods(), true)) {
            return false;
        }
        if (self::bloqueada($nome)) {
            return false;
        }
        if (preg_match(self::PADRAO_FORA, $nome.' /'.$r->uri())) {
            return false;
        }

        // Só o que está atrás do login do sistema interno (guard web). O
        // portal do cliente tem guard próprio e fica fora pelo padrão acima.
        return PreviaDePerfil::exigeLogin($r->gatherMiddleware());
    }
}
