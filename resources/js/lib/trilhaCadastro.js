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
// F1: nome, família, ambientes, título, descrição e, por variação que JÁ
// existe, SKU, custo, estoque e caixas. F2: a categoria (vai na 1ª linha, como
// `categoria_ml_id`) e a ficha técnica com as medidas do produto montado — o
// 2º passo do `gravarCadastro`, depois das linhas (a ficha exige a categoria
// GRAVADA). A ficha mora no mesmo estado da gaveta (`estado.ficha`, no formato
// de `lib/fichaTecnica.js`): o "mexido" e a guarda de saída a veem.

import { campoEditaveis, linhaParaServidor } from './produtosEstrutura.js';
import { categoriaParaConsulta, deveGravar, eixosEmUso, gruposDoProduto, montarAtributos, valoresIniciais } from './fichaTecnica.js';
import { mesclarSugestoes } from './fichaLayout.js';

export { CHAVE_NOVO_PRODUTO, deveFecharNoEsc } from './trilha.js';

/** O máximo de caixas por variação (o mesmo `MAX_VOLUMES` do normalizador do servidor). */
export const MAX_CAIXAS = 20;

/** O máximo de linhas por pedido, quando o retrato não diz (`ProdutoCadastroService::MAX_GRADE`). */
export const LINHAS_POR_PEDIDO = 200;

/** Os campos do PRODUTO na gaveta (vão na 1ª linha) e os de cada VARIAÇÃO. */
export const CAMPOS_DO_PRODUTO = ['nome', 'categoria', 'titulo', 'familia', 'ambientes', 'descricao'];
export const CAMPOS_DA_VARIACAO = ['codigo', 'custo', 'estoque', 'volumes'];

/** O texto do "Salvar" que parou num campo (o servidor devolve um erro por linha, com HTTP 200). */
export const MENSAGEM_DOS_CAMPOS = 'Confira os campos marcados: o que está neles não foi salvo.';

/** O 2º passo (a ficha) voltou com erro de campo: o 1º (as linhas) já ficou gravado (§5.9 do desenho). */
export const MENSAGEM_DA_FICHA = 'Os dados do produto foram salvos; confira os campos marcados na ficha.';

/** Só a ficha foi, e voltou com erro de campo. */
export const MENSAGEM_SO_DA_FICHA = 'Confira os campos marcados na ficha técnica: o que está neles não foi salvo.';

/** O SKU trocado para um que tinha anúncio na espera: o servidor ligou o produto a ele no mesmo "Salvar". */
export const AVISO_DO_SKU_ABSORVIDO = 'Este SKU já tinha anúncio: o produto ficou ligado a ele e vai direto para a etapa 6.';

/** O "Salvar" com a ficha da categoria ainda carregando: gravar agora apagaria a ficha (o PUT substitui). */
export const ESPERE_A_FICHA = 'Os campos da ficha técnica ainda estão carregando. Espere um instante e salve de novo.';

/** A categoria mudou e os campos dela não carregaram: as linhas gravam, a ficha fica para o próximo "Salvar". */
export const FICHA_FICOU_PARA_DEPOIS = 'A ficha técnica não foi salva: os campos da categoria não carregaram. Tente de novo depois.';

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
 * A categoria como a gaveta guarda: `{id, nome, caminho_texto}`, ou null. A que o cadastro tem só como TEXTO (sem
 * id, "a confirmar") conta como nenhuma: não abre ficha nem limite — a tela mostra o texto e pede a escolha. Aceita
 * a categoria do retrato, a candidata da busca e a escolha da árvore (`caminho` em lista).
 */
export function categoriaDaGaveta(categoria) {
    const id = categoriaParaConsulta(categoria?.id);
    if (!id) return null;
    const caminho = Array.isArray(categoria.caminho)
        ? categoria.caminho.map((c) => texto(typeof c === 'string' ? c : c?.nome)).filter(Boolean).join(' > ')
        : '';

    return { id, nome: texto(categoria.nome), caminho_texto: texto(categoria.caminho_texto) || caminho || texto(categoria.nome) };
}

/** O caminho da categoria para ler ("Casa › Móveis › Poltronas"): o servidor escreve com ">". */
export const caminhoParaTela = (categoria) => texto(categoria?.caminho_texto || categoria?.nome).replace(/\s*>\s*/g, ' › ');

