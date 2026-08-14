import { useState, useRef, useCallback, useMemo, useEffect } from 'react';
import axios from 'axios';
import { Check, BookOpen, Save, AlertCircle, X, ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SpreadsheetGrid } from '@/Components/SpreadsheetGrid';
import {
    PRECIF_LINHA_VAZIA,
    mesclarPrecificacaoComPlanilha,
    criarTesteDaPlanilha,
} from '@/lib/precificacaoProdutos';

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
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">ECF Consultoria · Onboarding</p>
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

// ─── Passo a passo em texto (App ECF / Adman) ────────────────────────────────

// Conteúdo fixo do passo a passo de cadastro na Adman, exibido no card do item
// App ECF. É institucional (não varia por empresa), então mora no front.
const ADMAN_PASSO_A_PASSO = {
    titulo:   'Passo a passo para cadastro na Adman',
    saudacao: 'Olá! Para realizar seu cadastro na Adman, o processo é bem simples:',
    passos: [
        'Acesse o link de criação de conta da Adman.',
        'Clique em "Criar uma conta".',
        'Preencha os dados solicitados no cadastro.',
        'Antes de fazer o vínculo com o Mercado Livre, confirme que você está logado no mesmo navegador/Chrome com a conta principal do Mercado Livre que participará do projeto.',
        'Faça o vínculo da Adman com essa conta do Mercado Livre.',
    ],
    atencao: 'O vínculo precisa ser feito com a conta do Mercado Livre participante do projeto, e não com uma conta pessoal ou outra conta que não será utilizada no projeto. Por isso, antes de acessar o link da Adman, abra o Mercado Livre no mesmo Chrome e confirme se está logado na conta correta.',
};

// Botão discreto que abre o passo a passo em texto. Usa ecf-yellow para se
// distinguir do TutorialBtn (vídeo, vermelho). Renderizado só no item app_ecf.
function PassoAPassoBtn({ onClick }) {
    return (
        <button
            onClick={onClick}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[11px] font-medium transition-all"
        >
            <BookOpen size={11} />
            Passo a passo
        </button>
    );
}

// Modal sobreposto com o tutorial em texto: saudação + passos numerados + caixa
// de atenção. Fecha no X ou no clique do backdrop (mesmo padrão do VideoModal).
function PassoAPassoModal({ conteudo, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm" onClick={onClose}>
            <div
                className="w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-2xl border border-white/[0.08] bg-[#0f1116] shadow-2xl"
                onClick={e => e.stopPropagation()}
            >
                {/* Cabeçalho fixo */}
                <div className="sticky top-0 flex items-center justify-between gap-3 px-5 py-4 border-b border-white/[0.06] bg-[#0f1116]">
                    <p className="text-white font-semibold text-[15px]">{conteudo.titulo}</p>
                    <button onClick={onClose} className="p-1.5 text-white/40 hover:text-white transition-colors shrink-0">
                        <X size={18} />
                    </button>
                </div>

                {/* Corpo */}
                <div className="px-5 py-4 space-y-4">
                    <p className="text-white/70 text-[13px] leading-relaxed">{conteudo.saudacao}</p>

                    <ol className="space-y-2.5">
                        {conteudo.passos.map((passo, i) => (
                            <li key={i} className="flex items-start gap-3">
                                <span className="flex items-center justify-center w-6 h-6 rounded-full bg-ecf-yellow/15 text-ecf-yellow text-[12px] font-bold shrink-0">
                                    {i + 1}
                                </span>
                                <span className="text-white/70 text-[13px] leading-relaxed pt-0.5">{passo}</span>
                            </li>
                        ))}
                    </ol>

                    {/* Caixa de atenção — vínculo precisa ser na conta ML do projeto */}
                    <div className="flex items-start gap-2.5 p-3.5 rounded-xl bg-amber-500/[0.08] border border-amber-500/25">
                        <AlertCircle size={16} className="text-amber-400 shrink-0 mt-0.5" />
                        <div>
                            <p className="text-amber-300 font-semibold text-[11px] uppercase tracking-wider mb-1">Atenção</p>
                            <p className="text-white/70 text-[13px] leading-relaxed">{conteudo.atencao}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Tabela de produtos ───────────────────────────────────────────────────────

// Eixos de variação aceitos (espelham os atributos de variação do ML: COLOR, SIZE…).
const VARIACAO_TIPOS = ['Cor', 'Tamanho', 'Voltagem', 'Material', 'Sabor', 'Outro'];

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
    // Variação: linhas com o MESMO "Grupo variação" viram um único anúncio no
    // Mercado Livre, diferenciadas pelo valor (ex: Cor = Azul / Cor = Preta).
    { id: 'variacao_grupo', label: 'Grupo variação',  type: 'text',   width: 130 },
    { id: 'variacao_tipo',  label: 'Tipo variação',   type: 'select', options: VARIACAO_TIPOS, width: 120 },
    { id: 'variacao_valor', label: 'Valor variação',  type: 'text',   width: 120 },
];

// Campo de texto/textarea/select rotulado (cadastro guiado de produto).
function CampoTexto({ label, dica, value, onChange, placeholder, tipo, opcoes, destaque = false }) {
    // `destaque` marca o campo que o cliente ainda precisa preencher (borda âmbar).
    const borda = destaque ? 'border-amber-500/40' : 'border-white/[0.1]';
    return (
        <div>
            <label className="text-white/60 text-[12px] font-medium block mb-1">
                {label}{dica && <span className="text-white/30 font-normal"> · {dica}</span>}
            </label>
            {tipo === 'select' ? (
                <CustomSelect value={value || opcoes[0]} onChange={onChange} opcoes={opcoes} className="w-full" />
            ) : tipo === 'textarea' ? (
                <textarea value={value ?? ''} onChange={e => onChange(e.target.value)} placeholder={placeholder} rows={3}
                    className={cn('w-full px-3 py-2 rounded-xl bg-white/[0.04] border text-white text-sm focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 resize-none', borda)} />
            ) : (
                <input value={value ?? ''} onChange={e => onChange(e.target.value)} placeholder={placeholder}
                    className={cn('w-full h-10 px-3 rounded-xl bg-white/[0.04] border text-white text-sm focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20', borda)} />
            )}
        </div>
    );
}

// Grupos de campos do cadastro guiado (espelha PRODUTOS_COLS, mais humano).
const PROD_GRUPOS = [
    { titulo: 'Identificação', cols: 3, campos: [
        { id: 'sku',     label: 'SKU',  dica: 'código', ph: 'ex: CAD-001' },
        { id: 'produto', label: 'Nome do produto', ph: 'ex: Cadeira Gamer Preta' },
        { id: 'curva',   label: 'Curva', tipo: 'select', opcoes: ['Curva A', 'Curva B', 'Curva C'] },
    ] },
    { titulo: 'Medidas do produto', cols: 4, campos: [
        { id: 'altura',       label: 'Altura',       dica: 'cm' },
        { id: 'largura',      label: 'Largura',      dica: 'cm' },
        { id: 'profundidade', label: 'Profundidade', dica: 'cm' },
        { id: 'peso_kg',      label: 'Peso',         dica: 'kg' },
    ] },
    { titulo: 'Medidas da embalagem', cols: 4, campos: [
        { id: 'altura_emb',  label: 'Altura',       dica: 'cm' },
        { id: 'largura_emb', label: 'Largura',      dica: 'cm' },
        { id: 'prof_emb',    label: 'Profundidade', dica: 'cm' },
        { id: 'peso_emb_kg', label: 'Peso',         dica: 'kg' },
    ] },
    { titulo: 'Disponibilidade', cols: 4, campos: [
        { id: 'estoque', label: 'Estoque', dica: 'unidades' },
    ] },
];

const PROD_VAZIO = { curva: 'Curva A', sku: '', produto: '', altura: '', largura: '', profundidade: '', peso_kg: '',
    altura_emb: '', largura_emb: '', prof_emb: '', peso_emb_kg: '', estoque: '', especificacoes: '', descricao: '',
    variacao_grupo: '', variacao_tipo: '', variacao_valor: '' };

// Obrigatórios do PRODUTO (valem para o anúncio inteiro) e da VARIAÇÃO (por linha).
const PROD_OBRIG_ITEM     = ['produto', 'altura', 'largura', 'profundidade', 'peso_kg', 'descricao'];
const PROD_OBRIG_VARIACAO = ['sku', 'estoque'];

const vazio = (v) => String(v ?? '').trim() === '';

function faltamDoProduto(p) {
    return PROD_OBRIG_ITEM.filter(k => vazio(p[k]));
}
function faltamDaVariacao(p) {
    const faltam = PROD_OBRIG_VARIACAO.filter(k => vazio(p[k]));
    // Quem faz parte de um grupo de variação precisa dizer QUAL variação é.
    if (!vazio(p.variacao_grupo) && vazio(p.variacao_valor)) {
        faltam.push(String(p.variacao_tipo || 'variação').toLowerCase());
    }
    return faltam;
}
function faltamCampos(p) {
    return [...faltamDoProduto(p), ...faltamDaVariacao(p)];
}

/** Gera um id de grupo de variação estável (não depende do SKU, que o cliente edita). */
function novoGrupoId() {
    return 'v' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4);
}

