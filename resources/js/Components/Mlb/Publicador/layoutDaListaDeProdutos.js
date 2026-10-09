// ═══════════════════════════════════════════════════════════════════════════
// Layout v2 da aba Produtos do Publicador (quick 261009-prd) — as funções
// PURAS da grade: colunas por breakpoint, altura de linha, miniatura de
// iniciais, ação principal por situação, ordenação do cliente e densidade.
//
// ⚠️ POR QUE ESTE ARQUIVO EXISTE, e não só os exports da página:
// `LinhaDeProduto.jsx` e `PainelDoProdutoLateral.jsx` precisam destas
// funções, e eles são importados por `Pages/Mlb/Publicador/Produtos.jsx`.
// Deixá-las na página fecharia um CICLO de import (página → componente →
// página) — e ciclo + Rollup é exatamente a família de bug que já apagou
// variável de escopo no bundle de produção deste projeto
// (feedback_rollup_map_scope_bug.md). A página REEXPORTA tudo daqui, então
// `import { acaoPrincipal } from '.../Produtos.jsx'` continua valendo.
//
// ⚠️ Toda função aqui é TOTAL: campo em formato inesperado (objeto, array,
// nulo, ausente) devolve um default seguro e NUNCA estoura. Foi um objeto do
// presenter renderizado cru que derrubou a árvore React inteira em 07/10
// ("Objects are not valid as a React child").
// ═══════════════════════════════════════════════════════════════════════════

// `LABEL_TIER_HISTORICO` é o par Clássico/Premium que o resto do módulo já
// usa — sem enum compartilhado entre PHP e JS, convenção do projeto. O módulo
// é folha (nenhum import próprio), então não arrasta nada para o bundle.
import { LABEL_TIER_HISTORICO } from '@/Pages/Mlb/anuncioHistoricoUtils';

/** O breakpoint da largura do CONTEÚDO (não da janela) que a referência usa. */
export const LARGURA_DE_CORTE = 1100;

/**
 * As duas grades de colunas, transcritas da referência
 * (`design_handoff_publicador/referencias/Publicador - Produtos v2.dc.html`):
 * seleção · Produto · Fase · Situação · Anúncios · Atualizado · ações.
 * No estreito a coluna "Atualizado" SAI (vai para o painel lateral).
 */
export const COLUNAS_LARGO = '44px minmax(240px,1fr) 132px 172px 120px 92px 152px';
export const COLUNAS_ESTREITO = '36px minmax(200px,1fr) 104px 148px 96px 140px';

/** A chave do `localStorage` combinada na spec. */
export const CHAVE_DA_DENSIDADE = 'publicador.produtos.densidade';

/** As duas densidades. ⚠️ Whitelist por ARRAY: `hasOwnProperty` deixaria `__proto__` passar. */
export const DENSIDADES = ['confortavel', 'compacto'];

/**
 * A ordem default por situação (a da referência): quem precisa de ação vem
 * primeiro, erro no topo de tudo, publicado no fim.
 */
export const ORDEM_DA_SITUACAO = {
    erro: -1,
    conferir: 0,
    rascunho: 1,
    pronto: 2,
    publicando: 3,
    parcial: 4,
    publicado: 5,
};

/** As três colunas ordenáveis do cabeçalho. */
export const COLUNAS_ORDENAVEIS = ['produto', 'situacao', 'atualizado'];

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Texto do servidor em forma segura; objeto/array/número viram `''`. */
const stringSegura = (valor) => (typeof valor === 'string' ? valor : '');

/** A largura em número; ausente ou lixo cai no 1400 que a referência usa de default. */
const larguraSegura = (largura) => (typeof largura === 'number' && Number.isFinite(largura) ? largura : 1400);

/**
 * A miniatura da linha: as INICIAIS do nome, não a foto.
 *
 * ⚠️ Decisão do usuário (09/10/2026): a linha de `produtosParaTela` não traz
 * imagem nenhuma, e a própria referência do handoff também não usa `<img>` —
 * ela desenha as iniciais num quadrado. Foto real exigiria um campo novo no
 * servidor (a capa do primeiro rascunho), o que está FORA do escopo desta
 * tarefa de UI.
 *
 * Regra: as duas primeiras palavras com MAIS de 2 letras, em maiúsculas. Sem
 * nenhuma palavra longa, as duas primeiras letras da primeira palavra.
 *
 * @param {unknown} nome
 * @returns {string} nunca vazio: cai em '—'
 */
export function iniciaisDoNome(nome) {
    const limpo = stringSegura(nome).trim();
    if (limpo === '') return '—';

    const palavras = limpo.split(/\s+/).filter((p) => p !== '');
    const longas = palavras.filter((p) => p.length > 2).slice(0, 2);
    if (longas.length > 0) {
        return longas.map((p) => p[0]).join('').toUpperCase();
    }

    const primeira = palavras[0] ?? '';

    return primeira === '' ? '—' : primeira.slice(0, 2).toUpperCase();
}

