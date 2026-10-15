import { useEffect, useMemo, useState } from 'react';
import { CalendarDays, ListChecks, Pencil, Plus, Trash2, X, Zap } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    INDICADORES_TAREFA,
    ORDEM_INDICADORES,
    ESTAGIOS_ALVO,
    ESTAGIO_ALVO_PADRAO,
    COR_SEM_RESPONSAVEL,
    universoTarefas,
    feitoDaTarefa,
    progressoDaTarefa,
    resumoPorResponsavel,
    distribuicaoEstagios,
    coresDosResponsaveis,
    rotuloEstagio,
} from '@/lib/polosTarefas';

/**
 * Tarefas do time (TKT-0017) — sub-aba da lente Metas do Painel Polos, onde a gestão cria e
 * ajusta os cartões que a tela "Tarefas" do Modo TV mostra.
 *
 * Cada cartão: indicador (lista fixa), título, descrição, meta, responsável e prazo (ou
 * contínuo). O "feito" é quantas empresas M1–M4 estão HOJE com o indicador feito — régua em
 * lib/polosTarefas.js, a mesma que a TV usa. A meta é o número que a pessoa digita e sobe
 * quando o time bate (80/120 → 120/180); nada zera na virada do mês.
 *
 * Gravação: o Painel passa `onSalvar(dados, id?)` e `onRemover(id)`, que devolvem Promise.
 * Os "Próximos passos" do rodapé da TV são editados aqui também (`onSalvarProximosPassos`).
 */

// Cor do anel/barra pelo andamento: verde = bateu, vermelho = prazo vencido, amarelo = em curso.
export const corDoProgresso = (p) => (p.batida ? '#22c55e' : p.atrasada ? '#ef4444' : '#ffe600');

/**
 * Anel de % em SVG. viewBox fixo + tamanho em CSS: escala com a caixa sem medição (o canvas
 * do ECharts precisaria de px resolvido, e é isso que já deixou donut em branco na TV).
 */
export function AnelProgresso({ pct, cor = '#ffe600', tamanho = 64, className }) {
    const r = 42;
    const c = 2 * Math.PI * r;
    const p = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, pct));
    const texto = pct === null || pct === undefined ? '—' : `${pct}%`;
    return (
        <svg viewBox="0 0 100 100" className={cn('shrink-0', className)} style={{ width: tamanho, height: tamanho }} aria-hidden="true">
            <circle cx="50" cy="50" r={r} fill="none" stroke="rgba(255,255,255,0.08)" strokeWidth="10" />
            <circle cx="50" cy="50" r={r} fill="none" stroke={cor} strokeWidth="10" strokeLinecap="round"
                    strokeDasharray={`${(c * p) / 100} ${c}`} transform="rotate(-90 50 50)" />
            {/* "182%" na fonte de "76%" encostava no anel: 4+ caracteres descem um degrau. */}
            <text x="50" y="51" textAnchor="middle" dominantBaseline="central" fill="#ffffff"
                  style={{ fontSize: texto.length >= 4 ? 19 : 24, fontWeight: 800 }} className="tabular-nums">
                {texto}
            </text>
        </svg>
    );
}

/** Donut da distribuição por estágio, em SVG (mesmo motivo do anel). */
export function DonutEstagios({ linhas = [], total = 0, tamanho = 160 }) {
    const r = 78;
    const c = 2 * Math.PI * r;
    let acumulado = 0;
    const fatias = linhas.filter((l) => l.n > 0).map((l) => {
        const tam = total > 0 ? (l.n / total) * c : 0;
        const fatia = { ...l, inicio: acumulado, tam };
        acumulado += tam;
        return fatia;
    });
    return (
        <svg viewBox="0 0 200 200" className="shrink-0" style={{ width: tamanho, height: tamanho }} aria-hidden="true">
            <circle cx="100" cy="100" r={r} fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth="30" />
            {fatias.map((f) => (
                <circle key={f.chave} cx="100" cy="100" r={r} fill="none" stroke={f.cor} strokeWidth="30"
                        // 1,5 de folga entre fatias: o vinco escuro separa as cores de longe.
                        strokeDasharray={`${Math.max(0, f.tam - (fatias.length > 1 ? 1.5 : 0))} ${c}`}
                        strokeDashoffset={-f.inicio} transform="rotate(-90 100 100)" />
            ))}
            <text x="100" y="94" textAnchor="middle" dominantBaseline="central" fill="#ffffff"
                  style={{ fontSize: 46, fontWeight: 800 }} className="tabular-nums">{total}</text>
            <text x="100" y="128" textAnchor="middle" dominantBaseline="central" fill="rgba(255,255,255,0.45)"
                  style={{ fontSize: 13, letterSpacing: 2 }}>EMPRESAS</text>
        </svg>
    );
}

