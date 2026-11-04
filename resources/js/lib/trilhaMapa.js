// ─── Mapa da loja — a trilha inteira numa imagem ────────────────────────────
//
// Port de `mapaGrafo()`, `mapaLayout()` e `mapaRelacionados()` do protótipo do
// Erlon, agora com os dados reais da trilha (`App\Services\Trilha\TrilhaDaEmpresa`):
//
//     Produtos → Ofertas → (Ondas) → Anúncios
//
// O que muda de propósito em relação ao protótipo:
// - Agrupa e filtra pela FAMÍLIA do produto (o protótipo usava o ambiente): é
//   como o cadastro e o Planejamento já organizam o catálogo. "Sem família" vai
//   para o fim.
// - As ondas existem desde a 2ª entrega (trilha de trabalho). Sem ondas o mapa
//   tem 3 colunas e a ligação vai direto da oferta aos anúncios; com ondas, 4
//   colunas como no protótipo (onda → anúncio, passando pela oferta: o `via`).
//   A caixa da onda diz "começa 05/11" ou "começou 03/11" (a data é o dia em
//   que o agente começa, não prazo); as ondas vão pela data e, no empate, pelo id.
//   Sem onda, três caixas: "Sem onda" (tracejada, âmbar) só com o que está no
//   Planejamento e pode ser planejado; o que já passou dele sem onda vai para a
//   caixa neutra "Começou sem onda" (a `w0` do protótipo, calculada aqui); a
//   oferta sem produto na Lista vai para a neutra tracejada "Fora das ondas".
//   A oferta que está numa onda fica na caixa dela, mesmo a que já andou ou
//   ficou sem produto (o "começa 05/11" é a data da onda, não da oferta): a
//   onda que tem oferta nunca some do desenho — a mesma conta do cartão dela.
// - O nó de anúncio NÃO usa o MLB como chave: na porta do cliente o MLB vem
//   null, e no "Ver como: Cliente" da equipe o dado ainda traz o MLB. A chave é a
//   posição do anúncio na oferta, e o texto só leva o MLB na visão da equipe.
// - Oferta sem produto na lista (veio da Lista SKUs/importação) não tem de onde
//   puxar a posição: vai para o fim da coluna das ofertas, com rótulo próprio,
//   em vez de subir para o topo como no protótipo.
// - Nenhum nó guarda o objeto original (o protótipo guardava `ref`): o que o
//   mapa mostra é só o que está no nó, e na visão do cliente nada sobra do MLB.
// - O nó de anúncio da equipe leva o `link` do anúncio publicado (04/11/2026) e o
//   nome dele para o leitor de tela: o painel ao lado oferece "Abrir o anúncio".
//   Na visão do cliente os dois vêm nulos, como o MLB (o link leva o MLB dentro).
//
// Funções PURAS — nada de React nem de DOM. Testes: tests/js/trilha-mapa.test.js.
//
// ⚠️ Sem classe do Tailwind neste arquivo: o `content` do tailwind.config.js só
// varre `.jsx`. As cores daqui são `rgb(var(--t-…))`, para `style` no SVG.

import { COR_DA_ETAPA, ETAPA_POR_CHAVE, ORDEM, SIGLA_DO_TIPO, chaveDaOferta, chaveDoProduto, contagemPorEtapa, linkDoAnuncio, rotuloDoLinkDoAnuncio } from './trilha.js';
import { ondasEmOrdem, quandoDaOnda } from './trilhaOndas.js';

/** Nome curto de cada etapa: faixa do fluxo, legenda do mapa e cartões do índice. */
export const ROTULO_CURTO = {
    lista: 'Lista',
    mapa: 'Mapeamento',
    plano: 'Planejamento',
    robo: 'Agente',
    revisao: 'Revisão',
    lanc: 'No ar',
};

export const SEM_FAMILIA = 'Sem família';

/** Produto mapeado (etapa null): já segue pelas ofertas, então a caixa dele fica neutra. */
export const COR_MAPEADO = 'rgb(var(--t-fg2))';

/** A caixa "Sem onda" (só existe quando há ondas): o que está no Planejamento e ainda falta planejar. */
export const COR_SEM_ONDA = 'rgb(var(--t-warn))';

