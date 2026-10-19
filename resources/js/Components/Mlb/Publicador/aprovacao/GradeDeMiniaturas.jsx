import { cn } from '@/lib/utils';
import {
    GLIFO_IA,
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 08 — A GRADE DE 6 COLUNAS DA COLUNA DE FOTOS (`:610`-`:618`).
//
// ⚠️⚠️ A BORDA DE SELEÇÃO É CALCULADA AQUI, E ISSO É DIVERGÊNCIA DECLARADA ═══
//
// `PainelDeRevisaoService.php:310` chama `FotosDoPainelService::para($oferta)`
// **sem o índice**, e o default é `0`. ➡️ O `borda` e o `anel` que chegam no
// payload estão **presos na foto de índice 0**, e o protótipo tem o mesmo defeito
// por construção (ele é estático). Usá-los crus deixaria o anel amarelo na
// primeira miniatura **para sempre**, qualquer que fosse a foto aberta.
// ➡️ A seleção VIVA mora na página (plano 188-08) e a borda é resolvida aqui,
// **espelhando `FotosDoPainelService::borda()` ramo por ramo**. As cinco chaves
// semânticas são as DELE (`BORDA_*`), e há gate lendo o PHP e comparando os
// conjuntos: duas numerações da mesma verdade divergiriam.
// ⚠️ **NÃO** se altera o serviço — território da wave 188-03, somente leitura.
//
// ⚠️⚠️ AS DUAS OMISSÕES DO REVS-09 (`.planning/REQUIREMENTS-v27.md:596` lista
// QUATRO bordas), as duas reproduzidas:
//   (a) o **5º ramo** `#3F3F46` (`pub-line-off`), que pinta `rejected`,
//       `regenerating`, `replaced` e `removed`;
//   (b) o **anel** `0 0 0 2px #FACC15`, que é box-shadow **ADICIONAL** à borda
//       (`:1113`), e só existe na SELECIONADA.
// ⚠️ O anel sai por `ring-2 ring-pub-accent` e não por `shadow-[...#FACC15]`:
// TOK-15 proíbe hex na tela, e o `ring` do Tailwind emite exatamente
// `0 0 0 calc(2px + 0px) <cor>` com o offset default. Há gate medindo o CSS
// construído.
//
// ⚠️ `t.hasAlert` é `al.length && st === 'pending'` (`:1105`) — **UM estado**; e
// `pins` existe em DOIS (`pending` ∪ `rejected`, `:1109`). **São regras
// diferentes de propósito. Não unifique.** O badge `✓` é só de `approved`.
//
// ⚠️ `title` NÃO-VAZIO no botão invisível: elemento sem nome acessível **passa em
// render e desaparece na tela**. O texto é o `titulo` do servidor
// (`GaleriaDaOfertaService::tituloDoQuadrado()`, `:1117`), com dois degraus de
// fallback — nunca `title=""`.
//
// ⚠️ Toda flag computada DENTRO do callback do `.map()`: o Rollup elimina
// variável de escopo do componente usada dentro do mapa.
// ⚠️ Nenhum `String(valor)` e nenhum `obj[chave]` cru: os dois **LANÇAM** com
// `{toString: null}`, e a TELA PRETA voltou SEIS vezes — uma **no clique**.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️⚠️ As CINCO chaves de borda de `FotosDoPainelService` (`BORDA_*`), na mesma
 * precedência de `:1113`. **UM vocabulário só**, chaves em pt-BR nos dois lados.
 */
export const BORDA_SELECIONADA = 'selecionada';

export const BORDA_ALERTA = 'alerta';

export const BORDA_APROVADA = 'aprovada';

export const BORDA_PENDENTE = 'pendente';

/** ⚠️ O 5º ramo, que o REVS-09 OMITE. */
export const BORDA_NEUTRA = 'neutra';

/** As cinco chaves → a classe de BORDA. ⚠️ Nenhum hex: TOK-15. */
export const BORDA_DA_MINIATURA = {
    [BORDA_SELECIONADA]: 'border-pub-accent',
    [BORDA_ALERTA]: 'border-pub-danger',
    [BORDA_APROVADA]: 'border-pub-ok',
    [BORDA_PENDENTE]: 'border-pub-warn',
    [BORDA_NEUTRA]: 'border-pub-line-off',
};

/** `pending` e `approved` de `PubAprovacaoImagem` — as duas chaves que a borda consulta. */
export const ESTADO_PENDENTE = 'pending';

export const ESTADO_APROVADA = 'approved';

/**
 * ⚠️⚠️ A PRECEDÊNCIA DE CINCO RAMOS, espelhando
 * `FotosDoPainelService::borda()` (`app/Services/Publicador/Aprovacao/Painel/
 * FotosDoPainelService.php:345`-`:364`).
 *
 * ⚠️ **Ela só se prova com CONFLITO:** selecionada **E** com alerta tem de sair
 * `selecionada`; com alerta **E** aprovada tem de sair `alerta`. Um gate que
 * testasse um estado por vez passaria com a ordem invertida.
 */
export function chaveDaBorda(estado, selecionada, comAlerta) {
    if (selecionada === true) return BORDA_SELECIONADA;
    if (comAlerta === true) return BORDA_ALERTA;
    if (estado === ESTADO_APROVADA) return BORDA_APROVADA;
    if (estado === ESTADO_PENDENTE) return BORDA_PENDENTE;

    // ⚠️ O 5º ramo.
    return BORDA_NEUTRA;
}

/** A classe de borda da chave, com o NEUTRO para chave desconhecida. */
export function classeDaBorda(chave) {
    const nome = typeof chave === 'string' && Object.prototype.hasOwnProperty.call(BORDA_DA_MINIATURA, chave)
        ? chave
        : BORDA_NEUTRA;

    return BORDA_DA_MINIATURA[nome];
}

// ─── As classes, uma casa só (`:610`-`:618`) ───────────────────────────────

/** ⚠️⚠️ `:610` — `repeat(6,minmax(0,1fr))`, e **não** `grid-cols-6` nem `repeat(6,1fr)`. */
export const CLASSES_GRADE = 'grid grid-cols-[repeat(6,minmax(0,1fr))] gap-[6px]';

/** `:612` — a miniatura 1:1. A borda e o anel entram por cima. */
export const CLASSES_MINIATURA = 'relative aspect-square overflow-hidden rounded-[8px] border-[1.5px] bg-pub-hover-2 text-pub-txt-off';

/** `:1113` — o anel ADICIONAL da selecionada (a 2ª omissão do REVS-09). */
export const CLASSES_ANEL = 'ring-2 ring-pub-accent';

/** `:613` — a foto da miniatura. ⚠️ O protótipo não declara `fit` aqui (a grande é `contain`). */
export const CLASSES_FOTO = 'absolute inset-0 h-full w-full object-cover';

/** `:614` — o botão invisível que cobre a miniatura inteira. */
export const CLASSES_BOTAO = 'absolute inset-0 cursor-pointer border-none bg-transparent p-0';

/** `:615` — o selo de posição da miniatura. */
export const CLASSES_SELO_POSICAO = 'pointer-events-none absolute left-[4px] top-[4px] flex h-[16px] items-center '
    + 'gap-[3px] rounded-pub-badge bg-pub-page/[0.85] px-[5px] text-[10px] font-bold';

/** A base dos dois badges de 16px (`:616`/`:617`). */
export const CLASSES_BADGE = 'pointer-events-none absolute grid h-[16px] w-[16px] place-items-center rounded-full '
    + 'text-[10px] font-[800] text-pub-page';

/** `:616` — o badge `!` de alerta (canto superior-direito). */
export const CLASSES_BADGE_ALERTA = `${CLASSES_BADGE} right-[4px] top-[4px] bg-pub-danger`;

/** `:617` — o badge `✓` de aprovada (canto inferior-direito). */
export const CLASSES_BADGE_APROVADA = `${CLASSES_BADGE} bottom-[4px] right-[4px] bg-pub-ok`;

/** `:616`/`:617` — os dois glifos, iguais aos de `FotosDoPainelService`. */
export const GLIFO_ALERTA = '!';

export const GLIFO_APROVADA = '✓';

/** O último degrau do `title`, para que ele **nunca** seja vazio. */
export const TITULO_PADRAO = 'Foto do conjunto';

/**
 * A grade de 6 colunas das miniaturas.
 *
 * @param {object} props
 * @param {unknown} [props.imagens] `painel.fotos.imagens`
 * @param {unknown} [props.selecionada] o índice VIVO da selecionada (da página)
 * @param {unknown} [props.onSelecionar] `(indice) => void`, ou `null`
 *
 * ⚠️ Coleção nula, string, número ou com objeto no meio **não derruba a página**:
 * `listaDoPainel` recusa o que não é array e cada item passa por
 * `objetoDoPainel`.
 */
export default function GradeDeMiniaturas({ imagens = null, selecionada = null, onSelecionar = null }) {
    const lista = listaDoPainel(imagens);
    const indiceSelecionado = inteiroDoPainel(selecionada);

    // ⚠️ Grade vazia **não desenha casca**: moldura de 6 colunas sem nada dentro é
    // D4 violado, e quem diz o vazio honesto é a `ColunaDeFotos`.
    if (lista.length === 0) return null;

    return (
        <div className={CLASSES_GRADE}>
            {lista.map((bruta, indice) => {
                // ⚠️⚠️ TODAS as flags DENTRO do callback.
                const imagem = objetoDoPainel(bruta);
                if (imagem === null) return null;

                const estaSelecionada = indiceSelecionado === indice;
                const comAlerta = imagem.com_alerta === true;
                const estado = typeof imagem.estado === 'string' ? imagem.estado : null;

                const posicao = inteiroDoPainel(imagem.posicao);
                const url = textoDoPainel(imagem.url_arquivo);
                const rotulo = textoDoPainel(imagem.rotulo);
                // ⚠️ Três degraus, para o `title` NUNCA sair vazio.
                const titulo = textoDoPainel(imagem.titulo) ?? rotulo ?? TITULO_PADRAO;

                const trocar = typeof onSelecionar === 'function' ? () => onSelecionar(indice) : undefined;

                return (
                    <div
                        key={`miniatura-${inteiroDoPainel(imagem.id) ?? indice}`}
                        className={cn(
                            CLASSES_MINIATURA,
                            classeDaBorda(chaveDaBorda(estado, estaSelecionada, comAlerta)),
                            // ⚠️ O anel é ADICIONAL à borda, e **só** na selecionada.
                            estaSelecionada ? CLASSES_ANEL : null,
                        )}
                    >
                        {url === null ? null : <img className={CLASSES_FOTO} src={url} alt={rotulo ?? ''} />}

                        <button type="button" className={CLASSES_BOTAO} title={titulo} onClick={trocar} />

                        {posicao === null ? null : (
                            <span className={CLASSES_SELO_POSICAO}>
                                {posicao}
                                {imagem.de_ia === true ? <span className="text-pub-accent">{GLIFO_IA}</span> : null}
                            </span>
                        )}

                        {/* ⚠️ `:1105` — o badge `!` é de UM estado só: `pending` COM
                            alerta. Foto reprovada tem marcador e **não** tem badge. */}
                        {comAlerta && estado === ESTADO_PENDENTE
                            ? <span className={CLASSES_BADGE_ALERTA}>{GLIFO_ALERTA}</span>
                            : null}

                        {estado === ESTADO_APROVADA
                            ? <span className={CLASSES_BADGE_APROVADA}>{GLIFO_APROVADA}</span>
                            : null}
                    </div>
                );
            })}
        </div>
    );
}
