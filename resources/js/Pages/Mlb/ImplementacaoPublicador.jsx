import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Copy, Check, RefreshCw } from 'lucide-react';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function calcPreco(custo, frete, comissao, imposto, margem) {
    const d = 1 - comissao - imposto - margem;
    if (d <= 0 || !custo || isNaN(parseFloat(custo))) return null;
    return (parseFloat(custo) + parseFloat(frete || 0)) / d;
}

function brl(n) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    return Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function pct(n) {
    if (n === null || n === undefined) return '—';
    return (Number(n) * 100).toFixed(1) + '%';
}

// ─── Merge produtos + precificação ───────────────────────────────────────────

function mergeProdutos(produtos, precif) {
    const cfg = {
        classico: { comissao: 0.115, imposto: 0.19, margem: 0.32, ...(precif.classico ?? {}) },
        premium:  { comissao: 0.165, imposto: 0.19, margem: 0.35, ...(precif.premium  ?? {}) },
    };
    const pricingMap = {};
    (precif.produtos ?? []).forEach((p, i) => { pricingMap[p.sku || `__idx_${i}`] = p; });

    return (produtos ?? []).filter(p => p.produto?.trim() || p.sku?.trim()).map((p, i) => {
        const key = p.sku?.trim() || `__idx_${i}`;
        const pr = pricingMap[key] ?? {};
        const fc = parseFloat(pr.frete_classico || 0);
        const fp = parseFloat(pr.frete_premium  || 0);
        const precoC = calcPreco(pr.custo, fc, cfg.classico.comissao, cfg.classico.imposto, cfg.classico.margem);
        const precoP = calcPreco(pr.custo, fp, cfg.premium.comissao,  cfg.premium.imposto,  cfg.premium.margem);
        return {
            sku:           p.sku,
            produto:       p.produto       || '—',
            curva:         p.curva         || '—',
            altura:        p.altura        || '—',
            largura:       p.largura       || '—',
            profundidade:  p.profundidade  || '—',
            peso_kg:       p.peso_kg       || '—',
            estoque:       p.estoque       || '—',
            especificacoes:p.especificacoes|| '—',
            descricao:     p.descricao     || '—',
            custo:         pr.custo        || null,
            frete_classico: fc,
            frete_premium:  fp,
            preco_classico:    precoC,
            preco_premium:     precoP,
            preco_anunciado_c: precoC ? precoC * 1.2 : null,
            preco_anunciado_p: precoP ? precoP * 1.2 : null,
            margem_c_r:        precoC ? precoC * cfg.classico.margem : null,
            margem_p_r:        precoP ? precoP * cfg.premium.margem  : null,
            cfg,
        };
    });
}

// ─── Componentes de seção ─────────────────────────────────────────────────────

