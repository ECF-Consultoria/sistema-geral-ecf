import { cn } from '@/lib/utils';
import { normalizar } from './identidade';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — glyph de metodologia (IDENT-01).
//
// A regra de ouro do README (§Componentes de identidade): "nunca depender só
// de cor". O que distingue as três metodologias é a FORMA:
//   Fase 1  → UM retângulo de borda
//   Fase 2  → DOIS retângulos sobrepostos, o da frente preenchido de amarelo
//   Legado  → UM retângulo de borda TRACEJADA
// A cor vem por cima da forma, nunca no lugar dela.
//
// Geometria medida no protótipo, arquivo e linha de cada tamanho:
//   `lista`  → "Publicacoes v2.dc.html":225/228/231 (coluna Metodologia da tabela)
//   `marca`  → "PublicadorHeader.dc.html":26-29     (a marca "Publicador")
//   `rodape` → "PublicadorHeader.dc.html":159       (rodapé do modal "Nova publicação")
//
// ⚠️ FID-03: nenhuma medida foi arredondada para o passo do Tailwind. 2,5px é
// `rounded-[2.5px]`, 1,6px é `border-[1.6px]`.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Cada tamanho reproduz UMA instância do protótipo, inclusive a cor da borda e o
 * fundo opaco do retângulo de trás — que no protótipo mudam com o contexto:
 *
 * - a marca do header é toda amarela (README §0: "glyph de Fase 2 em amarelo")
 *   e o retângulo de trás NÃO tem fundo (não precisa: tudo ali é amarelo);
 * - o glyph da tabela e o do rodapé do modal têm borda `pub-fase1` e o
 *   retângulo de trás ganha um fundo OPACO, para o traço de trás não aparecer
 *   atravessando o retângulo da frente. O fundo é o da superfície em que o
 *   glyph se apoia: `pub-surface` na tabela, `pub-sunken` no rodapé do modal.
 */
const TAMANHOS = {
    lista: {
        caixa: 'h-[18px] w-[18px]',
        retangulo: 'h-[13px] w-[10px] rounded-[2.5px] border-[1.5px]',
        borda: 'border-pub-fase1',
        atras: 'left-[1px] top-[1px]',
        frente: 'left-[6px] top-[4px]',
        fundoAtras: 'bg-pub-surface',
    },
    marca: {
        caixa: 'h-[24px] w-[24px]',
        retangulo: 'h-[18px] w-[15px] rounded-[4px] border-[1.6px]',
        borda: 'border-pub-accent',
        atras: 'left-0 top-0',
        frente: 'left-[8px] top-[6px]',
        fundoAtras: null,
    },
    rodape: {
        caixa: 'h-[16px] w-[16px]',
        retangulo: 'h-[12px] w-[9px] rounded-[2px] border-[1.4px]',
        borda: 'border-pub-fase1',
        atras: 'left-0 top-0',
        frente: 'left-[5px] top-[3px]',
        fundoAtras: 'bg-pub-sunken',
    },
};

/**
 * Traduz o que o servidor manda em uma das três metodologias.
 *
 * Aceita número E texto: `fase` chega como `int` da coluna do rascunho em
 * algumas telas e como string em outras. Qualquer outra coisa (objeto, nulo,
 * ausente, 7) devolve `null` — "não sei qual é", e quem chama decide. O glyph
 * desenha o retângulo tracejado nesse caso; o `RotuloDaFase` escreve "—" em vez
 * de inventar a palavra "Legado" para um dado que não chegou.
 *
 * Export NOMEADO de propósito: `RotuloDaFase.jsx` importa daqui, não recria.
 *
 * @param {unknown} fase
 * @returns {'f1'|'f2'|'legado'|null}
 */
export function chaveDaFase(fase) {
    if (fase === 1 || fase === '1') return 'f1';
    if (fase === 2 || fase === '2') return 'f2';
    if (normalizar(fase) === 'legado') return 'legado';
    return null;
}

/**
 * @param {object} props
 * @param {1|2|'legado'|string|number|null} props.fase
 * @param {'lista'|'marca'|'rodape'} [props.tamanho]
 * @param {string|null} [props.fundo]  classe de fundo do retângulo de trás na
 *   Fase 2 — só precisa trocar quando o glyph se apoia numa superfície que não
 *   é a padrão do tamanho (ex.: linha selecionada da tabela, `bg-pub-row-sel`).
 * @param {string} [props.className]
 */
export default function GlyphDaFase({ fase, tamanho = 'lista', fundo = null, className }) {
    const medida = TAMANHOS[tamanho] ?? TAMANHOS.lista;
    const chave = chaveDaFase(fase) ?? 'legado';

    // Fase 2: dois retângulos sobrepostos. O de trás leva borda + fundo opaco;
    // o da frente leva borda + o amarelo da metodologia.
    if (chave === 'f2') {
        return (
            <span aria-hidden="true" className={cn('relative inline-block flex-none', medida.caixa, className)}>
                <span className={cn('absolute', medida.retangulo, medida.borda, medida.atras, fundo ?? medida.fundoAtras)} />
                <span className={cn('absolute bg-pub-accent', medida.retangulo, medida.borda, medida.frente)} />
            </span>
        );
    }

    // Fase 1 e Legado: UM retângulo centrado na caixa — a caixa é a mesma dos
    // dois retângulos da Fase 2, para as três metodologias ocuparem exatamente a
    // mesma largura e a coluna não dançar de linha em linha.
    return (
        <span aria-hidden="true" className={cn('inline-grid flex-none place-items-center', medida.caixa, className)}>
            <span
                className={cn(
                    medida.retangulo,
                    chave === 'legado' ? 'border-dashed border-pub-txt-4' : medida.borda,
                )}
            />
        </span>
    );
}
