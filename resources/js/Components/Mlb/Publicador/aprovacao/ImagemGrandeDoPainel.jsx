import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 08 — A IMAGEM GRANDE 1:1 DA COLUNA DE FOTOS, os quatro selos
// e os MARCADORES NUMERADOS nas coordenadas x/y REAIS.
//
// ⚠️⚠️ AS COORDENADAS VÃO POR `style`, NUNCA POR CLASSE ═══════════════════════
//
// `left-[…%]` montado por concatenação de string **o scanner do Tailwind NÃO
// VÊ**: a classe fica no HTML, todo teste de render passa, e **o marcador não
// sai do canto**. É a mesma família do `*.js` que o `content` do
// `tailwind.config.js` não varre. ➡️ `style={{ left, top }}`, e há gate provando
// que não existe `left-[`/`top-[` montado por template literal neste arquivo,
// com isca da forma errada.
//
// ⚠️⚠️ A SEGUNDA TRANCA DA MESMA PORTA. O servidor
// (`FotosDoPainelService::noQuadro()`) já saneia `x`/`y` a 0–100, e aqui se
// saneia DE NOVO: coordenada que não é número FINITO dentro da faixa **não
// desenha marcador**. ⚠️ `typeof NaN === 'number'` — `Number.isFinite` é o teste
// certo, `typeof` não é.
//
// ⚠️⚠️ O ORDINAL É LIDO, NUNCA RECONTADO. `marcadores[*].ordinal` sai de
// `FotosDoPainelService::alertasOrdenados()`, que é a ÚNICA numeração: o número
// do marcador e o número do item de verificação saem do MESMO array. Renumerar
// aqui faria o texto dizer "2" com o marcador 2 em OUTRO defeito — e a pessoa
// reprovaria a foto errada. ⚠️ A wave 188-03 achou a armadilha de raiz:
// `PubAprovacaoImagem::alertas()` não tinha `orderBy`, e MariaDB × SQLite
// devolviam ordens DIFERENTES (normalizado em `porId()`).
//
// ⚠️⚠️ DUAS AÇÕES DO PROTÓTIPO NÃO SÃO DESTA FASE, e isso é D4/D14:
//   · "⤢ Ampliar" (`design_handoff_publicador/Aprovacao.dc.html:607`) abre o
//     LIGHTBOX, que é a **Fase 189**;
//   · "✦ Gerar novamente com IA" (`:606`) abre o modal de regeneração, que é a
//     **Fase 192**.
// ➡️ Os dois **NÃO são desenhados aqui**, e há gate de AUSÊNCIA. ⚠️ Botão sem
// destino é D4 violado, e `onClick` vazio é a mesma dívida com outra roupa.
// ⚠️ Os ponteiros do PLANO (`:607`/`:608`) estavam deslocados em +1: `:608` é um
// `</span>` puro. Os corretos são `:606` e `:607`, medidos por `sed` nesta wave
// e já corrigidos na `188-FID01.md` §C (C13/C14).
//
// ⚠️ "Gerando nova versão…" (`:605`, era `:606` na prosa do plano) **SIM é desta
// fase** — é ESTADO, não ação: a imagem pode chegar em `regenerating`, e não
// mostrá-lo deixaria a pessoa aprovando uma foto que está sendo refeita.
// ⚠️ O spinner do protótipo é `animation:spin .8s`; o token do projeto
// (`animate-pub-spin`) é `spin .7s` — **valores DIFERENTES, medidos**. A classe
// entrega os keyframes e a curva; a DURAÇÃO do protótipo entra por `style`,
// porque `duration-[Nms]` **NÃO COMPILA** neste projeto.
//
// ⚠️ `url_arquivo === null` é caso REAL (`PubImagem.caminho` nulo → a rota faz
// `abort(404)`). Nesse caso a tela mostra o RÓTULO como placeholder — como o
// próprio protótipo faz (`:595`, `placeholder="{{ P.sel.label }}"`) — e
// **nunca** `<img src="">` (string vazia num `src` faz o navegador recarregar a
// própria página) nem ícone de imagem quebrada. Disciplina do IMG-13.
//
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` sobre chave NÃO-string e
// nenhum `obj[chave]` cru: os três **LANÇAM** com `{toString: null}`, e a TELA
// PRETA voltou SEIS vezes neste projeto — uma delas **no clique**.
// ⚠️ Toda flag computada DENTRO do callback do `.map()`: o Rollup elimina
// variável de escopo do componente usada dentro do mapa.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As CHAVES SEMÂNTICAS de cor que o servidor manda (TOK-15: nenhum hex sai do
 * PHP), lidas de {@see FotosDoPainelService} — `verde`, `ambar`, `vermelho`,
 * `neutra`, `neutra_fraca`.
 *
 * ⚠️ Elas moram neste arquivo (e não num quinto módulo) de propósito: o plano
 * 188-08 cria QUATRO `.jsx`, e cada arquivo novo precisa entrar em
 * `ARQUIVOS_NOVOS` e em `JSX_DEPOIS_DA_187`. Este é o primeiro da coluna e não
 * importa nenhum irmão, então é a única casa possível sem criar ciclo.
 */
export const TEXTO_DA_COR = {
    verde: 'text-pub-ok-txt',
    ambar: 'text-pub-warn-txt',
    vermelho: 'text-pub-danger-txt',
    neutra: 'text-pub-neutral-txt',
    neutra_fraca: 'text-pub-neutral-txt',
};

/** A mesma chave, do lado do FUNDO (o dot do selo, o marcador, os badges). */
export const FUNDO_DA_COR = {
    verde: 'bg-pub-ok',
    ambar: 'bg-pub-warn',
    vermelho: 'bg-pub-danger',
    neutra: 'bg-pub-neutral-dot',
    neutra_fraca: 'bg-pub-txt-off',
};

/**
 * ⚠️⚠️ O NEUTRO de chave desconhecida — e ele **nunca** cai no verde.
 *
 * Afirmar aprovação que não existe é o pior lado do erro nesta coluna, porque
 * **aprovar JÁ ENFILEIRA e não há desfazer**.
 */
export const COR_NEUTRA = 'neutra';

/** A faixa do percentual de `x`/`y` — a mesma de `FotosDoPainelService::noQuadro()`. */
export const PONTO_MINIMO = 0;

export const PONTO_MAXIMO = 100;

/**
 * Lê um mapa de chave semântica sem nunca indexar com chave adversa.
 *
 * ⚠️ `mapa[chave]` cru **LANÇA** com `{toString: null}`, e `hasOwnProperty`
 * sobre chave não-string também. A guarda de tipo vem ANTES.
 */
export function classeDaCor(mapa, chave) {
    const nome = typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave)
        ? chave
        : COR_NEUTRA;

    return mapa[nome];
}

/** Texto seguro para a tela, ou `null`. ⚠️ Nunca `String(valor)`. */
export function textoDoPainel(valor) {
    return typeof valor === 'string' && valor.trim() !== '' ? valor : null;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é **0**. */
export function inteiroDoPainel(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/** Objeto comum de verdade, ou `null`. ⚠️ `null` e array são os dois `typeof 'object'`. */
export function objetoDoPainel(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** A lista, ou `[]`. ⚠️ String e número **não** são lista, e `.map` neles LANÇA. */
export function listaDoPainel(valor) {
    return Array.isArray(valor) ? valor : [];
}

/**
 * ⚠️⚠️ O percentual está DENTRO do quadro?
 *
 * ⚠️ `typeof NaN === 'number'` — é por isso que o teste é `Number.isFinite`, e
 * não `typeof`. Coordenada fora da faixa **não desenha marcador**: marcador
 * fantasma no canto aponta para um defeito que não está ali.
 */
export function noQuadro(valor) {
    return typeof valor === 'number'
        && Number.isFinite(valor)
        && valor >= PONTO_MINIMO
        && valor <= PONTO_MAXIMO;
}

// ─── As classes, uma casa só (`:594`-`:605`) ───────────────────────────────

/** `:594` — a caixa 1:1 da imagem grande. */
export const CLASSES_CAIXA = 'relative aspect-square overflow-hidden rounded-pub-sub bg-pub-hover-2 text-pub-txt-off';

/** `:595` — a imagem, `fit="contain"`. */
export const CLASSES_IMAGEM = 'absolute inset-0 h-full w-full object-contain';

/** `:595` — o placeholder quando `url_arquivo` é `null` (o RÓTULO, como no protótipo). */
export const CLASSES_PLACEHOLDER = 'absolute inset-0 grid place-items-center px-[14px] text-center text-[12px]';

/**
 * `:596` — o marcador. ⚠️ `left`/`top` vão por `style`; aqui só o que é FIXO.
 *
 * ⚠️ `font-[800]` e não `font-extrabold`: o GATE-03 fecha a escala nomeada em
 * 400/500/600/700, e o arbitrário entrega o MESMO peso computado (precedente de
 * `AnelDeProgresso.jsx`, em produção).
 * ⚠️ O brilho `0 0 0 6px rgba(239,68,68,0.18)` é FIXO no protótipo — ele não
 * muda com a severidade, e isso foi medido em `:596`.
 */
export const CLASSES_MARCADOR = 'pointer-events-none absolute grid h-[24px] w-[24px] -translate-x-1/2 -translate-y-1/2 '
    + 'place-items-center rounded-full border-2 border-pub-page text-[11px] font-[800] text-pub-page '
    + 'shadow-[0_0_0_6px_rgba(239,68,68,0.18)]';

/** `:597` — o wrapper dos selos no canto SUPERIOR-ESQUERDO. */
export const CLASSES_SELOS = 'pointer-events-none absolute left-[10px] top-[10px] flex gap-[6px]';

/** A base dos quatro selos de `:598`-`:601` — `height:22px` e `white-space:nowrap`. */
export const CLASSES_SELO_BASE = 'flex h-[22px] items-center whitespace-nowrap rounded-pub-chip px-[8px]';

/** `:598` — o selo `#n`. */
export const CLASSES_SELO_POSICAO = `${CLASSES_SELO_BASE} bg-pub-page/[0.8] text-[11.5px] font-semibold`;

