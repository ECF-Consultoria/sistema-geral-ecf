import { useState, useEffect, useRef, useMemo } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { cn } from '@/lib/utils';
import { Copy, Check, RefreshCw, Maximize2, X } from 'lucide-react';
import {
    produtosPreenchidos,
    mesclarPrecificacaoComPlanilha,
    impostoEfetivo,
    mcEfetivo,
    llEfetivo,
    calcPrecoFinal,
} from '@/lib/precificacaoProdutos';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function brl(n) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    return Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function pct(n) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    return (Number(n) * 100).toFixed(1) + '%';
}

/**
 * Rótulo de um percentual que pode variar produto a produto: valor único quando
 * todos batem, faixa quando não. O card de parâmetros mostrava só o alvo GLOBAL,
 * e com override por produto isso virava mentira — "0,0%" de margem numa tela em
 * que o preço já embutia 10%.
 */
function faixaPct(valores) {
    const nums = (valores ?? []).filter(v => v !== null && v !== undefined && !isNaN(v));
    if (nums.length === 0) return '—';
    const min = Math.min(...nums);
    const max = Math.max(...nums);
    return min === max ? pct(min) : `${pct(min)} – ${pct(max)}`;
}

// ─── Merge produtos + precificação ───────────────────────────────────────────

/**
 * Junta a Planilha de Produtos com as linhas de precificação já pareadas.
 *
 * Os percentuais saem de lib/precificacaoProdutos.js — a MESMA régua do Simulador
 * do cliente. Esta tela tinha a própria cópia da conta e lia só os alvos globais
 * (`margem_contribuicao`/`lucro_liquido`), ignorando o override por produto: com
 * MC 10% + LL 25% no produto e 0% no global, o publicador anunciava R$ 4.381,86
 * onde o cliente configurou R$ 7.706,48.
 */
function mergeProdutos(planilhaProdutos, linhasPrecif, precif) {
    const cfg = {
        classico: { comissao: 0.115, imposto: 0.19, ...(precif.classico ?? {}) },
        premium:  { comissao: 0.165, imposto: 0.19, ...(precif.premium  ?? {}) },
    };
    // Globais: acréscimo + alvos Margem de Contribuição e Lucro Líquido (default 0).
    const acr  = precif.acrescimo ?? 0.20;
    const mcG  = precif.margem_contribuicao ?? 0;
    const llG  = precif.lucro_liquido ?? 0;
    const modo = precif.modo_imposto;
    cfg.margem_contribuicao = mcG;
    cfg.lucro_liquido = llG;

    return produtosPreenchidos(planilhaProdutos).map((p, i) => {
        // Pareamento 1-para-1 pela ordem da planilha (linhasPrecif já vem mesclado).
        const pr = linhasPrecif[i] ?? {};
        const fc = parseFloat(pr.frete_classico || 0);
        const fp = parseFloat(pr.frete_premium  || 0);
        const impC = impostoEfetivo(pr, cfg.classico.imposto, modo);
        const impP = impostoEfetivo(pr, cfg.premium.imposto,  modo);
        const mc   = mcEfetivo(pr, mcG);
        const ll   = llEfetivo(pr, llG);
        const precoC = calcPrecoFinal(pr.custo, fc, cfg.classico.comissao, impC, mc, ll);
        const precoP = calcPrecoFinal(pr.custo, fp, cfg.premium.comissao,  impP, mc, ll);
        return {
            _i:            i,   // índice na lista mesclada — chave do frete e do save
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
            // Variação (cadastrada pelo cliente): N SKUs do mesmo grupo = 1 anúncio
            // no ML, com attribute_combinations (ex: Cor = Azul).
            variacao_grupo: p.variacao_grupo || '',
            variacao_tipo:  p.variacao_tipo  || '',
            variacao_valor: p.variacao_valor || '',
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
            // Percentuais EFETIVOS deste produto — é o que o card de parâmetros mostra.
            imposto_c: impC,
            imposto_p: impP,
            mc,
            ll,
            cfg,
        };
    });
}

/**
 * Reordena a lista deixando as variações do mesmo grupo adjacentes, na ordem em
 * que o grupo apareceu pela primeira vez. Produtos sem grupo ficam onde estão.
 * Devolve cada item com `_grupoPos`: 'unica' | 'inicio' | 'meio' | 'fim'.
 */
