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
     * Construtor enxuto — nome da empresa, tipo de serviço e autor (o user do Comercial).
     *
     * O autor é opcional: se null, a notificação aparece como originada pelo sistema.
     */
    public function __construct(string $nomeEmpresa, string $serviceType, ?int $autorUserId)
    {
        parent::__construct(
            titulo:      'Nova empresa cadastrada: ' . $nomeEmpresa,
            mensagem:    'O setor Comercial cadastrou a empresa "' . $nomeEmpresa . '" (tipo: ' . $serviceType . '). Verifique os pendentes.',
            categoria:   Categoria::MANUAL,
            autorUserId: $autorUserId,
            url:         route('notificacoes.index'),
            meta:        ['empresa' => $nomeEmpresa, 'service_type' => $serviceType],
        );
    }
}