/** `:599` — `★ PRINCIPAL`. */
export const CLASSES_SELO_PRINCIPAL = `${CLASSES_SELO_BASE} bg-pub-accent text-pub-page text-[11px] font-bold`;

/** `:600` — `✦ Gerada por IA` (o `✦` em `pub-accent`). */
export const CLASSES_SELO_IA = `${CLASSES_SELO_BASE} gap-[5px] bg-pub-page/[0.8] text-pub-txt text-[11.5px] font-semibold`;

/** `:601` — `Original`, idem sem o ✦ e com `#D4D4D8`. */
export const CLASSES_SELO_ORIGINAL = `${CLASSES_SELO_BASE} bg-pub-page/[0.8] text-pub-fase1 text-[11.5px] font-semibold`;

/** `:603` — o selo de ESTADO, no canto superior-direito. */
export const CLASSES_SELO_ESTADO = 'pointer-events-none absolute right-[10px] top-[10px] flex h-[22px] items-center '
    + 'gap-[6px] whitespace-nowrap rounded-pub-chip bg-pub-page/[0.8] px-[8px] text-[11.5px] font-semibold';

/** `:603` — o dot de 6px do selo de estado. */
export const CLASSES_DOT = 'h-[6px] w-[6px] rounded-full';

/**
 * `:604` — o wrapper dos botões do overlay, no canto INFERIOR-DIREITO.
 *
 * ⚠️ Ele estava AUSENTE da `188-FID01.md` original (achado da re-medição do
 * 188-01, C11w): sem ele "Gerando nova versão…" não tem posição nenhuma.
 */
