import { textoSeguro, textoUtil } from '../BarraDaConta';
import { numeroSeguro, urlSegura } from './acoes';
import { haQuanto } from '../tempo';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 08 — os literais e os view models dos QUATRO blocos que
// fecham o cockpit: o banner de integração (VG-02), a faixa de Desempenho
// (VG-15), "Publicações recentes" (VG-16) e "Atividade recente" (VG-17).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — linhas MEDIDAS no
// arquivo (as do `<action>` do 179-08-PLAN.md estão deslocadas; ver a SUMMARY):
//   · banner            → :28-:36    (o `banner` da aritmética em :594)
//   · faixa             → :286-:310  (os `kpis` em :554, o `spark()` em :443)
//   · recentes          → :313-:338  (os `recents` em :572, o `ST` em :562)
//   · atividade         → :341-:381  (`AT` :586 · `IC` :588 · `dayNames` :589 ·
//                                     `actGroups` :590 · `actTabs` :592)
//
// ⚠️ ESTE ARQUIVO NÃO PODE CONTER UMA ÚNICA CLASSE DO TAILWIND.
// O `content` do `tailwind.config.js:12` varre `./resources/js/**/*.jsx` e
// **não** `*.js`: classe escrita aqui não é varrida, não gera CSS e portanto
// NÃO EXISTE para a tela — com todos os testes de render passando, porque a
// classe chega ao HTML. O `<action>` do plano manda `ICONES_ATIVIDADE` carregar
// `fundo`/`borda`/`cor` AQUI; obedecer deixaria os quatro ícones circulares da
// Atividade sem fundo, sem borda e sem tinta, e nenhum gate acusaria. O mapa de
// classe mora em `AtividadeRecente.jsx` (achado medido no plano 179-06, com
// gate próprio desde o 179-07).
//
// ⚠️ Nenhum rótulo, cor ou formato de data vem do servidor (179-03-SUMMARY):
// do payload saem `chave`, números, booleanos, ISO e URLs. Todo texto abaixo é
// literal de tela DESTE plano.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Leitura de mapa fechado por chave que vem do SERVIDOR.
 *
 * ⚠️ `mapa[chave]` cru é furo: `tipo: 'constructor'` devolveria a função
 * `Object`, e `ICONES_ATIVIDADE['constructor'].fundo` seria `undefined` —
 * `className={undefined}` no ícone, sem nenhum erro no console. `toString`,
 * `valueOf` e `__proto__` fazem o mesmo. Só chave PRÓPRIA conta.
 */
function doMapa(mapa, chave, padrao) {
    return (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave))
        ? mapa[chave]
        : padrao;
}