// Campos que pertencem a CADA variação. Todo o resto (nome, medidas, descrição…)
// é do produto e vale para o grupo inteiro — mesmo modelo do Mercado Livre, onde
// o anúncio é um só e a variação carrega apenas SKU, estoque e a combinação.
const VARIACAO_CAMPOS = ['sku', 'estoque', 'variacao_valor'];

// Chip de um produto no catálogo do modo Guiado. Um grupo de variações ocupa UM
// chip só — as variações são editadas dentro da tela do produto.
function ChipProduto({ nome, faltam, sub, ativo, onSel, onDel }) {
    return (
        <div onClick={onSel}
            className={cn('group relative rounded-xl border pl-3 pr-7 py-2 transition cursor-pointer',
                ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/[0.08]' : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.05]')}>
            <p className="text-white/90 text-[13px] font-medium truncate max-w-[170px]">{nome}</p>
            <p className="text-[11px]">
                {sub && <span className="text-violet-300/70">{sub}</span>}
                {sub && ' · '}
                <span className={faltam === 0 ? 'text-green-400/80' : 'text-amber-400/80'}>
                    {faltam === 0 ? '✓ Completo' : `Faltam ${faltam}`}
                </span>
            </p>
            <button type="button" title="Excluir"
                onClick={e => { e.stopPropagation(); onDel(); }}
                className="absolute top-1.5 right-1.5 text-white/25 hover:text-red-400 opacity-0 group-hover:opacity-100 transition">
                <X size={13} />
            </button>
        </div>
    );
}

