import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo } from 'react';
import { Search, CheckCircle2, AlertTriangle, Rocket, Save, Loader2, Tag, PackageOpen, Store, Copy, Check, ChevronLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';

// ─── Badges de status do rascunho ───
const STATUS_BADGE = {
    rascunho:   'bg-white/5 border border-white/15 text-white/70',
    validado:   'bg-blue-500/10 border border-blue-500/30 text-blue-400',
    publicando: 'bg-ecf-yellow/10 border border-ecf-yellow/30 text-ecf-yellow',
    publicado:  'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    erro:       'bg-red-500/10 border border-red-500/30 text-red-400',
};
const STATUS_LABEL = {
    rascunho: 'Rascunho', validado: 'Validado', publicando: 'Publicando…',
    publicado: 'Publicado', erro: 'Erro',
};

const CONDICOES = [
    { v: 'new', l: 'Novo' },
    { v: 'used', l: 'Usado' },
    { v: 'not_specified', l: 'Não especificado' },
];

// ─── Fallback de cópia para contextos sem Clipboard API (ex.: http não-seguro) ───
function fallbackCopiar(txt, done) {
    try {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        done?.();
    } catch { /* silencioso: sem clipboard disponível */ }
}

// ─── Chip de SKU clicável — copia o SKU com um clique (DRAFT-05) ───
function SkuCopyChip({ sku }) {
    const [copiado, setCopiado] = useState(false);
    if (!sku) return null;

    const copiar = (ev) => {
        ev.stopPropagation();
        const txt = String(sku);
        const ok = () => { setCopiado(true); setTimeout(() => setCopiado(false), 1200); };
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(txt).then(ok).catch(() => fallbackCopiar(txt, ok));
        } else {
            fallbackCopiar(txt, ok);
        }
    };

    return (
        <button
            type="button"
            onClick={copiar}
            title={copiado ? 'Copiado!' : `Copiar SKU (${sku})`}
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-mono tabular-nums transition shrink-0',
                copiado
                    ? 'border-emerald-400/40 bg-emerald-500/[0.12] text-emerald-300'
                    : 'border-white/10 bg-white/[0.04] text-white/45 hover:text-white/80 hover:border-white/25',
            )}
        >
            {copiado ? <Check size={10} /> : <Copy size={10} />}
            {copiado ? 'copiado' : sku}
        </button>
    );
}

// ─── Input padrão ECF com badge de origem (DRAFT-04) ───
// origem === 'cliente'    → badge violet
// origem === 'publicador' → badge amber ('editado')
// origem ausente          → sem badge (comportamento original preservado)
function Campo({ label, children, dica, origem }) {
    return (
        <label className="block">
            <span className="mb-1 flex items-center gap-1.5 text-xs font-medium text-white/60">
                {label}
                {origem === 'cliente' && (
                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-violet-500/10 text-violet-300/80">
                        cliente
                    </span>
                )}
                {origem === 'publicador' && (
                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300/80">
                        editado
                    </span>
                )}
            </span>
            {children}
            {dica && <span className="mt-1 block text-[11px] text-white/40">{dica}</span>}
        </label>
    );
}

const inputCls = 'w-full rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-sm text-white placeholder-white/30 focus:border-ecf-yellow/50 focus:outline-none';

// ─── Formata valor em BRL ───
const fmtBRL = (n) => n != null ? `R$ ${Number(n).toFixed(2).replace('.', ',')}` : '—';

/**
 * Wizard de criação de anúncio — Momento 2 do módulo "Anunciar ML".
 *
 * Recebe `empresa` já fixada (passada pelo backend via rota mlb.anuncios.wizard).
 * NÃO exibe seletor de empresa — a empresa de destino é imutável no wizard (SEL-01).
 *
 * SEL-01: empresa fixada no header; ausência de seleção inline.
 * SEL-05: modal de confirmação antes de publicar, exibindo o nome da empresa.
 * SEL-07: salvar() envia mlb_empresa_id (não company_id) no POST rascunho.store.
 *
 * DRAFT-01: lista de produtos do cliente exibida antes do formulário.
 * DRAFT-04: badge violet 'cliente' / amber 'editado' por campo.
 * DRAFT-05: SkuCopyChip — copiar SKU com um clique + fallback.
 */
