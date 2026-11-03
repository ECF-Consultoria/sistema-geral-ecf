// ─── Trilha de produtos — o cadastro do produto na etapa 1 (3ª entrega) ─────
//
// As regras PURAS da gaveta de cadastro (`e:{id}` e `e:novo`), sem React, sem
// axios e sem classe do Tailwind (arquivo `.js`: o Tailwind só varre `.jsx`).
// Testadas em tests/js/trilha-cadastro.test.js.
//
// O contrato com o servidor é o §6.3 do desenho (`.planning/trilha/ENTREGA-3-DESENHO.md`):
// a rota 1 devolve o RETRATO da gaveta (produto, variações, listas, situação,
// edição); o "Salvar" manda as LINHAS do Portal (`linhaParaServidor` de
// `lib/produtosEstrutura.js`, a mesma régua já testada): só o que mudou, `''` =
// não mexi e `null` = limpar. O servidor é quem decide; daqui sai só o pedido e
// a leitura da resposta.
//
// F1 (esta fatia): nome, família, ambientes, título (sem os termos da
// categoria), descrição e, por variação que JÁ existe, SKU, custo, estoque e
// caixas. A ficha técnica (F2) entra como um 2º passo do `gravarCadastro`.

import { campoEditaveis, linhaParaServidor } from './produtosEstrutura.js';

export { CHAVE_NOVO_PRODUTO, deveFecharNoEsc } from './trilha.js';

/** O máximo de caixas por variação (o mesmo `MAX_VOLUMES` do normalizador do servidor). */
export const MAX_CAIXAS = 20;

/** O máximo de linhas por pedido, quando o retrato não diz (`ProdutoCadastroService::MAX_GRADE`). */
export const LINHAS_POR_PEDIDO = 200;

/** Os campos do PRODUTO na gaveta (vão na 1ª linha) e os de cada VARIAÇÃO. */
export const CAMPOS_DO_PRODUTO = ['nome', 'titulo', 'familia', 'ambientes', 'descricao'];
export const CAMPOS_DA_VARIACAO = ['codigo', 'custo', 'estoque', 'volumes'];

/** O texto do "Salvar" que parou num campo (o servidor devolve um erro por linha, com HTTP 200). */
export const MENSAGEM_DOS_CAMPOS = 'Confira os campos marcados: o que está neles não foi salvo.';

// ─── Do servidor para os campos ─────────────────────────────────────────────

const texto = (v) => String(v ?? '').trim();

/** A descrição como se compara: as quebras de linha do Windows viram `\n` e as pontas saem (como no servidor). */
const textoLongo = (v) => String(v ?? '').replace(/\r\n?/g, '\n').trim();

/** Custo do servidor ("890.00") para o texto que se digita ("890,00"); sem custo, vazio. */
export function custoParaTexto(custo) {
    if (custo === null || custo === undefined || custo === '') return '';
    const n = Number(String(custo).replace(',', '.'));

    return Number.isFinite(n) ? n.toFixed(2).replace('.', ',') : String(custo);
}

/** Uma medida ou peso do servidor (80, 18.5) para o texto do campo ("80", "18,5"). */
function numeroParaTexto(n) {
    if (n === null || n === undefined || n === '') return '';
    const x = Number(String(n).replace(',', '.'));

    return Number.isFinite(x) ? String(x).replace('.', ',') : String(n);
}

/** Uma caixa em branco (C × L × A em cm, peso em kg). */
export const caixaVazia = () => ({ c: '', l: '', a: '', kg: '' });

/** A caixa em que nada foi digitado não conta: nem como mudança, nem no pedido. */
export const caixaEmBranco = (caixa) => ['c', 'l', 'a', 'kg'].every((k) => texto(caixa?.[k]) === '');

const caixasPreenchidas = (caixas) => (caixas ?? []).filter((c) => !caixaEmBranco(c)).map((c) => ({ c: texto(c.c), l: texto(c.l), a: texto(c.a), kg: texto(c.kg) }));

/** A chave de uma variação na tela e no pedido (`chave` da linha, que volta nos erros). */
export const chaveDaVariacao = (variacao) => `v${variacao.id}`;

/**
 * Os campos da gaveta a partir do retrato (rota 1). Tudo em texto, como se digita: o custo com vírgula, o
 * estoque e as medidas como texto — quem interpreta é o servidor.
 *
 * @returns {{produto: {nome: string, titulo: string, familia: string, ambientes: string[], descricao: string},
 *            variacoes: Array<{id: number, codigo: string, custo: string, estoque: string, volumes: Array<{c: string, l: string, a: string, kg: string}>}>}}
 */
