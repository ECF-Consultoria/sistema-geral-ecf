// Phase 36 Plan 36-02 (D-06) — Admin/Empresas.jsx perdeu o modal de
// atribuir contrato. A acao foi migrada para a pagina dedicada do Comercial
// (Comercial/AtribuirServico.jsx, rota /comercial/atribuir-servico/{company}).
// Esta tela continua oferecendo listagem + filtros + visualizacao read-only
// dos contratos por empresa. Para atribuir/editar um contrato, o admin clica
// no botao "Servico" em /companies (aba Pendencias) ou navega manualmente
// para /comercial/atribuir-servico/{id}.
import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { Briefcase, Building2, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import GruposManager, { GrupoBadge } from '@/Components/GruposManager';

const fmtBRL = (n) => n == null ? null
    : Number(n).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

function Chip({ label, color }) {
    return (
        <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border shrink-0', color)}>
            {label}
        </span>
    );
}

function EmpresaRow({ empresa, expandida, onToggle }) {
    const contratos = empresa.servicos_contratados || [];

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]',
                !empresa.active && 'opacity-50',
            )}
        >
            <ChevronDown
                size={14}
                className={cn(
                    'transition-transform duration-200 shrink-0',
                    expandida ? 'rotate-180 text-ecf-yellow' : 'text-white/40',
                )}
            />
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white font-semibold text-[13px] truncate">{empresa.name}</span>
                    {empresa.grupo && <GrupoBadge grupo={empresa.grupo} />}
                    {!empresa.active && (
                        <span className="text-[10px] text-white/30 border border-white/[0.08] px-1.5 py-0.5 rounded-full shrink-0">
                            inativa
                        </span>
                    )}
                </div>
                <span className="text-white/35 text-[12px] mt-0.5 block truncate">
                    {contratos.length === 0
                        ? 'Sem contratos ativos'
                        : `${contratos.length} contrato${contratos.length === 1 ? '' : 's'} ativo${contratos.length === 1 ? '' : 's'}`}
                </span>
            </div>
            {contratos.length > 0
                ? contratos.slice(0, 3).map(c => (
                    <Chip key={c.id} label={c.servico_nome ?? 'Servico'} color="bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/20" />
                ))
                : <Chip label="Sem servicos" color="bg-white/[0.05] text-white/30 border-white/[0.08]" />
            }
        </div>
    );
}

