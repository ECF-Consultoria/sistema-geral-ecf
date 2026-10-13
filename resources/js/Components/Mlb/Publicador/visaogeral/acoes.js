import { textoSeguro, textoUtil } from '../BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 07 — os literais e os view models dos três blocos do meio do
// cockpit: "O que fazer agora" (VG-09/10/11), "Saúde da conta" (VG-13) e
// "Publicações no Mercado Livre" (VG-14/VG-18).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — linhas MEDIDAS no
// arquivo (as do `<action>` do 179-07-PLAN.md estão deslocadas; ver a SUMMARY):
//   · card "O que fazer agora"  → :165  (cabeçalho :166, H2 :168, sub :169)
//   · trilho do segmented       → :170  (opção :172, dot/contagem :173)
//   · linha da ação             → :176  (grid :177, tile :178, ícones :179-:181)
//   · título/badges             → :183  (14px :184, severidade :185, RECOM. :186)
//   · descrição / pilha / CTA   → :188 / :191 / :193   (chevron :194)
//   · sub-lista                 → :198-:210
//   · vazio                     → :215-:219
//   · Saúde da conta            → :235  (linha :240, botão :246, spinner :247)
//   · Publicações no ML         → :256  (total :260, barra :261, legenda :265)
//   · acervo de imagens         → :268  (mosaico :269-:271)
//   · a aritmética              → :423 (`SEV`) · :516-:526 (ações e abas) ·
//                                 :528-:540 (saúde) · :542-:544 e :603-:607
//
// ⚠️ ESTE ARQUIVO NÃO PODE CONTER UMA ÚNICA CLASSE DO TAILWIND.
// O `content` do `tailwind.config.js:12` varre `./resources/js/**/*.jsx` e
// **não** `*.js`: classe escrita aqui não é varrida, não gera CSS e portanto
// NÃO EXISTE para a tela — com todos os testes de render passando, porque a
// classe chega ao HTML. O `<action>` do plano manda `SEVERIDADES` carregar
// `texto`/`tint`/`dot`; obedecer deixaria o ícone de severidade SEM tinta e o
// dot do segmented invisível, sem nenhum gate acusar. Os mapas de classe moram
// nos três `.jsx` (achado medido no plano 179-06).
//
// ⚠️ Nenhum rótulo, cor ou formato de data vem do servidor (179-02-SUMMARY e
// 179-03-SUMMARY): do payload saem `chave`, números, booleanos e URLs. Tudo o
// que está escrito abaixo é literal de tela deste plano.
// ═══════════════════════════════════════════════════════════════════════════

// ─────────────────────────────────────────────────────────────────────────────
// 1. "O que fazer agora" — literais (`:165-:219`, `:423`, `:519-:526`, `:603`)
// ─────────────────────────────────────────────────────────────────────────────

export const TITULO = 'O que fazer agora';

/**
 * O rótulo do badge de severidade. O protótipo guarda `['Crítico', …]` em `SEV`
 * (`:423`) e aplica `.toUpperCase()` só no badge (`:519`, `sevLabel`), enquanto
 * a aba do segmented usa o rótulo em caixa de título (`:525`). São DOIS rótulos
 * para a mesma severidade, de propósito — por isso `ABAS_SEVERIDADE` abaixo não
 * reusa este.
 *
 * ⚠️ `ordem` é a do servidor (`AcoesDaContaService::ORDEM_DA_SEVERIDADE`) e
 * existe aqui só para o gate conferir que a ordem da tela é a mesma; a lista
 * chega ORDENADA e o cliente **não** reordena.
 */
export const SEVERIDADES = {
    critico: { rotulo: 'CRÍTICO', ordem: 0 },
    atencao: { rotulo: 'ATENÇÃO', ordem: 1 },
    oportunidade: { rotulo: 'OPORTUNIDADE', ordem: 2 },
};

/**
 * Severidade de uma chave que esta tela não conhece. O servidor já faz o mesmo
 * (179-02: "chave desconhecida cai em `atencao` e APARECE") — aqui a guarda
 * existe para o caso de uma severidade nova chegar do servidor: a ação aparece,
 * em vez de desaparecer em silêncio.
 */