function TabelaProdutos({ produtos, onSave }) {
    // Remove linhas completamente vazias do final (dados antigos salvos em excesso)
    const trimmed = [...produtos];
    while (trimmed.length > 0) {
        const last = trimmed[trimmed.length - 1];
        const hasData = PRODUTOS_COLS.some(c => String(last[c.id] ?? '').trim() !== '' && last[c.id] !== c.options?.[0]);
        if (!hasData) trimmed.pop(); else break;
    }
    const [rows, setRows] = useState(trimmed);
    const [view, setView] = useState('guiado');
    const [selIdx, setSelIdx] = useState(0);
    const [saving, setSaving] = useState(false);
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
        setSaving(true);
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => { onSaveRef.current(newRows); pendingRef.current = null; setSaving(false); }, 800);
    }

    const grupoDe = (r) => String(r?.variacao_grupo ?? '').trim();

    /**
     * Edita um campo do produto selecionado. Campo COMPARTILHADO (nome, medidas,
     * descrição…) vale para o grupo inteiro — o anúncio é um só; campo de
     * variação (SKU, estoque, valor) fica na linha dela.
     */
    function editProduto(campo, valor) {
        const base = rows.length > 0 ? rows : [{ ...PROD_VAZIO }];
        const idx  = Math.min(selIdx, Math.max(0, base.length - 1));
        const grupo = grupoDe(base[idx]);
        const propaga = grupo !== '' && !VARIACAO_CAMPOS.includes(campo);
        handleChange(base.map((r, i) =>
            i === idx || (propaga && grupoDe(r) === grupo) ? { ...r, [campo]: valor } : r));
    }
    /** Edita um campo de UMA variação (SKU, estoque, valor). */
    function editVariacao(idx, campo, valor) {
        handleChange(rows.map((r, i) => i === idx ? { ...r, [campo]: valor } : r));
    }
    function addProduto() {
        const novo = [...rows, { ...PROD_VAZIO }];
        handleChange(novo);
        setSelIdx(novo.length - 1);
    }
    function delProduto(idx) {
        const novo = rows.filter((_, i) => i !== idx);
        handleChange(novo);
        setSelIdx(s => Math.max(0, Math.min(s >= idx ? s - 1 : s, novo.length - 1)));
    }
    /** Exclui o produto inteiro, com todas as suas variações. */
    function delGrupo(grupo) {
        const novo = rows.filter(r => grupoDe(r) !== grupo);
        handleChange(novo);
        setSelIdx(s => Math.max(0, Math.min(s, novo.length - 1)));
    }

    /**
     * Acrescenta uma variação ao produto selecionado. Na primeira vez o próprio
     * produto vira a variação 1 e uma segunda é criada junto — variação sozinha
     * não existe. As linhas copiam tudo do produto e zeram só o que muda.
     */
    function addVariacao() {
        const base = rows[selIdx];
        if (!base) return;
        const grupoNovo = grupoDe(base) === '';
        const grupo = grupoNovo ? novoGrupoId() : grupoDe(base);
        const tipo  = base.variacao_tipo || 'Cor';

        const arr = rows.map((r, i) => i === selIdx ? { ...r, variacao_grupo: grupo, variacao_tipo: tipo } : r);
        let ultimo = selIdx;
        arr.forEach((r, i) => { if (grupoDe(r) === grupo) ultimo = i; });
        // A nova entra depois da última do grupo — a inserção é sempre à frente de
        // selIdx, então o produto aberto continua sendo o mesmo (sem trocar de tela).
        arr.splice(ultimo + 1, 0, { ...base, sku: '', estoque: '', variacao_grupo: grupo, variacao_tipo: tipo, variacao_valor: '' });

        handleChange(arr);
    }

    /** Troca o eixo da variação (Cor → Tamanho…) em TODAS as linhas do grupo. */
    function setTipoGrupo(grupo, tipo) {
        handleChange(rows.map(r => grupoDe(r) === grupo ? { ...r, variacao_tipo: tipo } : r));
    }

    /** Remove UMA variação. Sobrando uma só, o grupo se desfaz e vira produto normal. */
    function delVariacao(idx) {
        const grupo = grupoDe(rows[idx]);
        let arr = rows.filter((_, i) => i !== idx);
        if (arr.filter(r => grupoDe(r) === grupo).length <= 1) {
            arr = arr.map(r => grupoDe(r) === grupo
                ? { ...r, variacao_grupo: '', variacao_tipo: '', variacao_valor: '' } : r);
        }
        handleChange(arr);
        setSelIdx(s => Math.max(0, Math.min(s > idx ? s - 1 : s, arr.length - 1)));
    }

    /** Desfaz o grupo inteiro: as variações voltam a ser produtos independentes. */
    function desfazerGrupo(grupo) {
        handleChange(rows.map(r => grupoDe(r) === grupo
            ? { ...r, variacao_grupo: '', variacao_tipo: '', variacao_valor: '' } : r));
    }

    // Um chip por produto: o grupo de variações ocupa UM chip só.
    const chips = [];
    const posGrupo = new Map();
    rows.forEach((r, i) => {
        const g = grupoDe(r);
        if (!g) { chips.push({ tipo: 'solo', idx: i }); return; }
        if (!posGrupo.has(g)) { posGrupo.set(g, chips.length); chips.push({ tipo: 'grupo', chave: g, idxs: [i] }); }
        else chips[posGrupo.get(g)].idxs.push(i);
    });

    const row      = rows[selIdx] ?? PROD_VAZIO;
    const rowGrupo = grupoDe(row);
    // Linhas do grupo do produto aberto — cada uma vira um card de variação.
    const variacoes = rowGrupo ? rows.map((r, i) => ({ r, i })).filter(({ r }) => grupoDe(r) === rowGrupo) : [];
    const podeVariar = rows.length > 0 && (String(row.produto ?? '').trim() !== '' || String(row.sku ?? '').trim() !== '');
    // Com variações, SKU e estoque saem do formulário do produto e vão para os cards.
    const gruposForm = PROD_GRUPOS
        .map(g => ({ ...g, campos: g.campos.filter(c => !(rowGrupo && VARIACAO_CAMPOS.includes(c.id))) }))
        .filter(g => g.campos.length > 0);

    return (
        <div className="mt-3 space-y-4">
            {/* Toggle de modo */}
            <div className="flex items-center justify-between gap-3">
                <div className="inline-flex rounded-xl bg-white/[0.04] border border-white/[0.08] p-1">
                    {[{ k: 'guiado', l: '🎯 Guiado' }, { k: 'lote', l: '📊 Lote' }].map(({ k, l }) => (
                        <button key={k} type="button" onClick={() => setView(k)}
                            className={cn('px-4 py-1.5 rounded-lg text-[13px] font-semibold transition',
                                view === k ? 'bg-white/[0.1] text-white' : 'text-white/45 hover:text-white/75')}>
                            {l}
                        </button>
                    ))}
                </div>
                <span className="text-[11px] text-white/35 inline-flex items-center gap-1.5">
                    <span className={cn('h-1.5 w-1.5 rounded-full', saving ? 'bg-ecf-yellow animate-pulse' : 'bg-green-400')} />
                    {saving ? 'Salvando…' : 'Salvo automaticamente'}
                </span>
            </div>

            {view === 'lote' ? (
                <>
                    <SpreadsheetGrid columns={PRODUTOS_COLS} rows={rows} onChange={handleChange} minRows={10} exportFilename="produtos" showImportExport={false} />
                    <p className="mt-2 text-white/20 text-[11px]">
                        Dica: arraste o quadrado azul no canto da célula para preencher · Ctrl+C/V para copiar e colar
                    </p>
                    <p className="mt-1 text-white/20 text-[11px]">
                        Variações: repita o mesmo texto em <span className="text-violet-300/60">Grupo variação</span> nas
                        linhas do mesmo produto e mude só o <span className="text-violet-300/60">Valor variação</span> (ex: Azul, Preta).
                        Elas viram um anúncio só. Quem não tem variação deixa em branco.
                    </p>
                </>
            ) : (
                <div className="space-y-4">
                    {/* Catálogo de chips */}
                    <div>
                        <p className="text-white/40 text-[11px] uppercase tracking-wider mb-2">Meus produtos ({rows.length})</p>
                        <div className="flex flex-wrap items-start gap-2">
                            {chips.map((c, ci) => c.tipo === 'solo' ? (
                                <ChipProduto key={`s${ci}`} nome={rows[c.idx].produto || rows[c.idx].sku || `Produto ${c.idx + 1}`}
                                    faltam={faltamCampos(rows[c.idx]).length} ativo={c.idx === selIdx}
                                    onSel={() => setSelIdx(c.idx)} onDel={() => delProduto(c.idx)} />
                            ) : (
                                <ChipProduto key={`g${ci}`} nome={rows[c.idxs[0]].produto || `Produto ${c.idxs[0] + 1}`}
                                    sub={`${c.idxs.length} ${(rows[c.idxs[0]].variacao_tipo || 'variação').toLowerCase()}${c.idxs.length > 1 ? 's' : ''}`}
                                    faltam={c.idxs.reduce((t, i) => t + faltamCampos(rows[i]).length, 0)}
                                    ativo={c.idxs.includes(selIdx)}
                                    onSel={() => setSelIdx(c.idxs[0])} onDel={() => delGrupo(c.chave)} />
                            ))}
                            <button type="button" onClick={addProduto}
                                className="rounded-xl border border-dashed border-white/[0.15] px-4 py-2 text-white/50 hover:text-white hover:border-white/30 text-[13px] font-medium transition">
                                + Adicionar produto
                            </button>
                        </div>
                    </div>

                    {/* Formulário do produto selecionado */}
                    <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-5">
                        {/* Com variações, SKU e estoque são cobrados dentro de cada card, não aqui. */}
                        {(rowGrupo ? faltamDoProduto(row) : faltamCampos(row)).length > 0 && (
                            <div className="flex items-start gap-2 rounded-lg bg-amber-500/[0.08] border border-amber-500/20 px-3 py-2">
                                <span className="text-amber-300 text-[12px]">Faltam preencher: <span className="font-semibold">
                                    {(rowGrupo ? faltamDoProduto(row) : faltamCampos(row)).join(', ')}
                                </span></span>
                            </div>
                        )}
                        {gruposForm.map(g => (
                            <div key={g.titulo}>
                                <p className="text-white/40 text-[11px] uppercase tracking-wider mb-2">{g.titulo}</p>
                                <div className={cn('grid gap-3', g.cols === 3 ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-2 sm:grid-cols-4')}>
                                    {g.campos.map(c => (
                                        <CampoTexto key={c.id} label={c.label} dica={c.dica} placeholder={c.ph} tipo={c.tipo} opcoes={c.opcoes}
                                            value={row[c.id]} onChange={v => editProduto(c.id, v)} />
                                    ))}
                                </div>
                            </div>
                        ))}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <CampoTexto label="Especificações técnicas" tipo="textarea" placeholder="material, cor, voltagem, garantia…"
                                value={row.especificacoes} onChange={v => editProduto('especificacoes', v)} />
                            <CampoTexto label="Descrição" tipo="textarea" placeholder="texto que vai no anúncio"
                                value={row.descricao} onChange={v => editProduto('descricao', v)} />
                        </div>

                        {/* Variações — mesmo produto, muda só a cor/tamanho. Um anúncio só. */}
                        <div className={cn('rounded-xl border p-4',
                            rowGrupo ? 'border-violet-500/25 bg-violet-500/[0.05]' : 'border-white/[0.08] bg-white/[0.02]')}>
                            <div className="flex flex-wrap items-center justify-between gap-3 mb-1">
                                <p className={cn('text-[11px] uppercase tracking-wider', rowGrupo ? 'text-violet-300/80' : 'text-white/40')}>
                                    Variações{rowGrupo && ` · ${variacoes.length}`}
                                </p>
                                {rowGrupo && (
                                    <div className="flex items-center gap-2">
                                        <span className="text-white/40 text-[12px]">O que muda:</span>
                                        <CustomSelect small value={row.variacao_tipo || 'Cor'} opcoes={VARIACAO_TIPOS}
                                            onChange={v => setTipoGrupo(rowGrupo, v)} />
                                    </div>
                                )}
                            </div>

                            {!rowGrupo ? (
                                <>
                                    <p className="text-white/40 text-[12px] mb-3">
                                        É o mesmo produto mudando só a cor, o tamanho, a voltagem? Cadastre aqui — as
                                        variações viram <span className="text-white/70 font-semibold">um anúncio só</span> no
                                        Mercado Livre e reaproveitam tudo que você já preencheu acima.
                                    </p>
                                    <button type="button" onClick={addVariacao} disabled={!podeVariar}
                                        title={podeVariar ? '' : 'Preencha o nome do produto primeiro'}
                                        className={cn('rounded-xl border border-dashed px-4 py-2 text-[13px] font-medium transition',
                                            podeVariar
                                                ? 'border-violet-500/30 text-violet-300/80 hover:text-violet-200 hover:border-violet-400/50'
                                                : 'border-white/[0.08] text-white/20 cursor-not-allowed')}>
                                        + Este produto tem variações
                                    </button>
                                </>
                            ) : (
                                <>
                                    <p className="text-white/40 text-[12px] mb-3">
                                        Tudo que você preencheu acima vale para todas. Aqui só o que muda de uma para outra.
                                    </p>
                                    <div className="space-y-2">
                                        {variacoes.map(({ r, i }, ord) => {
                                            const faltam = faltamDaVariacao(r);
                                            return (
                                                <div key={i} className="rounded-xl border border-white/[0.08] bg-white/[0.03] p-3">
                                                    <div className="flex items-center justify-between gap-2 mb-2">
                                                        <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wider">
                                                            Variação {ord + 1}
                                                            {faltam.length > 0 && <span className="text-amber-400/80 font-normal normal-case tracking-normal"> · falta {faltam.join(', ')}</span>}
                                                        </p>
                                                        <button type="button" title="Excluir variação" onClick={() => delVariacao(i)}
                                                            className="text-white/25 hover:text-red-400 transition"><X size={13} /></button>
                                                    </div>
                                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                        <CampoTexto label={r.variacao_tipo || 'Valor'}
                                                            placeholder={r.variacao_tipo === 'Tamanho' ? 'ex: M' : 'ex: Azul'}
                                                            destaque={vazio(r.variacao_valor)}
                                                            value={r.variacao_valor} onChange={v => editVariacao(i, 'variacao_valor', v)} />
                                                        <CampoTexto label="SKU" dica="código desta variação" placeholder="ex: CAD-001-AZ"
                                                            destaque={vazio(r.sku)}
                                                            value={r.sku} onChange={v => editVariacao(i, 'sku', v)} />
                                                        <CampoTexto label="Estoque" dica="unidades"
                                                            destaque={vazio(r.estoque)}
                                                            value={r.estoque} onChange={v => editVariacao(i, 'estoque', v)} />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                    <div className="flex flex-wrap gap-2 mt-3">
                                        <button type="button" onClick={addVariacao}
                                            className="rounded-xl border border-dashed border-violet-500/30 px-4 py-2 text-violet-300/80 hover:text-violet-200 hover:border-violet-400/50 text-[13px] font-medium transition">
                                            + Adicionar variação
                                        </button>
                                        <button type="button" onClick={() => desfazerGrupo(rowGrupo)}
                                            title="As variações voltam a ser produtos separados"
                                            className="rounded-xl border border-white/[0.1] px-4 py-2 text-white/40 hover:text-white hover:border-white/25 text-[13px] transition">
                                            Não tem variação
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
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

// Preço = (Custo + Frete) / (1 − Comissão − Imposto − Margem de Contribuição − Lucro Líquido)
// (modelo da planilha ARB — MC e Lucro Líquido são dois alvos configuráveis no divisor)
function calcPreco(custo, frete, comissao, imposto, mc, ll) {
    const divisor = 1 - comissao - imposto - mc - ll;
    if (divisor <= 0 || isNaN(custo) || custo === '') return null;
    return (parseFloat(custo) + parseFloat(frete)) / divisor;
}

function fmt(n) {
    if (n === null || isNaN(n)) return '—';
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

// % é sobre o CUSTO; se custo inválido/0, mostra só o R$
function fmtRpct(valor, custo) {
    if (valor === null || valor === undefined || isNaN(valor)) return '—';
    const r = fmt(valor);
    const c = parseFloat(custo);
    if (!c || isNaN(c) || c <= 0) return r;
    return `${r} · ${((valor / c) * 100).toFixed(1)}%`;
}

// Campo grande com rótulo + microcopy (linguagem para leigos).
// `invalido`: quando true, o campo pisca em vermelho (borda + rótulo) até receber um valor — usado p/ frete obrigatório não preenchido.
function CampoValor({ label, dica, valor, onChange, prefixo = 'R$', invalido = false }) {
    return (
        <div>
            {label && <label className={`text-[13px] font-medium block ${invalido ? 'text-red-400' : 'text-white/70'}`}>{label}</label>}
            {dica && <p className="text-white/35 text-[11px] mb-1.5 mt-0.5">{dica}</p>}
            <div className={`flex items-center rounded-xl bg-white/[0.04] border ${invalido ? 'border-red-500 animate-blink-red' : 'border-white/[0.1] focus-within:border-ecf-yellow/40 transition-colors'}`}>
                <span className={`pl-3 text-sm ${invalido ? 'text-red-400' : 'text-white/30'}`}>{prefixo}</span>
                <input
                    type="number" step="0.01" min="0" inputMode="decimal"
                    value={valor}
                    onChange={e => onChange(e.target.value)}
                    placeholder="0,00"
                    className="w-full h-11 px-2 bg-transparent border-0 focus:ring-0 text-white text-base font-medium focus:outline-none placeholder:text-white/20"
                />
            </div>
        </div>
    );
}

/**
 * SimuladorPreco — modo guiado (1 produto por vez) da precificação.
 * Reaproveita a mesma lógica (calcPreco) e persistência (onEditProduto/cfg),
 * mas apresenta de forma humana: entrada simples + resultado em destaque +
 * composição visual do preço + semáforo de saúde da margem.
 */
// Simulador recebe modoImposto, impostoEfetivo, mcEfetivo e llEfetivo para calcular por produto
// (modo individual) ou com os valores globais do tier (modo massa — comportamento original).
function SimuladorPreco({ produtos, selIdx, setSelIdx, tier, setTier, cc, cp, acrNum, mcNum, llNum, onEditProduto, onAddProduto, onDeleteProduto, cfg, updateCfg, updateMC, updateLL, updateAcrescimo, saving, tabelaFreteUrl, modoImposto, impostoEfetivo, mcEfetivo, llEfetivo, vemDaPlanilha }) {
    // Parâmetros avançados já vêm EXPOSTOS por padrão (sem exigir clique) para
    // aumentar a chance de o cliente conferir/preencher. O toggle segue disponível
    // para recolher quem quiser.
    const [avancado, setAvancado] = useState(true);

    const row        = produtos[selIdx] ?? { custo: '', frete_classico: '', frete_premium: '' };
    // Produto que veio da Planilha de Produtos: SKU e nome são só reflexo dela.
    const daPlanilha = produtos.length > 0 && vemDaPlanilha?.(row);
    const t          = tier === 'classico' ? cc : cp;
    const freteCampo = tier === 'classico' ? 'frete_classico' : 'frete_premium';
    const custoN     = parseFloat(row.custo || 0) || 0;
    const freteN     = parseFloat(row[freteCampo] || 0) || 0;

    // No modo individual usa o imposto_individual do produto; no massa usa o imposto do tier.
    const impostoSimulador = impostoEfetivo(row, t.imposto);
    // MC e LL efetivos: no individual usa o valor do produto se preenchido; vazio herda o global.
    const mcEfetivoRow = mcEfetivo(row);
    const llEfetivoRow = llEfetivo(row);
    const preco      = calcPreco(row.custo, freteN, t.comissao, impostoSimulador, mcEfetivoRow, llEfetivoRow); // Preço C/ Desconto
    const anunciado  = preco ? preco * (1 + acrNum) : null;
    const comissaoRs = preco ? preco * t.comissao : 0;
    const impostoRs  = preco ? preco * impostoSimulador  : 0;
    const mcRs       = preco ? preco * mcEfetivoRow : 0;   // Margem de Contribuição R$
    const llRs       = preco ? preco * llEfetivoRow : 0;   // Lucro Líquido R$

    const comp = preco ? [
        { label: 'Custo do produto',      valor: custoN,     cor: '#64748b' },
        { label: 'Frete',                 valor: freteN,     cor: '#0ea5e9' },
        { label: 'Comissão do site',      valor: comissaoRs, cor: '#a855f7' },
        { label: 'Impostos',              valor: impostoRs,  cor: '#f97316' },
        { label: 'Margem de contribuição',valor: mcRs,       cor: '#38bdf8' },
        { label: 'Lucro líquido',         valor: llRs,       cor: '#22c55e' },
    ] : [];

    const cfgTier = cfg[tier];
    const corTier = tier === 'classico' ? 'text-blue-300' : 'text-violet-300';

    return (
        <div className="mx-auto max-w-5xl space-y-5">
            {/* Catálogo de produtos (chips) + adicionar + tier + status de salvo */}
            <div className="space-y-3">
                <div className="flex items-center justify-between gap-3">
                    <p className="text-white/40 text-[11px] uppercase tracking-wider">Meus produtos ({produtos.length})</p>
                    <div className="flex items-center gap-3">
                        <span className="text-[11px] text-white/35 inline-flex items-center gap-1.5">
                            <span className={cn('h-1.5 w-1.5 rounded-full', saving ? 'bg-ecf-yellow animate-pulse' : 'bg-green-400')} />
                            {saving ? 'Salvando…' : 'Salvo automaticamente'}
                        </span>
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
                </div>
                <div className="flex flex-wrap gap-2">
                    {produtos.map((p, i) => {
                        const f  = parseFloat(p[freteCampo] || 0) || 0;
                        // Chips do catálogo: usa imposto/MC/LL efetivos do produto (individual) ou globais (massa)
                        const pr = calcPreco(p.custo, f, t.comissao, impostoEfetivo(p, t.imposto), mcEfetivo(p), llEfetivo(p));
                        const ativo = i === selIdx;
                        return (
                            <div key={i} onClick={() => setSelIdx(i)}
                                className={cn('group relative rounded-xl border pl-3 pr-7 py-2 transition cursor-pointer',
                                    ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/[0.08]' : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.05]')}>
                                <p className="text-white/90 text-[13px] font-medium truncate max-w-[170px]">{p.descricao || p.sku || `Produto ${i + 1}`}</p>
                                <p className="text-white/40 text-[11px]">{pr ? fmt(pr) : 'sem preço'}{p.sku ? ` · ${p.sku}` : ''}</p>
                                <button type="button" title="Excluir produto"
                                    onClick={e => { e.stopPropagation(); onDeleteProduto(i); }}
                                    className="absolute top-1.5 right-1.5 text-white/25 hover:text-red-400 opacity-0 group-hover:opacity-100 transition">
                                    <X size={13} />
                                </button>
                            </div>
                        );
                    })}
                    <button type="button" onClick={onAddProduto}
                        className="rounded-xl border border-dashed border-white/[0.15] px-4 py-2 text-white/50 hover:text-white hover:border-white/30 text-[13px] font-medium transition self-stretch">
                        + Adicionar produto
                    </button>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                {/* ── Entrada ── */}
                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-4">
                    <p className="text-white/40 text-[11px] uppercase tracking-wider">Identificação do produto</p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label className="text-white/70 text-[13px] font-medium block mb-1">SKU <span className="text-white/30 font-normal">(código)</span></label>
                            {daPlanilha ? (
                                <div className="w-full h-10 px-3 rounded-xl bg-white/[0.02] border border-white/[0.06] text-white/50 text-sm flex items-center truncate">{row.sku || '—'}</div>
                            ) : (
                                <input value={row.sku ?? ''} onChange={e => onEditProduto('sku', e.target.value)} placeholder="ex: CAD-001"
                                    className="w-full h-10 px-3 rounded-xl bg-white/[0.04] border border-white/[0.1] text-white text-sm focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20" />
                            )}
                        </div>
                        <div>
                            <label className="text-white/70 text-[13px] font-medium block mb-1">Descrição</label>
                            {daPlanilha ? (
                                <div className="w-full h-10 px-3 rounded-xl bg-white/[0.02] border border-white/[0.06] text-white/50 text-sm flex items-center truncate">{row.descricao || '—'}</div>
                            ) : (
                                <input value={row.descricao ?? ''} onChange={e => onEditProduto('descricao', e.target.value)} placeholder="ex: Cadeira Gamer Preta"
                                    className="w-full h-10 px-3 rounded-xl bg-white/[0.04] border border-white/[0.1] text-white text-sm focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20" />
                            )}
                        </div>
                    </div>
                    {/* Produto cadastrado na Planilha de Produtos: identificação é de lá, não daqui. */}
                    {daPlanilha && (
                        <p className="text-white/30 text-[11px] -mt-2">
                            SKU e nome vêm da <span className="text-white/50">Planilha de Produtos</span> — para alterar, edite lá.
                        </p>
                    )}

                    <p className="text-white/40 text-[11px] uppercase tracking-wider pt-1">Quanto você gasta</p>
                    <CampoValor label="Custo do produto" dica="Quanto você paga no produto (sem frete)."
                        valor={row.custo ?? ''} onChange={v => onEditProduto('custo', v)} />
                    {/* Wrapper do campo Frete: link "Tabela de Frete" ao lado do label + campo piscando vermelho enquanto o frete não for preenchido */}
                    <div>
                        {tabelaFreteUrl && (
                            <div className="flex items-center justify-between mb-1">
                                <span className={`text-[13px] font-medium ${freteN <= 0 ? 'text-red-400' : 'text-white/70'}`}>
                                    Frete ({tier === 'classico' ? 'Clássico' : 'Premium'})
                                </span>
                                <a
                                    href={tabelaFreteUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-ecf-yellow hover:text-ecf-yellow/80 text-[12px] font-medium transition-colors"
                                >
                                    <ExternalLink size={12} />
                                    Tabela de Frete
                                </a>
                            </div>
                        )}
                        {/* Frete vazio/zerado: campo pisca vermelho (invalido) até o cliente informar um valor > 0 */}
                        <CampoValor
                            label={tabelaFreteUrl ? '' : `Frete (${tier === 'classico' ? 'Clássico' : 'Premium'})`}
                            dica="Quanto custa enviar este produto."
                            valor={row[freteCampo] ?? ''}
                            onChange={v => onEditProduto(freteCampo, v)}
                            invalido={freteN <= 0}
                        />
                        {/* Aviso textual de reforço — aparece junto com o campo piscando enquanto o frete não for preenchido */}
                        {freteN <= 0 && (
                            <p className="mt-1.5 flex items-center gap-1.5 text-red-400 text-[12px] font-medium">
                                <AlertCircle size={13} className="shrink-0" />
                                Frete não inserido — informe o frete deste produto para calcular o preço correto.
                            </p>
                        )}
                    </div>

                    {/* Avançado (defaults já preenchidos) */}
                    <button type="button" onClick={() => setAvancado(v => !v)}
                        className="flex items-center gap-2 text-white/40 hover:text-white/70 text-[12px] pt-1 transition-colors">
                        <span className={cn('text-[10px] transition-transform', avancado && 'rotate-180')}>▼</span>
                        Ajustar parâmetros (avançado) — já preenchemos o padrão
                    </button>
                    {avancado && (
                        <div className="space-y-3 rounded-xl bg-white/[0.02] border border-white/[0.06] p-3">
                            <p className={cn('text-[11px] font-bold uppercase tracking-wider', corTier)}>{tier === 'classico' ? 'Clássico' : 'Premium'} — taxas do site</p>
                            <div className="grid grid-cols-2 gap-2">
                                {/* Comissão: sempre global por tier, em ambos os modos */}
                                <div title="o que o site cobra">
                                    <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Comissão %</label>
                                    <input type="number" step="0.01" min="0" value={cfgTier.comissao}
                                        onChange={e => updateCfg(tier, 'comissao', e.target.value)}
                                        className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                </div>
                                {/* Imposto: no modo massa edita o global do tier; no individual edita o imposto_individual do produto selecionado */}
                                {modoImposto === 'massa' ? (
                                    <div title="tributos sobre a venda (todos os produtos)">
                                        <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Imposto %</label>
                                        <input type="number" step="0.01" min="0" value={cfgTier.imposto}
                                            onChange={e => updateCfg(tier, 'imposto', e.target.value)}
                                            className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                    </div>
                                ) : (
                                    <div title="imposto deste produto (modo individual)">
                                        <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Imposto deste produto %</label>
                                        <input type="number" step="0.01" min="0"
                                            value={row.imposto_individual ?? ''}
                                            onChange={e => onEditProduto('imposto_individual', e.target.value)}
                                            className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                    </div>
                                )}
                            </div>
                            <p className="text-white/30 text-[10px] uppercase tracking-wider pt-1">Seus alvos (valem p/ Clássico e Premium)</p>
                            <div className="grid grid-cols-3 gap-2">
                                {/* Margem Contrib.: SEMPRE por produto (vazio = herda o padrão global, configurável no painel do Lote) */}
                                <div title="Margem de Contribuição deste produto (vazio = herda o padrão global)">
                                    <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Margem Contrib. %</label>
                                    <input type="number" step="0.01" min="0"
                                        value={row.mc_individual ?? ''}
                                        placeholder={String(cfg.margem_contribuicao ?? '')}
                                        onChange={e => onEditProduto('mc_individual', e.target.value)}
                                        className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                </div>
                                {/* Lucro Líquido: SEMPRE por produto (vazio = herda o padrão global, configurável no painel do Lote) */}
                                <div title="Lucro Líquido deste produto (vazio = herda o padrão global)">
                                    <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Lucro Líquido %</label>
                                    <input type="number" step="0.01" min="0"
                                        value={row.ll_individual ?? ''}
                                        placeholder={String(cfg.lucro_liquido ?? '')}
                                        onChange={e => onEditProduto('ll_individual', e.target.value)}
                                        className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                </div>
                                {/* Acréscimo: sempre global nos dois modos */}
                                <div title="quanto inflar o preço de tabela para depois dar desconto">
                                    <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Acréscimo %</label>
                                    <input type="number" step="0.01" min="0" value={cfg.acrescimo}
                                        onChange={e => updateAcrescimo(e.target.value)}
                                        className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* ── Resultado ── */}
                <div className="rounded-2xl border border-ecf-yellow/20 bg-gradient-to-b from-ecf-yellow/[0.06] to-transparent p-5 space-y-4">
                    {preco ? (
                        <>
                            <div>
                                <p className="text-white/40 text-[11px] uppercase tracking-wider">Preço de venda sugerido</p>
                                <p className="text-ecf-yellow font-display font-extrabold text-4xl mt-1 leading-none">{fmt(preco)}</p>
                                <p className="text-white/40 text-[12px] mt-1.5">
                                    Anuncie por <span className="text-white/70 font-semibold">{fmt(anunciado)}</span> e dê o desconto até {fmt(preco)}.
                                </p>
                            </div>

                            {/* Composição visual do preço */}
                            <div>
                                <p className="text-white/40 text-[11px] uppercase tracking-wider mb-1.5">Para onde vai cada real</p>
                                <div className="flex h-3 rounded-full overflow-hidden">
                                    {comp.map((c, i) => (
                                        <div key={i} title={`${c.label}: ${fmt(c.valor)}`} style={{ width: `${(c.valor / preco) * 100}%`, background: c.cor }} />
                                    ))}
                                </div>
                                <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                                    {comp.map((c, i) => (
                                        <div key={i} className="flex items-center gap-2 text-[12px]">
                                            <span className="h-2 w-2 rounded-full shrink-0" style={{ background: c.cor }} />
                                            <span className="text-white/50 flex-1">{c.label}</span>
                                            <span className="text-white/80 tabular-nums">{fmt(c.valor)}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Lucro líquido + margem de contribuição (valores configurados, sem julgar) */}
                            <div className="grid grid-cols-2 gap-3">
                                <div className="rounded-xl bg-green-500/[0.06] border border-green-500/20 p-3">
                                    <p className="text-white/40 text-[11px]">Lucro líquido por venda</p>
                                    <p className="text-green-400 font-bold text-lg mt-0.5">{fmt(llRs)}</p>
                                    <p className="text-white/30 text-[11px]">{(llEfetivoRow * 100).toFixed(1)}% do preço</p>
                                </div>
                                <div className="rounded-xl bg-sky-500/[0.06] border border-sky-500/20 p-3">
                                    <p className="text-white/40 text-[11px]">Margem de contribuição</p>
                                    <p className="text-sky-300 font-bold text-lg mt-0.5">{fmt(mcRs)}</p>
                                    <p className="text-white/30 text-[11px]">{(mcEfetivoRow * 100).toFixed(1)}% do preço</p>
                                </div>
                            </div>
                        </>
                    ) : (
                        <div className="flex flex-col items-center justify-center h-full text-center py-10">
                            <p className="text-white/50 text-sm">Informe o <span className="text-white/80">custo</span> e o <span className="text-white/80">frete</span> ao lado</p>
                            <p className="text-white/30 text-[12px] mt-1">e o preço de venda aparece aqui automaticamente.</p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// acréscimo global do Anunciado: único valor (não por tier), default 20%
// Margem de Contribuição e Lucro Líquido são ALVOS globais (default 0 — cliente
// escolhe). Comissão/Imposto por tier; Acréscimo global (markup p/ desconto).
const CFG_DEFAULT = {
    classico:  { comissao: 0.115, imposto: 0.19 },
    premium:   { comissao: 0.165, imposto: 0.19 },
    margem_contribuicao: 0,
    lucro_liquido: 0,
    acrescimo: 0.20,
};

// Frete obrigatório no lote: destaca em vermelho quando a linha tem dados (SKU/descrição/custo) mas o frete está vazio/zerado.
function freteFaltando(valor, row) {
    if (!row) return false;
    const temDados = String(row.sku ?? '').trim() || String(row.descricao ?? '').trim() || String(row.custo ?? '').trim();
    const semFrete = !(parseFloat(valor || 0) > 0);
    return Boolean(temDados) && semFrete;
}

function PrecificacaoModal({ dados, planilhaProdutos, onSave, onSaveCfg, onClose, tabelaFreteUrl }) {
    const cfgC   = dados.classico  ?? CFG_DEFAULT.classico;
    const cfgP   = dados.premium   ?? CFG_DEFAULT.premium;
    const acr    = dados.acrescimo           ?? CFG_DEFAULT.acrescimo;
    const mc0    = dados.margem_contribuicao ?? CFG_DEFAULT.margem_contribuicao;
    const ll0    = dados.lucro_liquido       ?? CFG_DEFAULT.lucro_liquido;

    const [cfg, setCfg] = useState({
        classico:  { comissao: (cfgC.comissao * 100).toFixed(2), imposto: (cfgC.imposto * 100).toFixed(2) },
        premium:   { comissao: (cfgP.comissao * 100).toFixed(2), imposto: (cfgP.imposto * 100).toFixed(2) },
        // alvos globais + acréscimo, como string percentual ("20.00") para os inputs
        margem_contribuicao: (mc0 * 100).toFixed(2),
        lucro_liquido:       (ll0 * 100).toFixed(2),
        acrescimo:           (acr * 100).toFixed(2),
    });
    const [cfgOpen, setCfgOpen] = useState(false);
    const [saving, setSaving]   = useState(false);
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
            });
            onSaveCfg('classico', toDecimal('classico'));
            onSaveCfg('premium',  toDecimal('premium'));
        }, 800);
    }

    // Parâmetros globais (margem_contribuicao, lucro_liquido, acrescimo) — persistem
    // via onSaveCfg(campo, decimal) → onChange('precificacao', campo, val) → rota salvar.
    function updateGlobal(campo, valor) {
        const novo = { ...cfg, [campo]: valor };
        setCfg(novo);
        clearTimeout(cfgDebRef.current);
        cfgDebRef.current = setTimeout(() => {
            onSaveCfg(campo, (parseFloat(novo[campo]) || 0) / 100);
        }, 800);
    }
    const updateAcrescimo = v => updateGlobal('acrescimo', v);
    const updateMC        = v => updateGlobal('margem_contribuicao', v);
    const updateLL        = v => updateGlobal('lucro_liquido', v);

    // config numérica para cálculos em tempo real
    const cc = { comissao: parseFloat(cfg.classico.comissao)/100, imposto: parseFloat(cfg.classico.imposto)/100 };
    const cp = { comissao: parseFloat(cfg.premium.comissao)/100,  imposto: parseFloat(cfg.premium.imposto)/100  };
    const acrNum = (parseFloat(cfg.acrescimo) || 0) / 100;
    const mcNum  = (parseFloat(cfg.margem_contribuicao) || 0) / 100;
    const llNum  = (parseFloat(cfg.lucro_liquido) || 0) / 100;

    // ── Modo de imposto: 'massa' (padrão) | 'individual' (por produto) ────────
    // Ausência da chave modo_imposto em precificações antigas = modo massa (compat retroativa).
    const [modoImposto, setModoImposto] = useState(
        dados.modo_imposto === 'individual' ? 'individual' : 'massa'
    );

    // Persiste o modo via onSaveCfg → onChange('precificacao', 'modo_imposto', valor, true).
    // O valor é a string 'massa'|'individual' — o controller salva sem conversão.
    function setModo(novo) {
        setModoImposto(novo);
        onSaveCfg('modo_imposto', novo);
    }

    // Helper: no modo individual usa o imposto_individual do produto (string %, ex: "12" → 0.12).
    // No modo massa devolve o imposto decimal do tier (comportamento atual sem alteração).
    const impostoEfetivo = (row, tierImpostoDecimal) =>
        modoImposto === 'individual'
            ? ((parseFloat(row?.imposto_individual) || 0) / 100)
            : tierImpostoDecimal;

    // Helpers de MC e LL efetivos — MC e LL são SEMPRE por produto (independente do modo do imposto).
    // Campo VAZIO = HERDA o global (mcNum/llNum, o "padrão"); preenchido = override só daquele produto.
    const mcEfetivo = (row) =>
        (row?.mc_individual !== '' && row?.mc_individual != null)
            ? ((parseFloat(row.mc_individual) || 0) / 100)
            : mcNum;

    const llEfetivo = (row) =>
        (row?.ll_individual !== '' && row?.ll_individual != null)
            ? ((parseFloat(row.ll_individual) || 0) / 100)
            : llNum;

    const emptyRow = PRECIF_LINHA_VAZIA;

    // Pareamento planilha × precificação salva vive em lib/precificacaoProdutos.js — é
    // lógica pura, com os cenários travados em tests/js/precificacaoProdutos.test.js.
    // O ponto crítico está documentado lá: SKU repetido ("Não tenho" em todo produto)
    // colapsava a lista inteira num produto só.
    const vemDaPlanilha = useMemo(() => criarTesteDaPlanilha(planilhaProdutos), [planilhaProdutos]);

    const [rows, setRows] = useState(
        () => mesclarPrecificacaoComPlanilha(planilhaProdutos, dados.produtos)
    );
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
        setSaving(true);
        clearTimeout(debRef.current);
        debRef.current = setTimeout(() => { onSaveRef.current(newRows); pendingRef.current = null; setSaving(false); }, 800);
    }

    // Modo de visualização: Simulador (guiado, 1 produto) | Lote (planilha completa)
    const [view, setView]     = useState('simulador');
    const [selIdx, setSelIdx] = useState(0);
    const [tier, setTier]     = useState('classico');

    // Edita um campo (sku/descricao/custo/frete) do produto selecionado e persiste.
    function editProduto(campo, valor) {
        const base = rows.length > 0 ? rows : [{ ...emptyRow }];
        const idx  = Math.min(selIdx, Math.max(0, base.length - 1));
        const novo = base.map((r, i) => i === idx ? { ...r, [campo]: valor } : r);
        handleChange(novo);
    }

    // Adiciona um novo produto e o seleciona. O novo produto "chega" com a MESMA precificação
    // (imposto/MC/LL) do produto atualmente selecionado, como ponto de partida — assim você não
    // re-digita os alvos. Custo, frete, SKU e descrição ficam em branco (dados físicos do novo
    // produto). Como cada linha é um objeto próprio e imutável, editar um produto NÃO reflete
    // nos outros (nem o novo no anterior, nem o anterior no novo).
    function addProduto() {
        const atual = rows[selIdx] ?? {};
        const novoProduto = {
            ...emptyRow,
            imposto_individual: atual.imposto_individual ?? '',
            mc_individual:      atual.mc_individual ?? '',
            ll_individual:      atual.ll_individual ?? '',
        };
        const novo = [...rows, novoProduto];
        handleChange(novo);
        setSelIdx(novo.length - 1);
    }

    // Exclui o produto do índice e ajusta a seleção.
    function delProduto(idx) {
        const novo = rows.filter((_, i) => i !== idx);
        handleChange(novo);
        setSelIdx(s => Math.max(0, Math.min(s >= idx ? s - 1 : s, novo.length - 1)));
    }

    // Bloco por tier: Frete → Anunciado → Preço C/ Desconto → Lucro Líquido → Margem Contrib.
    // Preço = (custo+frete)/(1−comissão−imposto−MC−LL). Anunciado = preço×(1+acréscimo).
    // Lucro Líquido = preço×LL · Margem Contrib. = preço×MC (em R$ por produto).
    // No modo individual os 8 computes usam impostoEfetivo(row, tier.imposto) em vez do imposto global do tier.
    const cols = useMemo(() => {
        const colsProduto = [
            { id: 'sku',            label: 'SKU',        type: 'text',     width: 110 },
            { id: 'descricao',      label: 'Descrição',  type: 'text',     width: 180 },
            { id: 'custo',          label: 'Custo R$',   type: 'number',   width: 90  },
            // Imposto individual: só no modo individual (o toggle Massa|Individual controla SÓ o imposto).
            ...(modoImposto === 'individual' ? [
                { id: 'imposto_individual', label: 'Imposto Ind. %', type: 'number', width: 110 },
            ] : []),
            // MC e LL são SEMPRE por produto (vazio herda o global) — colunas sempre visíveis nos dois modos.
            { id: 'mc_individual',      label: 'Margem Contrib. %', type: 'number', width: 130 },
            { id: 'll_individual',      label: 'Lucro Líquido %',  type: 'number', width: 120 },
        ];
        const colsClassico = [
            { id: 'frete_classico', label: 'Frete',      type: 'number',   width: 80,
              conditionalFormat: (v, row) => freteFaltando(v, row) ? 'bg-red-500/20 text-red-300 ring-1 ring-inset ring-red-500/50' : null },
            { id: '_anunc_c',  label: 'Anunciado Cl.',         type: 'readonly', width: 120, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, impostoEfetivo(row, cc.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p ? p * (1 + acrNum) : null); } },
            { id: '_preco_c',  label: 'Preço C/ Desconto Cl.', type: 'readonly', width: 140, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, impostoEfetivo(row, cc.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p); } },
            { id: '_marg_c',   label: 'Lucro Líquido Cl.',      type: 'readonly', width: 130, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, impostoEfetivo(row, cc.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p ? p * llEfetivo(row) : null); } },
            { id: '_mc_c',     label: 'Margem Contrib. Cl.',    type: 'readonly', width: 150, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_classico||0), cc.comissao, impostoEfetivo(row, cc.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p ? p * mcEfetivo(row) : null); } },
        ];
        const colsPremium = [
            { id: 'frete_premium',  label: 'Frete',      type: 'number',   width: 80,
              conditionalFormat: (v, row) => freteFaltando(v, row) ? 'bg-red-500/20 text-red-300 ring-1 ring-inset ring-red-500/50' : null },
            { id: '_anunc_p',  label: 'Anunciado Pr.',          type: 'readonly', width: 120, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, impostoEfetivo(row, cp.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p ? p * (1 + acrNum) : null); } },
            { id: '_preco_p',  label: 'Preço C/ Desconto Pr.',  type: 'readonly', width: 140, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, impostoEfetivo(row, cp.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p); } },
            { id: '_marg_p',   label: 'Lucro Líquido Pr.',       type: 'readonly', width: 130, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, impostoEfetivo(row, cp.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p ? p * llEfetivo(row) : null); } },
            { id: '_mc_p',     label: 'Margem Contrib. Pr.',     type: 'readonly', width: 150, align: 'right',
              compute: row => { const p = calcPreco(row.custo, parseFloat(row.frete_premium||0), cp.comissao, impostoEfetivo(row, cp.imposto), mcEfetivo(row), llEfetivo(row)); return fmt(p ? p * mcEfetivo(row) : null); } },
        ];
        return [...colsProduto, ...colsClassico, ...colsPremium];
    }, [cc.comissao, cc.imposto, cp.comissao, cp.imposto, mcNum, llNum, acrNum, modoImposto, impostoEfetivo, mcEfetivo, llEfetivo]);

    // headerGroups: grupo de produto tem span 3 (massa) ou 6 (individual: SKU, Descrição, Custo, Imposto Ind., MC, LL).
    // Clássico=5 colunas e Premium=5 colunas permanecem fixos.
    const headerGroups = [
        { label: '',         span: modoImposto === 'individual' ? 6 : 5, className: '' },
        { label: 'Clássico', span: 5, className: 'text-blue-300/70' },
        { label: 'Premium',  span: 5, className: 'text-violet-300/70' },
    ];

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-[#050507]">
            <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06] bg-[#0b0c10] shrink-0">
                <div>
                    <h2 className="text-white font-display font-bold text-lg">Precificação</h2>
                    <p className="text-white/40 text-[12px] mt-0.5">
                        {view === 'simulador'
                            ? 'Descubra o preço de venda ideal de cada produto, passo a passo'
                            : 'Planilha completa — todos os produtos e tiers de uma vez'}
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    {/* Toggle de modo: Massa (tudo global) | Individual (imposto, MC e LL por produto) */}
                    <div className="flex items-center gap-2">
                        <span className="text-white/40 text-[12px]">Imposto:</span>
                        <div className="inline-flex rounded-xl bg-white/[0.04] border border-white/[0.08] p-1">
                            {[{ k: 'massa', l: 'Massa' }, { k: 'individual', l: 'Individual' }].map(({ k, l }) => (
                                <button key={k} type="button" onClick={() => setModo(k)}
                                    className={cn('px-3 py-1.5 rounded-lg text-[12px] font-semibold transition',
                                        modoImposto === k ? 'bg-ecf-yellow text-black' : 'text-white/45 hover:text-white/75')}>
                                    {l}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Toggle de modo de visualização: Simulador (guiado) | Lote (planilha) */}
                    <div className="inline-flex rounded-xl bg-white/[0.04] border border-white/[0.08] p-1">
                        {[{ k: 'simulador', l: '🎯 Simulador' }, { k: 'lote', l: '📊 Lote' }].map(({ k, l }) => (
                            <button key={k} type="button" onClick={() => setView(k)}
                                className={cn('px-4 py-1.5 rounded-lg text-[13px] font-semibold transition',
                                    view === k ? 'bg-white/[0.1] text-white' : 'text-white/45 hover:text-white/75')}>
                                {l}
                            </button>
                        ))}
                    </div>
                </div>

                <button onClick={onClose} className="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.05] hover:bg-white/[0.1] text-white/60 hover:text-white text-[13px] transition-all">
                    <X size={14} />
                    Fechar
                </button>
            </div>

            {view === 'lote' && (
            <div className="border-b border-white/[0.06] shrink-0">
                <button
                    onClick={() => setCfgOpen(v => !v)}
                    className="flex items-center gap-2 w-full px-5 py-3 text-white/40 hover:text-white/70 text-[12px] transition-colors"
                >
                    <span className={cn('transition-transform text-[10px]', cfgOpen && 'rotate-180')}>▼</span>
                    Configurar parâmetros — Comissão, Imposto, Margem Contrib., Lucro Líquido, Acréscimo
                    <span className="ml-2 text-white/20 text-[11px]">(comissão premium = clássico + 5% automático)</span>
                </button>
                {cfgOpen && (
                    <div className="px-5 pb-4 space-y-4">
                        {/* Parâmetros globais — Margem Contrib., Lucro Líquido, Acréscimo (não por tier) */}
                        <div className="flex flex-wrap gap-4">
                            <div className="w-44">
                                <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Margem Contrib. %</label>
                                <input type="number" step="0.01" min="0" value={cfg.margem_contribuicao} onChange={e => updateMC(e.target.value)}
                                    className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                            </div>
                            <div className="w-44">
                                <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Lucro Líquido %</label>
                                <input type="number" step="0.01" min="0" value={cfg.lucro_liquido} onChange={e => updateLL(e.target.value)}
                                    className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                            </div>
                            <div className="w-44">
                                <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">Acréscimo Anunciado %</label>
                                <input type="number" step="0.01" min="0" value={cfg.acrescimo} onChange={e => updateAcrescimo(e.target.value)}
                                    className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                            </div>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            {[
                                { tier: 'classico', label: 'Clássico', color: 'text-blue-300' },
                                { tier: 'premium',  label: 'Premium',  color: 'text-violet-300' },
                            ].map(({ tier, label, color }) => (
                                <div key={tier}>
                                    <p className={cn('text-[11px] font-bold uppercase tracking-wider mb-2', color)}>{label} — taxas do site</p>
                                    <div className="grid grid-cols-2 gap-2">
                                        {[
                                            { id: 'comissao', label: 'Comissão %' },
                                            { id: 'imposto',  label: 'Imposto %'  },
                                        ].map(({ id, label: lbl }) => (
                                            <div key={id}>
                                                <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">{lbl}</label>
                                                <input type="number" step="0.01" min="0" value={cfg[tier][id]} onChange={e => updateCfg(tier, id, e.target.value)}
                                                    className="w-full h-8 px-2 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
            )}

            <div className="flex-1 overflow-auto p-5">
                {view === 'simulador' ? (
                    <SimuladorPreco
                        produtos={rows}
                        selIdx={selIdx} setSelIdx={setSelIdx}
                        tier={tier} setTier={setTier}
                        cc={cc} cp={cp} acrNum={acrNum} mcNum={mcNum} llNum={llNum}
                        onEditProduto={editProduto} onAddProduto={addProduto} onDeleteProduto={delProduto}
                        cfg={cfg} updateCfg={updateCfg} updateMC={updateMC} updateLL={updateLL} updateAcrescimo={updateAcrescimo}
                        saving={saving}
                        tabelaFreteUrl={tabelaFreteUrl}
                        modoImposto={modoImposto}
                        impostoEfetivo={impostoEfetivo}
                        mcEfetivo={mcEfetivo}
                        llEfetivo={llEfetivo}
                        vemDaPlanilha={vemDaPlanilha}
                    />
                ) : (
                    <>
                        <SpreadsheetGrid columns={cols} rows={rows} onChange={handleChange} minRows={10} headerGroups={headerGroups} exportFilename="precificacao" />
                        <p className="mt-2 text-white/20 text-[11px]">
                            Dica: arraste o quadrado azul no canto da célula para preencher · Ctrl+C/V para copiar e colar
                        </p>
                        {/* SKU e Descrição de quem veio da planilha são re-derivados a cada abertura. */}
                        <p className="mt-1 text-white/20 text-[11px]">
                            SKU e Descrição dos produtos cadastrados vêm da Planilha de Produtos — renomear aqui não altera lá.
                        </p>
                    </>
                )}
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
        // '---' = sentinela "não escolhido" (default) → mantém o check travado até o cliente selecionar uma opção real.
        // Espelha ERP_OPCOES / INTEGRADOR_OPCOES de App\Models\MlbImplementacao — manter em sincronia manualmente.
        const opcoes = item.id === 'erp' ? ['---','Em Contratação','Tiny ERP','Bling','SAP','Netsuite','TOTVS','Omie','Outro'] : ['---','Em Contratação','Melhor Envio','Frenet','DirectLog','Jadlog','Correios','Trabalhar apenas com Mercado Envios','Outro'];
        const valor = dado?.valor ?? '---';
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

// ─── Regra de conteúdo mínimo p/ liberar o check ─────────────────────────────

// Espelha MlbImplementacao::itemTemConteudo (PHP). Só itens onde o cliente
// preenche/altera algo exigem conteúdo antes de "Marcar como feito"; itens de
// ação pura (acessar link, dar acesso, declarar) permanecem sempre liberados.
// Reativo: dado vem do estado local, então recalcula a cada tecla digitada.
function itemTemConteudo(item, dado = {}) {
    switch (item.tipo) {
        case 'select': { // ERP / Integrador — escolher qualquer opção real (≠ '---') libera
            const valor = String(dado.valor ?? '').trim();
            return valor !== '' && valor !== '---';
        }
        case 'texto': // HUB
            return String(dado.acesso ?? '').trim() !== '';
        case 'link':  // URL digitada pelo cliente
            return String(dado.link ?? '').trim() !== '';
        case 'produtos': // ≥ 1 produto com SKU ou nome
            return (dado.produtos ?? []).some(
                p => String(p.sku ?? '').trim() !== '' || String(p.produto ?? '').trim() !== ''
            );
        case 'precificacao': // ≥ 1 produto com custo informado
            return (dado.produtos ?? []).some(p => String(p.custo ?? '').trim() !== '');
        default:
            // ação pura (link/gmail/instruções/checkbox/select_opcoes): nada a preencher.
            return true;
    }
}

// ─── Item do checklist ────────────────────────────────────────────────────────

function ChecklistItem({ item, dado, tutorialUrl, linksAdmin, onChange, onPlay, onOpenProdutos, onOpenPrecificacao, onOpenPassoAPasso, tabelaFreteUrl, num }) {
    const feito = dado?.feito ?? false;
    // Já feito nunca trava (permite desmarcar); senão exige o conteúdo mínimo.
    const podeMarcar = feito || itemTemConteudo(item, dado);

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
                        {/* Passo a passo em texto — exclusivo do App ECF (cadastro na Adman) */}
                        {item.id === 'app_ecf' && <PassoAPassoBtn onClick={onOpenPassoAPasso} />}
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
                <>
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
                    {tabelaFreteUrl && (
                        <a
                            href={tabelaFreteUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-2 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow font-semibold text-[13px] hover:bg-ecf-yellow/[0.12] transition-all"
                        >
                            <ExternalLink size={14} />
                            Tabela de Frete
                        </a>
                    )}
                    {/* Link fixo da ajuda do ML sobre custo de taxa de venda */}
                    <a
                        href="https://www.mercadolivre.com.br/ajuda/870"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-2 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow font-semibold text-[13px] hover:bg-ecf-yellow/[0.12] transition-all"
                    >
                        <ExternalLink size={14} />
                        Taxa de Venda
                    </a>
                </>
            ) : (
                <ItemInput item={item} dado={dado} linksAdmin={linksAdmin} onChange={onChange} />
            )}

            {/* Checkbox de feito — oculto para select_opcoes (feito se selecionou algo) */}
            {item.tipo !== 'select_opcoes' && (
                <div className="mt-4 pt-3 border-t border-white/[0.06]">
                    <label className={cn('flex items-center gap-2.5 group w-fit', podeMarcar ? 'cursor-pointer' : 'cursor-not-allowed')}>
                        <div
                            onClick={() => { if (podeMarcar) onChange(item.id, 'feito', !feito, true); }}
                            className={cn(
                                'w-5 h-5 rounded border-2 flex items-center justify-center transition-all',
                                podeMarcar ? 'cursor-pointer' : 'cursor-not-allowed opacity-40',
                                feito ? 'border-emerald-400 bg-emerald-400' : 'border-white/20 group-hover:border-emerald-400/50'
                            )}
                        >
                            {feito && <Check size={11} className="text-white" />}
                        </div>
                        <span className={cn('text-[13px] font-medium transition-colors',
                            feito ? 'text-emerald-300' : podeMarcar ? 'text-white/40 group-hover:text-white/60' : 'text-white/25')}>
                            {item.id === 'certificado_a1'    ? 'Sim, possuo Certificado A1'
                             : item.id === 'publicar_em_massa' ? 'Confirmar'
                             : 'Marcar como feito'}
                        </span>
                    </label>
                    {/* Aviso enquanto o item não tem o conteúdo mínimo preenchido */}
                    {!podeMarcar && (
                        <p className="mt-1.5 flex items-center gap-1.5 text-amber-400/80 text-[12px]">
                            <AlertCircle size={12} className="shrink-0" />
                            Preencha as informações acima para marcar como feito.
                        </p>
                    )}
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

export default function ImplementacaoPublica({ impl, checklist, prazo_data = '', prazo_limite = '', tabela_frete_url = '' }) {
    const [dadosLocais, setDadosLocais]     = useState(impl.dados);
    const [progresso, setProgresso]         = useState(impl.progresso);
    const [saveStatus, setSaveStatus]       = useState('idle');
    const [video, setVideo]                 = useState(null);
    const [passoAPasso, setPassoAPasso]     = useState(null);
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
                        <span className="text-ecf-yellow font-semibold">Atenção:</span> todas as etapas devem ser concluídas <span className="text-white font-semibold">em até 5 dias</span>{prazo_limite ? <> (até <span className="text-white font-semibold">{fmtPrazo(prazo_limite)}</span>)</> : ''} para mantermos o andamento da implementação. Projetos sem retorno dentro do prazo poderão ser descontinuados.
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
                        tutorialUrl={item.tem_tutorial ? (tutoriais[item.id] ?? '') : ''}
                        linksAdmin={linksAdmin}
                        onChange={onChange}
                        onPlay={playVideo}
                        onOpenProdutos={() => setProdutosOpen(true)}
                        onOpenPrecificacao={() => setPrecifOpen(true)}
                        onOpenPassoAPasso={() => setPassoAPasso(ADMAN_PASSO_A_PASSO)}
                        tabelaFreteUrl={tabela_frete_url}
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

            {passoAPasso && (
                <PassoAPassoModal conteudo={passoAPasso} onClose={() => setPassoAPasso(null)} />
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
                    tabelaFreteUrl={tabela_frete_url}
                />
            )}
        </div>
    );
}
