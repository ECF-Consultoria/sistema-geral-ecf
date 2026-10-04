// ─── Publicador: o que todas as partes da tela usam ─────────────────────────
//
// As rotas são todas por produto (`mlb.anuncios.publicador.*`) e TODA resposta
// traz o estado inteiro do rascunho — a tela nunca adivinha o que foi gravado.

/** Monta o gerador de rotas de um editor: `criarRota('mlb.anuncios.publicador', 'produto')('salvar', 7)`. */
export const criarRota = (prefixo, chave) => (nome, id, extra = {}) => route(`${prefixo}.${nome}`, { [chave]: id, ...extra });

export const mensagemDe = (e) => {
    if (e?.code === 'ECONNABORTED') return 'O servidor demorou demais. Recarregue a página e confira antes de tentar de novo.';
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat()[0];

    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};

export const NOME_TIPO = { gold_special: 'Clássico', gold_pro: 'Premium' };
export const NOTA_TIPO = { gold_special: 'Menor comissão', gold_pro: 'Parcelado sem juros' };

// As etapas da spec (`02` §1).
export const NOME_ETAPA = {
    E0: 'Conta do Mercado Livre', E2: 'Categoria', E3: 'Características principais', E4: 'Variações',
    E5: 'Dados das variações', E6: 'Fotos', E7: 'Título', E8: 'Ficha técnica', E9: 'Descrição',
    E10: 'Condições de venda', E11: 'Conferência', E13: 'Publicação', OUTROS: 'Outros avisos do Mercado Livre',
};

export const GERAL = 'GENERAL';

export const paraTexto = (n) => (n === null || n === undefined || n === '' ? '' : Number(n).toFixed(2).replace('.', ','));
export const paraNumero = (t) => {
    const s = String(t ?? '').trim().replace(/\s|R\$/g, '');
    if (s === '') return null;
    const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s);

    return Number.isFinite(n) ? n : null;
};

export const valorVazio = (v) => ! v || ((v.value_id ?? '') === '' && String(v.value_name ?? '').trim() === '' && (v.value_number ?? '') === '');

/** Problemas que apontam para um atributo (do produto ou de uma variante). */
export const problemasDoAtributo = (problemas, id, variante = null) => (problemas ?? []).filter((p) => p.alvo?.atributo === id && (variante === null || ! p.alvo?.variante || p.alvo.variante === variante));

// ─── As 8 verificações e os itens da árvore do anúncio ──────────────────────
//
// Fonte única: a mesa de anúncio e o hook `usePublicador` importam daqui.
// As VERIFICAÇÕES são do servidor (uma por grupo de regras); os ITENS são a
// árvore da coluna esquerda (Conceito E, 03/10/2026): o que a pessoa escolhe
// para editar no centro. Cada verificação se resolve em um ou mais itens.
export const SECOES = [
    { chave: 'categoria', titulo: 'Categoria', etapas: ['E2'] },
    { chave: 'caracteristicas', titulo: 'Características', etapas: ['E3', 'E8'] },
    { chave: 'variacoes', titulo: 'Variações', etapas: ['E4'] },
    { chave: 'fotos', titulo: 'Fotos', etapas: ['E6'] },
    { chave: 'variantes', titulo: 'Estoque, SKU e código', etapas: ['E5'] },
    { chave: 'tipos', titulo: 'Título e preço', etapas: ['E7'] },
    { chave: 'envio', titulo: 'Envio, garantia e embalagem', etapas: ['E10'] },
    { chave: 'descricao', titulo: 'Descrição', etapas: ['E9'] },
];

/**
 * Os itens de primeiro nível da árvore, na ordem. `secoes` = as verificações que o item
 * fecha; `filtro` separa a verificação `tipos` entre títulos (E7/tipo) e preços (campo preco).
 * Os problemas de FOTO e de VARIANTE que apontam para uma variação (`alvo.variante`/`alvo.grupo`)
 * aparecem no subitem da variação (ver `problemasDaVariante`), não no item pai.
 */