export const CLASSES_OVERLAY = 'absolute bottom-[10px] right-[10px] flex gap-[6px]';

/** `:605` — "Gerando nova versão…". ⚠️ `white-space:nowrap` é a PRIMEIRA propriedade da linha. */
export const CLASSES_REGENERANDO = 'flex h-[30px] items-center gap-[7px] whitespace-nowrap rounded-[8px] '
    + 'bg-pub-page/[0.85] px-[11px] text-[12px] text-pub-fase1';

/** `:605` — o spinner de 10px. ⚠️ A duração do protótipo entra por `style` (ver o docblock). */
export const CLASSES_SPINNER = 'h-[10px] w-[10px] animate-pub-spin rounded-full border-[1.5px] border-pub-accent border-r-transparent';

/** `:605`, byte a byte — as reticências são `…` (U+2026), um caractere. */
export const REGENERANDO = 'Gerando nova versão…';

/** `:599`, byte a byte. */
export const PRINCIPAL = '★ PRINCIPAL';

/** `:600`/`:615` — o glifo de "gerada por IA". */
export const GLIFO_IA = '✦';

/** `:1104` — o estado de regeneração, a única chave que acende `:605`. */
export const ESTADO_REGENERANDO = 'regenerating';

/**
 * ⚠️ A duração do spinner do protótipo (`:605`, `animation:spin .8s`).
 *
 * O token `animate-pub-spin` é `.7s` — **medido, e diferente**. A classe entrega
 * os keyframes e a curva; este `style` entrega a duração do protótipo, porque
 * `duration-[Nms]`/`animate-[...]` arbitrários **não compilam** aqui.
 * ⚠️ O que roda no NAVEGADOR não é provado por esta fase (não há navegador nesta
 * máquina): o gate prova a FONTE e o CSS construído, nunca o movimento.
 */
