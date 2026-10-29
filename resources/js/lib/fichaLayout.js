// ─── A ficha do produto organizada em seções (29/10/2026) ───────────────────
//
// O usuário achou a ficha "chata de preencher, confusa, fora de organização": uma
// coluna de 5.361 px com 104 campos, o Salvar só no fim e as cores repetindo custo e
// caixa. A ficha nova tem um índice com o estado de cada seção, a barra de salvar
// presa embaixo, o custo e a caixa digitados UMA vez para todas as cores e a ficha
// técnica com as obrigatórias primeiro e o que não se aplica recolhido.
//
// Aqui moram só as contas, sem React, para o teste conferir de verdade. O servidor e
// o POST `linhas` não mudam: cada variação continua guardando o seu custo e as suas
// caixas. "Igual em todas" é uma leitura da tela sobre esses valores.

import { aceitaNaoSeAplica } from './fichaTecnica.js';

// ─── Valor comum entre as variações ─────────────────────────────────────────

const numero = (x) => {
    const t = String(x ?? '').trim().replace(/\s/g, '');
    if (t === '') return '';
    // "1.234,50" e "1234.5" valem o mesmo; vírgula é decimal no portal.
    const n = Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t);

    return Number.isFinite(n) ? String(Math.round(n * 1000) / 1000) : t;
};

/** O custo como chave de comparação: "89", "89,00" e 89 são o mesmo custo. */
export const assinaturaDoCusto = (v) => numero(v?.custo);

/** As caixas como chave de comparação: só as preenchidas, cada medida como número. */
export function assinaturaDasCaixas(caixas) {
    const preenchidas = (Array.isArray(caixas) ? caixas : [])
        .map((c) => ({ c: numero(c?.c), l: numero(c?.l), a: numero(c?.a), kg: numero(c?.kg) }))
        .filter((c) => c.c !== '' || c.l !== '' || c.a !== '' || c.kg !== '');

    return JSON.stringify(preenchidas);
}

/**
 * O valor que vale "para todas": o mais frequente entre as variações (empate fica com o
 * que aparece primeiro). `excluidas` são as que a pessoa marcou como "própria" mesmo com
 * o valor igual — não contam nem herdam. Devolve a assinatura e as chaves (`_k`) que a seguem.
 */
export function grupoComum(itens, assinaturaDe, excluidas = new Set()) {
    const contagem = new Map();
    const primeiro = new Map();
    const elegiveis = (Array.isArray(itens) ? itens : []).filter((it) => ! excluidas.has(it._k));
    elegiveis.forEach((it, i) => {
        const a = assinaturaDe(it);
        contagem.set(a, (contagem.get(a) ?? 0) + 1);
        if (! primeiro.has(a)) primeiro.set(a, i);
    });
    let melhor = null;
    contagem.forEach((n, a) => {
        if (melhor === null) { melhor = a; return; }
        const nm = contagem.get(melhor);
        if (n > nm || (n === nm && primeiro.get(a) < primeiro.get(melhor))) melhor = a;
    });

    return { assinatura: melhor, chaves: elegiveis.filter((it) => assinaturaDe(it) === melhor).map((it) => it._k) };
}

// ─── Ficha técnica: obrigatórias, outras e as que não se aplicam ────────────

/** O campo tem algo preenchido (texto, número, opções escolhidas)? "Não se aplica" não conta como valor. */
export function valorPreenchido(atual) {
    if (! atual) return false;
    if (Array.isArray(atual.valor)) return atual.valor.length > 0;

    return String(atual.valor ?? '').trim() !== '';
}

