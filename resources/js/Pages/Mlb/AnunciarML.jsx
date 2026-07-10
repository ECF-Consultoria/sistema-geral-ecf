import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo } from 'react';
import { Search, CheckCircle2, AlertTriangle, Rocket, Save, Loader2, Tag, PackageOpen } from 'lucide-react';
import { cn } from '@/lib/utils';

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

// Input padrão ECF
function Campo({ label, children, dica }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-white/60">{label}</span>
            {children}
            {dica && <span className="mt-1 block text-[11px] text-white/40">{dica}</span>}
        </label>
    );
}

const inputCls = 'w-full rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-sm text-white placeholder-white/30 focus:border-ecf-yellow/50 focus:outline-none';

export default function AnunciarML({ empresas = [], rascunhos = [] }) {
    const [empresaId, setEmpresaId]   = useState(empresas[0]?.id ?? '');
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

    const [candidatos, setCandidatos] = useState([]);
    const [tipos, setTipos]           = useState([]);
    const [erros, setErros]           = useState(null);   // {valido, erros:[]}
    const [busy, setBusy]             = useState('');      // '', 'prever', 'attrs', 'salvar', 'validar', 'publicar'
    const [flash, setFlash]           = useState('');

    // Tipos de anúncio (uma vez)
    useEffect(() => {
        window.axios.get(route('mlb.anuncios.meta.tipos'))
            .then(r => setTipos(Array.isArray(r.data) ? r.data : []))
            .catch(() => {});
    }, []);

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
        const attributes = Object.entries(valores)
            .filter(([, v]) => v && (v.value_id || v.value_name))
            .map(([id, v]) => (v.value_id ? { id, value_id: v.value_id } : { id, value_name: v.value_name }));

        return {
            title: titulo,
            category_id: categoryId,
            price: preco ? Number(preco) : null,
            currency_id: 'BRL',
            available_quantity: estoque ? Number(estoque) : null,
            condition: condicao,
            listing_type_id: tipoAnuncio,
            attributes,
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
        if (!empresaId) { setFlash('Selecione a empresa (cliente).'); return null; }
        setBusy('salvar');
        try {
            const payload = montarPayload();
            let id = rascunhoId;
            if (!id) {
                const r = await window.axios.post(route('mlb.anuncios.rascunho.store'), {
                    company_id: empresaId, category_id: categoryId || null, payload,
                });
                id = r.data.rascunho.id;
                setRascunhoId(id);
            } else {
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

    // ─── Publica ───
    const publicar = async () => {
        const id = await salvar();
        if (!id) return;
        setBusy('publicar');
        try {
            const r = await window.axios.post(route('mlb.anuncios.publicar', { rascunho: id }));
            if (r.data.ok) { setErros({ valido: true, erros: [] }); setFlash('Publicação enfileirada! O anúncio aparecerá na conta do cliente em instantes.'); }
        } catch (e) {
            if (e.response?.status === 422) { setErros({ valido: false, erros: e.response.data.erros ?? [] }); setFlash('Corrija as pendências antes de publicar.'); }
            else { setFlash('Falha ao publicar — tente novamente.'); }
        } finally { setBusy(''); }
    };

    const empresaNome = empresas.find(e => e.id === Number(empresaId))?.name ?? '—';

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl px-4 py-6">
                <header className="mb-6">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-white">
                        <Rocket size={20} className="text-ecf-yellow" /> Anunciar no Mercado Livre
                    </h1>
                    <p className="mt-1 text-sm text-white/50">Monte o anúncio aqui e publique direto na conta do cliente — sem entrar no painel do ML.</p>
                </header>

                {flash && (
                    <div className="mb-4 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-4 py-2 text-sm text-ecf-yellow">{flash}</div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    {/* ─── Coluna do formulário (wizard) ─── */}
                    <div className="space-y-5">
                        {/* Empresa */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <h2 className="mb-3 text-sm font-semibold text-white">1. Cliente (conta do Mercado Livre)</h2>
                            <Campo label="Empresa conectada">
                                <select className={inputCls} value={empresaId} onChange={e => setEmpresaId(e.target.value)}>
                                    {empresas.length === 0 && <option value="">Nenhuma empresa conectada ao ML</option>}
                                    {empresas.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}
                                </select>
                            </Campo>
                        </section>

                        {/* Título + categoria */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <h2 className="mb-3 text-sm font-semibold text-white">2. Título e categoria</h2>
                            <Campo label="Título do anúncio" dica={categoria?.settings?.max_title_length ? `Máx. ${categoria.settings.max_title_length} caracteres` : null}>
                                <div className="flex gap-2">
                                    <input className={inputCls} value={titulo} maxLength={categoria?.settings?.max_title_length ?? 60}
                                        onChange={e => setTitulo(e.target.value)} placeholder="Ex.: Tênis de corrida masculino leve" />
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
                                <h2 className="mb-3 text-sm font-semibold text-white">3. Ficha técnica (obrigatórios)</h2>
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
                            <h2 className="mb-3 text-sm font-semibold text-white">4. Preço, estoque e exposição</h2>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Campo label="Preço (R$)"><input className={inputCls} type="number" step="0.01" value={preco} onChange={e => setPreco(e.target.value)} placeholder="0,00" /></Campo>
                                <Campo label="Estoque"><input className={inputCls} type="number" min="1" value={estoque} onChange={e => setEstoque(e.target.value)} /></Campo>
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
                            <h2 className="mb-3 text-sm font-semibold text-white">5. Imagem, garantia e descrição</h2>
                            <div className="space-y-3">
                                <Campo label="URL da imagem principal" dica="Upload direto será adicionado numa próxima versão">
                                    <input className={inputCls} value={imagemUrl} onChange={e => setImagemUrl(e.target.value)} placeholder="https://…" />
                                </Campo>
                                <Campo label="Garantia"><input className={inputCls} value={garantia} onChange={e => setGarantia(e.target.value)} placeholder="Ex.: 30 dias" /></Campo>
                                <Campo label="Descrição">
                                    <textarea className={cn(inputCls, 'min-h-[100px] resize-y')} value={descricao} onChange={e => setDescricao(e.target.value)} placeholder="Descreva o produto…" />
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
                            <button onClick={publicar} disabled={!!busy || !categoryId}
                                className="flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-sm font-semibold text-black hover:brightness-95 disabled:opacity-40">
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
                                <p className="mt-1 text-[11px] text-white/40">{empresaNome} · {categoria?.name ?? 'sem categoria'}</p>
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

                        {/* Rascunhos recentes */}
                        {rascunhos.length > 0 && (
                            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-white/40">Rascunhos recentes</p>
                                <ul className="space-y-2">
                                    {rascunhos.slice(0, 8).map(r => (
                                        <li key={r.id} className="flex items-center gap-2 text-sm">
                                            <span className={cn('rounded px-1.5 py-0.5 text-[10px]', STATUS_BADGE[r.status] ?? STATUS_BADGE.rascunho)}>{STATUS_LABEL[r.status] ?? r.status}</span>
                                            <span className="truncate text-white/60">{r.company?.name ?? `Empresa ${r.company_id}`}</span>
                                            {r.ml_item_id && <span className="ml-auto text-[10px] text-emerald-400/70">{r.ml_item_id}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