/**
 * Os campos da gaveta a partir do retrato (rota 1). Tudo em texto, como se digita: o custo com vírgula, o
 * estoque e as medidas como texto — quem interpreta é o servidor. A ficha técnica no formato de
 * `lib/fichaTecnica.js` (`valoresIniciais` dos `salvos`).
 *
 * @returns {{produto: {nome: string, categoria: ?{id: string, nome: string, caminho_texto: string}, titulo: string,
 *              familia: string, ambientes: string[], descricao: string},
 *            variacoes: Array<{id: number, codigo: string, custo: string, estoque: string, volumes: Array<{c: string, l: string, a: string, kg: string}>}>,
 *            ficha: Record<string, {valor: any, unidade: string, naoSeAplica?: boolean, outro?: boolean}>}}
 */
export function estadoDoRetrato(retrato) {
    const p = retrato?.produto ?? {};

    return {
        produto: {
            nome: p.nome ?? '',
            categoria: categoriaDaGaveta(p.categoria),
            titulo: p.titulo ?? '',
            familia: p.familia ?? '',
            ambientes: [...(p.ambientes ?? [])],
            // O ambiente digitado no campo e ainda não juntado como chip: conta (é mexido e vai no "Salvar").
            ambiente_digitado: '',
            descricao: p.descricao ?? '',
        },
        variacoes: (retrato?.variacoes ?? []).map((v) => ({
            id: v.id,
            codigo: v.codigo ?? '',
            custo: custoParaTexto(v.custo),
            estoque: v.estoque === null || v.estoque === undefined ? '' : String(v.estoque),
            volumes: (v.volumes ?? []).map((c) => ({ c: numeroParaTexto(c?.c), l: numeroParaTexto(c?.l), a: numeroParaTexto(c?.a), kg: numeroParaTexto(c?.kg) })),
        })),
        ficha: valoresIniciais(retrato?.ficha?.salvos),
    };
}

// ─── O que mudou ────────────────────────────────────────────────────────────

/** Os ambientes como conjunto: sem espaço nas pontas, sem vazio e sem repetido. */
const conjunto = (lista) => [...new Set((lista ?? []).map(texto).filter(Boolean))];
const mesmoConjunto = (a, b) => JSON.stringify(conjunto(a).sort()) === JSON.stringify(conjunto(b).sort());

/**
 * Os ambientes que o "Salvar" leva: os chips E o que está digitado no campo e ainda não virou chip (revisão da F1:
 * o digitado se perdia — o "Salvar" não o via e fechar não perguntava). O digitado igual a um chip (sem caixa nem
 * acento) não repete.
 */
export function ambientesEfetivos(produto) {
    const chips = conjunto(produto?.ambientes);
    const digitado = texto(produto?.ambiente_digitado);
    if (!digitado || chips.some((a) => nomeComparavel(a) === nomeComparavel(digitado))) return chips;

    return [...chips, digitado];
}

/** O valor de um campo do produto para comparar e mandar (os ambientes contam o digitado). */
const doProduto = (produto, campo) => (campo === 'ambientes' ? ambientesEfetivos(produto) : produto?.[campo]);

/** O mesmo valor para o servidor? (texto aparado; a descrição com as quebras normalizadas; ambientes e caixas.) */
function igual(campo, a, b) {
    if (campo === 'categoria') return (a?.id ?? null) === (b?.id ?? null);
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
    const produto = CAMPOS_DO_PRODUTO.filter((c) => !igual(c, doProduto(estado?.produto, c), base.produto[c]));
    const variacoes = {};
    for (const v of estado?.variacoes ?? []) {
        const b = base.variacoes.find((x) => x.id === v.id);
        const campos = b ? CAMPOS_DA_VARIACAO.filter((c) => !igual(c, v[c], b[c])) : [...CAMPOS_DA_VARIACAO];
        if (campos.length) variacoes[v.id] = campos;
    }

    return { produto, variacoes };
}

/**
 * Alguma caixa de conteúdo difere do retrato do servidor? (é o que liga o "Salvar" e a guarda de saída) A ficha
 * técnica conta também: com os `grupos` da categoria, pelo corpo que iria ao servidor; sem eles, pelo que está na tela.
 */
