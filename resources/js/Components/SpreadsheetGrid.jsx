import { Fragment, useState, useRef, useEffect, useLayoutEffect, useMemo } from 'react';
import { cn } from '@/lib/utils';
import { lerTsvDoExcel, proximaEditavel } from '@/lib/gradeTeclado';
import {
    ArrowUp, ArrowDown, ArrowUpDown, Search, X, Download, Upload,
    ChevronRight, ChevronDown, Check, AlertCircle, Edit3, Layers,
} from 'lucide-react';

// ── Formatação por tipo ───────────────────────────────────────────────────────
function fmtVal(col, value) {
    if (value === null || value === undefined || value === '') return '';
    switch (col.type) {
        case 'currency': {
            const n = parseFloat(value);
            return isNaN(n) ? '' : n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }
        case 'percent':
            return String(value) + '%';
        case 'date':
            try { return new Date(value + 'T00:00:00').toLocaleDateString('pt-BR'); } catch { return value; }
        case 'checkbox':
            return (value === true || value === '1' || value === 'true') ? '✓' : '';
        case 'textarea':
            // preview: primeira linha, max 120 chars
            return String(value).split('\n')[0].slice(0, 120);
        default:
            return String(value);
    }
}

// ── Popup de textarea (para células com texto longo) ──────────────────────────
// FECHAR SALVA. O padrão anterior (clicar fora / X = descartar) fazia o texto
// digitado sumir sem nenhum aviso, e as ÚNICAS colunas afetadas eram as de texto
// longo — todas as outras comitam sozinhas no Enter/Tab/blur. Foi assim que a
// Planilha de Produtos do Onboarding ficou com "Espec. Técnicas" e "Descrição"
// vazias enquanto todo o resto salvava: o cliente escrevia e clicava fora.
// Só o "Cancelar" explícito (botão ou Esc) descarta.
function TextareaPopup({ label, value, onChange, onSave, onCancel }) {
    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" onClick={onSave}>
            <div className="w-full max-w-lg bg-[#0b0c14] border border-white/[0.1] rounded-2xl shadow-2xl flex flex-col" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-3.5 border-b border-white/[0.07]">
                    <p className="text-white/60 text-[12px] font-semibold uppercase tracking-wider">{label}</p>
                    <button onClick={onSave} title="Salvar e fechar" className="p-1 text-white/30 hover:text-white/70 transition-colors"><X size={14} /></button>
                </div>
                <textarea
                    autoFocus
                    value={value}
                    onChange={e => onChange(e.target.value)}
                    onKeyDown={e => {
                        if (e.key === 'Escape') onCancel();
                        // Ctrl+Enter salva; Enter sozinho continua quebrando linha.
                        else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); onSave(); }
                        e.stopPropagation();
                    }}
                    rows={8}
                    placeholder="Cole ou escreva o texto aqui..."
                    className="w-full px-5 py-4 bg-transparent text-white/90 text-[13px] leading-relaxed resize-none focus:outline-none placeholder:text-white/20"
                />
                <div className="flex items-center justify-between gap-2 px-5 py-3 border-t border-white/[0.07]">
                    <p className="text-white/25 text-[11px]">Ctrl+Enter salva · Esc cancela</p>
                    <div className="flex gap-2">
                        <button onClick={onCancel} className="px-4 py-1.5 text-[12px] text-white/40 hover:text-white transition-colors">Cancelar</button>
                        <button onClick={onSave} className="px-4 py-1.5 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[12px] hover:brightness-110 transition-all">Salvar</button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Camada do picker: popover ancorado no retangulo da celula ─────────────────
// Clique fora = fechar (quem decide se grava e o editor, via registrarFechar). Depois de
// montar, mede e recoloca para caber na viewport (vira para cima se nao couber embaixo).
function CamadaPicker({ anchor, onFora, children }) {
    const boxRef = useRef(null);
    const [pos, setPos] = useState({ top: (anchor?.bottom ?? 0) + 4, left: anchor?.left ?? 0 });
    useLayoutEffect(() => {
        const el = boxRef.current;
        if (!el || !anchor) return;
        const { width, height } = el.getBoundingClientRect();
        let top = anchor.bottom + 4;
        let left = anchor.left;
        if (top + height > window.innerHeight - 8) top = Math.max(8, anchor.top - height - 4);
        if (left + width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - width - 8);
        setPos({ top, left });
    }, [anchor]);
    return (
        <div className="fixed inset-0 z-[200]" onMouseDown={onFora}>
            <div ref={boxRef} style={{ position: 'fixed', top: pos.top, left: pos.left }}
                onMouseDown={e => e.stopPropagation()}>
                {children}
            </div>
        </div>
    );
}

// ── Tags: pills inline ────────────────────────────────────────────────────────
const TAG_COLORS = [
    'bg-blue-500/20 text-blue-300 border-blue-500/30',
    'bg-violet-500/20 text-violet-300 border-violet-500/30',
    'bg-amber-500/20 text-amber-300 border-amber-500/30',
    'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    'bg-red-500/20 text-red-300 border-red-500/30',
    'bg-pink-500/20 text-pink-300 border-pink-500/30',
];
function tagColor(tag, options) {
    const idx = (options ?? []).indexOf(tag);
    return TAG_COLORS[(idx >= 0 ? idx : tag.charCodeAt(0)) % TAG_COLORS.length];
}
function TagPills({ value, options, max = 99 }) {
    const tags = value ? String(value).split(',').filter(Boolean) : [];
    if (!tags.length) return null;
    return (
        <div className="flex items-center gap-1 flex-wrap">
            {tags.slice(0, max).map(t => (
                <span key={t} className={cn('px-1.5 py-0.5 rounded text-[10px] font-medium border', tagColor(t, options))}>
                    {t}
                </span>
            ))}
            {tags.length > max && <span className="text-white/30 text-[10px]">+{tags.length - max}</span>}
        </div>
    );
}

// ── TagsInput (para painel) ───────────────────────────────────────────────────
function TagsInput({ value, options, onChange }) {
    const selected = value ? String(value).split(',').filter(Boolean) : [];
    function toggle(tag) {
        const next = selected.includes(tag) ? selected.filter(t => t !== tag) : [...selected, tag];
        onChange(next.join(','));
    }
    if (options?.length) {
        return (
            <div className="flex flex-wrap gap-1.5 mt-1">
                {options.map(o => (
                    <button key={o} type="button" onClick={() => toggle(o)}
                        className={cn('px-2.5 py-1 rounded-full text-[11px] font-medium border transition-all',
                            selected.includes(o) ? 'bg-ecf-yellow/20 border-ecf-yellow/40 text-ecf-yellow' : 'bg-white/[0.04] border-white/[0.08] text-white/40 hover:text-white/70')}>
                        {o}
                    </button>
                ))}
            </div>
        );
    }
    return (
        <input type="text" value={value ?? ''} onChange={e => onChange(e.target.value)}
            placeholder="tag1, tag2, tag3"
            className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
    );
}