/** O campo está marcado como "não se aplica" (e o servidor deixa marcar)? */
export const naoSeAplica = (campo, atual) => aceitaNaoSeAplica(campo) && !! atual?.naoSeAplica;

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * Os campos dos grupos do servidor em dois blocos: obrigatórias e outras, SEMPRE na ordem da
 * categoria. Nada muda de lugar enquanto se preenche (29/10/2026, o usuário: "preenche e muda de
 * lugar, fica desorganizado") — nem o campo marcado "não se aplica", que fica onde está.
 *
 * `busca` filtra pelo nome. `filtro` é 'todas' | 'faltando' | 'preenchidas' ("não se aplica" conta
 * como resolvido). `so` (Set de ids) é a foto tirada quando a pessoa escolheu o filtro: com ela, o
 * que se preenche agora não some da lista — só um novo clique no filtro atualiza. `ids` devolve os
 * campos que passam, para quem quiser tirar essa foto.
 */
export function camposDaFicha(grupos, valores = {}, { busca = '', filtro = 'todas', so = null } = {}) {
    const todos = (Array.isArray(grupos) ? grupos : []).flatMap((g) => (Array.isArray(g?.campos) ? g.campos : []));
    const termo = semAcento(busca.trim());
    const acha = (c) => ! termo || semAcento(c.nome).includes(termo);
    const cheio = (c) => valorPreenchido(valores[c.id]);
    const na = (c) => ! c.obrigatorio && naoSeAplica(c, valores[c.id]);
    const resolvido = (c) => cheio(c) || na(c);
    const noFiltro = (c) => (so ? so.has(c.id) : (filtro === 'todas' || (filtro === 'faltando' ? ! resolvido(c) : resolvido(c))));
    const passa = (c) => acha(c) && noFiltro(c);

    const obrigatorias = todos.filter((c) => c.obrigatorio && passa(c));
    const outras = todos.filter((c) => ! c.obrigatorio && passa(c));

    return {
        obrigatorias,
        outras,
        ids: [...obrigatorias, ...outras].map((c) => c.id),
        contagem: {
            todas: todos.length,
            faltando: todos.filter((c) => ! resolvido(c)).length,
            preenchidas: todos.filter(resolvido).length,
            obrigatoriasFaltando: todos.filter((c) => c.obrigatorio && ! cheio(c)).length,
            obrigatorias: todos.filter((c) => c.obrigatorio).length,
            outrasPreenchidas: todos.filter((c) => ! c.obrigatorio && cheio(c)).length,
            naoSeAplicam: todos.filter(na).length,
        },
    };
}

// ─── Medidas: a mesma ordem no produto e na caixa ───────────────────────────
//
// O Gemini apontou na revisão de 29/10: o produto pedia Largura · Altura · Profundidade e a
// caixa Comprimento · Largura · Altura — ordem trocada induz erro. As duas seguem agora
// comprimento/profundidade · largura · altura · peso, que é a ordem da caixa.

const ORDEM_DAS_MEDIDAS = ['LENGTH', 'DEPTH', 'WIDTH', 'HEIGHT', 'DIAMETER', 'WEIGHT'];

export function ordenarMedidas(campos) {
    const peso = (c) => {
        const i = ORDEM_DAS_MEDIDAS.indexOf(String(c?.id ?? '').toUpperCase());

        return i === -1 ? ORDEM_DAS_MEDIDAS.indexOf('WEIGHT') - 0.5 : i;   // desconhecidas logo antes do peso
    };

    return (Array.isArray(campos) ? campos : []).map((c, i) => [c, i]).sort((a, b) => peso(a[0]) - peso(b[0]) || a[1] - b[1]).map(([c]) => c);
}

// ─── Índice da ficha ────────────────────────────────────────────────────────

/** O nome da seção das variações pelo eixo: "Cores e fotos" quando varia por cor. */
export function nomeDasVariacoes(eixo) {
    const e = semAcento(eixo);
    if (e === 'cor') return 'Cores e fotos';
    if (e === 'tamanho') return 'Tamanhos e fotos';

    return 'Variações e fotos';
}

/**
 * O estado de cada seção para o índice: tipo 'ok' | 'aviso' | 'erro' | 'neutro' e uma linha curta.
 * Só leitura do que a ficha já sabe — nenhuma regra nova de gravação.
 */
export function estadoDasSecoes(f) {
    const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;
    const s = {};

    s['secao-produto'] = ! String(f.nome ?? '').trim()
        ? ['erro', 'falta o nome']
        : (! f.temCategoria ? ['aviso', 'falta a categoria'] : ['ok', [f.familia, plural(f.ambientes ?? 0, 'ambiente', 'ambientes')].filter(Boolean).join(' · ')]);

    const tam = f.tituloTamanho ?? 0;
    s['secao-titulo'] = tam === 0
        ? ['aviso', 'vazio · ajuda a ser achado']
        : (tam > f.tituloLimite ? ['erro', `${tam}/${f.tituloLimite}: passou do limite`] : ['ok', `${tam}/${f.tituloLimite} caracteres`]);

    // `variacoesTexto` ("2 cores", "3 tamanhos") vem de quem chama, com o plural de `contagemDoEixo`.
    s['secao-cores'] = f.variacoesComErro > 0
        ? ['erro', plural(f.variacoesComErro, 'com erro', 'com erro')]
        : (f.semReferencia > 0
            ? ['erro', `${f.semReferencia} sem referência`]
            : ['ok', `${f.variacoesTexto} · ${f.estoque} un.`]);

    s['secao-medidas'] = f.semCaixa
        ? ['aviso', 'falta a caixa de envio']
        : (f.medidasFaltando > 0 ? ['aviso', `${plural(f.medidasFaltando, 'medida faltando', 'medidas faltando')}`] : ['ok', f.caixaTexto || 'completas']);

    if (! f.temCategoria) s['secao-ficha-tecnica'] = ['neutro', 'escolha a categoria'];
    else if (f.tecnicaCarregando) s['secao-ficha-tecnica'] = ['neutro', 'carregando…'];
    else if (f.tecnicaComErro) s['secao-ficha-tecnica'] = ['erro', 'campos para ajustar'];
    else if (f.obrigatoriasFaltando > 0) s['secao-ficha-tecnica'] = ['aviso', `${plural(f.obrigatoriasFaltando, 'obrigatória faltando', 'obrigatórias faltando')}`];
    else s['secao-ficha-tecnica'] = ['ok', `${f.obrigatorias}/${f.obrigatorias} obrigatórias · ${f.outrasPreenchidas} extras`];

    s['secao-descricao'] = (f.descricaoTamanho ?? 0) > 0 ? ['ok', `${f.descricaoTamanho} caracteres`] : ['aviso', 'vazia'];
    s['secao-preco'] = f.premiumPendente ? ['erro', 'Premium igual ao Clássico'] : ['neutro', 'frete e percentuais'];

    return s;
}