/**
 * As caixas neutras da coluna das ondas: "Começou sem onda" (o que já passou do Planejamento sem esperar uma
 * onda) e "Fora das ondas" (tracejada: a oferta sem produto na Lista, que não se planeja).
 */
export const COR_ANTES_DAS_ONDAS = 'rgb(var(--t-muted))';

/** As caixas da coluna das ondas que não são onda de verdade (não contam no cabeçalho nem abrem painel). */
export const PSEUDO_ONDAS = ['w:sem', 'w:antes', 'w:fora'];

/** Anúncio pela situação (`situacaoDoAnuncio`): no ar = verde da etapa "No ar"; pausado ou parado = âmbar; encerrado ou inativo = cinza. */
export const COR_DO_ANUNCIO = {
    ativo: COR_DA_ETAPA.lanc,
    // Recém-publicado em análise (4d, D59): a cor da Revisão — nem verde (não vende ainda), nem âmbar (não é alerta).
    analise: COR_DA_ETAPA.revisao,
    atencao: 'rgb(var(--t-warn))',
    fora: 'rgb(var(--t-muted))',
};

// Medidas do desenho em unidades do viewBox (o SVG escala para a largura da
// figura). 4 colunas = as do protótipo. 3 colunas (sem ondas) = as mesmas
// caixas, com mais folga entre elas para as linhas se lerem. `minimo` é a
// largura mínima da figura em px: abaixo disso ela rola DENTRO dela (celular)
// em vez de encolher o texto até ele sumir — mesma proporção do protótipo.
const MEDIDAS = {
    3: { largura: 1000, x: [16, 356, 712], w: [264, 284, 272], minimo: 820 },
    4: { largura: 1180, x: [16, 330, 660, 880], w: [264, 280, 170, 284], minimo: 960 },
};
const ALTURA = 34;
const ALTURA_ONDA = 52;
const FOLGA = 8;
const SALTO_DE_GRUPO = 22;
const TOPO = 24;

/** Pixels por letra a 12px (estimativa do protótipo, que cortava 34 letras numa caixa de 264). */
const LETRA = 7;

const comparar = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), 'pt-BR', { numeric: true, sensitivity: 'base' });

const lista = (mapa, chave) => mapa.get(chave) ?? [];

const poe = (mapa, chave, valor) => {
    if (!mapa.has(chave)) mapa.set(chave, []);
    mapa.get(chave).push(valor);
};

const arredonda = (v) => Math.round(v * 10) / 10;

/** "1 produto" / "3 produtos". */
export const quantos = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/** Corta o texto no tamanho que cabe, com reticências. */
export function corta(texto, max) {
    const t = String(texto ?? '');

    return t.length > max ? `${t.slice(0, Math.max(1, max - 1))}…` : t;
}

/** A família do produto como o mapa agrupa ("Sem família" quando não tem). */
export const familiaDe = (produto) => String(produto?.familia ?? '').trim() || SEM_FAMILIA;

/** O SKU que o mapa mostra do produto: o código do grupo; sem ele, o da 1ª cor. */
const skuDoProduto = (produto) => produto.codigo || (produto.skus ?? [])[0] || '';

// O cliente não lê o nome da plataforma (decisão de 07/10, sigilo). A porta do
// cliente já chega limpa do servidor, mas o "Ver como: Cliente" da equipe usa o
// dado da equipe — e a situação que o Publicador grava diz "Pausado pelo
// Mercado Livre". Na visão do cliente, o complemento sai.
const semPlataforma = (texto) => String(texto ?? '').replace(/\s+(?:no|pelo|do|ao)\s+Mercado\s+Livre\b/gi, '').trim();

/**
 * Em que pé está o anúncio: 'ativo' (no ar, `situacao` null), 'fora' (encerrado
 * ou inativo: não volta a vender) ou 'atencao' (o resto: pausado, em revisão,
 * esperando pagamento ou ativação, e o "Fora do ar" que o servidor dá a qualquer
 * situação nova). É a régua do servidor (`SituacaoDasOfertas::ENCERRADOS`): lá o
 * anúncio parado liga o alerta da oferta desde a revisão de 31/10/2026 — e aqui o
 * "Fora do ar" ficava cinza, como encerrado.
 */