function CartaoTarefa({ tarefa, progresso: p, cor, onEditar, onRemover }) {
    const corP = corDoProgresso(p);
    return (
        <div className="group flex flex-col rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="truncate rounded-md px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-black"
                              style={{ background: cor }}>
                            {tarefa.responsavel_nome || 'Sem responsável'}
                        </span>
                        <span className="truncate text-[11px] text-white/35">
                            {INDICADORES_TAREFA[tarefa.indicador]?.rotulo ?? tarefa.indicador}
                            {tarefa.indicador === 'publicacao' && ` · ${rotuloEstagio(tarefa.estagio_alvo || ESTAGIO_ALVO_PADRAO)}+`}
                        </span>
                    </div>
                    <h4 className="mt-2 truncate text-[15px] font-bold uppercase tracking-wide text-white" title={tarefa.titulo}>{tarefa.titulo}</h4>
                    {tarefa.descricao && <p className="mt-1 line-clamp-2 text-[12px] leading-snug text-white/50" title={tarefa.descricao}>{tarefa.descricao}</p>}
                </div>
                <AnelProgresso pct={p.pct} cor={corP} tamanho={64} />
            </div>
            <div className="mt-auto pt-3">
                <div className="h-2 w-full overflow-hidden rounded-full bg-white/[0.08]">
                    <div className="h-full rounded-full transition-all" style={{ width: `${p.pctBarra}%`, background: corP }} />
                </div>
                <div className="mt-2 flex items-center justify-between gap-2">
                    <span className="font-display text-[18px] font-extrabold tabular-nums text-white">
                        {p.feito}<span className="text-white/40"> / {p.meta}</span>
                    </span>
                    <span className={cn('inline-flex items-center gap-1 text-[12px]', p.atrasada ? 'font-semibold text-red-400' : 'text-white/45')}>
                        <CalendarDays size={12} /> Prazo: {p.prazoLabel}{p.atrasada ? ' · atrasada' : ''}
                    </span>
                </div>
                <div className="mt-2 flex justify-end gap-1.5 opacity-60 transition group-hover:opacity-100">
                    <button type="button" onClick={onEditar} title="Editar tarefa e meta"
                            className="inline-flex items-center gap-1 rounded-lg border border-white/[0.1] bg-white/[0.04] px-2 py-1 text-[11px] font-semibold text-white/80 hover:bg-white/[0.08]">
                        <Pencil size={11} /> Editar
                    </button>
                    <button type="button" onClick={onRemover} title="Tirar a tarefa da tela"
                            className="inline-flex items-center gap-1 rounded-lg border border-white/[0.1] bg-white/[0.04] px-2 py-1 text-[11px] font-semibold text-white/50 hover:border-red-500/40 hover:text-red-300">
                        <Trash2 size={11} /> Remover
                    </button>
                </div>
            </div>
        </div>
    );
}

const VAZIA = { indicador: 'places', estagio_alvo: null, titulo: '', descricao: '', meta: '', feito_manual: '', responsavel_id: '', prazo: '' };
const campo = 'w-full rounded-lg border border-white/[0.1] bg-white/[0.04] px-3 py-2 text-[13px] text-white outline-none focus:border-ecf-yellow/40';
const rotuloCampo = 'mb-1 block text-[11px] font-semibold uppercase tracking-wider text-white/45';

