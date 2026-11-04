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
// de `lib/fichaTecnica.js`): o "mexido" e a guarda de saída a veem. F3: as
// fotos (uma galeria por cor, ação imediata) e o frete (a logística de cada
// variação, o ME1 como AVISO e o "Conferir frete pela conta") — lidos do
// retrato; a resposta de cada ação imediata entra por `aplicarResposta`.
// F4: cores e tamanhos. O EIXO (cor, tamanho…) é do produto e mora em
// `estado.produto.eixo`/`eixo_2`; o VALOR (Branca, P) é de cada variação
// (`variacoes[].valor`/`valor_2`). Criar e remover variação são ações
// imediatas que levam junto a ESTRUTURA da tela (`linhasDaEstrutura`) e nada do
// conteúdo; renomear, trocar o tipo e o resto vão no "Salvar". A conferência
// das cores (`conferirCores`) é a régua do servidor, rodada ANTES de gravar.

import { assinaturaDasCaixas, assinaturaDoCusto } from './fichaLayout.js';
import { campoEditaveis, gruposPorCor, linhaParaServidor, refSugerida } from './produtosEstrutura.js';
import { categoriaParaConsulta, deveGravar, eixosEmUso, gruposDoProduto, montarAtributos, valoresIniciais } from './fichaTecnica.js';
import { mesclarSugestoes } from './fichaLayout.js';
import { LIMITE_IMAGENS, LIMITE_MB, avisoDeGrandes, prepararRemessa, quantasCabem, separarGrandes } from './imagensVariacao.js';
import { FOTOS_MINIMAS } from '../Components/Trilha/formatos.js';

export { CHAVE_NOVO_PRODUTO, deveFecharNoEsc } from './trilha.js';

/** O máximo de caixas por variação (o mesmo `MAX_VOLUMES` do normalizador do servidor). */
export const MAX_CAIXAS = 20;

/** O máximo de linhas por pedido, quando o retrato não diz (`ProdutoCadastroService::MAX_GRADE`). */
export const LINHAS_POR_PEDIDO = 200;

/**
 * Os campos do PRODUTO na gaveta (vão na 1ª linha; o eixo e o 2º eixo, F4, vão em TODAS) e os de cada VARIAÇÃO (o
 * valor e o 2º valor da variação são da F4: "Branca", "P").
 */
export const CAMPOS_DO_PRODUTO = ['nome', 'categoria', 'titulo', 'familia', 'ambientes', 'descricao', 'eixo', 'eixo_2'];
export const CAMPOS_DA_VARIACAO = ['codigo', 'custo', 'estoque', 'volumes', 'valor', 'valor_2'];

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

/**
 * A chave de uma variação na tela e no pedido (`chave` da linha, que volta nos erros): `v{id}` da que existe; a da
 * variação NOVA (F4, ainda sem id) é a que a tela deu a ela (`nova-1`…).
 */
export const chaveDaVariacao = (variacao) => (variacao?.id ? `v${variacao.id}` : String(variacao?.chave ?? ''));

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
 * O eixo do produto (`cor`, `tamanho`…, a chave do sistema; '' = não varia) e o valor de cada variação também são
 * texto (F4).
 *
 * @returns {{produto: {nome: string, categoria: ?{id: string, nome: string, caminho_texto: string}, titulo: string,
 *              familia: string, ambientes: string[], descricao: string, eixo: string, eixo_2: string},
 *            variacoes: Array<{id: number, codigo: string, custo: string, estoque: string, volumes: Array<{c: string, l: string, a: string, kg: string}>,
 *              valor: string, valor_2: string}>,
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
            eixo: p.eixo ?? '',
            eixo_2: p.eixo_2 ?? '',
        },
        variacoes: (retrato?.variacoes ?? []).map(estadoDaVariacao),
        ficha: valoresIniciais(retrato?.ficha?.salvos),
    };
}

