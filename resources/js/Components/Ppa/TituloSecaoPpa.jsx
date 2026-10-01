import { ChevronDown } from 'lucide-react';
import { GRUPO_ANDAMENTO, GRUPO_CONCLUIDO, GRUPO_FAZER } from '@/lib/ppaAgrupamento';
import { cn } from '@/lib/utils';

// ─── O cabeçalho de uma seção da lista de planos ────────────────────────────
//
// Compartilhado pelo Portal do Cliente e pela lista interna. As cores do ponto
// são as mesmas dos três grupos em `lib/ppaAgrupamento.js`: o ponto amarelo
// significa "aqui está o trabalho vivo" nas duas telas, e é isso que permite
// equipe e cliente falarem da mesma coisa ao telefone.

const PONTO = {
    [GRUPO_ANDAMENTO]: 'bg-ecf-yellow',
    [GRUPO_FAZER]:     'bg-white/35',
    [GRUPO_CONCLUIDO]: 'bg-emerald-400',
};

export default function TituloSecaoPpa({ chave, titulo, quantidade, aberta, onAlternar, dobravel }) {
    const conteudo = (
        <>
            <span className={cn('w-2 h-2 rounded-full shrink-0', PONTO[chave])} />
            <h2 className="text-white/75 font-display font-bold text-[13px] uppercase tracking-wider">
                {titulo}
            </h2>
            <span className="grid place-items-center min-w-[22px] h-[22px] px-1.5 rounded-md bg-white/[0.07] text-white/55 text-[11.5px] font-bold tabular-nums">
                {quantidade}
            </span>
            <span className="h-px flex-1 bg-white/[0.06]" />
            {dobravel && (
                <ChevronDown
                    size={15}
                    className={cn('shrink-0 text-white/30 transition-transform duration-200', !aberta && '-rotate-90')}
                />
            )}
        </>
    );

    if (!dobravel) {
        return <div className="flex items-center gap-2.5 px-1">{conteudo}</div>;
    }

    return (
        <button
            type="button"
            onClick={onAlternar}
            aria-expanded={aberta}
            className="w-full flex items-center gap-2.5 px-1 group hover:opacity-90 transition-opacity"
        >
            {conteudo}
        </button>
    );
}