export const DURACAO_DO_SPINNER = '.8s';

/**
 * ⚠️⚠️ Os marcadores DESENHÁVEIS — a lista saneada, com o ordinal PRESERVADO.
 *
 * ⚠️ Coordenada adversa **sai da lista**, e o ordinal dos que ficam **não é
 * recontado**: é o que o servidor mandou. A assimetria (item de texto com número
 * cujo marcador não existe) é DECLARADA e intencional — renumerar faria o texto
 * dizer "3" com o marcador escrito "4" (`188-FID01.md` §K).
 */
export function marcadoresDesenhaveis(marcadores) {
    return listaDoPainel(marcadores)
        .map((bruto) => {
            // ⚠️ Flag DENTRO do callback: o Rollup elimina variável de escopo do
            // componente usada dentro do `.map()`.
            const marcador = objetoDoPainel(bruto);
            if (marcador === null) return null;

            const ordinal = inteiroDoPainel(marcador.ordinal);
            if (ordinal === null) return null;

            return noQuadro(marcador.x) && noQuadro(marcador.y)
                ? { ordinal, x: marcador.x, y: marcador.y, cor: marcador.cor }
                : null;
        })
        .filter((marcador) => marcador !== null);
}

/**
 * ⚠️⚠️ `:596` — os marcadores numerados nas coordenadas x/y REAIS.
 *
 * NAMED EXPORT para o gate medir os marcadores sem montar a imagem toda.
 */
export function MarcadoresDaImagem({ marcadores = null }) {
    const desenhaveis = marcadoresDesenhaveis(marcadores);

    // ⚠️ Lista vazia → **nenhum** `span`, e a imagem continua desenhada. Casca de
    // marcador vazia é D4 violado.
    if (desenhaveis.length === 0) return null;

    return desenhaveis.map((marcador) => (
        <span
            key={`marcador-${marcador.ordinal}`}
            // ⚠️ A cor vem da severidade, e `high` × `med` dão cores DIFERENTES.
            className={cn(CLASSES_MARCADOR, classeDaCor(FUNDO_DA_COR, marcador.cor))}
            // ⚠️⚠️ POR `style`, NUNCA POR CLASSE: `left-[…%]` montado por
            // template literal o scanner do Tailwind não vê.
            style={{ left: `${marcador.x}%`, top: `${marcador.y}%` }}
        >
            {marcador.ordinal}
        </span>
    ));
}