export function situacaoDoAnuncio(anuncio) {
    const s = String(anuncio?.situacao ?? '').trim();
    if (s === '') return 'ativo';
    if (/^(encerrad|inativ)/i.test(s)) return 'fora';
    // "Em análise" só vem nas primeiras 24 h depois de publicar (D59); depois o servidor volta à régua antiga.
    if (/^em análise/i.test(s)) return 'analise';

    return 'atencao';
}

/**
 * As famílias da loja em ordem alfabética, "Sem família" no fim, com quantos
 * produtos cada uma tem (o número vai no botão do filtro).
 *
 * @returns {Array<{nome: string, produtos: number}>}
 */
export function familiasDoMapa(dados) {
    const conta = new Map();
    for (const p of dados?.produtos ?? []) {
        const f = familiaDe(p);
        conta.set(f, (conta.get(f) ?? 0) + 1);
    }

    return [...conta.entries()]
        .sort(([a], [b]) => (a === SEM_FAMILIA) - (b === SEM_FAMILIA) || comparar(a, b))
        .map(([nome, produtos]) => ({ nome, produtos }));
}

/**
 * O grafo do mapa (port de `mapaGrafo()`).
 *
 * Nó: `{id, tipo: 'produto'|'oferta'|'onda'|'anuncio', coluna, titulo, sub, situacao, cor, rotulo, …}`.
 * O id do produto e o da oferta SÃO as chaves da tela (`p:12`, `o:s-12`, de `lib/trilha.js`): o
 * "Abrir na trilha" passa o id direto. Ligação: `{de, para, cor, quantidade?, via?}` — `via` é a
 * oferta de uma ligação onda → anúncio (só com ondas).
 *
 * @param {{produtos?: Array, ofertas?: Array, ondas?: Array}} dados  as props da trilha
 * @param {{visao?: 'equipe'|'cliente', familia?: ?string}} opcoes  família desconhecida = todas
 * @returns {{nos: Array, ligacoes: Array, familias: Array<{nome: string, produtos: number}>, familia: ?string, temOndas: boolean, colunas: 3|4}}
 */
