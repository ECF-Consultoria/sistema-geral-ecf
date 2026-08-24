<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\OnboardingLink;
use App\Models\PortalUsuario;
use App\Services\Onboarding\OnboardingLinkService;
use App\Support\Portal\ModulosPortal;
use Illuminate\Support\Str;

/**
 * PortalClienteService — a porta de entrada do Portal do Cliente.
 *
 * Toda página do portal começa aqui: resolve o token em empresa, carimba o
 * acesso e monta o contexto que o `PortalClienteLayout` consome (identidade da
 * empresa + menu de módulos com badges). Um controller de módulo novo só
 * precisa de `resolver()` e `contexto()`.
 *
 * ### Por que o token continua sendo o do onboarding
 * `onboarding_links` já é "1 token por EMPRESA" — nunca foi por onboarding
 * (ver `OnboardingLinkService::paraEmpresa()`, `company_id` é `unique`). O
 * portal virou multimódulo sem trocar de chave: o mesmo link que o cliente já
 * tem no WhatsApp continua valendo, agora abrindo o Início em vez do
 * Onboarding. Criar um token novo teria invalidado todos os links já enviados
 * — e não havia nada a ganhar, porque a unidade de acesso sempre foi a empresa.
 */
class PortalClienteService
{
    public function __construct(
        private OnboardingLinkService $linkService,
        private PortalPpaService $ppaService,
    ) {
    }

    /**
     * Token → link, com o acesso carimbado. `firstOrFail()` é o que produz o
     * 404 de token inexistente ou adivinhado (T-135-11-01) — o mesmo contrato
     * que o portal tinha antes de virar multimódulo.
     */
    public function resolver(string $token): OnboardingLink
    {
        $link = OnboardingLink::where('token', $token)->with('company')->firstOrFail();

        $link->ultimo_acesso = now();
        $link->save();

        return $link;
    }

    /**
     * O contexto compartilhado do portal — vai como prop em TODA página, é o
     * que alimenta o menu lateral e o cabeçalho.
     *
     * Os badges são calculados aqui, e não em cada controller, porque o menu é
     * o mesmo em todas as páginas: o cliente precisa ver "3 pendências no
     * Onboarding" enquanto está no PPA. Custa duas consultas por request e
     * evita a classe de bug em que o número só está certo na página do próprio
     * módulo.
     *
     * @param  string  $modulo  chave do módulo da página atual
     */
    public function contexto(OnboardingLink $link, string $modulo): array
    {
        return $this->montarContexto($link->company, $modulo, $link->token);
    }

    /**
     * O mesmo contexto, para o portal AUTENTICADO.
     *
     * A empresa chega pronta — resolvida do usuário logado pelo
     * `PortalContexto`, nunca da URL. Sem token: as URLs do menu saem das
     * rotas sem token.
     *
     * `usuario` viaja no payload para a tela poder cumprimentar a pessoa pelo
     * nome e oferecer "sair" — coisas que o modo por token não tem, porque lá
     * não se sabe QUEM está do outro lado.
     */
    public function contextoAutenticado(Company $company, string $modulo, PortalUsuario $usuario): array
    {
        return [
            ...$this->montarContexto($company, $modulo, null),
            'usuario' => [
                'nome'  => $usuario->nome,
                'email' => $usuario->email,
            ],
            // Só desenha o seletor quem tem mais de uma. Para a maioria, que
            // tem uma empresa só, ele não existe.
            'empresas_disponiveis' => $usuario->empresas()->count() > 1
                ? $usuario->empresas()->orderBy('name')->get(['companies.id', 'companies.name'])
                    ->map(fn ($e) => ['id' => $e->id, 'nome' => $e->name])->values()->all()
                : [],
        ];
    }

    /** O que os dois modos têm em comum. */
    private function montarContexto(Company $company, string $modulo, ?string $token): array
    {
        return [
            'token'   => $token,
            'modulo'  => $modulo,
            'empresa' => $this->identidade($company),
            'modulos' => ModulosPortal::paraEmpresa($company, $token, $modulo, [
                ModulosPortal::ONBOARDING => $this->pendenciasOnboarding($company),
                ModulosPortal::PPA        => $this->ppaService->pendentes($company),
            ]),
        ];
    }

    /**
     * Nome, logo e o monograma de fallback.
     *
     * `iniciais` vai pronto do backend em vez de ser derivado no JSX para que
     * as duas telas que desenham a marca (menu e hub) nunca divirjam — e
     * porque a regra tem casos que não cabem numa expressão inline: nome de
     * uma palavra usa as DUAS primeiras letras dela ("Vitória" → "VI"), e
     * conectivos não contam ("Casa de Festas" → "CF", não "CD").
     */
    private function identidade(Company $company): array
    {
        return [
            'nome'     => $company->name,
            'logo_url' => $company->logo_url,
            'iniciais' => self::iniciais($company->name),
        ];
    }

    /**
     * Até duas iniciais para o monograma de empresa sem logo.
     */
    public static function iniciais(?string $nome): string
    {
        $ignorar = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o'];

        $palavras = collect(preg_split('/\s+/', trim((string) $nome)))
            ->filter(fn ($p) => $p !== '' && ! in_array(Str::lower($p), $ignorar, true))
            ->values();

        if ($palavras->isEmpty()) {
            return '?';
        }

        if ($palavras->count() === 1) {
            return Str::upper(Str::substr($palavras[0], 0, 2));
        }

        return Str::upper(Str::substr($palavras[0], 0, 1) . Str::substr($palavras[1], 0, 1));
    }

    /**
     * Quantos passos o cliente pode resolver AGORA.
     *
     * Mesma régua da tela do Onboarding: só `aberto`. Passo `bloqueado` espera
     * outro e não é acionável — contá-lo no badge mandaria o cliente para um
     * card em que ele não pode mexer.
     */
    private function pendenciasOnboarding(Company $company): int
    {
        return collect($this->linkService->passosDoCliente($company))
            ->where('status', 'aberto')
            ->count();
    }
}