/** Objeto de verdade (nem nulo, nem array, nem primitivo). */
function objetoUtil(valor) {
    return (valor !== null && typeof valor === 'object' && !Array.isArray(valor)) ? valor : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. A sparkline — a aritmética do `spark()` do protótipo (`:443-:448`)
// ─────────────────────────────────────────────────────────────────────────────

/** Faixa vertical útil do `viewBox 0 0 100 34`: o traço nunca passa de y=4. */
export const ALTURA_DA_SPARKLINE = 28;
export const BASE_DA_SPARKLINE = 32;

/**
 * O `d` da linha e o `d` da área de uma série, idênticos ao `spark()` do
 * protótipo (`:443`): `max = Math.max(...vals, 1)`, ponto `i` em
 * `[(i/(n-1))*100, 32 − (v/max)*28]`, coordenadas com `toFixed(1)`, e a área é
 * a linha fechada por ` L100 34 L0 34 Z`.
 *
 * ⚠️ As TRÊS guardas que o protótipo não tem (ele sempre recebe 12 pontos):
 *
 * 1. `n === 0` → `null`. Um `<path d="">` é elemento morto no DOM.
 * 2. `n === 1` → o ponto único em `x = 0` **e** repetido em `x = 100`. Com
 *    `n − 1 = 0` a divisão daria `NaN` no `d`, e o navegador descarta um `path`
 *    com `d` inválido **em silêncio**: a tela sairia sem a linha e sem nenhum
 *    erro. Repetido, o dia único vira um segmento reto — que é a verdade.
 * 3. valor não-finito (string, objeto, `null`, `NaN`, `Infinity`) → a série
 *    INTEIRA vira `null`. Meia série desenhada é pior que série nenhuma:
 *    afirmaria uma curva que o dado não tem.
 *
 * ⚠️ `serie: null` chega de propósito do servidor (`publicacoes_com_venda`,
 * 179-03): nenhum `<svg>` é o resultado CERTO, não defeito.
 *
 * @param {unknown} valores
 * @returns {{linha: string|null, area: string|null}}
 */
export function caminhoDaSparkline(valores) {
    const VAZIO = { linha: null, area: null };
    if (!Array.isArray(valores) || valores.length === 0) return VAZIO;

    const vals = [];
    for (const bruto of valores) {
        if (typeof bruto !== 'number' || !Number.isFinite(bruto)) return VAZIO;
        vals.push(Math.max(0, bruto));
    }

    const max = Math.max(...vals, 1);
    const n = vals.length;
    const alturaDe = (v) => BASE_DA_SPARKLINE - (v / max) * ALTURA_DA_SPARKLINE;

    const pontos = n === 1
        ? [[0, alturaDe(vals[0])], [100, alturaDe(vals[0])]]
        : vals.map((v, i) => [(i / (n - 1)) * 100, alturaDe(v)]);

    const linha = pontos
        .map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1))
        .join(' ');

    return { linha, area: `${linha} L100 34 L0 34 Z` };
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. O banner de integração (`:28-:36`, `:594`)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * O banner é ORIENTADO A DADO de propósito: o dia em que existir integração de
 * ERP neste sistema, o segundo caso é UMA ENTRADA NESTE MAPA, não um redesenho
 * da tela.
 *
 * ⚠️ Hoje `ml` é a única chave alcançável (179-03): o ERP **não é integração**
 * aqui — é DECLARADO na ficha de onboarding (ou pelo sync da planilha de
 * Polos), travado por asserção negativa na Fase 177-03. "Reconectar ERP" seria
 * botão sem destino (EXMP-03).
 *
 * ⚠️ O protótipo escreve *"O token da conta expirou há 2 h"* (`:594`). O PRAZO
 * NÃO EXISTE NO CONTRATO: `banner()` devolve `{chave, link_reconexao}` e a
 * Fase 177-03 travou a ausência de `renova_em`/`expires_at`. A frase sai **sem
 * o prazo** — dizer "há 2 h" seria número inventado (VG-18).
 */
export const BANNERS = {
    ml: {
        titulo: 'Mercado Livre desconectado',
        descricao: 'O token da conta expirou. Publicações, sincronização e métricas estão suspensas até reconectar.',
        cta: 'Reconectar conta',
    },
};

/**
 * `null` quando não há problema (VG-02: o banner só aparece com problema) e
 * também quando a `chave` não está no mapa — chave desconhecida não ganha um
 * banner genérico, porque o texto é que carrega a informação.
 *
 * @param {unknown} banner
 * @returns {{titulo: string, descricao: string, cta: string, href: string|null}|null}
 */
