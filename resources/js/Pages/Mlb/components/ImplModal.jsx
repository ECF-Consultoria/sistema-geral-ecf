import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Link2, Copy, Check, ExternalLink, RefreshCw, X, ArrowRightLeft } from 'lucide-react';
import { cn } from '@/lib/utils';

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

export default ImplModal;
