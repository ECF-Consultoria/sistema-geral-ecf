<?php

namespace App\Notifications;

/**
 * Notificação disparada pelo setor Comercial quando uma nova empresa é cadastrada.
 *
 * Enviada automaticamente para os líderes do setor de destino (Publicação,
 * Publicidade, Gestão) após criação bem-sucedida em ComercialController::store().
 *
 * A categoria é fixa (Categoria::MANUAL), e o payload inclui nome da empresa
 * e tipo de serviço no campo `meta`, para que os líderes identifiquem o contexto
 * sem acessar o banco.
 *
 * Canal e payload canônico vêm 100% do parent BaseNotification — via() e toArray()
 * não são sobrescritos.
 */
class EmpresaCadastradaNotification extends BaseNotification
{
    /**
     * Construtor enxuto — nome da empresa, lista de serviços e autor (o user do Comercial).
     *
     * O autor é opcional: se null, a notificação aparece como originada pelo sistema.
     *
     * Phase 14 (Frente B): aceita `string|array $servicos` em backward compat.
     * - `string`: formato legacy "polos+publicidade" (separado por '+'); convertido
     *   internamente para array de nomes.
     * - `array`: formato novo — lista de nomes de Servico já resolvidos
     *   (ex.: ['Polos', 'Publicidade']).
     *
     * A chave `meta` passou de `service_type` (string) para `servicos` (array).
     * Per CONTEXT.md D-07 item 6 e RESEARCH §9. A forma `string` será removida
     * no Plan 14-04 quando ComercialController for refatorado.
     *
     * @param  string|array<string>  $servicos  Nomes dos serviços (formato novo) ou string legacy.
     *
     * @deprecated A assinatura aceitando `string` é mantida apenas como
     *             backward compat até o Plan 14-04.
     */
    public function __construct(string $nomeEmpresa, string|array $servicos, ?int $autorUserId)
    {
        $servicosNomes = is_array($servicos)
            ? array_values(array_filter($servicos))
            : array_values(array_filter(array_map('trim', explode('+', $servicos))));
        $servicosLabel = implode(', ', $servicosNomes) ?: 'sem serviços';

        parent::__construct(
            titulo:      'Nova empresa cadastrada: ' . $nomeEmpresa,
            mensagem:    'O setor Comercial cadastrou a empresa "' . $nomeEmpresa . '" (serviços: ' . $servicosLabel . '). Verifique os pendentes.',
            categoria:   Categoria::MANUAL,
            autorUserId: $autorUserId,
            url:         route('notificacoes.index'),
            meta:        ['empresa' => $nomeEmpresa, 'servicos' => $servicosNomes],
        );
    }
}