export function mexido(estado, retrato, { grupos = null } = {}) {
    if (!estado || !retrato) return false;
    const m = camposMexidos(estado, retrato);

    return m.produto.length > 0 || Object.keys(m.variacoes).length > 0 || fichaMexida(estado, retrato, { grupos });
}

// ─── A ficha técnica (F2) ───────────────────────────────────────────────────

/** Um campo da ficha como se compara sem a definição: "não se aplica", as opções escolhidas ou o texto (com a unidade). */
function campoComparavel(v) {
    if (!v) return '';
    if (v.naoSeAplica) return '\u0000nao-se-aplica';
    if (Array.isArray(v.valor)) return v.valor.map((x) => texto(x)).filter(Boolean).sort().join('\u0001');
    const t = texto(v.valor);

    return t === '' ? '' : `${t}\u0001${texto(v.unidade)}`;
}

/** Os eixos que as variações do produto usam (`cor`, `tamanho`…): os campos deles saem da ficha (o valor vem da variação). */
export const eixosDoRetrato = (retrato) => eixosEmUso([{ eixo: retrato?.produto?.eixo, eixo_2: retrato?.produto?.eixo_2 }], retrato?.eixos);

/** A definição da categoria como vale para ESTE produto: sem os campos que são eixo das variações dele. */
export const gruposDaFicha = (grupos, retrato) => gruposDoProduto(grupos, eixosDoRetrato(retrato));

/**
 * A ficha técnica da tela difere da gravada? Com os `grupos` (a definição para o produto), compara o corpo que iria
 * ao servidor (`montarAtributos`: a opção pelo id, o texto antigo que não serve fica de fora, "não se aplica" vence);
 * sem eles, compara o que está digitado. Sem `estado.ficha`, nada mexido.
 */
export function fichaMexida(estado, retrato, { grupos = null } = {}) {
    if (!estado?.ficha) return false;
    const base = valoresIniciais(retrato?.ficha?.salvos);
    if (Array.isArray(grupos) && grupos.length) {
        return JSON.stringify(montarAtributos(grupos, estado.ficha)) !== JSON.stringify(montarAtributos(grupos, base));
    }
    const ids = new Set([...Object.keys(estado.ficha), ...Object.keys(base)]);

    return [...ids].some((id) => campoComparavel(estado.ficha[id]) !== campoComparavel(base[id]));
}

/**
 * O 2º passo do "Salvar": a ficha vai? `definicao` é a da gaveta: `{categoria, grupos, carregando}` (os `grupos` da
 * categoria que carregou por último; `carregando` = os da categoria de agora ainda não chegaram).
 *
 * - sem categoria na tela: não vai (a ficha exige a categoria GRAVADA);
 * - com os campos da categoria de agora: vai quando a ficha mudou OU a categoria mudou (trocar de categoria regrava a
 *   ficha inteira: o que a nova não tem sai), salvo se não há nada a gravar nem a limpar (`deveGravar`);
 * - sem eles: `esperar` se ainda carregam e havia o que gravar (mandar agora apagaria a ficha); fora do ar, as
 *   linhas gravam e a ficha fica (`pulou`).
 *
 * @returns {{gravar: boolean, esperar: boolean, pulou: boolean, atributos: Array, categoria: ?string}}
 */
export function fichaParaSalvar({ estado, retrato, definicao = null }) {
    const categoria = estado?.produto?.categoria?.id ?? null;
    const nada = { gravar: false, esperar: false, pulou: false, atributos: [], categoria };
    if (!categoria) return nada;
    const categoriaMudou = categoria !== (categoriaDaGaveta(retrato?.produto?.categoria)?.id ?? null);

    if (!Array.isArray(definicao?.grupos) || definicao.categoria !== categoria) {
        const havia = categoriaMudou || fichaMexida(estado, retrato);

        return { ...nada, esperar: havia && !!definicao?.carregando, pulou: havia && !definicao?.carregando };
    }

    const grupos = gruposDaFicha(definicao.grupos, retrato);
    const atributos = montarAtributos(grupos, estado.ficha ?? {});
    const antes = montarAtributos(grupos, valoresIniciais(retrato?.ficha?.salvos));
    const mudou = categoriaMudou || JSON.stringify(atributos) !== JSON.stringify(antes);
    const jaTinhaSalvos = (retrato?.ficha?.salvos ?? []).length > 0;

    return { ...nada, gravar: mudou && deveGravar({ definicaoPronta: true, atributos, jaTinhaSalvos }), atributos };
}

