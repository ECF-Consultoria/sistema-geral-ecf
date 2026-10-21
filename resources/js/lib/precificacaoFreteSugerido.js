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

// Fora do Mercado Envios (21/10/2026): o ML não cobra frete, mas o vendedor paga um (transportadora ou entrega
// combinada). O sugerido é o que o ML calcula para a caixa — a referência, nunca "o ML cobra".
export const REFERENCIA_FORA_DO_ME2 = 'referência do ML para a caixa';

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
            if (! s) return null;
            return s.fora_do_me2
                ? { texto: REFERENCIA_FORA_DO_ME2, tipo: 'sugerido', fonte: s.fonte, fora: true }
                : { texto: origem, tipo: 'sugerido', fonte: s.fonte };
        case 'outro_tipo':
            return { texto: `mesmo do ${outroTipo}`, tipo: 'herdado' };
        // Digitado diferente do Mercado Livre (21/10/2026, "digitado R$ 200, o ML cobra R$ 262,85"): o valor
        // digitado nunca é trocado; a legenda diz o do ML e acende (`alerta`) quando ele cobra MAIS do que entrou
        // no preço — aí a margem é menor que a planejada. Só existe sugestão dentro do Mercado Envios.
        case 'digitado':
            // Fora do Mercado Envios a referência só aparece ao lado, sem acender: o ML não cobra esse valor.
            if (s?.fora_do_me2) {
                return Number(s.valor) !== Number(calculo.frete)
                    ? { texto: `referência do ML: ${fmt(s.valor)}`, tipo: 'sugestao', fonte: s.fonte, fora: true, alerta: false }
                    : null;
            }
            return s && Number(s.valor) !== Number(calculo.frete)
                ? {
                    texto: s.fonte === 'conta' ? `o ML cobra ${fmt(s.valor)}` : `${origem}: ${fmt(s.valor)}`,
                    tipo: 'sugestao', fonte: s.fonte, alerta: Number(s.valor) > Number(calculo.frete),
                }
                : null;
        default:
            return null;
    }
}

/** O campo em branco mostra, apagado, o frete que a conta usa (o sugerido ou o do outro tipo). */
export const freteEmBranco = (calculo) => calculo?.frete_origem === 'sugerido' || calculo?.frete_origem === 'outro_tipo';

// ─── Um campo de frete só na linha (13/10/2026, "linhas enxutas") ───
//
// A linha mostra UM campo quando o frete do Clássico e o do Premium valem o mesmo; os dois campos, cada um com a
// origem dele, ficam no detalhe. Digitar no campo único grava nos dois tipos. Nada aqui calcula frete: só decide
// o que a linha desenha a partir do que foi digitado e do que o servidor mandou.

/** "200", "200,00" e " 200,0 " são o mesmo frete; vazio é vazio. */
const mesmoTexto = (texto) => {
    const t = String(texto ?? '').trim().replace(/\s|R\$/g, '');
    if (t === '') return '';
    const n = Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t);

    return Number.isFinite(n) ? String(n) : t;
};

/**
 * Os dois tipos cabem num campo só? Sim quando o digitado é igual nos dois (inclusive os dois em branco) ou quando
 * um está em branco HERDANDO o do outro ("mesmo do Clássico" — o caso de quem digitou só um).
 *
 * @param {string} freteC  o que está no campo do Clássico
 * @param {string} freteP  o que está no campo do Premium
 * @param {object} calculo  `por_oferta[id]` do servidor
 * @returns {{unico: boolean, valor: string}}  `valor` = o que o campo único mostra
 */
export function freteUnico(freteC, freteP, calculo) {
    const c = mesmoTexto(freteC);
    const p = mesmoTexto(freteP);
    if (p === '' && calculo?.premium?.frete_origem === 'outro_tipo') return { unico: true, valor: freteC ?? '' };
    if (c === '' && calculo?.classico?.frete_origem === 'outro_tipo') return { unico: true, valor: freteP ?? '' };

    return { unico: c === p, valor: freteC ?? '' };
}

/**
 * O texto pequeno sob o campo único: a origem do frete de um dos tipos (a que não é "mesmo do outro") ou, sem
 * sugestão nenhuma para comparar, "digitado". Null = nada a dizer (quem fala é o aviso de "sem tabela").
 *
 * @returns {{texto: string, tipo: string, fonte?: string}|null}
 */
export function legendaDoFreteUnico(calculo, fmt) {
    const proprio = [rotuloDoFrete(calculo?.classico, 'Premium', fmt), rotuloDoFrete(calculo?.premium, 'Clássico', fmt)]
        .find((r) => r && r.tipo !== 'herdado');
    if (proprio) return proprio;

    const digitado = calculo?.classico?.frete_origem === 'digitado' || calculo?.premium?.frete_origem === 'digitado';

    return digitado ? { texto: 'digitado', tipo: 'digitado' } : null;
}

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