export function estadoDoRetrato(retrato) {
    const p = retrato?.produto ?? {};

    return {
        produto: {
            nome: p.nome ?? '',
            titulo: p.titulo ?? '',
            familia: p.familia ?? '',
            ambientes: [...(p.ambientes ?? [])],
            descricao: p.descricao ?? '',
        },
        variacoes: (retrato?.variacoes ?? []).map((v) => ({
            id: v.id,
            codigo: v.codigo ?? '',
            custo: custoParaTexto(v.custo),
            estoque: v.estoque === null || v.estoque === undefined ? '' : String(v.estoque),
            volumes: (v.volumes ?? []).map((c) => ({ c: numeroParaTexto(c?.c), l: numeroParaTexto(c?.l), a: numeroParaTexto(c?.a), kg: numeroParaTexto(c?.kg) })),
        })),
    };
}

// ─── O que mudou ────────────────────────────────────────────────────────────

/** Os ambientes como conjunto: sem espaço nas pontas, sem vazio e sem repetido. */
const conjunto = (lista) => [...new Set((lista ?? []).map(texto).filter(Boolean))];
const mesmoConjunto = (a, b) => JSON.stringify(conjunto(a).sort()) === JSON.stringify(conjunto(b).sort());

/** O mesmo valor para o servidor? (texto aparado; a descrição com as quebras normalizadas; ambientes e caixas.) */
function igual(campo, a, b) {
    if (campo === 'ambientes') return mesmoConjunto(a, b);
    if (campo === 'volumes') return JSON.stringify(caixasPreenchidas(a)) === JSON.stringify(caixasPreenchidas(b));
    if (campo === 'descricao') return textoLongo(a) === textoLongo(b);

    return texto(a) === texto(b);
}

/**
 * Os campos que a pessoa mudou em relação ao retrato: `{produto: ['titulo'], variacoes: {31: ['custo']}}`.
 * A variação que o retrato não tem (não deveria acontecer na F1) conta com todos os campos.
 */
export function camposMexidos(estado, retrato) {
    const base = estadoDoRetrato(retrato);
    const produto = CAMPOS_DO_PRODUTO.filter((c) => !igual(c, estado?.produto?.[c], base.produto[c]));
    const variacoes = {};
    for (const v of estado?.variacoes ?? []) {
        const b = base.variacoes.find((x) => x.id === v.id);
        const campos = b ? CAMPOS_DA_VARIACAO.filter((c) => !igual(c, v[c], b[c])) : [...CAMPOS_DA_VARIACAO];
        if (campos.length) variacoes[v.id] = campos;
    }

    return { produto, variacoes };
}

/** Alguma caixa de conteúdo difere do retrato do servidor? (é o que liga o "Salvar" e a guarda de saída) */
export function mexido(estado, retrato) {
    if (!estado || !retrato) return false;
    const m = camposMexidos(estado, retrato);

    return m.produto.length > 0 || Object.keys(m.variacoes).length > 0;
}

// ─── O pedido do "Salvar" ───────────────────────────────────────────────────

/** As chaves que a linha leva mesmo sem mudança (`linhaParaServidor` manda código e nome sempre). */
const SEMPRE_NA_LINHA = ['chave', 'codigo', 'nome', 'id'];

/**
 * As linhas do "Salvar" (rota 3), uma por variação que mudou, sobre a régua do Portal (`linhaParaServidor`):
 * só o que mudou; `null` para limpar o que o servidor tinha; código e nome sempre. Os campos do PRODUTO vão na
 * 1ª linha (nome, título, família, ambientes e a descrição, que o servidor grava pela linha). Nada de
 * `produto_id` nem `grupo`: na trilha, uma requisição é um produto — o do endereço.
 *
 * @returns {Array<object>}
 */