/**
 * "Preencher com IA": junta as sugestões (no formato das linhas salvas) à ficha da tela, SÓ nos campos vazios — o que
 * a pessoa preencheu ou marcou "não se aplica" nunca muda. Nada grava: a ficha fica "mexida" até o "Salvar".
 *
 * @returns {{ficha: object, aplicados: string[], naoSeAplica: number}}
 */
export function aplicarSugestoesNaFicha(ficha, sugestoes) {
    const r = mesclarSugestoes(ficha ?? {}, Array.isArray(sugestoes) ? sugestoes : []);

    return { ficha: r.valores, aplicados: r.aplicados, naoSeAplica: r.naoSeAplica };
}

/** O resultado do "Preencher com IA" em uma frase (`{erro}` ou `{aplicados, naoSeAplica}`). */
export function textoDaIa(r) {
    if (r?.erro) return r.erro;
    const aplicados = Number(r?.aplicados ?? 0);
    if (!aplicados) return 'A IA não achou mais nada para preencher com o que o produto informa.';
    const na = Number(r?.naoSeAplica ?? 0);
    const valores = aplicados - na;
    const partes = [];
    if (valores > 0) partes.push(`preencheu ${valores} ${valores === 1 ? 'campo' : 'campos'}`);
    if (na > 0) partes.push(`marcou ${na} como "Não se aplica"`);

    return `A IA ${partes.join(' e ')}. Confira os marcados "sugestão da IA" e clique em Salvar.`;
}

/**
 * A IA da ficha só com a categoria SALVA e a mesma na tela: os campos são os da categoria gravada, e o servidor
 * recusa (422) a tela que trocou de categoria sem salvar.
 */
export function podePreencherComIa(retrato, estado) {
    const salva = categoriaDaGaveta(retrato?.produto?.categoria)?.id ?? null;

    return !!salva && salva === (estado?.produto?.categoria?.id ?? null);
}

// ─── A categoria (F2) ───────────────────────────────────────────────────────

/** A busca pelo nome do produto vai de 2 a 120 letras (`q` do servidor); fora disso, nada sai. */
export const MINIMO_DA_BUSCA = 2;
export const MAXIMO_DA_BUSCA = 120;

/** O texto da busca pelo nome, ou null quando curto demais. Acima de 120 letras, o começo. */
export function textoDaBusca(nome) {
    const t = texto(nome).slice(0, MAXIMO_DA_BUSCA).trim();

    return t.length >= MINIMO_DA_BUSCA ? t : null;
}

/**
 * A categoria SUGERIDA entre as candidatas do preditor: a 1ª que é a última do caminho (`folha`) E aceita produto
 * novo (`anunciavel`). Nula quando o servidor não soube dizer (`null`): essa não se sugere. Nada é aplicado sozinho
 * (D-06): a sugestão só aparece, e "Usar esta" é que põe na gaveta.
 */
export function sugestaoDaCategoria(candidatas) {
    return (Array.isArray(candidatas) ? candidatas : []).find((c) => categoriaParaConsulta(c?.id) && c.folha === true && c.anunciavel === true) ?? null;
}

/** A candidata pode ser usada direto? Só a última do caminho que aceita produto novo. */
export const candidataUsavel = (c) => c?.folha === true && c?.anunciavel !== false;

/** O que a candidata diz quando não pode ser usada direto (a tela abre a navegação nela, ou só avisa). */
export function porQueNaoUsar(c) {
    if (c?.folha === false) return 'Escolha uma mais específica';
    if (c?.folha === true && c?.anunciavel === false) return 'Não aceita produto novo';
    if (c?.folha !== true) return 'Confira na navegação';

    return null;
}

/** A categoria travada: com o produto no Publicador ela só se troca no editor de lá (D12; o servidor recusa também). */
export const categoriaTravada = (retrato) => !!(retrato?.situacao?.categoria_travada || retrato?.situacao?.no_publicador);

/**
 * Proteção contra o clique errado (demo de 23/10): a lista que acabou de mudar não aceita clique por este tempo — a
 * pessoa mirou na lista de antes. Vale para as candidatas e para os níveis da árvore.
 */