export function montarGrafo(dados, { visao = 'equipe', familia = null } = {}) {
    const produtos = dados?.produtos ?? [];
    const ofertas = dados?.ofertas ?? [];
    const ondas = dados?.ondas ?? [];
    const temOndas = ondas.length > 0;
    const equipe = visao !== 'cliente';
    const familias = familiasDoMapa(dados);
    const filtro = familia && familias.some((f) => f.nome === familia) ? familia : null;
    const ordemDaFamilia = new Map(familias.map((f, i) => [f.nome, i]));

    const nos = [];
    const ligacoes = [];

    // ── Coluna 0: os produtos, pela ordem da família e depois pelo SKU ──
    const doRecorte = produtos
        .filter((p) => !filtro || familiaDe(p) === filtro)
        .sort((a, b) => ordemDaFamilia.get(familiaDe(a)) - ordemDaFamilia.get(familiaDe(b))
            || comparar(skuDoProduto(a), skuDoProduto(b))
            || comparar(a.nome, b.nome));
    const noRecorte = new Set(doRecorte.map((p) => p.id));

    for (const p of doRecorte) {
        const faltam = (p.faltas ?? []).length;
        let situacao = 'mapeado';
        if (p.etapa === 'lista') situacao = faltam ? `${faltam === 1 ? 'falta' : 'faltam'} ${faltam}` : 'incompleto';
        else if (p.etapa === 'mapa') situacao = 'falta mapear';
        const titulo = p.nome || 'Produto sem nome';
        const sub = skuDoProduto(p) || 'sem SKU';

        nos.push({
            id: chaveDoProduto(p),
            tipo: 'produto',
            coluna: 0,
            titulo,
            sub,
            situacao,
            cor: p.etapa === 'lista' ? COR_DA_ETAPA.lista : p.etapa === 'mapa' ? COR_DA_ETAPA.mapa : COR_MAPEADO,
            grupo: familiaDe(p),
            etapa: p.etapa ?? null,
            rotulo: `Produto ${titulo} · ${sub} · ${situacao}`,
        });
    }

    // ── Coluna 1: as ofertas que levam algum produto do recorte (sem filtro, todas) ──
    // A oferta sem produto na lista não tem família: só aparece em "Todas".
    const ofs = ofertas.filter((o) => !filtro || (o.componentes ?? []).some((c) => noRecorte.has(c.produto_id)));
    const idsDasOndas = new Set(ondas.map((w) => w.id));
    // A oferta que está numa onda fica na caixa dela — também a que já andou (alguém adiantou no editor) e a que
    // ficou sem produto: ela ESTÁ na onda (o cartão da onda a conta, e só sai pelo "Tirar da onda"). O "começa
    // 05/11" da caixa é a data da ONDA, não da oferta. Sem onda: a sem produto na Lista fica fora das ondas (não
    // se planeja); das outras, "Sem onda" só o que está no Planejamento — o que já passou dele sem onda já começou.
    const ondaDa = (o) => {
        if (o.onda_id != null && idsDasOndas.has(o.onda_id)) return o.onda_id;
        if (o.sem_produto) return 'fora';

        return o.etapa === 'plano' ? 'sem' : 'antes';
    };

    for (const o of ofs) {
        const id = chaveDaOferta(o);
        const cor = COR_DA_ETAPA[o.etapa] ?? COR_MAPEADO;

        // Duas cores do mesmo produto no kit viram UMA linha com a soma (×2), não duas linhas sobrepostas.
        const porProduto = new Map();
        for (const c of o.componentes ?? []) {
            if (!noRecorte.has(c.produto_id)) continue;
            porProduto.set(c.produto_id, (porProduto.get(c.produto_id) ?? 0) + (Number(c.quantidade) || 1));
        }
        for (const [produtoId, quantidade] of porProduto) {
            ligacoes.push({ de: chaveDoProduto({ id: produtoId }), para: id, quantidade, cor });
        }

        const titulo = o.nome || o.sku || 'Oferta sem nome';
        const situacao = ETAPA_POR_CHAVE[o.etapa]?.titulo ?? '';
        const status = equipe ? (o.status ?? '') : semPlataforma(o.status);

        nos.push({
            id,
            tipo: 'oferta',
            coluna: 1,
            titulo,
            sub: o.sku || '',
            sigla: SIGLA_DO_TIPO[o.tipo] ?? null,
            tipoDeOferta: o.tipo ?? '',
            cor,
            situacao,
            status,
            alerta: !!o.alerta,
            etapa: o.etapa ?? null,
            semProduto: porProduto.size === 0,
            rotulo: `${o.tipo ?? 'Oferta'}: ${titulo} · ${o.sku || 'sem SKU'} · ${situacao}${status ? ` · ${status}` : ''}`,
        });
    }

    // ── Coluna 2, só com ondas: as ondas das ofertas do recorte, pela data e, no empate, pelo id ──
    if (temOndas) {
        const usadas = ondasEmOrdem(ondas).filter((w) => ofs.some((o) => ondaDa(o) === w.id));
        for (const w of usadas) {
            const situacao = quantos(ofs.filter((o) => ondaDa(o) === w.id).length, 'oferta', 'ofertas');
            const titulo = w.nome || `Onda ${w.id}`;
            const sub = w.data ? quandoDaOnda(w) : 'sem data';
            nos.push({ id: `w:${w.id}`, tipo: 'onda', coluna: 2, titulo, sub, situacao, cor: COR_DA_ETAPA.plano, rotulo: `Onda ${titulo} · ${sub} · ${situacao}` });
        }
        const semOnda = ofs.filter((o) => ondaDa(o) === 'sem').length;
        if (semOnda) {
            const situacao = quantos(semOnda, 'oferta', 'ofertas');
            nos.push({
                id: 'w:sem', tipo: 'onda', coluna: 2, titulo: 'Sem onda', sub: 'falta planejar', situacao, cor: COR_SEM_ONDA, tracejado: true, pseudo: true,
                rotulo: `Ofertas sem onda · falta planejar · ${situacao}`,
            });
        }
        const antes = ofs.filter((o) => ondaDa(o) === 'antes').length;
        if (antes) {
            const situacao = quantos(antes, 'oferta', 'ofertas');
            nos.push({
                id: 'w:antes', tipo: 'onda', coluna: 2, titulo: 'Começou sem onda', sub: 'já começadas', situacao, cor: COR_ANTES_DAS_ONDAS, pseudo: true,
                rotulo: `Ofertas que começaram sem onda · já começadas · ${situacao}`,
            });
        }
        const fora = ofs.filter((o) => ondaDa(o) === 'fora').length;
        if (fora) {
            const situacao = quantos(fora, 'oferta', 'ofertas');
            nos.push({
                id: 'w:fora', tipo: 'onda', coluna: 2, titulo: 'Fora das ondas', sub: 'sem produto na lista', situacao, cor: COR_ANTES_DAS_ONDAS, tracejado: true, pseudo: true,
                rotulo: `Ofertas fora das ondas · sem produto na lista · ${situacao}`,
            });
        }
        for (const o of ofs) {
            ligacoes.push({ de: chaveDaOferta(o), para: `w:${ondaDa(o)}`, cor: COR_DA_ETAPA[o.etapa] ?? COR_MAPEADO });
        }
    }

    // ── Última coluna: os anúncios de cada oferta ──
    const colunaDoAnuncio = temOndas ? 3 : 2;
    for (const o of ofs) {
        const origem = chaveDaOferta(o);
        (o.anuncios ?? []).forEach((a, i) => {
            const tipo = a.tipo || 'Anúncio';
            const estado = situacaoDoAnuncio(a);
            // O MLB só na visão da equipe: no "Ver como: Cliente" o dado ainda o traz.
            const mlb = equipe && a.mlb ? a.mlb : null;
            const sub = mlb ? `${tipo} · ${mlb}` : tipo;
            // O link do anúncio vai junto com o MLB: só na visão da equipe.
            const link = mlb ? linkDoAnuncio(a) : null;
            const rotuloDoLink = link ? rotuloDoLinkDoAnuncio({ tipo, mlb }) : null;
            const situacao = estado === 'ativo' ? 'No ar' : (equipe ? a.situacao : semPlataforma(a.situacao)) || 'Fora do ar';
            const titulo = o.nome || o.sku || 'Oferta sem nome';
            const id = `m:${o.id}:${i}`;
            const cor = COR_DO_ANUNCIO[estado];

            nos.push({ id, tipo: 'anuncio', coluna: colunaDoAnuncio, titulo, sub, situacao, cor, estado, origem, rotulo: `Anúncio ${sub} de ${titulo} · ${situacao}`, link, rotuloDoLink });
            ligacoes.push(temOndas ? { de: `w:${ondaDa(o)}`, para: id, cor, via: origem } : { de: origem, para: id, cor });
        });
    }

    return { nos, ligacoes, familias, familia: filtro, temOndas, colunas: temOndas ? 4 : 3 };
}

