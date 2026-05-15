import AppLayout from '@/Layouts/AppLayout';
import { useForm, router, usePage } from '@inertiajs/react';

import { useState, useEffect, useRef } from 'react';
import { Plus, Pencil, Trash2, ChevronDown, ChevronUp, RefreshCw, X, PlusCircle, AlertTriangle, ExternalLink, Clock, Link2, BookUser } from 'lucide-react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import ComboInput from '@/Components/ComboInput';
import { cn } from '@/lib/utils';

const PRIORIDADES = ['1 Urgente','2 Alto','3 Média','4 Baixa','Finalizado'];
const SKUS_QTD   = { 1: 3, 2: 4, 3: 3 };

// Opções padrão (não removíveis pelo usuário)
const DEFAULT_ESTAGIOS = ['Não Listado','Estágio 1','Estágio 2','Estágio 3','Concluido','Churn','Finalizado','Problema'];
const DEFAULT_STATUS   = ['M0','M1','M2','M3','M4','Fechamento','Encerrado','Churn'];
const DEFAULT_PROJETOS = ['POLOS','Implantação','Incubadora','Assessoria'];
const DEFAULT_REGIOES  = []; // Região é totalmente livre — sem padrões

function fmt(n) { return Number(n ?? 0).toLocaleString('pt-BR'); }

function ProgressBar({ ok, total }) {
    const pct = total > 0 ? Math.round(ok / total * 100) : 0;
    const color = pct === 100 ? '#22c55e' : pct >= 50 ? '#eab308' : '#8b5cf6';
    return (
        <div className="flex items-center gap-2">
            <div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden">
                <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full transition-all" />
            </div>
            <span className="text-white/40 text-[11px] shrink-0">{ok}/{total}</span>
        </div>
    );
}

function SkuRow({ sku, idx, stage, onChange, onRemove }) {
    return (
        <div className="flex items-center gap-2">
            <input
                type="text"
                value={sku.sku}
                onChange={e => onChange(stage, idx, 'sku', e.target.value)}
                placeholder={`SKU ${idx + 1}`}
                className="flex-1 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
            />
            {sku.ok && <span className="text-emerald-400 text-[11px] font-bold shrink-0">✓ feito</span>}
            {onRemove && (
                <button type="button" onClick={() => onRemove(stage, idx)}
                    className="p-1 text-white/20 hover:text-red-400 transition-colors shrink-0">
                    <X size={12} />
                </button>
            )}
        </div>
    );
}

