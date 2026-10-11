// ─── Precificação agrupada por família (11/10/2026) ─────────────────────────
//
// O usuário: a Precificação era "uma lista inteira sem saber o que é". Queria o
// produto unitário com as variações dele embaixo, e levantou o nó do kit: "vou
// pegar um produto de um e de outro", então o kit teria dois produtos-pai.
//
// O desenho aprovado:
// · grupos por FAMÍLIA (a do cadastro de Produtos), que abrem e fecham;
// · cada produto aparece UMA vez, com os combos dele logo abaixo;
// · kit e combit ficam em "Conjuntos desta família", também uma vez só, e cada
//   produto que entra neles diz "Também entra em";
// · o que não tem família fica no grupo "Sem família", por último.
//
// O servidor já manda os blocos na ordem (família, produtos antes de conjuntos)
// e com `familia` em cada um; aqui só se monta o que a tela desenha. Nenhuma
// conta de preço passa por este arquivo.

export const SEM_FAMILIA = 'sem-familia';
export const TIPOS = [['simples', 'Simples'], ['combo', 'Combo'], ['kit', 'Kit'], ['combit', 'Combit']];

const CONJUNTO = ['kit', 'combit'];
export const ehConjunto = (fase) => CONJUNTO.includes(fase);

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;
const lista = (v) => (Array.isArray(v) ? v : []);

/**
 * Os blocos da página viram famílias: `[{ chave, nome, semFamilia, produtos, conjuntos }]`.
 * `produtos` = `[{ chave, principal, combos, tambemEm }]` (`principal` nulo = o filtro tirou o produto e
 * deixou só combos dele). `temCalculo(id)` deixa de fora a oferta que veio sem preço calculado.
 */
export function montarFamilias(blocos, temCalculo = () => true) {
    const grupos = new Map();

    for (const b of lista(blocos)) {
        const ofertas = lista(b?.ofertas).filter((o) => o && temCalculo(o.id));
        if (ofertas.length === 0) continue;

        const f = b.familia && typeof b.familia === 'object' && b.familia.id != null ? b.familia : null;
        const chave = f ? `f-${f.id}` : SEM_FAMILIA;
        if (! grupos.has(chave)) {
            grupos.set(chave, { chave, nome: f ? String(f.nome ?? '') : 'Sem família', semFamilia: ! f, produtos: [], conjuntos: [] });
        }
        const grupo = grupos.get(chave);

        const principal = ofertas.find((o) => o.id === b.chave) ?? null;
        if (principal && ehConjunto(principal.fase)) {
            grupo.conjuntos.push(principal);
            continue;
        }

        grupo.produtos.push({
            chave: b.chave,
            principal,
            combos: ofertas.filter((o) => o.id !== b.chave),
            tambemEm: principal ? lista(b.tambem_em) : [],
        });
    }

    // "Sem família" sempre por último, mesmo que a página comece por ela.
    return [...grupos.values()].sort((a, b) => Number(a.semFamilia) - Number(b.semFamilia));
}

/** Todas as ofertas de uma família, na ordem da tela. */
export const ofertasDaFamilia = (familia) => [
    ...familia.produtos.flatMap((p) => [...(p.principal ? [p.principal] : []), ...p.combos]),
    ...familia.conjuntos,
];

/** "2 produtos · 1 combo · 1 conjunto" — só do que há. */
export function resumoDaFamilia(familia) {
    const produtos = familia.produtos.filter((p) => p.principal).length;
    const combos = familia.produtos.reduce((t, p) => t + p.combos.length, 0);
    const partes = [];
    if (produtos > 0) partes.push(plural(produtos, 'produto', 'produtos'));
    if (combos > 0) partes.push(plural(combos, 'combo', 'combos'));
    if (familia.conjuntos.length > 0) partes.push(plural(familia.conjuntos.length, 'conjunto', 'conjuntos'));

    return partes.join(' · ');
}

/** Quantas ofertas da família ainda não têm preço fechado (sem custo, sem frete ou conta impossível). */
export const pendentesDaFamilia = (familia, porOferta) => ofertasDaFamilia(familia)
    .filter((o) => porOferta?.[o.id]?.pendencia).length;

/**
 * O que a tabela desenha dentro de uma família, linha a linha:
 * `{ tipo: 'oferta', oferta, filho, alternar, aberto, tambemEm, itens }` ou `{ tipo: 'titulo' }`.
 *
 * `recolhidos[chave do produto]` esconde os combos dele. Com `filtrando` (busca ou tipo escolhido) a lista
 * fica rasa: sem recuo, sem abrir/fechar e sem "também entra em" — o que casou aparece direto.
 */
export function linhasDaFamilia(familia, { recolhidos = {}, filtrando = false } = {}) {
    const linhas = [];
    const oferta = (o, mais = {}) => ({ tipo: 'oferta', oferta: o, filho: false, alternar: null, aberto: false, tambemEm: [], itens: [], ...mais });

    for (const p of familia.produtos) {
        if (filtrando || ! p.principal) {
            if (p.principal) linhas.push(oferta(p.principal));
            p.combos.forEach((c) => linhas.push(oferta(c)));
            continue;
        }

        const temCombos = p.combos.length > 0;
        const aberto = temCombos && ! recolhidos[p.chave];
        linhas.push(oferta(p.principal, { alternar: temCombos ? p.chave : null, aberto, tambemEm: p.tambemEm }));
        if (aberto) p.combos.forEach((c) => linhas.push(oferta(c, { filho: true })));
    }

    if (familia.conjuntos.length > 0) {
        linhas.push({ tipo: 'titulo' });
        familia.conjuntos.forEach((k) => linhas.push(oferta(k, { itens: lista(k.componentes) })));
    }

    return linhas;
}

/** Onde está cada oferta da página: `{ [id]: { familia, produto } }` — para o atalho abrir o que estiver fechado. */
export function ondeEstaCadaOferta(familias) {
    const onde = {};
    for (const f of familias) {
        for (const p of f.produtos) {
            if (p.principal) onde[p.principal.id] = { familia: f.chave, produto: null };
            p.combos.forEach((c) => { onde[c.id] = { familia: f.chave, produto: p.chave }; });
        }
        f.conjuntos.forEach((k) => { onde[k.id] = { familia: f.chave, produto: null }; });
    }

    return onde;
}

/** Os filtros de tipo, com a contagem da empresa inteira (o painel), não a da página. */
export function filtrosDeTipo(painel, atual) {
    const porFase = painel?.por_fase ?? {};

    return [
        { chave: null, rotulo: 'Todos', quantos: Number(painel?.ofertas ?? 0), ativo: ! atual },
        ...TIPOS.map(([chave, rotulo]) => ({ chave, rotulo, quantos: Number(porFase[chave] ?? 0), ativo: atual === chave })),
    ];
}