/**
 * `:597`-`:601` — os selos do canto superior-esquerdo.
 *
 * ⚠️ `✦ Gerada por IA` e `Original` são **mutuamente exclusivos** (`:600`/`:601`:
 * `isOrig = m.k !== 'ai'`). Nunca os dois juntos, nunca nenhum dos dois.
 */
export function SelosDaImagem({ imagem = null }) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const posicao = textoDoPainel(dados.posicao_rotulo);
    const tipo = textoDoPainel(dados.tipo_rotulo);
    const deIa = dados.de_ia === true;

    return (
        <span className={CLASSES_SELOS}>
            {posicao === null ? null : <span className={CLASSES_SELO_POSICAO}>{posicao}</span>}
            {dados.principal === true ? <span className={CLASSES_SELO_PRINCIPAL}>{PRINCIPAL}</span> : null}
            {tipo === null ? null : (
                <span className={deIa ? CLASSES_SELO_IA : CLASSES_SELO_ORIGINAL}>
                    {deIa ? <span className="text-pub-accent">{GLIFO_IA}</span> : null}
                    {tipo}
                </span>
            )}
        </span>
    );
}

/**
 * ⚠️⚠️ `:603` — o selo de ESTADO, e ele tem **TEXTO NÃO-VAZIO SEMPRE**.
 *
 * Guarda que devolvesse `null` produziria elemento vazio, que **passa em render e
 * DESAPARECE na tela** (foi o conserto do anel de progresso na 187-08). O
 * servidor já garante o rótulo (`SELO_NEUTRO`); aqui o rótulo ausente **não
 * desenha o selo**, em vez de desenhar uma pílula muda.
 */
export function SeloDeEstado({ imagem = null }) {
    const dados = objetoDoPainel(imagem);
    const rotulo = dados === null ? null : textoDoPainel(dados.estado_rotulo);

    if (rotulo === null) return null;

    return (
        <span className={cn(CLASSES_SELO_ESTADO, classeDaCor(TEXTO_DA_COR, dados.estado_cor))}>
            <span className={cn(CLASSES_DOT, classeDaCor(FUNDO_DA_COR, dados.estado_dot))} />
            {rotulo}
        </span>
    );
}

/**
 * A imagem grande 1:1 com os selos, os marcadores e o aviso de regeneração.
 *
 * @param {object} props
 * @param {unknown} [props.imagem] uma entrada de `painel.fotos.imagens`
 *
 * ⚠️ `imagem` ausente → **nada**: caixa 1:1 vazia num painel sem foto
 * selecionada é D4 violado, e quem diz o vazio é a `ColunaDeFotos`.
 */
export default function ImagemGrandeDoPainel({ imagem = null }) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const url = textoDoPainel(dados.url_arquivo);
    const rotulo = textoDoPainel(dados.rotulo) ?? textoDoPainel(dados.titulo);
    const regenerando = dados.estado === ESTADO_REGENERANDO;

    return (
        <div className={CLASSES_CAIXA}>
            {/* ⚠️ `url_arquivo === null` → o RÓTULO como placeholder. NUNCA
                `<img src="">`: string vazia num `src` faz o navegador recarregar
                a própria página. */}
            {url === null
                ? <span className={CLASSES_PLACEHOLDER}>{rotulo}</span>
                : <img className={CLASSES_IMAGEM} src={url} alt={rotulo ?? ''} />}

            <MarcadoresDaImagem marcadores={dados.marcadores} />
            <SelosDaImagem imagem={dados} />
            <SeloDeEstado imagem={dados} />

            {/* ⚠️ `:604` — o wrapper do canto inferior-direito só existe COM
                conteúdo. "⤢ Ampliar" (Fase 189) e "✦ Gerar novamente com IA"
                (Fase 192) **não** nascem aqui. */}
            {regenerando ? (
                <span className={CLASSES_OVERLAY}>
                    <span className={CLASSES_REGENERANDO}>
                        <span className={CLASSES_SPINNER} style={{ animationDuration: DURACAO_DO_SPINNER }} />
                        {REGENERANDO}
                    </span>
                </span>
            ) : null}
        </div>
    );
}