export function bannerParaTela(banner) {
    const dados = objetoUtil(banner);
    if (dados === null) return null;

    const conteudo = doMapa(BANNERS, textoUtil(dados.chave), null);
    if (conteudo === null) return null;

    return { ...conteudo, href: urlSegura(dados.link_reconexao) };
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. A faixa de Desempenho (`:286-:310`, `:554`)
// ─────────────────────────────────────────────────────────────────────────────

export const TITULO_DESEMPENHO = 'Desempenho';
export const SUB_DESEMPENHO = 'Últimos 30 dias';
export const DIAS_PADRAO = 30;

/** O "—" de onde não houve medida, e o motivo que vai no `title`. */
export const VALOR_SEM_MEDIDA = '—';
export const MOTIVO_SEM_MEDIDA = 'Ainda não medimos este indicador nesta conta — não é zero';

/**
 * `Últimos 30 dias` sai idêntico ao literal do protótipo (`periodNames`,
 * `:596`) quando `dias` é 30, que é o que o servidor manda (179-03). A função
 * existe para o dia em que a janela mudar: cravar "30" no texto com `dias: 7`
 * no payload seria número inventado.
 */
export function subtituloDoDesempenho(dias) {
    return `Últimos ${numeroSeguro(dias, DIAS_PADRAO)} dias`;
}

/**
 * Rótulo e unidade por chave de KPI.
 *
 * ⚠️ A unidade é MEDIDA no protótipo (`:554`) mas **não é renderizada** por
 * ele: a célula do KPI (`:294-:306`) desenha só `label`, `value`, o delta e a
 * sparkline — `k.unit` é campo morto. Não há tamanho, cor nem posição medidos
 * para a unidade, e inventá-los seria redesenho. Ela entra no `title` da
 * célula, onde acrescenta informação sem inventar layout.
 */
export const KPIS = {
    publicacoes_criadas: { rotulo: 'Publicações criadas', unidade: null },
    criativos_ia: { rotulo: 'Criativos com IA', unidade: 'packs' },
    publicacoes_com_venda: { rotulo: 'Publicações com venda', unidade: 'de {n}' },
};

/**
 * ⚠️ Chave desconhecida APARECE, com rótulo genérico: o cliente não esconde
 * dado do servidor por falta de rótulo. Esconder seria perder um indicador
 * novo em silêncio.
 */
export const KPI_PADRAO = { rotulo: 'Indicador', unidade: null };

/**
 * A unidade já resolvida, ou `null`.
 *
 * ⚠️ `pack` no SINGULAR quando o valor é 1 — é o que o protótipo faz
 * (`kd[3][0] === 1 ? 'pack' : 'packs'`, `:554`).
 * ⚠️ `de {n}` só nasce com `de` medido: `de 0` é zero medido e aparece;
 * `de` nulo não vira "de 0".
 */
export function unidadeDoKpi(chave, valor, de) {
    const definicao = doMapa(KPIS, chave, KPI_PADRAO);
    if (definicao.unidade === null) return null;

    if (definicao.unidade === 'packs') return valor === 1 ? 'pack' : 'packs';
    if (definicao.unidade === 'de {n}') return de === null ? null : `de ${de}`;

    return definicao.unidade;
}

/**
 * Os KPIs prontos para a tela. Toda flag de render nasce AQUI, em função pura,
 * porque `kpi.chave === algo` dentro do `.map()` do componente leria variável
 * de escopo do componente — a forma que o Rollup elimina neste projeto
 * (REND-04, `feedback_rollup_map_scope_bug.md`).
 *
 * ⚠️ `valor` nulo ⇒ `—` com o motivo no `title`, NUNCA `0` e nunca inventado
 * (VG-18, EXMP-01). `valor: 0` ⇒ `0` aparece, porque zero MEDIDO é dado.
 * ⚠️ `delta` 0 ou nulo ⇒ nenhuma flecha. O protótipo só desenha o delta em
 * `up`/`down` (`:298`/`:299`) e formata `+N` no positivo (`:555`).
 *
 * @param {unknown} desempenho
 */
export function kpisParaTela(desempenho) {
    const dados = objetoUtil(desempenho);
    const brutos = dados === null ? null : dados.kpis;
    if (!Array.isArray(brutos)) return [];

    const saida = [];
    for (const bruto of brutos) {
        const kpi = objetoUtil(bruto);
        if (kpi === null) continue;

        const chave = textoUtil(kpi.chave);
        const definicao = doMapa(KPIS, chave, KPI_PADRAO);
        const valor = numeroSeguro(kpi.valor);
        const de = numeroSeguro(kpi.de);
        const delta = numeroSeguro(kpi.delta);
        const unidade = unidadeDoKpi(chave, valor, de);
        const { linha, area } = caminhoDaSparkline(kpi.serie);

        saida.push({
            chave: chave ?? '',
            rotulo: definicao.rotulo,
            valor: valor === null ? VALOR_SEM_MEDIDA : String(valor),
            title: valor === null
                ? MOTIVO_SEM_MEDIDA
                : (unidade === null ? null : `${valor} ${unidade}`),
            delta: (delta === null || delta === 0) ? null : (delta > 0 ? `↑ +${delta}` : `↓ ${delta}`),
            subiu: delta !== null && delta > 0,
            caiu: delta !== null && delta < 0,
            linha,
            area,
        });
    }

    return saida;
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. "Publicações recentes" (`:313-:338`, `:572`)
// ─────────────────────────────────────────────────────────────────────────────

export const TITULO_RECENTES = 'Publicações recentes';
export const SUB_RECENTES = 'O que acabou de entrar no ar nos últimos 30 dias';
export const VER_PUBLICACOES = 'Ver todas as publicações →';
export const VAZIO_RECENTES_TITULO = 'Nenhuma publicação no período';

/**
 * ⚠️ As aspas são CURVAS (“ ”), byte a byte como o protótipo (`:336`). Com
 * aspas retas o texto é OUTRO, e o gate tem contra-prova disso.
 */
export const VAZIO_RECENTES_TEXTO = 'Use “Nova publicação” para colocar o primeiro produto no ar.';

/**
 * Os dois motivos de lista vazia — a MESMA tela, `title` diferente (VG-18 em
 * dois níveis): sem Company não há o que mostrar; com Company e sem item, o
 * período está vazio.
 */
export const MOTIVO_RECENTES_INDISPONIVEL = 'Esta conta ainda não tem empresa ligada ao Publicador, então não há publicação para listar';
export const MOTIVO_RECENTES_PERIODO = 'Nenhuma publicação entrou no ar nos últimos 30 dias nesta conta';

/**
 * As até 5 linhas prontas para a tela.
 *
 * ⚠️ `quem` é o ÚNICO objeto do contrato (`{tipo, nome}`, 179-03) e a tela lê
 * `quem.nome ?? quem.tipo`. Objeto interpolado em texto foi a tela preta de
 * 07/10 — aqui ele é LIDO, nunca renderizado.
 * ⚠️ `quando` é ISO e passa por `haQuanto` de `../tempo`, que JÁ EXISTE e não
 * se reescreve (IDENT-08). ISO impossível devolve `null` e a linha sai sem a
 * hora, nunca com "Invalid Date".
 *
 * @param {unknown} recentes
 * @param {unknown} urlPublicacoes
 * @param {number} [agora]
 */
export function recentesParaTela(recentes, urlPublicacoes, agora = Date.now()) {
    const dados = objetoUtil(recentes);
    const href = urlSegura(urlPublicacoes);
    const brutos = dados === null ? null : dados.itens;

    const itens = [];
    if (Array.isArray(brutos)) {
        for (const bruto of brutos) {
            const item = objetoUtil(bruto);
            if (item === null) continue;

            const quem = objetoUtil(item.quem);
            const autor = quem === null
                ? textoUtil(item.quem)
                : (textoUtil(quem.nome) ?? textoUtil(quem.tipo));

            itens.push({
                produto_id: (typeof item.produto_id === 'number' || typeof item.produto_id === 'string') ? item.produto_id : null,
                produto_nome: textoUtil(item.produto_nome),
                capa: typeof item.capa === 'string' ? item.capa : null,
                titulo: textoSeguro(item.titulo),
                fase: (typeof item.fase === 'number' || typeof item.fase === 'string') ? item.fase : null,
                rotulo_fase: textoSeguro(item.rotulo_fase),
                tipo: textoSeguro(item.tipo),
                mlb: textoSeguro(item.ml_item_id),
                situacao: typeof item.situacao === 'string' ? item.situacao : null,
                quando: haQuanto(typeof item.quando === 'string' ? item.quando : null, agora),
                autor,
                nova: item.nova === true,
            });
        }
    }

    return {
        itens,
        href,
        vazio: itens.length === 0,
        motivo_vazio: (dados !== null && dados.disponivel === false)
            ? MOTIVO_RECENTES_INDISPONIVEL
            : MOTIVO_RECENTES_PERIODO,
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. "Atividade recente" (`:341-:381`, `:586-:592`)
// ─────────────────────────────────────────────────────────────────────────────

export const TITULO_ATIVIDADE = 'Atividade recente';
export const SUB_ATIVIDADE = 'Quem fez o quê nesta empresa';
export const VAZIO_ATIVIDADE = 'Nenhuma atividade neste filtro e período';

/**
 * Os chips do filtro.
 *
 * ⚠️ São QUATRO, não cinco. O chip "Sistema" do protótipo (`:592`, `sys` →
 * `['sync']`) **NÃO NASCE**: não existe fonte de EVENTO de sincronização neste
 * sistema. O que existe é um CARIMBO (`coletado_em`), que já é a linha de
 * Sincronização da Saúde da conta — um filtro que nunca pode ter conteúdo é
 * enfeite (EXMP-01).
 *
 * ⚠️ O 4º chip ("Produtos") usa uma QUARTA FONTE REAL (`produto_recebido`,
 * medida no 179-03), e por isso nasce.
 *
 * ⚠️ "Publicações" leva `conferiu` junto, como o `AT.pub = ['pub','phase','err']`
 * do protótipo (`:586`) — a conferência é parte do caminho da publicação.
 */
export const CHIPS_ATIVIDADE = [
    { chave: 'tudo', rotulo: 'Tudo', tipos: null },
    { chave: 'publicacoes', rotulo: 'Publicações', tipos: ['publicou', 'conferiu'] },
    { chave: 'criativos', rotulo: 'Criativos', tipos: ['criativos'] },
    { chave: 'produtos', rotulo: 'Produtos', tipos: ['produto_recebido'] },
];

/**
 * O verbo é do CLIENTE: o servidor manda `quem` (ator), `titulo` (alvo) e
 * `detalhe` (meta) — nenhuma frase (179-03).
 */
export const VERBOS = {
    publicou: 'publicou',
    criativos: 'gerou criativos para',
    conferiu: 'conferiu',
    produto_recebido: 'enviou o produto',
};

/** Tipo desconhecido ainda APARECE, com o verbo mais neutro possível. */
export const VERBO_PADRAO = 'registrou';

const MESES = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

export const ROTULOS_FIXOS_DO_GRUPO = { hoje: 'HOJE', ontem: 'ONTEM', sem_data: 'SEM DATA' };

/**
 * O rótulo do grupo de data (`dayNames`, `:589`): `HOJE`, `ONTEM`, `8 OUT` em
 * CAIXA ALTA e sem zero à esquerda, ou `SEM DATA`.
 *
 * ⚠️ A data é lida por REGEX, nunca por `new Date()`. Esta é a única tela da
 * fase que formata data, e `new Date('2026-13-01')` devolve `Invalid Date` —
 * que, interpolado, vira "Invalid Date" na tela. O `new Date` traria ainda o
 * deslocamento de fuso: `new Date('2026-10-08').getDate()` é **7** em BRT.
 *
 * @param {unknown} grupo
 * @returns {string}
 */
export function rotuloDoGrupo(grupo) {
    const dados = objetoUtil(grupo);
    const chave = dados === null ? null : textoUtil(dados.chave);
    if (chave === null) return ROTULOS_FIXOS_DO_GRUPO.sem_data;

    if (chave === 'hoje') return ROTULOS_FIXOS_DO_GRUPO.hoje;
    if (chave === 'ontem') return ROTULOS_FIXOS_DO_GRUPO.ontem;
    if (chave === 'sem_data') return ROTULOS_FIXOS_DO_GRUPO.sem_data;

    const achado = /^(\d{4})-(\d{2})-(\d{2})$/.exec(chave);
    if (achado === null) return ROTULOS_FIXOS_DO_GRUPO.sem_data;

    const mes = Number(achado[2]);
    const dia = Number(achado[3]);
    if (mes < 1 || mes > 12 || dia < 1 || dia > 31) return ROTULOS_FIXOS_DO_GRUPO.sem_data;

    return `${dia} ${MESES[mes - 1]}`;
}

/**
 * A hora do evento, lida do ISO por REGEX pelo mesmo motivo de
 * `rotuloDoGrupo`. Sem ISO (ou com ISO impossível) devolve `null` e a coluna da
 * hora não nasce — em vez de uma hora inventada.
 */
export function horaDoEvento(iso) {
    if (typeof iso !== 'string') return null;

    const achado = /^\d{4}-\d{2}-\d{2}[T ](\d{2}):(\d{2})/.exec(iso.trim());
    if (achado === null) return null;

    const hora = Number(achado[1]);
    const minuto = Number(achado[2]);
    if (hora > 23 || minuto > 59) return null;

    return `${achado[1]}:${achado[2]}`;
}

/** Os tipos de evento de um chip; `null` = todos. Chip desconhecido = todos. */
export function tiposDoChip(chave) {
    const atual = textoUtil(chave);
    const chip = CHIPS_ATIVIDADE.find((c) => c.chave === atual);
    return chip === undefined ? null : chip.tipos;
}

/**
 * Os chips com a flag de ativo decidida AQUI (REND-04): `chip.chave === atual`
 * dentro do `.map()` do componente leria `atual` do escopo do componente, que é
 * exatamente a forma que o Rollup elimina neste projeto. Foi uma violação REAL
 * achada pelo gate do 179-07.
 */
export function chipsParaTela(chipAtivo) {
    const atual = textoUtil(chipAtivo) ?? CHIPS_ATIVIDADE[0].chave;
    return CHIPS_ATIVIDADE.map((chip) => ({
        chave: chip.chave,
        rotulo: chip.rotulo,
        ativa: chip.chave === atual,
    }));
}

/**
 * Os grupos já filtrados pelo chip, com o rótulo de data e, em cada evento, o
 * verbo e a flag do fio vertical.
 *
 * ⚠️ `tem_fio` é `i < eventos.length - 1`: o fio liga um evento ao SEGUINTE
 * (`hasLine`, `:590`). Desenhá-lo no último deixaria um traço solto pendurado
 * embaixo do grupo.
 * ⚠️ Grupo que esvazia pelo filtro NÃO aparece (`.filter(g => g.events.length)`,
 * `:590`) — um rótulo "HOJE" sobre nada é moldura vazia.
 * ⚠️ `titulo` nulo (produto sem nome) ⇒ o alvo NÃO é renderizado e a frase fica
 * `ator + verbo`. É honesto, e nunca "undefined" na tela.
 *
 * @param {unknown} atividade
 * @param {unknown} chipAtivo
 */
export function gruposParaTela(atividade, chipAtivo) {
    const dados = objetoUtil(atividade);
    const brutos = dados === null ? null : dados.grupos;
    if (!Array.isArray(brutos)) return [];

    const tipos = tiposDoChip(chipAtivo);
    const grupos = [];

    for (const bruto of brutos) {
        const grupo = objetoUtil(bruto);
        if (grupo === null) continue;

        const eventosBrutos = Array.isArray(grupo.eventos) ? grupo.eventos : [];
        const eventos = [];

        for (const cru of eventosBrutos) {
            const evento = objetoUtil(cru);
            if (evento === null) continue;

            const tipo = textoUtil(evento.tipo) ?? '';
            if (tipos !== null && !tipos.includes(tipo)) continue;

            eventos.push({
                tipo,
                verbo: doMapa(VERBOS, tipo, VERBO_PADRAO),
                ator: textoSeguro(evento.quem),
                alvo: textoUtil(evento.titulo),
                detalhe: textoUtil(evento.detalhe),
                hora: horaDoEvento(evento.quando_iso),
                tem_fio: false,
            });
        }

        if (eventos.length === 0) continue;

        grupos.push({
            rotulo: rotuloDoGrupo(grupo),
            eventos: eventos.map((evento, i) => ({ ...evento, tem_fio: i < eventos.length - 1 })),
        });
    }

    return grupos;
}