export const ITENS = [
    { chave: 'produto', titulo: 'Produto e categoria', curto: 'Produto', secoes: ['categoria'] },
    { chave: 'ficha', titulo: 'Ficha técnica', curto: 'Ficha', secoes: ['caracteristicas'] },
    // Sem `fotos`: a foto de uma variação chega ao subitem dela por `problemasDaVariante`; o que
    // sobra da verificação de fotos (galeria geral, limite do anúncio) é do item "Fotos".
    { chave: 'variacoes', titulo: 'Variações', curto: 'Variações', secoes: ['variacoes', 'variantes'] },
    { chave: 'fotos', titulo: 'Fotos', curto: 'Fotos', secoes: ['fotos'] },
    { chave: 'titulos', titulo: 'Títulos (Clássico, Premium)', curto: 'Títulos', secoes: ['tipos'], filtro: (p) => p.alvo?.campo !== 'preco' },
    { chave: 'precos', titulo: 'Preços e taxas', curto: 'Preços', secoes: ['tipos'], filtro: (p) => p.alvo?.campo === 'preco' },
    { chave: 'logistica', titulo: 'Envio e garantia', curto: 'Envio', secoes: ['envio'] },
    { chave: 'descricao', titulo: 'Descrição', curto: 'Descrição', secoes: ['descricao'] },
];

export const ITEM_INICIAL = ITENS[0].chave;

/** Separa `ficha/obrigatorios`, `variacoes/COLOR=id:1` em `{ raiz, sub }` (o sub pode ter qualquer caractere). */
export const partesDoItem = (chave) => {
    const s = String(chave ?? '');
    const i = s.indexOf('/');

    return i === -1 ? { raiz: s, sub: null } : { raiz: s.slice(0, i), sub: s.slice(i + 1) };
};

export const itemDaVariante = (chaveDaVariante) => `variacoes/${chaveDaVariante}`;
export const ITEM_NOVA_VARIACAO = 'variacoes/nova';
export const SUBITENS_FICHA = ['obrigatorios', 'outras'];

/** Em que seção o problema se resolve; nulo = é da conta/conferência (vai para o inspetor). */
export const secaoDoProblema = (p) => {
    const e = p.alvo?.etapa;
    if (e === 'E10' && ['preco', 'tipo'].includes(p.alvo?.campo)) return 'tipos';

    return SECOES.find((s) => s.etapas.includes(e))?.chave ?? null;
};

/**
 * Em que item da árvore o problema se resolve. Problema que aponta para uma variação
 * (`alvo.variante`, ou `alvo.grupo` de fotos) vai para o subitem dela; `grupoDe(chave)` diz o
 * grupo de fotos de cada variação (vem de `fotosDaVariante`). Nulo = conta/conferência.
 */
export const itemDoProblema = (p, { variantes = [], grupoDe = () => null } = {}) => {
    const alvo = p.alvo ?? {};
    if (alvo.variante && variantes.some((v) => v.chave === alvo.variante)) return itemDaVariante(alvo.variante);
    if (alvo.grupo && alvo.grupo !== GERAL) {
        const dona = variantes.find((v) => grupoDe(v) === alvo.grupo);
        if (dona) return itemDaVariante(dona.chave);
    }
    const secao = secaoDoProblema(p);
    if (secao === 'tipos') return alvo.campo === 'preco' ? 'precos' : 'titulos';

    return ITENS.find((i) => i.secoes.includes(secao) && (! i.filtro || i.filtro(p)))?.chave ?? null;
};

/** O item que fecha a etapa `E…` da spec (para o "ir para" das pendências da conferência). */
export const itemDaEtapaMl = (etapaMl) => {
    const secao = SECOES.find((s) => s.etapas.includes(etapaMl))?.chave ?? null;
    if (secao === 'tipos') return 'titulos';

    return ITENS.find((i) => i.secoes.includes(secao))?.chave ?? null;
};

/** Os problemas que apontam para UMA variação: `alvo.variante` igual, ou `alvo.grupo` igual ao grupo de fotos dela. */
export const problemasDaVariante = (problemas, v, grupo) => (problemas ?? []).filter((p) => p.alvo?.variante === v.chave || (grupo && grupo !== GERAL && p.alvo?.grupo === grupo));

/**
 * Os problemas de um item de primeiro nível. `variacoes` fica só com o que NÃO aponta para
 * uma variação (os dela estão no subitem); `fotos` fica com os da galeria geral.
 */
export const problemasDoItem = (chave, problemas) => {
    const item = ITENS.find((i) => i.chave === chave);
    if (! item) return [];
    const lista = (problemas ?? []).filter((p) => item.secoes.includes(secaoDoProblema(p)) && (! item.filtro || item.filtro(p)));
    if (chave === 'variacoes') return lista.filter((p) => ! p.alvo?.variante && ! (p.alvo?.grupo && p.alvo.grupo !== GERAL));
    if (chave === 'fotos') return lista.filter((p) => ! p.alvo?.variante && (! p.alvo?.grupo || p.alvo.grupo === GERAL));

    return lista;
};