// ── Painel de linha ───────────────────────────────────────────────────────────
function RowPanel({ row, columns, rowNum, onSave, onClose }) {
    const [form, setForm] = useState(() => ({ ...row }));
    const editableCols = columns.filter(c => c.type !== 'readonly' && !c.compute);

    function submit(e) {
        e.preventDefault();
        onSave(form);
        onClose();
    }

    return (
        <div className="fixed inset-0 z-50 flex">
            <div className="flex-1 bg-black/40" onClick={onClose} />
            <div className="w-[400px] bg-[#0b0c14] border-l border-white/[0.08] flex flex-col shadow-2xl overflow-hidden">
                <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06] shrink-0">
                    <div>
                        <p className="text-white/40 text-[11px] uppercase tracking-wider">Linha {rowNum}</p>
                        <h3 className="text-white font-semibold text-[15px] mt-0.5">Editar registro</h3>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-white/30 hover:text-white/70 transition-colors"><X size={16} /></button>
                </div>
                <form onSubmit={submit} className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    {editableCols.map(col => (
                        <div key={col.id}>
                            <label className="text-white/40 text-[11px] font-medium uppercase tracking-wider block mb-1.5">
                                {col.label}{col.validate?.required && <span className="text-red-400 ml-1">*</span>}
                            </label>
                            {col.type === 'select' ? (
                                <select value={form[col.id] ?? ''} onChange={e => setForm(p => ({ ...p, [col.id]: e.target.value }))}
                                    className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40">
                                    <option value="" className="bg-[#0d0e14] text-white/30">—</option>
                    {col.options?.map(o => <option key={o} value={o} className="bg-[#0d0e14]">{o}</option>)}
                                </select>
                            ) : col.type === 'checkbox' ? (
                                <label className="flex items-center gap-2 cursor-pointer mt-1">
                                    <div onClick={() => setForm(p => ({ ...p, [col.id]: !p[col.id] }))}
                                        className={cn('w-5 h-5 rounded border-2 flex items-center justify-center cursor-pointer transition-all', form[col.id] ? 'border-ecf-yellow bg-ecf-yellow' : 'border-white/20')}>
                                        {form[col.id] && <Check size={11} className="text-[#252525]" />}
                                    </div>
                                    <span className="text-white/60 text-[13px]">{form[col.id] ? 'Sim' : 'Não'}</span>
                                </label>
                            ) : col.type === 'tags' ? (
                                <TagsInput value={form[col.id] ?? ''} options={col.options} onChange={v => setForm(p => ({ ...p, [col.id]: v }))} />
                            ) : (
                                <input
                                    type={['number','currency','percent'].includes(col.type) ? 'number' : col.type === 'date' ? 'date' : col.type === 'url' ? 'url' : 'text'}
                                    step={['number','currency'].includes(col.type) ? '0.01' : undefined}
                                    value={form[col.id] ?? ''}
                                    onChange={e => setForm(p => ({ ...p, [col.id]: e.target.value }))}
                                    className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
                                />
                            )}
                        </div>
                    ))}
                    <div className="pt-2 flex justify-end gap-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">Cancelar</button>
                        <button type="submit" className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── SpreadsheetGrid ───────────────────────────────────────────────────────────
/**
 * columns: Array<{
 *   id, label,
 *   type: 'text'|'number'|'currency'|'percent'|'date'|'checkbox'|'url'|'select'|'tags'|'readonly',
 *   options?: string[],           for select/tags
 *   compute?: (row) => any,       for readonly
 *   width?: number,
 *   align?: 'left'|'right',
 *   frozen?: boolean,             sticky left
 *   footer?: 'sum'|'avg'|'count'|'min'|'max'|null,
 *   validate?: { required?, min?, max?, pattern?, message? },
 *   conditionalFormat?: (value, row) => string | null,   extra className
 * }>
 *
 * Props opcionais (Fase 167 - tela de Produtos). TODAS aditivas: ausentes, a grade
 * se comporta exatamente como antes (o Onboarding publico do cliente depende disso).
 *   growOnPaste   colar do Excel CRESCE a grade (evento DOM paste). Antes o excedente era
 *                 descartado em silencio: colar 70 linhas gravava 10.
 *   onPasteBlock  (matriz, {row, col}) => true  - a pagina cuida do colar (ex.: cabecalho do modelo).
 *   maxPasteRows  limite de linhas por colagem; excedido chama onPasteLimit(qtd) e nao cola.
 *   makeRow       () => linha nova da pagina (default: linha vazia da grade).
 *   rowKey        chave estavel da linha (usada no key do React e no `selecionar`).
 *   onRowsCommit  (prev, next) => void - avisa a pagina que as linhas mudaram (para salvar so o que mudou).
 *   tabWrap       Tab anda so pelas colunas editaveis, passa para a linha de baixo e cria linha na ultima.
 *   variant       'padrao' (default, igual ao de sempre) | 'portal' (linha de 40px, celula com cara de campo).
 *   ariaLabel     liga role="grid" + aria-label na grade.
 *   rowClassName  (row, i) => className extra na <tr>.
 *   rowActions    (row, i) => nodo; desenha uma coluna fixa de 56px a direita.
 *   rowNote       (row, i) => nodo|null; desenha uma <tr> de largura total abaixo da linha.
 *   selecionar    { chave, coluna, n } - seleciona e foca a celula da linha com row[rowKey] === chave,
 *                 na coluna `coluna`, sempre que `n` muda (a pagina poe o foco na linha nova sem API imperativa).
 *
 * Campos extras de coluna (Fase 167):
 *   type 'picker'  popover ancorado na celula (Enter/Espaco/F2/duplo clique, ou digitar abre com textoInicial).
 *                  renderEditor({ row, value, anchor, textoInicial, onCommit(patch), onClose, registrarFechar(fn) }):
 *                  onCommit grava um patch de VARIOS campos na linha; registrarFechar(fn) diz o que fazer ao
 *                  clicar fora/Esc (fechar grava). Sem fn registrada, so fecha.
 *   renderCell(value, row)  substitui o texto da celula (fora da edicao).
 *   placeholder    texto fraco em celula editavel vazia.
 *   separador      true = filete a esquerda (agrupamento visual).
 */
export function SpreadsheetGrid({
    columns, rows, onChange,
    minRows = 10,
    headerGroups = null,
    exportFilename = 'planilha',
    showImportExport = true,
    growOnPaste = false,
    onPasteBlock = null,
    maxPasteRows = null,
    onPasteLimit = null,
    makeRow = null,
    rowKey = null,
    onRowsCommit = null,
    tabWrap = false,
    variant = 'padrao',
    ariaLabel = null,
    rowClassName = null,
    rowActions = null,
    rowNote = null,
    selecionar = null,
}) {
    const portal = variant === 'portal';
    const altura = portal ? 40 : 26;
    const C = columns.length;
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
    const mkEmpty = () => Object.fromEntries(columns.map(c => [c.id, c.type === 'checkbox' ? false : '']));
    // Linha de DADO nova: a pagina pode ter o seu molde (ids, defaults). O preenchimento
    // visual ate minRows segue com mkEmpty - aquilo e so espaco, nao e linha.
    const novaLinha = () => (makeRow ? makeRow() : mkEmpty());
    const ehEditavel = col => !!col && col.type !== 'readonly' && !col.compute;

    // ── Column widths (resize) ────────────────────────────────────────────────
    const [colWidths, setColWidths] = useState(() => columns.map(c => c.width ?? 100));
    const prevColIds = useRef(columns.map(c => c.id).join(','));
    useEffect(() => {
        const ids = columns.map(c => c.id).join(',');
        if (ids !== prevColIds.current) { prevColIds.current = ids; setColWidths(columns.map(c => c.width ?? 100)); }
    }, [columns]);
    const resizing = useRef(null);
    useEffect(() => {
        function mm(e) {
            if (!resizing.current) return;
            const { ci, sx, sw } = resizing.current;
            setColWidths(p => p.map((w, i) => i === ci ? Math.max(40, sw + e.clientX - sx) : w));
        }
        function mu() { resizing.current = null; }
        window.addEventListener('mousemove', mm);
        window.addEventListener('mouseup', mu);
        return () => { window.removeEventListener('mousemove', mm); window.removeEventListener('mouseup', mu); };
    }, []);

    // ── Sort / Filter / GroupBy ───────────────────────────────────────────────
    const [sortCol, setSortCol]   = useState(null);
    const [sortDir, setSortDir]   = useState('asc');
    const [filter, setFilter]     = useState('');
    const [groupBy, setGroupBy]   = useState(null);
    const [collapsed, setCollapsed] = useState(new Set());

    // ── Grid state ────────────────────────────────────────────────────────────
    const [selA, setSelA]     = useState({ r: 0, c: 0 });
    const [selB, setSelB]     = useState({ r: 0, c: 0 });
    const [editing, setEditing]   = useState(null);
    const [editVal, setEditVal]   = useState('');
    const [fillEnd, setFillEnd]   = useState(null);
    const [errors, setErrors]     = useState({});
    const [panelRow, setPanelRow] = useState(null);
    const [bulkActive, setBulkActive] = useState(false);
    const [bulkVal, setBulkVal]   = useState('');
    const [footerModes, setFooterModes] = useState(() =>
        columns.map(c => ['number','currency','percent'].includes(c.type) ? 'sum' : null)
    );

    const [textareaPopup, setTextareaPopup] = useState(null); // { r, c, value }
    const [pickerAberto, setPickerAberto] = useState(null);   // { r, c, anchor: DOMRect, textoInicial }
    const fecharRef = useRef(null); // o que o editor do picker pediu para rodar ao clicar fora/Esc

    const gridRef      = useRef(null);
    const inputRef     = useRef(null);
    const fileRef      = useRef(null);
    const fillDrag     = useRef(false);
    const selDrag      = useRef(false);
    const ctx          = useRef({});
    const editingStateRef = useRef(null); // espelho ref de `editing` — limpo imediatamente em commit() para evitar double-commit
    const pendingChar     = useRef(null); // char que iniciou a edição (para evitar duplicação)

    // ── History (undo/redo) ───────────────────────────────────────────────────
    const historyRef    = useRef([]);
    const historyIdx    = useRef(-1);
    function pushHistory(snap) {
        const stack = historyRef.current.slice(0, historyIdx.current + 1);
        stack.push(JSON.stringify(snap));
        if (stack.length > 100) stack.shift();
        historyRef.current = stack;
        historyIdx.current = stack.length - 1;
    }
    useEffect(() => {
        if (historyRef.current.length === 0) {
            historyRef.current = [JSON.stringify(rows)];
            historyIdx.current = 0;
        }
    }, []);

    // ── Derived: sort + filter + group ────────────────────────────────────────
    const { displayed, origIdx } = useMemo(() => {
        let idx = rows.map((_, i) => i);

        if (filter) {
            const q = filter.toLowerCase();
            idx = idx.filter(i => columns.some(c => !c.compute && String(rows[i]?.[c.id] ?? '').toLowerCase().includes(q)));
        }

        if (sortCol !== null && columns[sortCol] && !columns[sortCol].compute) {
            const cid = columns[sortCol].id;
            idx = [...idx].sort((a, b) => {
                const va = String(rows[a]?.[cid] ?? '');
                const vb = String(rows[b]?.[cid] ?? '');
                const cmp = va.localeCompare(vb, 'pt-BR', { numeric: true });
                return sortDir === 'asc' ? cmp : -cmp;
            });
        }

        if (groupBy) {
            const groups = new Map();
            idx.forEach(i => {
                const v = String(rows[i]?.[groupBy] ?? '(vazio)');
                if (!groups.has(v)) groups.set(v, []);
                groups.get(v).push(i);
            });
            const disp = [], oIdx = [];
            groups.forEach((list, val) => {
                disp.push({ __groupHeader: true, __val: val, __count: list.length });
                oIdx.push(-2);
                if (!collapsed.has(val)) {
                    list.forEach(i => { disp.push(rows[i]); oIdx.push(i); });
                }
            });
            return { displayed: disp, origIdx: oIdx };
        }

        const sorted = idx.map(i => rows[i]);
        const isMutated = filter || sortCol !== null;
        const pad = isMutated ? 0 : Math.max(0, minRows - sorted.length);
        return {
            displayed: [...sorted, ...Array.from({ length: pad }, mkEmpty)],
            origIdx: idx,
        };
    }, [rows, sortCol, sortDir, filter, groupBy, collapsed, minRows]);

    const R = displayed.length;
    // Visao filtrada/ordenada/agrupada: linha nova nao tem lugar visivel - vai para o fim de `rows`.
    const mutado = !!(filter || sortCol !== null || groupBy);
    ctx.current = { displayed, origIdx, rows, columns, onChange, R, C, fillEnd, selA, novaLinha, onRowsCommit, mutado, onPasteBlock, maxPasteRows, onPasteLimit };

    useEffect(() => { setSelA({ r: 0, c: 0 }); setSelB({ r: 0, c: 0 }); setEditing(null); }, [sortCol, sortDir, filter, groupBy]);

    // ── Selection ─────────────────────────────────────────────────────────────
    const r0 = Math.min(selA.r, selB.r), r1 = Math.max(selA.r, selB.r);
    const c0 = Math.min(selA.c, selB.c), c1 = Math.max(selA.c, selB.c);

    let fillBounds = null;
    if (fillEnd) {
        const dr = fillEnd.r - selA.r, dc = fillEnd.c - selA.c;
        if (Math.abs(dr) >= Math.abs(dc)) {
            const fR0 = fillEnd.r < selA.r ? fillEnd.r : selA.r + 1;
            const fR1 = fillEnd.r < selA.r ? selA.r - 1 : fillEnd.r;
            if (fR0 <= fR1) fillBounds = { r0: fR0, r1: fR1, c0: selA.c, c1: selA.c };
        } else {
            const fC0 = fillEnd.c < selA.c ? fillEnd.c : selA.c + 1;
            const fC1 = fillEnd.c < selA.c ? selA.c - 1 : fillEnd.c;
            if (fC0 <= fC1) fillBounds = { r0: selA.r, r1: selA.r, c0: fC0, c1: fC1 };
        }
    }
    const inFillPrev = (r, c) => fillBounds && r >= fillBounds.r0 && r <= fillBounds.r1 && c >= fillBounds.c0 && c <= fillBounds.c1;

    // ── Values / Mutations ────────────────────────────────────────────────────
    function getVal(r, c) {
        const col = columns[c];
        if (col.compute) return col.compute(displayed[r]);
        return displayed[r]?.[col.id] ?? '';
    }
    function display(r, c) {
        const col = columns[c];
        const v = getVal(r, c);
        return fmtVal(col, v);
    }

    // Ponto UNICO de emissao de linhas: alem de entregar a pagina, avisa o que mudou
    // (prev -> next) para ela gravar so as linhas alteradas.
    function emitir(next) {
        const prev = ctx.current.rows;
        ctx.current.onChange(next);
        ctx.current.onRowsCommit?.(prev, next);
    }

    // `crescerAte`: garante que exista a linha de indice `crescerAte` (Tab/Enter na ultima linha).
    // Em cada mudanca, `oi` explicito sobrepoe o mapeamento da visao (usado ao colar alem do exibido).
    function applyMulti(changes, skipHistory = false, crescerAte = null) {
        const { origIdx: oIdx, rows: orig, columns: cols, novaLinha } = ctx.current;
        let newRows = [...orig];
        for (const { r, c, value, oi: oiExplicito } of changes) {
            if (cols[c]?.type === 'readonly') continue;
            const oi = oiExplicito !== undefined ? oiExplicito : (r < oIdx.length ? oIdx[r] : r);
            if (oi === -2) continue; // group header
            while (newRows.length <= oi) newRows.push(novaLinha());
            newRows[oi] = { ...newRows[oi], [cols[c].id]: value };
        }
        if (crescerAte !== null) while (newRows.length <= crescerAte) newRows.push(novaLinha());
        if (!skipHistory) pushHistory(newRows);
        emitir(newRows);
    }

    // Acrescenta linhas ate existir o indice `ate` (sem alterar celula nenhuma).
    function criarLinhaAte(ate) {
        const n = [...ctx.current.rows];
        while (n.length <= ate) n.push(ctx.current.novaLinha());
        pushHistory(n);
        emitir(n);
    }

    // Patch de VARIOS campos na linha (editor do picker): uma unica emissao, um unico passo de historico.
    function aplicarPatch(r, patch) {
        const { origIdx: oIdx, rows: orig, novaLinha } = ctx.current;
        const oi = r < oIdx.length ? oIdx[r] : r;
        if (oi === -2) return;
        const newRows = [...orig];
        while (newRows.length <= oi) newRows.push(novaLinha());
        newRows[oi] = { ...newRows[oi], ...patch };
        pushHistory(newRows);
        emitir(newRows);
    }

    function fecharPicker() {
        fecharRef.current = null;
        setPickerAberto(null);
        requestAnimationFrame(() => gridRef.current?.focus());
    }

    // Esc fecha o picker; fechar GRAVA quando o editor registrou como (registrarFechar).
    useEffect(() => {
        if (!pickerAberto) return undefined;
        function aoTecla(e) {
            if (e.key !== 'Escape') return;
            e.preventDefault();
            e.stopPropagation();
            fecharRef.current ? fecharRef.current() : fecharPicker();
        }
        document.addEventListener('keydown', aoTecla, true);
        return () => document.removeEventListener('keydown', aoTecla, true);
    }, [pickerAberto]);

    // A pagina pede o foco numa celula (ex.: Ref da linha nova) trocando `selecionar.n`.
    useEffect(() => {
        if (!selecionar || !rowKey) return;
        const { chave, coluna } = selecionar;
        const ri = ctx.current.displayed.findIndex(rw => !rw.__groupHeader && rw[rowKey] === chave);
        const ci = columns.findIndex(c => c.id === coluna);
        if (ri < 0 || ci < 0) return;
        setSelA({ r: ri, c: ci }); setSelB({ r: ri, c: ci });
        requestAnimationFrame(() => {
            gridRef.current?.focus();
            gridRef.current?.querySelector(`[data-cell="${ri}-${ci}"]`)?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        });
    }, [selecionar?.n]);

    function undo() {
        if (historyIdx.current <= 0) return;
        historyIdx.current--;
        emitir(JSON.parse(historyRef.current[historyIdx.current]));
    }
    function redo() {
        if (historyIdx.current >= historyRef.current.length - 1) return;
        historyIdx.current++;
        emitir(JSON.parse(historyRef.current[historyIdx.current]));
    }

    // Colar do Excel (growOnPaste): posicional a partir da celula ativa, SEM o corte
    // pelo tamanho da grade - o applyMulti cresce com novaLinha(). Colunas calculadas/readonly
    // sao ignoradas. Chamada pelo listener de `paste`, que vive num effect de montagem unica:
    // por isso le tudo de ctx.current (closure velha de props seria um bug silencioso).
    function colarMatriz(matriz) {
        const { selA: sa, columns: cols, displayed: disp, rows: orig, mutado: visaoMutada,
                onPasteBlock: bloquear, maxPasteRows: maxL, onPasteLimit: aoLimite, C: nCols } = ctx.current;
        if (!matriz.length) return;
        if (bloquear?.(matriz, { row: sa.r, col: sa.c }) === true) return;
        if (maxL && matriz.length > maxL) { aoLimite?.(matriz.length); return; }
        const changes = [];
        matriz.forEach((linha, ri) => {
            const tr = sa.r + ri;
            if (disp[tr]?.__groupHeader) return;
            linha.forEach((val, ci) => {
                const tc = sa.c + ci;
                if (tc >= nCols || !ehEditavel(cols[tc])) return;
                const ch = { r: tr, c: tc, value: val };
                // Com filtro/ordenacao, o que passa do exibido vai para o FIM de rows.
                if (visaoMutada && tr >= disp.length) ch.oi = orig.length + (tr - disp.length);
                changes.push(ch);
            });
        });
        if (changes.length) applyMulti(changes);
    }

    useEffect(() => {
        if (!growOnPaste) return undefined;
        function aoColar(e) {
            // Edicao aberta (input da celula) ou foco fora da grade: o navegador cuida sozinho.
            if (editingStateRef.current) return;
            if (!gridRef.current || !gridRef.current.contains(document.activeElement)) return;
            const texto = e.clipboardData ? e.clipboardData.getData('text') : '';
            if (!texto) return;
            e.preventDefault();
            colarMatriz(lerTsvDoExcel(texto));
        }
        document.addEventListener('paste', aoColar);
        return () => document.removeEventListener('paste', aoColar);
    }, [growOnPaste]);

    // ── Validation ───────────────────────────────────────────────────────────
    function validateCell(r, c, value) {
        const rule = columns[c]?.validate;
        if (!rule) return null;
        if (rule.required && !String(value ?? '').trim()) return rule.message ?? 'Obrigatório';
        if (rule.min !== undefined && parseFloat(value) < rule.min) return rule.message ?? `Mín: ${rule.min}`;
        if (rule.max !== undefined && parseFloat(value) > rule.max) return rule.message ?? `Máx: ${rule.max}`;
        if (rule.pattern && !new RegExp(rule.pattern).test(String(value ?? ''))) return rule.message ?? 'Inválido';
        return null;
    }

    // ── Edit ──────────────────────────────────────────────────────────────────
    function startEdit(r, c, initChar = null) {
        const col = columns[c];
        if (!col || col.type === 'readonly') return;
        if (col.type === 'picker') {
            const td = gridRef.current?.querySelector(`[data-cell="${r}-${c}"]`);
            fecharRef.current = null;
            setPickerAberto({ r, c, anchor: td ? td.getBoundingClientRect() : null, textoInicial: initChar });
            return;
        }
        if (col.type === 'checkbox') {
            const cur = getVal(r, c);
            applyMulti([{ r, c, value: !(cur === true || cur === '1' || cur === 'true') }]);
            return;
        }
        if (col.type === 'textarea') {
            // Digitar direto na célula começa o texto do zero, igual às demais colunas
            // (antes o caractere digitado era engolido e o popup abria com o valor antigo).
            const atual = String(getVal(r, c) ?? '');
            setTextareaPopup({ r, c, value: initChar !== null ? initChar : atual, orig: atual });
            return;
        }
        pendingChar.current = initChar;
        editingStateRef.current = { r, c };
        setEditVal(initChar !== null ? '' : String(getVal(r, c) ?? ''));
        setEditing({ r, c });
    }

    function commit(dr = 0, dc = 0) {
        if (!editingStateRef.current) return;
        const { r, c } = editingStateRef.current;
        editingStateRef.current = null; // limpa imediatamente — bloqueia re-entrada (ex: onBlur após Enter)
        const err = validateCell(r, c, editVal);
        setErrors(p => { const n = { ...p }; if (err) n[`${r}_${c}`] = err; else delete n[`${r}_${c}`]; return n; });
        let nr = clamp(r + dr, 0, R - 1), nc = clamp(c + dc, 0, C - 1);
        let cresce = false;
        // tabWrap: Tab corre so pelas editaveis e, na ultima celula da ultima linha, cria a linha.
        if (tabWrap && !ctx.current.mutado) {
            if (dr === 0 && dc !== 0) {
                const res = proximaEditavel(columns, r, c, dc > 0 ? 1 : -1, R);
                if (res.criarLinha) { cresce = true; nr = R; nc = Math.max(0, columns.findIndex(ehEditavel)); }
                else { nr = res.r; nc = res.c; }
            } else if (dr === 1 && r === R - 1) { cresce = true; nr = R; nc = c; }
        }
        if (columns[c].type !== 'select') applyMulti([{ r, c, value: editVal }], false, cresce ? R : null);
        else if (cresce) criarLinhaAte(R);
        setEditing(null);
        setSelA({ r: nr, c: nc }); setSelB({ r: nr, c: nc });
        requestAnimationFrame(() => gridRef.current?.focus());
    }

    // Foca o input no próximo frame (depois que eventos de teclado terminam) e aplica o char pendente
    useEffect(() => {
        if (!editing) return;
        requestAnimationFrame(() => {
            if (!inputRef.current) return;
            inputRef.current.focus();
            if (pendingChar.current !== null) {
                setEditVal(pendingChar.current);
                pendingChar.current = null;
            } else if (typeof inputRef.current.select === 'function' && inputRef.current.tagName !== 'SELECT') {
                inputRef.current.select();
            }
        });
    }, [editing?.r, editing?.c]);

    // ── Footer ────────────────────────────────────────────────────────────────
    const FOOTER_CYCLE = ['sum', 'avg', 'count', 'min', 'max', null];
    function cycleFooter(ci) {
        const cur = footerModes[ci];
        const next = FOOTER_CYCLE[(FOOTER_CYCLE.indexOf(cur) + 1) % FOOTER_CYCLE.length];
        setFooterModes(p => p.map((m, i) => i === ci ? next : m));
    }
    function computeFooter(ci) {
        const mode = footerModes[ci];
        if (!mode) return null;
        const col = columns[ci];
        const vals = displayed
            .filter(row => !row.__groupHeader && row[col.id] !== '' && row[col.id] != null)
            .map(row => parseFloat(row[col.id])).filter(n => !isNaN(n));
        if (!vals.length) return '—';
        const sum = vals.reduce((a, b) => a + b, 0);
        let v;
        switch (mode) {
            case 'sum':   v = sum; break;
            case 'avg':   v = sum / vals.length; break;
            case 'count': return vals.length;
            case 'min':   v = Math.min(...vals); break;
            case 'max':   v = Math.max(...vals); break;
        }
        return col.type === 'currency'
            ? v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
            : col.type === 'percent' ? v.toFixed(2) + '%'
            : v % 1 === 0 ? v : v.toFixed(2);
    }

    // ── Sort ──────────────────────────────────────────────────────────────────
    function handleHeaderClick(ci) {
        if (columns[ci]?.type === 'readonly' || columns[ci]?.compute) return;
        if (editing) commit();
        if (sortCol === ci) {
            if (sortDir === 'asc') setSortDir('desc');
            else { setSortCol(null); setSortDir('asc'); }
        } else { setSortCol(ci); setSortDir('asc'); }
    }

    // ── Export ────────────────────────────────────────────────────────────────
    function exportCSV() {
        const { rows: orig, columns: cols } = ctx.current;
        const esc = v => { const s = String(v ?? ''); return s.includes(',') || s.includes('"') || s.includes('\n') ? `"${s.replace(/"/g, '""')}"` : s; };
        const editableCols = cols.filter(c => !c.compute);
        const lines = [editableCols.map(c => esc(c.label)).join(','), ...orig.map(row => editableCols.map(c => esc(row[c.id] ?? '')).join(','))];
        const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `${exportFilename}.csv` });
        a.click(); URL.revokeObjectURL(a.href);
    }

    // ── Import ────────────────────────────────────────────────────────────────
    function handleImportFile(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = ev => {
            const text = ev.target.result;
            const lines = text.split(/\r?\n/).filter(Boolean);
            if (!lines.length) return;
            const delim = lines[0].includes('\t') ? '\t' : ',';
            const parseRow = line => {
                const cells = []; let cur = ''; let inQ = false;
                for (let i = 0; i < line.length; i++) {
                    const ch = line[i];
                    if (ch === '"') { if (inQ && line[i+1] === '"') { cur += '"'; i++; } else inQ = !inQ; }
                    else if (ch === delim && !inQ) { cells.push(cur.trim()); cur = ''; }
                    else cur += ch;
                }
                cells.push(cur.trim()); return cells;
            };
            const { columns: cols } = ctx.current;
            const headers = parseRow(lines[0]).map(h => h.toLowerCase());
            const colMap = cols.map(col => headers.findIndex(h => h === col.label.toLowerCase() || h === col.id.toLowerCase()));
            const hasMatch = colMap.some(i => i >= 0);
            const dataLines = hasMatch ? lines.slice(1).filter(Boolean) : lines;
            const newRows = dataLines.map(line => {
                const cells = parseRow(line);
                const row = mkEmpty();
                if (hasMatch) {
                    cols.forEach((col, ci) => { if (colMap[ci] >= 0) row[col.id] = cells[colMap[ci]] ?? ''; });
                } else {
                    cols.filter(c => c.type !== 'readonly' && !c.compute).forEach((col, ci) => { row[col.id] = cells[ci] ?? ''; });
                }
                return row;
            });
            pushHistory(newRows);
            emitir(newRows);
        };
        reader.readAsText(file, 'UTF-8');
        e.target.value = '';
    }

    // ── Keyboard ──────────────────────────────────────────────────────────────
    function handleKeyDown(e) {
        // Quando editando, o input cuida de tudo — não processar aqui
        if (editing) return;
        const { r, c } = selA;

        if ((e.ctrlKey || e.metaKey) && e.key === 'z') { undo(); e.preventDefault(); return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'Z'))) { redo(); e.preventDefault(); return; }

        if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
            let text = '';
            for (let ri = r0; ri <= r1; ri++)
                if (!displayed[ri]?.__groupHeader)
                    text += columns.slice(c0, c1 + 1).map((_, ci) => display(ri, c0 + ci)).join('\t') + '\n';
            navigator.clipboard.writeText(text.trimEnd());
            e.preventDefault(); return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
            // growOnPaste: sai SEM preventDefault para o navegador disparar o evento `paste`
            // (o listener le o clipboardData e cresce a grade). Sem a prop, caminho antigo.
            if (growOnPaste) return;
            navigator.clipboard.readText().then(text => {
                const pRows = text.split(/\r?\n/).filter(Boolean).map(row => row.split('\t'));
                const changes = [];
                pRows.forEach((rowData, ri) => {
                    rowData.forEach((val, ci) => {
                        const tr = selA.r + ri, tc = selA.c + ci;
                        if (tr < ctx.current.R && tc < ctx.current.C && ctx.current.columns[tc]?.type !== 'readonly')
                            changes.push({ r: tr, c: tc, value: val });
                    });
                });
                if (changes.length) applyMulti(changes);
            });
            e.preventDefault(); return;
        }

        // Nav helpers that skip group headers
        function nextR(r, d) {
            let nr = clamp(r + d, 0, R - 1);
            while (nr >= 0 && nr < R && displayed[nr]?.__groupHeader) nr = clamp(nr + d, 0, R - 1);
            return nr;
        }

        switch (e.key) {
            case 'ArrowUp':    { const nr = nextR(r, -1); setSelA(p => ({ ...p, r: nr })); setSelB(e.shiftKey ? (p => ({ ...p, r: nr })) : (_ => ({ r: nr, c }))); e.preventDefault(); break; }
            case 'ArrowDown':  { const nr = nextR(r,  1); setSelA(p => ({ ...p, r: nr })); setSelB(e.shiftKey ? (p => ({ ...p, r: nr })) : (_ => ({ r: nr, c }))); e.preventDefault(); break; }
            case 'ArrowLeft':  { const nc = clamp(c-1,0,C-1); setSelA(p => ({ ...p, c: nc })); setSelB(e.shiftKey ? (p => ({ ...p, c: nc })) : (_ => ({ r, c: nc }))); e.preventDefault(); break; }
            case 'ArrowRight': { const nc = clamp(c+1,0,C-1); setSelA(p => ({ ...p, c: nc })); setSelB(e.shiftKey ? (p => ({ ...p, c: nc })) : (_ => ({ r, c: nc }))); e.preventDefault(); break; }
            case 'Tab': {
                if (tabWrap) {
                    let alvo = proximaEditavel(columns, r, c, e.shiftKey ? -1 : 1, R);
                    if (alvo.criarLinha) {
                        // Visao filtrada/ordenada nao tem onde mostrar a linha nova: fica parado.
                        if (mutado) alvo = { r, c };
                        else {
                            criarLinhaAte(R);
                            alvo = { r: R, c: Math.max(0, columns.findIndex(ehEditavel)) };
                        }
                    }
                    setSelA(alvo); setSelB(alvo);
                } else {
                    const nc = clamp(c+(e.shiftKey?-1:1),0,C-1); setSelA({ r, c: nc }); setSelB({ r, c: nc });
                }
                e.preventDefault(); break;
            }
            case 'Enter': {
                const col = columns[c];
                if (col?.type === 'select' || col?.type === 'tags' || col?.type === 'textarea' || col?.type === 'picker') { startEdit(r, c); }
                else if (tabWrap && !mutado && r === R - 1) { criarLinhaAte(R); setSelA({ r: R, c }); setSelB({ r: R, c }); }
                else { const nr = nextR(r, 1); setSelA({ r: nr, c }); setSelB({ r: nr, c }); }
                e.preventDefault(); break;
            }
            case 'F2':    startEdit(r, c); e.preventDefault(); break;
            case 'Delete':
            case 'Backspace': {
                const changes = [];
                for (let ri = r0; ri <= r1; ri++) for (let ci = c0; ci <= c1; ci++)
                    if (!displayed[ri]?.__groupHeader && columns[ci].type !== 'readonly') changes.push({ r: ri, c: ci, value: '' });
                if (changes.length) applyMulti(changes);
                e.preventDefault(); break;
            }
            default:
                if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    const col = columns[c];
                    // Espaco abre o picker (sem texto inicial); outro caractere abre com ele.
                    if (col.type === 'picker' && e.key === ' ') { startEdit(r, c); e.preventDefault(); return; }
                    if (col.type !== 'readonly' && col.type !== 'select' && col.type !== 'checkbox' && col.type !== 'tags') startEdit(r, c, e.key);
                }
        }
    }

    // ── Mouse ─────────────────────────────────────────────────────────────────
    function handleCellMouseDown(e, r, c) {
        if (e.button !== 0 || displayed[r]?.__groupHeader) return;
        // Captura se já estava na mesma célula antes de comprometer a edição atual
        const wasActiveCell = !editing && selA.r === r && selA.c === c;
        if (editing) commit();
        setSelA({ r, c }); setSelB({ r, c });
        selDrag.current = true;
        gridRef.current?.focus();
        // Select: abre no primeiro clique | tags/textarea: segundo clique
        if (columns[c]?.type === 'select') {
            startEdit(r, c);
        } else if (wasActiveCell && (columns[c]?.type === 'tags' || columns[c]?.type === 'textarea')) {
            startEdit(r, c);
        }
    }
    function handleCellMouseEnter(r, c) {
        if (selDrag.current && !displayed[r]?.__groupHeader) setSelB({ r, c });
        if (fillDrag.current) setFillEnd({ r, c });
    }
    function handleFillMouseDown(e) {
        e.preventDefault(); e.stopPropagation();
        fillDrag.current = true;
        setFillEnd({ r: selA.r, c: selA.c });
    }

    useEffect(() => {
        function up() {
            if (fillDrag.current) {
                const { fillEnd: fe, selA: sa, columns: cols } = ctx.current;
                if (fe) {
                    const dr = fe.r - sa.r, dc = fe.c - sa.c;
                    const changes = [];
                    const srcVal = cols[sa.c]?.compute ? null : (ctx.current.displayed[sa.r]?.[cols[sa.c].id] ?? '');
                    if (srcVal !== null && cols[sa.c]?.type !== 'readonly') {
                        if (Math.abs(dr) >= Math.abs(dc)) {
                            const r0f = fe.r < sa.r ? fe.r : sa.r + 1, r1f = fe.r < sa.r ? sa.r - 1 : fe.r;
                            for (let r = r0f; r <= r1f; r++) if (!ctx.current.displayed[r]?.__groupHeader) changes.push({ r, c: sa.c, value: srcVal });
                        } else {
                            const c0f = fe.c < sa.c ? fe.c : sa.c + 1, c1f = fe.c < sa.c ? sa.c - 1 : fe.c;
                            for (let c = c0f; c <= c1f; c++) if (cols[c]?.type !== 'readonly') changes.push({ r: sa.r, c, value: srcVal });
                        }
                    }
                    if (changes.length) applyMulti(changes);
                }
            }
            selDrag.current = false; fillDrag.current = false; setFillEnd(null);
        }
        window.addEventListener('mouseup', up);
        return () => window.removeEventListener('mouseup', up);
    }, []);

    // ── Freeze: sticky left positions ─────────────────────────────────────────
    function getStickyLeft(ci) {
        let left = 36;
        for (let i = 0; i < ci; i++) { if (columns[i].frozen) left += colWidths[i]; else break; }
        return left;
    }

    // ── Bulk edit ─────────────────────────────────────────────────────────────
    function applyBulk() {
        const changes = [];
        for (let ri = r0; ri <= r1; ri++) for (let ci = c0; ci <= c1; ci++)
            if (!displayed[ri]?.__groupHeader && columns[ci].type !== 'readonly') changes.push({ r: ri, c: ci, value: bulkVal });
        applyMulti(changes);
        setBulkActive(false); setBulkVal('');
    }

    // ── Render helpers ────────────────────────────────────────────────────────
    const sortIcon = ci => {
        if (sortCol !== ci) return <ArrowUpDown size={9} className="text-white/20 group-hover:text-white/40 shrink-0" />;
        return sortDir === 'asc' ? <ArrowUp size={9} className="text-ecf-yellow shrink-0" /> : <ArrowDown size={9} className="text-ecf-yellow shrink-0" />;
    };

    const isFiltered = filter || sortCol !== null || groupBy;
    const groupableCols = columns.filter(c => c.type === 'select' || c.type === 'tags');

    // ── Row num display ───────────────────────────────────────────────────────
    function rowLabel(ri) {
        if (isFiltered && ri < origIdx.length && origIdx[ri] >= 0) return origIdx[ri] + 1;
        return ri + 1;
    }

    // ── Cell render ───────────────────────────────────────────────────────────
    function renderCell(ri, ci, col) {
        const isEdit   = editing?.r === ri && editing?.c === ci;
        const val      = getVal(ri, ci);
        const dispVal  = isEdit ? editVal : display(ri, ci);
        const errKey   = `${ri}_${ci}`;
        const hasErr   = !!errors[errKey];
        const cfClass  = !isEdit && col.conditionalFormat ? (col.conditionalFormat(val, displayed[ri]) ?? '') : '';

        if (isEdit) {
            const commonCls = "w-full px-1.5 bg-[#0f1623] text-white/90 text-[12px] outline-none border-none block";
            // Select: dropdown customizado (overflow:hidden no td quebraria o nativo)
            if (col.type === 'select') return (
                <>
                    <div style={{ height: altura }} className="w-full px-2 flex items-center text-[12px] text-white/90 truncate select-none">
                        {editVal || <span className="text-white/30">—</span>}
                    </div>
                    <div
                        className="absolute left-0 top-full z-[999] min-w-full bg-[#13141c] border border-white/[0.15] shadow-2xl rounded-b-lg py-0.5"
                        onMouseDown={e => { e.preventDefault(); e.stopPropagation(); }}
                    >
                        <div
                            onMouseDown={e => { e.stopPropagation(); editingStateRef.current = null; applyMulti([{ r: ri, c: ci, value: '' }]); setEditing(null); requestAnimationFrame(() => gridRef.current?.focus()); }}
                            className="px-3 py-1.5 text-[12px] text-white/30 hover:bg-white/[0.08] cursor-pointer"
                        >—</div>
                        {col.options?.map(o => (
                            <div key={o}
                                onMouseDown={e => { e.stopPropagation(); editingStateRef.current = null; applyMulti([{ r: ri, c: ci, value: o }]); setEditing(null); requestAnimationFrame(() => gridRef.current?.focus()); }}
                                className={cn('px-3 py-1.5 text-[12px] text-white/80 hover:bg-white/[0.08] cursor-pointer', editVal === o && 'bg-white/[0.05] text-white')}
                            >{o}</div>
                        ))}
                    </div>
                </>
            );
            if (col.type === 'tags') return (
                <div className="p-1 flex flex-wrap gap-1 min-h-[26px]">
                    {(col.options ?? []).map(o => {
                        const sel = String(editVal).split(',').filter(Boolean);
                        const on = sel.includes(o);
                        return (
                            <button key={o} type="button" onMouseDown={e => { e.preventDefault(); const next = on ? sel.filter(t => t !== o) : [...sel, o]; setEditVal(next.join(',')); }}
                                className={cn('px-1.5 py-0.5 rounded text-[10px] font-medium border transition-all', on ? 'bg-ecf-yellow/20 border-ecf-yellow/40 text-ecf-yellow' : 'bg-white/[0.04] border-white/[0.08] text-white/30')}>
                                {o}
                            </button>
                        );
                    })}
                    {!col.options?.length && (
                        <input ref={inputRef} value={editVal} onChange={e => setEditVal(e.target.value)}
                            onBlur={() => commit()}
                            onKeyDown={e => { e.stopPropagation(); if (e.key === 'Escape') { editingStateRef.current = null; setEditing(null); requestAnimationFrame(() => gridRef.current?.focus()); e.preventDefault(); } else if (e.key === 'Tab') { commit(0,e.shiftKey?-1:1); e.preventDefault(); } }}
                            className="flex-1 bg-transparent text-white/90 text-[12px] outline-none" />
                    )}
                </div>
            );
            return (
                <input ref={inputRef}
                    type={['number','currency','percent'].includes(col.type) ? 'number' : col.type === 'date' ? 'date' : 'text'}
                    step={['number','currency'].includes(col.type) ? '0.01' : undefined}
                    value={editVal} onChange={e => setEditVal(e.target.value)}
                    onBlur={() => commit()}
                    onKeyDown={e => { e.stopPropagation(); if (e.key === 'Escape') { editingStateRef.current = null; setEditing(null); requestAnimationFrame(() => gridRef.current?.focus()); e.preventDefault(); } else if (e.key === 'Enter') { commit(1,0); e.preventDefault(); } else if (e.key === 'Tab') { commit(0,e.shiftKey?-1:1); e.preventDefault(); } }}
                    className={commonCls} style={{ minWidth: 0, height: altura }} />
            );
        }

        // Display mode
        if (col.renderCell) return (
            <div style={{ height: altura, overflow: 'hidden' }} className={cn('w-full', cfClass)}>
                {col.renderCell(val, displayed[ri])}
            </div>
        );
        if (col.type === 'checkbox') {
            const checked = val === true || val === '1' || val === 'true';
            return (
                <div className="w-full h-full flex items-center justify-center">
                    <div className={cn('w-4 h-4 rounded border-2 flex items-center justify-center', checked ? 'border-ecf-yellow bg-ecf-yellow' : 'border-white/20')}>
                        {checked && <Check size={10} className="text-[#252525]" />}
                    </div>
                </div>
            );
        }
        if (col.type === 'tags') return (
            <div style={{ height: altura, overflow: 'hidden' }} className="w-full px-1.5 flex items-center">
                <TagPills value={val} options={col.options} max={3} />
            </div>
        );
        if (col.type === 'url' && val) return (
            <a href={String(val)} target="_blank" rel="noreferrer" onClick={e => e.stopPropagation()}
                style={{ height: altura, overflow: 'hidden' }} className="w-full px-2 flex items-center text-[12px] text-blue-400 hover:underline truncate">
                {String(val)}
            </a>
        );
        return (
            <div style={{ height: altura, overflow: 'hidden' }} className={cn('w-full px-2 flex items-center truncate', portal ? 'text-[13px]' : 'text-[12px]', col.type === 'readonly' ? 'text-white/50' : 'text-white/85', portal && (col.type === 'readonly' || col.compute) && 'text-white/60', col.align === 'right' && 'justify-end', cfClass)}>
                {dispVal !== '' && dispVal != null ? dispVal : (col.placeholder && col.type !== 'readonly' && !col.compute ? <span className="text-white/25">{col.placeholder}</span> : dispVal)}
            </div>
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    return (
        <div className="space-y-2">
            {/* ── Toolbar ── */}
            <div className="flex items-center gap-2 flex-wrap">
                {/* Filtro */}
                <div className="relative min-w-[160px] max-w-xs flex-1">
                    <Search size={11} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                    <input type="text" value={filter} onChange={e => setFilter(e.target.value)} placeholder="Filtrar..."
                        className="w-full h-8 pl-7 pr-6 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20" />
                    {filter && <button onClick={() => setFilter('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-white/30 hover:text-white/60"><X size={11} /></button>}
                </div>

                {/* GroupBy */}
                {groupableCols.length > 0 && (
                    <select value={groupBy ?? ''} onChange={e => setGroupBy(e.target.value || null)}
                        className="h-8 pl-2.5 pr-6 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/50 text-[12px] focus:outline-none focus:border-ecf-yellow/40">
                        <option value="">Agrupar por...</option>
                        {groupableCols.map(c => <option key={c.id} value={c.id} className="bg-[#0d0e14]">{c.label}</option>)}
                    </select>
                )}

                {/* Sort ativo */}
                {sortCol !== null && (
                    <div className="flex items-center gap-1 px-2 py-1 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow text-[11px]">
                        {sortDir === 'asc' ? <ArrowUp size={10} /> : <ArrowDown size={10} />}
                        {columns[sortCol]?.label}
                        <button onClick={() => { setSortCol(null); setSortDir('asc'); }} className="ml-1 opacity-60 hover:opacity-100"><X size={10} /></button>
                    </div>
                )}

                {/* Bulk edit */}
                {r1 > r0 && !bulkActive && (
                    <button onClick={() => setBulkActive(true)}
                        className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.07] text-white/50 hover:text-white text-[11px] transition-all">
                        <Edit3 size={11} /> Preencher seleção
                    </button>
                )}
                {bulkActive && (
                    <div className="flex items-center gap-1.5">
                        <input type="text" value={bulkVal} onChange={e => setBulkVal(e.target.value)} placeholder="Novo valor..."
                            className="h-8 px-2.5 rounded-lg border border-ecf-yellow/40 bg-white/[0.03] text-white text-[12px] focus:outline-none w-32"
                            onKeyDown={e => { if (e.key === 'Enter') applyBulk(); if (e.key === 'Escape') { setBulkActive(false); setBulkVal(''); } }}
                            autoFocus />
                        <button onClick={applyBulk} className="h-8 px-3 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[12px]">OK</button>
                        <button onClick={() => { setBulkActive(false); setBulkVal(''); }} className="p-1.5 text-white/30 hover:text-white/60"><X size={12} /></button>
                    </div>
                )}

                <div className="flex items-center gap-1.5 ml-auto">
                    <span className="text-white/20 text-[11px] mr-1">
                        {isFiltered
                            ? `${displayed.filter(r => !r.__groupHeader).length} de ${rows.length}`
                            : `${rows.filter(r => columns.some(c => !c.compute && r[c.id]?.toString().trim())).length} linha(s)`}
                    </span>

                    {/* Undo/Redo */}
                    <button onClick={undo} title="Desfazer (Ctrl+Z)"
                        className="flex items-center justify-center w-7 h-7 rounded-lg border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.07] text-white/40 hover:text-white text-[11px] transition-all">
                        ↩
                    </button>
                    <button onClick={redo} title="Refazer (Ctrl+Y)"
                        className="flex items-center justify-center w-7 h-7 rounded-lg border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.07] text-white/40 hover:text-white text-[11px] transition-all">
                        ↪
                    </button>

                    {showImportExport && (<>
                    <button onClick={() => fileRef.current?.click()}
                        className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.07] text-white/50 hover:text-white text-[11px] transition-all" title="Importar CSV">
                        <Upload size={11} /> Importar
                    </button>
                    <input ref={fileRef} type="file" accept=".csv,.tsv,.txt" className="hidden" onChange={handleImportFile} />

                    <button onClick={exportCSV}
                        className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.07] text-white/50 hover:text-white text-[11px] transition-all" title="Exportar CSV">
                        <Download size={11} /> Exportar
                    </button>
                    </>)}
                </div>
            </div>

            {/* ── Grid ── */}
            <div className="overflow-auto select-none">
                <div ref={gridRef} tabIndex={0} onKeyDown={handleKeyDown} className="outline-none inline-block min-w-full"
                    role={ariaLabel ? 'grid' : undefined} aria-label={ariaLabel ?? undefined}>
                    <table className="border-collapse" style={{ tableLayout: 'fixed' }}>
                        <thead>
                            {headerGroups && (
                                <tr>
                                    <th style={{ width: 36, minWidth: 36 }} className="bg-[#12131a] border border-white/[0.07] sticky left-0 z-20" />
                                    {headerGroups.map((g, i) => (
                                        <th key={i} colSpan={g.span}
                                            className={cn('text-center text-[10px] font-bold uppercase tracking-wider py-1.5 border border-white/[0.07] bg-[#12131a]', g.className ?? 'text-white/40')}>
                                            {g.label}
                                        </th>
                                    ))}
                                </tr>
                            )}
                            <tr>
                                <th style={{ width: 36, minWidth: 36 }}
                                    className="bg-[#12131a] border border-white/[0.07] sticky left-0 z-20" />
                                {columns.map((col, ci) => {
                                    const frozen = col.frozen;
                                    return (
                                        <th key={col.id} role="columnheader"
                                            style={{ width: colWidths[ci], minWidth: 40, ...(frozen ? { position: 'sticky', left: getStickyLeft(ci), zIndex: 15 } : {}) }}
                                            onClick={() => handleHeaderClick(ci)}
                                            className={cn(
                                                'relative group text-left px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/40 bg-[#12131a] border border-white/[0.07] whitespace-nowrap overflow-hidden',
                                                portal && 'h-10 text-[12px] normal-case tracking-normal bg-ecf-card-2 sticky top-0 z-[12]',
                                                col.separador && 'border-l border-white/[0.08]',
                                                col.type !== 'readonly' && !col.compute && 'cursor-pointer hover:bg-white/[0.04] hover:text-white/60',
                                                sortCol === ci && 'text-ecf-yellow/80 bg-ecf-yellow/5',
                                                frozen && 'shadow-[2px_0_4px_rgba(0,0,0,0.3)]',
                                            )}>
                                            <div className="flex items-center gap-1 pr-2">
                                                <span className="truncate">{col.label}</span>
                                                {col.type !== 'readonly' && !col.compute && sortIcon(ci)}
                                                {col.validate?.required && <span className="text-red-400 ml-0.5">*</span>}
                                            </div>
                                            {/* Resize handle */}
                                            <div
                                                className="absolute top-0 right-0 h-full w-1.5 cursor-col-resize hover:bg-ecf-yellow/50 z-10"
                                                onMouseDown={e => { e.preventDefault(); e.stopPropagation(); resizing.current = { ci, sx: e.clientX, sw: colWidths[ci] }; }}
                                            />
                                        </th>
                                    );
                                })}
                                {rowActions && <th role="columnheader" style={{ width: 56, minWidth: 56 }} className={cn('border border-white/[0.07] bg-[#12131a] sticky right-0 z-20', portal && 'bg-ecf-card-2 top-0')} />}
                            </tr>
                        </thead>

                        <tbody>
                            {displayed.map((row, ri) => {
                                // Group header row
                                if (row.__groupHeader) {
                                    return (
                                        <tr key={`g${ri}`}>
                                            <td colSpan={C + 1 + (rowActions ? 1 : 0)}
                                                className="border border-white/[0.07] bg-white/[0.04] px-3 py-1.5 cursor-pointer"
                                                onClick={() => setCollapsed(p => { const n = new Set(p); n.has(row.__val) ? n.delete(row.__val) : n.add(row.__val); return n; })}>
                                                <div className="flex items-center gap-2 text-white/60 text-[11px] font-semibold">
                                                    {collapsed.has(row.__val) ? <ChevronRight size={12} /> : <ChevronDown size={12} />}
                                                    <span>{row.__val}</span>
                                                    <span className="text-white/30 font-normal">({row.__count})</span>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                }

                                const nota = rowNote ? rowNote(row, ri) : null;
                                return (
                                    <Fragment key={rowKey ? (row[rowKey] ?? ri) : ri}>
                                    <tr className={cn(rowClassName?.(row, ri))}>
                                        {/* Row number */}
                                        <td
                                            className="text-center text-[10px] text-white/20 bg-[#12131a] border border-white/[0.07] cursor-pointer hover:bg-white/[0.04] sticky left-0 z-10"
                                            style={{ height: altura }}
                                            onDoubleClick={() => setPanelRow(ri)}
                                            title="Duplo clique para abrir painel"
                                        >
                                            {rowLabel(ri)}
                                        </td>

                                        {columns.map((col, ci) => {
                                            const active  = ri === selA.r && ci === selA.c;
                                            const inSel   = ri >= r0 && ri <= r1 && ci >= c0 && ci <= c1;
                                            const inFill  = inFillPrev(ri, ci);
                                            const isEdit  = editing?.r === ri && editing?.c === ci;
                                            const hasErr  = !!errors[`${ri}_${ci}`];
                                            const frozen  = col.frozen;

                                            return (
                                                <td key={col.id}
                                                    style={{ height: altura, padding: 0, position: frozen ? 'sticky' : 'relative', ...(frozen ? { left: getStickyLeft(ci), zIndex: 5 } : {}) }}
                                                    className={cn(
                                                        isEdit && col.type === 'select' ? 'text-[12px] overflow-visible z-[100]' : 'text-[12px] overflow-hidden',
                                                        frozen && 'sticky shadow-[2px_0_4px_rgba(0,0,0,0.3)]',
                                                        isEdit   ? 'border-2 border-[#2563eb] z-20' :
                                                        hasErr   ? 'border border-red-500/70 bg-red-900/10' :
                                                        active   ? 'border-2 border-[#2563eb]' :
                                                        inFill   ? 'border border-dashed border-[#2563eb]/70 bg-blue-500/5' :
                                                        inSel    ? 'border border-white/[0.06] bg-blue-500/10' :
                                                                   'border border-white/[0.06]',
                                                        col.type === 'readonly' && 'bg-white/[0.015]',
                                                        col.type !== 'readonly' && !isEdit && 'cursor-cell',
                                                        frozen && !isEdit && !active && !inSel && 'bg-[#0b0c12]',
                                                        // Aparencia "portal": editavel parece campo, ativa tem contorno amarelo,
                                                        // calculada some o fundo. Vem por ultimo para sobrepor as classes de cima.
                                                        portal && ((col.type === 'readonly' || col.compute) ? 'bg-transparent cursor-default' : !hasErr && !inSel && !inFill && !isEdit && !active && 'bg-black/40 hover:bg-white/[0.04] cursor-text'),
                                                        portal && (isEdit || active) && 'border border-white/[0.06] outline outline-1 -outline-offset-1 outline-ecf-yellow/60',
                                                        col.separador && 'border-l border-white/[0.08]',
                                                    )}
                                                    aria-readonly={(col.type === 'readonly' || col.compute) ? 'true' : undefined}
                                                    data-cell={`${ri}-${ci}`}
                                                    onMouseDown={e => handleCellMouseDown(e, ri, ci)}
                                                    onMouseEnter={() => handleCellMouseEnter(ri, ci)}
                                                    onDoubleClick={() => startEdit(ri, ci)}
                                                >
                                                    {renderCell(ri, ci, col)}

                                                    {/* Error tooltip */}
                                                    {hasErr && !isEdit && (
                                                        <div className="absolute top-0 right-0 w-0 h-0 border-t-[6px] border-r-[6px] border-t-transparent border-r-red-500 z-30" title={errors[`${ri}_${ci}`]} />
                                                    )}

                                                    {/* Fill handle */}
                                                    {active && !editing && (
                                                        <div className="absolute bottom-0 right-0 w-[7px] h-[7px] bg-[#2563eb] cursor-crosshair z-30"
                                                            onMouseDown={handleFillMouseDown} />
                                                    )}
                                                </td>
                                            );
                                        })}
                                        {rowActions && (
                                            <td style={{ width: 56, minWidth: 56, height: altura, padding: 0 }}
                                                className="sticky right-0 z-10 bg-[#0b0c12] border border-white/[0.06]">
                                                {rowActions(row, ri)}
                                            </td>
                                        )}
                                    </tr>
                                    {nota && (
                                        <tr>
                                            <td colSpan={C + 1 + (rowActions ? 1 : 0)} className="border border-white/[0.06] p-0">{nota}</td>
                                        </tr>
                                    )}
                                    </Fragment>
                                );
                            })}
                        </tbody>

                        {/* Footer */}
                        {!portal && footerModes.some(m => m !== null) && (
                            <tfoot>
                                <tr>
                                    <td className="bg-[#12131a] border border-white/[0.07] sticky left-0 text-center text-[9px] text-white/20 px-1" style={{ height: 24 }}>
                                        <Layers size={10} className="mx-auto" />
                                    </td>
                                    {columns.map((col, ci) => {
                                        const val = computeFooter(ci);
                                        const mode = footerModes[ci];
                                        const canCycle = ['number','currency','percent'].includes(col.type);
                                        return (
                                            <td key={col.id}
                                                onClick={() => canCycle && cycleFooter(ci)}
                                                className={cn('border border-white/[0.07] bg-[#12131a] px-2 text-[11px] text-right', canCycle && 'cursor-pointer hover:bg-white/[0.04]')}
                                                style={{ height: 24 }}>
                                                {val !== null && (
                                                    <div className="flex items-center justify-end gap-1">
                                                        {mode && <span className="text-white/20 text-[9px] uppercase">{mode}</span>}
                                                        <span className="text-white/60 font-medium">{val}</span>
                                                    </div>
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>

            {/* Adicionar linhas */}
            {!filter && !sortCol && !groupBy && (
                <button
                    onClick={() => { const n = [...rows, ...Array.from({length: 10}, novaLinha)]; pushHistory(n); emitir(n); }}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-white/[0.07] bg-white/[0.02] hover:bg-white/[0.05] text-white/30 hover:text-white/60 text-[11px] transition-all"
                >
                    + 10 linhas
                </button>
            )}

            <p className="text-white/15 text-[10px]">
                Cabeçalho: ordenar · Duplo clique no nº da linha: painel · Arrastar quadrado azul: preencher · Ctrl+C/V · Ctrl+Z/Y: desfazer/refazer
            </p>

            {/* Textarea popup */}
            {textareaPopup && (
                <TextareaPopup
                    label={columns[textareaPopup.c]?.label ?? ''}
                    value={textareaPopup.value}
                    onChange={v => setTextareaPopup(p => ({ ...p, value: v }))}
                    onSave={() => {
                        // Só grava se mudou — abrir e fechar sem editar não suja o histórico
                        // nem dispara o autosave do formulário do cliente.
                        if (textareaPopup.value !== textareaPopup.orig) {
                            applyMulti([{ r: textareaPopup.r, c: textareaPopup.c, value: textareaPopup.value }]);
                        }
                        setTextareaPopup(null);
                        requestAnimationFrame(() => gridRef.current?.focus());
                    }}
                    onCancel={() => { setTextareaPopup(null); requestAnimationFrame(() => gridRef.current?.focus()); }}
                />
            )}

            {/* Picker: popover da coluna type 'picker' */}
            {pickerAberto && columns[pickerAberto.c]?.renderEditor && !displayed[pickerAberto.r]?.__groupHeader && (
                <CamadaPicker anchor={pickerAberto.anchor}
                    onFora={() => (fecharRef.current ? fecharRef.current() : fecharPicker())}>
                    {columns[pickerAberto.c].renderEditor({
                        row: displayed[pickerAberto.r],
                        value: getVal(pickerAberto.r, pickerAberto.c),
                        anchor: pickerAberto.anchor,
                        textoInicial: pickerAberto.textoInicial,
                        onCommit: patch => aplicarPatch(pickerAberto.r, patch),
                        onClose: fecharPicker,
                        registrarFechar: fn => { fecharRef.current = fn; },
                    })}
                </CamadaPicker>
            )}

            {/* Row Panel */}
            {panelRow !== null && !displayed[panelRow]?.__groupHeader && (
                <RowPanel
                    row={displayed[panelRow]}
                    columns={columns}
                    rowNum={rowLabel(panelRow)}
                    onSave={form => {
                        const changes = Object.entries(form).map(([k, v]) => {
                            const ci = columns.findIndex(c => c.id === k);
                            return ci >= 0 ? { r: panelRow, c: ci, value: v } : null;
                        }).filter(Boolean);
                        applyMulti(changes);
                    }}
                    onClose={() => setPanelRow(null)}
                />
            )}
        </div>
    );
}
