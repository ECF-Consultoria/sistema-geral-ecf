<?php

namespace App\Services\Nps;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsSurvey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * NpsPendingService — Phase 72 v15.0.
 *
 * Fonte única de verdade para "empresa X está pendente de NPS no mês Y?".
 * Consumidores:
 *  - Badges em Portfolio/Show.jsx e Companies/Index.jsx (Plan 72-03)
 *  - Widgets em Dashboard/Admin.jsx e Performance/Dashboard.jsx (Plan 72-02)
 *  - Futura integração com sistema de notificações (NPS-FUTURE-03)
 *
 * Contrato pendência (SC#1):
 *   Empresa Y é PENDENTE no mês M quando TODAS as condições abaixo forem verdadeiras:
 *   1. Data atual >= diaCobranca() no mês M (guard temporal só no mês corrente)
 *   2. NpsTemplateService::resolveForCompany(Y) retorna template T sem crash
 *   3. NÃO existe NpsSurvey com (company_id=Y, template_id=T, month_reference=M,
 *      status='completed')
 *
 * Escopo de carteira:
 *   forCarteira(User $user):
 *     - Admin: todas as companies do sistema
 *     - Não-admin: apenas $user->companies() (pivot company_users)
 *
 * Config:
 *   diaCobranca() lê Configuracao::get('nps_dia_cobranca', 25) — clamp 1..31
 *   como defesa em profundidade contra corrupção de valor no banco.
 *
 * Guard de degradação:
 *   NpsTemplateService::resolveForCompany dispara RuntimeException quando nem
 *   default existir. Empresas nesse estado são SILENCIOSAMENTE excluídas de
 *   forCarteira (Log::warning estruturado) — nunca crashar a lista.
 *
 * Referências:
 *  - .planning/phases/72-dashboards-pendencias-dia-cobranca/72-01-PLAN.md
 *  - .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-01-SUMMARY.md
 *  - app/Services/Nps/NpsTemplateService.php (Phase 69-01, resolveForCompany)
 *  - app/Models/Configuracao.php (get/set key-value)
 *  - app/Models/NpsSurvey.php (template_id + month_reference + status)
 */
class NpsPendingService
{
    public function __construct(
        private NpsTemplateService $templateService,
    ) {
    }

    /**
     * Dia (1..31) a partir do qual as pendências são cobradas no mês corrente.
     *
     * Lê Configuracao::get('nps_dia_cobranca', 25) e aplica clamp 1..31 como
     * defesa em profundidade — qualquer valor corrompido no banco (fora do
     * range esperado) é normalizado sem crash. Default 25 (próximo do fim do
     * mês, aumenta janela para responder antes da cobrança).
     */
    public function diaCobranca(): int
    {
        $dia = (int) Configuracao::get('nps_dia_cobranca', 25);

        // Clamp 1..31 — o método `atualizarDiaCobranca` já valida na escrita,
        // mas aqui garantimos robustez mesmo se o valor for editado direto no
        // banco por engano (defesa em profundidade).
        return max(1, min(31, $dia));
    }

    /**
     * True quando a empresa está pendente no mês de referência (default: mês
     * corrente).
     *
     * Overload: passar $mesReferencia = Carbon::parse('2026-06-01')->startOfMonth()
     * para checar mês passado (útil em relatórios históricos e no forCarteira
     * quando meses anteriores forem considerados).
     *
     * NpsTemplateService::resolveForCompany lança RuntimeException se nem
     * default existir — capturamos e retornamos false (não é "pendente"; é
     * anômalo — logamos warning para monitoramento).
     */
    public function isPendente(Company $company, ?Carbon $mesReferencia = null): bool
    {
        $mes = $mesReferencia?->copy()->startOfMonth() ?? now()->startOfMonth();

        // Guard: só marca pendente se o mês corrente já passou do dia de
        // cobrança. Meses passados sempre são "elegíveis" (ignora guard
        // temporal para uso em relatórios históricos).
        $ehMesCorrente = $mes->equalTo(now()->startOfMonth());
        if ($ehMesCorrente && now()->day < $this->diaCobranca()) {
            return false;
        }

        try {
            $template = $this->templateService->resolveForCompany($company);
        } catch (\RuntimeException $e) {
            // Empresa sem template aplicável nem is_default — situação anômala
            // (seed NPS Padrão faltando ou revertido). Log estruturado para
            // monitoramento; retorna false (não é "pendente"; é indefinido).
            Log::warning('[NpsPendingService] empresa sem template aplicável', [
                'company_id' => $company->id,
                'name'       => $company->name,
                'reason'     => $e->getMessage(),
            ]);
            return false;
        }

        // Existe survey completa para (company, template, mês)? Se sim, NÃO é
        // pendente. Match por whereDate('month_reference') para tolerar Carbon
        // vs string YYYY-MM-DD sem depender do cast do model.
        $completou = NpsSurvey::where('company_id', $company->id)
            ->where('template_id', $template->id)
            ->whereDate('month_reference', $mes->toDateString())
            ->where('status', 'completed')
            ->exists();

        return ! $completou;
    }

    /**
     * Lista empresas pendentes na carteira do usuário para o mês corrente (ou
     * informado).
     *
     * Shape do retorno (documentado para consumidores — Plans 72-02 / 72-03 /
     * NPS-FUTURE-03):
     *   [
     *     [
     *       'company_id'      => int,
     *       'name'            => string,
     *       'template_id'     => int,
     *       'template_nome'   => string,
     *       'month_reference' => 'YYYY-MM-DD',   // primeiro dia do mês
     *       'dias_atraso'     => int,             // now()->day - diaCobranca()
     *                                             // no mês corrente; 0 em meses passados
     *     ],
     *     ...
     *   ]
     *
     * Ordenado por name ASC (facilita consumo por UI).
     *
     * Escopo de carteira:
     *   - Admin ($user->isAdmin()): todas as companies do sistema
     *   - Não-admin: apenas $user->companies() (pivot company_users)
     *
     * Performance:
     *   Faz N chamadas de resolveForCompany (cada uma com 1-2 queries). Para
     *   carteiras grandes (>200 empresas), otimizar em v15.1 com batch fetch.
     *   Para v15.0, aceitável — endpoints admin usam LIMIT 100 (padrão Plan
     *   70-04) e widgets de dashboard mostram top N.
     */
    public function forCarteira(User $user, ?Carbon $mesReferencia = null): array
    {
        // Admin vê tudo; não-admin vê apenas empresas do pivot company_users
        // (padrão consolidado do projeto — mesmo usado em SugadorController,
        // NpsController::index, etc.).
        $companies = $user->isAdmin()
            ? Company::query()->orderBy('name')->get(['id', 'name'])
            : $user->companies()->orderBy('name')->get(['companies.id', 'name']);

        return $this->forCompanies($companies, $mesReferencia);
    }

    /**
     * Versão que aceita uma coleção pré-filtrada de empresas — evita re-consultar
     * `Company::query()->get()` quando o caller já tem o universo relevante
     * carregado (ex.: `DashboardController::adminDashboard` já filtra por
     * Performance + sem MlbEmpresa antes de chegar aqui).
     *
     * Reduz o custo de memória do endpoint admin (~350 companies caem para ~168
     * quando chamado direto por Dashboard/Admin) e mantém `forCarteira` como
     * interface pública consumida pelos badges.
     *
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @return array<int, array{company_id: int, name: string, template_id: int, template_nome: string, month_reference: string, dias_atraso: int}>
     */
    public function forCompanies($companies, ?Carbon $mesReferencia = null): array
    {
        $mes = $mesReferencia?->copy()->startOfMonth() ?? now()->startOfMonth();

        $ehMesCorrente = $mes->equalTo(now()->startOfMonth());
        $diasAtraso    = $ehMesCorrente ? max(0, now()->day - $this->diaCobranca()) : 0;

        $pendentes = [];
        foreach ($companies as $company) {
            if (! $this->isPendente($company, $mes)) {
                continue;
            }

            // Segunda chamada ao templateService para pegar nome + id do
            // template resolvido (necessário no shape de retorno). O
            // RuntimeException aqui já foi tratado (logado) dentro de
            // isPendente — se chegou aqui, ele NÃO vai disparar de novo.
            // Ainda envolvemos em try/catch por defesa em profundidade contra
            // race conditions (ex.: template desativado entre as 2 chamadas).
            try {
                $template = $this->templateService->resolveForCompany($company);
            } catch (\RuntimeException $e) {
                continue; // já logado em isPendente
            }

            $pendentes[] = [
                'company_id'      => $company->id,
                'name'            => $company->name,
                'template_id'     => $template->id,
                'template_nome'   => $template->nome,
                'month_reference' => $mes->toDateString(),
                'dias_atraso'     => $diasAtraso,
            ];
        }

        return $pendentes;
    }
}