/**
 * Estado de cada seção para o chip do card: `{ [chave]: { faltam, completo } }`.
 * Sem schema, toda seção além da categoria conta como incompleta (falta 1).
 */
export const estadoDasSecoes = (problemas, schema) => Object.fromEntries(SECOES.map((s) => {
    if (! schema && s.chave !== 'categoria') return [s.chave, { faltam: 1, completo: false }];
    const faltam = (problemas ?? []).filter((p) => p.severidade === 'BLOCKER' && secaoDoProblema(p) === s.chave).length;

    return [s.chave, { faltam, completo: faltam === 0 }];
}));

/** Quantos bloqueios há numa lista de problemas. */
export const contarBloqueios = (problemas) => (problemas ?? []).filter((p) => p.severidade === 'BLOCKER').length;

/**
 * Estado de cada item de primeiro nível: `{ [chave]: { faltam, completo } }`. Sem schema, tudo
 * menos o produto conta como incompleto. Em `variacoes` entram também os bloqueios das variações.
 */
export const estadoDosItens = (problemas, schema, { variantes = [], grupoDe = () => null } = {}) => Object.fromEntries(ITENS.map((i) => {
    if (! schema && i.chave !== 'produto') return [i.chave, { faltam: 1, completo: false }];
    let faltam = contarBloqueios(problemasDoItem(i.chave, problemas));
    if (i.chave === 'variacoes') {
        faltam += variantes.filter((v) => ! v.orfa && v.ativa).reduce((n, v) => n + contarBloqueios(problemasDaVariante(problemas, v, grupoDe(v))), 0);
    }

    return [i.chave, { faltam, completo: faltam === 0 }];
}));

export const contarItensProntos = (estados) => ITENS.filter((i) => estados[i.chave]?.completo).length;

/**
 * A chave de um item vinda de fora (URL, link antigo). Subitem de ficha só os dois conhecidos;
 * subitem de variação só se a variação existir (quando a lista é dada); senão cai no pai. Nulo = inválido.
 */
export const itemValido = (chave, { variantes = null } = {}) => {
    const { raiz, sub } = partesDoItem(chave);
    if (! ITENS.some((i) => i.chave === raiz)) return null;
    if (sub === null) return raiz;
    if (raiz === 'ficha') return SUBITENS_FICHA.includes(sub) ? chave : raiz;
    if (raiz === 'variacoes') {
        if (sub === 'nova') return chave;
        if (variantes === null) return chave;

        return variantes.some((v) => v.chave === sub && ! v.orfa) ? chave : raiz;
    }

    return raiz;
};

/**
 * A ordem de leitura do anúncio para o "Próximo item": itens de primeiro nível, com as
 * variações (ativas, não órfãs) logo depois de "Variações". Subitens da ficha e "nova" ficam fora.
 */
export const sequenciaDeItens = (variantes = []) => ITENS.flatMap((i) => (
    i.chave === 'variacoes'
        ? ['variacoes', ...variantes.filter((v) => ! v.orfa).map((v) => itemDaVariante(v.chave))]
        : [i.chave]
));

/** O item seguinte na sequência (nulo no fim); subitem da ficha conta como a ficha. */
export const proximoItem = (chave, variantes = []) => {
    const seq = sequenciaDeItens(variantes);
    const { raiz, sub } = partesDoItem(chave);
    const atual = seq.indexOf(seq.includes(chave) ? chave : raiz);
    if (sub === 'nova') return seq[seq.indexOf('variacoes') + 1] ?? null;

    return seq[atual + 1] ?? null;
};

/**
 * O primeiro item com bloqueio, na ordem de leitura (o que abre sozinho ao entrar);
 * nulo quando está tudo completo. Sem schema é o produto (a categoria vem antes de tudo).
 */
export const primeiroItemPendente = (problemas, schema, { variantes = [], grupoDe = () => null } = {}) => {
    if (! schema) return 'produto';
    for (const chave of sequenciaDeItens(variantes)) {
        const { raiz, sub } = partesDoItem(chave);
        if (raiz === 'variacoes' && sub) {
            const v = variantes.find((x) => x.chave === sub);
            if (v?.ativa && contarBloqueios(problemasDaVariante(problemas, v, grupoDe(v))) > 0) return chave;
        } else if (contarBloqueios(problemasDoItem(chave, problemas)) > 0) {
            return chave;
        }
    }

    return null;
};