function SyncVendasModal({ empresa, onClose }) {
    const today    = new Date().toISOString().slice(0, 10);
    const anoAtual = new Date().getFullYear();
    const { data, setData, post, processing, errors } = useForm({
        date_from: `${anoAtual}-01-01`,
        date_to:   today,
    });

    function submit(e) {
        e.preventDefault();
        if (empresa) {
            post(route('mlb.empresas.sync-vendas', empresa.id), { onSuccess: onClose });
        } else {
            post(route('mlb.sync-vendas'), { onSuccess: onClose });
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <h2 className="text-white font-bold text-base">Sincronizar Vendas</h2>
                        <p className="text-white/40 text-[12px] mt-0.5">
                            {empresa ? empresa.nome : 'Todas as empresas com Cust ID'}
                        </p>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>

                <p className="text-white/50 text-[12px] mb-4 leading-relaxed">
                    Consulta a API Adman e atualiza as publicações: marca como <strong className="text-white/80">vendido</strong>, salva a <strong className="text-white/80">quantidade</strong> e o <strong className="text-white/80">preço de venda</strong> de cada anúncio no período.
                </p>

                <form onSubmit={submit} className="space-y-4">
                    {/* Atalhos de período */}
                    <div className="flex flex-wrap gap-1.5">
                        {[
                            { label: 'Hoje',       from: today,                    to: today },
                            { label: 'Este mês',   from: `${anoAtual}-${String(new Date().getMonth()+1).padStart(2,'0')}-01`, to: today },
                            { label: `${anoAtual}`, from: `${anoAtual}-01-01`,    to: today },
                            { label: 'Histórico',  from: '2022-01-01',            to: today },
                        ].map(p => (
                            <button key={p.label} type="button"
                                onClick={() => { setData('date_from', p.from); setData('date_to', p.to); }}
                                className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold border transition-colors ${
                                    data.date_from === p.from && data.date_to === p.to
                                        ? 'bg-ecf-yellow/20 border-ecf-yellow/40 text-ecf-yellow'
                                        : 'border-white/[0.08] text-white/50 hover:text-white/80'
                                }`}>
                                {p.label}
                            </button>
                        ))}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">De</label>
                            <input type="date" value={data.date_from} max={today}
                                onChange={e => setData('date_from', e.target.value)}
                                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
                            {errors.date_from && <p className="text-red-400 text-xs mt-1">{errors.date_from}</p>}
                        </div>
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Até</label>
                            <input type="date" value={data.date_to} max={today}
                                onChange={e => setData('date_to', e.target.value)}
                                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
                            {errors.date_to && <p className="text-red-400 text-xs mt-1">{errors.date_to}</p>}
                        </div>
                    </div>

                    {errors.api && (
                        <p className="text-red-400 text-[12px] bg-red-500/10 rounded-lg px-3 py-2">{errors.api}</p>
                    )}
                    {errors.empresa && (
                        <p className="text-red-400 text-[12px] bg-red-500/10 rounded-lg px-3 py-2">{errors.empresa}</p>
                    )}

                    <div className="flex gap-3 pt-1">
                        <button type="submit" disabled={processing}
                            className="h-9 px-5 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] disabled:opacity-50 hover:bg-ecf-yellow/90 transition-colors flex items-center gap-2">
                            <RefreshCw size={13} className={processing ? 'animate-spin' : ''} />
                            {processing ? 'Sincronizando…' : 'Sincronizar'}
                        </button>
                        <button type="button" onClick={onClose}
                            className="h-9 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function EmpresaForm({ empresa, publicadores, estagiosDb, fasesDb, polosDb, projetosDb, excluidos = {}, onClose }) {
    const editing = !!empresa;
    const emptySkus = (n) => Array.from({ length: n }, () => ({ sku: '', ok: false }));

    const excl = (campo) => excluidos[campo] ?? [];
    const estagioOptions = [...new Set([...DEFAULT_ESTAGIOS.filter(d => !excl('estagio').includes(d)), ...(estagiosDb ?? [])])];
    const statusOptions  = [...new Set([...DEFAULT_STATUS.filter(d => !excl('fase').includes(d)),    ...(fasesDb ?? [])])];
    const projetoOptions = [...new Set([...DEFAULT_PROJETOS.filter(d => !excl('projeto').includes(d)), ...(projetosDb ?? [])])];
    const regiaoOptions  = [...new Set([...DEFAULT_REGIOES.filter(d => !excl('polo').includes(d)),   ...(polosDb ?? [])])];

    const handleDeleteOpcao = (campo) => (valor) => {
        router.delete(route('mlb.opcao-campo.destroy'), {
            data: { campo, valor },
            preserveScroll: true,
            preserveState: false,
        });
    };

    const handleCreateOpcao = (campo) => (valor) => {
        router.post(route('mlb.opcao-campo.store'), { campo, valor }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const { data, setData, post, put, processing, errors } = useForm({
        nome:           empresa?.nome           ?? '',
        cust_id:        empresa?.cust_id        ?? '',
        polo:           empresa?.polo           ?? '',
        gmail:          empresa?.gmail          ?? '',
        estagio:        empresa?.estagio        ?? 'Não Listado',
        fase:           empresa?.fase           ?? '',
        projeto:        empresa?.projeto        ?? '',
        prioridade:     empresa?.prioridade     ?? '',
        responsavel_id: empresa?.responsavel_id ?? '',
        contexto:       empresa?.contexto       ?? '',
        skus_estagio1:  empresa?.skus_estagio1?.length ? empresa.skus_estagio1 : emptySkus(3),
        skus_estagio2:  empresa?.skus_estagio2?.length ? empresa.skus_estagio2 : emptySkus(4),
        skus_estagio3:  empresa?.skus_estagio3?.length ? empresa.skus_estagio3 : emptySkus(3),
        prazo_estagio1: empresa?.prazo_estagio1 ?? '',
        prazo_estagio2: empresa?.prazo_estagio2 ?? '',
        prazo_estagio3: empresa?.prazo_estagio3 ?? '',
        encerramento:   empresa?.encerramento   ?? '',
    });

    function handleSkuChange(stage, idx, field, value) {
        const key = `skus_estagio${stage}`;
        const updated = [...data[key]];
        updated[idx] = { ...updated[idx], [field]: value };
        setData(key, updated);
    }

    function addSku(stage) {
        const key = `skus_estagio${stage}`;
        setData(key, [...data[key], { sku: '', ok: false }]);
    }

    function removeSku(stage, idx) {
        const key = `skus_estagio${stage}`;
        const updated = data[key].filter((_, i) => i !== idx);
        setData(key, updated);
    }

    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: onClose };
        if (editing) {
            put(route('mlb.empresas.update', empresa.id), opts);
        } else {
            post(route('mlb.empresas.store'), opts);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto p-6">
                <h2 className="text-white font-bold text-lg mb-5">
                    {editing ? 'Editar Empresa' : 'Nova Empresa'}
                </h2>
                <form onSubmit={submit} className="space-y-4">
                    {/* Dados gerais */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="col-span-2">
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Nome da Empresa *</label>
                            <input type="text" value={data.nome} onChange={e => setData('nome', e.target.value)}
                                required className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
                            {errors.nome && <p className="text-red-400 text-xs mt-1">{errors.nome}</p>}
                        </div>

                        {[
                            ['cust_id', 'Cust ID', 'Ex: 1234567890'],
                            ['gmail', 'Gmail', 'Ex: empresa@gmail.com'],
                        ].map(([k, label, ph]) => (
                            <div key={k}>
                                <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">{label}</label>
                                <input type="text" value={data[k]} onChange={e => setData(k, e.target.value)}
                                    placeholder={ph} className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20" />
                            </div>
                        ))}

                        {/* Região (ComboInput — selecione ou crie) */}
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">
                                Região
                                <span className="text-white/25 ml-1 normal-case font-normal">— ou digite uma nova</span>
                            </label>
                            <ComboInput
                                value={data.polo}
                                onChange={v => setData('polo', v)}
                                options={regiaoOptions}
                                defaults={DEFAULT_REGIOES}
                                placeholder="Selecione ou crie uma região"
                                onDelete={handleDeleteOpcao('polo')}
                                onCreateOption={handleCreateOpcao('polo')}
                            />
                        </div>

                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Responsável</label>
                            <Select
                                value={data.responsavel_id ? String(data.responsavel_id) : '__none__'}
                                onValueChange={v => setData('responsavel_id', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                    <SelectValue placeholder="— sem responsável —" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">— sem responsável —</SelectItem>
                                    {publicadores.map(p => <SelectItem key={p.id} value={String(p.id)}>{p.nome}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Estágio (combobox dinâmico) */}
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">
                                Estágio
                                <span className="text-white/25 ml-1 normal-case font-normal">— ou digite um novo</span>
                            </label>
                            <ComboInput
                                value={data.estagio}
                                onChange={v => setData('estagio', v)}
                                options={estagioOptions}
                                defaults={DEFAULT_ESTAGIOS}
                                placeholder="Selecione ou crie um estágio"
                                onDelete={handleDeleteOpcao('estagio')}
                                onCreateOption={handleCreateOpcao('estagio')}
                            />
                        </div>

                        {/* Status (antiga "Fase") */}
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">
                                Status
                                <span className="text-white/25 ml-1 normal-case font-normal">— M0..M4, Fechamento, Encerrado, Churn</span>
                            </label>
                            <ComboInput
                                value={data.fase}
                                onChange={v => setData('fase', v)}
                                options={statusOptions}
                                defaults={DEFAULT_STATUS}
                                placeholder="Selecione ou crie um status"
                                onDelete={handleDeleteOpcao('fase')}
                                onCreateOption={handleCreateOpcao('fase')}
                            />
                        </div>

                        {/* Projeto (novo campo independente) */}
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">
                                Projeto
                                <span className="text-white/25 ml-1 normal-case font-normal">— POLOS, Implantação, Incubadora, Assessoria</span>
                            </label>
                            <ComboInput
                                value={data.projeto}
                                onChange={v => setData('projeto', v)}
                                options={projetoOptions}
                                defaults={DEFAULT_PROJETOS}
                                placeholder="Selecione ou crie um projeto"
                                onDelete={handleDeleteOpcao('projeto')}
                                onCreateOption={handleCreateOpcao('projeto')}
                            />
                        </div>

                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Prioridade</label>
                            <Select
                                value={data.prioridade || '__none__'}
                                onValueChange={v => setData('prioridade', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                    <SelectValue placeholder="—" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">—</SelectItem>
                                    {PRIORIDADES.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Encerramento</label>
                            <input type="date" value={data.encerramento} onChange={e => setData('encerramento', e.target.value)}
                                className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
                        </div>

                        <div className="col-span-2">
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Contexto / Observações</label>
                            <textarea value={data.contexto} onChange={e => setData('contexto', e.target.value)} rows={2}
                                className="w-full px-3 py-2 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 resize-none placeholder:text-white/20"
                                placeholder="Observações sobre a empresa..." />
                        </div>
                    </div>

                    {/* SKUs por estágio */}
                    <div>
                        <p className="text-white font-semibold text-sm mb-3">SKUs por Estágio</p>
                        <div className="grid grid-cols-3 gap-4">
                            {[1, 2, 3].map(stage => (
                                <div key={stage} className="space-y-2">
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-white/60 text-[12px] font-semibold">Estágio {stage}</span>
                                        <div>
                                            <label className="text-white/40 text-[11px] mr-1">Prazo</label>
                                            <input type="date" value={data[`prazo_estagio${stage}`]}
                                                onChange={e => setData(`prazo_estagio${stage}`, e.target.value)}
                                                className="h-7 px-2 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[11px] focus:outline-none" />
                                        </div>
                                    </div>
                                    {data[`skus_estagio${stage}`].map((sku, idx) => (
                                        <SkuRow key={idx} sku={sku} idx={idx} stage={stage}
                                            onChange={handleSkuChange}
                                            onRemove={removeSku} />
                                    ))}
                                    <button type="button" onClick={() => addSku(stage)}
                                        className="flex items-center gap-1 text-white/30 hover:text-ecf-yellow text-[11px] transition-colors mt-1">
                                        <PlusCircle size={12} /> Adicionar SKU
                                    </button>
                                </div>
                            ))}
                        </div>
                        <p className="text-white/30 text-[11px] mt-2">
                            Os checkboxes ✓ são marcados pelo publicador conforme conclui cada SKU.
                        </p>
                    </div>

                    <div className="flex gap-3 pt-2">
                        <button type="submit" disabled={processing}
                            className="h-10 px-6 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] disabled:opacity-50 hover:bg-ecf-yellow/90 transition-colors">
                            {processing ? 'Salvando…' : editing ? 'Atualizar' : 'Cadastrar'}
                        </button>
                        <button type="button" onClick={onClose}
                            className="h-10 px-6 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ProblemaEmpresaModal({ empresa, onClose }) {
    const { data, setData, patch, processing } = useForm({ problema_nota: empresa.problema_nota ?? '' });

    function submit(e) {
        e.preventDefault();
        patch(route('mlb.empresas.problema', empresa.id), { onSuccess: onClose });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-white font-bold text-base flex items-center gap-2">
                        <AlertTriangle size={16} className="text-red-400" />
                        {empresa.problema ? 'Problema na Conta' : 'Reportar Problema na Conta'}
                    </h2>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>

                <p className="text-white/60 text-[13px] font-semibold mb-1">{empresa.nome}</p>
                {empresa.cust_id && <p className="text-white/30 text-[11px] font-mono mb-3">{empresa.cust_id}</p>}

                {empresa.problema ? (
                    <>
                        <div className="mb-4 rounded-lg border border-red-500/30 bg-red-500/8 px-3 py-2">
                            <p className="text-red-300 text-[12px]">{empresa.problema_nota || 'Sem descrição'}</p>
                            {empresa.problema_em && <p className="text-red-400/50 text-[11px] mt-1">Registrado em {empresa.problema_em}</p>}
                        </div>
                        <p className="text-white/40 text-[12px] mb-3">Clique em "Remover problema" para limpar ou edite a descrição abaixo:</p>
                        <textarea
                            value={data.problema_nota}
                            onChange={e => setData('problema_nota', e.target.value)}
                            rows={3}
                            placeholder="Descrição do problema…"
                            className="w-full px-3 py-2 rounded-xl border border-red-500/30 bg-red-500/5 text-white text-[12px] focus:outline-none focus:border-red-400/50 resize-none placeholder:text-white/20 mb-3"
                        />
                    </>
                ) : (
                    <>
                        <p className="text-white/40 text-[12px] mb-3">
                            Descreva o problema desta conta (suspensa, sem imagem, erro de login, etc.).
                        </p>
                        <textarea
                            value={data.problema_nota}
                            onChange={e => setData('problema_nota', e.target.value)}
                            rows={3}
                            placeholder="Ex: Conta suspensa pelo Mercado Livre desde 02/05…"
                            className="w-full px-3 py-2 rounded-xl border border-red-500/30 bg-red-500/5 text-white text-[12px] focus:outline-none focus:border-red-400/50 resize-none placeholder:text-white/20 mb-3"
                        />
                    </>
                )}

                <form onSubmit={submit} className="flex gap-3">
                    <button type="submit" disabled={processing}
                        className={cn(
                            'h-9 px-5 rounded-xl font-bold text-[13px] disabled:opacity-50 transition-colors',
                            empresa.problema
                                ? 'bg-white/10 text-white hover:bg-white/20'
                                : 'bg-red-500 text-white hover:bg-red-600'
                        )}>
                        {processing ? 'Salvando…' : empresa.problema ? 'Remover problema' : 'Confirmar problema'}
                    </button>
                    <button type="button" onClick={onClose}
                        className="h-9 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                        Cancelar
                    </button>
                </form>
            </div>
        </div>
    );
}

export default function Empresas({ empresas, publicadores, estagiosDb, fasesDb, polosDb, projetosDb, excluidos = {} }) {
    const { props } = usePage();
    const flash           = props.flash ?? {};
    const pubRole         = props.auth?.user?.publication_role;
    const isGestor        = pubRole === 'gestor';
    const isAdminUser     = props.auth?.user?.role === 'admin';
    const podeSync        = isGestor || isAdminUser;
    const appUrl          = props.asset_url ?? '';

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [expanded, setExpanded] = useState(null);
    const [busca, setBusca] = useState('');
    const [filtroResp, setFiltroResp] = useState('');
    const [filtroEstagio, setFiltroEstagio] = useState('');
    const [filtroFase, setFiltroFase] = useState('');
    const [filtroProjeto, setFiltroProjeto] = useState('');
    const [syncEmpresa, setSyncEmpresa] = useState(null);
    const [flashMsg, setFlashMsg] = useState(null);
    const [problemaEmpresa, setProblemaEmpresa] = useState(null);

    useEffect(() => {
        if (flash.success) { setFlashMsg({ type: 'success', text: flash.success }); }
        if (flash.error)   { setFlashMsg({ type: 'error',   text: flash.error   }); }
    }, [flash.success, flash.error]);

    function handleDelete(id) {
        if (!confirm('Remover esta empresa?')) return;
        router.delete(route('mlb.empresas.destroy', id));
    }

    const filtroAtivo = busca || filtroResp || filtroEstagio || filtroFase || filtroProjeto;
    const limparFiltros = () => { setBusca(''); setFiltroResp(''); setFiltroEstagio(''); setFiltroFase(''); setFiltroProjeto(''); };

    const lista = (empresas ?? []).filter(e => {
        const matchBusca    = !busca          || e.nome.toLowerCase().includes(busca.toLowerCase()) || (e.cust_id ?? '').includes(busca);
        const matchResp     = !filtroResp     || String(e.responsavel_id) === String(filtroResp);
        const matchEstagio  = !filtroEstagio  || e.estagio === filtroEstagio;
        const matchFase     = !filtroFase     || e.fase === filtroFase;
        const matchProjeto  = !filtroProjeto  || e.projeto === filtroProjeto;
        return matchBusca && matchResp && matchEstagio && matchFase && matchProjeto;
    });

    const concluidas = lista.filter(e => e.estagio === 'Concluido');
    const pendentes  = lista.filter(e => e.estagio !== 'Concluido');

    const prioColor = { '1 Urgente':'text-red-400', '2 Alto':'text-orange-400', '3 Média':'text-yellow-400', '4 Baixa':'text-white/30', 'Finalizado':'text-white/20' };

    function stagesVencidos(e) {
        return [1, 2, 3].filter(stage => {
            const prazo = e[`prazo_estagio${stage}`];
            if (!prazo) return false;
            const diff = Math.ceil((new Date(prazo + 'T00:00:00') - new Date()) / 86400000);
            if (diff >= 0) return false;
            const skus = e[`skus_estagio${stage}`] ?? [];
            return skus.filter(s => (s.sku ?? '').trim()).some(s => !s.ok);
        });
    }

    function renderEmpresa(e) {
        const isExp = expanded === e.id;
        const { ok, total, pct } = e.progresso;
        const temProblemaMLBs = (e.problemas_count ?? 0) > 0;
        const temProblemaConha = !!e.problema;
        const temQualquerProblema = temProblemaConha || temProblemaMLBs;
        const vencidos    = stagesVencidos(e);
        const temAtrasado = [1,2,3].some(s => (e[`skus_estagio${s}`] ?? []).some(sk => sk.atrasado));

        return (
            <div key={e.id} className={cn(
                'card-ecf rounded-2xl overflow-hidden transition-all',
                e.estagio === 'Concluido' && 'opacity-60',
                temQualquerProblema && 'ring-1 ring-red-500/30'
            )}>
                {/* Header */}
                <div className="flex items-center gap-3 p-4 cursor-pointer hover:bg-white/[0.02]" onClick={() => setExpanded(isExp ? null : e.id)}>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-white font-semibold text-[14px]">{e.nome}</span>
                            {e.cust_id && <span className="text-white/30 text-[11px] font-mono">{e.cust_id}</span>}
                            {/* Badge: problema na conta */}
                            {temProblemaConha && (
                                <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-red-500/20 text-red-400 border-red-500/40">
                                    <AlertTriangle size={9} /> Conta c/ problema
                                </span>
                            )}
                            {/* Badge: problemas em MLBs */}
                            {temProblemaMLBs && (
                                <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-orange-500/15 text-orange-400 border-orange-500/30">
                                    <AlertTriangle size={9} /> {e.problemas_count} MLB(s) c/ problema
                                </span>
                            )}
                            {/* Estágio */}
                            {e.estagio && (
                                <span className={cn('px-2 py-0.5 rounded-full text-[10px] font-bold border',
                                    e.estagio === 'Concluido'
                                        ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                        : 'bg-purple-500/15 text-purple-400 border-purple-500/30'
                                )}>{e.estagio}</span>
                            )}
                            {/* Status (antiga "Fase") */}
                            {e.fase && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-sky-500/15 text-sky-400 border-sky-500/30">
                                    {e.fase}
                                </span>
                            )}
                            {/* Projeto */}
                            {e.projeto && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-amber-500/15 text-amber-400 border-amber-500/30">
                                    {e.projeto}
                                </span>
                            )}
                            {e.prioridade && <span className={cn('text-[11px] font-medium', prioColor[e.prioridade])}>{e.prioridade}</span>}
                            {vencidos.length > 0 && (
                                <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-red-500/15 text-red-400 border-red-500/30">
                                    <Clock size={9} /> {vencidos.length} estágio{vencidos.length > 1 ? 's' : ''} vencido{vencidos.length > 1 ? 's' : ''}
                                </span>
                            )}
                            {temAtrasado && (
                                <span title="Possui SKU(s) concluído(s) fora do prazo"
                                    className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold border bg-orange-500/10 border-orange-500/25 text-orange-400">
                                    <Clock size={9} /> Atrasado
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-4 mt-1.5">
                            <div className="flex-1 max-w-[220px]">
                                <ProgressBar ok={ok} total={total} />
                            </div>
                            {e.responsavel_nome && (
                                <span className="text-white/40 text-[11px]">→ {e.responsavel_nome}</span>
                            )}
                            {e.polo && <span className="text-white/30 text-[11px]">📍 {e.polo}</span>}
                        </div>
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                        {/* Links de implementação */}
                        {e.implementacao_token && (
                            <>
                                <a
                                    href={`${appUrl}/implementacao/${e.implementacao_token}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    onClick={ev => ev.stopPropagation()}
                                    title="Link do Cliente (Implementação)"
                                    className="p-1.5 rounded-lg text-white/30 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-colors"
                                >
                                    <Link2 size={14} />
                                </a>
                                <a
                                    href={`${appUrl}/implementacao/${e.implementacao_token}/publicador`}
                                    target="_blank"
                                    rel="noreferrer"
                                    onClick={ev => ev.stopPropagation()}
                                    title="Link do Publicador"
                                    className="p-1.5 rounded-lg text-white/30 hover:text-violet-400 hover:bg-violet-500/10 transition-colors"
                                >
                                    <BookUser size={14} />
                                </a>
                            </>
                        )}
                        {/* Botão problema da conta */}
                        <button
                            onClick={ev => { ev.stopPropagation(); setProblemaEmpresa(e); }}
                            title={temProblemaConha ? `Problema: ${e.problema_nota}` : 'Reportar problema na conta'}
                            className={cn(
                                'p-1.5 rounded-lg transition-colors',
                                temProblemaConha
                                    ? 'text-red-400 bg-red-500/10 hover:bg-red-500/20'
                                    : 'text-white/30 hover:text-red-400 hover:bg-red-500/10'
                            )}>
                            <AlertTriangle size={14} />
                        </button>
                        {e.cust_id && (
                            <button
                                onClick={ev => { ev.stopPropagation(); setSyncEmpresa(e); }}
                                title="Sincronizar vendas via Adman"
                                className="p-1.5 rounded-lg text-white/30 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-colors">
                                <RefreshCw size={14} />
                            </button>
                        )}
                        <button onClick={ev => { ev.stopPropagation(); setEditing(e); setOpen(true); }}
                            className="p-1.5 rounded-lg text-white/30 hover:text-white/70 hover:bg-white/[0.05] transition-colors">
                            <Pencil size={14} />
                        </button>
                        <button onClick={ev => { ev.stopPropagation(); handleDelete(e.id); }}
                            className="p-1.5 rounded-lg text-white/30 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                            <Trash2 size={14} />
                        </button>
                        {isExp ? <ChevronUp size={16} className="text-white/30" /> : <ChevronDown size={16} className="text-white/30" />}
                    </div>
                </div>

                {/* Expanded: SKUs */}
                {isExp && (
                    <div className="border-t border-white/[0.06] p-4">
                        {/* Problema da conta (nível empresa) */}
                        {temProblemaConha && (
                            <div className="mb-4 rounded-xl border border-red-500/40 bg-red-500/10 p-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-start gap-2">
                                        <AlertTriangle size={13} className="text-red-400 shrink-0 mt-0.5" />
                                        <div>
                                            <p className="text-red-400 text-[11px] font-bold uppercase tracking-wide mb-0.5">Problema na Conta</p>
                                            <p className="text-red-300 text-[12px]">{e.problema_nota || 'Sem descrição'}</p>
                                            {e.problema_em && <p className="text-red-400/50 text-[11px] mt-0.5">Registrado em {e.problema_em}</p>}
                                        </div>
                                    </div>
                                    <button onClick={() => setProblemaEmpresa(e)}
                                        className="text-red-400/60 hover:text-red-300 text-[11px] underline shrink-0 transition-colors">
                                        Editar / Remover
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Problemas em MLBs reportados pelo publicador */}
                        {temProblemaMLBs && (
                            <div className="mb-4 rounded-xl border border-orange-500/25 bg-orange-500/8 p-3">
                                <p className="text-orange-400 text-[11px] font-bold uppercase tracking-wide mb-2 flex items-center gap-1.5">
                                    <AlertTriangle size={11} /> Problemas em Anúncios
                                </p>
                                <div className="space-y-1.5">
                                    {(e.problemas ?? []).map(prob => (
                                        <div key={prob.id} className="flex items-start gap-2 text-[12px]">
                                            <a href={`https://produto.mercadolivre.com.br/${prob.mlb_code.replace('MLB','MLB-')}`}
                                                target="_blank" rel="noopener noreferrer"
                                                className="text-purple-400 font-mono text-[11px] hover:text-purple-300 flex items-center gap-1 shrink-0">
                                                {prob.mlb_code} <ExternalLink size={8} />
                                            </a>
                                            <span className="text-orange-200">{prob.problema_nota || 'Sem descrição'}</span>
                                            {prob.problema_em && <span className="text-orange-400/40 text-[11px] ml-auto shrink-0">{prob.problema_em}</span>}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {e.contexto && (
                            <p className="text-white/50 text-[12px] italic mb-4">📝 {e.contexto}</p>
                        )}
                        <div className="grid grid-cols-3 gap-4">
                            {[1, 2, 3].map(stage => {
                                const skus       = e[`skus_estagio${stage}`] ?? [];
                                const filled     = skus.filter(s => (s.sku ?? '').trim() !== '');
                                const done       = filled.filter(s => s.ok);
                                const isVencido  = vencidos.includes(stage);
                                const prazo      = e[`prazo_estagio${stage}`];
                                const pendAtras  = filled.filter(s => !s.ok && isVencido).length;

                                return (
                                    <div key={stage}>
                                        <div className={cn(
                                            'rounded-lg px-2 py-1.5 mb-2',
                                            isVencido ? 'bg-red-500/10 border border-red-500/20' : ''
                                        )}>
                                            <p className={cn('text-[11px] font-semibold uppercase tracking-wide',
                                                isVencido ? 'text-red-400' : 'text-white/50')}>
                                                Estágio {stage} · {done.length}/{filled.length}
                                            </p>
                                            {prazo && (
                                                <p className={cn('text-[10px] mt-0.5 flex items-center gap-1',
                                                    isVencido ? 'text-red-400' : 'text-white/30')}>
                                                    <Clock size={9} />
                                                    {isVencido ? 'Vencido em ' : 'Prazo: '}
                                                    {new Date(prazo + 'T00:00:00').toLocaleDateString('pt-BR')}
                                                    {isVencido && pendAtras > 0 && (
                                                        <span className="font-bold"> · {pendAtras} pendente{pendAtras > 1 ? 's' : ''}</span>
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-1.5">
                                            {filled.length === 0
                                                ? <p className="text-white/20 text-[12px] italic">— sem SKUs —</p>
                                                : skus.map((s, i) => (s.sku ?? '').trim() ? (
                                                    <div key={i} className={cn(
                                                        'flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-[12px]',
                                                        s.ok
                                                            ? s.atrasado ? 'bg-red-500/8 text-red-300' : 'bg-emerald-500/10 text-emerald-400'
                                                            : isVencido ? 'bg-red-500/5 text-red-300 border border-red-500/15' : 'bg-white/[0.03] text-white/70'
                                                    )}>
                                                        <span className={cn('w-4 h-4 rounded border flex items-center justify-center shrink-0 text-[10px]',
                                                            s.ok
                                                                ? s.atrasado ? 'bg-red-500 border-red-500 text-white' : 'bg-emerald-500 border-emerald-500 text-white'
                                                                : isVencido ? 'border-red-500/30' : 'border-white/20')}>
                                                            {s.ok ? '✓' : ''}
                                                        </span>
                                                        <div className="flex-1 min-w-0">
                                                            <span className="truncate block">{s.sku}</span>
                                                            {s.ok && s.concluido_em && (
                                                                <span className={cn('text-[9px]', s.atrasado ? 'text-red-400' : 'text-white/25')}>
                                                                    {s.atrasado ? '⚠ Atrasado · ' : ''}
                                                                    {new Date(s.concluido_em).toLocaleDateString('pt-BR')}
                                                                </span>
                                                            )}
                                                            {!s.ok && isVencido && (
                                                                <span className="text-[9px] text-red-400">Não concluído no prazo</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                ) : null)
                                            }
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
        );
    }

    return (
        <AppLayout title="Empresas MLB">
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Empresas MLB</h1>
                    <p className="text-white/40 text-sm mt-0.5">
                        Cadastre empresas, distribua os SKUs nos estágios e atribua um publicador
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {podeSync && (
                        <button onClick={() => setSyncEmpresa('all')}
                            className="h-10 px-4 rounded-xl border border-ecf-yellow/30 text-ecf-yellow text-[13px] font-semibold flex items-center gap-2 hover:bg-ecf-yellow/10 transition-colors">
                            <RefreshCw size={14} /> Sync Vendas + Preços
                        </button>
                    )}
                    <button onClick={() => { setEditing(null); setOpen(true); }}
                        className="h-10 px-5 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] flex items-center gap-2 hover:bg-ecf-yellow/90 transition-colors">
                        <Plus size={16} /> Nova Empresa
                    </button>
                </div>
            </div>

            {/* Flash */}
            {flashMsg && (
                <div className={cn(
                    'rounded-xl px-4 py-3 mb-5 flex items-center justify-between text-[13px]',
                    flashMsg.type === 'success'
                        ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                        : 'bg-red-500/15 text-red-400 border border-red-500/30'
                )}>
                    <span>{flashMsg.text}</span>
                    <button onClick={() => setFlashMsg(null)} className="ml-4 opacity-60 hover:opacity-100 transition-opacity">
                        <X size={14} />
                    </button>
                </div>
            )}

            {/* KPIs */}
            <div className="grid grid-cols-4 gap-4 mb-6">
                {[
                    { label: 'Total',         value: empresas?.length ?? 0, color: 'text-ecf-yellow' },
                    { label: 'Em andamento',  value: (empresas ?? []).filter(e => e.estagio !== 'Concluido').length, color: 'text-purple-400' },
                    { label: 'Concluídas',    value: (empresas ?? []).filter(e => e.estagio === 'Concluido').length, color: 'text-emerald-400' },
                    { label: 'Sem responsável', value: (empresas ?? []).filter(e => !e.responsavel_id).length, color: 'text-orange-400' },
                ].map(k => (
                    <div key={k.label} className="card-ecf rounded-xl p-4">
                        <p className="text-white/40 text-[11px] uppercase tracking-wide font-semibold">{k.label}</p>
                        <p className={cn('font-display font-bold text-2xl mt-1', k.color)}>{fmt(k.value)}</p>
                    </div>
                ))}
            </div>

            {/* Filtros */}
            <div className="card-ecf rounded-2xl p-4 mb-4 flex gap-3 flex-wrap items-center">
                <input type="text" value={busca} onChange={e => setBusca(e.target.value)}
                    placeholder="Buscar por nome ou Cust ID…"
                    className="h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none placeholder:text-white/20 min-w-[200px]" />
                <Select value={filtroResp || '__none__'} onValueChange={v => setFiltroResp(v === '__none__' ? '' : v)}>
                    <SelectTrigger className="h-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 w-44">
                        <SelectValue placeholder="Responsável" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__none__">Todos os responsáveis</SelectItem>
                        <SelectItem value="0">Sem responsável</SelectItem>
                        {publicadores.map(p => <SelectItem key={p.id} value={String(p.id)}>{p.nome}</SelectItem>)}
                    </SelectContent>
                </Select>
                {estagiosDb.length > 0 && (
                    <Select value={filtroEstagio || '__none__'} onValueChange={v => setFiltroEstagio(v === '__none__' ? '' : v)}>
                        <SelectTrigger className="h-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 w-36">
                            <SelectValue placeholder="Estágio" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">Todos os estágios</SelectItem>
                            {estagiosDb.map(v => <SelectItem key={v} value={v}>{v}</SelectItem>)}
                        </SelectContent>
                    </Select>
                )}
                {fasesDb.length > 0 && (
                    <Select value={filtroFase || '__none__'} onValueChange={v => setFiltroFase(v === '__none__' ? '' : v)}>
                        <SelectTrigger className="h-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 w-32">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">Todos os status</SelectItem>
                            {fasesDb.map(v => <SelectItem key={v} value={v}>{v}</SelectItem>)}
                        </SelectContent>
                    </Select>
                )}
                {projetosDb.length > 0 && (
                    <Select value={filtroProjeto || '__none__'} onValueChange={v => setFiltroProjeto(v === '__none__' ? '' : v)}>
                        <SelectTrigger className="h-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 w-36">
                            <SelectValue placeholder="Projeto" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">Todos os projetos</SelectItem>
                            {projetosDb.map(v => <SelectItem key={v} value={v}>{v}</SelectItem>)}
                        </SelectContent>
                    </Select>
                )}
                {filtroAtivo && (
                    <button onClick={limparFiltros}
                        className="h-9 px-3 rounded-xl border border-white/[0.08] text-white/40 text-[12px] hover:text-white hover:border-white/20 transition-colors flex items-center gap-1.5">
                        <X size={12} /> Limpar
                    </button>
                )}
                {filtroAtivo && (
                    <span className="text-white/30 text-[12px] ml-auto">{lista.length} de {empresas?.length ?? 0}</span>
                )}
            </div>

            {/* Lista */}
            {pendentes.length === 0 && concluidas.length === 0 && (
                <div className="card-ecf rounded-2xl p-12 text-center text-white/20">
                    Nenhuma empresa cadastrada. Clique em "Nova Empresa" para começar.
                </div>
            )}

            <div className="space-y-3">
                {pendentes.map(renderEmpresa)}
                {concluidas.length > 0 && (
                    <>
                        <div className="flex items-center gap-2 py-2">
                            <div className="h-px flex-1 bg-white/[0.06]" />
                            <span className="text-white/20 text-[11px] uppercase tracking-wider">Concluídas ({concluidas.length})</span>
                            <div className="h-px flex-1 bg-white/[0.06]" />
                        </div>
                        {concluidas.map(renderEmpresa)}
                    </>
                )}
            </div>

            {/* Modal Sync Vendas */}
            {syncEmpresa && (
                <SyncVendasModal
                    empresa={syncEmpresa === 'all' ? null : syncEmpresa}
                    onClose={() => setSyncEmpresa(null)}
                />
            )}

            {/* Modal Problema da Conta */}
            {problemaEmpresa && (
                <ProblemaEmpresaModal
                    empresa={problemaEmpresa}
                    onClose={() => setProblemaEmpresa(null)}
                />
            )}

            {/* Modal Empresa */}
            {open && (
                <EmpresaForm
                    empresa={editing}
                    publicadores={publicadores}
                    estagiosDb={estagiosDb}
                    fasesDb={fasesDb}
                    polosDb={polosDb}
                    projetosDb={projetosDb}
                    excluidos={excluidos}
                    onClose={() => { setOpen(false); setEditing(null); }}
                />
            )}
        </AppLayout>
    );
}
