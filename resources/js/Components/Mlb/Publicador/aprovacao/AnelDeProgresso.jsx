import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 08 — O ANEL DE PROGRESSO: a célula 2 da linha da oferta.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas). ⚠️ **CITE O DIRETÓRIO:** há DUAS cópias do pacote de handoff
// com conteúdo DIFERENTE (`design_handoff_publicador/` × `new_layout_publicador/`)
// e a segunda é a versão ANTIGA — a ERRADA para esta fase.
//
// A geometria é `:135` e os quatro estados são `:1241`, medidos por `sed`:
//
//   `:135`   width:16px;height:16px;border-radius:50%;border:{{progBorder}};
//            background:{{progBg}};color:#0A0A0A;display:grid;place-items:center;
//            font-size:9.5px;font-weight:800   + title="{{ r.progTitle }}"
//   `:1241`  PG = { new:     ['',  '1.5px solid #FACC15', 'transparent',
//                             'Ainda não analisada'],
//                   partial: ['',  '1.5px solid #A1A1AA',
//                             'linear-gradient(90deg,#A1A1AA 50%,transparent 50%)',
//                             'Revisão parcial salva'],
//                   done:    ['✓', '1.5px solid #22C55E', '#22C55E', 'Aprovada'],
//                   fail:    ['!', '1.5px solid #EF4444', '#EF4444',
//                             'Falha na publicação'] }
//
// ⚠️ O plano prescreve `font-extrabold` na tabela FID-01, e o **GATE-03** de
// `publicador-vocabulario-novo.test.js` (Fase 177) **PROÍBE** a escala nomeada
// em todo arquivo de `ARQUIVOS_NOVOS`. A casa escreve `font-[800]` — o MESMO
// peso computado, por fora da escala nomeada. É o que `TabelaDaFila.jsx:141`
// (wave 7), `CabecalhoDaFila.jsx:186` (wave 4) e `PopoverDeFiltro.jsx:181`
// (wave 6) já fazem, pelo mesmo motivo medido.
//
// ═══ ⚠️⚠️ O ANEL NUNCA SAI VAZIO, E ESSE É O INVARIANTE CARO ═══════════════
//
// Em DOIS dos quatro estados o glifo é **string vazia** (`new` e `partial`): o
// sinal deles é a BORDA e o FUNDO. ➡️ Guarda que devolvesse `null` para chave
// desconhecida produziria um elemento **sem borda, sem fundo e sem texto** —
// que **passa em todo teste de render** (o HTML existe) e **DESAPARECE na
// tela**. É a mesma família do `andamentoDe()` da Fase 186, que NUNCA devolve
// `null` de propósito.
// ➡️ Aqui: chave desconhecida, nula, ausente, NaN, objeto ou `{toString:null}`
// cai no anel **NEUTRO**, e o `title` é SEMPRE texto NÃO-VAZIO. ⚠️ `title=""`
// é um `title` AUSENTE — e aí a cor viraria o único sinal, que é a regra
// permanente que este projeto não quebra.
//
// ⚠️ **A COR NUNCA É O ÚNICO SINAL:** além da borda/fundo, o `title` nomeia o
// estado por extenso, e a célula vizinha (Andamento) traz a pill com o rótulo
// escrito. Daltônico não fica sem informação.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe num `.js` não gera CSS e
// NÃO EXISTE PARA A TELA, com todo teste de render passando.
// ⚠️ **Nenhuma classe é montada por CONCATENAÇÃO** — o scanner do Tailwind não
// vê `border-[${n}px]`. Os quatro estados são literais, num mapa fechado.
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`:
// os três LANÇAM com `{toString: null}` e derrubam a página inteira (a TELA
// PRETA de 07/10/2026, que voltou QUATRO vezes na Fase 180 e UMA na wave 6 —
// aquela no CLIQUE).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * `:135` — a geometria, igual nos quatro estados. O que varia é borda/fundo/glifo.
 *
 * ⚠️ `border-[1.5px]` mora AQUI (é igual nos quatro, `:1241`) para que nenhum
 * estado possa ficar sem borda por esquecimento.
 */
const BASE =
    'grid h-[16px] w-[16px] place-items-center rounded-full border-[1.5px] text-[9.5px] font-[800] text-pub-page';

/**
 * Os QUATRO estados de `:1241`, com as chaves pt-BR que
 * `LinhaDaOfertaService::PROGRESSO_*` manda.
 *
 * ⚠️ O serviço decide QUAL CASO é; a tradução para NOME DE TOKEN é aqui — e não
 * pode ser no PHP, porque o `content` do Tailwind não varre `.php` (a classe
 * não geraria CSS e não existiria para a tela).
 *
 * ⚠️ `bg-pub-anel-parcial` é o token de `backgroundImage` do plano 187-01
 * (`tailwind.config.js:338`), com o gradiente literal de `:1241`:
 * `linear-gradient(90deg,#A1A1AA 50%,transparent 50%)`. Ele **ACORDA nesta
 * wave** e há gate provando a regra no CSS CONSTRUÍDO — "está escrito no JSX"
 * não é "existe na tela".
 */
export const ESTADOS = {
    nao_analisada: {
        glifo: '',
        aparencia: 'border-pub-accent bg-transparent',
        titulo: 'Ainda não analisada',
    },
    parcial: {
        glifo: '',
        aparencia: 'border-pub-txt-2 bg-pub-anel-parcial',
        titulo: 'Revisão parcial salva',
    },
    aprovada: {
        glifo: '✓',
        aparencia: 'border-pub-ok bg-pub-ok',
        titulo: 'Aprovada',
    },
    falha: {
        glifo: '!',
        aparencia: 'border-pub-danger bg-pub-danger',
        titulo: 'Falha na publicação',
    },
};

/**
 * O anel de quem chegou com chave que não é chave.
 *
 * ⚠️⚠️ **Ele EXISTE de propósito, e não é zelo.** Devolver `null` aqui daria um
 * elemento vazio que passa no render e desaparece na tela; devolver o `parcial`
 * AFIRMARIA "revisão parcial salva" sobre uma oferta de que não se sabe nada.
 * O neutro não afirma nada — e o `title` diz exatamente isso, por extenso.
 */
export const NEUTRO = {
    glifo: '',
    aparencia: 'border-pub-line-off bg-transparent',
    titulo: 'Progresso não informado',
};

/**
 * O estado do anel, a partir da chave do servidor.
 *
 * ⚠️ `Object.prototype.hasOwnProperty.call` e **NUNCA** `ESTADOS[chave]` cru nem
 * `chave.hasOwnProperty(...)`: chave vinda do servidor indexando mapa é o
 * caminho da TELA PRETA de 07/10/2026, e `hasOwnProperty` PRÓPRIO **LANÇA** com
 * `{hasOwnProperty: null}`.
 * ⚠️ `typeof === 'string'` ANTES: `Object.prototype.hasOwnProperty.call(m, {})`
 * coage a chave para `"[object Object]"` em vez de lançar, mas um número `0`
 * casaria índice em outro mapa — a guarda de TIPO fecha a classe inteira.
 *
 * @param {unknown} chave  `nao_analisada` | `parcial` | `aprovada` | `falha`
 * @returns {{glifo: string, aparencia: string, titulo: string, conhecido: boolean}}
 */
export function estadoDoAnel(chave) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(ESTADOS, chave)) {
        return { ...ESTADOS[chave], conhecido: true };
    }

    return { ...NEUTRO, conhecido: false };
}

/**
 * O anel de progresso da linha da oferta.
 *
 * @param {object} props
 * @param {unknown} [props.progresso] a chave de `LinhaDaOfertaService::PROGRESSO_*`.
 *   ⚠️ Qualquer outra coisa cai no anel NEUTRO — **nunca** `null`, nunca
 *   elemento sem `title`.
 * @param {unknown} [props.className] classe extra da raiz (posicionamento na
 *   célula). ⚠️ Só vale como string: número, objeto e array são ignorados, no
 *   molde de `SeloDeAndamentoInterno`. Sem a guarda, `clsx` transformaria `42`
 *   na classe `42` e `{a: 1}` na classe `a`.
 */
export default function AnelDeProgresso({ progresso = null, className = null }) {
    const estado = estadoDoAnel(progresso);
    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    return (
        <span title={estado.titulo} className={cn(BASE, estado.aparencia, classeExtra)}>
            {estado.glifo}
        </span>
    );
}
