import { useState, useRef, useCallback, useMemo, useEffect } from 'react';
import axios from 'axios';
import { Check, BookOpen, Save, AlertCircle, X, ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SpreadsheetGrid } from '@/Components/SpreadsheetGrid';

// ─── CustomSelect — dropdown cross-browser sem seta dupla ────────────────────

function CustomSelect({ value, onChange, opcoes, className = '', small = false }) {
    return (
        <select
            value={value}
            onChange={e => onChange(e.target.value)}
            className={cn(
                'w-full rounded-xl border border-white/[0.08] bg-white/[0.03] text-white focus:outline-none focus:border-ecf-yellow/40 cursor-pointer',
                small ? 'h-9 pl-2 pr-8 text-[12px]' : 'h-10 pl-3 pr-8 text-[13px]',
                className
            )}
        >
            {opcoes.map(o => (
                <option key={o} value={o} className="bg-[#0d0e14] text-white">{o}</option>
            ))}
        </select>
    );
}

// ─── YouTube embed ────────────────────────────────────────────────────────────

function toEmbedUrl(url) {
    if (!url) return null;
    try {
        const u = new URL(url);
        if (u.hostname === 'youtu.be') return `https://www.youtube.com/embed${u.pathname}`;
        const v = u.searchParams.get('v');
        if (v) return `https://www.youtube.com/embed/${v}`;
        if (u.pathname.startsWith('/embed/')) return url;
    } catch { /* URL inválida */ }
    return null;
}

