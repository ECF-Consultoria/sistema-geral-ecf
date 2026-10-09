import AppLayout from '@/Layouts/AppLayout';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import {
    Search, Plus, QrCode, Link2, X, Loader2, Camera, ChevronDown, ChevronLeft, ChevronRight, ShieldCheck,
    Copy, Check, Trash2, History, KeyRound, Pencil, Save,
} from 'lucide-react';
import jsQR from 'jsqr';
import { cn } from '@/lib/utils';
import {
    StatusBadge, ServicoIcone, Avatar, RingCountdown, Info, STATUS_LABELS, antesDoArroba, fmtCodigo, copyToClipboard,
} from '@/Components/Autenticadores/common';

// ─── Página: lista paginada + painel lateral com o código ───────────────────
// Clicou na conta, o código abre ao lado — sem trocar de página. A lista vem
// inteira nas props (só rótulos, é leve) e a paginação é no navegador: assim a
// busca continua cobrindo todas as contas, e a tela só desenha uma página.

const POR_PAGINA = [10, 20, 50];

export default function Index({ autenticadores = [], filtros = {}, servicos = [], selecionado = null }) {
    const { csrf_token } = usePage().props;

    const [q, setQ] = useState(filtros.q || '');
    const [fServico, setFServico] = useState(filtros.servico || '');
    const [fStatus, setFStatus] = useState(filtros.status || '');
    const [ordem, setOrdem] = useState('cliente'); // 'cliente' (A–Z) | 'recentes'
    const [porPagina, setPorPagina] = useState(10);
    const [pagina, setPagina] = useState(1);

    // Busca client-side: cliente, parte antes do @, número no domínio e serviço.
    const lista = useMemo(() => {
        const termo = q.trim().toLowerCase();
        const filtrada = autenticadores.filter((a) => {
            if (fServico && a.servico !== fServico) return false;
            if (fStatus && a.status !== fStatus) return false;
            if (!termo) return true;
            return [a.cliente, a.conta, a.servico, a.issuer, antesDoArroba(a.conta)]
                .filter(Boolean)
                .some((v) => String(v).toLowerCase().includes(termo));
        });
        // "Mais recentes" usa o id (autoincremento = ordem de cadastro); assim a
        // conta recém-adicionada fica no topo mesmo sem lembrar o nome.
        return [...filtrada].sort((a, b) => (
            ordem === 'recentes'
                ? b.id - a.id
                : String(a.cliente).localeCompare(String(b.cliente), 'pt-BR')
        ));
    }, [autenticadores, q, fServico, fStatus, ordem]);

    // Mudou busca/filtro/ordem/tamanho → volta para a Pág. 1.
    useEffect(() => { setPagina(1); }, [q, fServico, fStatus, ordem, porPagina]);

    const totalPaginas = Math.max(1, Math.ceil(lista.length / porPagina));
    const paginaAtual = Math.min(pagina, totalPaginas);
    const inicio = (paginaAtual - 1) * porPagina;
    const visiveis = lista.slice(inicio, inicio + porPagina);

    // Não pré-seleciona a primeira conta: abrir o painel busca o código, e cada
    // busca grava "Visualizou o código" na auditoria. Só abre por clique ou
    // pelo link direto /autenticadores/{id}.
    const [selId, setSelId] = useState(selecionado);
    const [painelAberto, setPainelAberto] = useState(selecionado !== null); // gaveta no celular
    const atual = useMemo(() => autenticadores.find((a) => a.id === selId) || null, [autenticadores, selId]);

    // Link direto: abre na página da lista onde a conta está.
    useEffect(() => {
        if (selecionado === null) return;
        const idx = lista.findIndex((a) => a.id === selecionado);
        if (idx >= 0) setPagina(Math.floor(idx / porPagina) + 1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const abrir = (id) => { setSelId(id); setPainelAberto(true); };
    // Fechar (só existe no celular) também desmonta o painel: senão ele seguiria
    // renovando o código escondido — e auditando visualização que ninguém fez.
    const fechar = () => { setPainelAberto(false); setSelId(null); };

    const [addAberto, setAddAberto] = useState(false);

    return (
        <AppLayout title="Autenticadores 2FA">
            <div className="max-w-7xl mx-auto space-y-6">
                {/* Cabeçalho */}
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl font-bold text-white flex items-center gap-2">
                            <ShieldCheck size={24} className="text-ecf-yellow" />
                            Autenticadores 2FA
                        </h1>
                        <p className="text-white/40 text-sm mt-1">Clique na conta para ver o código ao lado.</p>
                    </div>
                    <button
                        onClick={() => setAddAberto((v) => !v)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition"
                    >
                        <Plus size={18} /> Adicionar autenticador
                    </button>
                </div>

                {/* Busca + filtros */}
                <div className="flex gap-3 flex-wrap">
                    <div className="relative flex-1 min-w-[240px]">
                        <Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            autoFocus={selecionado === null}
                            placeholder="Pesquisar cliente, e-mail, domínio ou serviço…"
                            className="w-full pl-10 pr-3 py-2.5 rounded-lg bg-ecf-card border border-white/[0.08] text-white text-sm placeholder:text-white/30 focus:outline-none focus:border-ecf-yellow/40"
                        />
                    </div>
                    <FiltroSelect value={fServico} onChange={setFServico} placeholder="Serviço" options={servicos.map((s) => ({ value: s, label: s }))} />
                    <FiltroSelect value={fStatus} onChange={setFStatus} placeholder="Status" options={Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))} />
                    <FiltroSelect
                        value={ordem}
                        onChange={setOrdem}
                        allowEmpty={false}
                        options={[
                            { value: 'cliente', label: 'Ordem: Cliente (A–Z)' },
                            { value: 'recentes', label: 'Ordem: Mais recentes' },
                        ]}
                    />
                    <FiltroSelect
                        value={String(porPagina)}
                        onChange={(v) => setPorPagina(Number(v))}
                        allowEmpty={false}
                        options={POR_PAGINA.map((n) => ({ value: String(n), label: `${n} por página` }))}
                    />
                </div>

                {addAberto && (
                    <NovoAutenticador csrf={csrf_token} onClose={() => setAddAberto(false)} />
                )}

                {/* Lista + painel */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    <div className="lg:col-span-2 min-w-0 rounded-xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                        <div className="flex items-center gap-2 px-5 py-4 border-b border-white/[0.06]">
                            <h2 className="text-white font-semibold">Contas</h2>
                            <span className="text-[11px] text-white/40 bg-white/5 rounded-full px-2 py-0.5 tabular-nums">{lista.length}</span>
                            {q.trim() !== '' && <span className="ml-auto text-[12px] text-white/35">busca em todas as páginas</span>}
                        </div>

                        {lista.length === 0 ? (
                            <div className="px-5 py-12 text-center text-white/40 text-sm">
                                {autenticadores.length === 0 ? 'Nenhuma conta cadastrada ainda.' : 'Nenhuma conta encontrada para a busca.'}
                            </div>
                        ) : (
                            <>
                                <ul>
                                    {visiveis.map((a) => {
                                        const ativo = a.id === selId;
                                        return (
                                            <li key={a.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => abrir(a.id)}
                                                    className={cn(
                                                        'relative w-full flex items-center gap-4 px-5 py-3 text-left border-t border-white/[0.05] transition group',
                                                        ativo ? 'bg-ecf-yellow/[0.05]' : 'hover:bg-white/[0.03]',
                                                    )}
                                                >
                                                    {ativo && <span className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r bg-ecf-yellow" />}
                                                    <Avatar texto={a.cliente} />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-white font-medium truncate">{a.cliente}</p>
                                                        <p className="text-white/50 text-[13px] truncate">{a.conta}</p>
                                                    </div>
                                                    <span className="hidden sm:inline-flex items-center gap-2 text-white/70 text-sm w-40 shrink-0">
                                                        <ServicoIcone servico={a.servico} /> <span className="truncate">{a.servico}</span>
                                                    </span>
                                                    <span className="hidden md:block shrink-0"><StatusBadge status={a.status} /></span>
                                                    <ChevronRight size={18} className={cn('shrink-0 transition', ativo ? 'text-ecf-yellow' : 'text-white/25 group-hover:text-ecf-yellow')} />
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                                <Paginacao
                                    pagina={paginaAtual}
                                    total={totalPaginas}
                                    onChange={setPagina}
                                    resumo={`Mostrando ${inicio + 1}–${Math.min(inicio + porPagina, lista.length)} de ${lista.length}`}
                                />
                            </>
                        )}
                    </div>

                    {/* Desktop: coluna fixa ao lado. Celular/tablet: gaveta que sobe de baixo. */}
                    {painelAberto && (
                        <div className="lg:hidden fixed inset-0 z-40 bg-black/55" onClick={fechar} />
                    )}
                    <div
                        className={cn(
                            'min-w-0 lg:sticky lg:top-6',
                            'max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-50 max-lg:max-h-[88vh] max-lg:overflow-y-auto max-lg:transition-transform max-lg:duration-200',
                            painelAberto ? 'max-lg:translate-y-0' : 'max-lg:translate-y-full',
                        )}
                    >
                        {atual ? (
                            <PainelConta
                                key={atual.id}
                                autenticador={atual}
                                csrf={csrf_token}
                                onFechar={fechar}
                            />
                        ) : (
                            <div className="rounded-xl border border-dashed border-white/[0.1] bg-ecf-card/60 px-6 py-14 text-center max-lg:hidden">
                                <KeyRound size={26} className="mx-auto text-white/20 mb-2" />
                                <p className="text-white/60 text-sm font-medium">Nenhuma conta aberta</p>
                                <p className="text-white/35 text-[13px] mt-1">Clique numa conta da lista para ver o código aqui.</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

// ─── Paginação ──────────────────────────────────────────────────────────────

// Botões visíveis: 1 … 4 5 6 … 12 (primeira, última e as vizinhas da atual).
function numerosDePagina(total, atual) {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
    const set = new Set([1, total, atual - 1, atual, atual + 1]);
    if (atual <= 3) [2, 3, 4].forEach((n) => set.add(n));
    if (atual >= total - 2) [total - 3, total - 2, total - 1].forEach((n) => set.add(n));
    const ordenados = [...set].filter((n) => n >= 1 && n <= total).sort((a, b) => a - b);
    const out = [];
    ordenados.forEach((n, i) => {
        if (i > 0 && n - ordenados[i - 1] > 1) out.push(`gap-${n}`);
        out.push(n);
    });
    return out;
}

function Paginacao({ pagina, total, onChange, resumo }) {
    const base = 'h-8 rounded-lg text-[13px] font-medium tabular-nums transition disabled:opacity-30 disabled:pointer-events-none';
    return (
        <div className="flex items-center justify-between gap-3 flex-wrap px-5 py-3 border-t border-white/[0.06]">
            <span className="text-[12px] text-white/40 tabular-nums">{resumo}</span>
            {total > 1 && (
                <div className="flex items-center gap-1 flex-wrap">
                    <button type="button" onClick={() => onChange(pagina - 1)} disabled={pagina === 1} aria-label="Página anterior" className={cn(base, 'px-2 text-white/60 hover:bg-white/5 hover:text-white')}>
                        <ChevronLeft size={16} />
                    </button>
                    {numerosDePagina(total, pagina).map((n) => (
                        typeof n === 'string'
                            ? <span key={n} className="px-1 text-white/30 text-[13px]">…</span>
                            : (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => onChange(n)}
                                    aria-current={n === pagina ? 'page' : undefined}
                                    className={cn(
                                        base, 'min-w-[54px] px-2.5 border',
                                        n === pagina
                                            ? 'bg-ecf-yellow/[0.12] border-ecf-yellow/25 text-ecf-yellow'
                                            : 'border-transparent text-white/60 hover:bg-white/5 hover:text-white',
                                    )}
                                >
                                    Pág. {n}
                                </button>
                            )
                    ))}
                    <button type="button" onClick={() => onChange(pagina + 1)} disabled={pagina === total} aria-label="Próxima página" className={cn(base, 'px-2 text-white/60 hover:bg-white/5 hover:text-white')}>
                        <ChevronRight size={16} />
                    </button>
                </div>
            )}
        </div>
    );
}

// ─── Painel lateral: código ao vivo de uma conta ────────────────────────────
// Remonta a cada conta (key = id), então o estado do código/edição nunca vaza
// de uma conta para outra.

function PainelConta({ autenticador, csrf, onFechar }) {
    const [codigo, setCodigo] = useState(null);
    const [deadline, setDeadline] = useState(0);
    const [periodo, setPeriodo] = useState(autenticador.periodo || 30);
    const [, setAgora] = useState(Date.now()); // força o tick da contagem
    const [copiado, setCopiado] = useState(false);
    const [erro, setErro] = useState(null);
    const [histAberto, setHistAberto] = useState(false);
    const [editando, setEditando] = useState(false);
    const [confirmandoRemocao, setConfirmandoRemocao] = useState(false);
    const editForm = useForm({
        cliente: autenticador.cliente || '',
        conta:   autenticador.conta || '',
        servico: autenticador.servico || '',
    });
    const refreshRef = useRef(null);
    const seqRef = useRef(0);

    const buscarCodigo = useCallback(async (id) => {
        const seq = ++seqRef.current;
        const sentAt = performance.now();
        try {
            const { data } = await window.axios.get(route('autenticadores.codigo', id));
            if (seq !== seqRef.current) return;
            const recv = performance.now();
            setCodigo(data.code);
            setPeriodo(data.period);
            setDeadline(performance.now() - (recv - sentAt) / 2 + data.remaining_ms);
            setErro(null);
            const wait = Math.max(data.remaining_ms - (recv - sentAt) / 2, 0) + 200;
            clearTimeout(refreshRef.current);
            refreshRef.current = setTimeout(() => buscarCodigo(id), wait);
        } catch {
            if (seq !== seqRef.current) return;
            setCodigo(null);
            setErro('Falha ao obter o código.');
        }
    }, []);

    useEffect(() => {
        buscarCodigo(autenticador.id);
        return () => { seqRef.current++; clearTimeout(refreshRef.current); };
    }, [autenticador.id, buscarCodigo]);

    useEffect(() => {
        const t = setInterval(() => setAgora(Date.now()), 250);
        return () => clearInterval(t);
    }, []);

    const remainingMs = Math.max(deadline - performance.now(), 0);
    const seconds = codigo ? Math.ceil(remainingMs / 1000) : 0;
    const fraction = codigo ? remainingMs / (periodo * 1000) : 0;

    const copiar = async () => {
        if (!codigo) return;
        const ok = await copyToClipboard(codigo);
        if (ok) {
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
            window.axios.post(route('autenticadores.copiar', autenticador.id), {}, { headers: { 'X-CSRF-TOKEN': csrf } }).catch(() => {});
        }
    };

    const remover = () => router.delete(route('autenticadores.destroy', autenticador.id));

    // preserveState: a lista não remonta, então a página e a conta aberta continuam.
    const salvarEdicao = (e) => {
        e.preventDefault();
        editForm.patch(route('autenticadores.update', autenticador.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditando(false),
        });
    };

    const cancelarEdicao = () => {
        setEditando(false);
        editForm.clearErrors();
        editForm.setData({
            cliente: autenticador.cliente || '',
            conta:   autenticador.conta || '',
            servico: autenticador.servico || '',
        });
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5 space-y-5 max-lg:rounded-b-none max-lg:pb-8">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-white font-semibold">Detalhes do autenticador</h2>
                <div className="flex items-center gap-3">
                    {!editando && (
                        <button
                            onClick={() => setEditando(true)}
                            className="inline-flex items-center gap-1.5 text-[12px] text-white/50 hover:text-ecf-yellow transition"
                        >
                            <Pencil size={14} /> Editar
                        </button>
                    )}
                    <button
                        onClick={() => setConfirmandoRemocao(true)}
                        className="inline-flex items-center gap-1.5 text-[12px] text-white/50 hover:text-red-300 transition"
                    >
                        <Trash2 size={14} /> Remover
                    </button>
                    <button onClick={onFechar} aria-label="Fechar" className="lg:hidden text-white/50 hover:text-white/90">
                        <X size={18} />
                    </button>
                </div>
            </div>

            {confirmandoRemocao && (
                <div className="rounded-lg border border-red-400/30 bg-red-500/[0.06] p-3.5 space-y-3">
                    <p className="text-white/80 text-[13px]">Remover "{autenticador.cliente}"? O secret será apagado.</p>
                    <div className="flex gap-2">
                        <button
                            onClick={remover}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-red-500/85 text-white font-semibold text-[13px] hover:bg-red-500 transition"
                        >
                            <Trash2 size={14} /> Remover
                        </button>
                        <button
                            onClick={() => setConfirmandoRemocao(false)}
                            className="px-3.5 py-2 rounded-lg border border-white/[0.1] text-white/80 font-medium text-[13px] hover:bg-white/[0.03] transition"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            {editando ? (
                <form onSubmit={salvarEdicao} className="space-y-3 rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.03] p-4">
                    <p className="text-white/50 text-[12px]">Ajuste o nome que o time procura — o QR do ML costuma trazer o nome do Mercado Livre no lugar do nome da loja. O secret não é alterado.</p>
                    <Campo rotulo="Cliente / loja" valor={editForm.data.cliente} onChange={(v) => editForm.setData('cliente', v)} placeholder="Ex.: Loja Prime" />
                    <Campo rotulo="E-mail ou identificação" valor={editForm.data.conta} onChange={(v) => editForm.setData('conta', v)} placeholder="financeiro@loja.com" />
                    <Campo rotulo="Serviço" valor={editForm.data.servico} onChange={(v) => editForm.setData('servico', v)} placeholder="Google, Amazon, Mercado Livre…" />
                    {editForm.errors.cliente && <p className="text-red-300 text-[12px]">{editForm.errors.cliente}</p>}
                    <div className="flex gap-2 pt-1">
                        <button
                            type="submit"
                            disabled={editForm.processing}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition disabled:opacity-50"
                        >
                            {editForm.processing ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} Salvar
                        </button>
                        <button
                            type="button"
                            onClick={cancelarEdicao}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-white/[0.1] text-white/80 font-medium text-sm hover:bg-white/[0.03] transition"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            ) : (
                <>
                    <div className="flex items-center gap-3">
                        <Avatar texto={autenticador.cliente} className="w-11 h-11 text-[15px]" />
                        <div className="min-w-0">
                            <p className="text-white font-semibold text-lg leading-tight [overflow-wrap:anywhere]">{autenticador.cliente}</p>
                            <p className="text-white/50 text-[13px] break-all">{autenticador.conta}</p>
                        </div>
                        <div className="ml-auto shrink-0"><StatusBadge status={autenticador.status} /></div>
                    </div>

                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <Info rotulo="Serviço"><span className="inline-flex items-center gap-1.5"><ServicoIcone servico={autenticador.servico} /> {autenticador.servico}</span></Info>
                        <Info rotulo="Criado em">{autenticador.criado_em || '—'}</Info>
                        <Info rotulo="Atualização">{autenticador.atualizado_em || '—'}</Info>
                    </div>
                </>
            )}

            {/* Código ao vivo */}
            <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-4">
                <div className="flex items-center gap-2 text-[12px] text-white/50 mb-2">
                    <KeyRound size={14} className="text-ecf-yellow" /> Código de autenticação (TOTP)
                </div>
                <div className="flex items-center justify-between gap-3">
                    <div className="font-mono text-[36px] leading-none font-semibold text-white tabular-nums tracking-wider whitespace-nowrap">
                        {erro ? '—' : fmtCodigo(codigo)}
                    </div>
                    <RingCountdown seconds={seconds} fraction={fraction} />
                </div>
                {erro
                    ? <p className="text-red-300/80 text-[12px] mt-2">{erro}</p>
                    : <p className="text-white/40 text-[12px] mt-2">Expira em <span className="text-white/70 font-medium">{seconds}s</span></p>}
            </div>

            <div className="flex gap-2">
                <button
                    onClick={copiar}
                    disabled={!codigo}
                    className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition disabled:opacity-40"
                >
                    {copiado ? <><Check size={16} /> Copiado</> : <><Copy size={16} /> Copiar código</>}
                </button>
                <button
                    onClick={() => setHistAberto((v) => !v)}
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-white/[0.1] text-white/80 font-medium text-sm hover:bg-white/[0.03] transition"
                >
                    <History size={16} /> Histórico
                </button>
            </div>

            <p className="text-[11px] text-white/30 flex items-center gap-1.5"><ShieldCheck size={12} /> O secret nunca é exibido.</p>

            {histAberto && <Historico id={autenticador.id} />}
        </div>
    );
}

function Historico({ id }) {
    const [acessos, setAcessos] = useState(null);
    useEffect(() => {
        let vivo = true;
        window.axios.get(route('autenticadores.historico', id))
            .then(({ data }) => { if (vivo) setAcessos(data.acessos); })
            .catch(() => { if (vivo) setAcessos([]); });
        return () => { vivo = false; };
    }, [id]);

    if (acessos === null) return <p className="text-white/40 text-[12px]">Carregando histórico…</p>;
    if (acessos.length === 0) return <p className="text-white/40 text-[12px]">Sem acessos registrados.</p>;
    return (
        <div className="border-t border-white/[0.06] pt-3 space-y-2 max-h-56 overflow-y-auto">
            {acessos.map((a, i) => (
                <div key={i} className="flex items-center justify-between gap-3 text-[12px]">
                    <span className="text-white/70">{a.usuario} · {a.descricao}</span>
                    <span className="text-white/35 whitespace-nowrap">{a.created_at}</span>
                </div>
            ))}
        </div>
    );
}

// ─── Novo autenticador ──────────────────────────────────────────────────────

// Lê um QR Code de um arquivo de imagem (jsQR). Devolve o texto ou null.
async function decodeQrDaImagem(file) {
    const url = URL.createObjectURL(file);
    try {
        const img = await new Promise((resolve, reject) => {
            const el = new Image();
            el.onload = () => resolve(el);
            el.onerror = reject;
            el.src = url;
        });
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0);
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
        return jsQR(data.data, canvas.width, canvas.height)?.data ?? null;
    } finally {
        URL.revokeObjectURL(url);
    }
}

// Câmera ao vivo lendo QR Code (jsQR sobre os frames do vídeo). Exige HTTPS —
// produção é https, então funciona no celular e no desktop.
function ScannerQr({ onDetectar, onFechar }) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const rafRef = useRef(null);
    const cbRef = useRef(onDetectar);
    const [erro, setErro] = useState('');

    useEffect(() => { cbRef.current = onDetectar; });

    useEffect(() => {
        let cancelado = false;
        const canvas = document.createElement('canvas');

        const tick = () => {
            const video = videoRef.current;
            if (!video) return;
            if (video.readyState >= 2 && video.videoWidth) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(img.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
                if (code?.data && /^otpauth(-migration)?:/i.test(code.data)) {
                    cbRef.current(code.data);
                    return; // achou — para o loop
                }
            }
            rafRef.current = requestAnimationFrame(tick);
        };

        (async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });
                if (cancelado) { stream.getTracks().forEach((t) => t.stop()); return; }
                streamRef.current = stream;
                const video = videoRef.current;
                video.srcObject = stream;
                video.muted = true;
                video.playsInline = true;
                await video.play();
                rafRef.current = requestAnimationFrame(tick);
            } catch (e) {
                if (cancelado) return;
                setErro(e?.name === 'NotAllowedError'
                    ? 'Permissão de câmera negada — libere a câmera no navegador e tente de novo.'
                    : 'Não foi possível abrir a câmera. Use "Escolher imagem" ou cole a URI.');
            }
        })();

        return () => {
            cancelado = true;
            cancelAnimationFrame(rafRef.current);
            if (streamRef.current) streamRef.current.getTracks().forEach((t) => t.stop());
        };
    }, []);

    return (
        <div className="space-y-2">
            <div className="relative rounded-lg overflow-hidden border border-white/[0.12] bg-black" style={{ aspectRatio: '4 / 3' }}>
                <video ref={videoRef} className="w-full h-full object-cover" muted playsInline />
                {!erro && <div className="absolute inset-6 border-2 border-ecf-yellow/50 rounded-lg pointer-events-none" />}
            </div>
            {erro
                ? <p className="text-red-300 text-[12px]">{erro}</p>
                : <p className="text-white/40 text-[12px]">Aponte a câmera para o QR Code do autenticador.</p>}
            <button type="button" onClick={onFechar} className="text-white/60 text-[13px] hover:text-white/90">Fechar câmera</button>
        </div>
    );
}

function NovoAutenticador({ csrf, onClose }) {
    const [modo, setModo] = useState('uri'); // 'uri' | 'qr'
    const [qrMsg, setQrMsg] = useState(null);
    const [cameraAberta, setCameraAberta] = useState(false);

    const form = useForm({ uri: '', cliente: '', conta: '', servico: '', secret: '' });

    const temUri = form.data.uri.trim() !== '';

    const submeter = (e) => {
        e.preventDefault();
        form.post(route('autenticadores.store'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onClose(); },
        });
    };

    // Aplica o conteúdo de um QR lido (câmera ou imagem). Só aceita otpauth://.
    const aplicarQr = (data) => {
        if (!/^otpauth(-migration)?:/i.test(data || '')) {
            setQrMsg('QR lido, mas não é um autenticador (otpauth://).');
            return;
        }
        setCameraAberta(false);
        form.setData('uri', data);
        setModo('uri');
        setQrMsg('QR lido. Confira e clique em Adicionar.');
    };

    const lerImagem = async (file) => {
        setQrMsg('Lendo imagem…');
        try {
            const data = await decodeQrDaImagem(file);
            if (!data) { setQrMsg('Nenhum QR Code encontrado na imagem.'); return; }
            aplicarQr(data);
        } catch {
            setQrMsg('Não foi possível ler a imagem.');
        }
    };

    const onDrop = (e) => {
        e.preventDefault();
        const file = e.dataTransfer.files?.[0];
        if (file) lerImagem(file);
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5">
            <div className="flex items-center justify-between mb-1">
                <h2 className="text-white font-semibold">Novo autenticador</h2>
                <button onClick={onClose} className="text-white/40 hover:text-white/70"><X size={18} /></button>
            </div>
            <p className="text-white/40 text-[13px] mb-4">Importe por QR Code, cole a URI (uma ou várias contas) ou informe os dados manualmente.</p>

            {form.errors.uri && <p className="text-red-300 text-[13px] mb-3">{form.errors.uri}</p>}

            <form onSubmit={submeter} className="grid grid-cols-1 md:grid-cols-2 gap-5">
                {/* Coluna esquerda: QR / URI */}
                <div>
                    <div className="inline-flex rounded-lg border border-white/[0.1] p-1 mb-3">
                        <TabBtn ativo={modo === 'qr'} onClick={() => setModo('qr')} icon={QrCode}>Ler QR Code</TabBtn>
                        <TabBtn ativo={modo === 'uri'} onClick={() => setModo('uri')} icon={Link2}>Colar URI</TabBtn>
                    </div>

                    {modo === 'qr' ? (
                        <div className="space-y-3">
                            {cameraAberta ? (
                                <ScannerQr onDetectar={aplicarQr} onFechar={() => setCameraAberta(false)} />
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => { setQrMsg(null); setCameraAberta(true); }}
                                    className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/25 text-ecf-yellow font-medium text-sm hover:bg-ecf-yellow/[0.18] transition"
                                >
                                    <Camera size={18} /> Escanear com a câmera
                                </button>
                            )}
                            <div
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={onDrop}
                                className="rounded-lg border-2 border-dashed border-white/[0.12] p-5 text-center"
                            >
                                <QrCode size={28} className="mx-auto text-white/25 mb-1.5" />
                                <label className="text-ecf-yellow text-sm font-medium cursor-pointer hover:underline">
                                    Escolher imagem do QR Code
                                    <input type="file" accept="image/*" className="hidden" onChange={(e) => e.target.files?.[0] && lerImagem(e.target.files[0])} />
                                </label>
                                <p className="text-white/30 text-[12px] mt-1">ou arraste uma imagem aqui (JPG, PNG, WEBP)</p>
                            </div>
                            {qrMsg && <p className="text-white/60 text-[12px]">{qrMsg}</p>}
                        </div>
                    ) : (
                        <>
                            <textarea
                                value={form.data.uri}
                                onChange={(e) => form.setData('uri', e.target.value)}
                                rows={5}
                                spellCheck={false}
                                placeholder="otpauth://totp/…  ou  otpauth-migration://offline?data=…"
                                className="w-full px-3 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-[13px] font-mono placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                                style={{ WebkitTextSecurity: temUri ? 'disc' : 'none' }}
                            />
                            <p className="text-white/30 text-[12px] mt-1">A URI de "Transferir contas" traz várias contas de uma vez. O secret vai cifrado e não reaparece.</p>
                        </>
                    )}
                </div>

                {/* Coluna direita: manual */}
                <div className={cn('space-y-3', temUri && 'opacity-40 pointer-events-none')}>
                    <div className="text-white/30 text-[12px] uppercase tracking-wide">ou preencha manualmente</div>
                    <Campo rotulo="Cliente / loja" valor={form.data.cliente} onChange={(v) => form.setData('cliente', v)} placeholder="Ex.: Loja Prime" />
                    <Campo rotulo="E-mail ou identificação" valor={form.data.conta} onChange={(v) => form.setData('conta', v)} placeholder="financeiro@loja.com" />
                    <Campo rotulo="Serviço" valor={form.data.servico} onChange={(v) => form.setData('servico', v)} placeholder="Google, Amazon, Mercado Livre…" />
                    <div>
                        <label className="text-white/50 text-[12px] font-medium">Secret TOTP</label>
                        <input
                            value={form.data.secret}
                            onChange={(e) => form.setData('secret', e.target.value)}
                            spellCheck={false}
                            placeholder="abcd efgh ijkl mnop …"
                            className="w-full mt-1 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm font-mono placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
                            style={{ WebkitTextSecurity: form.data.secret ? 'disc' : 'none' }}
                        />
                    </div>
                </div>

                {/* Submit */}
                <div className="md:col-span-2 flex justify-end pt-1 border-t border-white/[0.06]">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition disabled:opacity-50"
                    >
                        {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Plus size={16} />} Adicionar autenticador
                    </button>
                </div>
            </form>
        </div>
    );
}

function TabBtn({ ativo, onClick, icon: Icon, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[13px] font-medium transition', ativo ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80')}
        >
            <Icon size={15} /> {children}
        </button>
    );
}

function Campo({ rotulo, valor, onChange, placeholder }) {
    return (
        <div>
            <label className="text-white/50 text-[12px] font-medium">{rotulo}</label>
            <input
                value={valor}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full mt-1 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
            />
        </div>
    );
}

function FiltroSelect({ value, onChange, placeholder, options, allowEmpty = true }) {
    return (
        <div className="relative">
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="appearance-none pl-3 pr-9 py-2.5 rounded-lg bg-ecf-card border border-white/[0.08] text-white text-sm focus:outline-none focus:border-ecf-yellow/40 min-w-[150px]"
            >
                {allowEmpty && <option value="" className="bg-ecf-card">{placeholder}: todos</option>}
                {options.map((o) => <option key={o.value} value={o.value} className="bg-ecf-card">{o.label}</option>)}
            </select>
            <ChevronDown size={16} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
        </div>
    );
}
