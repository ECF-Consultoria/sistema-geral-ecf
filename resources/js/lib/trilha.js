// ─── Trilha de produtos — o que as telas da trilha compartilham ─────────────
//
// As 6 etapas (textos do protótipo do Erlon, ajustados ao que o sistema faz
// hoje), a cor de cada uma e os ajudantes PUROS que agrupam os itens.
//
// Nada aqui decide em que etapa um item está: isso vem pronto do servidor
// (`App\Services\Trilha\TrilhaDaEmpresa`). O front só agrupa, conta e desenha.
//
// ⚠️ Sem classe do Tailwind neste arquivo: o `content` do tailwind.config.js só
// varre `.jsx`, então uma classe escrita num `.js` não seria gerada. As cores
// daqui são `rgb(var(--t-…))`, para `style` e para o SVG do Mapa da loja.

/**
 * As etapas, na ordem da trilha. `unidade` é o que a etapa conta: as duas
 * primeiras contam produtos; as outras, ofertas.
 */
export const ETAPAS = [
    {
        chave: 'lista',
        titulo: 'Lista de produtos',
        dono: 'Cliente',
        unidade: 'produtos',
        // Cita TUDO o que segura o produto na Lista (as pendências do Portal mais descrição, fotos e ficha
        // técnica, `App\Services\Trilha\CompletudeDoProduto`): o cliente segue esta regra. O teste de render lê a
        // régua das fontes PHP e falha se uma falta possível não estiver aqui.
        pronto: 'SKU, nome, família, ambiente, categoria, título de venda, descrição, custo, embalagem com medidas e peso (sem pendência de frete ME1), 3 fotos em cada cor e os campos obrigatórios da ficha técnica preenchidos.',
        faz: 'O cliente cadastra cada produto pela tela ou pela planilha. Ao escolher a categoria, a ficha técnica dela aparece para preencher.',
    },
    {
        chave: 'mapa',
        titulo: 'Mapeamento estrutural',
        dono: 'Estrategista',
        unidade: 'produtos',
        pronto: 'Cada produto com as ofertas definidas: a simples e os combos, kits e combits que fazem sentido.',
        faz: 'Todo produto vira oferta simples. Aqui se decide o que combina: combo (o mesmo produto em mais unidades), kit (com outros produtos) e combit (com outros, algum em mais unidades). Por enquanto a decisão é feita no Planejamento: o produto sai daqui quando não sobra sugestão esperando resposta.',
    },
    {
        chave: 'plano',
        titulo: 'Planejamento',
        dono: 'Estrategista',
        unidade: 'ofertas',
        pronto: 'Cada oferta numa onda com data de publicação.',
        faz: 'As ofertas entram em ondas com prazo. Normalmente as simples vêm primeiro. As ondas chegam na próxima entrega.',
    },
    {
        chave: 'robo',
        titulo: 'Preparação automática',
        dono: 'Robô',
        unidade: 'ofertas',
        pronto: 'Título, descrição e imagens prontos: o anúncio fica pré-pronto.',
        faz: 'O agente monta o anúncio com a lista do cliente: título, descrição e imagens a partir das fotos.',
    },
    {
        chave: 'revisao',
        titulo: 'Revisão e publicação',
        dono: 'Publicador',
        unidade: 'ofertas',
        pronto: 'Anúncio publicado em Clássico e Premium.',
        faz: 'A equipe confere a prévia do anúncio pré-pronto, ajusta o que precisar e publica.',
    },
    {
        chave: 'lanc',
        titulo: 'Protocolo e Jardinagem',
        dono: 'Analista',
        unidade: 'ofertas',
        pronto: 'Protocolo de lançamento concluído; depois o anúncio segue na Jardinagem.',
        faz: 'Aqui ficam as ofertas no ar. O protocolo de lançamento e a Jardinagem chegam por outro módulo.',
    },
];

export const ORDEM = ETAPAS.map((e) => e.chave);

export const ETAPA_POR_CHAVE = Object.fromEntries(ETAPAS.map((e) => [e.chave, e]));

/** Posição da etapa na trilha (0 a 5); -1 para chave desconhecida. */
export const indiceDaEtapa = (chave) => ORDEM.indexOf(chave);

/** A cor de cada etapa (as mesmas do Mapa da loja), para `style` e SVG. */
export const COR_DA_ETAPA = {
    lista: 'rgb(var(--t-s-lista))',
    mapa: 'rgb(var(--t-s-mapa))',
    plano: 'rgb(var(--t-s-plano))',
    robo: 'rgb(var(--t-s-robo))',
    revisao: 'rgb(var(--t-s-rev))',
    lanc: 'rgb(var(--t-s-lanc))',
};

/** Ordem dos tipos de oferta (a mesma das fases da metodologia: 1 a 4). */
export const TIPOS_DE_OFERTA = ['Simples', 'Combo', 'Kit', 'Combit'];

