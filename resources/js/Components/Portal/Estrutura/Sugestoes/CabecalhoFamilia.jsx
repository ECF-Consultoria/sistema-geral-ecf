import { cn } from '@/lib/utils';

// ─── Cabeçalho de cada grupo de família (UI-SPEC "Agrupamento por família") ─
//
// Só apresentação: contagens e "(continua)" vêm do servidor (`familia_totais`,
// `familia_continua`). O checkbox marca/desmarca as desta família na página.

export default function CabecalhoFamilia({ nome, semFamilia = false, naPagina, total, continua = false, todasMarcadas = false, onMarcarTodas, primeiro = false }) {
    return (
        <header className={cn('mb-3 flex flex-wrap items-center gap-x-3 gap-y-1', primeiro ? 'mt-0' : 'mt-6 lg:mt-8')} data-familia-cabecalho>
            <label className="-ml-1.5 grid h-11 w-11 shrink-0 place-items-center" title={`Marcar as ${naPagina} desta família nesta página`}>
                <input type="checkbox" checked={todasMarcadas} onChange={() => onMarcarTodas?.()}
                    aria-label={`Marcar as ${naPagina} desta família nesta página`}
                    className="h-5 w-5 rounded border-white/30 bg-transparent text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40" />
            </label>
            <h2 className="min-w-0 truncate text-[17px] font-semibold text-white">
                {semFamilia ? 'Sem família' : nome}{continua && <span className="ml-2 text-[13px] font-normal text-white/55">(continua)</span>}
            </h2>
            <span className="text-[12px] text-white/55">{naPagina} nesta página · {total} no total</span>
            {semFamilia && <p className="basis-full text-[12px] text-white/60">Sem família só entra em Combo. Escolha a família na ficha do produto.</p>}
        </header>
    );
}