/**
 * O `grid-template-columns` do breakpoint. O corte é INCLUSIVO em 1100px.
 * @param {unknown} largura largura do conteúdo, em px
 */
export function colunasDaLargura(largura) {
    return larguraSegura(largura) >= LARGURA_DE_CORTE ? COLUNAS_LARGO : COLUNAS_ESTREITO;
}

/** A altura FIXA da linha: nenhuma célula pode quebrar e empurrar a linha. */
export function alturaDaLinha(densidade) {
    return densidade === 'compacto' ? 52 : 64;
}

/** O lado do quadrado da miniatura — no compacto ela diminui, nunca desaparece. */
export function tamanhoDaMiniatura(densidade) {
    return densidade === 'compacto' ? 32 : 40;
}

/** Miniatura só com a chave ligada E no breakpoint largo (`thumbsOn` da referência). */
export function miniaturasVisiveis(mostrar, largura) {
    return mostrar === true && larguraSegura(largura) >= LARGURA_DE_CORTE;
}

// A tabela de ação principal, por `status.chave`.
//
// ⚠️ Neste módulo `status.chave === 'rascunho'` significa "NÃO EXISTE
// rascunho" (`EditorRascunhoService::prontidao()` devolve
// `['chave' => 'rascunho', 'rotulo' => 'a preencher']` justamente quando não
// há rascunho). Não existe chave "sem rascunho" — por isso 'rascunho' é
// "Começar rascunho" e 'conferir' (em preenchimento) é "Continuar". A tabela
// da spec dizia o contrário; o usuário aprovou esta correção em 09/10/2026.
const ACOES = {
    rascunho: { rotulo: 'Começar rascunho', destino: 'editor', estilo: 'secundario' },
    conferir: { rotulo: 'Continuar', destino: 'editor', estilo: 'secundario' },
    pronto: { rotulo: 'Publicar', destino: 'editor', estilo: 'primario' },
    publicando: { rotulo: 'Acompanhar', destino: 'painel', estilo: 'secundario' },
    publicado: { rotulo: 'Abrir', destino: 'produto', estilo: 'secundario' },
    parcial: { rotulo: 'Ver erro', destino: 'painel', estilo: 'erro' },
    erro: { rotulo: 'Ver erro', destino: 'painel', estilo: 'erro' },
};

/** O default de chave desconhecida: abrir o produto é sempre seguro. */
const ACAO_PADRAO = { rotulo: 'Abrir produto', destino: 'produto', estilo: 'secundario' };

/**
 * O ÚNICO botão contextual da linha (a spec trocava duas ações idênticas por
 * este). `destino` é 'editor' | 'painel' | 'produto' — quem navega é a página.
 *
 * @param {unknown} status o `status` de `produtosParaTela` ({chave, rotulo, faltam})
 * @returns {{rotulo: string, destino: string, estilo: string}}
 */
export function acaoPrincipal(status) {
    const chave = objetoSeguro(status).chave;
    const achada = typeof chave === 'string' && Object.prototype.hasOwnProperty.call(ACOES, chave)
        ? ACOES[chave]
        : null;

    return { ...(achada ?? ACAO_PADRAO) };
}

/** O peso da situação na ordenação; chave desconhecida vai para o fim. */
const pesoDaSituacao = (produto) => {
    const chave = objetoSeguro(objetoSeguro(produto).status).chave;

    return typeof chave === 'string' && Object.prototype.hasOwnProperty.call(ORDEM_DA_SITUACAO, chave)
        ? ORDEM_DA_SITUACAO[chave]
        : 99;
};

/** Quantas pendências a linha tem (desempate da situação, como na referência). */
const pendenciasDe = (produto) => {
    const faltam = objetoSeguro(objetoSeguro(produto).status).faltam;

    return typeof faltam === 'number' && Number.isFinite(faltam) ? faltam : 0;
};

/** O instante de "Atualizado"; data ausente ou inválida vira 0 (o mais antigo). */
const instanteDe = (produto) => {
    const iso = objetoSeguro(produto).atualizado_em;
    if (typeof iso !== 'string' || iso === '') return 0;
    const t = new Date(iso).getTime();

    return Number.isNaN(t) ? 0 : t;
};

/**
 * A ordenação do CLIENTE nos cabeçalhos Produto / Situação / Atualizado.
 *
 * ⚠️ Recebe só as linhas de TOPO: os kits continuam logo abaixo do base
 * (quem remonta a família é a página). ESTÁVEL de propósito — empate preserva
 * a ordem de entrada, nos dois sentidos, para a lista não "dançar" a cada
 * clique.
 *
 * `direcao` 1 é o sentido natural de cada coluna: nome crescente, situação da
 * que precisa de ação para a publicada, e "Atualizado" do mais recente para o
 * mais antigo.
 *
 * @param {Array<{produto: Object, recuado: boolean}>} linhasDeTopo
 * @param {unknown} coluna 'produto' | 'situacao' | 'atualizado'
 * @param {unknown} direcao 1 | -1
 */