export const LISTA_ESTAVEL_MS = 300;

/**
 * O clique vale? `mudouEm` = quando a lista mudou; `desceuEm` = quando o botão do mouse desceu no item (sem ele —
 * teclado, leitor de tela — conta a partir de `agora`).
 */
export const cliqueValeNaLista = ({ mudouEm = 0, desceuEm = null, agora }) => (desceuEm ?? agora) - mudouEm >= LISTA_ESTAVEL_MS;

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
        const doProdutoMexido = primeira ? mexidos.produto : [];
        if (!daVariacao.length && !doProdutoMexido.length) return;

        // Os campos que esta tela edita, no formato da linha do Portal. O que ela não edita (eixo, valor, caixas
        // em texto) fica igual dos dois lados e não vai.
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
        // A categoria (F2) vai pelo id, só quando a pessoa escolheu outra — nunca o nome como texto (na trilha, a
        // categoria só vem da busca ou da navegação; o servidor confere folha, anunciável e a trava do Publicador).
        if (doProdutoMexido.includes('categoria') && estado.produto.categoria?.id) {
            linha._categoriaEscolhida = true;
            linha.categoria_ml_id = estado.produto.categoria.id;
        }

        const saida = linhaParaServidor(linha);
        delete saida.produto_id;
        if (doProdutoMexido.includes('ambientes')) saida.ambientes = ambientesEfetivos(estado.produto);
        if (doProdutoMexido.includes('descricao')) {
            const descricao = textoLongo(estado.produto.descricao);
            saida.descricao = descricao !== '' ? descricao : null;
        }

        const leva = Object.keys(saida).some((k) => !SEMPRE_NA_LINHA.includes(k))
            || daVariacao.includes('codigo')
            || doProdutoMexido.includes('nome');
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
 * `linhas`: o passo 1 tinha o que mandar; `ficha`: o passo 2 gravou. Com a falha no passo 2, o 1º já ficou gravado.
 *
 * @returns {Promise<{ok: boolean, nada: boolean, passo: ?('linhas'|'ficha'), erros: Array, avisos: string[],
 *   criadas_nas_listas: {familias: string[], ambientes: string[]}, resposta: ?object, falha: ?Error, versao: ?string,
 *   linhas: boolean, ficha: boolean}>}
 */