/**
 * Onde fica cada caixa (port de `mapaLayout()`): a coluna dos produtos na ordem
 * do grafo, com um respiro e o nome da família a cada troca; as outras colunas
 * pelo centro dos pais (barycenter), empurrando para baixo a caixa que
 * encostaria na de cima — nunca há sobreposição na mesma coluna.
 *
 * As ligações já saem com a geometria (`x1, y1, x2, y2, d`): quando várias chegam
 * na mesma caixa (os produtos de um kit, as ofertas de uma onda), entram em
 * alturas diferentes, na ordem de quem sai, para as linhas não se cruzarem na
 * chegada e o "×N" de uma não cair em cima do da outra.
 *
 * @returns {{largura: number, altura: number, minimo: number, colunas: Array<{x: number, w: number}>,
 *            pos: Object<string, object>, nos: Array, ligacoes: Array, rotulos: Array<{x: number, y: number, texto: string}>}}
 */
export function posicionar(grafo) {
    const m = MEDIDAS[grafo.colunas] ?? MEDIDAS[3];
    const pos = {};
    const rotulos = [];
    const porColuna = m.x.map((_, c) => grafo.nos.filter((n) => n.coluna === c));
    const coloca = (n, y, h) => {
        pos[n.id] = { ...n, x: m.x[n.coluna], w: m.w[n.coluna], y, h };

        return pos[n.id];
    };

    // Coluna 0 — o rótulo da família fica 8 acima da 1ª caixa do grupo, no respiro.
    let y = 0;
    let grupo = null;
    for (const n of porColuna[0]) {
        if (n.grupo !== grupo) {
            if (grupo !== null) y += SALTO_DE_GRUPO;
            rotulos.push({ x: m.x[0], y: y + TOPO - 8, texto: corta(String(n.grupo).toUpperCase(), 34) });
            grupo = n.grupo;
        }
        coloca(n, y + TOPO, ALTURA);
        y += ALTURA + FOLGA;
    }

    // Quem puxa cada caixa: as ligações que chegam nela. O anúncio segue a oferta de
    // origem (com ondas, a ligação dele sai da onda, que é uma caixa só para muitas ofertas).
    const chegam = new Map();
    for (const l of grafo.ligacoes) {
        if (!l.via) poe(chegam, l.para, l.de);
    }
    const paisDe = (n) => (n.tipo === 'anuncio' ? [n.origem] : lista(chegam, n.id)).map((id) => pos[id]).filter(Boolean);

    for (let c = 1; c < porColuna.length; c++) {
        const h = grafo.temOndas && c === 2 ? ALTURA_ONDA : ALTURA;
        const comPai = [];
        const semPai = [];
        for (const n of porColuna[c]) {
            const pais = paisDe(n);
            if (pais.length) comPai.push({ n, ideal: pais.reduce((t, p) => t + p.y, 0) / pais.length });
            else semPai.push(n);
        }
        comPai.sort((a, b) => a.ideal - b.ideal);

        let yy = TOPO;
        for (const { n, ideal } of comPai) {
            // A onda é mais alta: sobe um pouco para o meio dela ficar na altura dos pais.
            const p = coloca(n, Math.max(yy, h === ALTURA_ONDA ? ideal - 6 : ideal), h);
            yy = p.y + h + FOLGA;
        }
        if (semPai.length) {
            if (comPai.length) yy += SALTO_DE_GRUPO;
            if (c === 1) rotulos.push({ x: m.x[1], y: yy - 8, texto: 'SEM PRODUTO NA LISTA' });
            for (const n of semPai) {
                const p = coloca(n, yy, h);
                yy = p.y + h + FOLGA;
            }
        }
    }

    const validas = grafo.ligacoes.filter((l) => pos[l.de] && pos[l.para]);
    const entradas = new Map();
    for (const l of validas) poe(entradas, l.para, l);
    const yDaEntrada = new Map();
    for (const [para, chegando] of entradas) {
        const b = pos[para];
        chegando.sort((l1, l2) => pos[l1.de].y - pos[l2.de].y);
        const passo = chegando.length > 1 ? Math.min(10, (b.h * 0.6) / (chegando.length - 1)) : 0;
        chegando.forEach((l, i) => yDaEntrada.set(l, b.y + b.h / 2 + (i - (chegando.length - 1) / 2) * passo));
    }

    const ligacoes = validas.map((l) => {
        const a = pos[l.de];
        const b = pos[l.para];
        const x1 = arredonda(a.x + a.w);
        const y1 = arredonda(a.y + a.h / 2);
        const x2 = arredonda(b.x);
        const y2 = arredonda(yDaEntrada.get(l));
        const meio = arredonda((x1 + x2) / 2);

        return { ...l, x1, y1, x2, y2, d: `M${x1},${y1} C${meio},${y1} ${meio},${y2} ${x2},${y2}` };
    });

    const nos = grafo.nos.map((n) => pos[n.id]).filter(Boolean);
    const fundo = nos.reduce((max, n) => Math.max(max, n.y + n.h), TOPO);

    return {
        largura: m.largura,
        altura: fundo + 20,
        minimo: m.minimo,
        colunas: m.x.map((x, i) => ({ x, w: m.w[i] })),
        pos,
        nos,
        ligacoes,
        rotulos,
    };
}

