import { ArrowRight } from 'lucide-react';
import { alvoDaFalta, rotuloDoBotaoDaFalta } from '@/lib/trilhaFaltas';
import { cn } from '@/lib/utils';
import { Pilula } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — a pílula de uma falta (R11, 04/11/2026) ───────────
//
// "custo", "título", "fotos (1 de 3)", "ficha: marca"… Para quem escreve, um
// botão: leva à gaveta de cadastro do produto já no campo daquela falta
// (`lib/trilhaFaltas.js` diz qual). Para o cliente, o "Ver como: Cliente" e o
// produto que já andou, o texto de sempre.
//
// O clique não sobe: a linha da planilha da Lista também abre a gaveta (sem
// alvo), e o pai não pode passar por cima.

const BOTAO = cn(
    'inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-trilha-warn-bg px-[9px] py-0.5 text-[12px] font-semibold text-trilha-warn',
    'cursor-pointer hover:underline disabled:cursor-default disabled:no-underline disabled:opacity-60',
    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
);

/**
 * @param {{
 *   falta: string,
 *   texto?: ?string,
 *   alvo?: ?{campo: string, nome?: string},
 *   onIr?: ?((alvo: {campo: string, nome?: string}, origem: HTMLElement) => void),
 *   acessivel?: ?string,
 *   className?: string,
 * }} props  `texto`: o que a pílula mostra (padrão: a própria falta); `alvo`: padrão, o da falta; `acessivel`: o que
 *   o leitor de tela diz quando o texto não basta (o "falta" de uma coluna da planilha → "custo").
 */
export default function PilulaDaFalta({ falta, texto = null, alvo = null, onIr = null, acessivel = null, className }) {
    const destino = alvo ?? alvoDaFalta(falta);
    const rotulo = texto ?? falta;
    if (!onIr || !destino) {
        return (
            <Pilula tom="warn" className={className} data-falta={falta}>
                {rotulo}
            </Pilula>
        );
    }

    return (
        <button
            type="button"
            className={cn(BOTAO, className)}
            data-falta={falta}
            data-alvo-da-falta={destino.campo}
            aria-label={rotuloDoBotaoDaFalta(acessivel ?? rotulo)}
            title={rotuloDoBotaoDaFalta(acessivel ?? rotulo)}
            onClick={(evento) => {
                evento.stopPropagation();
                onIr(destino, evento.currentTarget);
            }}
        >
            {rotulo}
            <ArrowRight aria-hidden="true" className="h-3 w-3 shrink-0" strokeWidth={2.5} />
        </button>
    );
}

/** A lista de pílulas das faltas (`<ul>`), com o mesmo `onIr` para todas. */
export function ListaDePilulasDasFaltas({ itens, onIr = null, className, ...resto }) {
    if (!itens?.length) return null;

    return (
        <ul className={cn('m-0 flex list-none flex-wrap gap-1.5 p-0', className)} aria-label="O que falta" {...resto}>
            {itens.map((item) => (
                <li key={item.falta}>
                    <PilulaDaFalta falta={item.falta} texto={item.texto} alvo={item.alvo} onIr={onIr} />
                </li>
            ))}
        </ul>
    );
}
