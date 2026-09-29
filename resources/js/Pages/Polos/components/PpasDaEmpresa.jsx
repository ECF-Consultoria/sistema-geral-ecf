import { ClipboardList, ExternalLink, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { grupoDoPlano, GRUPO_CONCLUIDO, percentual, resumoTarefas, seloPrazo } from '@/lib/ppaAgrupamento';

// Mesmos rótulos da lista do PPA (Pages/Ppa/Index.jsx).
const STATUS_PPA = {
    draft:     { label: 'Rascunho',  cls: 'bg-white/[0.06] text-white/45', title: 'Só interno — o cliente ainda não vê' },
    sent:      { label: 'Enviado',   cls: 'bg-sky-400/10 text-sky-300',     title: 'Visível para o cliente' },
    completed: { label: 'Concluído', cls: 'bg-emerald-400/10 text-emerald-300', title: 'Encerrado pela equipe' },
};

const SELO_TOM = {
    atrasado: 'bg-red-500/10 text-red-300',
    hoje:     'bg-amber-400/10 text-amber-300',
    proximo:  'bg-amber-400/[0.07] text-amber-200/80',
};

/**
 * PPAs gerados para a empresa — card da gaveta da linha no Painel Polos.
 *
 * `estado` vem de `mlb.polos-ppa.empresa`, buscado quando a gaveta abre:
 * `{ lista?: [...], loading?: bool, erro?: bool }`. Ao reabrir a gaveta a busca
 * se repete (um plano criado em outra aba aparece), mas a lista anterior segue
 * na tela enquanto isso — só a primeira abertura mostra "Carregando".
 *
 * Cada plano abre o próprio quadro em aba nova: voltar ao Painel recarregaria
 * a planilha inteira e perderia filtro e rolagem.
 */
export default function PpasDaEmpresa({ estado }) {
    const lista = estado?.lista;

    let corpo;
    if (!lista && estado?.erro) {
        corpo = <p className="text-red-300/70 text-[12px]">Falha ao buscar os PPAs.</p>;
    } else if (!lista) {
        corpo = <p className="text-white/30 text-[12px] inline-flex items-center gap-1.5"><RefreshCw size={12} className="animate-spin" /> Carregando…</p>;
    } else if (lista.length === 0) {
        corpo = <p className="text-white/30 text-[12px]">Nenhum PPA gerado para esta empresa.</p>;
    } else {
        corpo = (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                {lista.map((p) => <CardPpa key={p.id} p={p} />)}
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h4 className="text-white/60 text-[11px] font-semibold uppercase tracking-wider flex items-center gap-1.5">
                    <ClipboardList size={12} /> PPA
                    {lista?.length > 0 && (
                        <span className="rounded-full bg-white/[0.06] px-1.5 py-px text-[10px] font-semibold text-white/50 tabular-nums">{lista.length}</span>
                    )}
                    {lista && estado?.loading && <RefreshCw size={10} className="animate-spin text-white/25" />}
                </h4>
                <a href={route('mlb.polos-ppa.index')} target="_blank" rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-[11px] text-white/35 hover:text-ecf-yellow transition">
                    Abrir PPA Polos <ExternalLink size={11} />
                </a>
            </div>
            {corpo}
        </div>
    );
}

function CardPpa({ p }) {
    const st        = STATUS_PPA[p.status] ?? STATUS_PPA.draft;
    const contagem  = { total: p.total, feitas: p.feitas, fazendo: p.fazendo, aFazer: p.total - p.feitas - p.fazendo };
    const pct       = percentual(contagem);
    const concluido = grupoDoPlano({ concluido: p.status === 'completed', ...contagem }) === GRUPO_CONCLUIDO;
    const selo      = seloPrazo(p.prazo_dias, { encerrado: p.status === 'completed' });

    // Linha de apoio: prazo (ou o selo, quando aperta), responsável, última mexida.
    const apoio = [
        selo
            ? <span key="prazo" className={cn('rounded px-1 font-semibold', SELO_TOM[selo.tom])}>{selo.texto}</span>
            : <span key="prazo">{p.prazo ? `Prazo ${p.prazo}` : 'Sem prazo'}</span>,
        p.responsavel && <span key="resp">{p.responsavel}</span>,
        p.atualizado_em && <span key="atu">atualizado {p.atualizado_em}</span>,
        p.escopo === 'geral' && <span key="esc" className="text-white/45" title="PPA de carteira (empresa vinculada)">Carteira</span>,
    ].filter(Boolean);

    return (
        <a href={p.url} target="_blank" rel="noopener noreferrer" title="Abrir o quadro do plano"
            className="group block min-w-0 rounded-lg bg-white/[0.03] p-2.5 ring-1 ring-inset ring-white/[0.05] transition hover:bg-white/[0.05] hover:ring-ecf-yellow/25">
            <div className="flex items-start justify-between gap-2">
                <span className="min-w-0 truncate text-[12px] font-semibold text-white/85 group-hover:text-white">{p.titulo}</span>
                <span title={st.title} className={cn('shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold', st.cls)}>{st.label}</span>
            </div>

            <div className="mt-2 flex items-center gap-2">
                <div className="h-1 flex-1 overflow-hidden rounded-full bg-white/[0.07]">
                    <div className={cn('h-full rounded-full', concluido ? 'bg-emerald-400' : 'bg-ecf-yellow')} style={{ width: `${pct}%` }} />
                </div>
                <span className="text-[10.5px] tabular-nums text-white/45">{pct}%</span>
            </div>
            <p className="mt-1.5 truncate text-[11px] text-white/40">{resumoTarefas(contagem)}</p>

            <div className="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[10.5px] text-white/30">
                {apoio.map((item, i) => (
                    <span key={i} className="inline-flex items-center gap-1.5">
                        {i > 0 && <span className="text-white/15">·</span>}
                        {item}
                    </span>
                ))}
            </div>
        </a>
    );
}
