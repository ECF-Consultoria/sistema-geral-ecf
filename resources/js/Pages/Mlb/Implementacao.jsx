import AppLayout from '@/Layouts/AppLayout';
import { router, usePage } from '@inertiajs/react';
import { useState, useRef } from 'react';
import { Link2, Copy, Check, ExternalLink, RefreshCw, X, BookOpen, Eye, Plus, ArrowRightLeft, Settings2, BarChart2, FileText } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
// Mapa de estágio extraído para módulo compartilhado (padroniza Onboarding + Painel Polos).
import { ESTAGIO_COLORS } from '@/Pages/Polos/components/estagioBadge';
// Célula de Cust ID — MESMO componente do Painel Polos (copiar / criar / editar inline).
import { CustIdCell } from '@/Pages/Polos/components/CustIdCell';
import { NomeEmpresaCell } from '@/Pages/Polos/components/NomeEmpresaCell';

// Status do envio do link ao cliente (ONB-ENVIO-LINK)
const STATUS_ENVIO_LABELS = {
    falta_enviar: 'Pendente de envio',
    enviado:      'Enviado',
    concluido:    'Concluído',
};
const STATUS_ENVIO_BADGE = {
    falta_enviar: 'text-red-300 bg-red-500/10 border-red-500/20',
    enviado:      'text-amber-300 bg-amber-500/10 border-amber-500/20',
    concluido:    'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
};

function ProgressBar({ pct, feitos, total }) {
    const color = pct === 100 ? '#22c55e' : pct >= 60 ? '#eab308' : '#6366f1';
    return (
        <div className="flex items-center gap-2 min-w-[140px]">
            <div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden">
                <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full transition-all" />
            </div>
            <span className="text-white/40 text-[11px] shrink-0">{feitos}/{total}</span>
        </div>
    );
}

function CopyBtn({ text }) {
    const [copied, setCopied] = useState(false);
    function copy() {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }
    return (
        <button onClick={copy} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white transition-all text-[12px]">
            {copied ? <Check size={13} className="text-emerald-400" /> : <Copy size={13} />}
            {copied ? 'Copiado!' : 'Copiar'}
        </button>
    );
}

