import { useState, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { cn } from '@/lib/utils';
import { Copy, Check, RefreshCw } from 'lucide-react';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function calcPreco(custo, frete, comissao, imposto, mc, ll) {
    const d = 1 - comissao - imposto - mc - ll;
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
        classico: { comissao: 0.115, imposto: 0.19, ...(precif.classico ?? {}) },
        premium:  { comissao: 0.165, imposto: 0.19, ...(precif.premium  ?? {}) },
    };
    // Globais: acréscimo + alvos Margem de Contribuição e Lucro Líquido (default 0).
    const acr = precif.acrescimo ?? 0.20;
    const mc  = precif.margem_contribuicao ?? 0;
    const ll  = precif.lucro_liquido ?? 0;
    cfg.margem_contribuicao = mc;
    cfg.lucro_liquido = ll;
    const pricingMap = {};
    (precif.produtos ?? []).forEach((p, i) => { pricingMap[p.sku || `__idx_${i}`] = p; });

    return (produtos ?? []).filter(p => p.produto?.trim() || p.sku?.trim()).map((p, i) => {
        const key = p.sku?.trim() || `__idx_${i}`;
        const pr = pricingMap[key] ?? {};
        const fc = parseFloat(pr.frete_classico || 0);
        const fp = parseFloat(pr.frete_premium  || 0);
        const precoC = calcPreco(pr.custo, fc, cfg.classico.comissao, cfg.classico.imposto, mc, ll);
        const precoP = calcPreco(pr.custo, fp, cfg.premium.comissao,  cfg.premium.imposto,  mc, ll);
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
            preco_anunciado_c: precoC ? precoC * (1 + acr) : null,
            preco_anunciado_p: precoP ? precoP * (1 + acr) : null,
            margem_c_r:        precoC ? precoC * mc : null,   // margem de contribuição R$
            margem_p_r:        precoP ? precoP * mc : null,
            lucro_c_r:         precoC ? precoC * ll : null,   // lucro líquido R$
            lucro_p_r:         precoP ? precoP * ll : null,
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

// Botão copiar valor (para colar no marketplace).
function BtnCopy({ text }) {
    const [c, setC] = useState(false);
    if (!text) return null;
    return (
        <button type="button" title="Copiar"
            onClick={() => { navigator.clipboard.writeText(text); setC(true); setTimeout(() => setC(false), 1500); }}
            className="shrink-0 text-white/25 hover:text-white transition">
            {c ? <Check size={14} className="text-emerald-400" /> : <Copy size={14} />}
        </button>
    );
}

/**
 * PrecificacaoAcao — visão de AÇÃO do Publicador: por tier, mostra o preço a
 * PUBLICAR (anunciado) e o preço FINAL (com desconto) de cada produto, com
 * botão de copiar. O Publicador só pode mexer no FRETE (recalcula ao vivo e
 * persiste via implementacao.salvar). Comissão/imposto/MC/LL/acréscimo travados.
 */
function PrecificacaoAcao({ produtos, precif, token }) {
    const [tier, setTier] = useState('classico');
    const [fretes, setFretes] = useState(() => {
        const m = {};
        produtos.forEach(p => { if (p.sku) m[p.sku] = { frete_classico: p.frete_classico ?? '', frete_premium: p.frete_premium ?? '' }; });
        return m;
    });
    const debRef = useRef(null);

    const cfg = tier === 'classico'
        ? (precif.classico ?? { comissao: 0.115, imposto: 0.19 })
        : (precif.premium  ?? { comissao: 0.165, imposto: 0.19 });
    const mc  = precif.margem_contribuicao ?? 0;
    const ll  = precif.lucro_liquido ?? 0;
    const acr = precif.acrescimo ?? 0.20;
    const campoFrete = tier === 'classico' ? 'frete_classico' : 'frete_premium';

    function persist(fmap) {
        const base = {};
        (precif.produtos ?? []).forEach(p => { if (p.sku) base[p.sku] = p; });
        const lista = produtos.filter(p => p.sku).map(p => ({
            ...(base[p.sku] ?? { sku: p.sku }),
            sku: p.sku,
            frete_classico: fmap[p.sku]?.frete_classico ?? (base[p.sku]?.frete_classico ?? ''),
            frete_premium:  fmap[p.sku]?.frete_premium  ?? (base[p.sku]?.frete_premium  ?? ''),
        }));
        // salvarItem retorna JSON → usar axios (não o router do Inertia).
        axios.patch(route('implementacao.salvar', token), { id: 'precificacao', campo: 'produtos', valor: lista });
    }

    function setFrete(sku, valor) {
        const novo = { ...fretes, [sku]: { ...(fretes[sku] ?? {}), [campoFrete]: valor } };
        setFretes(novo);
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => persist(novo), 800);
    }

    return (
        <SectionCard title="Precificação — o que publicar" accent>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <p className="text-white/40 text-[12px]">
                    Anuncie pelo <span className="text-amber-300 font-semibold">Publicar por</span> e configure o desconto até o <span className="text-emerald-300 font-semibold">Preço final</span>. Você só ajusta o <span className="text-white/70 font-semibold">Frete</span>.
                </p>
                <div className="inline-flex rounded-xl bg-white/[0.04] border border-white/[0.08] p-1">
                    {[{ k: 'classico', l: 'Clássico' }, { k: 'premium', l: 'Premium' }].map(({ k, l }) => (
                        <button key={k} type="button" onClick={() => setTier(k)}
                            className={cn('px-4 py-1.5 rounded-lg text-[13px] font-semibold transition',
                                tier === k ? 'bg-ecf-yellow text-black' : 'text-white/50 hover:text-white/80')}>
                            {l}
                        </button>
                    ))}
                </div>
            </div>

            <div className="overflow-x-auto -mx-1">
                <table className="w-full" style={{ minWidth: '720px' }}>
                    <thead>
                        <tr className="border-b border-white/[0.08]">
                            <th className="text-left px-3 py-2 text-white/30 font-semibold uppercase tracking-wider text-[10px]">Produto</th>
                            <th className="text-left px-3 py-2 text-white/40 font-semibold uppercase tracking-wider text-[10px]">Frete R$ (você ajusta)</th>
                            <th className="text-right px-3 py-2 text-amber-300/70 font-semibold uppercase tracking-wider text-[10px]">🏷️ Publicar por</th>
                            <th className="text-right px-3 py-2 text-emerald-300/70 font-semibold uppercase tracking-wider text-[10px]">💰 Preço final</th>
                        </tr>
                    </thead>
                    <tbody>
                        {produtos.map((p, i) => {
                            const frete = parseFloat(fretes[p.sku]?.[campoFrete] || 0) || 0;
                            const preco = calcPreco(p.custo, frete, cfg.comissao, cfg.imposto, mc, ll); // final (c/ desconto)
                            const anunciado = preco ? preco * (1 + acr) : null;
                            return (
                                <tr key={i} className="border-b border-white/[0.04] last:border-0 hover:bg-white/[0.02]">
                                    <td className="px-3 py-2.5">
                                        <p className="text-white/85 text-[13px] font-medium max-w-[220px] truncate" title={p.produto}>{p.produto}</p>
                                        <p className="text-ecf-yellow/60 text-[11px] font-mono">{p.sku}</p>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div className="flex items-center rounded-lg bg-white/[0.05] border border-white/[0.1] focus-within:border-ecf-yellow/40 w-28">
                                            <span className="pl-2 text-white/30 text-[12px]">R$</span>
                                            <input type="number" step="0.01" min="0" inputMode="decimal"
                                                value={fretes[p.sku]?.[campoFrete] ?? ''}
                                                onChange={e => setFrete(p.sku, e.target.value)}
                                                placeholder="0,00"
                                                className="w-full h-9 px-1.5 bg-transparent text-white text-[13px] focus:outline-none placeholder:text-white/20" />
                                        </div>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div className="flex items-center justify-end gap-2">
                                            <span className="text-amber-300 font-bold text-[15px] tabular-nums">{brl(anunciado)}</span>
                                            <BtnCopy text={anunciado ? Number(anunciado).toFixed(2) : ''} />
                                        </div>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div className="flex items-center justify-end gap-2">
                                            <span className="text-emerald-300 font-bold text-[15px] tabular-nums">{brl(preco)}</span>
                                            <BtnCopy text={preco ? Number(preco).toFixed(2) : ''} />
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </SectionCard>
    );
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
                        <p className="text-white/30 text-[12px] mt-1">Onboarding iniciado em {impl.criado_em}</p>
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
                            <InfoRow label="App ECF" value={linksAdmin.app_ecf} link />
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
                    {/* Alvos globais (valem p/ os dois tiers) */}
                    <div className="mt-4 pt-3 border-t border-white/[0.06] grid grid-cols-3 gap-3">
                        {[
                            { k: 'Margem Contrib.', v: pct(precif.margem_contribuicao ?? 0) },
                            { k: 'Lucro Líquido',   v: pct(precif.lucro_liquido ?? 0)       },
                            { k: 'Acréscimo',       v: pct(precif.acrescimo ?? 0.20)         },
                        ].map(({ k, v }) => (
                            <div key={k} className="flex flex-col">
                                <span className="text-white/40 text-[11px]">{k}</span>
                                <span className="text-white/80 font-medium text-[13px]">{v}</span>
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

                {/* Precificação — visão de AÇÃO (frete editável, publicar por / preço final) */}
                {produtos.length > 0 && (
                    <PrecificacaoAcao produtos={produtos} precif={precif} token={impl.token} />
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