export const SEVERIDADE_PADRAO = 'atencao';

/**
 * As 4 opções do segmented (`:525`). ⚠️ `Todas` NÃO tem dot — o protótipo faz
 * `hasDot: k !== 'all'`, então são 3 dots, não 4.
 */
export const ABAS_SEVERIDADE = [
    { chave: 'todas', rotulo: 'Todas', tem_dot: false },
    { chave: 'critico', rotulo: 'Crítico', tem_dot: true },
    { chave: 'atencao', rotulo: 'Atenção', tem_dot: true },
    { chave: 'oportunidade', rotulo: 'Oportunidade', tem_dot: true },
];

/** Rótulo do CTA por chave de ação (as 12 chaves fechadas do 179-02). */
export const CTAS = {
    reconectar: 'Reconectar',
    com_problema: 'Corrigir',
    pausados: 'Revisar',
    rascunhos_pendencias: 'Continuar',
    prontos_fase2: 'Criar combos',
    sem_publicacao: 'Publicar',
    conferidos: 'Publicar',
    portal_novas: 'Sincronizar',
    alavancas: 'Abrir alavancas',
    ficha_incompleta: 'Corrigir',
    perdendo_catalogo: 'Corrigir',
    foto_insuficiente: 'Corrigir',
};

/**
 * ⚠️ Chave desconhecida ganha `Ver` e a ação APARECE. O servidor a mantém
 * (179-02) e o cliente não pode escondê-la por falta de rótulo: regressão por
 * omissão é a pior, porque nada acusa.
 */
export const CTA_PADRAO = 'Ver';

export const RECOMENDADO = 'RECOMENDADO AGORA';
export const ABRIR = 'Abrir →';
export const ADIAR = 'Adiar até amanhã';

/**
 * `title` do chevron. O protótipo não dá rótulo nenhum ao chevron (`:194` é um
 * `<span>` com um svg dentro), mas aqui ele é um `<button>` para o teclado
 * alcançar a gaveta — e botão sem nome acessível é botão mudo para quem usa
 * leitor de tela. Duas palavras, sem jargão.
 */
export const EXPANDIR = 'Expandir';
export const RECOLHER = 'Recolher';
export const VAZIO_TITULO = 'Nada pendente neste filtro';

/**
 * O literal do protótipo (`:219`), guardado para o gate comparar. ⚠️ O rodapé
 * do vazio **não** o usa cravado: `textoDaVolta()` monta a hora a partir do
 * `volta_em_iso` que o servidor manda exatamente para isso (VG-11). As 8h são
 * contrato com o `expiraEm()` do 179-02 (`Carbon::tomorrow()->setTime(8, 0)`);
 * se a hora do servidor mudar, a tela acompanha sozinha.
 */
export const VAZIO_TEXTO = 'Ações adiadas voltam amanhã às 8h.';

/** Rodapé do vazio quando não há instante medido — nenhuma hora inventada. */
export const VAZIO_TEXTO_SEM_HORA = 'Ações adiadas voltam amanhã.';

export const SEM_ACAO = 'Nenhuma ação pendente';

/**
 * Subtítulo do card (`:603`).
 *
 * ⚠️ MEDIDO: o protótipo escreve `acts.length + ' ações priorizadas…'` **sem
 * tratar o singular** — "1 ações". O plano mandou medir e manter o plural se
 * fosse o caso do protótipo; é o caso, e o plural fica (divergência declarada
 * na SUMMARY). O número é o da lista inteira, antes do filtro por severidade.
 */
export function SUBTITULO(quantas) {
    const n = numeroSeguro(quantas, 0);

    return n > 0 ? `${n} ações priorizadas a partir dos dados da conta` : SEM_ACAO;
}

/**
 * O rodapé do vazio, com a hora REAL de `volta_em_iso` (VG-11).
 *
 * ⚠️ A hora sai do ISO por expressão regular, nunca por `new Date()`: o ISO vem
 * no fuso da aplicação (`-03:00`) e o `Date` do navegador o converteria para o
 * fuso da máquina, mudando a hora escrita na tela. `Invalid Date` nunca pode
 * aparecer (REND-01), e sem instante medido não há hora nenhuma.
 */