export function ordenarTopo(linhasDeTopo, coluna, direcao) {
    const lista = Array.isArray(linhasDeTopo) ? [...linhasDeTopo] : [];
    if (!COLUNAS_ORDENAVEIS.includes(coluna)) return lista;

    const sentido = direcao === -1 ? -1 : 1;
    // Decora com o índice: é o que garante a estabilidade mesmo quando o
    // `Array.prototype.sort` do runtime não a garantisse.
    const decorada = lista.map((linha, indice) => ({ linha, indice, produto: objetoSeguro(linha).produto }));

    decorada.sort((a, b) => {
        let bruto = 0;
        if (coluna === 'produto') {
            bruto = stringSegura(objetoSeguro(a.produto).nome)
                .localeCompare(stringSegura(objetoSeguro(b.produto).nome), 'pt-BR');
        } else if (coluna === 'atualizado') {
            bruto = instanteDe(b.produto) - instanteDe(a.produto);
        } else {
            bruto = (pesoDaSituacao(a.produto) - pesoDaSituacao(b.produto))
                || (pendenciasDe(a.produto) - pendenciasDe(b.produto));
        }

        return bruto === 0 ? a.indice - b.indice : bruto * sentido;
    });

    return decorada.map((d) => d.linha);
}

/**
 * A linha 1 da célula Fase: "Fase 1" no base, "Fase 2 · Kit N" no kit — o
 * texto da referência. O `rotulo_fase` do servidor (fonte ÚNICA do rótulo,
 * ver `ProgramasPublicadorService::rotuloFase`) entra como o pedaço "Kit N" e
 * vai também no `title`, para a mesma linha nunca discordar da tela do
 * Produto.
 *
 * @param {unknown} produto
 * @returns {{texto: string, titulo: string, ehKit: boolean}}
 */
export function textoDaFase(produto) {
    const p = objetoSeguro(produto);
    const fase = typeof p.fase === 'number' && Number.isFinite(p.fase) ? p.fase : 1;
    const rotulo = stringSegura(p.rotulo_fase);
    const ehKit = p.eh_kit === true;

    if (!ehKit) {
        return { texto: `Fase ${fase}`, titulo: rotulo, ehKit: false };
    }

    const quantidade = typeof p.quantidade_kit === 'number' && Number.isFinite(p.quantidade_kit) ? p.quantidade_kit : null;
    const parte = rotulo !== '' ? rotulo : (quantidade !== null && quantidade > 1 ? `Kit ${quantidade}` : 'Kit');

    return { texto: `Fase ${fase} · ${parte}`, titulo: rotulo, ehKit: true };
}

/**
 * A célula Anúncios: quadradinhos C/P com o MLB no `title`, mais "2 no ar" ou
 * "1 de 2" (parcial). Sem lista de MLBs na linha (eles ficam no painel).
 *
 * @param {unknown} produto
 * @returns {{tipos: Array<{letra: string, titulo: string, mlb: string}>, texto: string, vazio: boolean}}
 */
export function resumoDosAnuncios(produto) {
    const p = objetoSeguro(produto);
    const brutos = Array.isArray(p.anuncios) ? p.anuncios : [];

    const tipos = [];
    for (const bruto of brutos) {
        const anuncio = objetoSeguro(bruto);
        const mlb = stringSegura(anuncio.ml_item_id);
        if (mlb === '') continue;
        const tipo = stringSegura(anuncio.listing_type_id);
        const nome = Object.prototype.hasOwnProperty.call(LABEL_TIER_HISTORICO, tipo) ? LABEL_TIER_HISTORICO[tipo] : null;
        tipos.push({
            letra: nome !== null ? nome[0] : '·',
            // `nome` é o rótulo longo que o PAINEL mostra ao lado do MLB.
            nome: nome !== null ? nome : '',
            titulo: nome !== null ? `${nome} · ${mlb}` : mlb,
            mlb,
        });
    }

    // `parcial` tem precedência no texto: "1 de 2" diz mais que "1 no ar".
    const parcial = objetoSeguro(p.parcial);
    const publicados = typeof parcial.publicados === 'number' && Number.isFinite(parcial.publicados) ? parcial.publicados : null;
    const total = typeof parcial.total === 'number' && Number.isFinite(parcial.total) ? parcial.total : null;
    if (publicados !== null && total !== null) {
        return { tipos, texto: `${publicados} de ${total}`, vazio: false };
    }

    if (tipos.length === 0) {
        return { tipos, texto: '', vazio: true };
    }

    return { tipos, texto: `${tipos.length} no ar`, vazio: false };
}

/**
 * A densidade lida do `localStorage`, validada.
 * ⚠️ Whitelist por `Array.includes`, NUNCA `hasOwnProperty`: com
 * `hasOwnProperty` o valor `__proto__` passaria e viraria densidade.
 */
export function densidadeInicial(lido) {
    return DENSIDADES.includes(lido) ? lido : 'confortavel';
}
