// Fila de revisão dos Polos (TKT-0027, TKT-0033) — ordens e filtros da lista, aplicados no
// cliente sobre a carteira já carregada. Módulo puro (sem React) para o teste em node.

// [rótulo, valor da empresa, direção]. Sem valor (ex.: sem bônus) vai sempre para o fim.
export const ORDENS = {
    prioridade:        ['Prioridade da fila', null, 0],
    bonus_mais:        ['Bônus: mais usado primeiro', (e) => e.bonus?.pct_usado, -1],
    bonus_menos:       ['Bônus: menos usado primeiro', (e) => e.bonus?.pct_usado, 1],
    // Consumo de publicidade (TKT-0033) = gasto de ADS do mês, o mesmo da coluna ADS do
    // Faturamento Polos (com e sem bônus).
    ads_mais:          ['Publicidade: maior consumo primeiro', (e) => e.investimento, -1],
    ads_menos:         ['Publicidade: menor consumo primeiro', (e) => e.investimento, 1],
    meta_menor:        ['Menor % da meta', (e) => e.pct, 1],
    faturamento_maior: ['Maior faturamento', (e) => e.faturamento, -1],
};

export const FILTRO_BONUS = {
    todos:  ['Bônus: todos', () => true],
    com:    ['Com bônus', (e) => !!e.bonus],
    parado: ['Bônus parado (usou < 20%)', (e) => e.bonus?.ritmo?.tipo === 'parado'],
    sem:    ['Sem bônus', (e) => !e.bonus],
};

const temSinal = (e, chave) => (e.sinais ?? []).some((s) => s.chave === chave);
export const FILTRO_ATENCAO = {
    todos:              ['Atenção: todas', () => true],
    atrasada:           ['Atrasadas', (e) => e.situacao === 'atrasada'],
    desatualizado:      ['Comentário desatualizado', (e) => e.desatualizado],
    sem_comentario_mes: ['Sem comentário no mês', (e) => !e.ultimo_comentario?.do_mes],
    campanha_desligada: ['Campanha desligada', (e) => temSinal(e, 'campanha_desligada')],
    places:             ['Places pendente', (e) => temSinal(e, 'places')],
    // "Ads sem acordo" fica só como chip no cartão, sem opção de filtro (pedido de 27/10).
};

// Situação da campanha (TKT-0033): as mesmas opções do filtro "Campanhas" do Faturamento
// Polos (TKT-0015). `e.campanha` vem pronta do servidor; null = não verificado.
export const campanhaDa = (e) => e.campanha ?? 'nao_verificado';
export const FILTRO_CAMPANHA = {
    todas:          ['Campanha: todas', () => true],
    ativa:          ['Campanha ativa', (e) => campanhaDa(e) === 'ativa'],
    desligada:      ['Campanha desligada', (e) => campanhaDa(e) === 'desligada'],
    sem_campanha:   ['Sem campanha', (e) => campanhaDa(e) === 'sem_campanha'],
    nao_verificado: ['Campanha não verificada', (e) => campanhaDa(e) === 'nao_verificado'],
};

export const FILTROS_PADRAO = { busca: '', ordem: 'prioridade', bonus: 'todos', atencao: 'todos', campanha: 'todas', fase: 'todas', polo: 'todos' };

const semAcento = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export function aplicarFiltros(lista, f) {
    const q = semAcento(f.busca.trim());
    const filtrada = lista.filter((e) => (
        (!q || semAcento(`${e.nome} ${e.cust_id ?? ''} ${e.polo ?? ''}`).includes(q))
        && (f.fase === 'todas' || e.fase === f.fase)
        && (f.polo === 'todos' || (e.polo ?? '') === f.polo)
        && FILTRO_BONUS[f.bonus][1](e)
        && FILTRO_ATENCAO[f.atencao][1](e)
        && FILTRO_CAMPANHA[f.campanha][1](e)
    ));

    const [, valor, dir] = ORDENS[f.ordem];
    if (!valor) return filtrada; // prioridade: a ordem que o servidor montou

    return [...filtrada].sort((a, b) => {
        const va = valor(a);
        const vb = valor(b);
        const semA = va === null || va === undefined;
        const semB = vb === null || vb === undefined;
        if (semA !== semB) return semA ? 1 : -1;
        if (!semA && va !== vb) return (va - vb) * dir;
        return a.nome.localeCompare(b.nome);
    });
}