export default function AnunciarML({ empresa = null, rascunhos = [], produtos = [] }) {
    const [rascunhoId, setRascunhoId] = useState(null);

    // Campos do anúncio
    const [titulo, setTitulo]         = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [categoria, setCategoria]   = useState(null);   // settings da categoria
    const [atributos, setAtributos]   = useState([]);     // catálogo de atributos
    const [valores, setValores]       = useState({});     // {attrId: {value_id|value_name}}
    const [preco, setPreco]           = useState('');
    const [estoque, setEstoque]       = useState('1');
    const [condicao, setCondicao]     = useState('new');
    const [tipoAnuncio, setTipoAnuncio] = useState('gold_special');
    const [imagemUrl, setImagemUrl]   = useState('');
    const [garantia, setGarantia]     = useState('30 dias');
    const [descricao, setDescricao]   = useState('');
    // Peso e dimensões do pacote — necessários para o ML calcular o frete me2
    const [pesoG, setPesoG]                 = useState('');
    const [comprimentoCm, setComprimentoCm] = useState('');
    const [larguraCm, setLarguraCm]         = useState('');
    const [alturaCm, setAlturaCm]           = useState('');

    // ─── Rastreamento de origem dos campos (DRAFT-04) ───
    // Mapa { campo: 'cliente' | 'publicador' } — populado por hidratarDoRascunho()
    const [origemCampos, setOrigemCampos] = useState({});

    const [candidatos, setCandidatos] = useState([]);
    const [tipos, setTipos]           = useState([]);
    const [erros, setErros]           = useState(null);   // {valido, erros:[]}
    const [busy, setBusy]             = useState('');      // '', 'prever', 'attrs', 'salvar', 'validar', 'publicar', 'criar'

    const [flash, setFlash]           = useState('');

    // SEL-05: modal de confirmação — null=fechado, true=aguardando confirmação
    const [confirmPublicar, setConfirmPublicar] = useState(false);

    // Tipos de anúncio (uma vez)
    useEffect(() => {
        window.axios.get(route('mlb.anuncios.meta.tipos'))
            .then(r => setTipos(Array.isArray(r.data) ? r.data : []))
            .catch(() => {});
    }, []);

    // ─── Hidrata o formulário a partir de um rascunho pré-preenchido (DRAFT-01) ───
    // Lê rascunho.payload e popula os estados existentes.
    // parseFloat ignora sufixos ' g' / ' cm' nativamente — sem regex.
    const hidratarDoRascunho = (rascunho) => {
        const payload = rascunho.payload ?? {};

        // Campos básicos
        if (payload.title != null)              setTitulo(payload.title);
        if (payload.price != null)              setPreco(String(payload.price));
        if (payload.available_quantity != null) setEstoque(String(payload.available_quantity));
        if (payload.description != null)        setDescricao(payload.description);

        // Extrai SELLER_PACKAGE_* de payload.attributes → states individuais de dimensão
        const attrs = Array.isArray(payload.attributes) ? payload.attributes : [];
        attrs.forEach(attr => {
            const v = parseFloat(attr.value_name); // parseFloat ignora sufixo ' g' / ' cm'
            if (isNaN(v)) return;
            if (attr.id === 'SELLER_PACKAGE_WEIGHT') setPesoG(String(v));
            if (attr.id === 'SELLER_PACKAGE_HEIGHT') setAlturaCm(String(v));
            if (attr.id === 'SELLER_PACKAGE_WIDTH')  setLarguraCm(String(v));
            if (attr.id === 'SELLER_PACKAGE_LENGTH') setComprimentoCm(String(v));
        });

        // Popula origemCampos a partir de meta_campos gravado pelo backend
        if (payload.meta_campos && typeof payload.meta_campos === 'object') {
            setOrigemCampos({ ...payload.meta_campos });
        }
    };

    // ─── Marca um campo como 'publicador' quando editado (DRAFT-04) ───
    // Só age se o campo já estava mapeado como 'cliente' — não polui campos livres.
    const marcarEditado = (campo) => {
        setOrigemCampos(o => {
            if (!o[campo]) return o; // campo não vem do cliente → sem badge
            return { ...o, [campo]: 'publicador' };
        });
    };

    // ─── Limpa o formulário para escolher outro produto ───
    const limparFormulario = () => {
        setRascunhoId(null);
        setTitulo('');
        setCategoryId('');
        setCategoria(null);
        setAtributos([]);
        setValores({});
        setPreco('');
        setEstoque('1');
        setCondicao('new');
        setTipoAnuncio('gold_special');
        setImagemUrl('');
        setGarantia('30 dias');
        setDescricao('');
        setPesoG('');
        setComprimentoCm('');
        setLarguraCm('');
        setAlturaCm('');
        setOrigemCampos({});
        setCandidatos([]);
        setErros(null);
        setFlash('');
    };

    // ─── Cria rascunho pré-preenchido a partir de um produto do cliente (DRAFT-01) ───
    const criarRascunhoProduto = async (produto) => {
        setBusy('criar');
        setFlash('');
        try {
            const r = await window.axios.post(
                route('mlb.anuncios.rascunho.por-produto', { company: empresa.id }),
                { sku: produto.sku },
            );
            hidratarDoRascunho(r.data.rascunho);
            setRascunhoId(r.data.rascunho.id);
            if (r.data.preco_indisponivel) {
                setFlash('Rascunho criado — preço não calculado (custo ausente na precificação do cliente).');
            } else {
                setFlash('Rascunho criado com dados do cliente.');
            }
        } catch (e) {
            if (e.response?.status === 422) {
                setFlash('Produto não encontrado na precificação do cliente.');
            } else {
                setFlash('Erro ao criar rascunho — tente novamente.');
            }
        } finally {
            setBusy('');
        }
    };

    // ─── Preditor de categoria ───
    const preverCategoria = async () => {
        if (!titulo.trim()) return;
        setBusy('prever');
        try {
            const r = await window.axios.get(route('mlb.anuncios.meta.prever'), { params: { q: titulo } });
            setCandidatos(Array.isArray(r.data) ? r.data : []);
        } finally { setBusy(''); }
    };

    // ─── Escolher categoria → carrega atributos ───
    const escolherCategoria = async (cat) => {
        setCategoryId(cat.category_id);
        setCandidatos([]);
        setValores({});
        setBusy('attrs');
        try {
            const r = await window.axios.get(route('mlb.anuncios.meta.atributos', { categoryId: cat.category_id }));
            setCategoria(r.data.categoria ?? null);
            setAtributos(Array.isArray(r.data.atributos) ? r.data.atributos : []);
        } finally { setBusy(''); }
    };

    // Só os atributos obrigatórios (o essencial do MVP), sem grade de moda
    const obrigatorios = useMemo(
        () => atributos.filter(a => a.tags?.required && a.id !== 'SIZE_GRID_ID' && !String(a.id).includes('GRID')),
        [atributos],
    );
    const exigeGrade = useMemo(
        () => atributos.some(a => a.tags?.required && (a.id === 'SIZE_GRID_ID' || String(a.id).includes('GRID'))),
        [atributos],
    );

    const setValor = (id, patch) => setValores(v => ({ ...v, [id]: { ...v[id], ...patch } }));

    // ─── Monta o payload no shape que o backend (ItemBuilder) espera ───
    const montarPayload = () => {
        // Mapa id → definição do atributo (value_type, default_unit) para normalizar unidades
        const defAttr = {};
        (atributos || []).forEach(a => { defAttr[a.id] = a; });

        // Atributos number_unit (ex.: WIDTH/HEIGHT/DEPTH) exigem valor COM unidade no ML
        // ("100 cm", não "100"). Se o publicador digitou só o número, anexa a unidade padrão.
        const normalizarValor = (id, valueName) => {
            const def = defAttr[id];
            const bruto = String(valueName).trim();
            if (def?.value_type === 'number_unit' && /^[\d.,]+$/.test(bruto)) {
                const unidade = def.default_unit || 'cm';
                return `${bruto} ${unidade}`;
            }
            return bruto;
        };

        const attributes = Object.entries(valores)
            .filter(([, v]) => v && (v.value_id || v.value_name))
            .map(([id, v]) => (v.value_id
                ? { id, value_id: v.value_id }
                : { id, value_name: normalizarValor(id, v.value_name) }));

        // Peso e dimensões do pacote viram atributos SELLER_PACKAGE_* (habilitam o me2)
        const pacote = [];
        if (pesoG)         pacote.push({ id: 'SELLER_PACKAGE_WEIGHT', value_name: `${pesoG} g` });
        if (alturaCm)      pacote.push({ id: 'SELLER_PACKAGE_HEIGHT', value_name: `${alturaCm} cm` });
        if (comprimentoCm) pacote.push({ id: 'SELLER_PACKAGE_LENGTH', value_name: `${comprimentoCm} cm` });
        if (larguraCm)     pacote.push({ id: 'SELLER_PACKAGE_WIDTH',  value_name: `${larguraCm} cm` });

        return {
            title: titulo,
            category_id: categoryId,
            price: preco ? Number(preco) : null,
            currency_id: 'BRL',
            available_quantity: estoque ? Number(estoque) : null,
            condition: condicao,
            listing_type_id: tipoAnuncio,
            attributes: [...attributes, ...pacote],
            pictures: imagemUrl ? [{ source: imagemUrl }] : [],
            sale_terms: garantia
                ? [{ id: 'WARRANTY_TYPE', value_name: 'Garantia do vendedor' }, { id: 'WARRANTY_TIME', value_name: garantia }]
                : [],
            shipping: { mode: 'me2', local_pick_up: false, free_shipping: false },
            description: descricao,
        };
    };

    // ─── Salva/atualiza o rascunho ───
    const salvar = async () => {
        setBusy('salvar');
        try {
            const payload = montarPayload();
            let id = rascunhoId;
            if (!id) {
                // Envia company_id (âncora = empresa com conta ML) — fixada na criação
                const r = await window.axios.post(route('mlb.anuncios.rascunho.store'), {
                    company_id: empresa.id, category_id: categoryId || null, payload,
                });
                id = r.data.rascunho.id;
                setRascunhoId(id);
            } else {
                // SEL-03: update não envia empresa — company_id e mlb_empresa_id são imutáveis
                await window.axios.put(route('mlb.anuncios.rascunho.update', { rascunho: id }), {
                    category_id: categoryId || null, payload,
                });
            }
            setFlash('Rascunho salvo.');
            return id;
        } finally { setBusy(''); }
    };

    // ─── Valida no ML (tempo real) ───
    const validar = async () => {
        const id = await salvar();
        if (!id) return;
        setBusy('validar');
        try {
            const r = await window.axios.post(route('mlb.anuncios.validar', { rascunho: id }));
            setErros(r.data);
            setFlash(r.data.valido ? 'Anúncio válido — pronto para publicar.' : 'Há pendências antes de publicar.');
        } finally { setBusy(''); }
    };

    // ─── Publica de verdade (chamado após confirmação no modal) ───
    const publicar = async () => {
        const id = await salvar();
        if (!id) return;
        setBusy('publicar');
        try {
            const r = await window.axios.post(route('mlb.anuncios.publicar', { rascunho: id }));
            if (r.data.ok) {
                setErros({ valido: true, erros: [] });
                setFlash(`Publicado! Anúncio ${r.data.ml_item_id || ''} criado na conta do cliente.`);
            } else {
                setErros({ valido: false, erros: r.data.erros ?? [] });
                setFlash('Não foi possível publicar — veja as pendências ao lado.');
            }
        } catch (e) {
            if (e.response?.status === 422) { setErros({ valido: false, erros: e.response.data.erros ?? [] }); setFlash('Não foi possível publicar — veja as pendências ao lado.'); }
            else { setFlash('Falha ao publicar — tente novamente.'); }
        } finally { setBusy(''); }
    };

    // SEL-05: confirmar publicação no modal → fecha o modal e chama publicar()
    const confirmarPublicacao = () => {
        setConfirmPublicar(false);
        publicar();
    };

    // Guarda de rota: se empresa não foi passada, o usuário acessou o wizard diretamente
    if (!empresa) {
        return (
            <AppLayout>
                <div className="mx-auto max-w-6xl px-4 py-6">
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-10 text-center text-white/40">
                        Abra o wizard a partir do painel de empresas.
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl px-4 py-6">
                <header className="mb-6">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-white">
                        <Rocket size={20} className="text-ecf-yellow" /> Anunciar no Mercado Livre
                    </h1>
                    <p className="mt-1 text-sm text-white/50">Monte o anúncio aqui e publique direto na conta do cliente — sem entrar no painel do ML.</p>

                    {/* SEL-01: empresa fixada — sempre visível no header do wizard */}
                    <div className="mt-3 flex items-center gap-2 rounded-lg border border-white/[0.08] bg-ecf-card px-4 py-2.5">
                        <Store size={15} className="shrink-0 text-white/40" />
                        <span className="text-xs text-white/50">Publicando na conta:</span>
                        <span className="text-sm font-semibold text-white">{empresa?.nome ?? '—'}</span>
                    </div>
                </header>

                {flash && (
                    <div className="mb-4 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-4 py-2 text-sm text-ecf-yellow">{flash}</div>
                )}

                {/* ─── Seção "Produtos do cliente" (DRAFT-01) ───
                    Visível quando ainda não há rascunho aberto e a empresa tem produtos cadastrados.
                    Ao clicar "Criar rascunho", o rascunho é criado (76-01) e o formulário é hidratado.
                    A seção recolhe automaticamente quando rascunhoId é setado. */}
                {!rascunhoId && produtos.length > 0 && (
                    <div className="mb-6 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <div className="mb-3">
                            <h2 className="text-sm font-semibold text-white">Produtos do cliente</h2>
                            <p className="mt-0.5 text-[11px] text-white/40">
                                Dados que o cliente preencheu — clique em Criar rascunho para começar já preenchido.
                            </p>
                        </div>

                        <div className="space-y-2">
                            {produtos.map((p, idx) => (
                                <div
                                    key={p.sku ?? idx}
                                    className="flex flex-wrap items-center gap-3 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2.5"
                                >
                                    {/* SKU copiável (DRAFT-05) */}
                                    <SkuCopyChip sku={p.sku} />

                                    {/* Nome do produto */}
                                    <span className="flex-1 min-w-0 truncate text-sm text-white/80 font-medium">
                                        {p.produto ?? '—'}
                                    </span>

                                    {/* Dimensões resumidas */}
                                    {p.tem_dimensoes ? (
                                        <span className="text-[11px] text-white/40 shrink-0">
                                            {p.altura}×{p.largura}×{p.profundidade} cm · {p.peso_kg} kg
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300/80 shrink-0">
                                            <AlertTriangle size={10} /> dimensões incompletas
                                        </span>
                                    )}

                                    {/* Estoque */}
                                    {p.estoque != null && (
                                        <span className="text-[11px] text-white/40 shrink-0">
                                            estq {p.estoque}
                                        </span>
                                    )}

                                    {/* Preço anunciado clássico */}
                                    {p.tem_preco ? (
                                        <span className="text-[11px] font-semibold text-ecf-yellow shrink-0">
                                            {fmtBRL(p.preco_anunciado_c)}
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300/80 shrink-0">
                                            sem preço
                                        </span>
                                    )}

                                    {/* Botão Criar rascunho */}
                                    <button
                                        type="button"
                                        onClick={() => criarRascunhoProduto(p)}
                                        disabled={busy === 'criar'}
                                        className="flex shrink-0 items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-1.5 text-xs font-semibold text-black hover:brightness-95 disabled:opacity-40"
                                    >
                                        {busy === 'criar' ? <Loader2 size={12} className="animate-spin" /> : <Rocket size={12} />}
                                        Criar rascunho
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    {/* ─── Coluna do formulário (wizard) ─── */}
                    <div className="space-y-5">
                        {/* Botão de voltar à lista de produtos (quando rascunho está aberto) */}
                        {rascunhoId && produtos.length > 0 && (
                            <button
                                type="button"
                                onClick={limparFormulario}
                                className="flex items-center gap-1.5 text-xs text-white/40 hover:text-white/70 transition mb-1"
                            >
                                <ChevronLeft size={14} /> Voltar aos produtos do cliente
                            </button>
                        )}

                        {/* Título + categoria */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <h2 className="mb-3 text-sm font-semibold text-white">1. Título e categoria</h2>
                            <Campo
                                label="Título do anúncio"
                                dica={categoria?.settings?.max_title_length ? `Máx. ${categoria.settings.max_title_length} caracteres` : null}
                                origem={origemCampos['title']}
                            >
                                <div className="flex gap-2">
                                    <input
                                        className={inputCls}
                                        value={titulo}
                                        maxLength={categoria?.settings?.max_title_length ?? 60}
                                        onChange={e => { setTitulo(e.target.value); marcarEditado('title'); }}
                                        placeholder="Ex.: Tênis de corrida masculino leve"
                                    />
                                    <button onClick={preverCategoria} disabled={busy === 'prever' || !titulo.trim()}
                                        className="flex shrink-0 items-center gap-1 rounded-lg bg-ecf-yellow px-3 py-2 text-sm font-medium text-black disabled:opacity-40">
                                        {busy === 'prever' ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />} Categoria
                                    </button>
                                </div>
                            </Campo>

                            {candidatos.length > 0 && (
                                <div className="mt-3 space-y-1">
                                    <p className="text-[11px] text-white/40">Sugestões de categoria:</p>
                                    {candidatos.map(c => (
                                        <button key={c.category_id} onClick={() => escolherCategoria(c)}
                                            className="flex w-full items-center gap-2 rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-left text-sm text-white/80 hover:border-ecf-yellow/40">
                                            <Tag size={14} className="text-ecf-yellow/70" /> {c.category_name}
                                            <span className="ml-auto text-[11px] text-white/30">{c.category_id}</span>
                                        </button>
                                    ))}
                                </div>
                            )}

                            {categoryId && (
                                <div className="mt-3 flex items-center gap-2 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 text-sm text-emerald-400">
                                    <CheckCircle2 size={15} /> {categoria?.name ?? categoryId}
                                    <span className="ml-auto text-[11px] text-white/30">{categoryId}</span>
                                </div>
                            )}
                            {exigeGrade && (
                                <p className="mt-2 flex items-center gap-1 text-[11px] text-amber-400/80">
                                    <AlertTriangle size={12} /> Categoria de moda: exige grade de tamanho (será tratada numa próxima versão).
                                </p>
                            )}
                        </section>

                        {/* Atributos obrigatórios (dinâmico) */}
                        {categoryId && (
                            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <h2 className="mb-3 text-sm font-semibold text-white">2. Ficha técnica (obrigatórios)</h2>
                                {busy === 'attrs' ? (
                                    <p className="flex items-center gap-2 text-sm text-white/50"><Loader2 size={15} className="animate-spin" /> Carregando atributos…</p>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {obrigatorios.map(a => (
                                            <Campo key={a.id} label={a.name}>
                                                {(a.value_type === 'list' || a.value_type === 'boolean') && a.values?.length ? (
                                                    <select className={inputCls} value={valores[a.id]?.value_id ?? ''}
                                                        onChange={e => setValor(a.id, { value_id: e.target.value, value_name: undefined })}>
                                                        <option value="">Selecione…</option>
                                                        {a.values.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                                    </select>
                                                ) : (
                                                    <input className={inputCls} value={valores[a.id]?.value_name ?? ''}
                                                        placeholder={a.value_type === 'number_unit' ? `Ex.: 10 ${a.default_unit ?? ''}` : ''}
                                                        onChange={e => setValor(a.id, { value_name: e.target.value, value_id: undefined })} />
                                                )}
                                            </Campo>
                                        ))}
                                        {obrigatorios.length === 0 && <p className="text-sm text-white/40">Sem atributos obrigatórios nesta categoria.</p>}
                                    </div>
                                )}
                            </section>
                        )}

                        {/* Preço, estoque, condição, tipo */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <h2 className="mb-3 text-sm font-semibold text-white">3. Preço, estoque e exposição</h2>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Campo label="Preço (R$)" origem={origemCampos['price']}>
                                    <input
                                        className={inputCls}
                                        type="number"
                                        step="0.01"
                                        value={preco}
                                        onChange={e => { setPreco(e.target.value); marcarEditado('price'); }}
                                        placeholder="0,00"
                                    />
                                </Campo>
                                <Campo label="Estoque" origem={origemCampos['available_quantity']}>
                                    <input
                                        className={inputCls}
                                        type="number"
                                        min="1"
                                        value={estoque}
                                        onChange={e => { setEstoque(e.target.value); marcarEditado('available_quantity'); }}
                                    />
                                </Campo>
                                <Campo label="Condição">
                                    <select className={inputCls} value={condicao} onChange={e => setCondicao(e.target.value)}>
                                        {CONDICOES.map(c => <option key={c.v} value={c.v}>{c.l}</option>)}
                                    </select>
                                </Campo>
                                <Campo label="Tipo de anúncio">
                                    <select className={inputCls} value={tipoAnuncio} onChange={e => setTipoAnuncio(e.target.value)}>
                                        {(tipos.length ? tipos : [{ id: 'gold_special', name: 'Clássico' }, { id: 'gold_pro', name: 'Premium' }])
                                            .map(t => <option key={t.id} value={t.id}>{t.name ?? t.id}</option>)}
                                    </select>
                                </Campo>
                            </div>
                        </section>

                        {/* Imagem, garantia, descrição */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <h2 className="mb-3 text-sm font-semibold text-white">4. Imagem, garantia e descrição</h2>
                            <div className="space-y-3">
                                <Campo label="URL da imagem principal" dica="Upload direto será adicionado numa próxima versão">
                                    <input className={inputCls} value={imagemUrl} onChange={e => setImagemUrl(e.target.value)} placeholder="https://…" />
                                </Campo>
                                <Campo label="Garantia"><input className={inputCls} value={garantia} onChange={e => setGarantia(e.target.value)} placeholder="Ex.: 30 dias" /></Campo>
                                <Campo label="Descrição" origem={origemCampos['description']}>
                                    <textarea
                                        className={cn(inputCls, 'min-h-[100px] resize-y')}
                                        value={descricao}
                                        onChange={e => { setDescricao(e.target.value); marcarEditado('description'); }}
                                        placeholder="Descreva o produto…"
                                    />
                                </Campo>
                            </div>
                        </section>

                        {/* Peso e dimensões do pacote (habilitam o frete me2) */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <h2 className="mb-1 text-sm font-semibold text-white">5. Peso e dimensões do pacote</h2>
                            <p className="mb-3 text-[11px] text-white/40">O Mercado Livre precisa disso para calcular o frete (Mercado Envios). Sem preencher, a publicação falha.</p>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {/* Cada campo de dimensão tem badge individual (DRAFT-04) */}
                                <Campo label="Peso (g)" origem={origemCampos['pesoG']}>
                                    <input
                                        className={inputCls}
                                        type="number"
                                        min="1"
                                        value={pesoG}
                                        onChange={e => { setPesoG(e.target.value); marcarEditado('pesoG'); }}
                                        placeholder="300"
                                    />
                                </Campo>
                                <Campo label="Comprimento (cm)" origem={origemCampos['comprimentoCm']}>
                                    <input
                                        className={inputCls}
                                        type="number"
                                        min="1"
                                        value={comprimentoCm}
                                        onChange={e => { setComprimentoCm(e.target.value); marcarEditado('comprimentoCm'); }}
                                        placeholder="20"
                                    />
                                </Campo>
                                <Campo label="Largura (cm)" origem={origemCampos['larguraCm']}>
                                    <input
                                        className={inputCls}
                                        type="number"
                                        min="1"
                                        value={larguraCm}
                                        onChange={e => { setLarguraCm(e.target.value); marcarEditado('larguraCm'); }}
                                        placeholder="15"
                                    />
                                </Campo>
                                <Campo label="Altura (cm)" origem={origemCampos['alturaCm']}>
                                    <input
                                        className={inputCls}
                                        type="number"
                                        min="1"
                                        value={alturaCm}
                                        onChange={e => { setAlturaCm(e.target.value); marcarEditado('alturaCm'); }}
                                        placeholder="10"
                                    />
                                </Campo>
                            </div>
                        </section>

                        {/* Ações */}
                        <div className="flex flex-wrap gap-3">
                            <button onClick={salvar} disabled={!!busy}
                                className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-40">
                                {busy === 'salvar' ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} Salvar rascunho
                            </button>
                            <button onClick={validar} disabled={!!busy || !categoryId}
                                className="flex items-center gap-2 rounded-lg border border-blue-500/30 bg-blue-500/10 px-4 py-2 text-sm font-medium text-blue-400 hover:bg-blue-500/20 disabled:opacity-40">
                                {busy === 'validar' ? <Loader2 size={15} className="animate-spin" /> : <CheckCircle2 size={15} />} Validar
                            </button>
                            {/* SEL-05: publicar abre modal de confirmação em vez de publicar direto */}
                            <button
                                onClick={() => setConfirmPublicar(true)}
                                disabled={!!busy || !categoryId}
                                className="flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-sm font-semibold text-black hover:brightness-95 disabled:opacity-40"
                            >
                                {busy === 'publicar' ? <Loader2 size={15} className="animate-spin" /> : <Rocket size={15} />} Publicar
                            </button>
                        </div>
                    </div>

                    {/* ─── Coluna de preview + validação ─── */}
                    <aside className="space-y-4">
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-white/40">Pré-visualização</p>
                            <div className="rounded-lg border border-white/[0.06] bg-ecf-bg p-3">
                                <div className="mb-2 flex aspect-square items-center justify-center rounded-md bg-white/5">
                                    {imagemUrl ? <img src={imagemUrl} alt="" className="h-full w-full rounded-md object-contain" /> : <PackageOpen size={40} className="text-white/15" />}
                                </div>
                                <p className="line-clamp-2 text-sm font-medium text-white">{titulo || 'Título do anúncio'}</p>
                                <p className="mt-1 text-lg font-semibold text-ecf-yellow">{preco ? `R$ ${Number(preco).toFixed(2)}` : 'R$ —'}</p>
                                <p className="mt-1 text-[11px] text-white/40">{empresa?.nome ?? '—'} · {categoria?.name ?? 'sem categoria'}</p>
                            </div>
                        </div>

                        {/* Resultado da validação */}
                        {erros && (
                            <div className={cn('rounded-xl border p-4', erros.valido ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-red-500/30 bg-red-500/5')}>
                                <p className={cn('mb-2 flex items-center gap-2 text-sm font-semibold', erros.valido ? 'text-emerald-400' : 'text-red-400')}>
                                    {erros.valido ? <><CheckCircle2 size={15} /> Válido para publicar</> : <><AlertTriangle size={15} /> {erros.erros.length} pendência(s)</>}
                                </p>
                                <ul className="space-y-1">
                                    {erros.erros.map((e, i) => (
                                        <li key={i} className="text-xs text-white/70">
                                            {e.campo && <span className="font-medium text-white/90">{e.campo}: </span>}{e.mensagem}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {/* Rascunhos recentes desta empresa */}
                        {rascunhos.length > 0 && (
                            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-white/40">Rascunhos recentes</p>
                                <ul className="space-y-2">
                                    {rascunhos.slice(0, 8).map(r => (
                                        <li key={r.id} className="flex items-center gap-2 text-sm">
                                            <span className={cn('rounded px-1.5 py-0.5 text-[10px]', STATUS_BADGE[r.status] ?? STATUS_BADGE.rascunho)}>{STATUS_LABEL[r.status] ?? r.status}</span>
                                            <span className="truncate text-white/60">{empresa?.nome ?? `Empresa ${r.company_id}`}</span>
                                            {r.ml_item_id && <span className="ml-auto text-[10px] text-emerald-400/70">{r.ml_item_id}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </aside>
                </div>
            </div>

            {/* SEL-05: modal de confirmação de publicação com nome da empresa */}
            <Dialog open={confirmPublicar} onOpenChange={setConfirmPublicar}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Confirmar publicação</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-white/60">
                        O anúncio será publicado na conta{' '}
                        <span className="font-semibold text-white">{empresa?.nome}</span>.
                        {' '}Confirma?
                    </p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmPublicar(false)}>Cancelar</Button>
                        <Button onClick={confirmarPublicacao}>Publicar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