/**
 * O que acende quando uma caixa é escolhida (port de `mapaRelacionados()`):
 * - produto → as ofertas que o levam, e a onda e os anúncios delas (os OUTROS produtos de um kit, não);
 * - oferta → os produtos dela, a onda e os anúncios;
 * - onda → as ofertas dela, com os produtos e os anúncios delas;
 * - anúncio → a oferta de origem, os produtos dela e a onda (o outro anúncio da mesma oferta, não).
 *
 * @returns {?Set<string>} null quando nada está escolhido ou o id não está no grafo
 */
export function relacionados(grafo, id) {
    if (!id) return null;
    const no = grafo.nos.find((n) => n.id === id);
    if (!no) return null;

    const ofertasDoProduto = new Map();
    const produtosDaOferta = new Map();
    const ofertasDaOnda = new Map();
    const ondaDaOferta = new Map();
    const anunciosDaOferta = new Map();
    for (const l of grafo.ligacoes) {
        if (l.de.startsWith('p:')) {
            poe(ofertasDoProduto, l.de, l.para);
            poe(produtosDaOferta, l.para, l.de);
        } else if (l.para.startsWith('w:')) {
            ondaDaOferta.set(l.de, l.para);
            poe(ofertasDaOnda, l.para, l.de);
        }
    }
    for (const n of grafo.nos) {
        if (n.tipo === 'anuncio') poe(anunciosDaOferta, n.origem, n.id);
    }

    const acesos = new Set([id]);
    const acende = (oferta, { produtos = true, anuncios = true } = {}) => {
        acesos.add(oferta);
        if (produtos) lista(produtosDaOferta, oferta).forEach((p) => acesos.add(p));
        if (anuncios) lista(anunciosDaOferta, oferta).forEach((a) => acesos.add(a));
        if (ondaDaOferta.has(oferta)) acesos.add(ondaDaOferta.get(oferta));
    };

    if (no.tipo === 'produto') lista(ofertasDoProduto, id).forEach((o) => acende(o, { produtos: false }));
    else if (no.tipo === 'oferta') acende(id);
    else if (no.tipo === 'onda') lista(ofertasDaOnda, id).forEach((o) => acende(o));
    else if (no.tipo === 'anuncio') acende(no.origem, { anuncios: false });

    return acesos;
}

