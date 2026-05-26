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
     * Phase 14 (Frente B): recebe a lista de nomes de Servico já resolvidos
     * (ex.: ['Polos', 'Publicidade']).
     *
     * A chave `meta` usa `servicos` (array).
     *
     * @param  array<string>  $servicos  Nomes dos serviços.
     */
    public function __construct(string $nomeEmpresa, array $servicos, ?int $autorUserId)
    {
        $servicosNomes = array_values(array_filter($servicos));
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