export function linhasParaSalvar(estado, retrato) {
    if (!estado || !retrato) return [];
    const base = estadoDoRetrato(retrato);
    const mexidos = camposMexidos(estado, retrato);
    const linhas = [];

    (estado.variacoes ?? []).forEach((v, i) => {
        const b = base.variacoes.find((x) => x.id === v.id) ?? { codigo: '', custo: '', estoque: '', volumes: [] };
        const primeira = i === 0;
        const daVariacao = mexidos.variacoes[v.id] ?? [];
        const doProduto = primeira ? mexidos.produto : [];
        if (!daVariacao.length && !doProduto.length) return;

        // Os campos que esta tela edita, no formato da linha do Portal. O que ela não edita (eixo, valor,
        // categoria, caixas em texto) fica igual dos dois lados e não vai.
        const linha = {
            id: v.id,
            _k: chaveDaVariacao(v),
            codigo: v.codigo,
            nome: estado.produto.nome,
            titulo: primeira ? estado.produto.titulo : base.produto.titulo,
            familia: primeira ? estado.produto.familia : base.produto.familia,
            custo: v.custo,
            estoque: v.estoque,
        };
        linha._base = campoEditaveis({
            codigo: b.codigo,
            nome: base.produto.nome,
            titulo: base.produto.titulo,
            familia: base.produto.familia,
            custo: b.custo,
            estoque: b.estoque,
        });
        // As caixas vão como lista (vazia = limpar), só quando mudaram; a caixa em branco não vai.
        if (daVariacao.includes('volumes')) linha.volumes_digitados = caixasPreenchidas(v.volumes);

        const saida = linhaParaServidor(linha);
        delete saida.produto_id;
        if (doProduto.includes('ambientes')) saida.ambientes = conjunto(estado.produto.ambientes);
        if (doProduto.includes('descricao')) {
            const descricao = textoLongo(estado.produto.descricao);
            saida.descricao = descricao !== '' ? descricao : null;
        }

        const leva = Object.keys(saida).some((k) => !SEMPRE_NA_LINHA.includes(k))
            || daVariacao.includes('codigo')
            || doProduto.includes('nome');
        if (leva) linhas.push(saida);
    });

    return linhas;
}

/** A resposta da rota 3 já traz o retrato novo? (o controller da trilha devolve o retrato junto do resultado) */
export const temRetrato = (resposta) => !!resposta && typeof resposta.produto === 'object' && resposta.produto !== null && Array.isArray(resposta.variacoes);

/**
 * O "Salvar", na ORDEM do contrato (§5.9 do desenho): 1) as linhas, em lotes de até 200; 2) a ficha técnica
 * (F2: `gravarFicha`, só quando há o que gravar). O 1º lote com erro de campo (HTTP 200 com `erros`) PARA a
 * sequência; o pedido que lança (rede, 409, 422 do Laravel) também — e o `passo` diz onde.
 *
 * Sem axios: `enviarLinhas(linhas, {versao})` e `gravarFicha({versao})` são injetados (devolvem o JSON do
 * servidor). A `versao` (F6, contra a aba velha) vai adiante: a de cada resposta vale para o pedido seguinte.
 *
 * @returns {Promise<{ok: boolean, nada: boolean, passo: ?('linhas'|'ficha'), erros: Array, avisos: string[],
 *   criadas_nas_listas: {familias: string[], ambientes: string[]}, resposta: ?object, falha: ?Error, versao: ?string}>}
 */
export async function gravarCadastro({ estado, retrato, enviarLinhas, gravarFicha = null, fichaMudou = false, versao = null, porPedido = null }) {
    const linhas = linhasParaSalvar(estado, retrato);
    const tamanho = Math.max(1, Number(porPedido ?? retrato?.limites?.linhas) || LINHAS_POR_PEDIDO);
    const r = {
        ok: false, nada: false, passo: null, erros: [], avisos: [], criadas_nas_listas: { familias: [], ambientes: [] },
        resposta: null, falha: null, versao, totais: null,
    };
    const somar = (totais) => {
        if (!totais) return;
        r.totais ??= { criadas: 0, atualizadas: 0, sem_mudanca: 0, com_erro: 0 };
        for (const k of Object.keys(r.totais)) r.totais[k] += Number(totais[k] ?? 0) || 0;
    };
    const daFicha = !!(gravarFicha && fichaMudou);
    if (!linhas.length && !daFicha) return { ...r, ok: true, nada: true };

    for (let i = 0; i < linhas.length; i += tamanho) {
        let resposta;
        try {
            resposta = await enviarLinhas(linhas.slice(i, i + tamanho), { versao: r.versao });
        } catch (e) {
            return { ...r, passo: 'linhas', falha: e };
        }
        r.resposta = resposta ?? {};
        somar(resposta?.totais);
        r.avisos.push(...(resposta?.avisos ?? []));
        r.criadas_nas_listas.familias.push(...(resposta?.criadas_nas_listas?.familias ?? []));
        r.criadas_nas_listas.ambientes.push(...(resposta?.criadas_nas_listas?.ambientes ?? []));
        if (resposta?.versao) r.versao = resposta.versao;
        if ((resposta?.erros ?? []).length) return { ...r, passo: 'linhas', erros: [...resposta.erros] };
    }

    if (daFicha) {
        try {
            const resposta = await gravarFicha({ versao: r.versao });
            r.resposta = resposta ?? r.resposta;
            if (resposta?.versao) r.versao = resposta.versao;
        } catch (e) {
            return { ...r, passo: 'ficha', falha: e };
        }
    }

    return { ...r, ok: true };
}