function VideoModal({ url, titulo, onClose }) {
    const embedUrl = toEmbedUrl(url);
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" onClick={onClose}>
            <div className="w-full max-w-3xl" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between mb-3">
                    <p className="text-white font-semibold text-[14px]">{titulo}</p>
                    <button onClick={onClose} className="p-1.5 text-white/40 hover:text-white transition-colors">
                        <X size={18} />
                    </button>
                </div>
                <div className="relative w-full rounded-2xl overflow-hidden bg-black" style={{ paddingTop: '56.25%' }}>
                    {embedUrl ? (
                        <iframe
                            src={embedUrl}
                            title={titulo}
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowFullScreen
                            className="absolute inset-0 w-full h-full"
                        />
                    ) : (
                        <div className="absolute inset-0 flex items-center justify-center text-white/40 text-[13px]">
                            URL de vídeo inválida.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Cabeçalho com progresso ──────────────────────────────────────────────────

function ProgressHeader({ empresa_nome, progresso }) {
    const { pct, feitos, total } = progresso;
    const color = pct === 100 ? '#22c55e' : pct >= 60 ? '#eab308' : '#6366f1';
    return (
        <div className="bg-[#0b0c10] border-b border-white/[0.06] sticky top-0 z-10">
            <div className="max-w-2xl mx-auto px-4 py-4">
                <div className="flex items-center justify-between mb-3">
                    <div>
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">ECF Consultoria · Implementação MLB</p>
                        <h1 className="text-white font-display font-bold text-lg mt-0.5">{empresa_nome}</h1>
                    </div>
                    <div className="text-right">
                        <span className="text-white font-bold text-xl">{pct}%</span>
                        <p className="text-white/40 text-[11px]">{feitos}/{total} itens</p>
                    </div>
                </div>
                <div className="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                    <div style={{ width: `${pct}%`, background: color, transition: 'width 0.4s ease' }} className="h-full rounded-full" />
                </div>
            </div>
        </div>
    );
}

// ─── Botão tutorial ───────────────────────────────────────────────────────────

function TutorialBtn({ url, titulo, onPlay }) {
    if (!url) return null;
    return (
        <button
            onClick={() => onPlay(url, titulo)}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 hover:text-red-300 text-[11px] font-medium transition-all"
        >
            <BookOpen size={11} />
            Tutorial
        </button>
    );
}

// ─── Tabela de produtos ───────────────────────────────────────────────────────

const PRODUTOS_COLS = [
    { id: 'curva',          label: 'Curva',          type: 'select', options: ['Curva A', 'Curva B', 'Curva C'], width: 100 },
    { id: 'sku',            label: 'SKU',             type: 'text',   width: 120 },
    { id: 'produto',        label: 'Produto',         type: 'text',   width: 200 },
    { id: 'altura',         label: 'Altura',          type: 'text',   width: 72  },
    { id: 'largura',        label: 'Largura',         type: 'text',   width: 72  },
    { id: 'profundidade',   label: 'Prof.',           type: 'text',   width: 72  },
    { id: 'peso_kg',        label: 'Peso KG',         type: 'text',   width: 80  },
    { id: 'altura_emb',     label: 'Alt. Emb.',       type: 'text',   width: 72  },
    { id: 'largura_emb',    label: 'Larg. Emb.',      type: 'text',   width: 72  },
    { id: 'prof_emb',       label: 'Prof. Emb.',      type: 'text',   width: 72  },
    { id: 'peso_emb_kg',    label: 'Peso Emb. KG',    type: 'text',   width: 90  },
    { id: 'estoque',        label: 'Estoque',         type: 'text',   width: 72  },
    { id: 'especificacoes', label: 'Espec. Técnicas', type: 'textarea', width: 180 },
    { id: 'descricao',      label: 'Descrição',       type: 'textarea', width: 200 },
];

function TabelaProdutos({ produtos, onSave }) {
    // Remove linhas completamente vazias do final (dados antigos salvos em excesso)
    const trimmed = [...produtos];
    while (trimmed.length > 0) {
        const last = trimmed[trimmed.length - 1];
        const hasData = PRODUTOS_COLS.some(c => String(last[c.id] ?? '').trim() !== '' && last[c.id] !== c.options?.[0]);
        if (!hasData) trimmed.pop(); else break;
    }
    const [rows, setRows] = useState(trimmed);
    const debRef    = useRef(null);
    const pendingRef = useRef(null);
    const onSaveRef  = useRef(onSave);
    useEffect(() => { onSaveRef.current = onSave; }, [onSave]);

    // Flush imediato ao fechar o modal
    useEffect(() => () => {
        clearTimeout(debRef.current);
        if (pendingRef.current) onSaveRef.current(pendingRef.current);
    }, []);

    function handleChange(newRows) {
        setRows(newRows);
        pendingRef.current = newRows;
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => { onSaveRef.current(newRows); pendingRef.current = null; }, 800);
    }

    return (
        <div className="mt-3">
            <SpreadsheetGrid columns={PRODUTOS_COLS} rows={rows} onChange={handleChange} minRows={10} exportFilename="produtos" showImportExport={false} />
            <p className="mt-2 text-white/20 text-[11px]">
                Dica: arraste o quadrado azul no canto da célula para preencher · Ctrl+C/V para copiar e colar
            </p>
        </div>
    );
}

// ─── Gmail display (read-only, com botão copiar) ─────────────────────────────

function GmailDisplay({ gmail }) {
    const [copied, setCopied] = useState(false);
    function copy() {
        navigator.clipboard.writeText(gmail);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }
    return (
        <div className="mt-3 flex items-center gap-3 p-3.5 rounded-xl bg-ecf-yellow/5 border border-ecf-yellow/20">
            <span className="flex-1 text-ecf-yellow font-mono text-[14px] font-semibold">{gmail}</span>
            <button
                onClick={copy}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[12px] font-medium transition-all shrink-0"
            >
                {copied ? <Check size={12} /> : null}
                {copied ? 'Copiado!' : 'Copiar'}
            </button>
        </div>
    );
}

// ─── Calculadora de precificação ─────────────────────────────────────────────

function calcPreco(custo, frete, comissao, imposto, margem) {
    const divisor = 1 - comissao - imposto - margem;
    if (divisor <= 0 || isNaN(custo) || custo === '') return null;
    return (parseFloat(custo) + parseFloat(frete)) / divisor;
}

function fmt(n) {
    if (n === null || isNaN(n)) return '—';
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

const CFG_DEFAULT = {
    classico: { comissao: 0.115, imposto: 0.19, margem: 0.32 },
    premium:  { comissao: 0.165, imposto: 0.19, margem: 0.35 },
};

function PrecificacaoModal({ dados, planilhaProdutos, onSave, onSaveCfg, onClose }) {
    const cfgC = dados.classico ?? CFG_DEFAULT.classico;
    const cfgP = dados.premium  ?? CFG_DEFAULT.premium;

    const [cfg, setCfg] = useState({
        classico: { comissao: (cfgC.comissao * 100).toFixed(2), imposto: (cfgC.imposto * 100).toFixed(2), margem: (cfgC.margem * 100).toFixed(2) },
        premium:  { comissao: (cfgP.comissao * 100).toFixed(2), imposto: (cfgP.imposto * 100).toFixed(2), margem: (cfgP.margem * 100).toFixed(2) },
    });
    const [cfgOpen, setCfgOpen] = useState(false);
    const cfgDebRef = useRef(null);

    function updateCfg(tier, campo, valor) {
        let novo = { ...cfg, [tier]: { ...cfg[tier], [campo]: valor } };
        // comissão clássico → premium = clássico + 5%
        if (tier === 'classico' && campo === 'comissao') {
            novo.premium = { ...novo.premium, comissao: (parseFloat(valor || 0) + 5).toFixed(2) };
        }
        setCfg(novo);
        clearTimeout(cfgDebRef.current);
        cfgDebRef.current = setTimeout(() => {
            const toDecimal = t => ({
                comissao: parseFloat(novo[t].comissao) / 100,
                imposto:  parseFloat(novo[t].imposto)  / 100,
                margem:   parseFloat(novo[t].margem)   / 100,
            });
            onSaveCfg('classico', toDecimal('classico'));
            onSaveCfg('premium',  toDecimal('premium'));
        }, 800);
    }

    // config numérica para cálculos em tempo real
    const cc = { comissao: parseFloat(cfg.classico.comissao)/100, imposto: parseFloat(cfg.classico.imposto)/100, margem: parseFloat(cfg.classico.margem)/100 };
    const cp = { comissao: parseFloat(cfg.premium.comissao)/100,  imposto: parseFloat(cfg.premium.imposto)/100,  margem: parseFloat(cfg.premium.margem)/100  };

    const emptyRow = { sku: '', descricao: '', custo: '', frete_classico: '', frete_premium: '' };

    function mergeComPlanilha() {
        const planilha = (planilhaProdutos ?? []).filter(p => p.sku?.trim());
        if (planilha.length === 0) {
            return dados.produtos?.length > 0
                ? dados.produtos.map(p => ({ ...emptyRow, ...p }))
                : [];
        }
        const existente = {};
        (dados.produtos ?? []).forEach(p => { if (p.sku) existente[p.sku] = p; });
        return planilha.map(p => ({
            ...emptyRow,
            sku:       p.sku ?? '',
            descricao: p.produto ?? '',
            ...(existente[p.sku] ?? {}),
        }));
    }

    const [rows, setRows] = useState(() => mergeComPlanilha());
    const debRef     = useRef(null);
    const pendingRef  = useRef(null);
    const onSaveRef   = useRef(onSave);
    useEffect(() => { onSaveRef.current = onSave; }, [onSave]);

    // Flush imediato ao fechar o modal
    useEffect(() => () => {
        clearTimeout(debRef.current);
        if (pendingRef.current) onSaveRef.current(pendingRef.current);
    }, []);

    function handleChange(newRows) {
        setRows(newRows);
        pendingRef.current = newRows;
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => { onSaveRef.current(newRows); pendingRef.current = null; }, 800);
    }

    const cols = useMemo(() => [
        { id: 'sku',            label: 'SKU',        type: 'text',     width: 110 },
        { id: 'descricao',      label: 'Descrição',  type: 'text',     width: 180 },
        { id: 'custo',          label: 'Custo R$',   type: 'number',   width: 90  },
        { id: 'frete_classico', label: 'Frete',      type: 'number',   width: 80  },
        { id: '_preco_c',  label: 'CP Cl.',      type: 'readonly', width: 100, align: 'right',
          compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, cc.imposto, cc.margem); return fmt(p); } },
        { id: '_anunc_c',  label: 'Anunciado Cl.', type: 'readonly', width: 110, align: 'right',
          compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, cc.imposto, cc.margem); return fmt(p ? p * 1.2 : null); } },
        { id: '_marg_c',   label: 'Margem Cl.',  type: 'readonly', width: 95,  align: 'right',
          compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, cc.imposto, cc.margem); return fmt(p ? p * cc.margem : null); } },
        { id: 'frete_premium',  label: 'Frete',      type: 'number',   width: 80  },
        { id: '_preco_p',  label: 'CP Pr.',      type: 'readonly', width: 100, align: 'right',
          compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, cp.imposto, cp.margem); return fmt(p); } },
        { id: '_anunc_p',  label: 'Anunciado Pr.', type: 'readonly', width: 110, align: 'right',
          compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, cp.imposto, cp.margem); return fmt(p ? p * 1.2 : null); } },
        { id: '_marg_p',   label: 'Margem Pr.',  type: 'readonly', width: 95,  align: 'right',
          compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, cp.imposto, cp.margem); return fmt(p ? p * cp.margem : null); } },
    ], [cc.comissao, cc.imposto, cc.margem, cp.comissao, cp.imposto, cp.margem]);

    const headerGroups = [
        { label: '',         span: 3, className: '' },
        { label: 'Clássico', span: 4, className: 'text-blue-300/70' },
        { label: 'Premium',  span: 4, className: 'text-violet-300/70' },
    ];

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-[#050507]">
            <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06] bg-[#0b0c10] shrink-0">
                <div>
                    <h2 className="text-white font-display font-bold text-lg">Precificação</h2>
                    <p className="text-white/40 text-[12px] mt-0.5">Informe o custo e frete por produto — os preços são calculados automaticamente</p>
                </div>
                <button onClick={onClose} className="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white text-[13px] transition-all">
                    <X size={14} />
                    Fechar
                </button>
            </div>

            <div className="border-b border-white/[0.06] shrink-0">
                <button
                    onClick={() => setCfgOpen(v => !v)}
                    className="flex items-center gap-2 w-full px-5 py-3 text-white/40 hover:text-white/70 text-[12px] transition-colors"
                >
                    <span className={cn('transition-transform text-[10px]', cfgOpen && 'rotate-180')}>▼</span>
                    Configurar parâmetros — Comissão, Imposto, Margem
                    <span className="ml-2 text-white/20 text-[11px]">(comissão premium = clássico + 5% automático)</span>
                </button>
                {cfgOpen && (
                    <div className="px-5 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-6">
                        {[
                            { tier: 'classico', label: 'Clássico', color: 'text-blue-300' },
                            { tier: 'premium',  label: 'Premium',  color: 'text-violet-300' },
                        ].map(({ tier, label, color }) => (
                            <div key={tier}>
                                <p className={cn('text-[11px] font-bold uppercase tracking-wider mb-2', color)}>{label}</p>
                                <div className="grid grid-cols-3 gap-2">
                                    {[
                                        { id: 'comissao', label: 'Comissão %' },
                                        { id: 'imposto',  label: 'Imposto %'  },
                                        { id: 'margem',   label: 'Margem %'   },
                                    ].map(({ id, label: lbl }) => (
                                        <div key={id}>
                                            <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">{lbl}</label>
                                            <input
                                                type="number" step="0.01" min="0"
                                                value={cfg[tier][id]}
                                                onChange={e => updateCfg(tier, id, e.target.value)}
                                                className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40"
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <div className="flex-1 overflow-auto p-5">
                <SpreadsheetGrid columns={cols} rows={rows} onChange={handleChange} minRows={10} headerGroups={headerGroups} exportFilename="precificacao" />
                <p className="mt-2 text-white/20 text-[11px]">
                    Dica: arraste o quadrado azul no canto da célula para preencher · Ctrl+C/V para copiar e colar
                </p>
            </div>
        </div>
    );
}

// ─── Modal de tela cheia para preenchimento de produtos ──────────────────────

function ProdutosModal({ produtos, onSave, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-[#050507]">
            <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06] bg-[#0b0c10] shrink-0">
                <div>
                    <h2 className="text-white font-display font-bold text-lg">Planilha de Produtos</h2>
                    <p className="text-white/40 text-[12px] mt-0.5">Preencha as informações de cada produto</p>
                </div>
                <button onClick={onClose} className="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white text-[13px] transition-all">
                    <X size={14} />
                    Fechar
                </button>
            </div>
            <div className="flex-1 overflow-auto p-5">
                <TabelaProdutos produtos={produtos} onSave={onSave} />
            </div>
        </div>
    );
}

// ─── Campo por tipo ───────────────────────────────────────────────────────────

function ItemInput({ item, dado, linksAdmin, onChange }) {
    const debRef = useRef({});

    function handleText(campo, value) {
        onChange(item.id, campo, value, false);
        clearTimeout(debRef.current[campo]);
        debRef.current[campo] = setTimeout(() => onChange(item.id, campo, value, true), 800);
    }

    const { tipo } = item;

    // Botão para URL fixa (mesma para todos)
    if (tipo === 'link_fixo') {
        return (
            <div className="mt-3">
                <a
                    href={item.link_fixo}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow hover:bg-ecf-yellow/20 text-[13px] font-medium transition-all"
                >
                    <ExternalLink size={14} />
                    Acessar
                </a>
            </div>
        );
    }

    // Botão para URL configurada pelo admin por empresa
    if (tipo === 'link_admin') {
        const url = linksAdmin?.[item.id];
        if (!url) return (
            <p className="mt-2 text-white/30 text-[12px] italic">Link ainda não configurado pela ECF.</p>
        );
        return (
            <div className="mt-3">
                <a
                    href={url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow hover:bg-ecf-yellow/20 text-[13px] font-medium transition-all"
                >
                    <ExternalLink size={14} />
                    Acessar
                </a>
            </div>
        );
    }

    // Acesso Colaborador — exibe o Gmail configurado pela ECF (read-only)
    if (tipo === 'gmail') {
        const gmail = linksAdmin?.gmail_colaborador;
        if (!gmail) return (
            <p className="mt-2 text-white/30 text-[12px] italic">Gmail ainda não configurado pela ECF.</p>
        );
        return (
            <GmailDisplay gmail={gmail} />
        );
    }

    // URL digitada pelo cliente
    if (tipo === 'link') {
        return (
            <div className="mt-3">
                <label className="text-white/40 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Link</label>
                <input
                    type="url"
                    value={dado?.link ?? ''}
                    onChange={e => handleText('link', e.target.value)}
                    onBlur={e => onChange(item.id, 'link', e.target.value, true)}
                    placeholder="https://..."
                    className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 transition-colors"
                />
            </div>
        );
    }

    // Textarea (HUB)
    if (tipo === 'texto') {
        return (
            <div className="mt-3">
                <label className="text-white/40 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Acesso / Informações</label>
                <textarea
                    value={dado?.acesso ?? ''}
                    onChange={e => handleText('acesso', e.target.value)}
                    onBlur={e => onChange(item.id, 'acesso', e.target.value, true)}
                    rows={3}
                    placeholder="Informe o login, senha ou link de acesso..."
                    className="w-full px-3 py-2.5 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 transition-colors resize-none"
                />
            </div>
        );
    }

    // ERP / Integrador
    if (tipo === 'select') {
        const opcoes = item.id === 'erp' ? ['Em Contratação','Tiny ERP','Bling','SAP','Netsuite','TOTVS','Omie','Outro'] : ['Em Contratação','Melhor Envio','Frenet','DirectLog','Jadlog','Correios','Outro'];
        const valor = dado?.valor ?? 'Em Contratação';
        return (
            <div className="mt-3 space-y-3">
                <div>
                    <label className="text-white/40 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Sistema</label>
                    <CustomSelect
                        value={valor}
                        onChange={v => onChange(item.id, 'valor', v, true)}
                        opcoes={opcoes}
                    />
                </div>
                {valor === 'Outro' && (
                    <div>
                        <label className="text-white/40 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Especificar</label>
                        <input
                            type="text"
                            value={dado?.outro ?? ''}
                            onChange={e => handleText('outro', e.target.value)}
                            onBlur={e => onChange(item.id, 'outro', e.target.value, true)}
                            placeholder="Nome do sistema..."
                            className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                        />
                    </div>
                )}
                {item.id === 'erp' && (
                    <div>
                        <label className="text-white/40 text-[11px] font-medium uppercase tracking-wider block mb-1.5">Acesso ao ERP</label>
                        <textarea
                            value={dado?.acesso ?? ''}
                            onChange={e => handleText('acesso', e.target.value)}
                            onBlur={e => onChange(item.id, 'acesso', e.target.value, true)}
                            rows={2}
                            placeholder="Login, senha ou link de acesso ao ERP..."
                            className="w-full px-3 py-2.5 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 resize-none"
                        />
                    </div>
                )}
            </div>
        );
    }

    // Select com opções livres (Publicar em Massa)
    if (tipo === 'select_opcoes') {
        return (
            <div className="mt-3">
                <CustomSelect
                    value={dado?.valor ?? ''}
                    onChange={v => {
                        const selecionado = v !== '' && v !== 'Selecione uma opção...';
                        onChange(item.id, 'valor', v, true);
                        onChange(item.id, 'feito', selecionado, true);
                    }}
                    opcoes={['Selecione uma opção...', ...item.opcoes]}
                />
            </div>
        );
    }

    // Produtos e Precificacao — sem campo inline; botão abre modal (tratado em ChecklistItem)
    if (tipo === 'produtos' || tipo === 'precificacao') return null;

    // Instruções simples (Endereço Fiscal)
    if (tipo === 'instrucoes') {
        return (
            <div className="mt-3 p-4 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                <p className="text-white/60 text-[13px] leading-relaxed">{item.instrucoes}</p>
            </div>
        );
    }

    // Instruções + link fixo (Inscrição Estadual)
    if (tipo === 'instrucoes_link') {
        return (
            <div className="mt-3 space-y-3">
                <div className="p-4 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                    <p className="text-white/60 text-[13px] leading-relaxed">{item.instrucoes}</p>
                </div>
                <a
                    href={item.link_fixo}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow hover:bg-ecf-yellow/20 text-[13px] font-medium transition-all"
                >
                    <ExternalLink size={14} />
                    Acessar Mercado Livre
                </a>
            </div>
        );
    }

    // checkbox — sem campo extra (só o checkbox de feito)
    return null;
}

// ─── Item do checklist ────────────────────────────────────────────────────────

function ChecklistItem({ item, dado, tutorialUrl, linksAdmin, onChange, onPlay, onOpenProdutos, onOpenPrecificacao, num }) {
    const feito = dado?.feito ?? false;

    return (
        <div className={cn(
            'rounded-2xl border p-5 transition-all',
            feito ? 'border-emerald-500/20 bg-emerald-950/10' : 'border-white/[0.08] bg-white/[0.02]'
        )}>
            <div className="flex items-start gap-3 mb-3">
                <div className={cn(
                    'w-7 h-7 rounded-full border-2 flex items-center justify-center shrink-0 text-[11px] font-bold mt-0.5',
                    feito ? 'border-emerald-400 bg-emerald-400 text-white' : 'border-white/20 text-white/30'
                )}>
                    {feito ? <Check size={12} /> : num}
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-white font-semibold text-[15px]">{item.titulo}</h3>
                        <TutorialBtn url={tutorialUrl} titulo={item.titulo} onPlay={onPlay} />
                    </div>
                    <p className="text-white/40 text-[12px] mt-0.5">{item.descricao}</p>
                </div>
            </div>

            {item.tipo === 'produtos' ? (
                <div className="mt-3 flex items-center justify-between p-4 rounded-xl border border-white/[0.08] bg-white/[0.02]">
                    <span className="text-white/50 text-[13px]">
                        {(dado?.produtos ?? []).length > 0
                            ? `${dado.produtos.length} produto${dado.produtos.length !== 1 ? 's' : ''} cadastrado${dado.produtos.length !== 1 ? 's' : ''}`
                            : 'Nenhum produto cadastrado ainda'}
                    </span>
                    <button onClick={onOpenProdutos} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] hover:brightness-110 transition-all">
                        Abrir Planilha
                    </button>
                </div>
            ) : item.tipo === 'precificacao' ? (
                <div className="mt-3 flex items-center justify-between p-4 rounded-xl border border-white/[0.08] bg-white/[0.02]">
                    <span className="text-white/50 text-[13px]">
                        {(dado?.produtos ?? []).length > 0
                            ? `${dado.produtos.length} produto${dado.produtos.length !== 1 ? 's' : ''} com custo informado`
                            : 'Nenhum produto precificado ainda'}
                    </span>
                    <button onClick={onOpenPrecificacao} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] hover:brightness-110 transition-all">
                        Abrir Precificação
                    </button>
                </div>
            ) : (
                <ItemInput item={item} dado={dado} linksAdmin={linksAdmin} onChange={onChange} />
            )}

            {/* Checkbox de feito — oculto para select_opcoes (feito se selecionou algo) */}
            {item.tipo !== 'select_opcoes' && (
                <div className="mt-4 pt-3 border-t border-white/[0.06]">
                    <label className="flex items-center gap-2.5 cursor-pointer group w-fit">
                        <div
                            onClick={() => onChange(item.id, 'feito', !feito, true)}
                            className={cn(
                                'w-5 h-5 rounded border-2 flex items-center justify-center transition-all cursor-pointer',
                                feito ? 'border-emerald-400 bg-emerald-400' : 'border-white/20 group-hover:border-emerald-400/50'
                            )}
                        >
                            {feito && <Check size={11} className="text-white" />}
                        </div>
                        <span className={cn('text-[13px] font-medium transition-colors', feito ? 'text-emerald-300' : 'text-white/40 group-hover:text-white/60')}>
                            {item.id === 'certificado_a1'    ? 'Sim, possuo Certificado A1'
                             : item.id === 'publicar_em_massa' ? 'Confirmar'
                             : 'Marcar como feito'}
                        </span>
                    </label>
                </div>
            )}
        </div>
    );
}

// ─── Indicador de salvamento ──────────────────────────────────────────────────

function SaveIndicator({ status }) {
    if (status === 'idle') return null;
    return (
        <div className={cn(
            'fixed bottom-5 right-5 flex items-center gap-2 px-3 py-2 rounded-xl text-[12px] font-medium',
            status === 'saving' && 'bg-white/10 text-white/60 border border-white/[0.08]',
            status === 'saved'  && 'bg-emerald-950/80 text-emerald-300 border border-emerald-500/20',
            status === 'error'  && 'bg-red-950/80 text-red-300 border border-red-500/20',
        )}>
            {status === 'saving' && <Save size={12} className="animate-pulse" />}
            {status === 'saved'  && <Check size={12} />}
            {status === 'error'  && <AlertCircle size={12} />}
            {status === 'saving' ? 'Salvando...' : status === 'saved' ? 'Salvo!' : 'Erro ao salvar'}
        </div>
    );
}

// ─── Página pública ───────────────────────────────────────────────────────────

function fmtPrazo(iso) {
    if (!iso) return null;
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

export default function ImplementacaoPublica({ impl, checklist, prazo_data = '' }) {
    const [dadosLocais, setDadosLocais]     = useState(impl.dados);
    const [progresso, setProgresso]         = useState(impl.progresso);
    const [saveStatus, setSaveStatus]       = useState('idle');
    const [video, setVideo]                 = useState(null);
    const [produtosOpen, setProdutosOpen]   = useState(false);
    const [precifOpen, setPrecifOpen]       = useState(false);
    const saveTimer = useRef(null);

    function playVideo(url, titulo) { setVideo({ url, titulo }); }

    function showSaved() {
        setSaveStatus('saved');
        clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(() => setSaveStatus('idle'), 2000);
    }

    const onChange = useCallback((id, campo, valor, doSave) => {
        setDadosLocais(prev => ({
            ...prev,
            itens: { ...prev.itens, [id]: { ...prev.itens[id], [campo]: valor } },
        }));

        if (!doSave) return;

        setSaveStatus('saving');
        axios.patch(route('implementacao.salvar', impl.token), { id, campo, valor })
            .then(res => { setProgresso(res.data.progresso); showSaved(); })
            .catch(() => {
                setSaveStatus('error');
                clearTimeout(saveTimer.current);
                saveTimer.current = setTimeout(() => setSaveStatus('idle'), 3000);
            });
    }, [impl.token]);

    const itens      = dadosLocais?.itens     ?? {};
    const tutoriais  = dadosLocais?.tutoriais  ?? {};
    const linksAdmin = dadosLocais?.links_admin ?? {};
    const introUrl   = dadosLocais?.tutorial_intro ?? '';

    return (
        <div className="min-h-screen bg-[#050507]">
            <ProgressHeader empresa_nome={impl.empresa_nome} progresso={progresso} />

            <div className="max-w-2xl mx-auto px-4 py-6 space-y-4">
                {/* Aviso de cronograma */}
                <div className="flex items-start gap-3 p-4 rounded-2xl border border-ecf-yellow/20 bg-ecf-yellow/[0.04]">
                    <AlertCircle size={16} className="text-ecf-yellow shrink-0 mt-0.5" />
                    <p className="text-white/60 text-[13px] leading-relaxed">
                        <span className="text-ecf-yellow font-semibold">Atenção:</span> todas as etapas devem ser concluídas antes{prazo_data ? <> de <span className="text-white font-semibold">{fmtPrazo(prazo_data)}</span></> : ' do prazo programado'} para mantermos o andamento da implementação. Projetos sem retorno dentro do prazo poderão ser descontinuados.
                    </p>
                </div>

                {/* Tutorial de introdução */}
                {introUrl && (
                    <div className="p-4 rounded-2xl border border-ecf-yellow/20 bg-ecf-yellow/5 flex items-center justify-between gap-4">
                        <div>
                            <p className="text-ecf-yellow font-semibold text-[14px]">Como preencher este formulário</p>
                            <p className="text-white/40 text-[12px] mt-0.5">Assista ao tutorial antes de começar</p>
                        </div>
                        <button
                            onClick={() => playVideo(introUrl, 'Como Preencher')}
                            className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] hover:brightness-110 transition-all shrink-0"
                        >
                            <BookOpen size={14} />
                            Assistir
                        </button>
                    </div>
                )}

                {checklist.map((item, idx) => (
                    <ChecklistItem
                        key={item.id}
                        item={item}
                        dado={itens[item.id] ?? {}}
                        tutorialUrl={tutoriais[item.id] ?? ''}
                        linksAdmin={linksAdmin}
                        onChange={onChange}
                        onPlay={playVideo}
                        onOpenProdutos={() => setProdutosOpen(true)}
                        onOpenPrecificacao={() => setPrecifOpen(true)}
                        num={String(idx + 1).padStart(2, '0')}
                    />
                ))}

                <div className="text-center py-6 space-y-1">
                    {progresso.pct === 100 ? (
                        <p className="text-emerald-400 font-semibold text-[15px]">Checklist completo! Aguarde o contato da ECF.</p>
                    ) : (
                        <p className="text-white/30 text-[13px]">Preencha todos os itens para concluir a implementação.</p>
                    )}
                    <p className="text-white/20 text-[11px]">Suas respostas são salvas automaticamente.</p>
                </div>
            </div>

            <SaveIndicator status={saveStatus} />

            {video && (
                <VideoModal url={video.url} titulo={video.titulo} onClose={() => setVideo(null)} />
            )}

            {produtosOpen && (
                <ProdutosModal
                    produtos={itens.planilha_produtos?.produtos ?? []}
                    onSave={arr => onChange('planilha_produtos', 'produtos', arr, true)}
                    onClose={() => setProdutosOpen(false)}
                />
            )}

            {precifOpen && (
                <PrecificacaoModal
                    dados={itens.precificacao ?? {}}
                    planilhaProdutos={itens.planilha_produtos?.produtos ?? []}
                    onSave={arr => onChange('precificacao', 'produtos', arr, true)}
                    onSaveCfg={(tier, val) => onChange('precificacao', tier, val, true)}
                    onClose={() => setPrecifOpen(false)}
                />
            )}
        </div>
    );
}
