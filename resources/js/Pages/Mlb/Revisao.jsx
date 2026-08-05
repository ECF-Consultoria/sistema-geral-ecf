import AppLayout from '@/Layouts/AppLayout';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    AlertOctagon, AlertTriangle, MessageSquare, CheckCircle2, RotateCcw,
    ExternalLink, Clock, ListChecks, BarChart3, ChevronDown, ChevronRight,
    Search, Undo2, ShieldCheck, Timer, Repeat, Ban,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const MESES_PT = {
    '01':'Janeiro','02':'Fevereiro','03':'Março','04':'Abril',
    '05':'Maio','06':'Junho','07':'Julho','08':'Agosto',
    '09':'Setembro','10':'Outubro','11':'Novembro','12':'Dezembro',
};

const nomeMes = (ym) => {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    return `${MESES_PT[m]} / ${y}`;
};

const fmt = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const mlbUrl = (code) => `https://produto.mercadolivre.com.br/${String(code).replace('MLB', 'MLB-')}`;

/**
 * "Revisado" não é estado: revisar não quer dizer que está correto.
 * O que importa é o veredicto — e de quem é a bola agora.
 */
const STATUS = {
    nao_revisado: { label: 'Não revisado', dot: 'bg-white/25',    text: 'text-white/45',    chip: 'bg-white/[0.04] border-white/[0.08]' },
    aprovado:     { label: 'Aprovado',     dot: 'bg-emerald-400', text: 'text-emerald-400', chip: 'bg-emerald-500/10 border-emerald-500/25' },
    em_ajuste:    { label: 'Em ajuste',    dot: 'bg-red-400',     text: 'text-red-400',     chip: 'bg-red-500/10 border-red-500/25' },
    reconferir:   { label: 'Reconferir',   dot: 'bg-amber-400',   text: 'text-amber-400',   chip: 'bg-amber-500/10 border-amber-500/25' },
};

const SEVERIDADE = {
    bloqueio:   { label: 'Bloqueio',   icon: AlertOctagon,  text: 'text-red-400',    chip: 'bg-red-500/10 border-red-500/30' },
    ajuste:     { label: 'Ajuste',     icon: AlertTriangle, text: 'text-orange-400', chip: 'bg-orange-500/10 border-orange-500/30' },
    observacao: { label: 'Observação', icon: MessageSquare, text: 'text-blue-400',   chip: 'bg-blue-500/10 border-blue-500/30' },
};

function StatusChip({ status, size = 'sm' }) {
    const s = STATUS[status] ?? STATUS.nao_revisado;
    return (
        <span className={cn(
            'inline-flex items-center gap-1.5 rounded-full border font-semibold whitespace-nowrap',
            s.chip, s.text,
            size === 'sm' ? 'px-2 py-0.5 text-[10px]' : 'px-2.5 py-1 text-[11px]',
        )}>
            <span className={cn('rounded-full', s.dot, size === 'sm' ? 'w-1.5 h-1.5' : 'w-2 h-2')} />
            {s.label}
        </span>
    );
}