function SectionCard({ title, children, accent }) {
    return (
        <div className={cn('rounded-2xl border overflow-hidden', accent ? 'border-violet-500/20' : 'border-white/[0.08]')}>
            <div className={cn('px-5 py-3 border-b', accent ? 'bg-violet-500/[0.06] border-violet-500/20' : 'bg-white/[0.03] border-white/[0.06]')}>
                <h2 className={cn('font-bold text-[14px]', accent ? 'text-violet-200' : 'text-white')}>{title}</h2>
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

function InfoRow({ label, value, link }) {
    if (!value || value === '—' || value === false) return null;
    return (
        <div className="flex items-start gap-3 py-2 border-b border-white/[0.04] last:border-0">
            <span className="text-white/40 text-[12px] w-44 shrink-0">{label}</span>
            {link ? (
                <a href={value} target="_blank" rel="noreferrer" className="text-ecf-yellow text-[12px] hover:underline truncate flex-1">{value}</a>
            ) : (
                <span className="text-white/80 text-[12px] flex-1">{value}</span>
            )}
        </div>
    );
}

function CopyCell({ value, maxW = 'max-w-[180px]', tdCls }) {
    const [copied, setCopied] = useState(false);
    if (!value || value === '—') return <td className={tdCls}><span className="text-white/20">—</span></td>;

    function copy(e) {
        e.stopPropagation();
        navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <td className={cn(tdCls, 'group')}>
            <div className="flex items-center gap-1.5">
                <span className={cn('truncate flex-1', maxW)} title={value}>{value}</span>
                <button
                    onClick={copy}
                    className="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded text-white/30 hover:text-white hover:bg-white/[0.08]"
                    title="Copiar"
                >
                    {copied ? <Check size={12} className="text-emerald-400" /> : <Copy size={12} />}
                </button>
            </div>
        </td>
    );
}

function BadgeFeito({ feito }) {
    return feito
        ? <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400">Feito</span>
        : <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-white/[0.05] text-white/30">Pendente</span>;
}

// ─── Página ───────────────────────────────────────────────────────────────────

export default function ImplementacaoPublicador({ impl, checklist }) {
    const [lastUpdate, setLastUpdate] = useState(new Date());

    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['impl'], onSuccess: () => setLastUpdate(new Date()) });
        }, 30000);
        return () => clearInterval(id);
    }, []);

    function refresh() {
        router.reload({ only: ['impl'], onSuccess: () => setLastUpdate(new Date()) });
    }

    const dados  = impl.dados ?? {};
    const itens  = dados.itens ?? {};
    const linksAdmin = dados.links_admin ?? {};

    const produtosBase  = itens.planilha_produtos?.produtos ?? [];
    const precif        = itens.precificacao ?? {};
    const produtos      = mergeProdutos(produtosBase, precif);

    const cfgC = precif.classico ?? { comissao: 0.115, imposto: 0.19, margem: 0.32 };
    const cfgP = precif.premium  ?? { comissao: 0.165, imposto: 0.19, margem: 0.35 };

    const erp   = itens.erp   ?? {};
    const integ = itens.integrador_logistico ?? {};
    const hub   = itens.hub   ?? {};
    const pmass = itens.publicar_em_massa ?? {};

    const thCls = "text-left px-3 py-2 text-white/30 font-semibold uppercase tracking-wider text-[10px] whitespace-nowrap";
    const tdCls = "px-3 py-2 text-white/70 text-[12px]";

    return (
        <div className="min-h-screen bg-[#050507]">
            {/* Header */}
            <div className="bg-[#0b0c10] border-b border-white/[0.06]">
                <div className="max-w-6xl mx-auto px-5 py-5 flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <p className="text-violet-300/60 text-[11px] font-semibold uppercase tracking-widest">ECF Consultoria · Visão do Publicador</p>
                        <h1 className="text-white font-display font-bold text-2xl mt-1">{impl.empresa_nome}</h1>
                        <p className="text-white/30 text-[12px] mt-1">Implementação iniciada em {impl.criado_em}</p>
                    </div>
                    <div className="flex gap-3 flex-wrap items-center">
                        <button
                            onClick={refresh}
                            className="flex items-center gap-1.5 px-3 py-2 rounded-xl border border-white/[0.08] text-white/40 hover:text-white hover:border-white/20 transition-colors text-[12px]"
                            title="Atualizar dados"
                        >
                            <RefreshCw size={13} />
                            <span className="text-[11px]">{lastUpdate.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</span>
                        </button>
                        {[
                            { label: 'Produtos',    value: produtos.length },
                            { label: 'Com Preço C', value: produtos.filter(p => p.preco_classico).length },
                            { label: 'Com Preço P', value: produtos.filter(p => p.preco_premium).length },
                        ].map(({ label, value }) => (
                            <div key={label} className="text-center px-4 py-2 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                                <p className="text-white font-bold text-lg">{value}</p>
                                <p className="text-white/30 text-[11px]">{label}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="max-w-6xl mx-auto px-5 py-6 space-y-6">

                {/* Dados Gerais */}
                <SectionCard title="Dados Gerais">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div>
                            <InfoRow label="ERP" value={erp.valor === 'Outro' ? `Outro: ${erp.outro}` : erp.valor} />
                            <InfoRow label="Acesso ERP" value={erp.acesso} />
                            <InfoRow label="Integrador Logístico" value={integ.valor === 'Outro' ? `Outro: ${integ.outro}` : integ.valor} />
                            <InfoRow label="HUB" value={hub.acesso} />
                            <InfoRow label="Publicar em Massa" value={pmass.valor} />
                        </div>
                        <div>
                            <InfoRow label="Conta Mercado Livre" value={itens.conta_ml?.feito ? 'Acesso confirmado' : 'Pendente'} />
                            <InfoRow label="Gmail Colaborador" value={linksAdmin.gmail_colaborador} />
                            <InfoRow label="Drive com Imagens" value={linksAdmin.drive_imagens} link />
                            <InfoRow label="App ECF" value={itens.app_ecf?.link} link />
                            <InfoRow label="Certificado A1" value={itens.certificado_a1?.feito ? 'Possui' : null} />
                            <InfoRow label="Programa Decola" value={itens.programa_decola?.link} link />
                        </div>
                    </div>
                </SectionCard>

                {/* Parâmetros de Precificação */}
                <SectionCard title="Parâmetros de Precificação" accent>
                    <div className="grid grid-cols-2 gap-6">
                        {[
                            { label: 'Clássico', cfg: cfgC, color: 'text-blue-300' },
                            { label: 'Premium',  cfg: cfgP, color: 'text-violet-300' },
                        ].map(({ label, cfg, color }) => (
                            <div key={label}>
                                <p className={cn('text-[11px] font-bold uppercase tracking-wider mb-3', color)}>{label}</p>
                                <div className="space-y-1.5">
                                    {[
                                        { k: 'Comissão',  v: pct(cfg.comissao) },
                                        { k: 'Imposto',   v: pct(cfg.imposto)  },
                                        { k: 'Margem',    v: pct(cfg.margem)   },
                                    ].map(({ k, v }) => (
                                        <div key={k} className="flex justify-between text-[12px]">
                                            <span className="text-white/40">{k}</span>
                                            <span className="text-white/80 font-medium">{v}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </SectionCard>

                {/* Catálogo de Produtos */}
                {produtos.length > 0 && (
                    <SectionCard title={`Catálogo de Produtos — ${produtos.length} SKU${produtos.length !== 1 ? 's' : ''}`}>
                        <div className="overflow-x-auto -mx-1">
                            <table className="w-full" style={{ minWidth: '900px' }}>
                                <thead>
                                    <tr className="border-b border-white/[0.06]">
                                        <th className={thCls}>SKU</th>
                                        <th className={thCls}>Produto</th>
                                        <th className={thCls}>Curva</th>
                                        <th className={thCls}>A × L × P</th>
                                        <th className={thCls}>Peso KG</th>
                                        <th className={thCls}>Estoque</th>
                                        <th className={thCls}>Especificações</th>
                                        <th className={thCls}>Descrição</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {produtos.map((p, i) => (
                                        <tr key={i} className="border-b border-white/[0.04] last:border-0 hover:bg-white/[0.02]">
                                            <td className={cn(tdCls, 'font-mono text-ecf-yellow/80')}>{p.sku}</td>
                                            <td className={cn(tdCls, 'max-w-[200px] truncate')} title={p.produto}>{p.produto}</td>
                                            <td className={tdCls}>{p.curva}</td>
                                            <td className={tdCls}>{p.altura} × {p.largura} × {p.profundidade}</td>
                                            <td className={tdCls}>{p.peso_kg}</td>
                                            <td className={tdCls}>{p.estoque}</td>
                                            <CopyCell value={p.especificacoes} tdCls={tdCls} />
                                            <CopyCell value={p.descricao} tdCls={tdCls} />
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </SectionCard>
                )}

                {/* Tabela de Precificação */}
                {produtos.length > 0 && (
                    <SectionCard title="Precificação" accent>
                        <div className="overflow-x-auto -mx-1">
                            <table className="w-full" style={{ minWidth: '1100px' }}>
                                <thead>
                                    <tr className="border-b border-white/[0.08]">
                                        <th className={thCls}>SKU</th>
                                        <th className={thCls}>Produto</th>
                                        <th className={thCls}>Custo R$</th>
                                        <th className={cn(thCls, 'text-blue-300/60')} colSpan={4}>Clássico</th>
                                        <th className={cn(thCls, 'text-violet-300/60')} colSpan={4}>Premium</th>
                                    </tr>
                                    <tr className="border-b border-white/[0.06] bg-white/[0.01]">
                                        <th /><th /><th />
                                        <th className={cn(thCls, 'text-blue-300/40')}>Frete R$</th>
                                        <th className={cn(thCls, 'text-blue-300/40')}>CP</th>
                                        <th className={cn(thCls, 'text-blue-300/40')}>Anunciado</th>
                                        <th className={cn(thCls, 'text-blue-300/40')}>Margem R$</th>
                                        <th className={cn(thCls, 'text-violet-300/40')}>Frete R$</th>
                                        <th className={cn(thCls, 'text-violet-300/40')}>CP</th>
                                        <th className={cn(thCls, 'text-violet-300/40')}>Anunciado</th>
                                        <th className={cn(thCls, 'text-violet-300/40')}>Margem R$</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {produtos.map((p, i) => (
                                        <tr key={i} className="border-b border-white/[0.04] last:border-0 hover:bg-white/[0.02]">
                                            <td className={cn(tdCls, 'font-mono text-ecf-yellow/80')}>{p.sku}</td>
                                            <td className={cn(tdCls, 'max-w-[160px] truncate')} title={p.produto}>{p.produto}</td>
                                            <td className={tdCls}>{brl(p.custo)}</td>
                                            <td className={tdCls}>{brl(p.frete_classico)}</td>
                                            <td className={cn(tdCls, 'font-semibold text-blue-300')}>{brl(p.preco_classico)}</td>
                                            <td className={cn(tdCls, 'text-blue-200')}>{brl(p.preco_anunciado_c)}</td>
                                            <td className={tdCls}>{brl(p.margem_c_r)}</td>
                                            <td className={tdCls}>{brl(p.frete_premium)}</td>
                                            <td className={cn(tdCls, 'font-semibold text-violet-300')}>{brl(p.preco_premium)}</td>
                                            <td className={cn(tdCls, 'text-violet-200')}>{brl(p.preco_anunciado_p)}</td>
                                            <td className={tdCls}>{brl(p.margem_p_r)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </SectionCard>
                )}

                {produtos.length === 0 && (
                    <div className="text-center py-16 text-white/30 text-[14px]">
                        Nenhum produto cadastrado ainda pelo cliente.
                    </div>
                )}

                <p className="text-center text-white/20 text-[11px] pb-4">ECF Consultoria · Visão somente leitura</p>
            </div>
        </div>
    );
}