export function textoDaVolta(iso) {
    const texto = textoUtil(iso);
    if (texto === null) return VAZIO_TEXTO_SEM_HORA;

    const achado = /T(\d{2}):(\d{2})/.exec(texto);
    if (achado === null) return VAZIO_TEXTO_SEM_HORA;

    const hora = Number(achado[1]);
    const minuto = achado[2];
    if (!Number.isFinite(hora) || hora > 23) return VAZIO_TEXTO_SEM_HORA;

    const relogio = minuto === '00' ? `${hora}h` : `${hora}h${minuto}`;

    return `Ações adiadas voltam amanhã às ${relogio}.`;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Guardas de fronteira (T-179-30, a tela preta de 07/10)
// ─────────────────────────────────────────────────────────────────────────────

/** `true` só para objeto comum — array e `null` ficam de fora. */
function objeto(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor);
}

/** Array ou nada: string, número e objeto viram `[]` em vez de derrubar o map. */
function lista(valor) {
    return Array.isArray(valor) ? valor : [];
}

/**
 * Número inteiro ou o padrão. ⚠️ `NaN`, `Infinity`, objeto, array e string não
 * numérica caem no padrão: `{numero} produtos` com `NaN` é número inventado.
 */
export function numeroSeguro(valor, padrao = null) {
    if (typeof valor === 'number' && Number.isFinite(valor)) return Math.trunc(valor);
    if (typeof valor === 'string' && valor.trim() !== '') {
        const n = Number(valor);
        if (Number.isFinite(n)) return Math.trunc(n);
    }

    return padrao;
}

/**
 * URL do servidor. Mais estrita que `textoUtil`, pelo mesmo motivo do 179-06
 * (D-8): `textoUtil(7)` devolve `'7'`, e `href="7"` é destino morto. Botão sem
 * destino é proibido (EXMP-01), então sem URL o elemento não nasce.
 */