/** Sigla curta do tipo, para selo pequeno (Mapa da loja). */
export const SIGLA_DO_TIPO = { Simples: 'S', Combo: 'CB', Kit: 'KIT', Combit: 'CBT' };

/** Chave de um item na tela e na URL (`?abrir=`): `p:12` para produto, `o:s-12` para oferta. */
export const chaveDoProduto = (produto) => `p:${produto.id}`;
export const chaveDaOferta = (oferta) => `o:${oferta.id}`;

/**
 * Os itens de uma etapa, no formato que as linhas da tela usam.
 *
 * @param {{produtos: Array, ofertas: Array}} dados
 * @param {string} chave
 * @returns {Array<{tipo: 'p'|'o', chave: string, etapa: string, item: object}>}
 */
export function itensDaEtapa(dados, chave) {
    if (chave === 'lista' || chave === 'mapa') {
        return (dados.produtos ?? [])
            .filter((p) => p.etapa === chave)
            .map((p) => ({ tipo: 'p', chave: chaveDoProduto(p), etapa: chave, item: p }));
    }

    return (dados.ofertas ?? [])
        .filter((o) => o.etapa === chave)
        .map((o) => ({ tipo: 'o', chave: chaveDaOferta(o), etapa: chave, item: o }));
}

/** Todos os itens, na ordem da trilha (produtos das etapas 1–2, depois ofertas das 3–6). */
export const todosNaOrdem = (dados) => ORDEM.flatMap((k) => itensDaEtapa(dados, k));

/** Quantos itens em cada etapa: `{lista: 3, mapa: 2, …}`. */
export const contagemPorEtapa = (dados) => Object.fromEntries(ORDEM.map((k) => [k, itensDaEtapa(dados, k).length]));

/** Acha um item pela chave da tela (`p:12`, `o:s-12`); null se não existir mais. */
export function acharItem(dados, chave) {
    if (typeof chave !== 'string') return null;
    if (chave.startsWith('p:')) {
        const id = Number(chave.slice(2));
        const item = (dados.produtos ?? []).find((p) => p.id === id);

        return item ? { tipo: 'p', chave, etapa: item.etapa, item } : null;
    }
    if (chave.startsWith('o:')) {
        const id = chave.slice(2);
        const item = (dados.ofertas ?? []).find((o) => o.id === id);

        return item ? { tipo: 'o', chave, etapa: item.etapa, item } : null;
    }

    return null;
}

/** As ofertas (visíveis) que levam um produto, em qualquer quantidade. */
export const ofertasDoProduto = (dados, produtoId) =>
    (dados.ofertas ?? []).filter((o) => (o.componentes ?? []).some((c) => c.produto_id === produtoId));

/**
 * "Com quem está agora": o trabalho pendente de cada dono, com a etapa que o
 * botão abre. A Jardinagem é de outro módulo, então o Analista não entra.
 *
 * @param {{produtos: Array, ofertas: Array}} dados
 * @param {'equipe'|'cliente'} visao
 */
export function comQuemEsta(dados, visao) {
    const n = contagemPorEtapa(dados);

    return [
        { dono: 'Cliente', sigla: 'C', papel: 'cli', descricao: visao === 'cliente' ? 'você' : 'completar a lista', quantidade: n.lista, etapa: 'lista' },
        { dono: 'Estrategista', sigla: 'E', papel: '', descricao: 'mapear e planejar', quantidade: n.mapa + n.plano, etapa: 'mapa' },
        { dono: 'Robô', sigla: '⚙', papel: 'robo', descricao: 'preparando', quantidade: n.robo, etapa: 'robo' },
        { dono: 'Publicador', sigla: 'P', papel: '', descricao: 'revisar e publicar', quantidade: n.revisao, etapa: 'revisao' },
    ];
}

/**
 * A oferta está à venda? É o que o STATUS diz ("No ar · …": algum anúncio ativo ou
 * pausado). A etapa 6 guarda a oferta publicada de qualquer jeito (é da
 * Jardinagem), também a "Fora do ar · …" (anúncio em revisão, esperando
 * pagamento…), a de "Anúncios encerrados" e a "Publicado" cujo anúncio não foi
 * achado — e essas não estão no ar.
 */
export const estaNoAr = (oferta) => oferta?.etapa === 'lanc' && String(oferta?.status ?? '').startsWith('No ar');

/**
 * O texto do botão da linha. O cliente só age na lista de produtos; no resto,
 * acompanha. Na etapa 6, a equipe lê "No ar" só quando a oferta está no ar
 * (`estaNoAr`); a que não está pede "Conferir" — "No ar" ao lado de "Fora do ar"
 * se contradiria.
 */
export function acaoDoItem(etapa, visao, noAr = true) {
    if (visao === 'cliente') return etapa === 'lista' ? 'Completar' : 'Acompanhar';
    if (etapa === 'lanc') return noAr ? 'No ar' : 'Conferir';

    return { lista: 'Completar', mapa: 'Mapear', plano: 'Planejar', robo: 'Ver preparo', revisao: 'Revisar' }[etapa] ?? 'Abrir';
}