/** A ligação acende quando as duas pontas — e a oferta do caminho, se houver — estão acesas. */
export const ligacaoAcesa = (ligacao, acesos) => !!acesos
    && acesos.has(ligacao.de)
    && acesos.has(ligacao.para)
    && (!ligacao.via || acesos.has(ligacao.via));

/**
 * O texto de dentro da caixa: onde ele começa (depois do selo do tipo, como no
 * protótipo) e o título e o SKU cortados para caber na largura.
 */
export function caixaDoNo(no) {
    const larguraDoSelo = no.sigla ? no.sigla.length * 7 + 8 : 0;
    const recuo = no.sigla ? larguraDoSelo + 14 : 12;
    const cabe = Math.max(6, Math.floor((no.w - recuo - 10) / LETRA));

    return { larguraDoSelo, recuo, titulo: corta(no.titulo, cabe), sub: corta(no.sub, cabe) };
}

/** Os títulos das colunas, com quantos itens cada uma desenha agora (já com o filtro). */
export function cabecalhosDoMapa(grafo) {
    const conta = (tipo) => grafo.nos.filter((n) => n.tipo === tipo && !n.pseudo).length;

    return [
        { titulo: 'Lista de produtos', detalhe: quantos(conta('produto'), 'produto', 'produtos') },
        { titulo: 'Ofertas', detalhe: quantos(conta('oferta'), 'oferta', 'ofertas') },
        ...(grafo.temOndas ? [{ titulo: 'Ondas', detalhe: quantos(conta('onda'), 'onda', 'ondas') }] : []),
        { titulo: 'Anúncios', detalhe: quantos(conta('anuncio'), 'anúncio', 'anúncios') },
    ];
}

/**
 * O status do agente que é PARADA (alguém precisa olhar): o agente parou, ou a onda
 * começou há tempo e nada começou. "Na fila · com pendência na Lista" tem alerta
 * mas é aviso — o agente prepara com o que houver —, não parada.
 */
