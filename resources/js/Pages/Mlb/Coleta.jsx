import AppLayout from '@/Layouts/AppLayout';
import { useForm, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { RefreshCw, MessageSquare, Info, Lightbulb, CheckCircle2 } from 'lucide-react';
import { Progress } from '@/Components/ui/progress';
import { cn } from '@/lib/utils';

// Cores semânticas de status — paleta MLB existente (UI-SPEC §Cor)
const STATUS_BADGE = {
    pendente:  'bg-blue-500/10 border border-blue-500/30 text-blue-400',
    rodando:   'bg-ecf-yellow/10 border border-ecf-yellow/30 text-ecf-yellow',
    concluido: 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    erro:      'bg-red-500/10 border border-red-500/30 text-red-400',
};

// Copy exata do §Contrato de Copywriting do UI-SPEC
const STATUS_LABEL = {
    pendente:  'Aguardando na fila…',
    rodando:   'Coleta em andamento',
    concluido: 'Análise concluída',
    erro:      'Falha na coleta — veja o detalhe abaixo',
};

export default function Coleta({ coletas = [], coleta = null }) {
    // Formulário de nova coleta (sem state global — useForm do Inertia, D-07)
    const form = useForm({ keyword: '', categoria_id: '', condicao: '' });

    const [localStatus, setLocalStatus] = useState(coleta?.status ?? null);
    const [progresso, setProgresso]     = useState(0);
    const [timeout10, setTimeout10]     = useState(false);
    const pollRef = useRef(null);
    const rampRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();
        // Após sucesso o controller redireciona para coletaShow — a página recarrega já em "pendente"
        form.post(route('mlb.coleta.store'));
    };

    // Polling de status (padrão de Grants/Index.jsx) — 3s, deadline 10 min, cleanup no unmount
    useEffect(() => {
        if (!coleta?.id) return;
        setLocalStatus(coleta.status);
        setTimeout10(false);

        if (coleta.status !== 'pendente' && coleta.status !== 'rodando') {
            return;
        }

        const deadline = Date.now() + 10 * 60 * 1000; // 10 min (T-17-16)

        // Ramp visual da barra: 0 → 90 % enquanto a coleta roda
        setProgresso(8);
        rampRef.current = setInterval(() => {
            setProgresso((p) => (p < 90 ? p + 6 : p));
        }, 1500);

        pollRef.current = setInterval(async () => {
            if (Date.now() > deadline) {
                clearInterval(pollRef.current);
                clearInterval(rampRef.current);
                setTimeout10(true);
                return;
            }
            try {
                const res  = await fetch(route('mlb.coleta.status', coleta.id));
                const data = await res.json();
                setLocalStatus(data.status);
                if (data.status === 'concluido' || data.status === 'erro') {
                    clearInterval(pollRef.current);
                    clearInterval(rampRef.current);
                    setProgresso(100);
                    if (data.status === 'concluido') {
                        router.reload({ only: ['coleta'] }); // recarrega só a prop coleta
                    }
                }
            } catch {
                /* silencioso — polling não deve quebrar a UI */
            }
        }, 3000);

        return () => {
            if (pollRef.current) clearInterval(pollRef.current);
            if (rampRef.current) clearInterval(rampRef.current);
        };
    }, [coleta?.id, coleta?.status]);

    const resultado    = coleta?.resultado ?? null;
    const meta         = resultado?.meta ?? {};
    const ranking      = resultado?.ranking_keywords ?? [];
    const duvidas      = resultado?.top_duvidas ?? [];
    const recomendacao = resultado?.recomendacao ?? null;
    const produtos     = resultado?.produtos_analisados ?? [];
    const emAndamento  = localStatus === 'pendente' || localStatus === 'rodando';

    return (
        <AppLayout title="Inteligência de Anúncios">
            <div className="space-y-5 max-w-[1200px]">

                {/* [1] Cabeçalho da página */}
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Inteligência de Anúncios</h1>
                    <p className="text-white/40 text-[13px] mt-1">
                        Pesquise palavras-chave e veja o que os concorrentes top usam nos anúncios do Mercado Livre.
                    </p>
                </div>

                {/* [2] Formulário de nova coleta */}
                <div className="card-ecf rounded-2xl p-5">
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-[1fr_12rem_10rem] gap-4">
                            {/* Keyword */}
                            <div>
                                <label htmlFor="keyword" className="text-white/50 text-[11px] uppercase tracking-wide font-bold block mb-1">
                                    Palavra-chave
                                </label>
                                <input
                                    id="keyword"
                                    type="text"
                                    placeholder="Ex: fone bluetooth esportivo"
                                    value={form.data.keyword}
                                    onChange={(e) => form.setData('keyword', e.target.value)}
                                    className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
                                />
                                {form.errors.keyword && (
                                    <p className="text-red-400 text-[13px] mt-1">{form.errors.keyword}</p>
                                )}
                            </div>

                            {/* Categoria (opcional) */}
                            <div>
                                <label htmlFor="categoria_id" className="text-white/50 text-[11px] uppercase tracking-wide font-bold block mb-1">
                                    Categoria (opcional)
                                </label>
                                <input
                                    id="categoria_id"
                                    type="text"
                                    placeholder="Ex: MLB1051"
                                    value={form.data.categoria_id}
                                    onChange={(e) => form.setData('categoria_id', e.target.value)}
                                    className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
                                />
                            </div>

                            {/* Condição (opcional) */}
                            <div>
                                <label htmlFor="condicao" className="text-white/50 text-[11px] uppercase tracking-wide font-bold block mb-1">
                                    Condição (opcional)
                                </label>
                                <select
                                    id="condicao"
                                    value={form.data.condicao}
                                    onChange={(e) => form.setData('condicao', e.target.value)}
                                    className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
                                >
                                    <option value="">Qualquer</option>
                                    <option value="new">Novo</option>
                                    <option value="used">Usado</option>
                                </select>
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={form.processing}
                            aria-disabled={form.processing}
                            aria-label={form.processing ? 'Coletando dados, aguarde' : 'Iniciar coleta'}
                            className="h-10 px-6 rounded-xl bg-ecf-yellow text-black font-bold text-[13px] hover:bg-ecf-yellow-2 transition-colors disabled:opacity-50"
                        >
                            {form.processing ? 'Coletando…' : 'Iniciar Coleta'}
                        </button>
                    </form>
                </div>

                {/* [3] Barra de progresso / status da coleta em andamento */}
                {coleta && emAndamento && (
                    <div className="card-ecf rounded-2xl p-4 flex items-center gap-3 border border-ecf-yellow/20">
                        <RefreshCw size={16} className="text-ecf-yellow animate-spin shrink-0" aria-hidden="true" />
                        <div className="flex-1">
                            <p className="text-ecf-yellow text-[13px]">
                                {localStatus === 'pendente'
                                    ? 'Aguardando na fila…'
                                    : <>Coleta em andamento — analisando <span className="font-bold">{coleta.keyword}</span>…</>}
                            </p>
                            <Progress className="mt-2 h-2 [&>div]:bg-ecf-yellow" value={progresso} />
                            {timeout10 && (
                                <p className="text-red-400 text-[13px] mt-2">
                                    A coleta está demorando mais do esperado. Verifique os logs ou tente novamente.
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/* [4] Histórico de coletas */}
                <div className="card-ecf rounded-2xl overflow-hidden">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="text-white/30 text-[11px] uppercase tracking-wide">
                                <th className="font-bold px-5 py-2.5">Keyword</th>
                                <th className="font-bold px-3 py-2.5">Categoria</th>
                                <th className="font-bold px-3 py-2.5">Status</th>
                                <th className="font-bold px-3 py-2.5">Duração</th>
                                <th className="font-bold px-3 py-2.5">Data</th>
                                <th className="font-bold px-5 py-2.5 text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.04]">
                            {coletas.map((c) => (
                                <tr
                                    key={c.id}
                                    className={cn(
                                        'hover:bg-white/[0.02] transition-colors',
                                        coleta?.id === c.id && 'bg-ecf-yellow/5 border-l-2 border-ecf-yellow'
                                    )}
                                >
                                    <td className="px-5 py-3.5">
                                        <span className="text-white text-[13px] font-bold truncate max-w-[200px] block">{c.keyword}</span>
                                    </td>
                                    <td className="px-3 py-3.5 text-white/40 text-[13px]">{c.categoria_id || '—'}</td>
                                    <td className="px-3 py-3.5">
                                        <span
                                            aria-label={`Status: ${c.status}`}
                                            className={cn('inline-flex px-2 py-1 rounded-full text-[11px] font-bold', STATUS_BADGE[c.status])}
                                        >
                                            {c.status}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3.5 text-white/40 text-[13px] font-mono">{c.duracao || '—'}</td>
                                    <td className="px-3 py-3.5 text-white/40 text-[13px]">{c.created_at}</td>
                                    <td className="px-5 py-3.5 text-right">
                                        <button
                                            type="button"
                                            onClick={() => router.get(route('mlb.coleta.show', c.id))}
                                            className="text-ecf-yellow text-[13px] hover:underline"
                                        >
                                            Ver relatório
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {coletas.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-white/30 text-[13px] text-center py-8">
                                        Nenhuma coleta realizada ainda. Use o formulário acima para iniciar.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* [5] Relatório da coleta selecionada */}
                {!coleta && (
                    <div className="card-ecf rounded-2xl p-5 text-white/30 text-[13px] text-center">
                        Selecione uma coleta no histórico para ver o relatório.
                    </div>
                )}

                {coleta && localStatus === 'erro' && (
                    <div className="card-ecf rounded-2xl p-5 border border-red-500/20">
                        <p className="text-red-400 text-[13px] font-bold">Falha na coleta — veja o detalhe abaixo</p>
                        {coleta.erro_mensagem && (
                            <p className="text-white/50 text-[13px] mt-2 font-mono">{coleta.erro_mensagem}</p>
                        )}
                    </div>
                )}

                {coleta && localStatus === 'concluido' && resultado && (
                    <div className="space-y-5 animate-fade-in">

                        {/* 5a. Ranking de Keywords */}
                        <div className="card-ecf rounded-2xl p-5">
                            <h2 className="text-white font-display font-bold text-xl mb-3">Ranking de Keywords</h2>
                            <div className="grid grid-cols-1 gap-2">
                                {ranking.map((k, i) => (
                                    <div key={`${k.termo}-${i}`} className="flex items-center justify-between px-3 py-2 rounded-xl bg-white/[0.02]">
                                        <span className="text-white/30 text-[11px] font-mono w-6 text-right shrink-0">{i + 1}</span>
                                        <span className={cn('text-[13px] flex-1 ml-2', i === 0 ? 'text-ecf-yellow font-bold' : 'text-white')}>
                                            {k.termo}
                                        </span>
                                        {k.eh_tendencia && (
                                            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[11px] font-bold bg-ecf-yellow/20 border border-ecf-yellow/40 text-ecf-yellow mr-2">
                                                Tendência
                                            </span>
                                        )}
                                        <span className="text-white/40 text-[13px] font-mono">{k.frequencia}</span>
                                    </div>
                                ))}
                                {ranking.length === 0 && (
                                    <p className="text-white/30 text-[13px]">Nenhuma keyword minerada.</p>
                                )}
                            </div>
                        </div>

                        {/* 5b. Top Dúvidas dos Compradores */}
                        <div className="card-ecf rounded-2xl p-5">
                            <h2 className="text-white font-display font-bold text-xl mb-3">Top Dúvidas dos Compradores</h2>
                            {meta.questions_disponivel === false ? (
                                <div className="bg-blue-500/5 border border-blue-500/20 rounded-xl p-3 flex items-start gap-2">
                                    <Info size={14} className="text-blue-400 shrink-0 mt-0.5" aria-hidden="true" />
                                    <p className="text-white/60 text-[13px]">
                                        Perguntas de compradores não disponíveis para anúncios de terceiros com este token. O ranking de keywords ainda está completo.
                                    </p>
                                </div>
                            ) : (
                                <div>
                                    {duvidas.map((d, i) => (
                                        <div key={`${d.tema}-${i}`} className="flex items-start gap-2 py-2 border-b border-white/[0.04]">
                                            <MessageSquare size={14} className="text-blue-400 shrink-0 mt-0.5" aria-hidden="true" />
                                            <div className="flex-1">
                                                <p className="text-white/80 text-[13px] font-bold">{d.tema}</p>
                                                {d.exemplo && <p className="text-white/40 text-[13px] italic">{d.exemplo}</p>}
                                            </div>
                                            <span className="text-[11px] text-white/40">{d.frequencia}</span>
                                        </div>
                                    ))}
                                    {duvidas.length === 0 && (
                                        <p className="text-white/30 text-[13px]">Nenhuma dúvida recorrente identificada.</p>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* 5c. Recomendação de Título e Descrição (heurística) */}
                        {recomendacao && (
                            <div className="card-ecf rounded-2xl p-5">
                                <h2 className="text-white font-display font-bold text-xl mb-3">Recomendação de Título e Descrição</h2>

                                <div className="bg-ecf-yellow/5 border border-ecf-yellow/20 rounded-xl p-3 flex items-start gap-2 mb-4">
                                    <Lightbulb size={14} className="text-ecf-yellow shrink-0 mt-0.5" aria-hidden="true" />
                                    <p className="text-white/60 text-[13px]">
                                        Recomendação gerada por regras (heurística). Análise qualitativa por IA estará disponível na Fase 2.
                                    </p>
                                </div>

                                {recomendacao.titulo_sugerido && (
                                    <p className="font-mono text-white text-[13px] font-bold bg-white/[0.04] rounded-xl px-4 py-3 mb-4">
                                        {recomendacao.titulo_sugerido}
                                    </p>
                                )}

                                {(recomendacao.palavras_top_incluir?.length > 0) && (
                                    <div className="mb-4">
                                        <p className="text-white/50 text-[11px] uppercase tracking-wide font-bold mb-2">Palavras-chave para incluir</p>
                                        <div className="flex flex-wrap gap-2">
                                            {recomendacao.palavras_top_incluir.map((p, i) => (
                                                <span key={`${p}-${i}`} className="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-bold bg-white/[0.06] border border-white/[0.10] text-white/70">
                                                    {p}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {(recomendacao.pontos_fortes_antecipar?.length > 0) && (
                                    <div>
                                        <p className="text-white/50 text-[11px] uppercase tracking-wide font-bold mb-2">Pontos a antecipar</p>
                                        <ul className="space-y-1">
                                            {recomendacao.pontos_fortes_antecipar.map((pt, i) => (
                                                <li key={`${pt}-${i}`} className="flex items-center gap-2 text-white/70 text-[13px]">
                                                    <CheckCircle2 size={14} className="text-emerald-400 shrink-0" aria-hidden="true" />
                                                    {pt}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Produtos analisados (sold_quantity oculto → N/D) */}
                        {produtos.length > 0 && (
                            <div className="card-ecf rounded-2xl p-5">
                                <h2 className="text-white font-display font-bold text-xl mb-3">Produtos Analisados</h2>
                                <div className="grid grid-cols-1 gap-2">
                                    {produtos.map((p, i) => (
                                        <div key={`${p.item_id}-${i}`} className="flex items-center justify-between px-3 py-2 rounded-xl bg-white/[0.02]">
                                            <span className="text-white/30 text-[11px] font-mono w-6 text-right shrink-0">{p.posicao_ranking ?? i + 1}</span>
                                            <span className="text-white text-[13px] flex-1 ml-2 truncate">{p.titulo || p.item_id}</span>
                                            <span className="text-white/40 text-[13px] font-mono">
                                                {p.sold_quantity === null || p.sold_quantity === undefined ? 'N/D' : p.sold_quantity}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