function ContratosSection({ empresa }) {
    const contratos = empresa.servicos_contratados || [];

    return (
        <div className="space-y-3 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Briefcase size={14} className="text-ecf-yellow/70" />
                    <span className="text-white/80 text-[13px] font-semibold">Servicos contratados</span>
                </div>
                {/* Phase 36 Plan 36-02 (D-06) — botao "Adicionar contrato" removido.
                    Acao migrada para a pagina dedicada do Comercial. Aponta direto
                    para a rota nomeada do Comercial pra quem precisar atribuir
                    contrato a partir daqui. */}
                <a
                    href={`/comercial/atribuir-servico/${empresa.id}`}
                    className="inline-flex items-center gap-1 px-2.5 py-1 text-[12px] rounded-md bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow border border-ecf-yellow/20"
                >
                    Atribuir no Comercial
                </a>
            </div>
            {contratos.length === 0 ? (
                <p className="text-white/35 text-[12px] py-2">Nenhum contrato ativo.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-white/[0.06]">
                    <table className="w-full text-[12px]">
                        <thead>
                            <tr className="text-white/35 border-b border-white/[0.06]">
                                <th className="text-left py-2 px-3">Servico</th>
                                <th className="text-right py-2 px-3">Valor</th>
                                <th className="text-center py-2 px-3">Tipo</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contratos.map(c => (
                                <tr key={c.id} className="border-b border-white/[0.03] last:border-0 text-white/70">
                                    <td className="py-2 px-3">{c.servico_nome ?? '-'}</td>
                                    <td className="py-2 px-3 text-right font-mono">{fmtBRL(c.valor_contratado) ?? '-'}</td>
                                    <td className="py-2 px-3 text-center">{c.tipo_cobranca === 'mensal' ? 'Mensal' : 'Unica'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

// Conteúdo expandido da empresa: só os contratos de serviço (read-only).
// (O agrupamento agora é feito pelos grupos nomeados na aba "Grupos".)
function EmpresaForm({ empresa }) {
    return <ContratosSection empresa={empresa} />;
}

function EmpresaList({ empresas, initialAberta = null }) {
    const [aberta, setAberta] = useState(initialAberta);

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <div key={empresa.id}>
                    <EmpresaRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => setAberta(prev => prev === empresa.id ? null : empresa.id)}
                    />
                    {aberta === empresa.id && (
                        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">
                            <EmpresaForm empresa={empresa} />
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

export default function Empresas({ companies, servicos_disponiveis = [], grupos = [] }) {
    // Aba inicial + empresa em foco vindas do query param (?tab, ?empresa) —
    // ex: link "Serviço" da aba Pendências de /companies abre a empresa aqui.
    const params = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
    const [tab, setTab] = useState(() => (params.get('tab') === 'grupos' ? 'grupos' : 'empresas'));
    const empresaFoco = params.get('empresa') ? Number(params.get('empresa')) : null;

    const [busca, setBusca] = useState('');
    const [servicoFiltro, setServicoFiltro] = useState('');
    const [semServico, setSemServico] = useState(false);

    const servicosNomes = [...new Set(
        companies.flatMap(e => (e.servicos_contratados || []).map(s => s.servico_nome).filter(Boolean)),
    )].sort((a, b) => a.localeCompare(b, 'pt-BR'));

    const filtradas = companies.filter(e => {
        const q = busca.toLowerCase();
        const buscaOk = !busca.trim()
            || e.name.toLowerCase().includes(q)
            || String(e.adman_account_id || '').includes(q)
            || String(e.ml_store_id || '').includes(q);
        const servicoOk = !servicoFiltro
            || (e.servicos_contratados || []).some(s => s.servico_nome === servicoFiltro);
        const semServicoOk = !semServico || (e.servicos_contratados || []).length === 0;
        return buscaOk && servicoOk && semServicoOk;
    });

    return (
        <AppLayout title="Empresas - Administrativo">
            <main className="p-6">
                <div className="space-y-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 mb-2">
                                <Building2 size={20} className="text-ecf-yellow" />
                                <h1 className="text-xl font-semibold font-display text-white">Empresas</h1>
                            </div>
                            <p className="text-[13px] text-white/40">
                                Listagem read-only. Para atribuir contrato use o Comercial.
                            </p>
                        </div>
                        <span className="text-white/30 text-[12px] shrink-0 pt-1">
                            {companies.length} empresa{companies.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    {/* Abas: Empresas | Grupos (mesmos grupos nomeados de /companies) */}
                    <div className="flex gap-1 border-b border-white/[0.08]">
                        {[
                            { key: 'empresas', label: `Empresas (${companies.length})` },
                            { key: 'grupos', label: `Grupos (${grupos.length})` },
                        ].map(t => (
                            <button
                                key={t.key}
                                onClick={() => setTab(t.key)}
                                className={cn(
                                    'px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px',
                                    tab === t.key ? 'border-ecf-yellow text-white' : 'border-transparent text-white/50 hover:text-white/80',
                                )}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    {tab === 'empresas' && (
                        <>
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    type="text"
                                    value={busca}
                                    onChange={e => setBusca(e.target.value)}
                                    placeholder="Buscar por nome ou cust id..."
                                    className="h-9 flex-1 min-w-[220px] px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                                />
                                <select
                                    value={servicoFiltro}
                                    onChange={e => setServicoFiltro(e.target.value)}
                                    className={cn(
                                        'h-9 min-w-[180px] px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] focus:outline-none focus:border-ecf-yellow/40',
                                        servicoFiltro ? 'text-white/80' : 'text-white/30',
                                    )}
                                >
                                    <option value="">Todos os servicos</option>
                                    {servicosNomes.map(nome => (
                                        <option key={nome} value={nome}>{nome}</option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    onClick={() => setSemServico(v => !v)}
                                    className={cn('h-9 px-3 rounded-lg border text-[13px] whitespace-nowrap transition-colors',
                                        semServico ? 'border-fuchsia-500/40 bg-fuchsia-500/10 text-fuchsia-300' : 'border-white/[0.08] bg-white/[0.03] text-white/50 hover:text-white/80')}
                                >
                                    Sem serviço atribuído ({companies.filter(e => (e.servicos_contratados || []).length === 0).length})
                                </button>
                            </div>

                            <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
                                {filtradas.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-16 gap-2">
                                        <Building2 size={24} className="text-white/20" />
                                        <p className="text-[13px] text-white/40">Nenhuma empresa encontrada.</p>
                                    </div>
                                ) : (
                                    <EmpresaList
                                        empresas={filtradas}
                                        initialAberta={empresaFoco}
                                    />
                                )}
                            </div>
                        </>
                    )}

                    {tab === 'grupos' && (
                        <GruposManager grupos={grupos} companies={companies} servicos={servicos_disponiveis} />
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