const PARADA_DO_ROBO = /^(?:O agente parou|Ainda não começou)/;

/**
 * A faixa do fluxo no topo do mapa (Lista → Mapeamento → Planejamento → Agente →
 * Revisão → No ar), sempre da loja inteira — o filtro de família não mexe nela,
 * como no protótipo.
 *
 * @returns {Array<{chave: string, titulo: string, numero: number, unidade: string, detalhe: string, cor: string}>}
 */
export function resumoDoFluxo(dados) {
    const produtos = dados?.produtos ?? [];
    const ofertas = dados?.ofertas ?? [];
    const ondas = dados?.ondas ?? [];
    const n = contagemPorEtapa({ produtos, ofertas });
    const alertas = (etapa) => ofertas.filter((o) => o.etapa === etapa && o.alerta).length;
    const idsDeOnda = new Set(ondas.map((w) => w.id));
    const emOndas = ofertas.filter((o) => o.onda_id != null && idsDeOnda.has(o.onda_id)).length;
    // O que falta planejar: a oferta sem produto na Lista fica fora das ondas e não conta.
    const paraPlanejar = ofertas.filter((o) => o.etapa === 'plano' && !o.sem_produto).length;
    const anuncios = ofertas.filter((o) => o.etapa === 'lanc').reduce((t, o) => t + (o.anuncios ?? []).length, 0);
    const passo = (chave, numero, unidade, detalhe) => ({ chave, titulo: ROTULO_CURTO[chave], numero, unidade, detalhe, cor: COR_DA_ETAPA[chave] });
    const robo = ofertas.filter((o) => o.etapa === 'robo' && PARADA_DO_ROBO.test(String(o.status ?? ''))).length;
    const revisao = alertas('revisao');

    return [
        passo('lista', produtos.length, produtos.length === 1 ? 'produto' : 'produtos', n.lista ? quantos(n.lista, 'incompleto', 'incompletos') : ''),
        passo('mapa', ofertas.length, ofertas.length === 1 ? 'oferta' : 'ofertas', n.mapa ? `${quantos(n.mapa, 'produto', 'produtos')} por mapear` : ''),
        ondas.length
            ? passo('plano', emOndas, 'em ondas', paraPlanejar ? `${paraPlanejar} sem onda` : '')
            // Sem ondas, tudo o que está no Planejamento (e se planeja) espera a sua.
            : passo('plano', n.plano, n.plano === 1 ? 'oferta' : 'ofertas', paraPlanejar ? 'esperando onda' : ''),
        passo('robo', n.robo, 'preparando', robo ? quantos(robo, 'parada', 'paradas') : ''),
        passo('revisao', n.revisao, 'para publicar', revisao ? `${revisao} com pendência` : ''),
        passo('lanc', n.lanc, n.lanc === 1 ? 'oferta' : 'ofertas', anuncios ? quantos(anuncios, 'anúncio', 'anúncios') : ''),
    ];
}

/** A legenda: a cor de cada etapa (e do produto mapeado) e a de cada situação do anúncio. */
export function legendaDoMapa(temOndas = false) {
    return {
        etapas: [
            ...ORDEM.map((k) => ({ rotulo: k === 'lista' ? 'Lista de produtos' : ROTULO_CURTO[k], cor: COR_DA_ETAPA[k] })),
            { rotulo: 'Produto mapeado', cor: COR_MAPEADO },
            ...(temOndas ? [{ rotulo: 'Sem onda', cor: COR_SEM_ONDA }, { rotulo: 'Começou sem onda ou fora das ondas', cor: COR_ANTES_DAS_ONDAS }] : []),
        ],
        anuncios: [
            { rotulo: 'No ar', cor: COR_DO_ANUNCIO.ativo },
            { rotulo: 'Em análise (recém-publicado)', cor: COR_DO_ANUNCIO.analise },
            { rotulo: 'Pausado, em revisão ou fora do ar', cor: COR_DO_ANUNCIO.atencao },
            { rotulo: 'Encerrado ou inativo', cor: COR_DO_ANUNCIO.fora },
        ],
    };
}