// ─── A resposta ─────────────────────────────────────────────────────────────

/**
 * O servidor gravou alguma coisa? Com os `totais` das linhas zerados ("890" contra os "890,00" que já estavam lá),
 * nada mudou — a tela não diz "Salvo". Sem `totais` na resposta, não dá para saber: conta como gravado.
 */
export const nadaMudou = (r) => !!r?.totais && Number(r.totais.criadas ?? 0) + Number(r.totais.atualizadas ?? 0) === 0 && !(r.erros ?? []).length;

/**
 * Os erros de campo do servidor (`erros[]`, um por linha) pelo lugar na gaveta: `porChave[v31].custo`. O que
 * não tem campo que a gaveta mostre (`linha`, `categoria` na F1…) vai em `soltas`, para o rodapé.
 *
 * @returns {{porChave: Record<string, Record<string, string>>, soltas: string[]}}
 */
export function errosPorCampo(erros, campos = [...CAMPOS_DO_PRODUTO, ...CAMPOS_DA_VARIACAO]) {
    const porChave = {};
    const soltas = [];
    for (const erro of erros ?? []) {
        const doErro = Object.entries(erro?.campos ?? {}).filter(([, m]) => typeof m === 'string' && m.trim());
        const conhecidos = doErro.filter(([campo]) => campos.includes(campo));
        if (erro?.chave && conhecidos.length) {
            porChave[erro.chave] = { ...(porChave[erro.chave] ?? {}), ...Object.fromEntries(conhecidos) };
        }
        const fora = doErro.filter(([campo]) => !campos.includes(campo)).map(([, m]) => m);
        if (fora.length) soltas.push(...fora);
        else if (!conhecidos.length && texto(erro?.mensagem)) soltas.push(texto(erro.mensagem));
    }

    return { porChave, soltas: [...new Set(soltas)] };
}

/**
 * O reducer das AÇÕES IMEDIATAS (cor nova, foto — F3/F4): junta o retrato novo ao que está na tela sem apagar o
 * que ainda não foi salvo. Campo que a pessoa mexeu (difere do retrato ANTIGO) fica como ela deixou; o resto
 * passa a ser o do retrato novo. Variação que sumiu sai; a nova entra como veio.
 */
export function aplicarResposta(estado, antigo, novo) {
    const base = estadoDoRetrato(antigo);
    const vindo = estadoDoRetrato(novo);
    if (!estado) return vindo;
    const manter = (campo, atual, deBase, deNovo) => (igual(campo, atual, deBase) ? deNovo : atual);

    return {
        produto: Object.fromEntries(CAMPOS_DO_PRODUTO.map((c) => [c, manter(c, estado.produto?.[c], base.produto[c], vindo.produto[c])])),
        variacoes: vindo.variacoes.map((v) => {
            const atual = (estado.variacoes ?? []).find((x) => x.id === v.id);
            const b = base.variacoes.find((x) => x.id === v.id);
            if (!atual || !b) return v;

            return { id: v.id, ...Object.fromEntries(CAMPOS_DA_VARIACAO.map((c) => [c, manter(c, atual[c], b[c], v[c])])) };
        }),
    };
}

/**
 * Depois do "Salvar": os campos passam a ser os do retrato novo (o servidor normaliza — "1.890" vira "1890,00",
 * o título ganha maiúsculas), MENOS as linhas que voltaram com erro: essas não gravaram nada e ficam como a
 * pessoa deixou, para ela corrigir. Os campos do produto andam com a 1ª linha.
 */
export function depoisDeSalvar(estado, novo, erros = []) {
    const vindo = estadoDoRetrato(novo);
    const comErro = new Set((erros ?? []).map((e) => e?.chave).filter(Boolean));
    if (!estado || !comErro.size) return vindo;
    const primeira = estado.variacoes?.[0];

    return {
        produto: primeira && comErro.has(chaveDaVariacao(primeira)) ? { ...estado.produto } : vindo.produto,
        variacoes: vindo.variacoes.map((v) => (comErro.has(chaveDaVariacao(v)) ? (estado.variacoes ?? []).find((x) => x.id === v.id) ?? v : v)),
    };
}

/** "a, b e c". */
const emLista = (itens) => (itens.length > 1 ? `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}` : itens[0] ?? '');

