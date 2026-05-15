import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { SlidersHorizontal, Trash2, Plus, Info } from 'lucide-react';

const MESES_PT = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

function formatMes(ym) {
    if (!ym) return '—';
    const [y, m] = ym.split('-');
    return `${MESES_PT[parseInt(m, 10) - 1]} / ${y}`;
}

function deletar(id) {
    if (!confirm('Remover este registro de meta?')) return;
    router.delete(route('mlb.metas.destroy', id), { preserveScroll: true });
}

export default function Metas({ publicadores, historico }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id:    '',
        mes_inicio: '',
        meta:       '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('mlb.metas.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    // Agrupa historico por user_id para exibir por pessoa
    const porUsuario = publicadores.map(p => ({
        ...p,
        registros: historico.filter(h => h.user_id === p.id),
    }));

    return (
        <AppLayout title="Metas por Mês">
            <div className="space-y-6 max-w-3xl">

                {/* Header */}
                <div>
                    <div className="flex items-center gap-2 mb-1">
                        <SlidersHorizontal size={16} className="text-ecf-yellow/70" />
                        <h2 className="text-white font-semibold text-lg">Metas por Mês</h2>
                    </div>
                    <p className="text-white/40 text-sm">
                        Define a meta individual de cada publicador por período.
                    </p>
                </div>

                {/* Como funciona */}
                <div className="flex gap-3 rounded-xl border border-blue-500/20 bg-blue-500/5 px-4 py-3">
                    <Info size={14} className="text-blue-400 shrink-0 mt-0.5" />
                    <p className="text-blue-300/80 text-[12px] leading-relaxed">
                        A meta funciona por <b className="text-blue-300">data de início efetiva</b>: ao definir uma meta a partir de um mês,
                        ela vale para aquele mês e todos os seguintes — até que um novo registro seja criado.
                        <br />
                        Exemplo: meta 220 a partir de Jan/2026 → vale para Jan, Fev, Mar...
                        Se criar meta 260 a partir de Mai/2026 → Abr ainda usa 220, Mai em diante usa 260.
                    </p>
                </div>

                {/* Formulário */}
                <form onSubmit={submit} className="card-ecf rounded-2xl p-5 space-y-4">
                    <p className="text-white font-semibold text-sm mb-1">Adicionar / Atualizar Meta</p>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        {/* Publicador */}
                        <div className="sm:col-span-1">
                            <label className="block text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-1.5">
                                Publicador
                            </label>
                            <select
                                value={data.user_id}
                                onChange={e => setData('user_id', e.target.value)}
                                className="appearance-none w-full h-9 pl-3 pr-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                            >
                                <option value="">Selecionar...</option>
                                {publicadores.map(p => (
                                    <option key={p.id} value={p.id}>{p.nome}</option>
                                ))}
                            </select>
                            {errors.user_id && <p className="text-red-400 text-[11px] mt-1">{errors.user_id}</p>}
                        </div>

                        {/* Mês de início */}
                        <div>
                            <label className="block text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-1.5">
                                A partir de
                            </label>
                            <input
                                type="month"
                                value={data.mes_inicio}
                                onChange={e => setData('mes_inicio', e.target.value)}
                                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                            />
                            {errors.mes_inicio && <p className="text-red-400 text-[11px] mt-1">{errors.mes_inicio}</p>}
                        </div>

                        {/* Meta */}
                        <div>
                            <label className="block text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-1.5">
                                Meta (anúncios)
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="9999"
                                value={data.meta}
                                onChange={e => setData('meta', e.target.value)}
                                placeholder="ex: 220"
                                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 [appearance:textfield]"
                            />
                            {errors.meta && <p className="text-red-400 text-[11px] mt-1">{errors.meta}</p>}
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="flex items-center gap-2 px-4 h-9 rounded-xl bg-ecf-yellow text-black font-bold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                    >
                        <Plus size={14} />
                        {processing ? 'Salvando...' : 'Salvar Meta'}
                    </button>
                </form>

                {/* Histórico por publicador */}
                <div className="space-y-4">
                    {porUsuario.map(p => (
                        <div key={p.id} className="card-ecf rounded-2xl overflow-hidden">
                            <div className="px-5 py-3 border-b border-white/[0.06] flex items-center justify-between">
                                <span className="text-white font-semibold text-sm">{p.nome}</span>
                                <span className="text-white/30 text-[11px]">
                                    {p.registros.length} registro{p.registros.length !== 1 ? 's' : ''}
                                </span>
                            </div>

                            {p.registros.length === 0 ? (
                                <div className="px-5 py-4 text-white/20 text-[12px]">
                                    Nenhum registro — usa o valor padrão do cadastro do usuário.
                                </div>
                            ) : (
                                <div className="divide-y divide-white/[0.04]">
                                    {p.registros.map((r, idx) => (
                                        <div key={r.id} className="flex items-center justify-between px-5 py-3">
                                            <div className="flex items-center gap-4">
                                                {/* Indicador de vigência */}
                                                <span className={cn(
                                                    'w-2 h-2 rounded-full shrink-0',
                                                    idx === 0 ? 'bg-emerald-400' : 'bg-white/20'
                                                )} />
                                                <div>
                                                    <span className="text-white text-[13px] font-medium">
                                                        {formatMes(r.mes_inicio)}
                                                    </span>
                                                    {idx === 0 && (
                                                        <span className="ml-2 px-1.5 py-0.5 rounded text-[10px] bg-emerald-500/15 text-emerald-400 font-semibold">
                                                            vigente
                                                        </span>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-6">
                                                <span className="text-ecf-yellow font-extrabold text-lg">
                                                    {r.meta}
                                                </span>
                                                <span className="text-white/20 text-[11px]">anúncios/mês</span>
                                                <button
                                                    onClick={() => deletar(r.id)}
                                                    className="p-1.5 rounded-lg text-white/20 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                                    title="Remover"
                                                >
                                                    <Trash2 size={13} />
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}

                    {publicadores.length === 0 && (
                        <div className="card-ecf rounded-2xl p-12 text-center text-white/20 text-sm">
                            Nenhum publicador cadastrado.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
