import { cn } from '@/lib/utils';
import { andamentoDe } from './andamentoDoPortao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 186, plano 02 — a PILL de andamento interno da aprovação
// (VOC-02/VOC-05/AND-02).
//
// ⚠️ DESTINO DECLARADO: ela é consumida pela coluna **Andamento** da tabela da
// fila (FILA-13) na **Fase 187**. Não é componente órfão — é a peça que aquela
// fase monta. Enquanto isso é conferida por render direto
// (`tests/js/publicador-aprovacao-vocabulario.test.js`).
//
// ⚠️⚠️ VOC-05 — COR NUNCA É O ÚNICO SINAL: forma + cor + TEXTO, as três de uma
// vez. Quem enxerga mal cor distingue pela FORMA (bolinha cheia × anel
// girando); quem usa leitor de tela ouve o TEXTO; a cor é a terceira camada, e
// nenhuma das três é dispensável. A forma leva `aria-hidden` porque é
// decoração: o estado é dito pelo rótulo.
//
// MEDIDAS DO PROTÓTIPO (`design_handoff_publicador/Aprovacao.dc.html`, linhas
// MEDIDAS com `grep -n` neste arquivo, não copiadas da tabela do plano):
//   · a pill         → :170  `display:flex;align-items:center;gap:6px;height:22px;`
//                            `padding:0 8px;border-radius:6px;white-space:nowrap;`
//                            `border:1px solid <borda>;background:<fundo>;`
//                            `color:<texto>;font-size:11.5px;font-weight:600`
//   · o anel girando → :171  `width:9px;height:9px;border-radius:50%;`
//                            `border:1.5px solid <texto>;border-right-color:transparent`
//   · a bolinha      → :172  `width:6px;height:6px;border-radius:50%;background:<texto>`
//
// ⚠️⚠️ MEDIÇÃO QUE A TABELA DO PLANO NÃO TRAZIA, e que muda o código: dentro da
// pill, a bolinha e o anel são pintados com `r.stColor`, que é `m[1]` — a COR DO
// TEXTO —, e **NÃO** com `m[4]`, a cor de dot de `STM`. O `m[4]` é usado em
// outro lugar: nos cards da faixa de andamento (`:1217`, `dot: m[4]`), que são
// da Fase 187. Daí `bg-current` e `border-current` aqui: é o MESMO valor
// computado da cor que a própria pill declara, com uma classe só — e sem a
// chance de o dot e o texto divergirem numa edição futura. O campo `dot` de
// `ANDAMENTOS` continua existindo e é da faixa, não desta pill.
//
// ⚠️ `duration-[Nms]`, `delay-[…]` e `ease-[…]` NÃO COMPILAM neste projeto
// (`tailwindcss-animate` torna a classe ambígua), então o anel usa a animação
// NOMEADA `animate-pub-spin`, que já existe em `tailwind.config.js` (GATE-09).
// ⚠️ DIVERGÊNCIA DECLARADA: `pub-spin` é `spin .7s linear infinite` e o
// protótipo declara `.8s` (`:171`). O plano manda usar o token existente, e
// 100 ms num anel de 9px não é discernível; registrado na 186-02-SUMMARY para
// quem quiser o décimo exato ter de criar um token novo DE PROPÓSITO, e não por
// acidente.
//
// ⚠️⚠️ REND-01/REND-03 — O FALLBACK QUE NÃO É `null`. Toda a guarda de tipo
// mora em `andamentoDe()`, que devolve SEMPRE um objeto desenhável: chave
// conhecida → o estado; string desconhecida → a chave COMO CHEGOU, na forma
// neutra; qualquer outra coisa → "—". É por isso que aqui não há um único
// `if (!estado) return null`: esse `return null` É o bug S3, e o sintoma dele
// em produção é o selo renderizando um retângulo colorido SEM TEXTO, com a
// suíte inteira verde.
//
// ⚠️ Nenhum `String(valor)` nem `obj[valor]` neste arquivo: os dois LANÇAM com
// `{ toString: null }`, e a tela preta de 07/10 voltou QUATRO vezes na Fase 180
// exatamente assim.
// ═══════════════════════════════════════════════════════════════════════════

/** A base da pill (`:170`) — igual nos 8 estados; o que varia é cor/fundo/borda. */
const BASE =
    'inline-flex items-center gap-[6px] h-[22px] px-[8px] rounded-pub-chip whitespace-nowrap border text-[11.5px] font-semibold';

/**
 * A pill de andamento interno.
 *
 * @param {object} props
 * @param {string|null} [props.andamento]  uma das 8 chaves pt-BR do banco
 *   (`preparando`, `aguardando_aprovacao`, `em_revisao_ecf`,
 *   `ajustes_solicitados`, `aprovado`, `publicando`, `falha`, `publicado`).
 *   ⚠️ Chave desconhecida NÃO apaga o selo: aparece como chegou, na forma
 *   neutra. Valor que não é string vira "—".
 * @param {string|null} [props.className]  classe extra da RAIZ (posicionamento
 *   na célula da tabela, por exemplo). ⚠️ Só vale como string: número, objeto e
 *   array são ignorados, no molde da prop `corpo` de
 *   `SeloDeStatusDaPublicacao`. Sem essa guarda, `clsx` transformaria `42` na
 *   classe `42` e `{ a: 1 }` na classe `a`.
 */
export default function SeloDeAndamentoInterno({ andamento = null, className = null }) {
    const estado = andamentoDe(andamento);
    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    return (
        <span className={cn(BASE, estado.fundo, estado.borda, estado.texto, classeExtra)}>
            {estado.spinner ? (
                // O anel ABERTO girando (`:171`). `border-r-transparent` é o que
                // abre o anel: sem ele a forma é um círculo fechado e a rotação
                // não se vê.
                <span
                    aria-hidden="true"
                    className="h-[9px] w-[9px] flex-none rounded-full border-[1.5px] border-current border-r-transparent animate-pub-spin"
                />
            ) : (
                // A bolinha cheia (`:172`).
                <span aria-hidden="true" className="h-[6px] w-[6px] flex-none rounded-full bg-current" />
            )}
            {estado.rotulo}
        </span>
    );
}
