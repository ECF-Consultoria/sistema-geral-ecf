import { normalizar } from '../identidade/identidade';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 178, plano 02 — os LITERAIS DE TELA do modal "Nova publicação" e a
// busca local.
//
// Módulo PURO: sem JSX e sem import de React, exatamente como
// `identidade/identidade.js`. Há duas razões, e a segunda é a que importa mais:
//
//   1. os gates importam este arquivo direto, sem bundle;
//   2. ⚠️ `feedback_rollup_map_scope_bug.md` — variável de escopo do
//      componente lida dentro de um `.map()` já foi ELIMINADA pelo Rollup no
//      bundle de produção DESTE projeto (ReferenceError em produção, com os
//      testes passando). O corpo do modal mapeia grupos E itens, então a
//      classificação e o filtro moram aqui fora, numa função pura que recebe
//      tudo por argumento.
//
// ─── O que o SERVIDOR manda (contrato da wave 1, 178-01-SUMMARY) ───
//   GET mlb.anuncios.publicador.nova-publicacao  (nenhum parâmetro de query)
//   → { total: int, grupos: [ { chave, itens: [ <item> ] } ] }  — SEMPRE 3 grupos
//   <item> = { produto_id, nome, sku, nota, capa, url }  — 6 campos, todos escalares
//
// ⚠️ **Nenhum rótulo de UI vem no payload.** O servidor manda só a `chave` do
// grupo; "PRONTOS PARA FASE 2", o hint "reaproveita a Fase 1" e o CTA "Criar
// combo" são literais de TELA e moram aqui — o mesmo padrão de
// `GRUPO_RECENTES`/`GRUPO_TODAS` em `SeletorDeEmpresaDoHeader.jsx` (177-06).
// Há teste no servidor (`test_payload_nao_carrega_rotulo_de_tela`) que reprova
// quem tentar mandar rótulo de lá.
//
// ⚠️ "Grupo sem item não aparece" (NOVA-03) é regra de RENDER, não de payload:
// o servidor manda os três grupos sempre, inclusive vazios. Quem filtra é a
// `filtrarGrupos()` daqui.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Os três grupos do "próximo passo", na ORDEM DA TELA.
 *
 * Valores copiados do objeto `meta` do protótipo
 * (`new_layout_publicador/PublicadorHeader.dc.html`:213), chave por chave:
 *   ready → ['PRONTOS PARA FASE 2', 'Criar combo',    …, 'reaproveita a Fase 1']
 *   none  → ['SEM PUBLICAÇÃO',      'Publicar Fase 1', …, '1 unidade por oferta']
 *   draft → ['RASCUNHOS',           'Continuar',       …, 'retome de onde parou']
 *
 * ⚠️ **A ordem desta constante É a ordem da tela** (NOVA-02) — é o
 * `['ready','none','draft'].map(...)` do protótipo :214. Nada de reordenar pelo
 * payload: o servidor pode mudar a ordem do JSON e a tela não muda.
 *
 * ⚠️ O rótulo em caixa alta é o **TEXTO**, não um `text-transform`: o protótipo
 * :143 não tem `text-transform` nenhum, e `uppercase` por CSS faria o
 * copiar/colar e o leitor de tela divergirem do desenho.
 *
 * `tom` é só a CHAVE de estilo do CTA. As classes ficam no JSX
 * (`PainelNovaPublicacao.jsx`), nunca aqui — TOK-15: cor por nome de token, e
 * este módulo não conhece Tailwind.
 */
export const GRUPOS = [
    { chave: 'prontos_fase2', rotulo: 'PRONTOS PARA FASE 2', hint: 'reaproveita a Fase 1', cta: 'Criar combo', tom: 'accent' },
    { chave: 'sem_publicacao', rotulo: 'SEM PUBLICAÇÃO', hint: '1 unidade por oferta', cta: 'Publicar Fase 1', tom: 'solido' },
    { chave: 'rascunhos', rotulo: 'RASCUNHOS', hint: 'retome de onde parou', cta: 'Continuar', tom: 'discreto' },
];

/** NOVA-05 — o vazio da busca (protótipo :156). */
export const VAZIO = 'Nenhum produto encontrado';

/** NOVA-06 — a frase do rodapé (protótipo :160), byte a byte, com o ponto final. */
export const RODAPE = 'A Fase 2 usa o mesmo Publicador e reaproveita categoria, atributos e ficha técnica da Fase 1.';

/** O placeholder do input de 16px (protótipo :137). */
export const PLACEHOLDER = 'Qual produto você quer publicar?';

/**
 * ⚠️ Estados que o protótipo NÃO tem, porque a lista dele é mock constante.
 *
 * Com fetch real eles existem, e **lista vazia em silêncio é proibida** — é a
 * mesma disciplina do dropdown de empresa
 * (`SeletorDeEmpresaDoHeader.jsx`, PainelDeEmpresas). As duas frases são
 * renderizadas com a MESMA geometria do vazio (protótipo :156), para não haver
 * um segundo desenho de "nada aqui".
 */
