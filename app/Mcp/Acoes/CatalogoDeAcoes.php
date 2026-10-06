<?php

namespace App\Mcp\Acoes;

use App\Mcp\Telas\PreviaDePerfil;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Quais formulários/botões do Admin o `enviar_formulario` pode acionar.
 *
 * Decisão do usuário em 06/10/2026: quem está conectado pode "editar o que
 * quiser" pelo MCP, gravando direto — o mesmo que faria na tela. Entra toda
 * rota POST/PUT/PATCH/DELETE com nome, atrás do login (`auth`). Sai só o que
 * não é "usar a tela":
 *  - mexer na própria sessão/identidade de quem conectou (sair, senha, perfil);
 *  - credencial e conexão OAuth guardadas (Google, Mercado Livre, Shopee);
 *  - o módulo de anúncios do Mercado Livre — ver {@see BLOQUEADAS};
 *  - arquivo (exportar/baixar), o próprio MCP e o OAuth do Passport.
 *
 * Como em {@see \App\Mcp\Telas\CatalogoDeTelas}, o filtro por perfil aqui é
 * só prévia (middleware da rota); quem decide é o controller, ao receber o
 * formulário.
 */
final class CatalogoDeAcoes
{
    /** Métodos que gravam, na ordem de preferência quando a rota aceita mais de um. */
    private const METODOS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const PADRAO_FORA = '/(oauth|passport|sanctum|_ignition|mcp|export|download|baixar|impersonat|webhook)/i';

    /**
     * Fora do MCP mesmo para quem pode na tela. Termina em `.*` = prefixo inteiro.
     */
    public const BLOQUEADAS = [
        // Sessão e identidade de quem conectou: derrubariam a própria conexão
        // ou trocariam a senha/conta por uma conversa.
        'logout',
        'password.*',
        'profile.*',
        'verification.*',

        // Credencial e conexão OAuth guardadas. Desconectar a conta Google ou
        // do Mercado Livre/Shopee de um cliente não é "preencher a tela".
        'google.*',
        'agenda.google.*',
        'ml.oauth.*',
        'shopee.oauth.*',

        // Anúncios do Mercado Livre (Anunciar/Publicador): o módulo mistura
        // rascunho local com escrita na API do ML (publicar, editar anúncio,
        // foto, alavanca) em conta de CLIENTE — o que é proibido fora da conta
        // de teste (regra do usuário de 01/10/2026). Fica de fora inteiro.
        'mlb.anuncios.*',
    ];

    public function __construct(private Router $router) {}

    /**
     * @return Collection<int, array{acao:string, metodo:string, endereco:string, parametros:array<int,string>, modulo:string, middleware:array<int,string>}>
     */
    public function todas(): Collection
    {
        return collect($this->router->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => $this->ehAcao($r))
            ->map(fn (Route $r) => [
                'acao'       => $r->getName(),
                'metodo'     => $this->metodo($r),
                'endereco'   => '/'.ltrim($r->uri(), '/'),
                'parametros' => $r->parameterNames(),
                'modulo'     => Str::before(ltrim($r->uri(), '/'), '/') ?: 'inicio',
                'middleware' => $r->gatherMiddleware(),
            ])
            ->sortBy(fn ($a) => $a['endereco'].' '.$a['metodo'])
            ->values();
    }

    public function paraUsuario(User $usuario): Collection
    {
        return $this->todas()
            ->filter(fn (array $a) => PreviaDePerfil::permite($usuario, $a['middleware']))
            ->values();
    }

    public function achar(User $usuario, string $acao): ?array
    {
        return $this->paraUsuario($usuario)->firstWhere('acao', $acao);
    }

    public function rota(string $acao): ?Route
    {
        return $this->router->getRoutes()->getByName($acao);
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

    private function ehAcao(Route $r): bool
    {
        $nome = $r->getName();
        if (! $nome || $this->metodo($r) === null) {
            return false;
        }
        if (self::bloqueada($nome) || preg_match(self::PADRAO_FORA, $nome.' /'.$r->uri())) {
            return false;
        }

        return PreviaDePerfil::exigeLogin($r->gatherMiddleware());
    }

    private function metodo(Route $r): ?string
    {
        foreach (self::METODOS as $m) {
            if (in_array($m, $r->methods(), true)) {
                return $m;
            }
        }

        return null;
    }
}