export async function gravarCadastro({ estado, retrato, enviarLinhas, gravarFicha = null, fichaMudou = false, versao = null, porPedido = null }) {
    const linhas = linhasParaSalvar(estado, retrato);
    const tamanho = Math.max(1, Number(porPedido ?? retrato?.limites?.linhas) || LINHAS_POR_PEDIDO);
    const r = {
        ok: false, nada: false, passo: null, erros: [], avisos: [], criadas_nas_listas: { familias: [], ambientes: [] },
        resposta: null, falha: null, versao, totais: null, linhas: linhas.length > 0, ficha: false,
    };
    const somar = (totais) => {
        if (!totais) return;
        r.totais ??= { criadas: 0, atualizadas: 0, sem_mudanca: 0, com_erro: 0, absorvidos_da_espera: 0 };
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
            r.ficha = true;
            if (resposta?.versao) r.versao = resposta.versao;
        } catch (e) {
            // As linhas (se houve) JÁ gravaram: `linhas` diz se o passo 1 foi, e `resposta` é o que ele devolveu.
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
export const nadaMudou = (r) => !!r?.totais && !r.ficha && Number(r.totais.criadas ?? 0) + Number(r.totais.atualizadas ?? 0) === 0 && !(r.erros ?? []).length;

/**
 * Os erros de campo do servidor (`erros[]`, um por linha) pelo lugar na gaveta: `porChave[v31].custo`. O que
 * não tem campo que a gaveta mostre (`linha`…) vai em `soltas`, para o rodapé. O erro da categoria (o serviço o
 * chama de `categoria_ml_id`) vai no campo `categoria`.
 *
 * @returns {{porChave: Record<string, Record<string, string>>, soltas: string[]}}
 */
export function errosPorCampo(erros, campos = [...CAMPOS_DO_PRODUTO, ...CAMPOS_DA_VARIACAO]) {
    const porChave = {};
    const soltas = [];
    for (const erro of erros ?? []) {
        const doErro = Object.entries(erro?.campos ?? {})
            .filter(([, m]) => typeof m === 'string' && m.trim())
            .map(([campo, m]) => [campo === 'categoria_ml_id' ? 'categoria' : campo, m]);
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
 * O erro da categoria num 422 do passo das linhas (a trilha recusa a categoria não anunciável, ou a troca com o
 * produto no Publicador, ANTES do serviço: `errors["linhas.0.categoria_ml_id"]`). → a mensagem, ou null.
 */
export function erroDaCategoriaNo422(falha) {
    if (falha?.response?.status !== 422) return null;
    for (const [chave, mensagens] of Object.entries(falha.response.data?.errors ?? {})) {
        if (!/(?:^|\.)categoria(?:_ml_id)?$/.test(chave)) continue;
        const m = [mensagens].flat().map(texto).find(Boolean);
        if (m) return m;
    }

    return null;
}

/** A ficha da tela por cima da que veio: o campo que a pessoa mexeu (difere do retrato ANTIGO) fica; o resto vem. */
function fichaJunta(estado, antigo, novo) {
    if (!estado?.ficha) return novo.ficha;
    const ids = new Set([...Object.keys(estado.ficha), ...Object.keys(antigo.ficha), ...Object.keys(novo.ficha)]);
    const saida = {};
    for (const id of ids) {
        const mexeu = campoComparavel(estado.ficha[id]) !== campoComparavel(antigo.ficha[id]);
        const valor = mexeu ? estado.ficha[id] : novo.ficha[id];
        if (valor !== undefined) saida[id] = valor;
    }

    return saida;
}

/**
 * O reducer das AÇÕES IMEDIATAS (cor nova, foto — F3/F4): junta o retrato novo ao que está na tela sem apagar o
 * que ainda não foi salvo. Campo que a pessoa mexeu (difere do retrato ANTIGO) fica como ela deixou; o resto
 * passa a ser o do retrato novo — a ficha também, campo a campo. Variação que sumiu sai; a nova entra como veio.
 */
export function aplicarResposta(estado, antigo, novo) {
    const base = estadoDoRetrato(antigo);
    const vindo = estadoDoRetrato(novo);
    if (!estado) return vindo;
    const manter = (campo, atual, deBase, deNovo) => (igual(campo, atual, deBase) ? deNovo : atual);

    return {
        produto: {
            ...Object.fromEntries(CAMPOS_DO_PRODUTO.map((c) => [c, manter(c, estado.produto?.[c], base.produto[c], vindo.produto[c])])),
            ambiente_digitado: estado.produto?.ambiente_digitado ?? '',
        },
        variacoes: vindo.variacoes.map((v) => {
            const atual = (estado.variacoes ?? []).find((x) => x.id === v.id);
            const b = base.variacoes.find((x) => x.id === v.id);
            if (!atual || !b) return v;

            return { id: v.id, ...Object.fromEntries(CAMPOS_DA_VARIACAO.map((c) => [c, manter(c, atual[c], b[c], v[c])])) };
        }),
        ficha: fichaJunta(estado, base, vindo),
    };
}

/**
 * Depois do "Salvar": os campos passam a ser os do retrato novo (o servidor normaliza — "1.890" vira "1890,00",
 * o título ganha maiúsculas), MENOS as linhas que voltaram com erro: essas não gravaram nada e ficam como a
 * pessoa deixou, para ela corrigir. Os campos do produto andam com a 1ª linha. Com erro, o "Salvar" parou antes
 * da ficha: ela fica como está na tela.
 */
export function depoisDeSalvar(estado, novo, erros = []) {
    const vindo = estadoDoRetrato(novo);
    const comErro = new Set((erros ?? []).map((e) => e?.chave).filter(Boolean));
    if (!estado || !comErro.size) return vindo;
    const primeira = estado.variacoes?.[0];

    return {
        produto: primeira && comErro.has(chaveDaVariacao(primeira)) ? { ...estado.produto } : vindo.produto,
        variacoes: vindo.variacoes.map((v) => (comErro.has(chaveDaVariacao(v)) ? (estado.variacoes ?? []).find((x) => x.id === v.id) ?? v : v)),
        ficha: estado.ficha ?? vindo.ficha,
    };
}

/**
 * O passo 2 (a ficha) falhou e o passo 1 (as linhas) gravou: os campos do produto e das variações passam a ser os do
 * retrato novo, e a FICHA fica como a pessoa deixou — ela corrige os campos marcados e salva de novo.
 */
export const depoisDaFichaRecusada = (estado, novo) => ({ ...estadoDoRetrato(novo), ficha: estado?.ficha ?? estadoDoRetrato(novo).ficha });

/** "a, b e c". */
const emLista = (itens) => (itens.length > 1 ? `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}` : itens[0] ?? '');

/**
 * O resultado do "Salvar" que toma o lugar do formulário (o padrão do Mapear):
 * - `completo`: o produto estava na Lista e saiu → "Lista completa: o produto seguiu para o mapeamento.";
 * - `incompleto`: continua na Lista → "Salvo. Ainda faltam: …" (as faltas do SERVIDOR, nada recalculado aqui);
 * - `salvo`: já estava fora da Lista → "Salvo.";
 * - `absorvido`: o SKU que a pessoa digitou já tinha anúncio na espera — o servidor ligou o produto a ele no MESMO
 *   save (`totais.absorvidos_da_espera > 0`) e ele vai direto para a etapa 6 (e costuma voltar travado: só leitura,
 *   conforme o retrato). Nada de "seguiu para o mapeamento" (revisão da F1).
 * "Criamos a família X" vai junto quando o servidor criou nome novo nas listas da empresa.
 *
 * @returns {{tipo: 'completo'|'incompleto'|'salvo'|'absorvido', texto: string, faltas: string[], extra: ?string}}
 */
export function resultadoDoSalvar(antigo, novo, criadas = null, { absorvidos = 0 } = {}) {
    const antes = antigo?.situacao?.etapa ?? null;
    const depois = novo?.situacao?.etapa ?? null;
    const faltas = [...(novo?.situacao?.faltas ?? [])];
    const familias = criadas?.familias ?? [];
    const ambientes = criadas?.ambientes ?? [];
    const partes = [];
    if (familias.length) partes.push(`${familias.length > 1 ? 'as famílias' : 'a família'} ${emLista(familias)}`);
    if (ambientes.length) partes.push(`${ambientes.length > 1 ? 'os ambientes' : 'o ambiente'} ${emLista(ambientes)}`);
    const extra = partes.length ? `Criamos ${partes.join(' e ')} na lista da empresa.` : null;

    if (Number(absorvidos) > 0) return { tipo: 'absorvido', texto: AVISO_DO_SKU_ABSORVIDO, faltas: [], extra };
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

/**
 * A gaveta `e:novo` tem algo a perder? O nome ou o SKU digitados e o produto ainda não criado (revisão da F1: o Esc
 * e o clique no fundo os perdiam calados). Durante o envio e depois de criado, não: o que foi digitado já está no
 * servidor (ou vai estar).
 */
export const produtoNovoMexido = ({ nome = '', codigo = '', criando = false, criado = false } = {}) => !criando && !criado && (texto(nome) !== '' || texto(codigo) !== '');

/**
 * O Enter num campo com sugestões (família, ambientes). Com a lista ABERTA ele nunca envia o formulário (revisão da
 * F1: "Pol" com "Poltronas" na lista → Enter → o "Salvar" implícito criava a família "Pol"): escolhe a opção
 * marcada pelas setas ou, sem marca, a 1ª da lista (a que mais casa; o "(nova)" vem por último). Com a lista
 * fechada, o Enter só é do campo quando ele tem o que fazer sem lista (`temSemEscolha`: o ambiente digitado vira
 * chip); senão segue o padrão do navegador.
 *
 * @param {{listaAberta: boolean, ativa?: number, itens?: Array, temSemEscolha?: boolean}} opcoes
 * @returns {{prevenir: boolean, escolher: ?object, semEscolha: boolean}}
 */
export function acaoDoEnter({ listaAberta, ativa = -1, itens = [], temSemEscolha = false }) {
    if (listaAberta) return { prevenir: true, escolher: itens[ativa >= 0 ? ativa : 0] ?? null, semEscolha: false };

    return { prevenir: !!temSemEscolha, escolher: null, semEscolha: !!temSemEscolha };
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
