<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\PortalTicketEquipe;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Str;

/**
 * PortalEquipeService — a equipe da ECF entrando no portal de um cliente.
 *
 * ### O problema que isto resolve
 * O analista precisa ver o portal como o cliente vê, para orientar. Os
 * caminhos ruins eram dois: pedir o código de acesso do cliente (uma pessoa
 * usando a credencial de outra, e a trilha de auditoria vira ficção), ou
 * manter o link por token vivo para uso interno (que é justamente o link
 * repassável que o login veio substituir).
 *
 * O caminho daqui é um terceiro: a equipe entra com a IDENTIDADE DELA. Fica
 * registrado quem entrou, em qual portal e quando — e o que a pessoa fizer lá
 * dentro sai no nome dela, não no do cliente.
 *
 * ### Por que existe um ticket no meio
 * Portal e sistema interno são domínios diferentes, e cookie de sessão não
 * atravessa domínio. O ticket carrega a identidade de um lado ao outro: nasce
 * onde a pessoa já provou quem é, vale 60 segundos, serve uma vez só.
 *
 * Em instalação de domínio único (o local, e qualquer ambiente sem
 * `PORTAL_CLIENTE_DOMINIO`) o mecanismo é o mesmo — só que a viagem é para o
 * mesmo endereço. Não há um caminho para produção e outro para o local.
 */
class PortalEquipeService
{
    /** Segundos de vida do ticket. É o tempo de um redirecionamento. */
    private const VIDA_SEGUNDOS = 60;

    public function __construct(private PortalAuditoria $auditoria)
    {
    }

    /**
     * Quem da equipe pode ver o portal de qual empresa.
     *
     * É a MESMA régua do onboarding, e não uma nova: admin vê qualquer
     * empresa; os demais precisam de `core.onboarding` e de a empresa estar na
     * carteira. Inventar uma régua própria aqui seria abrir uma porta lateral
     * para dados que a régua principal protege.
     */
    public function podeEntrar(User $membro, Company $empresa): bool
    {
        if ($membro->isAdmin()) {
            return true;
        }

        if (! $membro->hasPermission(Permissions::CORE_ONBOARDING)) {
            return false;
        }

        return $membro->companies()->where('companies.id', $empresa->id)->exists();
    }

    /**
     * Emite a passagem e devolve o token EM CLARO — a única vez que ele
     * existe fora do hash. Quem chama põe na URL de redirecionamento.
     */
    public function emitir(User $membro, Company $empresa, ?string $ip = null): string
    {
        // Varre o lixo antes de criar. Ticket vencido não serve para nada e a
        // tabela não deve virar um histórico acidental de quem entrou onde —
        // esse histórico é do activity_log, com contexto.
        PortalTicketEquipe::where('expira_em', '<', now())->delete();

        $token = Str::random(64);

        PortalTicketEquipe::create([
            'user_id'    => $membro->id,
            'company_id' => $empresa->id,
            'token_hash' => hash('sha256', $token),
            'expira_em'  => now()->addSeconds(self::VIDA_SEGUNDOS),
            'ip'         => $ip,
        ]);

        return $token;
    }

    /**
     * Consome a passagem. Devolve `null` para QUALQUER recusa — vencido,
     * já usado, inexistente, empresa apagada.
     *
     * Motivo de não distinguir: quem chega aqui com um token inválido não tem
     * nada a ganhar sabendo qual dos motivos foi. Quem chega com um válido não
     * precisa saber.
     *
     * @return array{membro: User, empresa: Company}|null
     */
    public function consumir(string $token, ?string $ip = null): ?array
    {
        $ticket = PortalTicketEquipe::with(['usuario', 'empresa'])
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('usado_em')
            ->where('expira_em', '>', now())
            ->first();

        if (! $ticket || ! $ticket->usuario || ! $ticket->empresa) {
            return null;
        }

        // Marcar como usado ANTES de devolver: se duas requisições chegarem
        // juntas com o mesmo token, só uma passa.
        $ticket->update(['usado_em' => now()]);

        // A permissão é reconferida na entrada, não só na emissão. Entre os
        // dois momentos cabem 60 segundos — pouco, mas o suficiente para
        // alguém ter sido removido da carteira.
        if (! $this->podeEntrar($ticket->usuario, $ticket->empresa)) {
            return null;
        }

        $this->auditoria->equipeEntrou($ticket->usuario, $ticket->empresa, $ip);

        return ['membro' => $ticket->usuario, 'empresa' => $ticket->empresa];
    }

    /**
     * A URL de entrada no portal, com a passagem.
     *
     * Sem `PORTAL_CLIENTE_DOMINIO` configurado (local), aponta para o próprio
     * endereço — o fluxo é idêntico, só não troca de domínio.
     */
    public function urlDeEntrada(string $token): string
    {
        $url = route('portal.equipe.entrar', ['t' => $token]);
        $dominio = config('portal.dominio_cliente');

        if (! $dominio) {
            return $url;
        }

        return preg_replace('#^(https?://)[^/]+#', '$1'.$dominio, $url);
    }
}