function ConfigurarForm({ impl, checklist, onClose }) {
    const tutoriaisComSuporte = checklist.filter(i => i.tem_tutorial);
    const dados      = impl.dados ?? {};
    const tutoriais  = dados.tutoriais ?? {};
    const linksAdmin = dados.links_admin ?? {};

    const cfg = dados.itens?.precificacao ?? {};
    const [form, setForm] = useState({
        tutorial_intro: dados.tutorial_intro ?? '',
        prazo_data:     dados.prazo_data     ?? '',
        tutoriais:   Object.fromEntries(tutoriaisComSuporte.map(i => [i.id, tutoriais[i.id] ?? ''])),
        links_admin: {
            gmail_colaborador: linksAdmin.gmail_colaborador ?? '',
            drive_imagens:     linksAdmin.drive_imagens     ?? '',
            programa_decola:   linksAdmin.programa_decola   ?? '',
        },
        precificacao_config: {
            classico: {
                comissao: ((cfg.classico?.comissao ?? 0.115) * 100).toFixed(2),
                imposto:  ((cfg.classico?.imposto  ?? 0.19)  * 100).toFixed(2),
                margem:   ((cfg.classico?.margem   ?? 0.32)  * 100).toFixed(2),
            },
            premium: {
                comissao: ((cfg.premium?.comissao ?? 0.165) * 100).toFixed(2),
                imposto:  ((cfg.premium?.imposto  ?? 0.19)  * 100).toFixed(2),
                margem:   ((cfg.premium?.margem   ?? 0.35)  * 100).toFixed(2),
            },
        },
    });
    const [saving, setSaving] = useState(false);

    function submit(e) {
        e.preventDefault();
        setSaving(true);
        const payload = {
            ...form,
            precificacao_config: {
                classico: {
                    comissao: parseFloat(form.precificacao_config.classico.comissao) / 100,
                    imposto:  parseFloat(form.precificacao_config.classico.imposto)  / 100,
                    margem:   parseFloat(form.precificacao_config.classico.margem)   / 100,
                },
                premium: {
                    comissao: parseFloat(form.precificacao_config.premium.comissao) / 100,
                    imposto:  parseFloat(form.precificacao_config.premium.imposto)  / 100,
                    margem:   parseFloat(form.precificacao_config.premium.margem)   / 100,
                },
            },
        };
        router.post(route('mlb.implementacao.tutoriais', impl.impl_id), payload, {
            onFinish: () => { setSaving(false); onClose(); },
            preserveScroll: true,
        });
    }

    return (
        <form onSubmit={submit} className="space-y-5">
            {/* Links do admin */}
            <div>
                <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-3">Links configurados por vocês</p>
                {[
                    { id: 'gmail_colaborador', label: 'Gmail — Acesso Colaborador', type: 'email', placeholder: 'exemplo@gmail.com' },
                    { id: 'drive_imagens',     label: 'Link — Drive com Imagens',   type: 'url',   placeholder: 'https://drive.google.com/...' },
                    { id: 'programa_decola',   label: 'Link — Programa Decola',      type: 'url',   placeholder: 'https://...' },
                ].map(({ id, label, type, placeholder }) => (
                    <div key={id} className="mb-3">
                        <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">{label}</label>
                        <input
                            type={type}
                            value={form.links_admin[id] ?? ''}
                            onChange={e => setForm(f => ({ ...f, links_admin: { ...f.links_admin, [id]: e.target.value } }))}
                            placeholder={placeholder}
                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                        />
                    </div>
                ))}
            </div>

            <div className="h-px bg-white/[0.06]" />

            {/* Precificação */}
            <div>
                <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-3">Configuração de Precificação</p>
                {[
                    { tier: 'classico', label: 'Clássico' },
                    { tier: 'premium',  label: 'Premium'  },
                ].map(({ tier, label }) => (
                    <div key={tier} className="mb-4">
                        <p className="text-white/50 text-[11px] font-semibold mb-2">{label}</p>
                        <div className="grid grid-cols-2 gap-2">
                            {[
                                { id: 'comissao', label: 'Comissão %' },
                                { id: 'imposto',  label: 'Imposto %'  },
                                { id: 'margem',   label: 'Margem %'   },
                            ].map(({ id, label: lbl }) => (
                                <div key={id}>
                                    <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">{lbl}</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={form.precificacao_config[tier][id]}
                                        onChange={e => setForm(f => ({
                                            ...f,
                                            precificacao_config: {
                                                ...f.precificacao_config,
                                                [tier]: { ...f.precificacao_config[tier], [id]: e.target.value },
                                            },
                                        }))}
                                        className="w-full h-8 px-2 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40"
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            <div className="h-px bg-white/[0.06]" />

            {/* Prazo */}
            <div>
                <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-3">Prazo da implementação</p>
                <input
                    type="date"
                    value={form.prazo_data}
                    onChange={e => setForm(f => ({ ...f, prazo_data: e.target.value }))}
                    className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
                />
                <p className="text-white/25 text-[11px] mt-1.5">Exibido no aviso de prazo na página do cliente</p>
            </div>

            <div className="h-px bg-white/[0.06]" />

            {/* Tutoriais */}
            <div>
                <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-3">Tutoriais (YouTube)</p>
                <div className="mb-3">
                    <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">
                        Tutorial de Introdução (Como Preencher)
                    </label>
                    <input
                        type="url"
                        value={form.tutorial_intro}
                        onChange={e => setForm(f => ({ ...f, tutorial_intro: e.target.value }))}
                        placeholder="https://youtube.com/watch?v=..."
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                    />
                </div>
                {tutoriaisComSuporte.map(item => (
                    <div key={item.id} className="mb-3">
                        <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">
                            Tutorial — {item.titulo}
                        </label>
                        <input
                            type="url"
                            value={form.tutoriais[item.id] ?? ''}
                            onChange={e => setForm(f => ({ ...f, tutoriais: { ...f.tutoriais, [item.id]: e.target.value } }))}
                            placeholder="https://youtube.com/watch?v=..."
                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                        />
                    </div>
                ))}
            </div>

            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">
                    Cancelar
                </button>
                <button
                    type="submit"
                    disabled={saving}
                    className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                >
                    {saving ? 'Salvando...' : 'Salvar'}
                </button>
            </div>
        </form>
    );
}

const COLUNAS_PRODUTO = ['curva','sku','produto','altura','largura','profundidade','peso_kg','altura_emb','largura_emb','prof_emb','peso_emb_kg','estoque','especificacoes','descricao'];
const LABEL_COLUNA   = { curva:'Curva', sku:'SKU', produto:'Produto', altura:'Altura', largura:'Largura', profundidade:'Profundidade', peso_kg:'Peso KG', altura_emb:'Alt. Emb.', largura_emb:'Larg. Emb.', prof_emb:'Prof. Emb.', peso_emb_kg:'Peso Emb. KG', estoque:'Estoque', especificacoes:'Espec. Técnicas', descricao:'Descrição' };

function DadosView({ impl, checklist, erp_opcoes, integrador_opcoes }) {
    const itens      = impl.dados?.itens      ?? {};
    const linksAdmin = impl.dados?.links_admin ?? {};
    const [expandProd, setExpandProd] = useState(false);

    function detalhe(item, dado) {
        const { tipo, id } = item;
        if (tipo === 'link' || tipo === 'app_ecf') {
            if (!dado.link) return <span className="text-white/20 text-[11px]">Não preenchido</span>;
            return <a href={dado.link} target="_blank" rel="noreferrer" className="text-ecf-yellow text-[11px] flex items-center gap-1 hover:underline truncate max-w-[300px]"><ExternalLink size={10}/>{dado.link}</a>;
        }
        if (tipo === 'gmail') {
            return <span className="text-white/50 text-[11px] font-mono">{dado.gmail || '—'}</span>;
        }
        if (tipo === 'select') {
            const v = dado.valor === 'Outro' ? `Outro: ${dado.outro ?? ''}` : (dado.valor ?? '—');
            return <span className="text-white/50 text-[11px]">{v}{id === 'erp' && dado.acesso ? <span className="ml-2 text-white/30">· {dado.acesso}</span> : null}</span>;
        }
        if (tipo === 'select_opcoes') {
            return <span className="text-white/50 text-[11px]">{dado.valor || '—'}</span>;
        }
        if (tipo === 'texto') {
            return <span className="text-white/50 text-[11px]">{dado.acesso || '—'}</span>;
        }
        if (tipo === 'link_admin') {
            const url = linksAdmin[id];
            return url ? <a href={url} target="_blank" rel="noreferrer" className="text-ecf-yellow text-[11px] flex items-center gap-1 hover:underline truncate max-w-[300px]"><ExternalLink size={10}/>{url}</a>
                       : <span className="text-white/20 text-[11px]">Link não configurado</span>;
        }
        if (tipo === 'produtos') {
            const prods = dado.produtos ?? [];
            if (prods.length === 0) return <span className="text-white/20 text-[11px]">Nenhum produto cadastrado</span>;
            return (
                <div className="w-full">
                    <button onClick={() => setExpandProd(v => !v)} className="text-ecf-yellow text-[11px] font-medium hover:underline">
                        {prods.length} produto{prods.length !== 1 ? 's' : ''} {expandProd ? '▲ ocultar' : '▼ ver'}
                    </button>
                    {expandProd && (
                        <div className="mt-2 overflow-x-auto rounded-lg border border-white/[0.06]">
                            <table className="text-[11px]" style={{ minWidth: '700px' }}>
                                <thead>
                                    <tr className="border-b border-white/[0.06] bg-white/[0.03]">
                                        {COLUNAS_PRODUTO.map(c => (
                                            <th key={c} className="text-left px-2 py-1.5 text-white/30 font-semibold uppercase tracking-wider whitespace-nowrap">{LABEL_COLUNA[c]}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {prods.map((p, i) => (
                                        <tr key={i} className="border-b border-white/[0.04] last:border-0">
                                            {COLUNAS_PRODUTO.map(c => (
                                                <td key={c} className="px-2 py-1.5 text-white/60 whitespace-nowrap max-w-[150px] truncate">{p[c] || '—'}</td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            );
        }
        return null;
    }

    return (
        <div className="space-y-2">
            {checklist.map((item) => {
                const dado = itens[item.id] ?? {};
                const feito = dado.feito ?? false;
                return (
                    <div key={item.id} className={cn(
                        'flex items-start gap-3 p-3 rounded-xl border',
                        feito ? 'border-emerald-500/20 bg-emerald-950/20' : 'border-white/[0.06] bg-white/[0.02]'
                    )}>
                        <div className={cn(
                            'w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5',
                            feito ? 'border-emerald-400 bg-emerald-400' : 'border-white/20'
                        )}>
                            {feito && <Check size={10} className="text-white" />}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-white/80 text-[13px] font-medium">{item.titulo}</p>
                            <div className="mt-1">{detalhe(item, dado)}</div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function PadroesModal({ padroes, checklist, polo_opcoes = [], onClose }) {
    const tutoriaisComSuporte = checklist.filter(i => i.tem_tutorial);
    const [form, setForm] = useState({
        tutorial_intro: padroes.tutorial_intro ?? '',
        tutoriais:           Object.fromEntries(tutoriaisComSuporte.map(i => [i.id, padroes.tutoriais?.[i.id] ?? ''])),
        links_admin_extra: {
            app_ecf:         padroes.links_admin_extra?.app_ecf ?? '',
            programa_decola: padroes.links_admin_extra?.programa_decola ?? '',
            tabela_frete:    padroes.links_admin_extra?.tabela_frete ?? '',
        },
        // Mensagem de boas-vindas padrão (placeholders substituídos por empresa ao copiar)
        mensagem_boas_vindas: padroes.mensagem_boas_vindas ?? '',
        // Grant por polo: cada região tem url + nome do projeto (resolvido pelo polo da empresa)
        grants_por_polo: Object.fromEntries(
            polo_opcoes.map(p => [p, {
                url:  padroes.grants_por_polo?.[p]?.url  ?? '',
                nome: padroes.grants_por_polo?.[p]?.nome ?? '',
            }])
        ),
    });
    const [saving, setSaving] = useState(false);

    function submit(e) {
        e.preventDefault();
        setSaving(true);
        router.post(route('mlb.implementacao.padroes'), form, {
            onFinish: () => { setSaving(false); onClose(); },
            preserveScroll: true,
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[90vh] flex flex-col">
                <div className="flex items-center justify-between p-5 border-b border-white/[0.06] shrink-0">
                    <div>
                        <h2 className="text-white font-bold text-base">Padrões Globais</h2>
                        <p className="text-white/40 text-[12px] mt-0.5">Aplicados automaticamente em toda nova implementação</p>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition-colors"><X size={16} /></button>
                </div>
                <form onSubmit={submit} className="flex-1 overflow-y-auto p-5 space-y-5">
                    {/* Links fixos */}
                    <div>
                        <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-3">Links padrão</p>
                        {[
                            { id: 'app_ecf',         label: 'Link — App ECF',         key: 'links_admin_extra' },
                            { id: 'programa_decola', label: 'Link — Programa Decola', key: 'links_admin_extra' },
                            { id: 'tabela_frete',    label: 'Link — Tabela de Frete', key: 'links_admin_extra' },
                        ].map(({ id, label }) => (
                            <div key={id} className="mb-3">
                                <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">{label}</label>
                                <input
                                    type="url"
                                    value={form.links_admin_extra[id] ?? ''}
                                    onChange={e => setForm(f => ({ ...f, links_admin_extra: { ...f.links_admin_extra, [id]: e.target.value } }))}
                                    placeholder="https://..."
                                    className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                                />
                            </div>
                        ))}
                    </div>
                    <div className="h-px bg-white/[0.06]" />
                    {/* Grants por Polo — cada região tem seu link de Grant do Mercado Livre */}
                    <div>
                        <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-1">Grants por Polo</p>
                        <p className="text-white/25 text-[11px] mb-3">Cada região tem seu Grant. A empresa recebe o Grant do polo em que está cadastrada.</p>
                        {polo_opcoes.length === 0 && (
                            <p className="text-white/20 text-[12px]">Nenhum polo disponível.</p>
                        )}
                        {polo_opcoes.map(polo => (
                            <div key={polo} className="mb-4 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                <p className="text-white/60 text-[12px] font-semibold mb-2">{polo}</p>
                                <div className="space-y-2">
                                    <div>
                                        <label className="text-white/40 text-[10px] uppercase tracking-wider block mb-1">Nome do projeto (Grant)</label>
                                        <input
                                            type="text"
                                            value={form.grants_por_polo[polo]?.nome ?? ''}
                                            onChange={e => setForm(f => ({ ...f, grants_por_polo: { ...f.grants_por_polo, [polo]: { ...f.grants_por_polo[polo], nome: e.target.value } } }))}
                                            placeholder="Ex: Projeto Polos - Serra Gaúcha"
                                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                                        />
                                    </div>
                                    <div>
                                        <label className="text-white/40 text-[10px] uppercase tracking-wider block mb-1">Link do Grant</label>
                                        <input
                                            type="url"
                                            value={form.grants_por_polo[polo]?.url ?? ''}
                                            onChange={e => setForm(f => ({ ...f, grants_por_polo: { ...f.grants_por_polo, [polo]: { ...f.grants_por_polo[polo], url: e.target.value } } }))}
                                            placeholder="https://partners.mercadolivre.com.br/auth/..."
                                            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                                        />
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="h-px bg-white/[0.06]" />
                    {/* Mensagem de boas-vindas padrão */}
                    <div>
                        <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-1">Mensagem de Boas-vindas</p>
                        <p className="text-white/25 text-[11px] mb-3">
                            Texto padrão enviado ao cliente. Use os marcadores:{' '}
                            <code className="text-ecf-yellow/80">{'{link_formulario}'}</code>,{' '}
                            <code className="text-ecf-yellow/80">{'{link_grant}'}</code>,{' '}
                            <code className="text-ecf-yellow/80">{'{projeto_grant}'}</code>,{' '}
                            <code className="text-ecf-yellow/80">{'{link_oauth}'}</code>,{' '}
                            <code className="text-ecf-yellow/80">{'{empresa}'}</code> — substituídos automaticamente por empresa ao copiar.
                        </p>
                        <p className="text-white/25 text-[11px] mb-3">
                            <code className="text-ecf-yellow/80">{'{link_oauth}'}</code> é a autorização do
                            sistema ECF <strong>por empresa</strong> — não expira, identifica quem autorizou e
                            preenche o Cust ID sozinho. Não substitui o <code className="text-ecf-yellow/80">{'{link_grant}'}</code>,
                            que é o programa de Partners do Mercado Livre (um por polo).
                        </p>
                        <textarea
                            value={form.mensagem_boas_vindas}
                            onChange={e => setForm(f => ({ ...f, mensagem_boas_vindas: e.target.value }))}
                            rows={12}
                            placeholder="Olá, seja muito bem-vindo(a) ao Projeto Polos! 🚀..."
                            className="w-full px-3 py-2 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] leading-relaxed focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 resize-y"
                        />
                    </div>
                    <div className="h-px bg-white/[0.06]" />
                    {/* Tutoriais */}
                    <div>
                        <p className="text-white/30 text-[10px] font-bold uppercase tracking-widest mb-3">Tutoriais padrão (YouTube)</p>
                        <div className="mb-3">
                            <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Tutorial de Introdução</label>
                            <input
                                type="url"
                                value={form.tutorial_intro}
                                onChange={e => setForm(f => ({ ...f, tutorial_intro: e.target.value }))}
                                placeholder="https://youtube.com/watch?v=..."
                                className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                            />
                        </div>
                        {tutoriaisComSuporte.map(item => (
                            <div key={item.id} className="mb-3">
                                <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Tutorial — {item.titulo}</label>
                                <input
                                    type="url"
                                    value={form.tutoriais[item.id] ?? ''}
                                    onChange={e => setForm(f => ({ ...f, tutoriais: { ...f.tutoriais, [item.id]: e.target.value } }))}
                                    placeholder="https://youtube.com/watch?v=..."
                                    className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                                />
                            </div>
                        ))}
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">Cancelar</button>
                        <button type="submit" disabled={saving} className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50">
                            {saving ? 'Salvando...' : 'Salvar Padrões'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function SyncSkusBtn({ implId }) {
    const [syncing, setSyncing] = useState(false);
    function sync() {
        setSyncing(true);
        router.post(route('mlb.implementacao.sincronizar-skus', implId), {}, {
            onFinish: () => setSyncing(false),
            preserveScroll: true,
        });
    }
    return (
        <button
            onClick={sync}
            disabled={syncing}
            className="flex items-center gap-2 px-3 py-2 rounded-xl border border-violet-500/20 bg-violet-500/10 text-violet-300 hover:bg-violet-500/20 text-[12px] font-medium transition-all disabled:opacity-40 w-full justify-center"
        >
            {syncing ? <RefreshCw size={13} className="animate-spin" /> : <ArrowRightLeft size={13} />}
            {syncing ? 'Sincronizando...' : 'Sincronizar SKUs para os Estágios da Empresa'}
        </button>
    );
}

function NovaImplModal({ onClose }) {
    const [nome, setNome] = useState('');
    const [saving, setSaving] = useState(false);
    const [aviso, setAviso] = useState(null); // mensagem de confirmação quando empresa já existe

    function submit(e) {
        e.preventDefault();
        if (!nome.trim()) return;
        setSaving(true);
        router.post(route('mlb.implementacao.criar'), { nome }, {
            onError: (errors) => {
                setSaving(false);
                if (errors.empresa_existente) setAviso(errors.empresa_existente);
            },
            onSuccess: () => { setSaving(false); onClose(); },
            preserveScroll: true,
        });
    }

    function confirmar() {
        setSaving(true);
        router.post(route('mlb.implementacao.criar'), { nome, confirmar: true }, {
            onFinish: () => { setSaving(false); onClose(); },
            preserveScroll: true,
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-5">
                    <div>
                        <h2 className="text-white font-bold text-base">Novo Onboarding</h2>
                        <p className="text-white/40 text-[12px] mt-0.5">
                            {aviso ? 'Empresa já cadastrada' : 'Empresa Polos · será criada automaticamente'}
                        </p>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>

                {aviso ? (
                    <div className="space-y-4">
                        <p className="text-amber-300 text-[13px] leading-relaxed bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2.5">
                            {aviso}
                        </p>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setAviso(null)} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">
                                Voltar
                            </button>
                            <button
                                onClick={confirmar}
                                disabled={saving}
                                className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                            >
                                {saving ? 'Vinculando...' : 'Sim, vincular'}
                            </button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="text-white/50 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Nome da Empresa</label>
                            <input
                                type="text"
                                value={nome}
                                onChange={e => setNome(e.target.value)}
                                placeholder="Ex: Casa A"
                                autoFocus
                                className="w-full h-10 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                            />
                        </div>
                        <div className="flex justify-end gap-2 pt-1">
                            <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={saving || !nome.trim()}
                                className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                            >
                                {saving ? 'Verificando...' : 'Criar e Gerar Link'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}

function ImplModal({ empresa, checklist, erp_opcoes, integrador_opcoes, global_padroes = {}, onClose }) {
    const [tab, setTab] = useState('link');
    const [confirmRemover, setConfirmRemover] = useState(false);
    const [removendo, setRemovendo] = useState(false);
    const url           = route('implementacao.workspace',  empresa.token);
    const urlPublicador = route('implementacao.publicador', empresa.token);

    // ─── Mensagem de boas-vindas (template global + dados da empresa) ───────────
    // Resolve o Grant pelo polo cadastrado da empresa (sem texto livre → sem divergência).
    const grantPolo    = empresa.polo ? (global_padroes.grants_por_polo?.[empresa.polo] ?? null) : null;
    const linkGrant    = grantPolo?.url ?? '';
    const projetoGrant = grantPolo?.nome ?? (empresa.polo ? `Projeto Polos - ${empresa.polo}` : '');
    // Autorização do app da ECF, por EMPRESA — diferente do Grant, que é um por
    // polo e não diz quem autorizou. Não expira: quem tem validade é a URL do ML,
    // gerada só quando o cliente clica nesta rota.
    const linkOauth    = route('implementacao.conectar-ml', empresa.token);
    const template     = global_padroes.mensagem_boas_vindas ?? '';
    // split/join evita depender de String.prototype.replaceAll e troca todas as ocorrências
    const aplicar = (txt, alvo, valor) => txt.split(alvo).join(valor ?? '');
    const mensagem = aplicar(aplicar(aplicar(aplicar(aplicar(
        template,
        '{link_formulario}', url),
        '{link_grant}',      linkGrant),
        '{projeto_grant}',   projetoGrant),
        '{link_oauth}',      linkOauth),
        '{empresa}',         empresa.nome ?? '');
    // Aviso quando faltam dados para preencher o Grant na mensagem
    const avisoGrant = !empresa.polo
        ? 'Empresa sem polo definido — o link do Grant não será preenchido. Defina o polo na ficha.'
        : (!linkGrant ? `Nenhum Grant configurado para o polo "${empresa.polo}". Configure em Padrões.` : null);

    function remover() {
        setRemovendo(true);
        router.delete(route('mlb.implementacao.destroy', empresa.impl_id), {
            onFinish: () => { setRemovendo(false); onClose(); },
            preserveScroll: true,
        });
    }


    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[90vh] flex flex-col">
                {/* Header */}
                <div className="flex items-center justify-between p-5 border-b border-white/[0.06] shrink-0">
                    <div>
                        <h2 className="text-white font-bold text-base">{empresa.nome}</h2>
                        <p className="text-white/40 text-[12px] mt-0.5">Onboarding</p>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>

                {/* Tabs */}
                <div className="flex border-b border-white/[0.06] shrink-0">
                    {[
                        { id: 'link',      label: 'Link & Status' },
                        { id: 'tutoriais', label: 'Configurar' },
                        { id: 'dados',     label: 'Dados Cliente' },
                    ].map(t => (
                        <button
                            key={t.id}
                            onClick={() => setTab(t.id)}
                            className={cn(
                                'px-4 py-3 text-[13px] font-medium border-b-2 transition-colors',
                                tab === t.id
                                    ? 'border-ecf-yellow text-ecf-yellow'
                                    : 'border-transparent text-white/40 hover:text-white/70'
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto p-5">
                    {tab === 'link' && (
                        <div className="space-y-4">
                            {empresa.progresso && (
                                <div className="p-4 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                                    <p className="text-white/40 text-[11px] font-medium uppercase tracking-wider mb-2">Progresso</p>
                                    <ProgressBar pct={empresa.progresso.pct} feitos={empresa.progresso.feitos} total={empresa.progresso.total} />
                                </div>
                            )}
                            {/* Status do envio do link (ONB-ENVIO-LINK) */}
                            {empresa.status_envio && (
                                <div className="p-4 rounded-xl bg-white/[0.03] border border-white/[0.06] space-y-3">
                                    <p className="text-white/40 text-[11px] font-medium uppercase tracking-wider">Status do Envio</p>
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border', STATUS_ENVIO_BADGE[empresa.status_envio])}>
                                                {STATUS_ENVIO_LABELS[empresa.status_envio]}
                                            </span>
                                            {empresa.link_enviado_em && (
                                                <p className="text-white/30 text-[11px] mt-1.5">
                                                    por {empresa.link_enviado_por ?? '—'} em {empresa.link_enviado_em}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            {empresa.link_enviado_em == null && empresa.status_envio !== 'concluido' && (
                                                <button
                                                    onClick={() => router.post(route('mlb.implementacao.marcar-enviado', empresa.impl_id), {}, { preserveScroll: true })}
                                                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white text-[12px] transition-all"
                                                >
                                                    Marcar enviado
                                                </button>
                                            )}
                                            {empresa.link_enviado_em != null && (
                                                <button
                                                    onClick={() => router.post(route('mlb.implementacao.desfazer-envio', empresa.impl_id), {}, { preserveScroll: true })}
                                                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white text-[12px] transition-all"
                                                >
                                                    Desfazer envio
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                            {empresa.ultimo_acesso && (
                                <p className="text-white/30 text-[12px]">Último acesso do cliente: <span className="text-white/60">{empresa.ultimo_acesso}</span></p>
                            )}
                            <div className="p-4 rounded-xl bg-white/[0.03] border border-white/[0.06] space-y-3">
                                <p className="text-white/40 text-[11px] font-medium uppercase tracking-wider">Link do Cliente</p>
                                <div className="flex items-center gap-2 p-2.5 rounded-lg bg-black/30 border border-white/[0.06]">
                                    <Link2 size={13} className="text-white/30 shrink-0" />
                                    <span className="text-white/60 text-[12px] flex-1 truncate">{url}</span>
                                </div>
                                <div className="flex gap-2">
                                    <CopyBtn text={url} />
                                    <a href={url} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white transition-all text-[12px]">
                                        <ExternalLink size={13} />
                                        Abrir
                                    </a>
                                </div>
                            </div>

                            {/* Mensagem de boas-vindas pronta para enviar (link do formulário + Grant do polo já preenchidos) */}
                            {template ? (
                                <div className="p-4 rounded-xl bg-white/[0.03] border border-white/[0.06] space-y-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-white/40 text-[11px] font-medium uppercase tracking-wider">Mensagem de Boas-vindas</p>
                                        <CopyBtn text={mensagem} />
                                    </div>
                                    {avisoGrant && (
                                        <p className="text-amber-300 text-[11px] bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                                            {avisoGrant}
                                        </p>
                                    )}
                                    <pre className="text-white/60 text-[12px] leading-relaxed whitespace-pre-wrap font-sans bg-black/30 border border-white/[0.06] rounded-lg p-3 max-h-56 overflow-y-auto">{mensagem}</pre>
                                </div>
                            ) : (
                                <div className="p-4 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                                    <p className="text-white/40 text-[11px] font-medium uppercase tracking-wider mb-1">Mensagem de Boas-vindas</p>
                                    <p className="text-white/30 text-[12px]">Nenhum modelo configurado. Defina o texto em <span className="text-white/50">Padrões</span>.</p>
                                </div>
                            )}

                            <div className="p-4 rounded-xl bg-violet-500/[0.05] border border-violet-500/20 space-y-3">
                                <p className="text-violet-300/70 text-[11px] font-medium uppercase tracking-wider">Link do Publicador</p>
                                <div className="flex items-center gap-2 p-2.5 rounded-lg bg-black/30 border border-white/[0.06]">
                                    <Link2 size={13} className="text-violet-300/40 shrink-0" />
                                    <span className="text-white/60 text-[12px] flex-1 truncate">{urlPublicador}</span>
                                </div>
                                <div className="flex gap-2">
                                    <CopyBtn text={urlPublicador} />
                                    <a href={urlPublicador} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white transition-all text-[12px]">
                                        <ExternalLink size={13} />
                                        Abrir
                                    </a>
                                </div>
                            </div>

                            {/* Remover implementação */}
                            <div className="pt-2 border-t border-white/[0.06]">
                                {!confirmRemover ? (
                                    <button
                                        onClick={() => setConfirmRemover(true)}
                                        className="text-red-400/60 hover:text-red-400 text-[12px] transition-colors"
                                    >
                                        Remover implementação desta empresa
                                    </button>
                                ) : (
                                    <div className="flex items-center gap-3">
                                        <p className="text-red-400 text-[12px] flex-1">Remover? A empresa continua em Empresas.</p>
                                        <button onClick={() => setConfirmRemover(false)} className="text-white/40 hover:text-white text-[12px] transition-colors">Cancelar</button>
                                        <button
                                            onClick={remover}
                                            disabled={removendo}
                                            className="px-3 py-1 rounded-lg bg-red-500/20 text-red-400 hover:bg-red-500/30 text-[12px] font-medium transition-all disabled:opacity-40"
                                        >
                                            {removendo ? 'Removendo...' : 'Confirmar'}
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {tab === 'tutoriais' && (
                        <ConfigurarForm impl={empresa} checklist={checklist} onClose={onClose} />
                    )}

                    {tab === 'dados' && (
                        <div className="space-y-4">
                            <SyncSkusBtn implId={empresa.impl_id} />
                            <DadosView impl={empresa} checklist={checklist} erp_opcoes={erp_opcoes} integrador_opcoes={integrador_opcoes} />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function Implementacao({ empresas, checklist, erp_opcoes, integrador_opcoes, global_padroes, filtros, polo_opcoes, fase_opcoes, usuarios = [] }) {
    const [modal, setModal]         = useState(null);
    const [busca, setBusca]         = useState('');
    const [novaImpl, setNovaImpl]   = useState(false);
    const [padroes, setPadroes]     = useState(false);

    // Filtros de Polo e Fase (alimentados pelo backend)
    const poloOpts = polo_opcoes ?? [];
    const faseOpts = fase_opcoes ?? [];

    function aplicarFiltro(campo, valor) {
        // Preserva todos os filtros ativos no spread — trocar qualquer filtro não apaga os outros
        const params = {
            polo:          filtros?.polo ?? '',
            fase:          filtros?.fase ?? '',
            fora_do_prazo: filtros?.fora_do_prazo ? '1' : '',
            falta_enviar:  filtros?.falta_enviar  ? '1' : '',
            [campo]: valor === '__todos__' ? '' : valor,
        };
        router.get(route('mlb.implementacao.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    // Contador de empresas que ainda faltam ter o link enviado
    const faltamEnviar = empresas.filter(e => e.status_envio === 'falta_enviar').length;

    // Salva SÓ o cust_id (endpoint dedicado que não zera os demais campos da empresa).
    const salvarCustId = (e, valor) =>
        router.patch(route('mlb.empresas.cust-id', e.id), { cust_id: String(valor ?? '').trim() }, {
            preserveScroll: true,
            preserveState: true,
        });

    // Renomear a empresa (mesmo motivo do cust_id: endpoint de UM campo).
    const salvarNome = (e, valor) =>
        router.patch(route('mlb.empresas.nome', e.id), { nome: String(valor ?? '').trim() }, {
            preserveScroll: true,
            preserveState: true,
        });

    // Busca local (complementar aos filtros de Polo/Fase do backend)
    // Aceita nome OU Cust ID no mesmo campo - o Cust ID casa por trecho, colar o id inteiro tambem funciona
    const termoBusca = busca.trim().toLowerCase();
    const filtradas = !termoBusca
        ? empresas
        : empresas.filter(e =>
            (e.nome || '').toLowerCase().includes(termoBusca)
            || String(e.cust_id ?? '').toLowerCase().includes(termoBusca)
        );

    return (
        <AppLayout title="Onboarding">
            <div className="max-w-7xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-white font-display font-bold text-xl">Onboarding</h1>
                            {faltamEnviar > 0 && (
                                <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full text-red-300 bg-red-500/10 border border-red-500/20 whitespace-nowrap">
                                    {faltamEnviar} pendente{faltamEnviar !== 1 ? 's' : ''} de envio
                                </span>
                            )}
                        </div>
                        <p className="text-white/40 text-[13px] mt-0.5">Crie onboardings para novos clientes Polos e acompanhe o preenchimento</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <input
                            type="text"
                            value={busca}
                            onChange={e => setBusca(e.target.value)}
                            placeholder="Buscar empresa ou Cust ID..."
                            className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 w-56"
                        />
                        <Link
                            href={route('mlb.implementacao.indicadores')}
                            className="flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] text-white/40 hover:text-white hover:border-white/20 text-[13px] transition-all"
                        >
                            <BarChart2 size={15} />
                            Indicadores
                        </Link>
                        <button
                            onClick={() => setPadroes(true)}
                            title="Configurar padrões globais"
                            className="flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] text-white/40 hover:text-white hover:border-white/20 text-[13px] transition-all"
                        >
                            <Settings2 size={15} />
                            Padrões
                        </button>
                        <button
                            onClick={() => setNovaImpl(true)}
                            className="flex items-center gap-2 h-9 px-4 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all whitespace-nowrap"
                        >
                            <Plus size={15} />
                            Novo Onboarding
                        </button>
                    </div>
                </div>

                {/* Barra de filtros Polo / Fase */}
                {(poloOpts.length > 0 || faseOpts.length > 0) && (
                    <div className="flex items-center gap-3 flex-wrap">
                        <span className="text-white/30 text-[12px]">Filtrar:</span>
                        {poloOpts.length > 0 && (
                            <Select
                                value={filtros?.polo || '__todos__'}
                                onValueChange={v => aplicarFiltro('polo', v)}
                            >
                                <SelectTrigger className="h-9 w-44 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                    <SelectValue placeholder="Todos os Polos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__todos__">Todos os Polos</SelectItem>
                                    {poloOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        )}
                        {faseOpts.length > 0 && (
                            <Select
                                value={filtros?.fase || '__todos__'}
                                onValueChange={v => aplicarFiltro('fase', v)}
                            >
                                <SelectTrigger className="h-9 w-44 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                    <SelectValue placeholder="Todas as fases" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__todos__">Todas as fases</SelectItem>
                                    {faseOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        )}
                        {/* Toggle "Fora do prazo" — button simples (não Radix Select) para evitar bug value="" */}
                        <button
                            onClick={() => aplicarFiltro('fora_do_prazo', filtros?.fora_do_prazo ? '' : '1')}
                            className={cn(
                                'flex items-center gap-1.5 h-9 px-3 rounded-xl border text-[13px] transition-all',
                                filtros?.fora_do_prazo
                                    ? 'border-red-500/40 bg-red-500/10 text-red-300'
                                    : 'border-white/[0.08] bg-white/[0.03] text-white/40 hover:text-white/70 hover:border-white/20'
                            )}
                        >
                            Fora do prazo
                        </button>

                        {/* Toggle "Pendente de envio" — mesmo padrão do botão "Fora do prazo" */}
                        <button
                            onClick={() => aplicarFiltro('falta_enviar', filtros?.falta_enviar ? '' : '1')}
                            className={cn(
                                'flex items-center gap-1.5 h-9 px-3 rounded-xl border text-[13px] transition-all',
                                filtros?.falta_enviar
                                    ? 'border-red-500/40 bg-red-500/10 text-red-300'
                                    : 'border-white/[0.08] bg-white/[0.03] text-white/40 hover:text-white/70 hover:border-white/20'
                            )}
                        >
                            Pendente de envio
                        </button>

                        {/* Limpar filtros — aparece quando qualquer filtro está ativo */}
                        {(filtros?.polo || filtros?.fase || filtros?.fora_do_prazo || filtros?.falta_enviar) && (
                            <button
                                onClick={() => router.get(route('mlb.implementacao.index'), {}, { replace: true })}
                                className="text-white/30 hover:text-white text-[12px] transition-colors"
                            >
                                Limpar filtros
                            </button>
                        )}
                    </div>
                )}

                {/* Tabela — rola horizontalmente em telas estreitas em vez de espremer as colunas */}
                <div className="card-ecf rounded-2xl overflow-x-auto">
                    <table className="w-full min-w-[860px]">
                        <thead>
                            <tr className="border-b border-white/[0.06]">
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider">Empresa</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden lg:table-cell">Polos</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden lg:table-cell">Fase</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden sm:table-cell">Estágio</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden md:table-cell">Status do envio</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden lg:table-cell">Responsável</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider">Progresso</th>
                                <th className="text-left px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider hidden md:table-cell">Último Acesso</th>
                                <th className="text-right px-4 py-3 text-white/30 text-[11px] font-semibold uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtradas.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="px-4 py-12 text-center">
                                        {busca || filtros?.polo || filtros?.fase || filtros?.fora_do_prazo || filtros?.falta_enviar ? (
                                            <div className="space-y-1">
                                                <p className="text-white/40 text-[13px] font-semibold">Nenhum resultado</p>
                                                <p className="text-white/20 text-[12px]">Nenhuma empresa corresponde aos filtros selecionados. Limpe os filtros para ver todas.</p>
                                            </div>
                                        ) : (
                                            <div className="space-y-1">
                                                <p className="text-white/40 text-[13px] font-semibold">Nenhuma empresa Polos</p>
                                                <p className="text-white/20 text-[12px]">Nenhuma empresa Polos possui ficha de onboarding ainda. A criação de fichas será habilitada na Frente 4.</p>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            )}
                            {filtradas.map((empresa, idx) => (
                                <tr
                                    key={empresa.id}
                                    className={cn('border-b border-white/[0.04] transition-colors hover:bg-white/[0.02] [&>td]:align-top', idx === filtradas.length - 1 && 'border-b-0')}
                                >
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            {/* Nome editável inline: lápis no hover, Enter salva, Esc cancela */}
                                            <NomeEmpresaCell e={empresa} onSalvar={salvarNome} className="text-white text-[13px] font-medium" />
                                            {/* Cust ID inline: copia (chip), cadastra ("+") e corrige (lápis) sem sair da tela */}
                                            <CustIdCell e={empresa} onSalvar={salvarCustId} />
                                            {/* Badge "Fora do prazo" — apenas exibe prop calculada no backend (plano 02) */}
                                            {empresa.fora_do_prazo && (
                                                <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full text-red-300 bg-red-500/10 border border-red-500/20 whitespace-nowrap">
                                                    Fora do prazo
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 hidden lg:table-cell">
                                        <span className="text-white/50 text-[12px]">{empresa.polo ?? '—'}</span>
                                    </td>
                                    <td className="px-4 py-3 hidden lg:table-cell">
                                        <span className="text-white/50 text-[12px]">{empresa.fase ?? '—'}</span>
                                    </td>
                                    <td className="px-4 py-3 hidden sm:table-cell">
                                        <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full', ESTAGIO_COLORS[empresa.estagio] ?? 'text-white/30 bg-white/[0.04]')}>
                                            {empresa.estagio ?? '—'}
                                        </span>
                                    </td>
                                    {/* Coluna Status do envio (ONB-ENVIO-LINK) — badge + ação de envio consolidada */}
                                    <td className="px-4 py-3 hidden md:table-cell">
                                        {empresa.status_envio ? (
                                            <div className="flex flex-col items-start gap-1">
                                                <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border whitespace-nowrap', STATUS_ENVIO_BADGE[empresa.status_envio])}>
                                                    {STATUS_ENVIO_LABELS[empresa.status_envio]}
                                                </span>
                                                {empresa.link_enviado_em ? (
                                                    <p className="text-white/30 text-[10px] leading-tight">
                                                        por {empresa.link_enviado_por ?? '—'} em {empresa.link_enviado_em}
                                                        <button
                                                            onClick={() => router.post(route('mlb.implementacao.desfazer-envio', empresa.impl_id), {}, { preserveScroll: true })}
                                                            className="ml-2 text-white/30 hover:text-white/60 transition-colors"
                                                        >
                                                            Desfazer
                                                        </button>
                                                    </p>
                                                ) : (
                                                    empresa.impl_id && empresa.status_envio !== 'concluido' && (
                                                        <button
                                                            onClick={() => router.post(route('mlb.implementacao.marcar-enviado', empresa.impl_id), {}, { preserveScroll: true })}
                                                            className="text-emerald-300/70 hover:text-emerald-300 text-[10px] transition-colors whitespace-nowrap"
                                                        >
                                                            Marcar enviado
                                                        </button>
                                                    )
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-white/20 text-[12px]">—</span>
                                        )}
                                    </td>
                                    {/* Coluna Responsável (ONB-RESPONSAVEL) */}
                                    <td className="px-4 py-3 hidden lg:table-cell">
                                        <Select
                                            value={empresa.responsavel_id ? String(empresa.responsavel_id) : '__sem__'}
                                            onValueChange={v => router.patch(
                                                route('mlb.implementacao.responsavel', empresa.impl_id),
                                                { responsavel_id: v === '__sem__' ? null : Number(v) },
                                                { preserveScroll: true, preserveState: true }
                                            )}
                                        >
                                            <SelectTrigger className="h-8 w-36 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px]">
                                                <SelectValue placeholder="Sem responsável" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {/* CRÍTICO: nunca <SelectItem value=""> — sentinela '__sem__' → null (memória do projeto) */}
                                                <SelectItem value="__sem__">Sem responsável</SelectItem>
                                                {usuarios.map(u => (
                                                    <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </td>
                                    <td className="px-4 py-3">
                                        {empresa.progresso ? (
                                            <ProgressBar pct={empresa.progresso.pct} feitos={empresa.progresso.feitos} total={empresa.progresso.total} />
                                        ) : (
                                            <span className="text-white/20 text-[12px]">Sem link</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 hidden md:table-cell">
                                        <span className="text-white/40 text-[12px]">{empresa.ultimo_acesso ?? '—'}</span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            {/* Link para a ficha de Onboarding (ONB-01). Ações de envio vivem na coluna Status do envio. */}
                                            {empresa.impl_id && (
                                                <Link
                                                    href={route('mlb.implementacao.ficha', empresa.impl_id)}
                                                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white text-[12px] transition-all whitespace-nowrap"
                                                >
                                                    <FileText size={12} />
                                                    Abrir ficha
                                                </Link>
                                            )}
                                            <button
                                                onClick={() => setModal(empresa)}
                                                className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/50 hover:text-white text-[12px] transition-all whitespace-nowrap"
                                            >
                                                <Eye size={12} />
                                                Ver
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="text-white/20 text-[12px] text-center">{filtradas.length} empresa{filtradas.length !== 1 ? 's' : ''}</p>
            </div>

            {padroes && <PadroesModal padroes={global_padroes ?? {}} checklist={checklist} polo_opcoes={poloOpts} onClose={() => setPadroes(false)} />}

            {novaImpl && <NovaImplModal onClose={() => setNovaImpl(false)} />}

            {modal && (
                <ImplModal
                    empresa={modal}
                    checklist={checklist}
                    erp_opcoes={erp_opcoes}
                    integrador_opcoes={integrador_opcoes}
                    global_padroes={global_padroes ?? {}}
                    onClose={() => setModal(null)}
                />
            )}
        </AppLayout>
    );
}
