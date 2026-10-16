import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 07 — OS MINI-QUADRADOS DE FOTO (FILA-09).
//
// Os quadradinhos que dizem, de relance, em que pé está cada foto da galeria
// final. Dois tamanhos no protótipo, e eles são DIFERENTES de propósito:
//   · **10px** na sub-linha de conjunto (`:127`)
//   · **12px** na célula "Fotos" da linha da oferta (`:153`)
// ⚠️ "Deixei igual" é **FID-02**, justificativa VETADA.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`), e os ponteiros abaixo foram MEDIDOS
// com `awk`/`sed` nesta sessão (16/10/2026):
//
//   · `sq()`            → `:1115`-`:1122` (o ternário do `title` é `:1117`)
//   · quadrado de 10px  → `:127`  (`width:10px;height:10px;border-radius:3px`)
//   · quadrado de 12px  → `:153`  (idem, com 12px)
//   · a fila dos dois   → `display:flex;gap:3px` (+ `margin-left:4px` só em `:127`)
//
// ═══ ⚠️⚠️ OS 4 ESTADOS, E A ORDEM DO TERNÁRIO IMPORTA ══════════════════════
//
// `sq()` decide nesta ordem (`:1118`-`:1121`), e trocá-la muda o desenho:
//   1. `approved`      → fundo `#22C55E`, borda `1.5px solid #22C55E`
//   2. `regenerating`  → fundo transparente, borda `1.5px **dashed** #71717A`
//   3. com alerta      → fundo `rgba(239,68,68,0.3)`, borda `1.5px solid #EF4444`
//   4. (o resto)       → fundo transparente, borda `1.5px solid #FBBF24`
//
// ⚠️ Repare que `regenerating` vem ANTES do alerta: uma foto sendo regerada com
// alerta é TRACEJADA, não vermelha. Avaliar o alerta primeiro pintaria de
// vermelho uma foto que está sendo trocada — e ninguém precisa conferir o que
// vai ser substituído em segundos (IMG-09).
//
// ═══ ⚠️⚠️ O ESTADO `regenerando` NÃO VEM DO SERVIDOR HOJE, E ISSO É MEDIDO ══
//
// `PubAprovacaoImagem::NO_CONJUNTO_FINAL` é `[pending, approved]`, e
// `noConjuntoFinal()` exige estado nessa lista — então **a galeria final nunca
// contém `regenerating`** (ver o docblock de `GaleriaDaOfertaService`). Alargar a
// galeria para fabricar o quadrado tracejado faria a contagem dos quadrados
// divergir de `BloqueiosDaOfertaService::contagemDeFotos()`, que é exatamente a
// verdade declarada do plano (*"os mini-quadrados contam o MESMO que o bloqueio
// conta"*), e exigiria mexer na Fase 186 — **território INTOCÁVEL**.
// ➡️ O ramo existe aqui, com o literal do protótipo, e é conferido por **props
// diretas**. ⚠️ **Não "consertar" isto**: a decisão é do orquestrador (16/10/2026)
// e o outro caminho é território proibido.
//
// ═══ ⚠️ A COR NUNCA É O ÚNICO SINAL (VOC-05) ═══════════════════════════════
//
// Um quadrado de 10px não cabe texto, então o sinal não-cromático é a **FORMA**
// (preenchido × vazado × **tracejado**) e o **`title`**, que diz o estado por
// extenso: `"<rótulo> · aprovada | com alerta | a conferir"`, montado no SERVIDOR
// (`GaleriaDaOfertaService::tituloDoQuadrado()`).
// ⚠️⚠️ **DIVERGÊNCIA D7, reproduzida e NÃO consertada:** o ternário do protótipo
// (`:1117`) só distingue `approved` e "com alerta", então `regenerating` diz
// **"a conferir"** embora a borda seja tracejada. O protótipo ganha nos rótulos.
//
// ⚠️ **`title` vazio é `title` AUSENTE.** Quadrado sem `title` seria cor como
// único sinal, e por isso o atributo só é escrito com texto não-vazio — e o gate
// exige que todos os quadrados desenhados o tenham.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe num `.js` não gera CSS e
// NÃO EXISTE PARA A TELA, com todo teste de render passando.
//
// ⚠️ **Nenhuma classe é montada por CONCATENAÇÃO.** `h-[${n}px]` o scanner do
// Tailwind não vê: a classe fica no HTML, o render passa e o quadrado sai sem
// tamanho na tela. Os dois tamanhos são LITERAIS, num mapa fechado.
//
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`:
// os três LANÇAM com `{toString: null}` e derrubam a página inteira (a TELA
// PRETA de 07/10/2026, que voltou QUATRO vezes na Fase 180 e UMA na wave 6 —
// aquela no CLIQUE). Toda chave passa por guarda de TIPO antes.
// ═══════════════════════════════════════════════════════════════════════════

/** As chaves de estado da revisão, em pt-BR do banco — `PubAprovacaoImagem`. */
export const APROVADA = 'approved';
export const REGENERANDO = 'regenerating';

/**
 * Os dois tamanhos do protótipo, como classes LITERAIS.
 *
 * ⚠️ Mapa FECHADO e literal de propósito: tamanho fora dele cai no de 10px (o da
 * sub-linha), nunca numa classe inventada. E nunca `h-[${n}px]` — ver o topo.
 */
const TAMANHOS = {
    10: 'h-[10px] w-[10px]',
    12: 'h-[12px] w-[12px]',
};

/** `:127`/`:153` — igual nos dois tamanhos; o que varia é o lado. */
const CLASSES_BASE = 'flex-none rounded-[3px] border-[1.5px]';

/** Os 4 estados, literais de `sq()` (`:1118`-`:1121`). */
const APARENCIA_APROVADA = 'bg-pub-ok border-pub-ok';
const APARENCIA_REGENERANDO = 'bg-transparent border-dashed border-pub-txt-4';
const APARENCIA_COM_ALERTA = 'bg-pub-danger/[0.3] border-pub-danger';
const APARENCIA_A_CONFERIR = 'bg-transparent border-pub-warn-txt';

/** `:127` / `:153` — a fila dos quadrados. O `ml-[4px]` de `:127` vem por `className`. */
const CLASSES_FILA = 'flex gap-[3px]';

/**
 * A aparência de UM quadrado, na ordem do ternário de `sq()`.
 *
 * ⚠️ **Exportada** porque é o que torna os 4 estados conferíveis um a um sem
 * depender de a galeria do servidor emitir cada um — e o `regenerando` ela NÃO
 * emite (ver o topo).
 *
 * @param {unknown} estado  a chave de estado da revisão
 * @param {unknown} comAlerta  `true` estrito; qualquer outra coisa é "sem alerta"
 */
export function aparenciaDoQuadrado(estado, comAlerta) {
    // ⚠️ Guarda de TIPO antes de comparar: `estado` chega do servidor e pode ser
    // objeto, nulo, NaN ou array. Nenhuma comparação abaixo lança, mas a guarda
    // deixa explícito que "não é string" cai no default — nunca num ramo por
    // acidente de coerção.
    const chave = typeof estado === 'string' ? estado : '';

    if (chave === APROVADA) return APARENCIA_APROVADA;
    // ⚠️ ANTES do alerta, como no protótipo (`:1119` vem antes de `:1120`).
    if (chave === REGENERANDO) return APARENCIA_REGENERANDO;
    // ⚠️ Igualdade ESTRITA com `true`: `com_alerta` chegando `'sim'`, `1` ou `{}`
    // não é alerta. Pintar de vermelho por truthiness afirmaria um alerta que
    // ninguém levantou.
    if (comAlerta === true) return APARENCIA_COM_ALERTA;

    return APARENCIA_A_CONFERIR;
}

/**
 * A fila de mini-quadrados de uma galeria.
 *
 * ⚠️ **Lista vazia ou inválida não desenha casca nenhuma** (D4): sem foto não há
 * fila de quadrados, e um `<span>` vazio de 10px seria decoração.
 *
 * @param {object} props
 * @param {unknown} [props.quadrados] lista de `{id, estado, com_alerta, titulo}` do servidor
 * @param {unknown} [props.tamanho] `10` (sub-linha) ou `12` (linha da oferta)
 * @param {unknown} [props.className] classe extra da fila (o `ml-[4px]` de `:127`)
 */
export default function QuadradosDeFoto({ quadrados = null, tamanho = 10, className = null }) {
    // ⚠️ `Array.isArray` ANTES de `.map`: `quadrados` chegando string tem `.length`
    // e `.map` não existe — seria `TypeError` na renderização.
    const lista = Array.isArray(quadrados) ? quadrados : [];

    if (lista.length === 0) return null;

    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    return (
        <span className={cn(CLASSES_FILA, classeExtra)}>
            {lista.map((cru, indice) => {
                // ⚠️⚠️ **TODA FLAG NASCE DENTRO DO CALLBACK.** O Rollup elimina
                // variável de escopo do componente usada dentro do `.map()` e o
                // `ReferenceError` só aparece no bundle de produção — já mordeu
                // este projeto, e aqui são três `.map()` aninhados contando a
                // tabela inteira.
                const item = cru !== null && typeof cru === 'object' && !Array.isArray(cru) ? cru : null;
                const estado = item === null ? null : item.estado;
                const comAlerta = item === null ? null : item.com_alerta;
                const titulo = item !== null && typeof item.titulo === 'string' && item.titulo.trim() !== ''
                    ? item.titulo
                    : null;
                const lado = TAMANHOS[tamanho === 12 ? 12 : 10];
                // ⚠️ A chave do React nunca interpola valor do servidor: `id` só
                // entra quando é inteiro, senão o índice. `${objeto}` LANÇARIA.
                const chave = item !== null && typeof item.id === 'number' && Number.isInteger(item.id)
                    ? `q-${item.id}`
                    : `q-i-${indice}`;

                return (
                    <span
                        key={chave}
                        // ⚠️ `title` só com texto: `title=""` é `title` ausente, e
                        // aí a cor viraria o único sinal. ⚠️ `?? undefined` e
                        // nunca `String(...)`.
                        title={titulo ?? undefined}
                        className={cn(CLASSES_BASE, lado, aparenciaDoQuadrado(estado, comAlerta))}
                    />
                );
            })}
        </span>
    );
}