function ModalTarefa({ inicial, universo, usuarios, onFechar, onSalvar }) {
    const [f, setF] = useState(() => ({
        ...VAZIA,
        ...inicial,
        titulo: inicial?.titulo ?? INDICADORES_TAREFA[inicial?.indicador ?? VAZIA.indicador].rotulo,
        meta: inicial?.meta ?? '',
        feito_manual: inicial?.feito_manual ?? '',
        responsavel_id: inicial?.responsavel_id ? String(inicial.responsavel_id) : '',
        prazo: inicial?.prazo ?? '',
        descricao: inicial?.descricao ?? '',
    }));
    const [continuo, setContinuo] = useState(() => !!inicial?.id && !inicial?.prazo);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);

    const ind = INDICADORES_TAREFA[f.indicador];
    const feitoHoje = useMemo(() => feitoDaTarefa(f, universo), [f, universo]);

    // O título acompanha o indicador enquanto a pessoa não escreveu um próprio.
    const trocarIndicador = (indicador) => setF((v) => {
        const tituloPadrao = !v.titulo || v.titulo === INDICADORES_TAREFA[v.indicador]?.rotulo;
        return {
            ...v,
            indicador,
            estagio_alvo: indicador === 'publicacao' ? (v.estagio_alvo || ESTAGIO_ALVO_PADRAO) : null,
            titulo: tituloPadrao ? INDICADORES_TAREFA[indicador].rotulo : v.titulo,
        };
    });

    const salvar = (ev) => {
        ev.preventDefault();
        setSalvando(true);
        setErro(null);
        const dados = {
            indicador: f.indicador,
            estagio_alvo: f.indicador === 'publicacao' ? (f.estagio_alvo || ESTAGIO_ALVO_PADRAO) : null,
            titulo: (f.titulo || ind?.rotulo || '').trim(),
            descricao: (f.descricao || '').trim() || null,
            meta: Math.max(0, parseInt(f.meta, 10) || 0),
            feito_manual: ind?.manual ? Math.max(0, parseInt(f.feito_manual, 10) || 0) : null,
            responsavel_id: f.responsavel_id ? Number(f.responsavel_id) : null,
            prazo: continuo ? null : (f.prazo || null),
        };
        onSalvar(dados, inicial?.id)
            .then(() => onFechar())
            .catch((e) => {
                const erros = e?.response?.data?.errors;
                setErro(erros ? Object.values(erros).flat().join(' ') : (e?.response?.data?.message || 'Não foi possível salvar. Tente de novo.'));
                setSalvando(false);
            });
    };

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onFechar} />
            <form onSubmit={salvar} className="relative flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-white/10 bg-ecf-card shadow-2xl">
                <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <h3 className="flex items-center gap-2 font-semibold text-white"><ListChecks size={16} /> {inicial?.id ? 'Editar tarefa' : 'Nova tarefa'}</h3>
                    <button type="button" onClick={onFechar} className="text-white/40 transition hover:text-white"><X size={18} /></button>
                </div>
                <div className="flex-1 space-y-4 overflow-auto px-5 py-4">
                    <div>
                        <label className={rotuloCampo} htmlFor="tarefa-indicador">Indicador</label>
                        <select id="tarefa-indicador" value={f.indicador} onChange={(e) => trocarIndicador(e.target.value)} className={campo}>
                            {ORDEM_INDICADORES.map((k) => <option key={k} value={k} className="bg-ecf-card">{INDICADORES_TAREFA[k].rotulo}</option>)}
                        </select>
                        {ind?.manual ? (
                            <p className="mt-1.5 text-[12px] text-white/45">
                                Para a tarefa com uma lista de empresas escolhida à mão (ex.: integração de anúncios ME1 de 4 empresas).
                                O feito é o número que você atualiza abaixo; ponha os nomes na descrição.
                            </p>
                        ) : (
                            <p className="mt-1.5 text-[12px] text-white/45">
                                Conta as empresas de M1 a M4 com {ind?.regra}. Hoje: <b className="text-white/80 tabular-nums">{feitoHoje}</b> de {universo.length}.
                            </p>
                        )}
                    </div>
                    {f.indicador === 'publicacao' && (
                        <div>
                            <label className={rotuloCampo} htmlFor="tarefa-estagio">Estágio que a empresa precisa atingir</label>
                            <select id="tarefa-estagio" value={f.estagio_alvo || ESTAGIO_ALVO_PADRAO}
                                    onChange={(e) => setF((v) => ({ ...v, estagio_alvo: e.target.value }))} className={campo}>
                                {ESTAGIOS_ALVO.map((s) => <option key={s} value={s} className="bg-ecf-card">{rotuloEstagio(s)} ou adiante</option>)}
                            </select>
                        </div>
                    )}
                    <div>
                        <label className={rotuloCampo} htmlFor="tarefa-titulo">Título</label>
                        <input id="tarefa-titulo" value={f.titulo} maxLength={120} required
                               onChange={(e) => setF((v) => ({ ...v, titulo: e.target.value }))} className={campo} />
                    </div>
                    <div>
                        <label className={rotuloCampo} htmlFor="tarefa-descricao">Descrição</label>
                        <textarea id="tarefa-descricao" value={f.descricao} maxLength={500} rows={2}
                                  placeholder="Ex.: ativar 39 empresas até sexta"
                                  onChange={(e) => setF((v) => ({ ...v, descricao: e.target.value }))} className={cn(campo, 'resize-none')} />
                    </div>
                    {ind?.manual && (
                        <div>
                            <label className={rotuloCampo} htmlFor="tarefa-feito">Feito até agora</label>
                            <input id="tarefa-feito" type="number" min={0} max={100000} value={f.feito_manual}
                                   onChange={(e) => setF((v) => ({ ...v, feito_manual: e.target.value }))} className={cn(campo, 'tabular-nums')} />
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className={rotuloCampo} htmlFor="tarefa-meta">Meta (empresas)</label>
                            <input id="tarefa-meta" type="number" min={0} max={100000} required value={f.meta}
                                   onChange={(e) => setF((v) => ({ ...v, meta: e.target.value }))} className={cn(campo, 'tabular-nums')} />
                        </div>
                        <div>
                            <label className={rotuloCampo} htmlFor="tarefa-responsavel">Responsável</label>
                            <select id="tarefa-responsavel" value={f.responsavel_id}
                                    onChange={(e) => setF((v) => ({ ...v, responsavel_id: e.target.value }))} className={campo}>
                                <option value="" className="bg-ecf-card">Sem responsável</option>
                                {usuarios.map((u) => <option key={u.id} value={String(u.id)} className="bg-ecf-card">{u.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className={rotuloCampo} htmlFor="tarefa-prazo">Prazo</label>
                        <div className="flex items-center gap-3">
                            <input id="tarefa-prazo" type="date" value={continuo ? '' : f.prazo} disabled={continuo}
                                   onChange={(e) => setF((v) => ({ ...v, prazo: e.target.value }))}
                                   className={cn(campo, 'flex-1 [color-scheme:dark]', continuo && 'opacity-40')} />
                            <label className="inline-flex shrink-0 cursor-pointer items-center gap-2 text-[13px] text-white/70">
                                <input type="checkbox" checked={continuo} onChange={(e) => setContinuo(e.target.checked)} className="accent-[#ffe600]" />
                                Contínuo
                            </label>
                        </div>
                    </div>
                    {erro && <p className="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-[12px] text-red-300">{erro}</p>}
                </div>
                <div className="flex justify-end gap-2 border-t border-white/10 px-5 py-3">
                    <button type="button" onClick={onFechar} className="rounded-lg px-3 py-1.5 text-[13px] font-semibold text-white/60 hover:text-white">Cancelar</button>
                    <button type="submit" disabled={salvando}
                            className="rounded-lg bg-ecf-yellow px-4 py-1.5 text-[13px] font-bold text-black transition hover:brightness-95 disabled:opacity-50">
                        {salvando ? 'Salvando…' : 'Salvar'}
                    </button>
                </div>
            </form>
        </div>
    );
}

const MAX_PASSOS = 6;

/** "Próximos passos" do rodapé da tela Tarefas da TV: até 6 linhas curtas, salvas de uma vez. */
function ProximosPassos({ itens = [], onSalvar }) {
    const chave = JSON.stringify(itens);
    const [lista, setLista] = useState(() => (itens.length > 0 ? itens : ['']));
    const [salvando, setSalvando] = useState(false);
    const [aviso, setAviso] = useState(null);

    // Volta ao que está gravado quando o Painel recarrega a lista.
    useEffect(() => { setLista(itens.length > 0 ? itens : ['']); }, [chave]);

    const salvar = () => {
        setSalvando(true);
        setAviso(null);
        onSalvar?.(lista.map((t) => t.trim()).filter(Boolean))
            .then(() => setAviso({ ok: true, texto: 'Salvo. Já aparece na TV.' }))
            .catch((e) => {
                const erros = e?.response?.data?.errors;
                setAviso({ ok: false, texto: erros ? Object.values(erros).flat().join(' ') : 'Não foi possível salvar. Tente de novo.' });
            })
            .finally(() => setSalvando(false));
    };

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-white/45">
                    <Zap size={12} className="text-ecf-yellow" /> Próximos passos (rodapé da TV)
                </h4>
                <span className="text-[11px] text-white/35">Até {MAX_PASSOS} linhas. Vazio = o bloco some da TV.</span>
            </div>
            <div className="mt-3 space-y-2">
                {lista.map((texto, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <input value={texto} maxLength={120} placeholder="Ex.: Acompanhar evolução diária das tarefas"
                               aria-label={`Próximo passo ${i + 1}`}
                               onChange={(e) => setLista((l) => l.map((t, j) => (j === i ? e.target.value : t)))}
                               className={cn(campo, 'flex-1')} />
                        <button type="button" title="Tirar esta linha"
                                onClick={() => setLista((l) => (l.length > 1 ? l.filter((_, j) => j !== i) : ['']))}
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/45 hover:border-red-500/40 hover:text-red-300">
                            <X size={13} />
                        </button>
                    </div>
                ))}
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                <button type="button" disabled={lista.length >= MAX_PASSOS}
                        onClick={() => setLista((l) => [...l, ''])}
                        className="inline-flex items-center gap-1 rounded-lg border border-white/[0.1] bg-white/[0.04] px-2.5 py-1.5 text-[12px] font-semibold text-white/70 hover:bg-white/[0.08] disabled:opacity-40">
                    <Plus size={12} /> Adicionar linha
                </button>
                <div className="flex items-center gap-3">
                    {aviso && <span className={cn('text-[12px]', aviso.ok ? 'text-emerald-300' : 'text-red-300')}>{aviso.texto}</span>}
                    <button type="button" onClick={salvar} disabled={salvando}
                            className="rounded-lg bg-ecf-yellow px-3 py-1.5 text-[12px] font-bold text-black transition hover:brightness-95 disabled:opacity-50">
                        {salvando ? 'Salvando…' : 'Salvar próximos passos'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function TarefasPanel({
    empresas = [], tarefas = [], usuarios = [], onSalvar, onRemover, proximosPassos = [], onSalvarProximosPassos,
}) {
    const [editando, setEditando] = useState(null);   // null | {} (nova) | tarefa
    const universo = useMemo(() => universoTarefas(empresas), [empresas]);
    const cores    = useMemo(() => coresDosResponsaveis(tarefas), [tarefas]);
    const linhas   = useMemo(() => tarefas.map((t) => ({ t, p: progressoDaTarefa(t, universo) })), [tarefas, universo]);
    const resumo   = useMemo(() => resumoPorResponsavel(tarefas, universo), [tarefas, universo]);
    const estagios = useMemo(() => distribuicaoEstagios(universo), [universo]);

    const remover = (t) => {
        if (!window.confirm(`Tirar a tarefa "${t.titulo}" do painel?`)) return;
        onRemover?.(t.id).catch(() => window.alert('Não foi possível remover a tarefa. Tente de novo.'));
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 className="text-[15px] font-semibold text-white">Tarefas do time</h3>
                    <p className="mt-0.5 text-[12px] text-white/45">
                        Feito = empresas de M1 a M4 com o indicador em dia hoje ({universo.length} empresas). A meta é sua: quando o time bater, suba o número.
                        Os cartões aparecem no Modo TV, na tela Tarefas.
                    </p>
                </div>
                <button type="button" onClick={() => setEditando({})}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-1.5 text-[12px] font-bold text-black transition hover:brightness-95">
                    <Plus size={13} /> Nova tarefa
                </button>
            </div>

            {linhas.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-white/[0.1] px-6 py-12 text-center text-[13px] text-white/40">
                    Nenhuma tarefa ainda. Crie a primeira em <b className="text-white/70">Nova tarefa</b>: escolha o indicador (Places, ME1, Decola…), a meta, o responsável e o prazo.
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {linhas.map(({ t, p }) => (
                        <CartaoTarefa key={t.id} tarefa={t} progresso={p}
                                      cor={t.responsavel_id ? (cores[t.responsavel_id] ?? COR_SEM_RESPONSAVEL) : COR_SEM_RESPONSAVEL}
                                      onEditar={() => setEditando(t)} onRemover={() => remover(t)} />
                    ))}
                </div>
            )}

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
                    <h4 className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Estágios · M1 a M4</h4>
                    <div className="mt-3 flex items-center gap-5">
                        <DonutEstagios linhas={estagios.linhas} total={estagios.total} tamanho={140} />
                        <div className="min-w-0 flex-1 space-y-1.5">
                            {estagios.linhas.map((l) => (
                                <div key={l.chave} className="flex items-center gap-2 text-[13px]">
                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: l.cor }} />
                                    <span className="flex-1 truncate text-white/70">{l.rotulo}</span>
                                    <b className="tabular-nums text-white">{l.n}</b>
                                    <span className="w-10 text-right tabular-nums text-white/40">{l.pct}%</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
                    <h4 className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Resumo por responsável</h4>
                    {resumo.length === 0 ? (
                        <p className="mt-3 text-[13px] text-white/35">Sem tarefas cadastradas.</p>
                    ) : (
                        <div className="mt-3 space-y-3">
                            {resumo.map((r) => (
                                <div key={r.id ?? 'sem'}>
                                    <div className="flex items-center justify-between gap-2 text-[13px]">
                                        <span className="flex min-w-0 items-center gap-2">
                                            <span className="truncate rounded-md px-2 py-0.5 text-[11px] font-bold uppercase text-black"
                                                  style={{ background: r.id ? (cores[r.id] ?? COR_SEM_RESPONSAVEL) : COR_SEM_RESPONSAVEL }}>{r.nome}</span>
                                            <span className="text-white/45">{r.tarefas} {r.tarefas === 1 ? 'tarefa' : 'tarefas'}</span>
                                        </span>
                                        <b className="tabular-nums text-white">{r.pct === null ? '—' : `${r.pct}%`}</b>
                                    </div>
                                    <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-white/[0.08]">
                                        <div className="h-full rounded-full" style={{ width: `${r.pct ?? 0}%`, background: r.id ? (cores[r.id] ?? COR_SEM_RESPONSAVEL) : COR_SEM_RESPONSAVEL }} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <ProximosPassos itens={proximosPassos} onSalvar={onSalvarProximosPassos} />

            {editando && (
                <ModalTarefa inicial={editando} universo={universo} usuarios={usuarios}
                             onFechar={() => setEditando(null)} onSalvar={onSalvar} />
            )}
        </div>
    );
}
