import { cn } from '@/lib/utils';

function Cartao({ rotulo, numero, nota, onClick }) {
    const corpo = (
        <>
            <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">{rotulo}</p>
            <p className="mt-1 font-display text-[24px] font-bold tabular-nums text-white">{numero}</p>
            <p className="text-[13px] font-normal text-white/55">{nota}</p>
        </>
    );
    const classe = 'rounded-xl bg-ecf-card p-4 text-left';

    if (onClick) {
        return (
            <button
                type="button"
                onClick={onClick}
                className={cn(classe, 'hover:bg-ecf-card-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow')}
            >
                {corpo}
            </button>
        );
    }
    return <div className={classe}>{corpo}</div>;
}

/** Faixa de 4 indicadores do programa selecionado (sem acento amarelo). */
export default function IndicadoresDoPrograma({ indicadores, onFiltrarProntos }) {
    const i = indicadores ?? {};
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Cartao rotulo="Empresas" numero={i.empresas ?? 0} nota="com conta conectada" />
            <Cartao rotulo="Com dados do Portal" numero={i.com_portal ?? 0} nota={`${i.pct_sincronizado ?? 0}% sincronizado`} />
            <Cartao rotulo="Prontos para publicar" numero={i.prontos ?? 0} nota="produtos aptos" onClick={onFiltrarProntos} />
            <Cartao rotulo="Publicados no mês" numero={i.publicados_mes ?? 0} nota="anúncios no ar" />
        </div>
    );
}