/** Uma variação do retrato como a gaveta guarda (tudo em texto, como se digita). */
function estadoDaVariacao(v) {
    return {
        id: v.id,
        codigo: v.codigo ?? '',
        custo: custoParaTexto(v.custo),
        estoque: v.estoque === null || v.estoque === undefined ? '' : String(v.estoque),
        volumes: (v.volumes ?? []).map((c) => ({ c: numeroParaTexto(c?.c), l: numeroParaTexto(c?.l), a: numeroParaTexto(c?.a), kg: numeroParaTexto(c?.kg) })),
        valor: v.valor ?? '',
        valor_2: v.valor_2 ?? '',
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
 * A variação que o retrato não tem (a NOVA, F4, pela chave dela) conta com todos os campos.
 */
export function camposMexidos(estado, retrato) {
    const base = estadoDoRetrato(retrato);
    const produto = CAMPOS_DO_PRODUTO.filter((c) => !igual(c, doProduto(estado?.produto, c), base.produto[c]));
    const variacoes = {};
    for (const v of estado?.variacoes ?? []) {
        const b = v.id ? base.variacoes.find((x) => x.id === v.id) : null;
        const campos = b ? CAMPOS_DA_VARIACAO.filter((c) => !igual(c, v[c], b[c])) : [...CAMPOS_DA_VARIACAO];
        if (campos.length) variacoes[v.id || chaveDaVariacao(v)] = campos;
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
 * F4: o EIXO do produto vai em toda linha cuja variação ainda não o tem (a 1ª cor nomeia a variação que já existe:
 * `eixo` e `valor` na linha dela); o valor e o 2º valor de cada uma, quando mudaram. A variação NOVA (sem `id`, com
 * a `chave` que a tela deu) vai com TUDO explícito — eixos, valores, custo e caixas: sem eles o servidor copiaria os
 * da 1ª variação do banco, e numa cor de custo próprio o tamanho novo nasceria com o custo de outra cor.
 *
 * @returns {Array<object>}
 */
export function linhasParaSalvar(estado, retrato) {
    if (!estado || !retrato) return [];
    const base = estadoDoRetrato(retrato);
    const mexidos = camposMexidos(estado, retrato);
    const eixo = texto(estado.produto?.eixo);
    const eixo2 = texto(estado.produto?.eixo_2);
    const linhas = [];

    (estado.variacoes ?? []).forEach((v, i) => {
        if (!v.id) {
            linhas.push(linhaDaVariacaoNova(v, estado, retrato));

            return;
        }
        const b = base.variacoes.find((x) => x.id === v.id) ?? { codigo: '', custo: '', estoque: '', volumes: [], valor: '', valor_2: '' };
        // O eixo que a variação tem gravado: o dela, quando o retrato manda; senão, o do produto (o da 1ª variação).
        const salva = (retrato.variacoes ?? []).find((x) => x.id === v.id) ?? {};
        const eixoAntes = texto(salva.eixo !== undefined ? salva.eixo : base.produto.eixo);
        const eixo2Antes = texto(salva.eixo_2 !== undefined ? salva.eixo_2 : base.produto.eixo_2);
        const mudouEixo = eixo !== eixoAntes || eixo2 !== eixo2Antes;
        const primeira = i === 0;
        const daVariacao = mexidos.variacoes[v.id] ?? [];
        const doProdutoMexido = primeira ? mexidos.produto : [];
        if (!daVariacao.length && !doProdutoMexido.length && !mudouEixo) return;

        // Os campos que esta tela edita, no formato da linha do Portal. O que não mudou fica igual dos dois lados e
        // não vai.
        const linha = {
            id: v.id,
            _k: chaveDaVariacao(v),
            codigo: v.codigo,
            nome: estado.produto.nome,
            titulo: primeira ? estado.produto.titulo : base.produto.titulo,
            familia: primeira ? estado.produto.familia : base.produto.familia,
            custo: v.custo,
            estoque: v.estoque,
            eixo_rotulo: eixo,
            valor: eixo ? v.valor ?? '' : '',
            eixo_2_rotulo: eixo2,
            valor_2: eixo2 ? v.valor_2 ?? '' : '',
        };
        linha._base = campoEditaveis({
            codigo: b.codigo,
            nome: base.produto.nome,
            titulo: base.produto.titulo,
            familia: base.produto.familia,
            custo: b.custo,
            estoque: b.estoque,
            eixo_rotulo: eixoAntes,
            valor: b.valor,
            eixo_2_rotulo: eixo2Antes,
            valor_2: b.valor_2,
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

/**
 * A linha da variação NOVA (F4): pela régua do Portal para "variação nova de produto já gravado" (`linhaParaServidor`
 * com `produto_id`, que manda eixo, custo, caixas e estoque EXPLÍCITOS — vazio vai `null`), sem o `produto_id` (o do
 * endereço vale) e sem a categoria em texto (a linha nova não é do produto).
 */
function linhaDaVariacaoNova(v, estado, retrato) {
    const eixo = texto(estado.produto?.eixo);
    const eixo2 = texto(estado.produto?.eixo_2);
    const saida = linhaParaServidor({
        _k: chaveDaVariacao(v),
        produto_id: retrato.produto?.id ?? true,
        codigo: v.codigo,
        nome: estado.produto.nome,
        eixo_rotulo: eixo,
        valor: eixo ? v.valor ?? '' : '',
        eixo_2_rotulo: eixo2,
        valor_2: eixo2 ? v.valor_2 ?? '' : '',
        custo: v.custo ?? '',
        estoque: v.estoque ?? '',
        volumes_digitados: caixasPreenchidas(v.volumes),
    });
    delete saida.produto_id;
    delete saida.categoria_texto;

    return saida;
}

// ─── Cores e tamanhos (F4) ──────────────────────────────────────────────────
//
// §5.6 do desenho. Um cartão por cor (com cor E tamanho, as variações da cor; com um eixo só, cada variação é um
// cartão), o nome editável, as fotos da cor e, dentro, uma linha por tamanho. "+ Cor" e "+ Tamanho" são AÇÕES
// IMEDIATAS: o pedido leva as variações novas e a ESTRUTURA que está na tela (o eixo do produto e os valores das que
// já existem — a 1ª cor nomeia a variação que já existe, no MESMO pedido) e nada do conteúdo delas (SKU, custo,
// estoque e caixas esperam o "Salvar"). Remover é imediato também (rota 14, uma variação por pedido).
//
// A conferência das cores é a régua do servidor (a do `CoresDoGrupo` do Publicador), rodada na tela ANTES de gravar:
// com duas variações ou mais, toda variação tem valor; nenhum valor (nem par cor e tamanho) se repete, sem caixa nem
// acento; todas usam o mesmo tipo. O que fugisse disso viraria, em silêncio, um anúncio separado.

/** Os tipos de variação do sistema, quando o retrato não manda os dele (`eixos`). */
export const EIXOS_DO_SISTEMA = { cor: 'Cor', tamanho: 'Tamanho', voltagem: 'Voltagem', material: 'Material', sabor: 'Sabor', outro: 'Outro' };

/** Como o texto chama o valor de cada tipo ("a cor", "o tamanho"). */
const ARTIGO_DO_EIXO = { cor: 'a cor', tamanho: 'o tamanho', voltagem: 'a voltagem', material: 'o material', sabor: 'o sabor', outro: 'o nome da variação' };

const comMaiuscula = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

/** O rótulo do tipo de variação ("Cor"), pelos `eixos` do retrato. */
export function rotuloDoEixo(eixo, eixos = null) {
    const chave = texto(eixo);
    if (!chave) return '';

    return texto(eixos?.[chave]) || EIXOS_DO_SISTEMA[chave] || comMaiuscula(chave);
}

/** "a cor", "o tamanho"… (o tipo sem artigo conhecido vira "o valor de X"). */
export function nomeDoEixo(eixo, eixos = null) {
    const chave = texto(eixo);
    if (ARTIGO_DO_EIXO[chave]) return ARTIGO_DO_EIXO[chave];

    return chave ? `o valor de ${rotuloDoEixo(chave, eixos).toLowerCase()}` : 'o nome da variação';
}

/** Como o servidor compara os valores das variações: sem caixa, sem acento e sem espaço sobrando (`ChaveCanonica::texto`). */
export const valorComparavel = (v) => texto(v).replace(/\s+/g, ' ').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * "Este produto varia?" — o modo da gaveta, pelo eixo do produto e quantas variações ele tem:
 * `nao` (uma variação, sem tipo); `cor`; `cor-tamanho`; `outro` (um tipo só, que não é cor); `dois` (dois tipos que
 * não são cor e tamanho — veio de fora; a gaveta mostra como cor e tamanho); `sem-tipo` (várias variações sem tipo).
 */
export function modoDasVariacoes(produto, quantas) {
    const eixo = texto(produto?.eixo);
    const eixo2 = texto(produto?.eixo_2);
    if (!eixo) return Number(quantas) > 1 ? 'sem-tipo' : 'nao';
    if (eixo2) return eixo === 'cor' && eixo2 === 'tamanho' ? 'cor-tamanho' : 'dois';

    return eixo === 'cor' ? 'cor' : 'outro';
}

/**
 * A conferência das cores (a régua do servidor), sobre as variações COMO FICARIAM depois de gravar: cada item é
 * `{chave, eixo, valor, eixo_2, valor_2, grupo?}` (`grupo`: o cartão da cor, para dizer se a repetida é a cor ou o
 * tamanho). Com menos de duas variações não há o que conferir. A 1ª de um valor repetido fica; as outras são
 * marcadas.
 *
 * @returns {{ok: boolean, porChave: Record<string, Record<string, string>>, lista: Array<{chave: string, campo: string, mensagem: string}>}}
 *   `campo`: `valor`, `valor_2` ou `eixo`.
 */
export function conferirCores(itens, { eixos = null } = {}) {
    const todos = (Array.isArray(itens) ? itens : []).filter(Boolean);
    const porChave = {};
    const lista = [];
    const marcar = (chave, campo, mensagem) => {
        if (porChave[chave]?.[campo]) return;
        porChave[chave] = { ...(porChave[chave] ?? {}), [campo]: mensagem };
        lista.push({ chave, campo, mensagem });
    };
    if (todos.length < 2) return { ok: true, porChave, lista };

    const tipo = (it) => `${texto(it.eixo) || 'outro'}${texto(it.eixo_2) ? `+${texto(it.eixo_2)}` : ''}`;
    // O tipo que vale é o da maioria entre as que têm valor (no empate, o que aparece primeiro) — como o servidor.
    const contagem = new Map();
    for (const it of todos) {
        if (texto(it.valor) === '') continue;
        contagem.set(tipo(it), (contagem.get(tipo(it)) ?? 0) + 1);
    }
    let dominante = null;
    for (const [t, n] of contagem) if (dominante === null || n > contagem.get(dominante)) dominante = t;

    const vistos = new Map();
    for (const it of todos) {
        const chave = String(it.chave ?? '');
        const valor = texto(it.valor);
        const eixo2 = texto(it.eixo_2);
        if (valor === '') {
            marcar(chave, 'valor', `Preencha ${nomeDoEixo(it.eixo, eixos)} desta variação: com duas ou mais, cada uma precisa da sua.`);
            continue;
        }
        if (eixo2 && texto(it.valor_2) === '') {
            marcar(chave, 'valor_2', `Preencha ${nomeDoEixo(eixo2, eixos)} desta variação.`);
            continue;
        }
        if (dominante !== null && tipo(it) !== dominante) {
            const rotulos = [rotuloDoEixo(it.eixo, eixos) || 'sem tipo', rotuloDoEixo(eixo2, eixos)].filter(Boolean).join(' e ');
            marcar(chave, 'eixo', `Esta variação usa outro tipo (${rotulos}); todas precisam usar o mesmo.`);
            continue;
        }
        const par = `${valorComparavel(valor)}|${eixo2 ? valorComparavel(it.valor_2) : ''}`;
        const primeiro = vistos.get(par);
        if (!primeiro) {
            vistos.set(par, it);
            continue;
        }
        if (!eixo2) {
            marcar(chave, 'valor', `${comMaiuscula(nomeDoEixo(it.eixo, eixos))} “${valor}” se repete neste produto.`);
        } else if ((it.grupo ?? null) !== null && it.grupo !== primeiro.grupo) {
            marcar(chave, 'valor', `${comMaiuscula(nomeDoEixo(it.eixo, eixos))} “${valor}” já existe neste produto.`);
        } else {
            marcar(chave, 'valor_2', `${comMaiuscula(nomeDoEixo(eixo2, eixos))} “${texto(it.valor_2)}” se repete em ${valor}.`);
        }
    }

    return { ok: lista.length === 0, porChave, lista };
}

/**
 * Os cartões da gaveta: com cor E tamanho, um por COR (as variações agrupadas pelo valor GRAVADO — o cartão não muda
 * de lugar enquanto o nome é digitado); com um tipo só, um por variação. A chave do cartão é a da 1ª variação dele
 * (a mesma da galeria de fotos). As fotos moram na "dona" (a que o servidor marca `dona_da_cor`; senão a 1ª com foto;
 * senão a 1ª).
 *
 * @returns {Array<{chave: string, ids: number[], dona: number, valor: string, linhas: Array<object>,
 *   galeria: {chave: string, variacaoId: number, cor: ?string, tamanhos: string[], fotos: Array, onde: string}}>}
 *   `linhas`: as variações do cartão como estão na tela (com `bling` e `estoque_do_bling` do retrato).
 */
export function cartoesDasVariacoes(retrato, estado) {
    const salvas = retrato?.variacoes ?? [];
    const naTela = new Map((estado?.variacoes ?? []).map((v) => [v.id, v]));
    const eixo = texto(estado?.produto?.eixo ?? retrato?.produto?.eixo);
    const dois = texto(estado?.produto?.eixo_2 ?? retrato?.produto?.eixo_2) !== '';
    const grupos = [];
    const porValor = new Map();
    for (const s of salvas) {
        const k = dois ? valorComparavel(s.valor) : `#${s.id}`;
        let g = porValor.get(k);
        if (!g) {
            g = { chave: `v${s.id}`, salvas: [] };
            porValor.set(k, g);
            grupos.push(g);
        }
        g.salvas.push(s);
    }

    return grupos.map((g) => {
        const dona = g.salvas.find((s) => s.dona_da_cor === true) ?? g.salvas.find((s) => (s.fotos ?? []).length > 0) ?? g.salvas[0];
        const linhas = g.salvas.map((s) => ({ ...(naTela.get(s.id) ?? estadoDaVariacao(s)), bling: !!s.bling, estoque_do_bling: !!s.estoque_do_bling }));
        const cor = texto(g.salvas[0].valor) || null;
        let onde = 'nesta variação';
        if (salvas.length === 1 && !eixo) onde = 'neste produto';
        else if (dois || eixo === 'cor') onde = 'nesta cor';

        return {
            chave: g.chave,
            ids: g.salvas.map((s) => s.id),
            dona: dona.id,
            valor: linhas[0].valor ?? '',
            linhas,
            galeria: {
                chave: g.chave,
                variacaoId: dona.id,
                cor,
                tamanhos: dois ? g.salvas.map((s) => texto(s.valor_2)).filter(Boolean) : [],
                fotos: dona.fotos ?? [],
                onde,
            },
        };
    });
}

/** O cartão de cada variação (`id → chave do cartão`): é o `grupo` da conferência das cores. */
function cartaoDeCada(retrato, estado) {
    const mapa = new Map();
    for (const c of cartoesDasVariacoes(retrato, estado)) for (const id of c.ids) mapa.set(id, c.chave);

    return mapa;
}

/**
 * As variações como ficariam depois de gravar, para a conferência das cores: as da tela (com o eixo do produto) e as
 * novas (`novas`, de "+ Cor"/"+ Tamanho").
 */
export function itensParaConferir(estado, retrato, novas = []) {
    const eixo = texto(estado?.produto?.eixo);
    const eixo2 = texto(estado?.produto?.eixo_2);
    const cartao = cartaoDeCada(retrato, estado);
    const item = (v, chave, grupo) => ({ chave, eixo, valor: eixo ? v.valor : '', eixo_2: eixo2, valor_2: eixo2 ? v.valor_2 : '', grupo });

    return [
        ...(estado?.variacoes ?? []).map((v) => item(v, chaveDaVariacao(v), cartao.get(v.id) ?? chaveDaVariacao(v))),
        ...(novas ?? []).map((n) => item(n, n.chave, n.grupo ?? n.chave)),
    ];
}

/**
 * A conferência das cores de uma GRAVAÇÃO, como o servidor: só barra o problema que a gravação CRIA (o que já estava
 * gravado assim não segura o resto do cadastro). `porChave` traz todos — os de antes também, para a tela mostrar o
 * quadro inteiro; `novos`, os que barram.
 *
 * @returns {{ok: boolean, porChave: Record<string, Record<string, string>>, novos: Array<{chave: string, campo: string, mensagem: string}>}}
 */
export function conferirGravacaoDasCores(estado, retrato, novas = [], { eixos = null } = {}) {
    const depois = conferirCores(itensParaConferir(estado, retrato, novas), { eixos });
    const antes = conferirCores(itensParaConferir(estadoDoRetrato(retrato), retrato), { eixos });
    const novos = depois.lista.filter((p) => !antes.porChave[p.chave]);

    return { ok: novos.length === 0, porChave: depois.porChave, novos };
}

/**
 * Os SKUs sugeridos para `quantas` variações novas, um diferente do outro: o de cada uma é a régua do Portal
 * (`refSugerida`: a Ref da 1ª variação com o menor número livre a partir de 2), contando as já sugeridas. Sem Ref na
 * 1ª, nada a sugerir ('').
 *
 * @returns {string[]}
 */
export function refsSugeridas(variacoes, quantas, { usadas = [] } = {}) {
    const base = [...(variacoes ?? []).map((v) => ({ codigo: v?.codigo ?? '' })), ...(usadas ?? []).map((codigo) => ({ codigo }))];
    const saida = [];
    for (let i = 0; i < Math.max(0, Number(quantas) || 0); i += 1) {
        const sugerida = base.length ? refSugerida([...base, ...saida.filter(Boolean).map((codigo) => ({ codigo }))]) : '';
        saida.push(sugerida);
    }

    return saida;
}

/** Os tamanhos do produto (o 2º valor), na ordem em que aparecem, sem repetir (sem caixa nem acento). */
export function tamanhosDoProduto(estado) {
    const vistos = new Set();
    const saida = [];
    for (const v of estado?.variacoes ?? []) {
        const t = texto(v.valor_2);
        if (!t || vistos.has(valorComparavel(t))) continue;
        vistos.add(valorComparavel(t));
        saida.push(t);
    }

    return saida;
}

/**
 * As variações que "+ Cor" cria: com cor E tamanho, uma por tamanho do produto; com um tipo só, uma. Cada uma leva o
 * SKU (`codigos`, na ordem dos tamanhos) e a `referencia` — a variação GRAVADA de onde vêm custo e caixas (o mesmo
 * tamanho na 1ª cor que o tem; senão a 1ª variação).
 *
 * @returns {Array<{chave: string, valor: string, valor_2: string, codigo: string, referencia: ?number, grupo: string}>}
 */
export function novasDaCor(estado, valor, { codigos = [] } = {}) {
    const vars = estado?.variacoes ?? [];
    const dois = texto(estado?.produto?.eixo_2) !== '';
    const tamanhos = dois ? tamanhosDoProduto(estado) : [''];

    return (tamanhos.length ? tamanhos : ['']).map((t, i) => {
        const mesmo = dois ? vars.find((v) => valorComparavel(v.valor_2) === valorComparavel(t)) : null;

        return {
            chave: `nova-${i + 1}`,
            valor: texto(valor),
            valor_2: t,
            codigo: texto(codigos[i]),
            referencia: (mesmo ?? vars[0])?.id ?? null,
            grupo: 'nova-cor',
        };
    });
}

/**
 * As variações que "+ Tamanho" cria: uma por cor (cartão), com o nome da cor como está na tela. A `referencia` é a 1ª
 * variação da cor (o custo e as caixas da cor).
 *
 * @returns {Array<{chave: string, valor: string, valor_2: string, codigo: string, referencia: number, grupo: string}>}
 */
export function novasDoTamanho(retrato, estado, valor2, { codigos = [] } = {}) {
    return cartoesDasVariacoes(retrato, estado).map((cartao, i) => ({
        chave: `novo-${i + 1}`,
        valor: texto(cartao.valor),
        valor_2: texto(valor2),
        codigo: texto(codigos[i]),
        referencia: cartao.ids[0],
        grupo: cartao.chave,
    }));
}

/**
 * O estado que uma AÇÃO IMEDIATA grava: o conteúdo como está GRAVADO (o que a pessoa mexeu e não salvou não vai), a
 * estrutura como está NA TELA (o eixo do produto, o valor e o 2º valor de cada variação) e as variações `novas`, com o
 * custo e as caixas GRAVADOS da `referencia` de cada uma.
 */
export function estadoDaEstrutura(estado, retrato, novas = []) {
    const base = estadoDoRetrato(retrato);
    const naTela = new Map((estado?.variacoes ?? []).map((v) => [v.id, v]));
    const eixo = texto(estado?.produto?.eixo ?? base.produto.eixo);
    const eixo2 = texto(estado?.produto?.eixo_2 ?? base.produto.eixo_2);

    return {
        produto: { ...base.produto, eixo, eixo_2: eixo2 },
        variacoes: [
            ...base.variacoes.map((b) => {
                const v = naTela.get(b.id);

                return v ? { ...b, valor: v.valor ?? '', valor_2: v.valor_2 ?? '' } : b;
            }),
            ...(novas ?? []).map((n) => {
                const ref = base.variacoes.find((b) => b.id === n.referencia) ?? base.variacoes[0] ?? { custo: '', volumes: [] };

                return { id: null, chave: n.chave, codigo: texto(n.codigo), valor: texto(n.valor), valor_2: texto(n.valor_2), custo: ref.custo, estoque: '', volumes: ref.volumes };
            }),
        ],
        ficha: base.ficha,
    };
}

/** As linhas de uma ação imediata ("+ Cor", "+ Tamanho"): a estrutura da tela e as novas, pela mesma régua do "Salvar". */
export const linhasDaEstrutura = (estado, retrato, novas = []) => linhasParaSalvar(estadoDaEstrutura(estado, retrato, novas), retrato);

/**
 * A estrutura (o tipo de variação ou o valor de alguma) mudou na tela e não foi salva? Ela vai junto na próxima ação
 * imediata e é o que liga a conferência das cores no "Salvar".
 */
export function estruturaMexida(estado, retrato) {
    if (!estado || !retrato) return false;
    const m = camposMexidos(estado, retrato);

    return m.produto.includes('eixo') || m.produto.includes('eixo_2') || Object.values(m.variacoes).some((c) => c.includes('valor') || c.includes('valor_2'));
}

/**
 * Trocar o tipo de variação ("Este produto varia?") — conteúdo da tela, grava no "Salvar":
 * - para "não varia", só com uma variação (o valor dela sai); com mais, `pode: false` e o motivo;
 * - tirar o 2º tipo (o tamanho) com valores pede confirmação; com uma cor de dois tamanhos ou mais, não dá (a cor se
 *   repetiria): `pode: false`;
 * - o resto troca direto (os valores ficam: muitas vezes o tipo é que estava errado).
 *
 * @returns {{pode: boolean, mudou: boolean, motivo?: string, confirmar?: string, estado: object}}
 */
export function trocaDeEixo(estado, novo, { eixos = null } = {}) {
    const vars = estado?.variacoes ?? [];
    const eixo = texto(novo?.eixo);
    const eixo2 = eixo ? texto(novo?.eixo_2) : '';
    const eixoAntes = texto(estado?.produto?.eixo);
    const eixo2Antes = texto(estado?.produto?.eixo_2);
    if (eixo === eixoAntes && eixo2 === eixo2Antes) return { pode: true, mudou: false, estado };
    if (!eixo && vars.length > 1) {
        return { pode: false, mudou: false, motivo: 'Para o produto não variar, ele fica com uma variação só: remova as outras antes.', estado };
    }

    const resultado = {
        ...estado,
        produto: { ...estado.produto, eixo, eixo_2: eixo2 },
        variacoes: vars.map((v) => ({ ...v, valor: eixo ? v.valor : '', valor_2: eixo2 ? v.valor_2 : '' })),
    };
    if (eixo2Antes && !eixo2) {
        const tamanhos = tamanhosDoProduto(estado);
        if (tamanhos.length) {
            const porCor = new Map();
            for (const v of vars) porCor.set(valorComparavel(v.valor), (porCor.get(valorComparavel(v.valor)) ?? 0) + 1);
            if ([...porCor.values()].some((n) => n > 1)) {
                const r1 = rotuloDoEixo(eixoAntes, eixos).toLowerCase();
                const r2 = rotuloDoEixo(eixo2Antes, eixos).toLowerCase();

                return {
                    pode: false,
                    mudou: false,
                    motivo: `Há ${r1} com mais de uma variação de ${r2}: remova as que sobram antes (fica uma por ${r1}).`,
                    estado,
                };
            }

            return {
                pode: true,
                mudou: true,
                confirmar: `${comMaiuscula(nomeDoEixo(eixo2Antes, eixos))} sai de todas as variações (${emLista(tamanhos)}) quando você salvar.`,
                estado: resultado,
            };
        }
    }

    return { pode: true, mudou: true, estado: resultado };
}

/** Custo e caixas iguais em todas as variações? (é o padrão "iguais em todas as cores"; "89" e "89,00" são o mesmo) */
export function custoECaixasIguais(variacoes) {
    const vars = variacoes ?? [];
    if (vars.length < 2) return true;

    return new Set(vars.map((v) => assinaturaDoCusto(v))).size === 1 && new Set(vars.map((v) => assinaturaDasCaixas(v.volumes))).size === 1;
}

/** "Iguais em todas as cores": o custo e as caixas de uma variação (`deId`, ou a 1ª) em todas. */
export function comCustoECaixasIguais(estado, deId = null) {
    const vars = estado?.variacoes ?? [];
    const fonte = vars.find((v) => v.id === deId) ?? vars[0];
    if (!fonte) return estado;

    return { ...estado, variacoes: vars.map((v) => ({ ...v, custo: fonte.custo, volumes: (fonte.volumes ?? []).map((c) => ({ ...c })) })) };
}

/**
 * "Diferente por cor", com cor E tamanho: o custo e as caixas são da COR — cada tamanho leva os da 1ª variação do
 * cartão (o tamanho novo, que nasce com os GRAVADOS da cor, passa a ter os que estão na tela).
 */
export function custoIgualEmCadaCor(estado, retrato) {
    if (!estado) return estado;
    const deCada = new Map();
    for (const cartao of cartoesDasVariacoes(retrato, estado)) {
        const fonte = (estado.variacoes ?? []).find((v) => v.id === cartao.ids[0]);
        if (fonte) for (const id of cartao.ids) deCada.set(id, fonte);
    }

    return {
        ...estado,
        variacoes: (estado.variacoes ?? []).map((v) => {
            const fonte = deCada.get(v.id);

            return fonte && fonte !== v ? { ...v, custo: fonte.custo, volumes: (fonte.volumes ?? []).map((c) => ({ ...c })) } : v;
        }),
    };
}

/** O "Salvar" parou na conferência das cores (na tela, antes de gravar). */
export const MENSAGEM_DAS_CORES = 'Confira as variações marcadas: cada uma precisa de um nome próprio, sem repetir.';

/**
 * Os erros de um 422 das linhas pelo lugar na gaveta (a conferência das cores do servidor, F4): a chave do Laravel
 * `linhas.{i}.{campo}` vira a `chave` que a tela mandou naquela linha (`linhas`, o corpo do pedido); a da variação
 * que nenhuma linha tocou, `variacoes.{id}.{campo}`, vira `v{id}`; a que já vem como `{chave}.{campo}` também
 * serve. Os `erros[]` do mesmo 422 (o formato dos erros de linha: `chave` ou `variacao_id`, `campos`) somam. O que
 * não tem lugar vai em `soltas`. Sem 422, null.
 *
 * @returns {?{porChave: Record<string, Record<string, string>>, soltas: string[]}}
 */
export function errosDasLinhasNo422(falha, linhas = []) {
    if (falha?.response?.status !== 422) return null;
    const dados = falha.response.data ?? {};
    const porChave = {};
    const soltas = [];
    const chaves = new Set((linhas ?? []).map((l) => l?.chave).filter(Boolean));
    const por = (chave, campo, m) => {
        const lugar = campo === 'categoria_ml_id' ? 'categoria' : campo;
        porChave[chave] = { ...(porChave[chave] ?? {}), [lugar]: m };
    };
    for (const [k, mensagens] of Object.entries(dados.errors ?? {})) {
        const m = [mensagens].flat().map((x) => (typeof x === 'string' ? x.trim() : '')).find(Boolean);
        if (!m) continue;
        const pelaPosicao = k.match(/^linhas\.(\d+)\.(\w+)$/);
        const pelaVariacao = k.match(/^variacoes\.(\d+)\.(\w+)$/);
        const pelaChave = k.match(/^([^.]+)\.(\w+)$/);
        if (pelaPosicao && linhas?.[Number(pelaPosicao[1])]?.chave) por(linhas[Number(pelaPosicao[1])].chave, pelaPosicao[2], m);
        else if (pelaVariacao) por(`v${pelaVariacao[1]}`, pelaVariacao[2], m);
        else if (pelaChave && chaves.has(pelaChave[1])) por(pelaChave[1], pelaChave[2], m);
        else soltas.push(m);
    }
    // Os mesmos erros como os de linha do Portal (`erros: [{chave|variacao_id, campos}]`): somam (a mensagem igual não
    // se repete). O que já tem lugar não fica também solto.
    const deLinha = errosPorCampo(dados.erros ?? [], [...CAMPOS_DO_PRODUTO, ...CAMPOS_DA_VARIACAO]);
    for (const [chave, campos] of Object.entries(deLinha.porChave)) porChave[chave] = { ...(porChave[chave] ?? {}), ...campos };
    const comLugar = new Set(Object.values(porChave).flatMap((campos) => Object.values(campos)));
    soltas.push(...deLinha.soltas);

    return { porChave, soltas: [...new Set(soltas)].filter((m) => !comLugar.has(m)) };
}

/** A cor nova num produto JÁ mapeado: o combo é por cor e não nasce sozinho (D15) — a gaveta pede para mapear de novo. */
export const AVISO_DA_COR_NOVA_NO_MAPEADO = 'Cor nova salva. Este produto já foi mapeado: mapeie de novo para a cor ganhar os combos.';

/** O mesmo, para o tamanho (ou outra variação) novo. */
export const AVISO_DA_VARIACAO_NOVA_NO_MAPEADO = 'Variação nova salva. Este produto já foi mapeado: mapeie de novo para ela ganhar os combos.';

/**
 * O aviso depois de "+ Cor"/"+ Tamanho": o servidor diz (`mapear_de_novo`, quando criou variação num produto já
 * mapeado). Sem a chave (resposta antiga), vale o retrato: variação criada (`totais.criadas`) e `situacao.mapeado_em`.
 */
export function avisoDaCorNova(resposta, { cor = true } = {}) {
    const mapear = typeof resposta?.mapear_de_novo === 'boolean'
        ? resposta.mapear_de_novo
        : Number(resposta?.totais?.criadas ?? 0) > 0 && !!resposta?.situacao?.mapeado_em;
    if (!mapear) return null;

    return cor ? AVISO_DA_COR_NOVA_NO_MAPEADO : AVISO_DA_VARIACAO_NOVA_NO_MAPEADO;
}

// ─── Excluir o produto (F4, rota 15) ────────────────────────────────────────

const TIPO_DA_MONTADA = { combo: 'Combo', kit: 'Kit', combit: 'Combit' };

const contar = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/**
 * Os textos dos dois passos de "Excluir produto", a partir da PRÉVIA do servidor (o que sai junto: as ofertas
 * montadas que usam o produto — combos, kits, combits). Nada é calculado aqui.
 *
 * @returns {{frase: string, montadas: Array<{id: number, tipo: string, sku: string, nome: string}>, fraseDasMontadas: ?string,
 *   emUso: ?string, botao: string}}
 */
export function textosDaExclusao(previa) {
    const produto = (previa?.produtos ?? [])[0] ?? null;
    const variacoes = Number(previa?.totais?.variacoes ?? produto?.variacoes ?? 0) || 0;
    const montadas = (previa?.montadas ?? []).map((m) => ({ id: m.id, tipo: TIPO_DA_MONTADA[m.fase] ?? 'Oferta', sku: texto(m.sku), nome: texto(m.nome) }));
    const n = montadas.length;

    return {
        frase: `O produto sai do cadastro com ${contar(variacoes, 'variação', 'variações')}, as fotos e a ficha técnica. Não dá para desfazer.`,
        montadas,
        fraseDasMontadas: n ? `${n === 1 ? '1 oferta montada usa' : `${n} ofertas montadas usam`} este produto e ${n === 1 ? 'sai' : 'saem'} junto:` : null,
        emUso: Number(previa?.totais?.em_uso ?? 0) > 0 ? 'Parte disto já está ligada a anúncio ou a preparo: o que estava ligado fica solto.' : null,
        botao: n ? `Excluir o produto e ${n === 1 ? 'a oferta' : `as ${n} ofertas`}` : 'Excluir produto',
    };
}

/** As ofertas montadas que a pessoa VIU na prévia: o servidor só exclui se forem exatamente essas. */
export const montadasDaPrevia = (previa) => (previa?.montadas ?? []).map((m) => m.id).filter((id) => id !== null && id !== undefined);

/** O que sai junto mudou entre a prévia e a confirmação (422 em `montadas`): a gaveta mostra a prévia de novo. */
export const exclusaoMudou = (falha) => falha?.response?.status === 422 && !!falha.response.data?.errors?.montadas;

/** A resposta da rota 3 já traz o retrato novo? (o controller da trilha devolve o retrato junto do resultado) */
export const temRetrato = (resposta) => !!resposta && typeof resposta.produto === 'object' && resposta.produto !== null && Array.isArray(resposta.variacoes);

// ─── A aba velha (F6) ───────────────────────────────────────────────────────
//
// O servidor responde 409 por DOIS motivos, e a gaveta os trata diferente:
// - a TRAVA (`{travado: true, motivo}`): o produto andou — a gaveta vira só leitura;
// - a VERSÃO (`{versao_atual, message}`, SEM `travado`): o produto mudou depois que
//   a gaveta abriu — o que a pessoa digitou fica na tela e o rodapé oferece
//   [Recarregar]. Nunca vira só leitura por isso.

/** O texto do 409 da versão quando o servidor não manda o dele. */
export const MENSAGEM_DA_VERSAO = 'Este produto mudou depois que você abriu (outra pessoa salvou nele). Recarregue para ver o que mudou.';

/** O 409 da TRAVA (pergunta 1): o produto andou. */
export const travadoNo409 = (falha) => falha?.response?.status === 409 && !!falha.response.data?.travado;

/** O 409 da VERSÃO (F6): a gaveta é de antes de outra gravação no produto. É o 409 sem `travado`. */
export const conflitoDeVersao = (falha) => falha?.response?.status === 409 && !falha.response.data?.travado;

/** O texto do conflito para o rodapé: o do servidor, ou o nosso. */
export function textoDoConflito(falha) {
    const doServidor = falha?.response?.data?.message;

    return typeof doServidor === 'string' && doServidor.trim() ? doServidor.trim() : MENSAGEM_DA_VERSAO;
}

/**
 * A versão que a gaveta passa a conhecer depois de uma resposta com retrato.
 * - Escrita CONFERIDA (mandou a versão e passou — linhas, ficha, remover cor): a da resposta, que é a desta gravação.
 * - Escrita que o servidor NÃO confere (fotos, ordem das fotos, frete): elas não mudam a versão. Se a da resposta é
 *   outra, o produto mudou POR FORA depois que a gaveta abriu (outra aba, o Portal, a planilha, a importação…):
 *   - com algo MEXIDO na gaveta: fica a de antes e `mudou: true` — a gaveta avisa já (adotá-la deixaria o próximo
 *     "Salvar" passar por cima do que foi gravado por fora);
 *   - sem NADA mexido (R1, 04/11/2026): não há o que perder — a resposta já é o retrato de agora e a gaveta passa a
 *     mostrá-lo, como o [Recarregar] faria. Adota a nova, sem aviso (`porFora: true`). Antes, o envio de foto
 *     acusava "Recarregue…" mesmo com a gaveta toda salva: um alarme sem risco nenhum.
 *   `mexido` vale `true` quando não se diz (o seguro: avisar).
 * Sem versão conhecida (o retrato ainda não tinha), fica a da resposta.
 *
 * @returns {{versao: ?string, mudou: boolean, porFora: boolean}}
 */
export function versaoDepoisDe(conhecida, resposta, { conferida = false, mexido = true } = {}) {
    const nova = typeof resposta?.versao === 'string' && resposta.versao ? resposta.versao : null;
    if (!nova) return { versao: conhecida ?? null, mudou: false, porFora: false };
    if (conferida || !conhecida || nova === conhecida) return { versao: nova, mudou: false, porFora: false };
    if (!mexido) return { versao: nova, mudou: false, porFora: true };

    return { versao: conhecida, mudou: true, porFora: false };
}

/** R1: a nota do rodapé quando a gaveta (sem nada mexido) passou a mostrar o que outra gravação deixou no produto. */
export const NOTA_DO_RETRATO_DE_FORA = 'Este produto tinha mudado em outro lugar: a gaveta já mostra o que está salvo agora.';

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
        // F4: a variação que nenhuma linha tocou (a conferência das cores olha o produto inteiro) vem só com o id.
        const chave = erro?.chave || (erro?.variacao_id ? `v${erro.variacao_id}` : null);
        if (chave && conhecidos.length) {
            porChave[chave] = { ...(porChave[chave] ?? {}), ...Object.fromEntries(conhecidos) };
        } else if (conhecidos.length) {
            soltas.push(...conhecidos.map(([, m]) => m));
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

// ─── Fotos (F3) ─────────────────────────────────────────────────────────────
//
// As fotos são AÇÃO IMEDIATA (enviar e excluir gravam na hora, sem o "Salvar"): elas não moram no estado da gaveta,
// e sim no retrato (`variacoes[].fotos`, com as URLs da rota da equipe). A resposta de cada ação é o retrato novo,
// juntado ao estado por `aplicarResposta` — o que a pessoa digitou e não salvou fica.

/**
 * As galerias da gaveta, uma por COR (§5.7 do desenho), na ordem das variações:
 * - com cor E tamanho, as fotos são da cor e moram numa variação dela, a "dona" (`gruposPorCor`, a régua do Portal:
 *   a 1ª com foto, senão a 1ª — a mesma do `dona_da_cor` do servidor, que vale quando vem);
 * - com um eixo só (ou nenhum), cada variação guarda as suas: uma galeria por variação;
 * - sem variação (o produto que não varia), uma galeria só.
 *
 * `onde` é como os textos da galeria chamam o lugar ("nesta cor", "neste produto").
 *
 * @returns {Array<{chave: string, variacaoId: number, cor: ?string, tamanhos: string[], fotos: Array<{id: number,
 *   url: string, url_original?: string, nome_original?: string}>, onde: string}>}
 */
export function fotosPorCor(retrato) {
    const variacoes = (retrato?.variacoes ?? []).map((v) => ({ ...v, _k: chaveDaVariacao(v), imagens: v.fotos ?? [] }));
    if (!variacoes.length) return [];
    const doisEixos = texto(retrato?.produto?.eixo_2) !== '' || variacoes.some((v) => texto(v.valor_2) !== '');
    const grupos = doisEixos
        ? gruposPorCor(variacoes)
        : variacoes.map((v) => ({ chave: v._k, valor: v.valor ?? '', vars: [v], dona: v }));
    const eixo = texto(retrato?.produto?.eixo).toLowerCase();

    return grupos.map((g) => {
        const dona = g.vars.find((v) => v.dona_da_cor === true) ?? g.dona;
        const cor = texto(g.valor) || null;
        let onde = 'nesta variação';
        if (!cor && variacoes.length === 1) onde = 'neste produto';
        else if (doisEixos || eixo === 'cor') onde = 'nesta cor';

        return {
            chave: g.chave,
            variacaoId: dona.id,
            cor,
            tamanhos: doisEixos ? g.vars.map((v) => texto(v.valor_2)).filter(Boolean) : [],
            fotos: dona.fotos ?? [],
            onde,
        };
    });
}

/**
 * O contador da galeria: abaixo do mínimo da Lista (3 em cada cor), "2 de 3" em âmbar; no mínimo ou acima,
 * "3 fotos" em verde. O servidor é quem decide a falta; isto só lê o que a galeria tem.
 *
 * @returns {{texto: string, tom: 'warn'|'ok', completo: boolean}}
 */
export function contadorDeFotos(quantas, minimo = FOTOS_MINIMAS) {
    const n = Math.max(0, Number(quantas) || 0);
    if (n < minimo) return { texto: `${n} de ${minimo}`, tom: 'warn', completo: false };

    return { texto: `${n} ${n === 1 ? 'foto' : 'fotos'}`, tom: 'ok', completo: true };
}

/** A foto grande ("Ver maior"): a `url_original` do retrato; sem ela, a mesma rota com `?original=1`. */
export function urlOriginalDaFoto(foto) {
    if (texto(foto?.url_original)) return texto(foto.url_original);
    const url = texto(foto?.url);
    if (!url) return null;

    return `${url}${url.includes('?') ? '&' : '?'}original=1`;
}

/**
 * A remessa de fotos de uma galeria, conferida ANTES do envio (o servidor é tudo ou nada; a tela avisa antes):
 * - acima do teto de tamanho (`mb`, o do retrato) não vai — `separarGrandes`, com o aviso de cada uma;
 * - formato fora de JPG/PNG/WebP não vai — `prepararRemessa`, sem o teto de quantidade (este vai abaixo, no texto da
 *   trilha: a galeria é da cor, não da "variação");
 * - mais do que cabe (`quantasCabem`): NADA vai, e o aviso diz quantas ainda cabem.
 * Os arquivos que servem vão juntos, numa remessa só, com os avisos dos que ficaram de fora.
 *
 * @param {Array<{name: string, size?: number}>|FileList} escolhidos
 * @returns {{arquivos: Array, avisos: string[]}}  `arquivos` vazio = não envia
 */
export function remessaDeFotos(escolhidos, total, { limite = LIMITE_IMAGENS, mb = LIMITE_MB, onde = 'nesta cor' } = {}) {
    const lista = Array.from(escolhidos ?? []);
    if (!lista.length) return { arquivos: [], avisos: [] };
    const tetoMb = Number(mb) > 0 ? Number(mb) : LIMITE_MB;
    const { cabem: noTamanho, grandes } = separarGrandes(lista, tetoMb * 1024 * 1024);
    const { arquivos: noFormato, avisos } = prepararRemessa(noTamanho, 0, Number.POSITIVE_INFINITY);
    const deGrandes = avisoDeGrandes(grandes, tetoMb);
    if (deGrandes) avisos.push(deGrandes);
    if (!noFormato.length) return { arquivos: [], avisos };

    const cabem = quantasCabem(total, limite);
    if (noFormato.length > cabem) {
        avisos.push(cabem === 0
            ? `Já há ${limite} fotos ${onde}, o máximo. Exclua alguma para enviar outra.`
            : `Cabem mais ${cabem} ${cabem === 1 ? 'foto' : 'fotos'} ${onde}. Escolha menos e envie de novo.`);

        return { arquivos: [], avisos };
    }

    return { arquivos: noFormato, avisos };
}

/** "3 fotos enviadas." / "1 foto enviada." — o que a galeria diz depois do envio. */
export const textoDoEnvio = (n) => (Number(n) === 1 ? '1 foto enviada.' : `${Number(n) || 0} fotos enviadas.`);

// ─── A ordem das fotos (R6, 04/11/2026) ─────────────────────────────────────
//
// Arrastar ou as setas mudam a ordem de UMA cor; a 1ª é a capa. O pedido leva os ids de TODAS as fotos da galeria
// (o servidor recusa a lista que não bate com a de agora). Funções puras: a galeria só desenha.

/**
 * Os ids depois de pôr a foto `id` na posição `para` (0 = capa; fora da faixa vai para a ponta). Null quando nada muda
 * (o id não está na galeria, a posição não é inteira, ou a foto já estava ali) — não há pedido a fazer.
 *
 * @returns {?number[]}
 */
export function ordemMovida(fotos, id, para) {
    const ids = (fotos ?? []).map((f) => f?.id);
    const de = ids.indexOf(id);
    if (de < 0 || !Number.isInteger(para)) return null;
    const alvo = Math.min(Math.max(para, 0), ids.length - 1);
    if (alvo === de) return null;
    const nova = ids.slice();
    nova.splice(de, 1);
    nova.splice(alvo, 0, id);

    return nova;
}

/**
 * As fotos na ordem `ordem` (os ids) — o que a galeria mostra enquanto o pedido corre. A ordem que não é exatamente a
 * desta galeria (uma foto entrou ou saiu no meio do caminho) não vale: ficam as fotos como vieram.
 */
export function fotosNaOrdem(fotos, ordem) {
    const lista = fotos ?? [];
    if (!Array.isArray(ordem) || ordem.length !== lista.length) return lista;
    const porId = new Map(lista.map((f) => [f?.id, f]));
    const saida = ordem.map((id) => porId.get(id));

    return saida.every(Boolean) && new Set(ordem).size === ordem.length ? saida : lista;
}

/** O que a galeria diz depois de gravar a ordem: a capa nova merece ser dita. */
export function textoDaOrdem(antes, ordem) {
    const capaAntes = (antes ?? [])[0]?.id;

    return Array.isArray(ordem) && ordem.length && ordem[0] !== capaAntes ? 'Ordem salva: a capa mudou.' : 'Ordem salva.';
}

// ─── Frete (F3) ─────────────────────────────────────────────────────────────

/**
 * A logística de cada variação, como o retrato diz (ME2, Full, ME1 ou "pendente" sem caixa salva) — nada calculado
 * aqui. Com a trilha de trabalho o ME1 NÃO segura o produto: é aviso (pergunta 3 do desenho).
 *
 * @returns {Array<{id: number, nome: string, codigo: string, logistica: 'me2'|'me2_full'|'me1'|'pendente'}>}
 */
export function logisticaDasVariacoes(retrato) {
    const conhecidas = ['me2', 'me2_full', 'me1'];

    return (retrato?.variacoes ?? []).map((v) => ({
        id: v.id,
        nome: [v.valor, v.valor_2].map(texto).filter(Boolean).join(' · '),
        codigo: texto(v.codigo),
        logistica: conhecidas.includes(v.logistica) ? v.logistica : 'pendente',
    }));
}

/** Alguma variação só vai por transportadora (ME1)? É o aviso "vai por ME1 (transportadora)". */
export const vaiPorMe1 = (retrato) => logisticaDasVariacoes(retrato).some((v) => v.logistica === 'me1');

/**
 * A embalagem está mexida (não salva) em alguma variação? O "Conferir frete pela conta" usa as caixas SALVAS: com
 * caixa mexida, conferir agora diria o frete das caixas de antes.
 */
export function embalagemMexida(estado, retrato) {
    if (!estado || !retrato) return false;
    const m = camposMexidos(estado, retrato);

    return Object.values(m.variacoes).some((campos) => campos.includes('volumes'));
}

// ─── Avisos da gaveta ───────────────────────────────────────────────────────

/**
 * A onda de um item deste produto já começou e o agente ainda não mexeu: salvar faz o agente preparar com o que
 * estiver aqui. O nome da onda é livre ("Onda 1 · simples", "Black Friday").
 */
export function avisoDaOndaComecada(onda) {
    if (!onda?.nome) return null;
    const nome = texto(onda.nome);
    const qual = /^onda\b/i.test(nome) ? `A ${nome}` : `A onda “${nome}”`;

    return `${qual} já começou: ao salvar, o agente prepara este produto com o que estiver aqui (usa IA).`;
}
