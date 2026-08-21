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
//
// Quick 260819-guy (Tarefa 7 item 3, 2026-08-19) — `rascunho` era "Não
// enviado": literalmente verdade, mas lê como falha ("alguém esqueceu de
// enviar"). O sistema para no rascunho DE PROPÓSITO (D-02 da Fase 127-05,
// `GerarContratoAssinaturaJob`, `ativar: false` — "a ativação acontece FORA
// do sistema"); "Falta enviar" descreve a AÇÃO PENDENTE, não uma falha.
// Chave e quantidade de chaves NÃO mudaram — só o texto do rótulo.
export const CONTRATO_STATUS_LABELS = {
    rascunho:               'Falta enviar',
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

// Quick 260816-d72 (UI-06/D-05) — estado SÓ DE EXIBIÇÃO, mesmo espírito de
// `SEM_CONTRATO` acima: deriva de `ContratoAssinatura::estaPreparando()` no
// BACKEND (regra de status + envelope + janela de tempo), o front recebe o
// booleano pronto e NUNCA recalcula tempo. NÃO é valor do enum
// `ContratoAssinatura::STATUS_*` e NUNCA deve entrar em
// `CONTRATO_STATUS_LABELS`/`CONTRATO_STATUS_CLS` — o resumo de 7 contagens
// (D-04) continua contando "preparando" dentro de `rascunho`.
export const PREPARANDO_LABEL = 'Preparando';

// Âmbar (espera), nunca vermelho (erro) e nunca `ecf-yellow` (ação
// primária, já ocupado pelo botão "Gerar contrato" e pelo ícone do
// cabeçalho — regra do UI-SPEC de um único ecf-yellow por tela). Mesma
// classe já usada por `aguardando_assinaturas` acima.
export const PREPARANDO_CLS = 'bg-amber-500/10 text-amber-300 border-amber-500/20';

// `title` do badge na lista — coluna estreita, densidade travada pelo
// UI-SPEC, não cabe a frase inteira.
export const PREPARANDO_TITULO = 'O contrato acabou de ser pedido e pode levar até um minuto para ficar pronto.';

// Faixa explicativa do detalhe — mesmo molde da faixa de "cancelamento
// solicitado" já existente em `Admin/ContratoDetalhe.jsx`.
export const PREPARANDO_AVISO = 'Preparando o contrato, pode levar até um minuto. Atualize a página em instantes para ver a situação.';

// Quick 260820-my3 (Tarefa 2) — estado SÓ DE EXIBIÇÃO, irmão de
// `PREPARANDO_*` acima: deriva de `ContratoAssinatura::estaMontagemTravada()`
// no BACKEND — mesma regra de `estaPreparando()` (status + envelope), do
// lado OPOSTO da mesma janela de tempo. Mutuamente exclusivo com
// `preparando` por construção. NÃO é valor do enum
// `ContratoAssinatura::STATUS_*` e NUNCA deve entrar em
// `CONTRATO_STATUS_LABELS`/`CONTRATO_STATUS_CLS` — mesma disciplina do
// resumo de 7 contagens (D-04), continua contando "montagem travada" dentro
// de `rascunho`.
//
// Incidente que originou (2026-08-20, produção): contrato pedido, nada
// criado, e a tela dizia "Falta enviar" — mandando o usuário procurar na
// Clicksign onde não havia nada. Copy SEM jargão (nada de "envelope",
// "job", "fila", "worker"): diz que o contrato foi pedido, ainda não ficou
// pronto, não adianta procurar na Clicksign, e o time técnico precisa
// olhar.
export const MONTAGEM_TRAVADA_LABEL = 'Travado';

// Laranja — mais alarmante que o âmbar de `preparando` (pede atenção do
// time técnico, não é mais "só espera"), mas NUNCA o rose de `erro`: não é
// uma falha registrada, é uma montagem que não terminou. Mesma classe já
// usada por `expirado` em `CONTRATO_STATUS_CLS` (reuso de tom, não de
// significado — este estado não entra naquele mapa).
export const MONTAGEM_TRAVADA_CLS = 'bg-orange-500/10 text-orange-400 border-orange-500/20';

// `title` do badge na lista — mesmo molde de `PREPARANDO_TITULO`.
export const MONTAGEM_TRAVADA_TITULO = 'O contrato foi pedido, mas não ficou pronto. Não procure na Clicksign — avise o time técnico.';

// Faixa explicativa do detalhe — mesmo molde de `PREPARANDO_AVISO`.
export const MONTAGEM_TRAVADA_AVISO = 'Este contrato foi pedido, mas não ficou pronto — ainda não existe nada para encontrar na Clicksign. Avise o time técnico para verificar.';

/**
 * Rótulo com os sub-estados de espera/travamento: devolve "Travado" quando
 * `travado` for verdadeiro, "Preparando" quando `preparando` for
 * verdadeiro, senão delega para `rotuloContrato()`. `travado` é opcional —
 * chamadas existentes com dois argumentos continuam funcionando.
 */
export function rotuloContratoComPreparo(status, preparando, travado = false) {
    if (travado) return MONTAGEM_TRAVADA_LABEL;
    return preparando ? PREPARANDO_LABEL : rotuloContrato(status);
}

/**
 * Classe com os sub-estados de espera/travamento: devolve a classe laranja
 * quando `travado` for verdadeiro, a âmbar quando `preparando` for
 * verdadeiro, senão delega para `classeContrato()`. `travado` é opcional —
 * chamadas existentes com dois argumentos continuam funcionando.
 */
export function classeContratoComPreparo(status, preparando, travado = false) {
    if (travado) return MONTAGEM_TRAVADA_CLS;
    return preparando ? PREPARANDO_CLS : classeContrato(status);
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
