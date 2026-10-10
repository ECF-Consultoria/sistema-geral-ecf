// ═══════════════════════════════════════════════════════════════════════
// Frete SUGERIDO na Precificação do Portal (09/10/2026, ADR PORTAL-02).
//
// Só textos e decisões de exibição. O frete, a faixa de preço, o ponto fixo
// frete ↔ preço e o preço em si chegam PRONTOS do servidor
// (`EstruturaPrecificacaoService` + `FreteMe2Service`): recalcular aqui é como o
// onboarding chegou a publicar preço 43% errado (`precificacao-onboarding-duas-telas.md` §1).
//
// Rótulo NEUTRO de origem: "sugerido pela sua conta" quando o valor veio da
// cotação real da conta do cliente, "estimado pela tabela" quando veio da tabela
// de custos do Mercado Livre.
// ═══════════════════════════════════════════════════════════════════════

export const ORIGEM_DO_SUGERIDO = {
    conta: 'sugerido pela sua conta',
    tabela: 'estimado pela tabela',
};

/** Visitas do "Cotar agora" num clique, enquanto o servidor devolve pendência (o lote é por chamada). */
export const VOLTAS_DA_COTACAO = 4;

/**
 * O texto pequeno sob o campo de frete de um tipo, ou null.
 * - sugerido: de onde veio (conta ou tabela);
 * - herdado do outro tipo (sem sugestão: ME1, oferta sem medidas): "mesmo do Clássico";
 * - digitado diferente da sugestão: a sugestão ao lado, para comparar.
 *
 * @param {object} calculo  `por_oferta[id].classico|premium` do servidor
 * @param {string} outroTipo  'Clássico' | 'Premium'
 * @param {(v:number)=>string} fmt  formatador de reais
 * @returns {{texto: string, tipo: 'sugerido'|'herdado'|'sugestao', fonte?: string}|null}
 */
export function rotuloDoFrete(calculo, outroTipo, fmt) {
    const s = calculo?.frete_sugerido ?? null;
    const origem = s ? (ORIGEM_DO_SUGERIDO[s.fonte] ?? ORIGEM_DO_SUGERIDO.tabela) : null;

    switch (calculo?.frete_origem) {
        case 'sugerido':
            return s ? { texto: origem, tipo: 'sugerido', fonte: s.fonte } : null;
        case 'outro_tipo':
            return { texto: `mesmo do ${outroTipo}`, tipo: 'herdado' };
        case 'digitado':
            return s && Number(s.valor) !== Number(calculo.frete)
                ? { texto: `${origem}: ${fmt(s.valor)}`, tipo: 'sugestao', fonte: s.fonte }
                : null;
        default:
            return null;
    }
}

/** O campo em branco mostra, apagado, o frete que a conta usa (o sugerido ou o do outro tipo). */
export const freteEmBranco = (calculo) => calculo?.frete_origem === 'sugerido' || calculo?.frete_origem === 'outro_tipo';

/**
 * A frase depois do "Cotar agora", a partir do resumo `cotacao` do servidor.
 *
 * @param {{conectado?: boolean, total?: number, cotados?: number, pendentes?: number, falhou?: boolean, limitado?: boolean}|null} c
 */
export function textoDaCotacao(c) {
    if (! c) return 'Não deu para cotar agora. Tente de novo.';
    if (c.limitado) return 'Muitas cotações seguidas. Espere um minuto e tente de novo.';
    if (! c.conectado) return 'Conecte a conta do Mercado Livre para cotar o frete real.';
    if (! c.total) return 'Nenhum produto desta página tem frete do Mercado Envios para cotar.';
    if (c.falhou) return `O Mercado Livre não respondeu a todas as cotações (${c.cotados} de ${c.total}). As outras seguem estimadas pela tabela; tente de novo.`;
    if (c.pendentes > 0) return `Cotamos ${c.cotados} de ${c.total} fretes. Clique de novo para cotar o resto.`;

    return `Fretes cotados na sua conta: ${c.cotados} de ${c.total}.`;
}

/** Repete a visita só quando sobrou pendência e nada deu errado, até o teto do clique. */
export const deveCotarDeNovo = (c, voltas) => !! c && ! c.limitado && !! c.conectado && ! c.falhou && c.pendentes > 0 && voltas < VOLTAS_DA_COTACAO;
