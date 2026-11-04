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
        // A data da onda é o dia em que o agente COMEÇA — nunca "prazo" nem "data de publicação" (decisão de
        // 31/10/2026), com ou sem a trilha de trabalho ligada: o cliente também lê este texto.
        pronto: 'Cada oferta numa onda, com o dia em que o agente começa.',
        faz: 'As ofertas entram em ondas com data: no dia da onda o agente começa a preparar. Normalmente as simples vêm primeiro, mas a ordem depende da lista do cliente.',
    },
    {
        chave: 'robo',
        titulo: 'Preparação automática',
        dono: 'Agente',
        unidade: 'ofertas',
        pronto: 'Título, descrição e imagens prontos: o anúncio fica pré-pronto.',
        faz: 'O agente monta o anúncio com a lista do cliente: título, descrição e imagens a partir das fotos.',
    },
    {
        chave: 'revisao',
        titulo: 'Revisão e publicação',
        // 4e (D59): o cliente lê este texto no "Onde está" — nada de "Publicador" (o nome do módulo interno).
        dono: 'Equipe ECF',
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

/**
 * Os textos que mudam com a TRILHA DE TRABALHO (2ª entrega, 02/11/2026: o
 * mapeamento e as ondas passam a ser feitos aqui, e o agente começa no dia da
 * onda). Os do Planejamento são os mesmos nas duas (estão em `ETAPAS`). As
 * siglas são as do sistema (`-CB2`, `KT-`, `CT4-`).
 */
const TEXTOS_DA_TRILHA_DE_TRABALHO = {
    // 3ª entrega (02/11/2026): quem cadastra é a equipe, aqui dentro. O `faz` é neutro: o cliente também lê.
    // O "Pronto quando" segue a régua do servidor (o teste de render a confere), com a pergunta 3 do desenho: na
    // trilha de trabalho o "frete ME1" deixou de segurar o produto — vira o aviso "vai por ME1 (transportadora)"
    // (`CompletudeDoProduto::AVISO_ME1`), e o texto diz isso em vez de pedir "sem pendência de frete ME1".
    lista: {
        dono: 'Equipe ECF',
        faz: 'A equipe cadastra cada produto aqui: nome, categoria, ficha técnica, cores, fotos, medidas e custo. Completo, ele segue sozinho para o mapeamento.',
        pronto: 'SKU, nome, família, ambiente, categoria, título de venda, descrição, custo, embalagem com medidas e peso, 3 fotos em cada cor e os campos obrigatórios da ficha técnica preenchidos. O que passa do tamanho do frete ME2 não fica preso aqui: segue com o aviso “vai por ME1 (transportadora)”.',
    },
    mapa: {
        faz: 'Todo produto vira oferta simples. Aqui se decide o que combina: combo (o mesmo produto em mais unidades, SKU-CB2), kit (com outros produtos, KT-SKU1-SKU2) e combit (com outros, algum em mais unidades, CT4-SKU1-SKU2). Não precisa esperar a simples vender.',
    },
    robo: {
        faz: 'Na data da onda, o agente monta o anúncio com a lista do cliente: título, descrição e imagens a partir das fotos.',
    },
};

/** A etapa com os textos da trilha que a empresa usa: os de hoje, ou os da trilha de trabalho (`trabalho`). */
export function etapaDaTrilha(chave, trabalho = false) {
    const etapa = ETAPA_POR_CHAVE[chave];
    if (!etapa) return null;
    const outros = trabalho ? TEXTOS_DA_TRILHA_DE_TRABALHO[chave] : null;

    return outros ? { ...etapa, ...outros } : etapa;
}

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

/**
 * Chave de um item na tela e na URL (`?abrir=`): `p:12` para produto, `o:s-12`
 * para oferta. Da 2ª entrega: `w:7` para a onda, `w:nova` para criar uma, e
 * `m:12` para o painel de mapear o produto 12.
 */
export const chaveDoProduto = (produto) => `p:${produto.id}`;
export const chaveDaOferta = (oferta) => `o:${oferta.id}`;
export const chaveDaOnda = (onda) => `w:${onda.id}`;
export const CHAVE_NOVA_ONDA = 'w:nova';
export const chaveDeMapear = (produto) => `m:${produto.id}`;

/**
 * 3ª entrega: a gaveta de CADASTRO do produto — `e:12` edita o produto 12 e `e:novo` cria um. Só para a
 * equipe com escrita: para os outros, `e:12` cai no painel de leitura `p:12` e `e:novo` não abre nada
 * (`painelDaChave`, em `Components/Trilha/estadoDaTela.js`).
 */
export const CHAVE_NOVO_PRODUTO = 'e:novo';
export const chaveDeCadastro = (produto) => `e:${produto.id}`;

/** F7: a gaveta da planilha por categoria (baixar, enviar, conferir e gravar) — também só da equipe com escrita. */
export const CHAVE_PLANILHA = 'e:planilha';

/** F8: a gaveta de importar os anúncios da loja (escolher, importar, acompanhar) — também só da equipe com escrita. */
export const CHAVE_ML = 'e:ml';

/**
 * 4ª entrega, fatia 4a: a PRÉVIA do anúncio de uma oferta — `v:s-12`, `v:c-12-2`, `v:o-55` (a chave é a da oferta
 * com `v:` no lugar de `o:`). Só para a equipe com `escrita.revisao` (`painelDaChave`); para os outros não abre nada.
 */
export const chaveDaPrevia = (oferta) => `v:${oferta.id}`;

/**
 * As etapas em que a oferta tem prévia (D44): a revisão e, depois de publicada, para conferência. Antes delas o agente
 * ainda prepara — a gaveta diz isso em vez de perguntar ao servidor.
 */
export const ETAPAS_DA_PREVIA = ['revisao', 'lanc'];

/** A oferta já tem prévia para ver (`ETAPAS_DA_PREVIA`)? */
export const temPrevia = (oferta) => ETAPAS_DA_PREVIA.includes(oferta?.etapa);

/**
 * A gaveta fecha no Esc só quando ninguém dentro dela já tratou a tecla: a lista aberta de um campo (família,
 * ambientes) fecha a si mesma com `preventDefault`, e o Esc dela não pode levar a gaveta junto (3ª entrega).
 */
export const deveFecharNoEsc = (evento) => evento?.key === 'Escape' && !evento.defaultPrevented;

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

/**
 * O que a chave do `?abrir=` abre, se ainda existir:
 * - `p:`/`o:` → `{painel: 'item', …acharItem}` (o painel do produto ou da oferta);
 * - `w:7` → `{painel: 'onda', onda}`; `w:nova` → `{painel: 'onda', onda: null}`;
 * - `m:12` → `{painel: 'mapear', produto}`;
 * - `e:12` → `{painel: 'cadastro', produto}`; `e:novo` → `{painel: 'cadastro', produto: null}`;
 * - `e:planilha` → `{painel: 'planilha'}` (F7);
 * - `e:ml` → `{painel: 'ml'}` (F8, importar os anúncios da loja);
 * - `v:s-12` → `{painel: 'previa', oferta}` (4a, a prévia do anúncio da oferta).
 * Quem pode ver cada um (o `w:nova`, o `m:` e o `e:` são da equipe com escrita; o `v:`, da equipe com
 * `escrita.revisao`) quem decide é a tela.
 */
export function acharAberto(dados, chave) {
    if (typeof chave !== 'string') return null;
    if (chave === CHAVE_NOVA_ONDA) return { painel: 'onda', chave, onda: null };
    if (chave === CHAVE_NOVO_PRODUTO) return { painel: 'cadastro', chave, produto: null };
    if (chave === CHAVE_PLANILHA) return { painel: 'planilha', chave };
    if (chave === CHAVE_ML) return { painel: 'ml', chave };
    if (chave.startsWith('v:')) {
        const id = chave.slice(2);
        const oferta = (dados.ofertas ?? []).find((o) => o.id === id);

        return oferta ? { painel: 'previa', chave, etapa: oferta.etapa, oferta } : null;
    }
    if (chave.startsWith('e:')) {
        const id = Number(chave.slice(2));
        const produto = (dados.produtos ?? []).find((p) => p.id === id);

        return produto ? { painel: 'cadastro', chave, produto } : null;
    }
    if (chave.startsWith('w:')) {
        const id = Number(chave.slice(2));
        const onda = (dados.ondas ?? []).find((w) => w.id === id);

        return onda ? { painel: 'onda', chave, onda } : null;
    }
    if (chave.startsWith('m:')) {
        const id = Number(chave.slice(2));
        const produto = (dados.produtos ?? []).find((p) => p.id === id);

        return produto ? { painel: 'mapear', chave, produto } : null;
    }
    const achado = acharItem(dados, chave);

    return achado ? { painel: 'item', ...achado } : null;
}

/** As ofertas (visíveis) que levam um produto, em qualquer quantidade. */
export const ofertasDoProduto = (dados, produtoId) =>
    (dados.ofertas ?? []).filter((o) => (o.componentes ?? []).some((c) => c.produto_id === produtoId));

/**
 * "Com quem está agora": o trabalho pendente de cada dono, com a etapa que o
 * botão abre. A Jardinagem é de outro módulo, então o Analista não entra. A
 * oferta sem produto na Lista que está no Planejamento não é trabalho do
 * Estrategista: ela fica fora das ondas ("Fora das ondas · sem produto na Lista").
 *
 * Com a trilha de trabalho (3ª entrega), a Lista é da equipe: "Equipe ECF · cadastrar a lista" — e o
 * cliente lê "cadastrando seus produtos".
 *
 * @param {{produtos: Array, ofertas: Array}} dados
 * @param {'equipe'|'cliente'} visao
 * @param {boolean} [trabalho]
 */
export function comQuemEsta(dados, visao, trabalho = false) {
    const n = contagemPorEtapa(dados);
    const paraPlanejar = (dados.ofertas ?? []).filter((o) => o.etapa === 'plano' && !o.sem_produto).length;
    const daLista = trabalho
        ? { dono: 'Equipe ECF', sigla: 'EQ', papel: '', descricao: visao === 'cliente' ? 'cadastrando seus produtos' : 'cadastrar a lista' }
        : { dono: 'Cliente', sigla: 'C', papel: 'cli', descricao: visao === 'cliente' ? 'você' : 'completar a lista' };

    // 4e: o anúncio "Com o cliente para aprovar" está na etapa 5, mas o trabalho é do cliente — sai da conta da
    // revisão e ganha o próprio chip (só quando há algum: loja sem aprovação do cliente não muda).
    const comCliente = (dados.ofertas ?? []).filter((o) => o.etapa === 'revisao' && String(o.status ?? '').startsWith('Com o cliente')).length;
    // D59: o cliente nunca lê "Publicador" (o nome do módulo interno).
    const daRevisao = visao === 'cliente'
        ? { dono: 'Equipe ECF', sigla: 'EQ', papel: '', descricao: 'revisando seus anúncios' }
        : { dono: 'Publicador', sigla: 'P', papel: '', descricao: 'revisar e publicar' };

    return [
        { ...daLista, quantidade: n.lista, etapa: 'lista', chave: 'lista' },
        { dono: 'Estrategista', sigla: 'E', papel: '', descricao: 'mapear e planejar', quantidade: n.mapa + paraPlanejar, etapa: 'mapa', chave: 'mapa' },
        { dono: 'Agente', sigla: '⚙', papel: 'robo', descricao: 'preparando', quantidade: n.robo, etapa: 'robo', chave: 'robo' },
        { ...daRevisao, quantidade: n.revisao - comCliente, etapa: 'revisao', chave: 'revisao' },
        ...(comCliente > 0
            ? [{ dono: visao === 'cliente' ? 'Você' : 'Cliente', sigla: 'C', papel: 'cli', descricao: 'aprovar anúncios', quantidade: comCliente, etapa: 'revisao', chave: 'aprovacao' }]
            : []),
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
 * Recém-publicado, ainda em análise pela plataforma (4d, D59): nas primeiras 24 h o servidor dá "Em análise · …"
 * sem alerta. Não está à venda ainda (não é "No ar"), mas também não pede conferência (não é âmbar).
 */
export const estaEmAnalise = (oferta) => oferta?.etapa === 'lanc' && String(oferta?.status ?? '').startsWith('Em análise');

/**
 * O link do anúncio publicado (04/11/2026: "o MLB já me direcionando para o anúncio"). Só a porta da EQUIPE o recebe
 * (`ApresentacaoDaTrilha::anuncios`; na do cliente vem nulo, junto com o MLB). O servidor já confere o domínio; aqui
 * fica só a régua do `href`: endereço `https://` ou nada — o resto (vazio, `javascript:`, `http:`) segue como texto.
 */
export function linkDoAnuncio(anuncio) {
    const link = typeof anuncio?.link === 'string' ? anuncio.link.trim() : '';

    return /^https:\/\/[^\s]+$/i.test(link) ? link : null;
}

/**
 * O nome do link para o leitor de tela: "Abrir o anúncio Clássico MLB5511656741 (nova aba)". Sem o nome do canal de
 * venda: este arquivo vai no pacote do cliente (a régua "nenhum Mercado Livre fora de Cadastro/").
 */
export const rotuloDoLinkDoAnuncio = (anuncio) => `Abrir o anúncio ${[anuncio?.tipo, anuncio?.mlb].filter(Boolean).join(' ')} (nova aba)`;

/** Os estados da aprovação do cliente (4e, §19.16 — `trilha_aprovacoes.estado`). */
export const ESTADOS_DA_APROVACAO = ['com_cliente', 'ajuste', 'aprovado'];

/**
 * O começo do status que o servidor escreve na linha para cada estado da aprovação (§19.16, "Lista";
 * `AprovacaoDoClienteNaTrilha::STATUS_*`): "Com o cliente para aprovar", "O cliente pediu ajuste" (ou "Em ajuste pela
 * equipe", quando o anúncio voltou porque a equipe o mudou depois de enviar — D55), "Aprovado pelo cliente · na fila"
 * (ou "· falta publicar", D56). Mudou o texto no servidor, mude aqui — como os prefixos de `estaNoAr`/`estaEmAnalise`.
 */
export const STATUS_DA_APROVACAO = [
    ['com_cliente', 'Com o cliente'],
    ['ajuste', 'O cliente pediu ajuste'],
    ['ajuste', 'Em ajuste pela equipe'],
    ['aprovado', 'Aprovado pelo cliente'],
];

/**
 * Em que pé está a aprovação do cliente desta oferta (4e): `com_cliente`, `ajuste`, `aprovado` ou null (a loja sem a
 * aprovação, ou o anúncio que ainda não foi ao cliente). Vale o campo `aprovacao.estado` quando o servidor o manda na
 * oferta; sem ele, o começo do status da linha (o que o contrato descreve).
 */
export function estadoDaAprovacao(oferta) {
    const doCampo = oferta?.aprovacao?.estado;
    if (ESTADOS_DA_APROVACAO.includes(doCampo)) return doCampo;
    const status = String(oferta?.status ?? '');

    return STATUS_DA_APROVACAO.find(([, prefixo]) => status.startsWith(prefixo))?.[0] ?? null;
}

/**
 * O texto do botão da linha. O cliente só age na lista de produtos; no resto,
 * acompanha. Na etapa 6, a equipe lê "No ar" só quando a oferta está no ar
 * (`estaNoAr`); a que não está pede "Conferir" — "No ar" ao lado de "Fora do ar"
 * se contradiria. A oferta sem produto na Lista não entra em onda: no
 * Planejamento ela pede "Abrir", não "Planejar".
 *
 * Com a trilha de trabalho (`trabalho`, 3ª entrega) quem cadastra é a equipe: o cliente só acompanha, também
 * na Lista. O produto que já andou (`travado`: o agente preparou, está na revisão ou no ar) só se lê — "Ver".
 *
 * 4e (§19.16): o anúncio que está com o cliente para aprovar (`aprovacao` = `com_cliente`) pede "Aprovar" ao cliente
 * — só quando a linha abre a gaveta dele (`podeAprovar`: o cliente de verdade, com o endereço) — e diz "Com o
 * cliente" à equipe (ela espera).
 */
export function acaoDoItem(etapa, visao, noAr = true, { semProduto = false, trabalho = false, travado = false, emAnalise = false, aprovacao = null, podeAprovar = false } = {}) {
    if (visao === 'cliente') {
        if (podeAprovar && aprovacao === 'com_cliente') return 'Aprovar';

        return etapa === 'lista' && !trabalho ? 'Completar' : 'Acompanhar';
    }
    if (etapa === 'revisao' && aprovacao === 'com_cliente') return 'Com o cliente';
    if (etapa === 'lista' && travado) return 'Ver';
    if (etapa === 'lanc' && emAnalise && !noAr) return 'Em análise';
    if (etapa === 'lanc') return noAr ? 'No ar' : 'Conferir';
    if (etapa === 'plano' && semProduto) return 'Abrir';

    return { lista: 'Completar', mapa: 'Mapear', plano: 'Planejar', robo: 'Ver preparo', revisao: 'Revisar' }[etapa] ?? 'Abrir';
}