function agruparVariacoes(produtos) {
    const ordem   = [];
    const buckets = new Map();

    produtos.forEach(p => {
        const g = p.variacao_grupo || null;
        if (!g) { ordem.push({ tipo: 'solto', item: p }); return; }
        if (!buckets.has(g)) { buckets.set(g, []); ordem.push({ tipo: 'grupo', chave: g }); }
        buckets.get(g).push(p);
    });

    const saida = [];
    ordem.forEach(o => {
        if (o.tipo === 'solto') { saida.push({ ...o.item, _grupoPos: 'unica' }); return; }
        const itens = buckets.get(o.chave);
        itens.forEach((p, i) => saida.push({
            ...p,
            _grupoPos: itens.length === 1 ? 'unica' : i === 0 ? 'inicio' : i === itens.length - 1 ? 'fim' : 'meio',
        }));
    });
    return saida;
}

/** Quantos anúncios os SKUs representam no ML (grupo de variação = 1 anúncio). */
function contarAnuncios(produtos) {
    const grupos = new Set();
    let avulsos = 0;
    produtos.forEach(p => { p.variacao_grupo ? grupos.add(p.variacao_grupo) : avulsos++; });
    return grupos.size + avulsos;
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
    // '---' é a sentinela "não escolhido" dos selects do checklist (ERP, Integrador, HUB) —
    // linha em branco é mais honesta do que exibir o sentinela.
    if (!value || value === '—' || value === '---' || value === false) return null;
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

function CopyCell({ value, maxW = 'max-w-[180px]', tdCls, extra = null }) {
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
                <span className={cn('truncate', maxW)} title={value}>{value}</span>
                {extra}
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

/**
 * TextoCell — célula de texto longo (Especificações / Descrição): preview
 * truncado, botão copiar e botão expandir, que abre o card com o texto inteiro.
 */
function TextoCell({ value, titulo, subtitulo, tdCls, onExpand }) {
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
            <div className="flex items-center gap-1">
                <button
                    type="button"
                    onClick={() => onExpand({ titulo, subtitulo, texto: value })}
                    className="truncate max-w-[180px] text-left hover:text-white hover:underline decoration-white/20 underline-offset-2"
                    title="Ver texto completo"
                >
                    {value}
                </button>
                <div className="flex items-center gap-0.5 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={() => onExpand({ titulo, subtitulo, texto: value })}
                        className="p-1 rounded text-white/30 hover:text-white hover:bg-white/[0.08]" title="Ver completo">
                        <Maximize2 size={12} />
                    </button>
                    <button onClick={copy}
                        className="p-1 rounded text-white/30 hover:text-white hover:bg-white/[0.08]" title="Copiar">
                        {copied ? <Check size={12} className="text-emerald-400" /> : <Copy size={12} />}
                    </button>
                </div>
            </div>
        </td>
    );
}

/** Card de leitura do texto completo de Especificações / Descrição. */
function TextoModal({ titulo, subtitulo, texto, onClose }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        const onKey = e => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    function copy() {
        navigator.clipboard.writeText(texto);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-2xl max-h-[80vh] bg-[#0b0c14] border border-white/[0.1] rounded-2xl shadow-2xl flex flex-col"
                onClick={e => e.stopPropagation()}>
                <div className="flex items-start justify-between gap-4 px-5 py-4 border-b border-white/[0.07] shrink-0">
                    <div className="min-w-0">
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">{titulo}</p>
                        <p className="text-white font-semibold text-[14px] mt-0.5 truncate">{subtitulo}</p>
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                        <button onClick={copy} title="Copiar tudo"
                            className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white text-[12px] transition">
                            {copied ? <Check size={13} className="text-emerald-400" /> : <Copy size={13} />}
                            {copied ? 'Copiado' : 'Copiar'}
                        </button>
                        <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition"><X size={16} /></button>
                    </div>
                </div>
                <div className="overflow-y-auto px-5 py-4">
                    <p className="text-white/80 text-[13px] leading-relaxed whitespace-pre-wrap break-words">{texto}</p>
                </div>
            </div>
        </div>
    );
}

/** Quadradinho de check-in do publicador (feito / pendente) por SKU. */
function CheckinBox({ marcado, onToggle, disabled }) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onToggle}
            title={disabled ? 'Produto sem SKU — não dá para marcar' : marcado ? 'Marcado como feito · clique para desmarcar' : 'Marcar como feito'}
            className={cn('h-[18px] w-[18px] rounded-[5px] border flex items-center justify-center transition',
                disabled ? 'border-white/[0.08] cursor-not-allowed'
                    : marcado ? 'bg-emerald-500 border-emerald-500 hover:brightness-110'
                              : 'border-white/25 hover:border-white/60')}
        >
            {marcado && <Check size={12} strokeWidth={3} className="text-[#052e16]" />}
        </button>
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
function PrecificacaoAcao({ produtos, linhasPrecif, precif, token }) {
    const [tier, setTier] = useState('classico');
    // Frete indexado pela POSIÇÃO, nunca pelo SKU: cliente que digita "Não tenho" em
    // todos os produtos fazia um único input governar (e salvar) a linha de todos.
    const [fretes, setFretes] = useState(() => {
        const m = {};
        produtos.forEach(p => { m[p._i] = { frete_classico: p.frete_classico ?? '', frete_premium: p.frete_premium ?? '' }; });
        return m;
    });
    const debRef = useRef(null);

    const cfg = tier === 'classico'
        ? { comissao: 0.115, imposto: 0.19, ...(precif.classico ?? {}) }
        : { comissao: 0.165, imposto: 0.19, ...(precif.premium  ?? {}) };
    const mcG  = precif.margem_contribuicao ?? 0;
    const llG  = precif.lucro_liquido ?? 0;
    const acr  = precif.acrescimo ?? 0.20;
    const modo = precif.modo_imposto;
    const campoFrete = tier === 'classico' ? 'frete_classico' : 'frete_premium';

    /**
     * Salva os fretes preservando TODO o resto de cada linha.
     *
     * Este save já destruiu dado de cliente: ele reconstruía a lista a partir de um
     * mapa chaveado por SKU, então com o SKU repetido todas as N linhas viravam cópia
     * da última — custo, imposto e margens de todos os produtos substituídos pelos de
     * um só, a cada tecla de frete. Agora parte das linhas já pareadas 1-para-1 e só
     * troca o frete; as linhas que o Simulador criou à parte (avulsos, que ficam depois
     * das da planilha) seguem intactas porque não têm entrada em `fmap`.
     */
    function persist(fmap) {
        const lista = linhasPrecif.map((linha, i) => {
            const f = fmap[i];
            if (!f) return linha;
            return {
                ...linha,
                frete_classico: f.frete_classico ?? linha.frete_classico ?? '',
                frete_premium:  f.frete_premium  ?? linha.frete_premium  ?? '',
            };
        });
        // salvarItem retorna JSON → usar axios (não o router do Inertia).
        axios.patch(route('implementacao.salvar', token), { id: 'precificacao', campo: 'produtos', valor: lista });
    }

    function setFrete(idx, valor) {
        const novo = { ...fretes, [idx]: { ...(fretes[idx] ?? {}), [campoFrete]: valor } };
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
                            const frete = parseFloat(fretes[p._i]?.[campoFrete] || 0) || 0;
                            // Percentuais do PRODUTO (override) com o global como padrão —
                            // a mesma régua que o cliente viu no Simulador.
                            const linha = linhasPrecif[p._i] ?? {};
                            const preco = calcPrecoFinal(p.custo, frete, cfg.comissao,
                                impostoEfetivo(linha, cfg.imposto, modo),
                                mcEfetivo(linha, mcG), llEfetivo(linha, llG)); // final (c/ desconto)
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
                                                value={fretes[p._i]?.[campoFrete] ?? ''}
                                                onChange={e => setFrete(p._i, e.target.value)}
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
    const [texto, setTexto]     = useState(null);   // card de texto completo aberto
    const [checkin, setCheckin] = useState(() => impl.checkin ?? {});
    // SKUs com PATCH em voo — o reload de 30s não pode reverter o clique recém-dado.
    const emVooRef = useRef(new Set());

    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['impl'], onSuccess: () => setLastUpdate(new Date()) });
        }, 30000);
        return () => clearInterval(id);
    }, []);

    // Reconcilia o check-in vindo do servidor preservando o que ainda está em voo.
    useEffect(() => {
        setCheckin(local => {
            const servidor = { ...(impl.checkin ?? {}) };
            emVooRef.current.forEach(sku => {
                if (local[sku]) servidor[sku] = true; else delete servidor[sku];
            });
            return servidor;
        });
    }, [impl.checkin]);

    function toggleCheckin(sku) {
        if (!sku) return;
        const feito = !checkin[sku];
        setCheckin(c => { const n = { ...c }; if (feito) n[sku] = true; else delete n[sku]; return n; });
        emVooRef.current.add(sku);
        axios.patch(route('implementacao.publicador.checkin', impl.token), { sku, feito })
            .finally(() => emVooRef.current.delete(sku));
    }

    function refresh() {
        router.reload({ only: ['impl'], onSuccess: () => setLastUpdate(new Date()) });
    }

    const dados  = impl.dados ?? {};
    const itens  = dados.itens ?? {};
    const linksAdmin = dados.links_admin ?? {};

    const produtosBase  = itens.planilha_produtos?.produtos ?? [];
    const precif        = itens.precificacao ?? {};
    // Pareamento Planilha × precificação salva: lógica pura em lib/precificacaoProdutos.js,
    // a MESMA que o Simulador do cliente usa. As linhas da planilha vêm primeiro, na ordem
    // dela; depois os avulsos criados só no Simulador (que esta tela não publica).
    const linhasPrecif  = useMemo(
        () => mesclarPrecificacaoComPlanilha(produtosBase, precif.produtos),
        [produtosBase, precif.produtos]
    );
    const produtos      = useMemo(
        () => mergeProdutos(produtosBase, linhasPrecif, precif),
        [produtosBase, linhasPrecif, precif]
    );
    const catalogo      = agruparVariacoes(produtos);
    const feitos        = produtos.filter(p => p.sku && checkin[p.sku]).length;

    const cfgC = { comissao: 0.115, imposto: 0.19, ...(precif.classico ?? {}) };
    const cfgP = { comissao: 0.165, imposto: 0.19, ...(precif.premium  ?? {}) };

    // Algum percentual varia entre os produtos? Só então a legenda da faixa faz sentido.
    const temFaixa = ['mc', 'll', 'imposto_c', 'imposto_p'].some(
        k => new Set(produtos.map(p => p[k])).size > 1
    );

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
                            { label: 'Anúncios',    value: contarAnuncios(produtos) },
                            { label: 'Check-in',    value: `${feitos}/${produtos.length}`, destaque: feitos > 0 && feitos === produtos.length },
                            { label: 'Com Preço C', value: produtos.filter(p => p.preco_classico).length },
                            { label: 'Com Preço P', value: produtos.filter(p => p.preco_premium).length },
                        ].map(({ label, value, destaque }) => (
                            <div key={label} className={cn('text-center px-4 py-2 rounded-xl border',
                                destaque ? 'bg-emerald-500/[0.08] border-emerald-500/25' : 'bg-white/[0.03] border-white/[0.06]')}>
                                <p className={cn('font-bold text-lg', destaque ? 'text-emerald-300' : 'text-white')}>{value}</p>
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
                            {/* HUB virou dropdown em 2026-09-01 (era textarea livre): o valor
                                mora em .valor e o texto opcional continua em .acesso. */}
                            <InfoRow label="HUB" value={hub.valor === 'Outro' ? `Outro: ${hub.outro}` : hub.valor} />
                            <InfoRow label="Acesso HUB" value={hub.acesso} />
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
                            { label: 'Clássico', cfg: cfgC, color: 'text-blue-300', impostos: produtos.map(p => p.imposto_c) },
                            { label: 'Premium',  cfg: cfgP, color: 'text-violet-300', impostos: produtos.map(p => p.imposto_p) },
                        ].map(({ label, cfg, color, impostos }) => (
                            <div key={label}>
                                <p className={cn('text-[11px] font-bold uppercase tracking-wider mb-3', color)}>{label}</p>
                                <div className="space-y-1.5">
                                    {[
                                        { k: 'Comissão',  v: pct(cfg.comissao) },
                                        // Imposto EFETIVO: no modo individual o valor vem de cada
                                        // produto, então aqui vira faixa em vez do global do tier.
                                        { k: 'Imposto',   v: produtos.length ? faixaPct(impostos) : pct(cfg.imposto) },
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
                    {/* Alvos que entram no preço — EFETIVOS. Margem e lucro podem ser
                        definidos produto a produto no Simulador do cliente; mostrar só o
                        alvo global aqui já exibiu "0,0%" numa tela cujo preço embutia 10%. */}
                    <div className="mt-4 pt-3 border-t border-white/[0.06] grid grid-cols-3 gap-3">
                        {[
                            { k: 'Margem Contrib.', v: produtos.length ? faixaPct(produtos.map(p => p.mc)) : pct(precif.margem_contribuicao ?? 0) },
                            { k: 'Lucro Líquido',   v: produtos.length ? faixaPct(produtos.map(p => p.ll)) : pct(precif.lucro_liquido ?? 0)       },
                            { k: 'Acréscimo',       v: pct(precif.acrescimo ?? 0.20)         },
                        ].map(({ k, v }) => (
                            <div key={k} className="flex flex-col">
                                <span className="text-white/40 text-[11px]">{k}</span>
                                <span className="text-white/80 font-medium text-[13px]">{v}</span>
                            </div>
                        ))}
                    </div>
                    {temFaixa && (
                        <p className="mt-3 text-white/30 text-[11px]">
                            Onde aparece uma faixa, o cliente definiu o valor produto a produto no Simulador — cada linha da tabela abaixo usa o seu.
                        </p>
                    )}
                </SectionCard>

                {/* Catálogo de Produtos */}
                {produtos.length > 0 && (
                    <SectionCard title={`Catálogo de Produtos — ${produtos.length} SKU${produtos.length !== 1 ? 's' : ''}`}>
                        <p className="text-white/35 text-[12px] mb-3">
                            Marque o <span className="text-emerald-300 font-semibold">quadradinho</span> conforme
                            for publicando. SKUs com a barra roxa são <span className="text-violet-300 font-semibold">variações
                            do mesmo anúncio</span>.
                        </p>
                        <div className="overflow-x-auto -mx-1">
                            <table className="w-full" style={{ minWidth: '940px' }}>
                                <thead>
                                    <tr className="border-b border-white/[0.06]">
                                        <th className={cn(thCls, 'w-8')} title="Check-in do publicador">✓</th>
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
                                    {catalogo.map((p, i) => {
                                        const marcado = !!(p.sku && checkin[p.sku]);
                                        const emGrupo = p._grupoPos !== 'unica';
                                        return (
                                            <tr key={i} className={cn('border-b border-white/[0.04] last:border-0 transition-colors',
                                                marcado ? 'bg-emerald-500/[0.04] hover:bg-emerald-500/[0.07]' : 'hover:bg-white/[0.02]')}>
                                                <td className={cn(tdCls, 'relative')}>
                                                    {emGrupo && (
                                                        <span className={cn('absolute left-0 w-[3px] bg-violet-500/50',
                                                            p._grupoPos === 'inicio' ? 'top-1.5 bottom-0 rounded-t'
                                                                : p._grupoPos === 'fim' ? 'top-0 bottom-1.5 rounded-b' : 'inset-y-0')} />
                                                    )}
                                                    <CheckinBox marcado={marcado} disabled={!p.sku} onToggle={() => toggleCheckin(p.sku)} />
                                                </td>
                                                <td className={cn(tdCls, 'font-mono', marcado ? 'text-emerald-400/70 line-through' : 'text-ecf-yellow/80')}>{p.sku}</td>
                                                <CopyCell value={p.produto} maxW="max-w-[200px]" tdCls={tdCls}
                                                    extra={p.variacao_valor && (
                                                        <span className="ml-1.5 shrink-0 px-1.5 py-0.5 rounded bg-violet-500/15 text-violet-300 text-[10px] font-medium whitespace-nowrap">
                                                            {p.variacao_tipo || 'Variação'}: {p.variacao_valor}
                                                        </span>
                                                    )} />
                                                <td className={tdCls}>{p.curva}</td>
                                                <td className={tdCls}>{p.altura} × {p.largura} × {p.profundidade}</td>
                                                <td className={tdCls}>{p.peso_kg}</td>
                                                <td className={tdCls}>{p.estoque}</td>
                                                <TextoCell value={p.especificacoes} tdCls={tdCls} onExpand={setTexto}
                                                    titulo="Especificações técnicas" subtitulo={`${p.sku ? p.sku + ' · ' : ''}${p.produto}`} />
                                                <TextoCell value={p.descricao} tdCls={tdCls} onExpand={setTexto}
                                                    titulo="Descrição" subtitulo={`${p.sku ? p.sku + ' · ' : ''}${p.produto}`} />
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </SectionCard>
                )}

                {/* Precificação — visão de AÇÃO (frete editável, publicar por / preço final) */}
                {produtos.length > 0 && (
                    <PrecificacaoAcao produtos={produtos} linhasPrecif={linhasPrecif} precif={precif} token={impl.token} />
                )}

                {produtos.length === 0 && (
                    <div className="text-center py-16 text-white/30 text-[14px]">
                        Nenhum produto cadastrado ainda pelo cliente.
                    </div>
                )}

                <p className="text-center text-white/20 text-[11px] pb-4">
                    ECF Consultoria · Dados do cliente em somente leitura — só o check-in e o frete são seus
                </p>
            </div>

            {texto && <TextoModal {...texto} onClose={() => setTexto(null)} />}
        </div>
    );
}