export function urlSegura(valor) {
    return typeof valor === 'string' ? textoUtil(valor) : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. "O que fazer agora" — os view models
// ─────────────────────────────────────────────────────────────────────────────

/** A pilha de afetados mostra até 3 (`:191`, `a.items.slice(0, 3)`). */
export const MAX_TILES = 3;

/**
 * O destino de uma ação, como URL.
 *
 * Os três shapes que `PainelVisaoGeralService` produz (`:495`, `:514`, `:560`):
 *   `{rota, params}`  → `route()` do Ziggy
 *   `{acao, url}`     → a `url` (é o `link_reconexao` do Mercado Livre)
 *   `{acao}`          → **null**: `{acao: 'sincronizar'}` não é link, é ação,
 *                       e a ação dela vive na tela de Produtos (ver a SUMMARY).
 *
 * ⚠️ Função PURA, chamada DENTRO do `.map()` (REND-04). O `try/catch` existe
 * porque o Ziggy lança quando o nome da rota não está no manifest — e uma rota
 * removida numa fase futura derrubaria a tela inteira em vez de sumir com um
 * botão.
 */
export function destinoHref(destino) {
    if (!objeto(destino)) return null;

    const url = urlSegura(destino.url);
    if (url !== null) return url;

    const rota = textoUtil(destino.rota);
    if (rota === null) return null;

    const params = objeto(destino.params) ? destino.params : {};
    try {
        const href = typeof route === 'function' ? route(rota, params) : null;

        return typeof href === 'string' && href.trim() !== '' ? href : null;
    } catch {
        return null;
    }
}

/**
 * Normaliza o payload `acoes` do 179-02 para o shape que o JSX consome.
 *
 * ⚠️ Ação `null` dentro do array é IGNORADA, `itens` que não é array vira `[]` e
 * item `null` é descartado: na Fase 178 um grupo nulo dentro de `grupos`
 * derrubou o painel inteiro, e só o render adverso pegou.
 */
export function normalizarAcoes(bruta) {
    const dados = objeto(bruta) ? bruta : {};
    const contagens = objeto(dados.contagens) ? dados.contagens : {};

    return {
        acoes: lista(dados.acoes)
            .filter((acao) => objeto(acao))
            .map((acao) => ({
                chave: textoUtil(acao.chave),
                severidade: SEVERIDADES[acao.severidade] !== undefined ? acao.severidade : SEVERIDADE_PADRAO,
                titulo: textoUtil(acao.titulo),
                descricao: textoUtil(acao.descricao),
                numero: numeroSeguro(acao.numero),
                recomendada: acao.recomendada === true,
                destino: objeto(acao.destino) ? acao.destino : null,
                itens: lista(acao.itens)
                    .filter((item) => objeto(item))
                    .map((item) => ({
                        nome: textoUtil(item.nome),
                        meta: textoUtil(item.meta),
                        motivo: textoUtil(item.motivo),
                    })),
            })),
        contagens: {
            todas: numeroSeguro(contagens.todas, 0),
            critico: numeroSeguro(contagens.critico, 0),
            atencao: numeroSeguro(contagens.atencao, 0),
            oportunidade: numeroSeguro(contagens.oportunidade, 0),
        },
        volta_em_iso: textoUtil(dados.volta_em_iso),
    };
}

/**
 * As 4 opções do segmented com a contagem.
 *
 * ⚠️ A contagem sai da PROP `contagens`, não do número de linhas visíveis — o
 * protótipo conta a lista inteira (`:520`) e filtrar não muda contagem. O que
 * muda é o adiamento otimista: a ação já saiu da tela, então sai da contagem
 * também, senão o segmented prometeria uma linha que não existe mais.
 *
 * @param {object} contagens   `{todas, critico, atencao, oportunidade}` já normalizado
 * @param {Array<{severidade: string}>} escondidas  as adiadas só no cliente
 */
export function abasComContagem(contagens, escondidas = []) {
    const base = objeto(contagens) ? contagens : {};
    const fora = lista(escondidas).filter((acao) => objeto(acao));

    return ABAS_SEVERIDADE.map((aba) => {
        const bruto = numeroSeguro(base[aba.chave], 0) ?? 0;
        const descontadas = aba.chave === 'todas'
            ? fora.length
            : fora.filter((acao) => acao.severidade === aba.chave).length;

        return {
            chave: aba.chave,
            rotulo: aba.rotulo,
            tem_dot: aba.tem_dot,
            total: Math.max(bruto - descontadas, 0),
        };
    });
}

/**
 * As ações que a tela desenha, já recortadas pelo filtro e pelo adiamento
 * otimista, com TODO derivado pronto — é isto que o `.map()` do JSX consome,
 * para que nenhuma variável de escopo do componente entre no callback (REND-04,
 * `feedback_rollup_map_scope_bug.md`).
 *
 * ⚠️ `recomendada` vem do servidor e NÃO se recalcula sob filtro (VG-10): o
 * protótipo decide `isTop` sobre a lista completa (`:518`).
 * ⚠️ `aberta` exige sub-item (`:520`: `open && a.items.length > 0`) — chevron
 * não abre gaveta vazia.
 * ⚠️ `pode_adiar` exige `contaChave`: sem conta não há rota, e botão sem destino
 * é proibido (EXMP-01).
 */
export function acoesParaTela(dados, filtro = 'todas', expandidas = {}, escondidas = {}, contaChave = null) {
    const base = objeto(dados) ? dados : { acoes: [] };
    const abertas = objeto(expandidas) ? expandidas : {};
    const fora = objeto(escondidas) ? escondidas : {};
    const conta = textoUtil(contaChave);

    return lista(base.acoes)
        .filter((acao) => acao.chave === null || fora[acao.chave] !== true)
        .filter((acao) => filtro === 'todas' || acao.severidade === filtro)
        .map((acao) => ({
            chave: acao.chave,
            severidade: acao.severidade,
            rotulo_severidade: SEVERIDADES[acao.severidade].rotulo,
            titulo: acao.titulo,
            descricao: acao.descricao,
            numero: acao.numero,
            recomendada: acao.recomendada,
            cta: acao.chave !== null && CTAS[acao.chave] !== undefined ? CTAS[acao.chave] : CTA_PADRAO,
            href: destinoHref(acao.destino),
            aberta: acao.chave !== null && abertas[acao.chave] === true && acao.itens.length > 0,
            tem_itens: acao.itens.length > 0,
            pode_adiar: acao.chave !== null && conta !== null,
            conta,
            tiles: acao.itens.slice(0, MAX_TILES),
            itens: acao.itens,
        }));
}

/**
 * As ações escondidas só no cliente, para a contagem do segmented. Separada de
 * `acoesParaTela` porque o filtro por severidade **não** pode influir aqui.
 */
export function escondidasDaLista(dados, escondidas = {}) {
    const base = objeto(dados) ? dados : { acoes: [] };
    const fora = objeto(escondidas) ? escondidas : {};

    return lista(base.acoes).filter((acao) => acao.chave !== null && fora[acao.chave] === true);
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. "Saúde da conta" — literais e view model (`:235-:254`, `:528-:540`)
// ─────────────────────────────────────────────────────────────────────────────

export const TITULO_SAUDE = 'Saúde da conta';

/** `saude.geral` → rótulo (`:540`, `healthIssue`). */
export const GERAL = {
    tudo_conectado: 'Tudo conectado',
    atencao: 'Atenção',
    requer_acao: 'Requer ação',
};

/**
 * Nome e tile de cada linha (`:528-:530`).
 *
 * ⚠️ O tile da Sincronização é o caractere `⟳` — MEDIDO em `:539`
 * (`health[2].tile = '⟳'`), não um ícone do Lucide.
 */
export const LINHAS = {
    mercado_livre: { nome: 'Mercado Livre', tile: 'ML' },
    erp: { nome: 'ERP', tile: 'ERP' },
    sincronizacao: { nome: 'Sincronização', tile: '⟳' },
};

/** `saude.linhas[0].estado` → rótulo da pílula de status da linha do ML. */
export const ESTADO_ML = {
    ativo: 'Conectado',
    expirado: 'Token expirado',
    sem_token: 'Sem conexão',
};

export const ATUALIZAR = 'Atualizar';
export const ATUALIZANDO = 'Atualizando';
export const NUNCA_COLETADO = 'Nunca coletado';
export const ERP_NAO_INFORMADO = 'Não informado';

/**
 * ⚠️ O ERP **NÃO é integração neste sistema** — ele é DECLARADO na ficha de
 * onboarding (ou pelo sync da planilha de Polos). A Fase 177, plano 03, mediu
 * isso e travou com asserção negativa (`assertArrayNotHasKey('conectado',
 * $erp)`), e o 179-03 repetiu (`problema` é SEMPRE `false` na linha do ERP).
 *
 * Por isso a linha do ERP:
 *   · diz `Declarado: X` ou `Não informado`, e **NUNCA `Conectado`**;
 *   · **não** ganha dot verde nem pílula de status;
 *   · **não** move o `geral` do card;
 *   · **não** gera CTA "Reconectar ERP" — seria botão sem destino (EXMP-03).
 *
 * O protótipo desenha o contrário (`:368`, `:519`, `:529`). Quem for "consertar"
 * isto: o dado não existe. Antes de mexer, leia a 177-03-SUMMARY.
 */
export function metaDoErp(declarado) {
    const nome = textoUtil(declarado);

    return nome === null ? ERP_NAO_INFORMADO : `Declarado: ${nome}`;
}

/**
 * Meta da linha de Sincronização. O protótipo escreve `'Última coleta ' + S.synced`
 * (`:529`), com `synced = 'há 25 min'`; o contrato do 179-03 expõe `horas` (int)
 * e `nunca_coletado`.
 *
 * ⚠️ "há 0 h" seria mentira quando nunca houve coleta — e `nunca_coletado` tem
 * texto próprio (o mapa `null` × `0` do 179-03).
 */
export function metaDaSincronizacao(linha) {
    const dados = objeto(linha) ? linha : {};
    if (dados.nunca_coletado === true) return NUNCA_COLETADO;

    const horas = numeroSeguro(dados.horas);
    if (horas === null) return NUNCA_COLETADO;

    return `Última coleta há ${horas} h`;
}

/**
 * Meta da linha do Mercado Livre.
 *
 * ⚠️ **Nenhum prazo inventado.** O protótipo escreve *"Conta ECFDEV02 · token
 * renova em 4 h"* (`:528`); o contrato expõe só `estado`, e a 177-03 travou a
 * AUSÊNCIA de `renova_em`. A meta é a identidade da conta; o estado vai na
 * pílula, que é onde o protótipo o põe (`:250`).
 */
export function metaDoMercadoLivre(linha) {
    const dados = objeto(linha) ? linha : {};
    const partes = [textoUtil(dados.conta_nome), textoUtil(dados.conta_ml_id)].filter((p) => p !== null);

    return partes.length > 0 ? partes.join(' · ') : null;
}

/**
 * As linhas da Saúde, já com nome, tile, meta, pílula e botão decididos.
 *
 * ⚠️ Linha de chave desconhecida é IGNORADA (o card tem 3 linhas, sempre), e
 * linha `null` também — o mesmo descarte da Jornada.
 */
export function linhasDaSaude(bruta) {
    const dados = objeto(bruta) ? bruta : {};
    const geral = GERAL[dados.geral] !== undefined ? dados.geral : null;

    return {
        geral,
        rotulo_geral: geral === null ? null : GERAL[geral],
        linhas: lista(dados.linhas)
            .filter((linha) => objeto(linha) && LINHAS[linha.chave] !== undefined)
            .map((linha) => {
                const chave = linha.chave;
                const ml = chave === 'mercado_livre';
                const sincronizacao = chave === 'sincronizacao';
                const estado = ml && ESTADO_ML[linha.estado] !== undefined ? linha.estado : null;

                return {
                    chave,
                    nome: LINHAS[chave].nome,
                    tile: LINHAS[chave].tile,
                    meta: ml
                        ? metaDoMercadoLivre(linha)
                        : sincronizacao
                            ? metaDaSincronizacao(linha)
                            : metaDoErp(linha.declarado),
                    estado,
                    rotulo_estado: estado === null ? null : ESTADO_ML[estado],
                    // ⚠️ O ERP nunca tem problema (179-03, M04/M05) e a linha de
                    // Sincronização usa `defasado`/`nunca_coletado`, que é o caso
                    // âmbar REAL desta tela (179-03, D-2).
                    problema: ml ? linha.problema === true : false,
                    atencao: sincronizacao && (linha.defasado === true || linha.nunca_coletado === true),
                    atualizar_url: sincronizacao ? urlSegura(linha.atualizar_url) : null,
                };
            }),
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. "Publicações no Mercado Livre" (`:256-:274`, `:542-:544`, `:606-:607`)
// ─────────────────────────────────────────────────────────────────────────────

export const TITULO_PUBS = 'Publicações no Mercado Livre';
export const VER_TODAS = 'Ver todas →';
export const OFERTAS = 'ofertas';

/**
 * As 4 faixas reais da barra (`publicacoesMl.faixas[].chave` do 179-03).
 *
 * ⚠️ **"Com erro" NÃO EXISTE e não vai ser inventada.** O protótipo desenha
 * `Ativas / Pausadas / Com erro` (`:544`), mas `MlAcervoItem.status` só assume
 * `active|paused|under_review|closed` — medido na Fase 177, plano 03. O terceiro
 * sinal REAL é "Pedem ação" (`severidade > 0`), que é o MESMO número do dot da
 * aba Publicações.
 */
export const FAIXAS = {
    ativas: 'Ativas',
    pausadas: 'Pausadas',
    em_revisao: 'Em revisão',
    encerradas: 'Encerradas',
};

export const PEDEM_ACAO = 'Pedem ação';
export const ACERVO = 'Acervo de imagens';
export const SEM_MEDIDA = '—';

export const TITLE_SEM_MEDIDA =
    'Nenhuma coleta do acervo ainda: este número não foi medido, e zero seria mentira.';

/** `total` é 0 MEDIDO: a conta foi coletada e não tem anúncio (`:606`). */
export const SPLIT_SEM_ANUNCIO = 'nenhuma ainda';

/**
 * `total` é `null`: o acervo nunca foi coletado. ⚠️ Os dois textos existem
 * porque `ativas_pct` null tem DOIS significados (179-03, armadilha 3) — e
 * "0% ativas" num acervo não coletado seria número inventado (VG-18).
 */
export const SPLIT_NAO_MEDIDO = 'nunca coletado';

/** Meta do bloco do acervo (`:607`). */
export function METADO_ACERVO(imagens, packs) {
    const i = imagens === null ? SEM_MEDIDA : imagens;
    const p = packs === null ? SEM_MEDIDA : packs;

    return `${i} imagens · ${p} packs gerados com IA`;
}

/**
 * As 6 sementes do mosaico do acervo (`:270-:271`).
 *
 * ⚠️ O mosaico é ORNAMENTO (`aria-hidden`): ele ilustra o acervo, não mede
 * nada. O protótipo escreve 6 cores à mão; aqui o tom sai de `tomDoProduto()`
 * de `identidade.js`, a ÚNICA casa onde `oklch(` pode aparecer (IDENT-08). A 5ª
 * célula é amarela no protótipo e por isso sai como classe de token, não como
 * tom calculado.
 */
export const SEMENTES_DO_MOSAICO = ['acervo-1', 'acervo-2', 'acervo-3', 'acervo-4', null, 'acervo-6'];

/** A posição (base 1) da célula amarela do mosaico (`:271`). */
export const CELULA_AMARELA = 5;

/**
 * O resumo de Publicações, já com texto e destino decididos.
 *
 * ⚠️ `pedem_acao` pode NÃO EXISTIR no objeto (chave ausente, não nula) — 179-03,
 * armadilha 1: sem número medido a legenda **não nasce**.
 * ⚠️ `outras` NÃO é faixa: é o resíduo que faz a soma fechar com o total do
 * header. Ele não é desenhado e, principalmente, **não** é somado a
 * "Encerradas" (179-03, armadilha 2).
 * ⚠️ `urlPublicacoes` nula (D23) ⇒ `href` null em tudo, e o JSX desenha `<span>`.
 */
export function resumoParaTela(bruto, urlPublicacoes = null) {
    const dados = objeto(bruto) ? bruto : {};
    const total = numeroSeguro(dados.total);
    const pct = numeroSeguro(dados.ativas_pct);
    const todas = urlSegura(urlPublicacoes);
    const pedem = objeto(dados.pedem_acao) ? dados.pedem_acao : null;

    return {
        disponivel: dados.disponivel === true,
        total,
        total_texto: total === null ? SEM_MEDIDA : String(total),
        // O protótipo mantém "ofertas" sempre e troca só a segunda metade (`:606`).
        split: total === null
            ? SPLIT_NAO_MEDIDO
            : total === 0
                ? SPLIT_SEM_ANUNCIO
                : pct === null
                    ? SPLIT_NAO_MEDIDO
                    : `${pct}% ativas`,
        url_todas: todas,
        faixas: lista(dados.faixas)
            .filter((faixa) => objeto(faixa) && FAIXAS[faixa.chave] !== undefined)
            .map((faixa) => ({
                chave: faixa.chave,
                rotulo: FAIXAS[faixa.chave],
                total: numeroSeguro(faixa.total, 0) ?? 0,
                href: urlSegura(faixa.url),
            })),
        pedem_acao: pedem === null
            ? null
            : {
                chave: 'pedem_acao',
                rotulo: PEDEM_ACAO,
                total: numeroSeguro(pedem.total, 0) ?? 0,
                href: urlSegura(pedem.url),
            },
    };
}

/**
 * O acervo de imagens.
 *
 * ⚠️ `imagens`/`packs` nulos mostram `—` com o motivo no `title`, **nunca 0**
 * (VG-18). E o bloco sai **sem link**: não existe tela de acervo por conta — o
 * acervo é endereçado por `publicador/produtos/{produto}/acervo` e vive dentro
 * do editor, território de outro dev nesta fase. `Abrir →` sem destino seria
 * botão mudo (EXMP-03), então o `Abrir →` do protótipo (`:273`) não nasce.
 */
export function acervoParaTela(bruto) {
    const dados = objeto(bruto) ? bruto : {};
    const imagens = numeroSeguro(dados.imagens);
    const packs = numeroSeguro(dados.packs);

    return {
        imagens,
        packs,
        nao_medido: imagens === null || packs === null,
        meta: METADO_ACERVO(imagens, packs),
    };
}

/** Reexportado para os três `.jsx` não repetirem o import. */
export { textoSeguro, textoUtil };
