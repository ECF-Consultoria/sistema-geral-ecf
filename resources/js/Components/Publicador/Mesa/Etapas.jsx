import { ETAPAS } from '../apoio';
import { cn } from '@/lib/utils';

// ─── As 3 etapas no topo (04/10/2026) ───────────────────────────────────────
//
// Só os nomes, como no Mercado Livre: "1 Produto — 2 Detalhes — 3 Condições
// de venda". Sem contador, sem "completo/falta N" — pedido do cliente. Clicar
// leva direto à etapa (navegação livre); quem confere o que falta é o
// "Continuar" de cada etapa.

export default function Etapas({ atual, onIr }) {
    const indice = ETAPAS.findIndex((e) => e.chave === atual);

    return (
        <nav aria-label="Etapas do anúncio" data-etapas>
            <ol className="flex items-center gap-3 max-sm:gap-2">
                {ETAPAS.map((e, i) => {
                    const ativa = e.chave === atual;
                    const passou = i < indice;

                    return (
                        <li key={e.chave} className={cn('flex min-w-0 items-center gap-3 max-sm:gap-2', i < ETAPAS.length - 1 && 'flex-1')}>
                            <button type="button" onClick={() => onIr(e.chave)} aria-current={ativa ? 'step' : undefined} data-etapa={e.chave}
                                className="flex min-w-0 shrink-0 items-center gap-2.5 rounded-lg py-1 pr-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-full border-2 text-[13px] font-bold tabular-nums',
                                    ativa ? 'border-ecf-yellow bg-ecf-yellow/15 text-ecf-yellow' : (passou ? 'border-white/60 text-white' : 'border-white/25 text-white/50'))}>
                                    {i + 1}
                                </span>
                                {/* No celular só a etapa aberta mostra o nome; as outras ficam no número (o nome segue para o leitor de tela). */}
                                <span className={cn('truncate text-[15px]', ativa ? 'font-bold text-white' : cn('max-sm:sr-only', passou ? 'text-white/80 hover:text-white' : 'text-white/50 hover:text-white/80'))}>{e.titulo}</span>
                            </button>
                            {i < ETAPAS.length - 1 && <span aria-hidden="true" className={cn('h-px min-w-4 flex-1', passou ? 'bg-white/45' : 'bg-white/15')} />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
