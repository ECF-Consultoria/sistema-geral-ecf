import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Eye, EyeOff, Info, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';

// Rótulos pt-BR dos estágios (espelha App\Support\Modules::STAGES).
const STAGE_LABEL = {
    ideia:              'Ideia',
    backlog:            'Backlog',
    em_desenvolvimento: 'Em dev',
    homologacao:        'Homologação',
    producao:           'Produção',
    arquivado:          'Arquivado',
};

/**
 * Painel de Controle Dev. Só o Dev (users.is_dev) chega aqui.
 * Oculta/mostra itens do menu para Admin e demais papéis — reflete no menu de
 * todos no próximo carregamento, sem deploy.
 *
 * Conceder o cargo Dev saiu daqui (quick 260727-mx3): virou cargo de verdade,
 * atribuído no cadastro do usuário em Administração → Usuários.
 */
export default function Modulos({ modulos = [], totalOcultos = 0 }) {
    const [savingId, setSavingId] = useState(null);

    // Agrupa módulos por `grupo` preservando a ordem já ordenada pelo controller.
    const grupos = useMemo(() => {
        const map = new Map();
        for (const m of modulos) {
            if (!map.has(m.grupo)) map.set(m.grupo, []);
            map.get(m.grupo).push(m);
        }
        return Array.from(map.entries());
    }, [modulos]);

    function toggleModulo(m) {
        setSavingId(m.id);
        router.patch(
            route('dev.modulos.visibilidade', m.id),
            { visivel_para_todos: !m.visivel_para_todos },
            { preserveScroll: true, onFinish: () => setSavingId(null) },
        );
    }

    return (
        <AppLayout title="Controle Dev">
            <div className="max-w-4xl mx-auto px-4 sm:px-6 py-6 space-y-10">

                {/* ═══ Visibilidade dos Módulos ═══ */}
                <section className="space-y-4">
                    <div>
                        <div className="flex items-center gap-2.5">
                            <EyeOff size={22} className="text-ecf-yellow" />
                            <h1 className="text-xl font-semibold text-white">Visibilidade dos Módulos</h1>
                        </div>
                        <p className="mt-1.5 text-[13px] text-white/50">
                            Controle o que <span className="text-white/70">Admin e demais papéis</span> veem no menu lateral.
                            Você (Dev) enxerga tudo — inclusive o que está oculto.
                        </p>
                    </div>

                    <div className="flex items-start gap-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] px-4 py-3">
                        <Info size={16} className="mt-0.5 shrink-0 text-white/40" />
                        <div className="space-y-1.5">
                            <p className="text-[12.5px] leading-relaxed text-white/55">
                                Ocultar aqui remove o item <strong className="text-white/75">do menu</strong>. A rota ainda
                                responde por URL direta (a trava no servidor virá numa próxima etapa). A mudança vale para
                                todos no próximo carregamento, sem deploy.
                            </p>
                            <p className="text-[12.5px] leading-relaxed text-white/40">
                                O <strong className="text-white/60">cargo Dev</strong> é concedido em{' '}
                                <Link href={route('users.index')} className="text-ecf-yellow/80 underline underline-offset-2 hover:text-ecf-yellow">
                                    Administração → Usuários
                                </Link>, no cadastro de cada pessoa.
                            </p>
                        </div>
                    </div>

                    <div className="text-[12px] text-white/45">
                        {modulos.length} módulos · <span className="text-amber-400/80">{totalOcultos} oculto{totalOcultos === 1 ? '' : 's'}</span>
                    </div>

                    <div className="space-y-6">
                        {grupos.map(([grupo, itens]) => (
                            <div key={grupo}>
                                <h2 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/35">{grupo}</h2>
                                <div className="overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card divide-y divide-white/[0.06]">
                                    {itens.map((m) => {
                                        const oculto = !m.visivel_para_todos;
                                        const saving = savingId === m.id;
                                        return (
                                            <div key={m.id} className="flex items-center gap-3 px-4 py-3">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className={cn('truncate text-[14px]', oculto ? 'text-white/40' : 'text-white/90')}>
                                                            {m.name}
                                                        </span>
                                                        <span className="rounded bg-white/[0.06] px-1.5 py-0.5 text-[10px] text-white/40">
                                                            {STAGE_LABEL[m.stage] ?? m.stage}
                                                        </span>
                                                    </div>
                                                    <div className="mt-0.5 truncate font-mono text-[11px] text-white/30">{m.key}</div>
                                                </div>

                                                <button
                                                    type="button"
                                                    onClick={() => toggleModulo(m)}
                                                    disabled={saving}
                                                    className={cn(
                                                        'inline-flex w-[112px] shrink-0 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-[12.5px] font-medium transition-colors disabled:opacity-60',
                                                        oculto
                                                            ? 'bg-amber-500/10 text-amber-300 hover:bg-amber-500/20'
                                                            : 'bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20',
                                                    )}
                                                    title={oculto ? 'Clique para tornar visível a todos' : 'Clique para ocultar (só Dev)'}
                                                >
                                                    {saving
                                                        ? <Loader2 size={14} className="animate-spin" />
                                                        : oculto ? <EyeOff size={14} /> : <Eye size={14} />}
                                                    {saving ? '...' : oculto ? 'Oculto' : 'Visível'}
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
