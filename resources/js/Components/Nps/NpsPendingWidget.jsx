import { Link } from '@inertiajs/react';
import { AlertCircle, Clock } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * NpsPendingWidget — Phase 72 Plan 03 v15.0.
 *
 * Card com lista de empresas pendentes de NPS no mês corrente.
 * Consome shape do NpsPendingService::forCarteira (Plan 72-01):
 *   [{ company_id, name, template_id, template_nome, month_reference, dias_atraso }, ...]
 *
 * Props:
 *   - pendentes (array): lista injetada pelo backend (default seguro `[]`)
 *   - title (string, opcional): default "Empresas pendentes de NPS este mês"
 *   - maxVisible (int, opcional): default 5 — mostra "+ N outras empresas pendentes"
 *                                 quando o total ultrapassa esse limite
 *
 * Design (herdado Phase 70-05 + tokens ecf-*):
 *   - Empty state (nenhuma pendência): card com título e mensagem neutra
 *   - Header com contagem em badge orange-500/20 (dá senso de urgência sem virar destroy)
 *   - Lista scrollável com link para /companies/{id} via Ziggy route()
 *   - Rodapé "+ N outras" quando maxVisible < total
 *   - Trade-off (research §3): link vai para Companies/Show — não pra /nps/{token},
 *     porque o token só é gerado no disparo mensal. Funcionalidade "Disparar NPS
 *     manual" fica pra Phase 73 (v15.1).
 *
 * Comentário sobre "Ver todas": nesta versão v15.0 mostramos apenas o rodapé
 * com a contagem residual. Uma rota /nps/pendentes dedicada é candidata futura.
 */
export default function NpsPendingWidget({
    pendentes = [],
    title = 'Empresas pendentes de NPS este mês',
    maxVisible = 5,
}) {
    // Guard defensivo — pode chegar null/undefined caso o backend não injete a prop
    const lista = Array.isArray(pendentes) ? pendentes : [];

    // Empty state — mesma moldura que o card normal, mensagem neutra
    if (lista.length === 0) {
        return (
            <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-5">
                <h3 className="text-white text-sm font-semibold mb-1">{title}</h3>
                <p className="text-white/40 text-xs">
                    Nenhuma empresa pendente — todas responderam este mês.
                </p>
            </div>
        );
    }

    const visiveis = lista.slice(0, maxVisible);
    const restantes = lista.length - visiveis.length;

    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-5">
            {/* Header: título + badge com contagem total (soft orange, sem ser alarmante) */}
            <header className="flex items-center justify-between mb-3">
                <h3 className="text-white text-sm font-semibold">{title}</h3>
                <span
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-orange-500/20 text-orange-400 border border-orange-500/30 text-[11px] font-medium"
                    title={`${lista.length} empresa${lista.length === 1 ? '' : 's'} pendente${lista.length === 1 ? '' : 's'}`}
                >
                    <AlertCircle className="w-3 h-3" />
                    {lista.length}
                </span>
            </header>

            {/* Lista: até maxVisible items — cada item é um link para Companies/Show */}
            <ul className="space-y-2">
                {visiveis.map((p) => {
                    // Pluralização pt-BR do "dias atraso"
                    const diasLabel = p.dias_atraso === 0
                        ? 'hoje'
                        : `há ${p.dias_atraso} ${p.dias_atraso === 1 ? 'dia' : 'dias'}`;

                    return (
                        <li key={p.company_id}>
                            <Link
                                href={route('companies.show', p.company_id)}
                                title={`Template: ${p.template_nome}`}
                                className={cn(
                                    'flex items-center justify-between rounded-lg px-3 py-2 border transition-colors',
                                    'bg-white/[0.03] border-white/[0.08]',
                                    'hover:bg-orange-500/10 hover:border-orange-500/20',
                                )}
                            >
                                <span className="text-white text-sm truncate mr-3">{p.name}</span>
                                <span className="inline-flex items-center gap-1 text-orange-300 text-[11px] whitespace-nowrap">
                                    <Clock className="w-3 h-3" />
                                    {diasLabel}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>

            {/* Rodapé — "+ N outras empresas pendentes" quando maxVisible < total */}
            {restantes > 0 && (
                <p className="text-white/40 text-xs text-center mt-3">
                    + {restantes} {restantes === 1 ? 'outra empresa pendente' : 'outras empresas pendentes'}
                </p>
            )}
        </div>
    );
}
