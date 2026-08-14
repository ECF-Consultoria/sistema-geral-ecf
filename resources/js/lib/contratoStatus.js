/**
 * Fase 131 Plano 01 (D-04 do 131-CONTEXT.md) — espelho manual de
 * `ContratoAssinatura::STATUS_*` (o projeto não tem enum compartilhado
 * PHP↔JS, sincronia é manual — mesmo padrão de `SETOR_LABELS`/`SETOR_CLS`
 * em `Comercial/EmpresasListagem.jsx`).
 *
 * Módulo COMPARTILHADO de propósito: três telas desta fase consomem o
 * mesmo mapa (`Admin/Contratos.jsx`, `Admin/ContratoDetalhe.jsx` e o badge
 * de `Comercial/EmpresasListagem.jsx`) — duplicar por página faria os
 * rótulos derivarem entre telas.
 *
 * Correspondência estado→ícone, do 131-UI-SPEC.md — NENHUM ícone é
 * importado aqui de propósito (biblioteca sem dependência de UI/tela);
 * cada página importa o ícone que precisa da sua própria biblioteca de
 * ícones:
 *   rascunho               -> FileEdit
 *   aguardando_assinaturas -> Send
 *   assinado               -> CheckCircle2
 *   recusado               -> XCircle
 *   expirado               -> TimerOff
 *   cancelado              -> Ban
 *   erro                   -> AlertTriangle
 */

// Os 7 estados de `ContratoAssinatura::STATUS_TODOS` — redação final do
// 131-UI-SPEC.md ("Copywriting Contract" / D-04). Exatamente 7 chaves: se o
// resumo por situação (D-04) contar mais ou menos que isso, alguma tela
// desviou deste módulo.
export const CONTRATO_STATUS_LABELS = {
    rascunho:               'Não enviado',
    aguardando_assinaturas: 'Esperando assinatura',
    assinado:                'Assinado',
    recusado:                'Cliente recusou',
    expirado:                'Prazo venceu',
    cancelado:                'Cancelado',
    erro:                     'Falha no envio',
};

// Estado SÓ DE EXIBIÇÃO — empresa que ainda não tem nenhum
// `ContratoAssinatura` criado (ainda não passou por "Gerar contrato").
// NÃO é valor do enum `ContratoAssinatura::STATUS_*` e NUNCA deve entrar no
// mapa de rótulos acima, senão o resumo de 7 contagens da D-04 vira 8.
export const SEM_CONTRATO = 'aguardando_administrativo';
export const SEM_CONTRATO_LABEL = 'Aguardando Administrativo';

// As 7 cores do 131-UI-SPEC.md ("Mapa de cor por estado do contrato") +
// `outros` como fallback, no mesmo espírito de `SETOR_CLS.outros` em
// `Comercial/EmpresasListagem.jsx`.
export const CONTRATO_STATUS_CLS = {
    rascunho:               'bg-white/[0.05] text-white/50 border-white/10',
    aguardando_assinaturas: 'bg-amber-500/10 text-amber-300 border-amber-500/20',
    assinado:                'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    recusado:                'bg-red-500/10 text-red-400 border-red-500/20',
    expirado:                'bg-orange-500/10 text-orange-400 border-orange-500/20',
    cancelado:                'bg-white/[0.04] text-white/35 border-white/10',
    erro:                     'bg-rose-500/10 text-rose-400 border-rose-500/20',
    outros:                   'bg-white/10 text-white/60 border-white/10',
};

/**
 * Devolve o rótulo pt-BR do estado. Fallback para o próprio `status` cru
 * se vier uma chave inesperada — nunca lança, nunca renderiza `undefined`.
 */
export function rotuloContrato(status) {
    return CONTRATO_STATUS_LABELS[status] ?? status;
}

/**
 * Devolve a classe Tailwind (badge) do estado. Fallback em
 * `CONTRATO_STATUS_CLS.outros` se vier uma chave inesperada.
 */
export function classeContrato(status) {
    return CONTRATO_STATUS_CLS[status] ?? CONTRATO_STATUS_CLS.outros;
}

/**
 * Pluralização pt-BR do "há N dias" (D-08 do 131-CONTEXT.md, badge do
 * Comercial), redação travada pelo 131-UI-SPEC.md:
 *   0        -> "há menos de 1 dia"
 *   1        -> "há 1 dia"
 *   >= 2      -> "há N dias"
 *   nulo/negativo -> "há menos de 1 dia" (nunca "há -1 dias")
 */
export function formatarHaDias(dias) {
    const n = Number(dias);
    if (dias === null || dias === undefined || !Number.isFinite(n) || n <= 0) {
        return 'há menos de 1 dia';
    }
    if (n === 1) {
        return 'há 1 dia';
    }
    return `há ${n} dias`;
}
