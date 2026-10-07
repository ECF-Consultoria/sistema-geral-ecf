import { Box, Layers, Lightbulb, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { percentualDoTotal, rotuloDaFase } from '@/lib/sugestoesEstrutura';

// ─── Quatro cartões de resumo (168-18, D-24) ────────────────────────────────
//
// `resumo` {total, combo, kit, combit} vem do servidor: o conjunto da aba Pendentes, sem
// filtro. Aqui só se desenha; a porcentagem e o plural são formatação.

// Classes literais (o Tailwind só gera o que aparece escrito por inteiro).
const COR = {
    sky: { degrade: 'from-sky-500/[0.07]', circulo: 'bg-sky-500/15 text-sky-300' },
    violet: { degrade: 'from-violet-500/[0.07]', circulo: 'bg-violet-500/15 text-violet-300' },
    emerald: { degrade: 'from-emerald-500/[0.07]', circulo: 'bg-emerald-500/15 text-emerald-300' },
    amarelo: { degrade: 'from-ecf-yellow/[0.07]', circulo: 'bg-ecf-yellow/15 text-ecf-yellow' },
};

function Cartao({ id, cor, Icone, numero, rotulo, descricao }) {
    return (
        <div data-cartao-resumo={id}
            className={cn('flex min-w-0 items-center gap-3 rounded-[12px] border border-white/[0.08] bg-ecf-card bg-gradient-to-r to-transparent px-3.5 py-3 xl:h-[84px] xl:gap-4 xl:py-0', COR[cor].degrade)}>
            <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-full xl:h-[60px] xl:w-[60px]', COR[cor].circulo)}>
                <Icone className="h-5 w-5 xl:h-7 xl:w-7" aria-hidden="true" />
            </span>
            <div className="min-w-0 leading-tight">
                <p className="text-[22px] font-bold tabular-nums text-white">{numero}</p>
                <p className="truncate text-[15px] text-white/85">{rotulo}</p>
                <p className="truncate text-[12px] text-white/50">{descricao}</p>
            </div>
        </div>
    );
}

export default function ResumoSugestoes({ resumo }) {
    const total = resumo?.total ?? 0;
    const qtd = (fase) => resumo?.[fase] ?? 0;

    return (
        <section data-resumo-sugestoes aria-label="Resumo das sugestões" className="grid grid-cols-2 gap-3 xl:grid-cols-4 xl:gap-[15px]">
            <Cartao id="total" cor="sky" Icone={Lightbulb} numero={total} rotulo={total === 1 ? 'sugestão' : 'sugestões'} descricao="Total de combinações encontradas" />
            <Cartao id="combo" cor="violet" Icone={Box} numero={qtd('combo')} rotulo={rotuloDaFase('combo', qtd('combo'))} descricao={percentualDoTotal(qtd('combo'), total)} />
            <Cartao id="kit" cor="emerald" Icone={Package} numero={qtd('kit')} rotulo={rotuloDaFase('kit', qtd('kit'))} descricao={percentualDoTotal(qtd('kit'), total)} />
            <Cartao id="combit" cor="amarelo" Icone={Layers} numero={qtd('combit')} rotulo={rotuloDaFase('combit', qtd('combit'))} descricao={percentualDoTotal(qtd('combit'), total)} />
        </section>
    );
}
