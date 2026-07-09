<?php

namespace App\Services\Digisac;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsDigisacEnvio;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Support\NpsTextRenderer;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Orquestra o envio de UMA survey via canal Digisac (WhatsApp) — v15.5.
 *
 * Responsabilidades:
 *  1. Resolver credenciais (serviceId + userId) — empresa > Configuracao > config()
 *  2. Guard-rail: empresa sem grupo mapeado → grava `NpsDigisacEnvio.status=skipped`
 *     e devolve sem tocar na API (feature request explícito).
 *  3. Renderizar texto do template com placeholders via NpsTextRenderer
 *     (usa `nps_templates.mensagem_whatsapp` ou fallback `nps_digisac_mensagem_default`)
 *  4. Chamar `DigisacClient::sendMessage`
 *  5. Persistir `NpsDigisacEnvio` com status apropriado (enviado | falha)
 *
 * NÃO faz retry — falha isolada, o comando NpsDispararMensal continua as demais
 * empresas normalmente (o canal email não é afetado por falha aqui).
 *
 * Placeholders suportados (mesmos que já existem no NpsTextRenderer):
 *   {nome_empresa}, {nome_estrategista}, {nome_analista},
 *   {mes_referencia}, {link_nps}
 *
 * @see app/Services/Digisac/DigisacClient.php
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see app/Support/NpsTextRenderer.php
 */
class NpsDigisacDispatchService
{
    public function __construct(private readonly DigisacClient $client) {}

    /**
     * Envia a mensagem de NPS via Digisac para UMA survey.
     *
     * @param array{
     *   nome_empresa: string,
     *   nome_estrategista: string,
     *   nome_analista: ?string,
     *   mes_referencia: string,
     *   link_nps: string,
     * } $vars Variáveis já preparadas pelo comando (mesmo shape do fluxo email)
     *
     * @return NpsDigisacEnvio Registro de auditoria criado (com status final)
     */
    public function send(NpsSurvey $survey, Company $company, NpsTemplate $template, array $vars): NpsDigisacEnvio
    {
        $serviceId = $this->resolveServiceId($company);
        $userId    = $this->resolveUserId();
        $contactId = (string) ($company->digisac_group_contact_id ?? '');

        // Guard: empresa sem grupo mapeado — registrar skipped e sair.
        if ($contactId === '') {
            return NpsDigisacEnvio::create([
                'survey_id'           => $survey->id,
                'company_id'          => $company->id,
                'service_id'          => $serviceId ?: null,
                'user_id'             => $userId ?: null,
                'contact_id'          => null,
                'grupo_nome_snapshot' => null,
                'mensagem'            => null,
                'status'              => NpsDigisacEnvio::STATUS_SKIPPED,
                'erro_msg'            => 'sem_grupo_mapeado',
                'metadata'            => ['reason' => 'company_sem_digisac_group_contact_id'],
            ]);
        }

        // Renderiza texto com placeholders — template.mensagem_whatsapp > fallback global
        $textoBruto = $this->resolveMensagemBruta($template);
        $texto      = NpsTextRenderer::render($textoBruto, $vars);

        try {
            $result = $this->client->sendMessage(
                contactId: $contactId,
                text:      $texto,
                userId:    $userId,
                serviceId: $serviceId,
            );

            return NpsDigisacEnvio::create([
                'survey_id'           => $survey->id,
                'company_id'          => $company->id,
                'service_id'          => $serviceId ?: null,
                'user_id'             => $userId ?: null,
                'contact_id'          => $contactId,
                'grupo_nome_snapshot' => $company->digisac_group_name_snapshot,
                'mensagem'            => $texto,
                'status'              => NpsDigisacEnvio::STATUS_ENVIADO,
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'erro_msg'            => null,
                'metadata'            => ['response' => $result['raw'] ?? null],
            ]);
        } catch (Throwable $e) {
            Log::error("[NPS Digisac] falha envio empresa {$company->id} survey_id={$survey->id}: " . $e->getMessage());

            return NpsDigisacEnvio::create([
                'survey_id'           => $survey->id,
                'company_id'          => $company->id,
                'service_id'          => $serviceId ?: null,
                'user_id'             => $userId ?: null,
                'contact_id'          => $contactId,
                'grupo_nome_snapshot' => $company->digisac_group_name_snapshot,
                'mensagem'            => $texto,
                'status'              => NpsDigisacEnvio::STATUS_FALHA,
                'erro_msg'            => substr($e->getMessage(), 0, 65000),
                'metadata'            => null,
            ]);
        }
    }

    /**
     * Fonte de verdade da mensagem WhatsApp:
     *   1. `nps_templates.mensagem_whatsapp` (se definida)
     *   2. `Configuracao::nps_digisac_mensagem_default` (fallback global)
     *   3. String vazia (guard final — evita null pointer no render)
     */
    private function resolveMensagemBruta(NpsTemplate $template): string
    {
        $doTemplate = trim((string) ($template->mensagem_whatsapp ?? ''));
        if ($doTemplate !== '') {
            return $doTemplate;
        }
        return (string) Configuracao::get('nps_digisac_mensagem_default', '');
    }

    /**
     * Resolve serviceId em ordem de precedência:
     *   1. `companies.digisac_service_id` (se a empresa tem conexão dedicada)
     *   2. `Configuracao::nps_digisac_service_id` (default do admin)
     *   3. `config('digisac.default_service_id')` (env)
     */
    private function resolveServiceId(Company $company): string
    {
        $daEmpresa = trim((string) ($company->digisac_service_id ?? ''));
        if ($daEmpresa !== '') {
            return $daEmpresa;
        }
        $doAdmin = trim((string) Configuracao::get('nps_digisac_service_id', ''));
        if ($doAdmin !== '') {
            return $doAdmin;
        }
        return (string) config('digisac.default_service_id', '');
    }

    /**
     * Resolve userId em ordem de precedência:
     *   1. `Configuracao::nps_digisac_user_id` (default do admin)
     *   2. `config('digisac.default_user_id')` (env)
     *
     * Empresa NÃO define userId proprio — sempre parte do mesmo usuário-bot global.
     */
    private function resolveUserId(): string
    {
        $doAdmin = trim((string) Configuracao::get('nps_digisac_user_id', ''));
        if ($doAdmin !== '') {
            return $doAdmin;
        }
        return (string) config('digisac.default_user_id', '');
    }
}
