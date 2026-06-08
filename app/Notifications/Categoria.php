<?php

namespace App\Notifications;

/**
 * Categorias canônicas de Notification do MVP v3.0.
 *
 * É a tipagem da coluna `data->categoria` na tabela `notifications` — toda
 * Notification do sistema (extends `BaseNotification`) recebe uma instância
 * deste enum no construtor e persiste `$this->categoria->value` (string) no
 * payload canônico (ver `BaseNotification::toArray`).
 *
 * A lista é INTENCIONALMENTE estática (não no banco), mesmo princípio do
 * catálogo `App\Support\Permissions`: adicionar uma categoria nova exige
 * código novo (subclasse de Notification + dispatch site + UI label), então
 * banco seria fonte falsa de verdade. Phases 9+ usam `Categoria::from($string)`
 * para hidratar do banco; valores inválidos disparam `ValueError` em tempo de
 * leitura — defesa contra "categoria forjada" persistida fora do dispatch.
 *
 * Categorias adicionais (`sync_falhado`, `sugador_detectado`, etc.) estão em
 * Deferred Ideas (08-CONTEXT.md §deferred) — não adicionar nesta fase.
 */
enum Categoria: string
{
    /** Disparada quando uma meta é atribuída a um setor/empresa/carteira (Phase 11). */
    case META_ATRIBUIDA = 'meta_atribuida';

    /** Disparada quando uma meta (qualquer escopo) é atingida (Phase 11). */
    case META_ATINGIDA  = 'meta_atingida';

    /** Notificação criada manualmente por um usuário com permissão `notificacoes.criar` (Phase 12). */
    case MANUAL         = 'manual';

    /** Signal crítico do ECF Drive para empresa da nossa carteira — `signal.detected` com severity=critical (Phase 29). */
    case ALERTA_ECF     = 'alerta_ecf';
}