/**
 * O resultado do "Salvar" que toma o lugar do formulário (o padrão do Mapear):
 * - `completo`: o produto estava na Lista e saiu → "Lista completa: o produto seguiu para o mapeamento.";
 * - `incompleto`: continua na Lista → "Salvo. Ainda faltam: …" (as faltas do SERVIDOR, nada recalculado aqui);
 * - `salvo`: já estava fora da Lista → "Salvo.".
 * "Criamos a família X" vai junto quando o servidor criou nome novo nas listas da empresa.
 *
 * @returns {{tipo: 'completo'|'incompleto'|'salvo', texto: string, faltas: string[], extra: ?string}}
 */
export function resultadoDoSalvar(antigo, novo, criadas = null) {
    const antes = antigo?.situacao?.etapa ?? null;
    const depois = novo?.situacao?.etapa ?? null;
    const faltas = [...(novo?.situacao?.faltas ?? [])];
    const familias = criadas?.familias ?? [];
    const ambientes = criadas?.ambientes ?? [];
    const partes = [];
    if (familias.length) partes.push(`${familias.length > 1 ? 'as famílias' : 'a família'} ${emLista(familias)}`);
    if (ambientes.length) partes.push(`${ambientes.length > 1 ? 'os ambientes' : 'o ambiente'} ${emLista(ambientes)}`);
    const extra = partes.length ? `Criamos ${partes.join(' e ')} na lista da empresa.` : null;

    if (depois === 'lista') {
        return { tipo: 'incompleto', texto: faltas.length ? `Salvo. Ainda faltam: ${faltas.join(', ')}.` : 'Salvo. O produto ainda está na Lista.', faltas, extra };
    }
    if (antes === 'lista') return { tipo: 'completo', texto: 'Lista completa: o produto seguiu para o mapeamento.', faltas: [], extra };

    return { tipo: 'salvo', texto: 'Salvo.', faltas: [], extra };
}

// ─── Família e ambientes: a lista com busca ─────────────────────────────────

/** A forma de comparar nomes da lista: sem caixa, sem acento e sem espaço nas pontas (como o servidor). */
export const nomeComparavel = (nome) => texto(nome).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * As opções da lista (família ou ambiente) que casam com o que foi digitado: as que COMEÇAM com o texto
 * primeiro, depois as que o contêm; sem as já escolhidas (`fora`). Com o texto vazio, todas. E `novo`: o texto
 * digitado que ainda não existe na lista — vira "(nova)"/"(novo)", criado pelo servidor no "Salvar".
 *
 * @param {Array<{id: number, nome: string}|string>} opcoes
 * @returns {{opcoes: string[], novo: ?string}}
 */
export function opcoesQueCasam(opcoes, digitado, { fora = [], limite = 8 } = {}) {
    const nomes = [...new Set((opcoes ?? []).map((o) => texto(typeof o === 'string' ? o : o?.nome)).filter(Boolean))];
    const tirar = new Set((fora ?? []).map(nomeComparavel));
    const busca = nomeComparavel(digitado);
    const livres = nomes.filter((n) => !tirar.has(nomeComparavel(n)));
    const comeca = livres.filter((n) => nomeComparavel(n).startsWith(busca));
    const contem = livres.filter((n) => !nomeComparavel(n).startsWith(busca) && nomeComparavel(n).includes(busca));
    const existe = nomes.some((n) => nomeComparavel(n) === busca);

    return {
        opcoes: [...comeca, ...contem].slice(0, limite),
        novo: busca !== '' && !existe && !tirar.has(busca) ? texto(digitado) : null,
    };
}

/** O nome é novo na lista da empresa? (vira a etiqueta "(nova)" ao lado do campo) */
export const nomeNovo = (opcoes, nome) => texto(nome) !== '' && !(opcoes ?? []).some((o) => nomeComparavel(typeof o === 'string' ? o : o?.nome) === nomeComparavel(nome));

// ─── Avisos da gaveta ───────────────────────────────────────────────────────

/**
 * A onda de um item deste produto já começou e o robô ainda não mexeu: salvar faz o robô preparar com o que
 * estiver aqui. O nome da onda é livre ("Onda 1 · simples", "Black Friday").
 */
export function avisoDaOndaComecada(onda) {
    if (!onda?.nome) return null;
    const nome = texto(onda.nome);
    const qual = /^onda\b/i.test(nome) ? `A ${nome}` : `A onda “${nome}”`;

    return `${qual} já começou: ao salvar, o robô prepara este produto com o que estiver aqui (usa IA).`;
}