export const CARREGANDO = 'Carregando…';
export const ERRO = 'Não foi possível carregar os produtos agora. Tente de novo em instantes.';

/**
 * A fronteira entre o payload do servidor e qualquer leitura do cliente.
 *
 * Molde: `normalizarPayload` de `SeletorDeEmpresaDoHeader.jsx` (177-06).
 *
 * ⚠️ `Number.isFinite` e **nunca** `typeof === 'number'`: `NaN` é `number`, e um
 * `NaN` atravessando até o JSX escreve literalmente "NaN" na tela. Foi a classe
 * de bug da tela preta de 07/10 (campo do servidor renderizado cru).
 *
 * @param {unknown} dados  o corpo da resposta; nulo, string, número ou array
 *   caem todos em `{total: null, grupos: []}` sem lançar
 * @returns {{total: number|null, grupos: Array<{chave: unknown, itens: Array}>}}
 */
export function normalizarPayload(dados) {
    const grupos = Array.isArray(dados?.grupos) ? dados.grupos : [];

    return {
        total: Number.isFinite(dados?.total) ? dados.total : null,
        grupos: grupos.map((grupo) => ({
            chave: grupo?.chave,
            itens: Array.isArray(grupo?.itens) ? grupo.itens : [],
        })),
    };
}

/**
 * O "palheiro" de um item: nome e SKU juntos, já normalizados.
 *
 * ⚠️ Cada campo passa por `normalizar()` SEPARADAMENTE, e não como
 * `` normalizar(`${item.nome} ${item.sku}`) ``. O protótipo :214 faz
 * `norm(p[0] + ' ' + p[1])` porque lá o dado é fixo; aqui o dado vem do
 * servidor, e um campo-objeto dentro de um template literal viraria
 * "[object Object]" — ou seja, buscar "object" acharia o produto. É exatamente
 * o fantasma de busca que o docblock de `normalizar()` descreve. Normalizando
 * campo por campo, campo-objeto vira `''` e não casa nada.
 *
 * O espaço entre os dois é preservado: a busca atravessa a fronteira
 * nome→sku igual ao protótipo quando os dois são texto.
 */
function palheiro(item) {
    return `${normalizar(item?.nome)} ${normalizar(item?.sku)}`;
}

/**
 * Os grupos prontos para a tela: na ordem de `GRUPOS`, já com rótulo/hint/cta/tom,
 * filtrados pela busca local e **sem os grupos que ficaram sem item** (NOVA-03).
 *
 * É o `modalGroups` do protótipo :214, incluindo o `.filter(g => g.items.length)`
 * do fim daquela linha.
 *
 * A busca é LOCAL, sobre o payload já carregado — nenhuma ida à rede por tecla
 * digitada (T-178-09, e é o que o protótipo faz).
 *
 * @param {object} [entrada]
 * @param {unknown} [entrada.grupos]    o `grupos` do payload; qualquer coisa que
 *   não seja array devolve `[]` sem lançar
 * @param {unknown} [entrada.consulta]  o texto digitado; não-texto vira `''`
 *   (= "sem busca"), nunca "[object Object]"
 * @returns {Array<{chave: string, rotulo: string, hint: string, cta: string, tom: string, itens: Array}>}
 */
export function filtrarGrupos({ grupos = [], consulta = '' } = {}) {
    const doServidor = Array.isArray(grupos) ? grupos : [];

    // ⚠️ `normalizar(consulta)` ANTES do `trim()`, e NUNCA
    // `normalizar(String(consulta ?? '').trim())`: com `String()` cru uma
    // consulta que chegasse como objeto viraria "[object object]" e filtraria a
    // lista inteira por um termo que o usuário nunca digitou. `normalizar()`
    // devolve '' para não-texto (IDENT-08 é a única casa dessa guarda), e '' é o
    // mesmo que "sem busca".
    const q = normalizar(consulta).trim();

    const saida = [];

    // ⚠️ O laço é sobre `GRUPOS` (a ordem da tela), e NÃO sobre o payload.
    for (const grupo of GRUPOS) {
        const achado = doServidor.find((g) => g?.chave === grupo.chave);
        const itens = Array.isArray(achado?.itens) ? achado.itens : [];

        // ⚠️ Toda flag nasce DENTRO do filtro (REND-04 / Rollup): nada de
        // calcular algo aqui fora e ler dentro do callback.
        const casados = itens.filter((item) => q === '' || palheiro(item).includes(q));

        // NOVA-03: grupo sem item não entra — e por isso a tela não desenha
        // título sozinho. `casados` é um array NOVO (`filter`), então mutar a
        // saída não corrompe o payload que ainda está em estado do React.
        if (casados.length > 0) {
            saida.push({ ...grupo, itens: casados });
        }
    }

    return saida;
}
