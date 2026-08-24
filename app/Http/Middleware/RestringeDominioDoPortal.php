<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RestringeDominioDoPortal — no domínio do Portal do Cliente, só existe o
 * Portal do Cliente.
 *
 * O Portal roda na MESMA aplicação do sistema interno (mesmo Laravel, mesmo
 * banco, mesmo deploy), servido num segundo domínio pelo Nginx. Sem este
 * middleware, todas as rotas internas respondem nos dois endereços: até
 * 24/08/2026, `cliente.ecfconsultoria.com.br/dashboard` levava à tela de login
 * do admin, e um login bem-sucedido ali entregava o sistema interno inteiro no
 * endereço que a gente divulga para cliente.
 *
 * ### Por que ALLOWLIST, e não bloqueio
 * A regra é "no domínio do cliente nada existe, exceto o que está em
 * {@see self::PERMITIDO}". O inverso — listar o que bloquear — falha na primeira
 * rota nova: quem criar `/relatorios-internos` amanhã não vai lembrar de vir
 * aqui proibir, e ela nasceria pública no endereço do cliente. Com allowlist, a
 * rota nova nasce invisível lá, que é o padrão seguro. O custo é ter de vir aqui
 * ao acrescentar um módulo do portal — e isso é justamente o que se quer que
 * seja uma decisão consciente.
 *
 * ### O que este middleware NÃO faz
 * Não é autenticação. O acesso ao Portal continua sendo por posse do token na
 * URL enquanto o login de cliente não existir. Este middleware só garante que o
 * domínio do cliente não sirva o sistema interno — são problemas diferentes, e
 * este resolve um deles.
 *
 * ### Desligado quando não configurado
 * Sem `PORTAL_CLIENTE_DOMINIO` no `.env`, o middleware não faz nada. Assim o
 * ambiente local e qualquer instalação sem o subdomínio seguem funcionando como
 * antes, e a mudança é reversível por variável de ambiente — sem deploy.
 */
class RestringeDominioDoPortal
{
    /**
     * O que existe no domínio do cliente. Padrões de `Request::is()`.
     *
     * Cada linha aqui é uma decisão de expor algo no endereço público do
     * cliente. Acrescente com o mesmo cuidado que se acrescenta uma rota sem
     * `auth`.
     */
    private const PERMITIDO = [
        // O Portal e todos os seus módulos.
        'portal-cliente',
        'portal-cliente/*',

        // Prefixo antigo do mesmo portal — responde 301 para o novo. Está no
        // WhatsApp de clientes e não há como recolher.
        'onboarding-cliente/*',

        // Volta do consentimento do Mercado Livre. O cliente sai do portal para
        // autorizar e o ML devolve nesta rota; sem ela, o fluxo de conectar a
        // conta morre no meio, no domínio dele.
        'oauth/mercadolivre/callback',

        // A raiz, que apresenta o portal a quem digitou só o domínio.
        '/',

        // ── Portal AUTENTICADO ───────────────────────────────────────────
        // A porta nova: entrar por e-mail e código, e navegar sem token na
        // URL. Cada linha aqui é uma decisão de expor algo no endereço
        // público do cliente — acrescente com o mesmo cuidado de sempre.
        'entrar',
        'entrar/*',
        'sair',

        // Uma linha por rota, NUNCA `portal/*`. O curinga deixava passar
        // `/portal/usuarios` — a tela ADMIN de gerenciar acessos, que colide
        // no prefixo. Foi pego em produção, e é a demonstração de por que a
        // allowlist tem de ser específica: um curinga aqui reintroduz
        // exatamente o vazamento que este middleware existe para impedir.
        'portal/inicio',
        'portal/onboarding',
        'portal/ppa',
        'portal/ppa/tarefas/*',
        'portal/empresa',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $dominio = config('portal.dominio_cliente');

        // Domínio interno (ou subdomínio não configurado): nada muda.
        if (! $dominio || $request->getHost() !== $dominio) {
            return $next($request);
        }

        // A sessão do CLIENTE dura muito mais que a da equipe. `SESSION_LIFETIME`
        // é 120 minutos, curto de propósito para quem mexe no sistema interno —
        // e aplicá-lo ao cliente faria ele pedir código novo a cada duas horas,
        // reintroduzindo por outra via o atrito que esta mudança veio remover.
        //
        // Precisa ser aqui: este middleware roda em `prepend`, ANTES do
        // `StartSession`, que é quem lê o lifetime para montar o cookie.
        // Ajustar depois não teria efeito nenhum.
        config(['session.lifetime' => config('portal.sessao_minutos', 43200)]);

        foreach (self::PERMITIDO as $padrao) {
            if ($request->is($padrao)) {
                return $next($request);
            }
        }

        // 404, não 403: no domínio do cliente essas rotas não existem, e dizer
        // "proibido" confirmaria que existem em algum lugar.
        abort(404);
    }
}
