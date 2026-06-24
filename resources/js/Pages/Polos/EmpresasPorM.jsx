import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Link2, BookUser, Search } from 'lucide-react';

// Ordem fixa das fases M (mesma do backend)
const ORDEM_M = ['M0', 'M1', 'M2', 'M3', 'M4'];

// Sentinela para empresas sem estágio definido
const SEM_ESTAGIO = '__sem__';
const estagioKey = (e) => (e.estagio && e.estagio !== '') ? e.estagio : SEM_ESTAGIO;
const estagioLabel = (k) => (k === SEM_ESTAGIO ? 'Sem estágio' : k);

// Ordena estágios: "Estágio N" por número primeiro, depois os demais alfabético
function ordenarEstagio(a, b) {
    if (a === SEM_ESTAGIO) return 1;
    if (b === SEM_ESTAGIO) return -1;
    const na = /Est[áa]gio\s*(\d+)/i.exec(a);
    const nb = /Est[áa]gio\s*(\d+)/i.exec(b);
    if (na && nb) return Number(na[1]) - Number(nb[1]);
    if (na) return -1;
    if (nb) return 1;
    return a.localeCompare(b, 'pt-BR');
}

// ─── Card compacto de empresa POLOS ──────────────────────────────────────────
// Apenas identidade/classificação. A fase (M) fica em destaque para deixar claro
// em qual M a empresa está.
function EmpresaCard({ e, appUrl }) {
    const concluida = e.estagio === 'Concluido';

    return (
        <div className="card-ecf rounded-2xl overflow-hidden flex flex-col transition-all">
            <div className="p-3.5 flex flex-col gap-2.5 flex-1">
                {/* Identidade: nome */}
                <div className="flex items-center gap-2 min-w-0">
                    <span
                        className="text-white font-semibold text-[14px] truncate flex-1"
                        title={e.nome}
                    >
                        {e.nome}
                    </span>
                </div>

                {/* Classificação: M (fase) em destaque + estágio */}
                <div className="flex items-center gap-1.5 flex-wrap">
                    {e.fase && (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/30">
                            {e.fase}
                        </span>
                    )}
                    {e.estagio && (
                        <span className={cn(
                            'px-2 py-0.5 rounded-full text-[10px] font-bold border',
                            concluida
                                ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                : 'bg-purple-500/15 text-purple-400 border-purple-500/30'
                        )}>
                            {e.estagio}
                        </span>
                    )}
                </div>

                {/* Responsável */}
                {e.responsavel_nome && (
                    <p className="text-white/45 text-[11px] truncate">→ {e.responsavel_nome}</p>
                )}
            </div>

            {/* Rodapé de ações com links de implementação */}
            {e.implementacao_token && (
                <div className="flex items-center justify-end gap-1 px-2.5 py-1.5 border-t border-white/[0.05]">
                    <a
                        href={`${appUrl}/implementacao/${e.implementacao_token}`}
                        target="_blank"
                        rel="noreferrer"
                        title="Onboarding / Link do Cliente"
                        className="p-1.5 rounded-lg text-white/20 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-colors"
                    >
                        <Link2 size={13} />
                    </a>
                    <a
                        href={`${appUrl}/implementacao/${e.implementacao_token}/publicador`}
                        target="_blank"
                        rel="noreferrer"
                        title="Visão do Publicador"
                        className="p-1.5 rounded-lg text-white/20 hover:text-violet-400 hover:bg-violet-500/10 transition-colors"
                    >
                        <BookUser size={13} />
                    </a>
                </div>
            )}
        </div>
    );
}

// ─── Chip de filtro genérico ──────────────────────────────────────────────────
function FiltroChip({ ativo, vazio, tom, onClick, children, n }) {
    // tom: 'yellow' (fase) | 'purple' (estágio)
    const ativoCls = tom === 'purple'
        ? 'bg-purple-500 text-white'
        : 'bg-ecf-yellow text-black';
    return (
        <button
            type="button"
            disabled={vazio}
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-semibold transition',
                ativo
                    ? ativoCls
                    : vazio
                        ? 'bg-white/[0.02] text-white/20 cursor-default'
                        : 'bg-white/[0.05] text-white/70 hover:bg-white/[0.1]'
            )}
        >
            {children}
            <span className={cn(
                'tabular-nums rounded-full px-1.5 text-[10px]',
                ativo ? 'bg-black/15' : 'bg-white/10'
            )}>
                {n}
            </span>
        </button>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────