function IdadeBadge({ dias }) {
    if (dias == null) return null;
    // O total de pendências não diz nada; o que dói é o que está parado há dias.
    const tom = dias > 7 ? 'text-red-400 bg-red-500/10 border-red-500/25'
        : dias > 2 ? 'text-orange-400 bg-orange-500/10 border-orange-500/25'
        : 'text-white/35 bg-white/[0.03] border-white/[0.08]';
    return (
        <span className={cn('inline-flex items-center gap-1 px-1.5 py-0.5 rounded border text-[10px] font-semibold', tom)}>
            <Clock size={9} /> {dias}d
        </span>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Fora da metodologia
// Anúncio publicado fora do método continua registrado — apagar destruiria a
// evidência de que foi feito. O que muda é que ele para de contar.
// ═══════════════════════════════════════════════════════════════════════

const TIRAR_DA_APURACAO = 'Fora da metodologia: deixa de contar em vendas, meta, conversão e score. O anúncio continua registrado.';
const VOLTAR_A_CONTAR   = 'Voltar a contar em vendas, meta, conversão e score.';

const alternarDesconsiderado = (pub) =>
    router.patch(
        route('mlb.revisao.desconsiderar', pub.id),
        { desconsiderado: !pub.desconsiderado },
        { preserveScroll: true, preserveState: true },
    );

function SeloForaMetodologia({ pub }) {
    if (!pub.desconsiderado) return null;

    const autoria = [pub.desconsiderado_por, pub.desconsiderado_em].filter(Boolean).join(' · ');

    return (
        <span
            className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border border-orange-500/30 bg-orange-500/10 text-orange-400 text-[10px] font-bold whitespace-nowrap"
            title={autoria ? `${TIRAR_DA_APURACAO}\nMarcado por ${autoria}` : TIRAR_DA_APURACAO}
        >
            <Ban size={9} /> Fora da metodologia
        </span>
    );
}

/** Botão de alternância. `compacto` usa só o ícone, para caber no card. */
function BotaoDesconsiderar({ pub, compacto = false }) {
    const fora = pub.desconsiderado;

    return (
        <button
            onClick={() => alternarDesconsiderado(pub)}
            title={fora ? VOLTAR_A_CONTAR : TIRAR_DA_APURACAO}
            className={cn(
                'rounded-lg border transition-colors inline-flex items-center justify-center gap-1 font-bold',
                compacto ? 'h-6 w-6' : 'h-7 px-2.5 text-[11px]',
                fora
                    ? 'border-orange-500/30 bg-orange-500/10 text-orange-400 hover:bg-orange-500/20'
                    : 'border-white/[0.08] text-white/30 hover:text-orange-400 hover:border-orange-500/30',
            )}
        >
            {fora ? <Undo2 size={compacto ? 10 : 11} /> : <Ban size={compacto ? 10 : 11} />}
            {!compacto && (fora ? 'Voltar a contar' : 'Desconsiderar')}
        </button>
    );
}

function Kpi({ title, value, sub, tone = 'neutral', icon: Icon }) {
    const tones = {
        neutral: 'text-white',
        green:   'text-emerald-400',
        red:     'text-red-400',
        amber:   'text-amber-400',
    };
    return (
        <div className="card-ecf rounded-xl p-4">
            <div className="flex items-center gap-2 mb-1.5">
                {Icon && <Icon size={13} className="text-white/25" />}
                <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">{title}</p>
            </div>
            <p className={cn('font-display font-bold text-2xl leading-none', tones[tone])}>{value}</p>
            {sub && <p className="text-white/25 text-[11px] mt-1.5">{sub}</p>}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Registro de pendência
// ═══════════════════════════════════════════════════════════════════════

function FormPendencia({ pubId, severidades, onClose }) {
    // Sem campo de categoria: era opcional e o time não preencheria, então só
    // somava atrito. A coluna segue no banco caso volte a fazer sentido.
    const { data, setData, post, processing, errors } = useForm({
        severidade: 'ajuste',
        texto: '',
    });

    function salvar() {
        post(route('mlb.pendencia.abrir', pubId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
        });
    }

    return (
        <div className="mt-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 space-y-2.5">
            <div className="flex flex-wrap gap-1.5">
                {Object.keys(severidades).map((key) => {
                    const s = SEVERIDADE[key];
                    if (!s) return null;
                    const ativo = data.severidade === key;
                    const Icon = s.icon;
                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setData('severidade', key)}
                            className={cn(
                                'inline-flex items-center gap-1.5 px-2.5 h-7 rounded-lg border text-[11px] font-semibold transition-all',
                                ativo ? cn(s.chip, s.text) : 'border-white/[0.08] text-white/35 hover:text-white/60',
                            )}
                        >
                            <Icon size={11} /> {s.label}
                        </button>
                    );
                })}
            </div>

            <textarea
                value={data.texto}
                onChange={(e) => setData('texto', e.target.value)}
                rows={2}
                autoFocus
                placeholder="O que precisa ser corrigido?"
                className="w-full px-3 py-2 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 resize-none placeholder:text-white/20"
            />
            {errors.texto && <p className="text-red-400 text-[11px]">{errors.texto}</p>}

            <div className="flex gap-2">
                <button
                    onClick={salvar}
                    disabled={processing || !data.texto.trim()}
                    className="h-7 px-3 rounded-lg bg-ecf-yellow text-[#252525] text-[12px] font-bold disabled:opacity-40"
                >
                    Registrar pendência
                </button>
                <button
                    onClick={onClose}
                    className="h-7 px-3 rounded-lg border border-white/[0.08] text-white/40 text-[12px] hover:text-white/70"
                >
                    Cancelar
                </button>
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Linha da fila — densa, não card: o líder passa por dezenas por vez
// ═══════════════════════════════════════════════════════════════════════

function LinhaPub({ pub, selecionado, onToggleSelecao, podeRevisar, severidades, categorias }) {
    const [abrirForm, setAbrirForm] = useState(false);
    const [expandido, setExpandido] = useState(false);

    const temPendencia = pub.pendencias?.length > 0;
    const mostrarDetalhe = expandido || temPendencia || abrirForm;

    const aprovar  = () => router.patch(route('mlb.revisao.aprovar', pub.id), {}, { preserveScroll: true, preserveState: true });
    const reverter = () => router.patch(route('mlb.revisao.reverter', pub.id), {}, { preserveScroll: true, preserveState: true });
    const resolver = (id) => router.patch(route('mlb.pendencia.resolver', id), {}, { preserveScroll: true, preserveState: true });
    const reabrir  = (id) => router.patch(route('mlb.pendencia.reabrir', id), {}, { preserveScroll: true, preserveState: true });

    return (
        <div className={cn(
            'rounded-xl border transition-all',
            pub.status_revisao === 'em_ajuste'  ? 'border-red-500/25 bg-red-500/[0.03]'
          : pub.status_revisao === 'reconferir' ? 'border-amber-500/25 bg-amber-500/[0.03]'
          : pub.status_revisao === 'aprovado'   ? 'border-white/[0.05] bg-white/[0.01]'
          : 'border-white/[0.07] bg-white/[0.02]',
        )}>
            <div className="flex items-center gap-3 px-3 py-2.5">
                {podeRevisar && (
                    <input
                        type="checkbox"
                        checked={selecionado}
                        onChange={() => onToggleSelecao(pub.id)}
                        className="shrink-0 w-3.5 h-3.5 rounded border-white/20 bg-white/[0.03] accent-ecf-yellow cursor-pointer"
                    />
                )}

                <button
                    onClick={() => setExpandido(v => !v)}
                    className="shrink-0 text-white/20 hover:text-white/50 transition-colors"
                    title={expandido ? 'Recolher' : 'Ver detalhes'}
                >
                    {mostrarDetalhe ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                </button>

                <a
                    href={mlbUrl(pub.mlb_code)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="shrink-0 w-[132px] text-purple-400 font-mono text-[12px] hover:text-purple-300 flex items-center gap-1"
                >
                    {pub.mlb_code} <ExternalLink size={10} />
                </a>

                <span className="flex-1 min-w-0 text-white/80 text-[13px] truncate" title={pub.empresa}>
                    {pub.empresa || '— sem loja —'}
                </span>

                <span className="hidden md:flex shrink-0 w-[140px] items-center gap-1.5 text-white/40 text-[12px] truncate">
                    {pub.usuario}
                    {pub.usuario_removido && (
                        <span className="px-1 py-0.5 rounded text-[9px] bg-red-500/15 border border-red-500/30 text-red-400">rem</span>
                    )}
                </span>

                <span className="hidden lg:block shrink-0 w-[70px] text-white/25 text-[11px]">{pub.data}</span>

                <div className="shrink-0 flex items-center gap-1.5">
                    {pub.pendencias?.map(p => {
                        const s = SEVERIDADE[p.severidade] ?? SEVERIDADE.ajuste;
                        const Icon = s.icon;
                        return (
                            <span
                                key={p.id}
                                className={cn('inline-flex items-center px-1.5 py-1 rounded border', s.chip, s.text)}
                                title={`${s.label}: ${p.texto ?? ''}`}
                            >
                                <Icon size={9} />
                            </span>
                        );
                    })}
                    <SeloForaMetodologia pub={pub} />
                    <StatusChip status={pub.status_revisao} />
                </div>

                {podeRevisar && (
                    <div className="shrink-0 flex items-center gap-1">
                        {/* Fora da apuração não há veredicto a dar: sobra só o desfazer. */}
                        {!pub.desconsiderado && (
                            <>
                                {pub.status_revisao !== 'aprovado' && !temPendencia && (
                                    <button
                                        onClick={aprovar}
                                        title="Aprovar — conferido e correto"
                                        className="h-7 px-2.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-400 text-[11px] font-bold hover:bg-emerald-500/20 transition-colors inline-flex items-center gap-1"
                                    >
                                        <CheckCircle2 size={11} /> Aprovar
                                    </button>
                                )}
                                {!abrirForm && (
                                    <button
                                        onClick={() => setAbrirForm(true)}
                                        title="Registrar pendência"
                                        className="h-7 px-2.5 rounded-lg border border-white/[0.08] text-white/40 text-[11px] font-semibold hover:text-white/80 hover:border-white/20 transition-colors inline-flex items-center gap-1"
                                    >
                                        <AlertTriangle size={11} /> Pendência
                                    </button>
                                )}
                                {pub.status_revisao === 'aprovado' && (
                                    <button
                                        onClick={reverter}
                                        title="Desfazer aprovação"
                                        className="h-7 w-7 rounded-lg border border-white/[0.06] text-white/20 hover:text-white/60 transition-colors inline-flex items-center justify-center"
                                    >
                                        <Undo2 size={11} />
                                    </button>
                                )}
                            </>
                        )}
                        <BotaoDesconsiderar pub={pub} />
                    </div>
                )}
            </div>

            {mostrarDetalhe && (
                <div className="px-3 pb-3 pl-11 space-y-2">
                    {pub.pendencias?.map(p => {
                        const s = SEVERIDADE[p.severidade] ?? SEVERIDADE.ajuste;
                        const Icon = s.icon;
                        return (
                            <div key={p.id} className={cn('rounded-lg border px-3 py-2', s.chip)}>
                                <div className="flex items-start gap-2">
                                    <Icon size={12} className={cn('mt-0.5 shrink-0', s.text)} />
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className={cn('text-[11px] font-bold uppercase tracking-wide', s.text)}>{s.label}</span>
                                            {p.categoria && (
                                                <span className="text-white/40 text-[10px] px-1.5 py-0.5 rounded bg-white/[0.05]">
                                                    {categorias[p.categoria] ?? p.categoria}
                                                </span>
                                            )}
                                            <IdadeBadge dias={p.idade_dias} />
                                            {p.status === 'corrigida' && (
                                                <span className="inline-flex items-center gap-1 text-amber-400 text-[10px] font-bold">
                                                    <RotateCcw size={9} /> aguardando sua conferência
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-white/70 text-[12px] mt-1 whitespace-pre-line">{p.texto}</p>
                                        <p className="text-white/25 text-[10px] mt-1">
                                            aberta por {p.aberta_por ?? '—'} · {p.aberta_em ?? '—'}
                                            {p.corrigida_por && ` · corrigida por ${p.corrigida_por} em ${p.corrigida_em}`}
                                        </p>
                                    </div>

                                    {podeRevisar && (
                                        <div className="shrink-0 flex items-center gap-1">
                                            <button
                                                onClick={() => resolver(p.id)}
                                                title="Confirmar que está resolvido"
                                                className="h-6 px-2 rounded border border-emerald-500/30 text-emerald-400 text-[10px] font-bold hover:bg-emerald-500/15 transition-colors"
                                            >
                                                Resolver
                                            </button>
                                            {p.status === 'corrigida' && (
                                                <button
                                                    onClick={() => reabrir(p.id)}
                                                    title="Ainda não resolveu — devolver ao publicador"
                                                    className="h-6 px-2 rounded border border-white/[0.08] text-white/35 text-[10px] font-bold hover:text-white/70 transition-colors"
                                                >
                                                    Reabrir
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {expandido && !temPendencia && pub.revisado_por && (
                        <p className="text-white/25 text-[11px]">
                            Aprovado por {pub.revisado_por}{pub.revisado_em && ` · ${pub.revisado_em}`}
                        </p>
                    )}

                    {abrirForm && (
                        <FormPendencia
                            pubId={pub.id}
                            severidades={severidades}
                            onClose={() => setAbrirForm(false)}
                        />
                    )}
                </div>
            )}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Aba Fila — a lista de trabalho do líder
// ═══════════════════════════════════════════════════════════════════════

/**
 * Card de uma publicação dentro da coluna de estado.
 * Compacto de propósito: nada estica, então não sobra espaço morto no meio
 * como acontecia na linha larga que repetia o nome da loja.
 */
function CardEstado({ pub, selecionado, onToggleSelecao, podeRevisar, severidades, categorias }) {
    const [abrirForm, setAbrirForm] = useState(false);

    const pendencia = pub.pendencias?.[0];
    const sev = pendencia ? (SEVERIDADE[pendencia.severidade] ?? SEVERIDADE.ajuste) : null;
    const IconSev = sev?.icon;

    const aprovar  = () => router.patch(route('mlb.revisao.aprovar', pub.id), {}, { preserveScroll: true, preserveState: true });
    const reverter = () => router.patch(route('mlb.revisao.reverter', pub.id), {}, { preserveScroll: true, preserveState: true });
    const resolver = (id) => router.patch(route('mlb.pendencia.resolver', id), {}, { preserveScroll: true, preserveState: true });
    const reabrir  = (id) => router.patch(route('mlb.pendencia.reabrir', id), {}, { preserveScroll: true, preserveState: true });

    return (
        <div className={cn(
            'rounded-lg border p-2.5 transition-colors',
            pub.status_revisao === 'em_ajuste'  ? 'border-red-500/25 bg-red-500/[0.04]'
          : pub.status_revisao === 'reconferir' ? 'border-amber-500/25 bg-amber-500/[0.04]'
          : 'border-white/[0.07] bg-white/[0.015] hover:bg-white/[0.03]',
        )}>
            <div className="flex items-center gap-2">
                {podeRevisar && (
                    <input
                        type="checkbox"
                        checked={selecionado}
                        onChange={() => onToggleSelecao(pub.id)}
                        className="shrink-0 w-3.5 h-3.5 rounded border-white/20 bg-white/[0.03] accent-ecf-yellow cursor-pointer"
                    />
                )}
                <a
                    href={mlbUrl(pub.mlb_code)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-purple-400 font-mono text-[11.5px] hover:text-purple-300 truncate"
                >
                    {pub.mlb_code}
                </a>
                <div className="ml-auto flex items-center gap-1.5 shrink-0">
                    <SeloForaMetodologia pub={pub} />
                    {sev && (
                        <span className={cn('inline-flex items-center gap-1 px-1.5 py-0.5 rounded border text-[10px] font-bold', sev.chip, sev.text)}>
                            <IconSev size={9} /> {sev.label}
                        </span>
                    )}
                    <IdadeBadge dias={pendencia?.idade_dias} />
                </div>
            </div>

            {pendencia?.texto && (
                <p className="text-white/70 text-[12px] mt-1.5 leading-snug line-clamp-2" title={pendencia.texto}>
                    {pendencia.texto}
                </p>
            )}

            <div className="flex items-center gap-2 mt-1.5">
                <span className="text-white/35 text-[11px] truncate min-w-0" title={`${pub.empresa} · ${pub.usuario}`}>
                    {pub.empresa || '— sem loja —'} · {pub.usuario}
                </span>
                {pub.usuario_removido && (
                    <span className="shrink-0 px-1 py-0.5 rounded text-[9px] bg-red-500/15 border border-red-500/30 text-red-400">rem</span>
                )}
            </div>

            <div className="flex items-center justify-between gap-2 mt-2">
                <span className="text-white/20 text-[10.5px] tabular-nums shrink-0">
                    {pendencia?.status === 'corrigida'
                        ? `corrigido por ${pendencia.corrigida_por ?? '—'}`
                        : pub.data}
                </span>

                {podeRevisar && (
                    <div className="flex items-center gap-1 shrink-0">
                        {pendencia ? (
                            <>
                                <button
                                    onClick={() => resolver(pendencia.id)}
                                    className="h-6 px-2 rounded border border-emerald-500/30 text-emerald-400 text-[10.5px] font-bold hover:bg-emerald-500/15 transition-colors"
                                >
                                    Resolver
                                </button>
                                {pendencia.status === 'corrigida' && (
                                    <button
                                        onClick={() => reabrir(pendencia.id)}
                                        className="h-6 px-2 rounded border border-white/[0.08] text-white/35 text-[10.5px] font-bold hover:text-white/70 transition-colors"
                                    >
                                        Reabrir
                                    </button>
                                )}
                            </>
                        ) : pub.status_revisao === 'aprovado' ? (
                            <button
                                onClick={reverter}
                                title="Desfazer aprovação"
                                className="h-6 w-6 rounded border border-white/[0.06] text-white/20 hover:text-white/60 inline-flex items-center justify-center"
                            >
                                <Undo2 size={10} />
                            </button>
                        ) : (
                            <>
                                <button
                                    onClick={aprovar}
                                    title="Aprovar — conferido e correto"
                                    className="h-6 px-2 rounded border border-emerald-500/30 bg-emerald-500/10 text-emerald-400 text-[10.5px] font-bold hover:bg-emerald-500/20 transition-colors inline-flex items-center gap-1"
                                >
                                    <CheckCircle2 size={10} /> Aprovar
                                </button>
                                <button
                                    onClick={() => setAbrirForm(v => !v)}
                                    title="Registrar pendência"
                                    className="h-6 w-6 rounded border border-white/[0.08] text-white/30 hover:text-white/80 inline-flex items-center justify-center"
                                >
                                    <AlertTriangle size={10} />
                                </button>
                            </>
                        )}
                        <BotaoDesconsiderar pub={pub} compacto />
                    </div>
                )}
            </div>

            {abrirForm && (
                <FormPendencia
                    pubId={pub.id}
                    severidades={severidades}
                    categorias={categorias}
                    onClose={() => setAbrirForm(false)}
                />
            )}
        </div>
    );
}

/** Coluna de um estado: cabeçalho com total real, cards e corte explícito. */
function ColunaEstado({ estado, dados, rotulo, sub, tom, onVerTodos, selecionados = [], ...cardProps }) {
    const itens = dados?.itens ?? [];
    const total = dados?.total ?? 0;

    return (
        <div className="flex flex-col min-w-0">
            <div className={cn('flex items-center justify-between gap-2 px-3 py-2 rounded-lg border mb-2', tom.head)}>
                <div className="min-w-0">
                    <p className={cn('text-[11.5px] font-bold leading-none truncate', tom.text)}>{rotulo}</p>
                    {sub && <p className="text-white/25 text-[10px] mt-1 truncate">{sub}</p>}
                </div>
                <span className={cn('shrink-0 font-display font-bold text-lg tabular-nums leading-none', tom.text)}>
                    {fmt(total)}
                </span>
            </div>

            <div className="space-y-1.5 overflow-y-auto pr-1 max-h-[62vh]">
                {itens.length === 0 && (
                    <div className="rounded-lg border border-white/[0.05] bg-white/[0.01] p-6 text-center">
                        <p className="text-white/20 text-[12px]">Nada aqui.</p>
                    </div>
                )}

                {itens.map(p => (
                    <CardEstado
                        key={p.id}
                        pub={p}
                        selecionado={selecionados.includes(p.id)}
                        {...cardProps}
                    />
                ))}

                {/* Corte explícito: silenciar o resto faria a coluna parecer completa. */}
                {dados?.truncado && (
                    <button
                        onClick={() => onVerTodos(estado)}
                        className="w-full rounded-lg border border-dashed border-white/[0.1] py-2.5 text-white/35 text-[11.5px] hover:text-white/70 hover:border-white/20 transition-colors"
                    >
                        mostrando {itens.length} de {fmt(total)} — ver todos
                    </button>
                )}
            </div>
        </div>
    );
}

function AbaFila({ colunas, publicacoes, kpis, filters, publicadores, podeRevisar, severidades, categorias, aplicar }) {
    const [selecionados, setSelecionados] = useState([]);
    const [busca, setBusca] = useState(filters.busca ?? '');

    const emColunas = !!colunas;
    const linhas = publicacoes?.data ?? [];

    const toggleSelecao = (id) =>
        setSelecionados(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id]);

    const todosSelecionados = linhas.length > 0 && selecionados.length === linhas.length;
    const toggleTodos = () => setSelecionados(todosSelecionados ? [] : linhas.map(l => l.id));

    function aprovarLote() {
        router.post(route('mlb.revisao.aprovar-lote'), { ids: selecionados }, {
            preserveScroll: true,
            onSuccess: () => setSelecionados([]),
        });
    }

    // Agrupa por loja — só na lista filtrada por um estado específico.
    const grupos = useMemo(() => {
        const mapa = new Map();
        for (const p of linhas) {
            const chave = p.empresa || '— sem loja —';
            if (!mapa.has(chave)) mapa.set(chave, []);
            mapa.get(chave).push(p);
        }
        return [...mapa.entries()];
    }, [linhas]);

    const cardProps = {
        onToggleSelecao: toggleSelecao,
        podeRevisar,
        severidades,
        categorias,
    };

    const vazio = emColunas
        ? Object.values(colunas).every(c => (c?.total ?? 0) === 0)
        : linhas.length === 0;

    return (
        <>
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
                <Kpi title="Na competência" value={fmt(kpis?.total)} icon={ListChecks}
                     sub={kpis?.cobertura != null ? `${kpis.cobertura}% com veredicto` : null} />
                <Kpi title="Não revisado" value={fmt(kpis?.nao_revisado)} />
                <Kpi title="Aprovado" value={fmt(kpis?.aprovado)} tone="green" icon={ShieldCheck} />
                <Kpi title="Em ajuste" value={fmt(kpis?.em_ajuste)} tone="red" sub="bola com o publicador" />
                <Kpi title="Reconferir" value={fmt(kpis?.reconferir)} tone="amber" sub="bola com você" />
                {/* Fora do total acima de propósito: não entram na apuração. */}
                <Kpi title="Fora da metodologia" value={fmt(kpis?.desconsiderados)} icon={Ban}
                     sub="não contam em vendas nem meta" />
            </div>

            <div className="card-ecf rounded-2xl p-3 mb-4 flex flex-wrap gap-2 items-center">
                <select
                    value={filters.mesRef}
                    onChange={(e) => aplicar({ mes: e.target.value })}
                    className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    {(filters.meses ?? []).map(m => <option key={m} value={m}>{nomeMes(m)}</option>)}
                </select>

                <select
                    value={filters.status}
                    onChange={(e) => aplicar({ status: e.target.value })}
                    className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    <option value="pendentes">Colunas por estado</option>
                    <option value="todos">Lista · todos</option>
                    <option value="nao_revisado">Lista · não revisado</option>
                    <option value="aprovado">Lista · aprovado</option>
                    <option value="em_ajuste">Lista · em ajuste</option>
                    <option value="reconferir">Lista · reconferir</option>
                    {/* Única visão que enxerga os desconsiderados — é por onde se desfaz. */}
                    <option value="desconsiderados">Lista · fora da metodologia</option>
                </select>

                {publicadores?.length > 0 && (
                    <select
                        value={filters.funcId}
                        onChange={(e) => aplicar({ func: e.target.value })}
                        className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                    >
                        <option value="">Todos os publicadores</option>
                        {publicadores.map(p => <option key={p.id} value={p.id}>{p.nome}</option>)}
                    </select>
                )}

                <select
                    value={filters.severidade}
                    onChange={(e) => aplicar({ sev: e.target.value })}
                    className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    <option value="">Qualquer severidade</option>
                    {Object.entries(severidades).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>

                <div className="relative flex-1 min-w-[180px]">
                    <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/20" />
                    <input
                        type="text"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && aplicar({ busca })}
                        placeholder="Buscar MLB ou loja…"
                        className="w-full h-9 pl-8 pr-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none placeholder:text-white/20"
                    />
                </div>
            </div>

            {podeRevisar && selecionados.length > 0 && (
                <div className="flex items-center gap-3 mb-3 px-1">
                    <button
                        onClick={aprovarLote}
                        className="h-8 px-3 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-[12px] font-bold hover:bg-emerald-500/25 transition-colors inline-flex items-center gap-1.5"
                    >
                        <CheckCircle2 size={12} /> Aprovar {selecionados.length} selecionado(s)
                    </button>
                    <button
                        onClick={() => setSelecionados([])}
                        className="text-white/30 text-[12px] hover:text-white/60"
                    >
                        limpar seleção
                    </button>
                </div>
            )}

            {podeRevisar && !emColunas && linhas.length > 0 && selecionados.length === 0 && (
                <div className="flex items-center gap-3 mb-3 px-1">
                    <label className="flex items-center gap-2 cursor-pointer select-none">
                        <input
                            type="checkbox"
                            checked={todosSelecionados}
                            onChange={toggleTodos}
                            className="w-3.5 h-3.5 rounded border-white/20 bg-white/[0.03] accent-ecf-yellow cursor-pointer"
                        />
                        <span className="text-white/35 text-[12px]">Selecionar página</span>
                    </label>
                </div>
            )}

            {vazio && (
                <div className="card-ecf rounded-2xl p-12 text-center">
                    <ShieldCheck size={28} className="text-emerald-400/30 mx-auto mb-3" />
                    <p className="text-white/40 text-sm">Nada esperando por você nesta competência.</p>
                    <p className="text-white/20 text-[12px] mt-1">Troque o filtro de status para ver o que já foi revisado.</p>
                </div>
            )}

            {/* ─── Colunas por estado: agrupa pelo que precisa acontecer ─── */}
            {emColunas && !vazio && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                    <ColunaEstado
                        estado="nao_revisado"
                        dados={colunas.nao_revisado}
                        rotulo="Não revisado"
                        sub="ninguém olhou ainda"
                        tom={{ head: 'border-white/[0.08] bg-white/[0.03]', text: 'text-white/70' }}
                        onVerTodos={(e) => aplicar({ status: e })}
                        {...cardProps}
                        selecionados={selecionados}
                    />
                    <ColunaEstado
                        estado="em_ajuste"
                        dados={colunas.em_ajuste}
                        rotulo="Em ajuste"
                        sub="bola com o publicador"
                        tom={{ head: 'border-red-500/25 bg-red-500/10', text: 'text-red-400' }}
                        onVerTodos={(e) => aplicar({ status: e })}
                        {...cardProps}
                        selecionados={selecionados}
                    />
                    <ColunaEstado
                        estado="reconferir"
                        dados={colunas.reconferir}
                        rotulo="Reconferir"
                        sub="bola com você"
                        tom={{ head: 'border-amber-500/25 bg-amber-500/10', text: 'text-amber-400' }}
                        onVerTodos={(e) => aplicar({ status: e })}
                        {...cardProps}
                        selecionados={selecionados}
                    />
                </div>
            )}

            {/* ─── Lista agrupada por loja: quando um estado é filtrado ─── */}
            {!emColunas && (
                <div className="space-y-5">
                    {grupos.map(([empresa, pubs]) => (
                        <div key={empresa}>
                            <div className="flex items-center gap-2 mb-2 pl-1">
                                <div className="w-1 h-4 rounded-full bg-ecf-yellow shrink-0" />
                                <span className="text-white font-semibold text-[14px]">{empresa}</span>
                                <span className="text-white/25 text-[11px]">{pubs.length} anúncio(s)</span>
                            </div>
                            <div className="space-y-1.5">
                                {pubs.map(p => (
                                    <LinhaPub
                                        key={p.id}
                                        pub={p}
                                        selecionado={selecionados.includes(p.id)}
                                        onToggleSelecao={toggleSelecao}
                                        podeRevisar={podeRevisar}
                                        severidades={severidades}
                                        categorias={categorias}
                                    />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {publicacoes?.links?.length > 3 && (
                <div className="flex flex-wrap gap-1 justify-center mt-6">
                    {publicacoes.links.map((l, i) => (
                        <button
                            key={i}
                            disabled={!l.url}
                            onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                            className={cn(
                                'h-8 min-w-8 px-2.5 rounded-lg text-[12px] font-medium transition-colors',
                                l.active ? 'bg-ecf-yellow text-[#252525] font-bold'
                                    : l.url ? 'border border-white/[0.08] text-white/50 hover:text-white/90'
                                    : 'text-white/15 cursor-default',
                            )}
                            dangerouslySetInnerHTML={{ __html: l.label }}
                        />
                    ))}
                </div>
            )}
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Aba Gráfico — o que o gestor nunca conseguiu ver
// ═══════════════════════════════════════════════════════════════════════

function BarraProporcao({ partes }) {
    const total = partes.reduce((s, p) => s + (p.valor ?? 0), 0);
    if (!total) return <div className="h-2 rounded-full bg-white/[0.04]" />;

    return (
        <div className="flex h-2 rounded-full overflow-hidden bg-white/[0.04]">
            {partes.filter(p => p.valor > 0).map((p, i) => (
                <div
                    key={i}
                    className={p.cor}
                    style={{ width: `${(p.valor / total) * 100}%` }}
                    title={`${p.label}: ${p.valor}`}
                />
            ))}
        </div>
    );
}

function AbaGrafico({ grafico, filters, aplicar }) {
    const s = grafico ?? {};
    const cob = s.cobertura ?? {};
    const aging = s.aging ?? {};
    const maxPendencias = Math.max(1, ...(s.por_publicador ?? []).map(x => x.n));

    return (
        <>
            <div className="card-ecf rounded-2xl p-3 mb-5 flex flex-wrap gap-2 items-center">
                <select
                    value={filters.mesRef}
                    onChange={(e) => aplicar({ mes: e.target.value })}
                    className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    {(filters.meses ?? []).map(m => <option key={m} value={m}>{nomeMes(m)}</option>)}
                </select>
            </div>

            <div className="card-ecf rounded-2xl p-5 mb-5">
                <div className="flex items-end justify-between mb-3 flex-wrap gap-3">
                    <div>
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-1.5">Cobertura da competência</p>
                        <p className="font-display font-bold text-3xl text-white leading-none">
                            {cob.pct != null ? `${cob.pct}%` : '—'}
                            <span className="text-white/25 text-sm font-normal ml-2">
                                {fmt((cob.total ?? 0) - (cob.nao_revisado ?? 0))} de {fmt(cob.total)} com veredicto
                            </span>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        <StatusChip status="aprovado" size="md" />
                        <StatusChip status="em_ajuste" size="md" />
                        <StatusChip status="reconferir" size="md" />
                        <StatusChip status="nao_revisado" size="md" />
                    </div>
                </div>

                <BarraProporcao partes={[
                    { label: 'Aprovado',     valor: cob.aprovado ?? 0,     cor: 'bg-emerald-400' },
                    { label: 'Em ajuste',    valor: cob.em_ajuste ?? 0,    cor: 'bg-red-400' },
                    { label: 'Reconferir',   valor: cob.reconferir ?? 0,   cor: 'bg-amber-400' },
                    { label: 'Não revisado', valor: cob.nao_revisado ?? 0, cor: 'bg-white/15' },
                ]} />

                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
                    <div><p className="text-emerald-400 font-bold text-lg">{fmt(cob.aprovado)}</p><p className="text-white/30 text-[11px]">Aprovados</p></div>
                    <div><p className="text-red-400 font-bold text-lg">{fmt(cob.em_ajuste)}</p><p className="text-white/30 text-[11px]">Em ajuste</p></div>
                    <div><p className="text-amber-400 font-bold text-lg">{fmt(cob.reconferir)}</p><p className="text-white/30 text-[11px]">Reconferir</p></div>
                    <div><p className="text-white/50 font-bold text-lg">{fmt(cob.nao_revisado)}</p><p className="text-white/30 text-[11px]">Não revisado</p></div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
                <Kpi title="Pendências em aberto" value={fmt(s.pendentes_total)} tone="red" icon={AlertTriangle}
                     sub={`${fmt(aging.mais_7d)} parada(s) há mais de 7 dias`} />
                <Kpi title="Tempo médio de resolução" value={s.tempo_medio_horas != null ? `${s.tempo_medio_horas}h` : '—'}
                     icon={Timer} sub="da abertura até o líder confirmar" />
                <Kpi title="Reaberturas" value={fmt(s.reaberturas)} tone="amber" icon={Repeat}
                     sub="correções que não resolveram" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
                {/* A resposta a "o que o líder andou revisando" */}
                <div className="card-ecf rounded-2xl p-5">
                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-3">Quem revisou no mês</p>
                    {(s.por_revisor ?? []).length === 0 ? (
                        <p className="text-white/20 text-[12px] py-6 text-center">Nenhuma revisão registrada nesta competência.</p>
                    ) : (
                        <div className="space-y-2.5">
                            <div className="flex items-center gap-3 pb-1.5 border-b border-white/[0.06]">
                                <span className="flex-1 text-white/20 text-[10px] uppercase tracking-wide">revisor</span>
                                <span className="shrink-0 text-white/20 text-[10px] w-12 text-right">aprov.</span>
                                <span className="shrink-0 text-white/20 text-[10px] w-12 text-right">ajuste</span>
                                <span className="shrink-0 text-white/20 text-[10px] w-24 text-right">última</span>
                            </div>
                            {s.por_revisor.map((r, i) => (
                                <div key={i} className="flex items-center gap-3">
                                    <span className="flex-1 min-w-0 text-white/80 text-[13px] truncate">{r.revisor}</span>
                                    <span className="shrink-0 text-emerald-400 text-[12px] font-semibold w-12 text-right">{fmt(r.aprovados)}</span>
                                    <span className="shrink-0 text-red-400 text-[12px] font-semibold w-12 text-right">{fmt(r.ajustes)}</span>
                                    <span className="shrink-0 text-white/25 text-[11px] w-24 text-right">{r.ultima}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Quem mais recebe pendência — dado que se preenche sozinho */}
                <div className="card-ecf rounded-2xl p-5">
                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide mb-3">Quem mais recebe pendência</p>
                    {(s.por_publicador ?? []).length === 0 ? (
                        <p className="text-white/20 text-[12px] py-6 text-center">Sem pendências nesta competência.</p>
                    ) : (
                        <div className="space-y-2">
                            {s.por_publicador.map((c, i) => (
                                <div key={i} className="flex items-center gap-3">
                                    <span className="w-32 shrink-0 text-white/60 text-[12px] truncate" title={c.publicador}>{c.publicador}</span>
                                    <div className="flex-1 h-2 rounded-full bg-white/[0.04] overflow-hidden">
                                        <div className="h-full rounded-full bg-ecf-yellow/70" style={{ width: `${(c.n / maxPendencias) * 100}%` }} />
                                    </div>
                                    <span className="shrink-0 w-8 text-right text-white/50 text-[12px] font-semibold tabular-nums">{c.n}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="card-ecf rounded-2xl p-5">
                <div className="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">Pendências mais antigas</p>
                    <div className="flex gap-2">
                        <span className="px-2 py-0.5 rounded-full border border-white/[0.08] text-white/40 text-[10px] font-semibold">até 2d: {fmt(aging.ate_2d)}</span>
                        <span className="px-2 py-0.5 rounded-full border border-orange-500/25 bg-orange-500/10 text-orange-400 text-[10px] font-semibold">3–7d: {fmt(aging.de_3_a_7d)}</span>
                        <span className="px-2 py-0.5 rounded-full border border-red-500/25 bg-red-500/10 text-red-400 text-[10px] font-semibold">+7d: {fmt(aging.mais_7d)}</span>
                    </div>
                </div>

                {(s.mais_antigas ?? []).length === 0 ? (
                    <p className="text-white/20 text-[12px] py-6 text-center">Nenhuma pendência em aberto.</p>
                ) : (
                    <div className="space-y-1.5">
                        {s.mais_antigas.map(p => {
                            const sev = SEVERIDADE[p.severidade] ?? SEVERIDADE.ajuste;
                            const Icon = sev.icon;
                            return (
                                <div key={p.id} className="flex items-center gap-3 px-3 py-2 rounded-lg border border-white/[0.05] bg-white/[0.01]">
                                    <Icon size={12} className={cn('shrink-0', sev.text)} />
                                    <a
                                        href={mlbUrl(p.mlb_code)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="shrink-0 w-[120px] text-purple-400 font-mono text-[11px] hover:text-purple-300"
                                    >
                                        {p.mlb_code}
                                    </a>
                                    <span className="flex-1 min-w-0 text-white/60 text-[12px] truncate" title={p.texto}>
                                        {p.texto || p.empresa}
                                    </span>
                                    <span className="hidden md:block shrink-0 w-[120px] text-white/30 text-[11px] truncate">{p.publicador}</span>
                                    {p.status === 'corrigida' && (
                                        <span className="shrink-0 text-amber-400 text-[10px] font-bold">reconferir</span>
                                    )}
                                    <IdadeBadge dias={p.idade_dias} />
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

// ═══════════════════════════════════════════════════════════════════════

export default function Revisao({
    modo = 'fila',
    colunas, publicacoes, kpis, grafico, filters = {}, publicadores = [], meses = [],
    pode_revisar: podeRevisar = false,
    pode_ver_grafico: podeVerGrafico = false,
    severidades = {}, categorias = {},
}) {
    function aplicar(updates) {
        router.get(route('mlb.revisao'), {
            modo,
            mes:    filters.mesRef,
            func:   filters.funcId,
            status: filters.status,
            sev:    filters.severidade,
            busca:  filters.busca,
            ...updates,
        }, { preserveState: true, preserveScroll: true });
    }

    const trocarModo = (novo) =>
        router.get(route('mlb.revisao'), { modo: novo, mes: filters.mesRef });

    const filtrosComMeses = { ...filters, meses };

    return (
        <AppLayout title="Revisão">
            <div className="flex items-start justify-between gap-4 mb-5 flex-wrap">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl">Revisão de Anúncios</h1>
                    <p className="text-white/40 text-sm mt-0.5">
                        {modo === 'grafico'
                            ? 'O que foi revisado, por quem, e o que está parado'
                            : 'Aprove o que está correto e registre o que precisa de ajuste'}
                    </p>
                </div>

                {podeVerGrafico && (
                    <div className="flex rounded-xl border border-white/[0.08] bg-white/[0.02] p-1">
                        <button
                            onClick={() => trocarModo('fila')}
                            className={cn(
                                'h-8 px-3.5 rounded-lg text-[12px] font-semibold transition-all inline-flex items-center gap-1.5',
                                modo === 'fila' ? 'bg-ecf-yellow text-[#252525]' : 'text-white/40 hover:text-white/70',
                            )}
                        >
                            <ListChecks size={12} /> Fila
                        </button>
                        <button
                            onClick={() => trocarModo('grafico')}
                            className={cn(
                                'h-8 px-3.5 rounded-lg text-[12px] font-semibold transition-all inline-flex items-center gap-1.5',
                                modo === 'grafico' ? 'bg-ecf-yellow text-[#252525]' : 'text-white/40 hover:text-white/70',
                            )}
                        >
                            <BarChart3 size={12} /> Gráfico
                        </button>
                    </div>
                )}
            </div>

            {modo === 'grafico' ? (
                <AbaGrafico grafico={grafico} filters={filtrosComMeses} aplicar={aplicar} />
            ) : (
                <AbaFila
                    colunas={colunas}
                    publicacoes={publicacoes}
                    kpis={kpis}
                    filters={filtrosComMeses}
                    publicadores={publicadores}
                    podeRevisar={podeRevisar}
                    severidades={severidades}
                    categorias={categorias}
                    aplicar={aplicar}
                />
            )}
        </AppLayout>
    );
}