export default function EmpresasPorM({ grupos, contagens, totalPolos }) {
    const { asset_url } = usePage().props;
    // URL base para links de implementação (mesmo padrão de EmpresaRow em Mlb/Projetos.jsx)
    const appUrl = asset_url ?? '';

    // Lista achatada de todas as empresas POLOS (cada uma já carrega .fase = M)
    const todas = useMemo(() => Object.values(grupos ?? {}).flat(), [grupos]);

    // Filtros: por fase M (chips), por estágio (chips) e por nome (busca)
    const [filtroM, setFiltroM]             = useState('todas'); // 'todas' | 'M0'..'M4'
    const [filtroEstagio, setFiltroEstagio] = useState('todos'); // 'todos' | estágio | SEM_ESTAGIO
    const [busca, setBusca]                 = useState('');

    // Subconjunto considerando só a fase M selecionada — base para os chips de
    // estágio (as contagens de estágio refletem a fase atual).
    const baseEstagio = useMemo(
        () => todas.filter((e) => filtroM === 'todas' || e.fase === filtroM),
        [todas, filtroM]
    );

    // Resultado final: combina fase M + estágio + busca (AND)
    const filtradas = useMemo(() => {
        const q = busca.trim().toLowerCase();
        return baseEstagio.filter((e) => {
            if (filtroEstagio !== 'todos' && estagioKey(e) !== filtroEstagio) return false;
            if (q && !(e.nome ?? '').toLowerCase().includes(q)) return false;
            return true;
        });
    }, [baseEstagio, filtroEstagio, busca]);

    // Chips de fase M: "Todas" + M0..M4 com a contagem de cada M
    // (contagens cobre todos os M, inclusive os com 0 empresas)
    const chipsM = [
        { key: 'todas', label: 'Todas', n: totalPolos ?? todas.length },
        ...ORDEM_M.map((m) => ({ key: m, label: m, n: contagens?.[m] ?? 0 })),
    ];

    // Chips de estágio: "Todos" + estágios distintos da fase selecionada, com contagem
    const chipsEstagio = useMemo(() => {
        const cont = {};
        baseEstagio.forEach((e) => {
            const k = estagioKey(e);
            cont[k] = (cont[k] ?? 0) + 1;
        });
        const chaves = Object.keys(cont).sort(ordenarEstagio);
        return [
            { key: 'todos', label: 'Todos', n: baseEstagio.length },
            ...chaves.map((k) => ({ key: k, label: estagioLabel(k), n: cont[k] })),
        ];
    }, [baseEstagio]);

    // Troca de fase reseta o filtro de estágio (evita filtro de estágio "preso"
    // num valor que não existe na nova fase)
    const trocarFase = (key) => {
        setFiltroM(key);
        setFiltroEstagio('todos');
    };

    return (
        <AppLayout title="Empresas">
            <div className="space-y-5">
                {/* Cabeçalho */}
                <div>
                    <h2 className="text-white font-display font-bold text-xl leading-tight">
                        Empresas
                    </h2>
                    <p className="text-white/40 text-[13px] mt-1">
                        Empresas do projeto Polos por fase de implantação (M0–M4)
                    </p>
                </div>

                {/* Barra de filtros */}
                <div className="space-y-2.5">
                    {/* Filtro por fase M + busca por nome */}
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-white/30 text-[11px] uppercase tracking-wider w-16 shrink-0">Fase</span>
                        {chipsM.map((c) => (
                            <FiltroChip
                                key={c.key}
                                ativo={filtroM === c.key}
                                vazio={c.n === 0 && c.key !== 'todas'}
                                tom="yellow"
                                n={c.n}
                                onClick={() => trocarFase(c.key)}
                            >
                                {c.label}
                            </FiltroChip>
                        ))}

                        {/* Busca por nome */}
                        <div className="relative ml-auto">
                            <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                            <input
                                type="text"
                                value={busca}
                                onChange={(ev) => setBusca(ev.target.value)}
                                placeholder="Buscar empresa…"
                                className="w-52 rounded-full border border-white/[0.1] bg-ecf-card pl-8 pr-3 py-1.5 text-[12px] text-white/90 outline-none focus:border-ecf-yellow/40"
                            />
                        </div>
                    </div>

                    {/* Filtro por estágio */}
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-white/30 text-[11px] uppercase tracking-wider w-16 shrink-0">Estágio</span>
                        {chipsEstagio.map((c) => (
                            <FiltroChip
                                key={c.key}
                                ativo={filtroEstagio === c.key}
                                tom="purple"
                                n={c.n}
                                onClick={() => setFiltroEstagio(c.key)}
                            >
                                {c.label}
                            </FiltroChip>
                        ))}
                    </div>
                </div>

                {/* Grid de cards (ou estado vazio) */}
                {filtradas.length > 0 ? (
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        {filtradas.map((e) => (
                            <EmpresaCard key={e.id} e={e} appUrl={appUrl} />
                        ))}
                    </div>
                ) : (
                    <div className="card-ecf rounded-2xl p-12 text-center text-white/20">
                        {todas.length === 0
                            ? 'Nenhuma empresa Polos com fase definida.'
                            : 'Nenhuma empresa neste filtro.'}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
