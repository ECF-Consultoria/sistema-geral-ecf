import { useMemo, useState } from 'react';
import {
    FlaskConical, RotateCcw, X, Sparkles, ArrowUp, ArrowDown,
    ChevronRight, Loader2, AlertTriangle,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { faixaBonusCls, faixaBonusLabel, marketplaceLabel } from '@/lib/desempenhoLabels';
import {
    INDICADORES,
    PONTO_MAX,
    PONTO_MIN,
    parsePonto,
    resumoFaixas,
    simularRanking,
} from '@/lib/simuladorDesempenho';

// ═══════════════════════════════════════════════════════════════════════
// Modo simulador do ranking de /performance (2026-08-31).
//
// Substitui a tabela do ranking por uma versão editável: os pontos de NPS,
// Faturamento e Margem de cada profissional viram campo, e a nota final, a
// faixa de bônus e a POSIÇÃO no ranking se refazem a cada tecla.
//
// NADA É SALVO. Não há request, não há rota de gravação, nada toca
// `desempenho_score_snapshots` nem `bonus_faixas` — sair do modo descarta a
// simulação inteira. O aviso no topo existe para que ninguém confunda a tela
// com o ranking oficial num print de tela.
//
// Toda a conta mora em `@/lib/simuladorDesempenho` (travada por
// `tests/js/simuladorDesempenho.test.js`), justamente para não virar lógica
// solta dentro do JSX: o projeto não tem harness de render de React, e
// cálculo escondido em componente só seria conferível no olho.
// ═══════════════════════════════════════════════════════════════════════

const GRID = 'grid grid-cols-[3.25rem_minmax(0,1fr)_7rem_8.5rem_5rem_5.25rem_5.25rem_5.25rem_2.25rem] gap-2 px-5';

// Sub-tabela por empresa — alinhada à direita para ler como um recuo do
// colaborador, com as 3 colunas de ponto na mesma largura da tabela de cima.
const GRID_EMPRESA = 'grid grid-cols-[1.75rem_minmax(0,1fr)_5rem_5.25rem_5.25rem_5.25rem_2.25rem] gap-2 pl-12 pr-5';

// Formata ponto/nota em pt-BR com 2 casas fixas — "4,03", nunca "4" nem "4,1".
// Mesma régua de exibição do ranking oficial (`formatNota` do Index.jsx):
// duplicada aqui de propósito para o simulador não importar do Index e criar
// ciclo de import (Index → Simulador → Index).
function fmt(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toFixed(2).replace('.', ',');
}

// Conta que gerou a nota: "(4,80+4,54+4,10)/3". Ausente aparece como 0,00
// porque é assim que ele entra na soma — o divisor é fixo em 3.
function contaNota(pontos) {
    return `(${INDICADORES.map((k) => fmt(pontos?.[k] ?? 0)).join('+')})/3`;
}

export default function SimuladorRanking({
    ranking = [],
    faixas = [],
    onSair,
    mesLabel = null,
    mes = null,              // 'YYYY-MM' — competência aberta; null no mês corrente
    mesEmCurso = false,
}) {
    // `edicoes` guarda VALOR parseado por profissional; `textos` guarda o que
    // está literalmente escrito no campo. Os dois são necessários: digitar
    // "4," é estado válido do input e não pode ser normalizado no meio da
    // digitação, senão o cursor pula e não dá para escrever "4,5".
    const [edicoes, setEdicoes] = useState({});
    const [textos, setTextos] = useState({});

    // ── Nível empresa (simulador detalhado) ──────────────────────────────
    // `carteiras` é o que veio do servidor (snapshot congelado, read-only);
    // `edicoesEmpresa` e `excluidas` são o que o admin mexeu por cima.
    const [carteiras, setCarteiras] = useState(null);   // null = nunca carregado
    const [carregando, setCarregando] = useState(false);
    const [erroCarga, setErroCarga] = useState(null);
    const [expandidos, setExpandidos] = useState({});
    const [edicoesEmpresa, setEdicoesEmpresa] = useState({});
    const [excluidas, setExcluidas] = useState({});

    // Carrega a carteira de TODO MUNDO de uma vez, na primeira expansão —
    // uma query no servidor (`paraUsuarios`) em vez de N. Quem só usa o
    // simulador simples nunca paga esse custo.
    const carregarCarteiras = () => {
        // Mês em curso não tem snapshot por empresa — a expansão mostra o
        // aviso e não gasta requisição para receber um objeto vazio.
        if (mesEmCurso || carteiras !== null || carregando) return;
        setCarregando(true);
        setErroCarga(null);

        fetch(route('performance.simulador.empresas') + (mes ? `?mes=${mes}` : ''), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
            .then((data) => setCarteiras(data?.empresas_por_user ?? {}))
            .catch((e) => setErroCarga(e.message ?? 'falha ao carregar'))
            .finally(() => setCarregando(false));
    };

    const alternarExpansao = (id) => {
        carregarCarteiras();
        setExpandidos((e) => ({ ...e, [id]: !e[id] }));
    };

    // Formato que `simularRanking` espera: só entram profissionais cuja
    // carteira JÁ chegou do servidor.
    const carteirasParaSim = useMemo(() => {
        if (!carteiras) return {};
        const out = {};
        for (const [uid, empresas] of Object.entries(carteiras)) {
            out[uid] = {
                empresas,
                edicoes: edicoesEmpresa[uid] ?? {},
                excluidas: excluidas[uid] ?? [],
            };
        }
        return out;
    }, [carteiras, edicoesEmpresa, excluidas]);

    const linhas = useMemo(
        () => simularRanking(ranking, edicoes, faixas, carteirasParaSim),
        [ranking, edicoes, faixas, carteirasParaSim],
    );
    const resumo = useMemo(() => resumoFaixas(linhas, faixas), [linhas, faixas]);

    const qtdEditadas = linhas.filter((l) => l.editada).length;
    const qtdMudouFaixa = linhas.filter((l) => l.faixa_mudou).length;

    const setPonto = (id, campo, texto) => {
        setTextos((t) => ({ ...t, [`${id}:${campo}`]: texto }));
        setEdicoes((e) => ({ ...e, [id]: { ...(e[id] ?? {}), [campo]: parsePonto(texto) } }));
    };

    // Ao sair do campo o rascunho é descartado e a célula volta a exibir o
    // valor formatado (o número continua vivo em `edicoes`).
    const normalizar = (id, campo) => {
        setTextos((t) => {
            const out = { ...t };
            delete out[`${id}:${campo}`];
            return out;
        });
    };

    // ↑/↓ ajustam de 0,10 em 0,10 (0,50 com Shift) — simular é ficar
    // empurrando o ponto para cima e para baixo, e fazer isso digitando o
    // número inteiro toda vez é lento.
    const aoTeclar = (e, linha, campo) => {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        e.preventDefault();
        const passo = (e.shiftKey ? 0.5 : 0.1) * (e.key === 'ArrowUp' ? 1 : -1);
        const atual = linha.pontos_simulados?.[campo] ?? 0;
        const novo = Math.min(PONTO_MAX, Math.max(PONTO_MIN, Number(atual) + passo));
        setPonto(linha.id, campo, novo.toFixed(2).replace('.', ','));
    };

    // ── Edição no nível empresa ──────────────────────────────────────────
    // Chave de texto tem 3 partes (`user:empresa:campo`) para não colidir com
    // a do nível carteira, que tem 2.
    const setPontoEmpresa = (userId, companyId, campo, texto) => {
        setTextos((t) => ({ ...t, [`${userId}:${companyId}:${campo}`]: texto }));
        setEdicoesEmpresa((e) => ({
            ...e,
            [userId]: {
                ...(e[userId] ?? {}),
                [companyId]: { ...((e[userId] ?? {})[companyId] ?? {}), [campo]: parsePonto(texto) },
            },
        }));
    };

    const normalizarEmpresa = (userId, companyId, campo) => {
        setTextos((t) => {
            const out = { ...t };
            delete out[`${userId}:${companyId}:${campo}`];
            return out;
        });
    };

    const alternarInclusao = (userId, companyId) => {
        setExcluidas((e) => {
            const atual = e[userId] ?? [];
            const fora = atual.includes(companyId);
            return { ...e, [userId]: fora ? atual.filter((c) => c !== companyId) : [...atual, companyId] };
        });
    };

    const resetLinha = (id) => {
        setEdicoes((e) => {
            const out = { ...e };
            delete out[id];
            return out;
        });
        setEdicoesEmpresa((e) => {
            const out = { ...e };
            delete out[id];
            return out;
        });
        setExcluidas((e) => {
            const out = { ...e };
            delete out[id];
            return out;
        });
        // Limpa tanto `user:campo` quanto `user:empresa:campo`.
        setTextos((t) => Object.fromEntries(
            Object.entries(t).filter(([chave]) => !chave.startsWith(`${id}:`)),
        ));
    };

    const restaurarTudo = () => {
        setEdicoes({});
        setTextos({});
        setEdicoesEmpresa({});
        setExcluidas({});
    };

    return (
        <div className="space-y-4">
            {/* Aviso permanente — a tela precisa se identificar como simulação
                mesmo num print recortado. */}
            <div className="rounded-2xl border border-ecf-yellow/30 bg-ecf-yellow/[0.06] px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2.5 min-w-0">
                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-ecf-yellow/15 text-ecf-yellow shrink-0">
                        <FlaskConical size={15} />
                    </span>
                    <div className="min-w-0">
                        <p className="text-ecf-yellow text-[13px] font-semibold leading-tight">
                            Modo simulação{mesLabel ? ` · ${mesLabel}` : ''} — nada é salvo
                        </p>
                        <p className="text-white/50 text-[11.5px] leading-tight mt-0.5">
                            Edite os pontos para ver a nota final, a faixa de bônus e a posição que sairiam.
                            Sair descarta tudo; o ranking oficial não muda.
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    {qtdEditadas > 0 && (
                        <span className="text-white/50 text-[11.5px] tabular-nums">
                            {qtdEditadas} {qtdEditadas === 1 ? 'linha editada' : 'linhas editadas'}
                            {qtdMudouFaixa > 0 && ` · ${qtdMudouFaixa} mudou de faixa`}
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={restaurarTudo}
                        disabled={qtdEditadas === 0}
                        title="Voltar todos os pontos aos valores reais"
                        className={cn(
                            'flex items-center gap-1.5 h-8 px-3 rounded-lg border text-[12px] font-semibold transition-colors',
                            qtdEditadas === 0
                                ? 'border-white/[0.06] text-white/25 cursor-not-allowed'
                                : 'border-white/15 text-white/70 hover:text-white hover:bg-white/[0.05]',
                        )}
                    >
                        <RotateCcw size={13} />
                        Restaurar
                    </button>
                    <button
                        type="button"
                        onClick={onSair}
                        className="flex items-center gap-1.5 h-8 px-3 rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow text-[12px] font-semibold hover:bg-ecf-yellow/15 transition-colors"
                    >
                        <X size={13} />
                        Sair do simulador
                    </button>
                </div>
            </div>

            {/* Tabela editável */}
            <div className="rounded-2xl border border-white/[0.06] bg-ecf-card overflow-hidden">
                <div className={cn(GRID, 'py-3 border-b border-white/[0.06] text-white/40 text-[11px] font-semibold uppercase tracking-wide')}>
                    <span title="Posição simulada — a seta mostra o movimento em relação ao ranking real.">#</span>
                    <span>Nome</span>
                    <span className="text-right" title="Nota simulada: (NPS + Faturamento + Margem) / 3.">Nota sim.</span>
                    <span title="Faixa de bônus resultante da nota simulada, pela régua ativa.">Faixa sim.</span>
                    <span className="text-right" title="Diferença entre a nota simulada e a nota real.">Δ nota</span>
                    <span className="text-right" title="Pontos de NPS (0 a 5). Campo vazio = a carteira não tem o indicador; ele soma 0 e o divisor continua 3.">NPS</span>
                    <span className="text-right" title="Pontos de faturamento (0 a 5).">Faturamento</span>
                    <span className="text-right" title="Pontos de margem (0 a 5). Carteira só-Shopee não tem margem — a plataforma não fornece CMV.">Margem</span>
                    <span />
                </div>

                <div className="divide-y divide-white/[0.04]">
                    {linhas.map((l) => (
                      <div key={l.id}>
                        <div
                            className={cn(
                                GRID,
                                'py-2.5 items-center transition-colors',
                                l.editada && 'bg-ecf-yellow/[0.035]',
                            )}
                        >
                            {/* Posição simulada + movimento vs ranking real */}
                            <div className="flex items-center gap-1">
                                <span className="text-white/50 font-semibold text-[12px] tabular-nums w-5 text-right">
                                    {l.posicao_simulada}
                                </span>
                                {l.delta_posicao > 0 && (
                                    <span
                                        className="inline-flex items-center text-emerald-300 text-[10px] font-bold tabular-nums"
                                        title={`Subiu ${l.delta_posicao} ${l.delta_posicao === 1 ? 'posição' : 'posições'} (era ${l.posicao_original}º)`}
                                    >
                                        <ArrowUp size={10} />{l.delta_posicao}
                                    </span>
                                )}
                                {l.delta_posicao < 0 && (
                                    <span
                                        className="inline-flex items-center text-rose-300 text-[10px] font-bold tabular-nums"
                                        title={`Caiu ${Math.abs(l.delta_posicao)} ${Math.abs(l.delta_posicao) === 1 ? 'posição' : 'posições'} (era ${l.posicao_original}º)`}
                                    >
                                        <ArrowDown size={10} />{Math.abs(l.delta_posicao)}
                                    </span>
                                )}
                            </div>

                            {/* Nome + cargo, com o gatilho do detalhe por empresa */}
                            <div className="min-w-0 flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => alternarExpansao(l.id)}
                                    disabled={l.calculando}
                                    title={
                                        expandidos[l.id]
                                            ? 'Fechar as empresas deste profissional'
                                            : 'Abrir as empresas e editar o ponto loja a loja'
                                    }
                                    className={cn(
                                        'grid h-5 w-5 shrink-0 place-items-center rounded transition-colors',
                                        l.calculando
                                            ? 'text-white/15 cursor-not-allowed'
                                            : 'text-white/35 hover:text-white hover:bg-white/[0.06]',
                                    )}
                                >
                                    <ChevronRight
                                        size={13}
                                        className={cn('transition-transform', expandidos[l.id] && 'rotate-90')}
                                    />
                                </button>
                                <div className="min-w-0">
                                    <p className="text-white font-semibold text-[13px] truncate">{l.name}</p>
                                    <p className="text-white/40 text-[11px]">
                                        {l.cargo_label ?? '—'}
                                        {l.derivado_das_lojas && (
                                            <span className="text-ecf-yellow/70"> · nota vem das lojas</span>
                                        )}
                                    </p>
                                </div>
                            </div>

                            {/* Nota simulada + a conta que a gerou */}
                            <div className="text-right">
                                {l.calculando ? (
                                    <span className="text-white/40 text-[11px] animate-pulse" title="Nota ainda sendo calculada em segundo plano — sem pontos para simular.">
                                        calculando…
                                    </span>
                                ) : (
                                    <>
                                        <span className={cn(
                                            'font-display font-extrabold text-[16px] tabular-nums',
                                            l.editada ? 'text-ecf-yellow' : 'text-white',
                                        )}>
                                            {fmt(l.nota_simulada)}
                                        </span>
                                        <span
                                            className="text-white/30 text-[10px] block leading-none mt-0.5 tabular-nums"
                                            title="Soma dos três indicadores dividida por 3. Indicador ausente entra como 0,00 — o divisor é fixo."
                                        >
                                            {contaNota(l.pontos_simulados)}
                                        </span>
                                    </>
                                )}
                            </div>

                            {/* Faixa simulada — destaca quando mudou em relação à real */}
                            <div className="flex items-center gap-1.5 flex-wrap">
                                <span
                                    className={cn(
                                        'inline-flex items-center text-[10px] font-semibold px-2 py-0.5 rounded-full border',
                                        faixaBonusCls(l.faixa_simulada),
                                        l.faixa_mudou && 'ring-1 ring-ecf-yellow/50',
                                    )}
                                    title={
                                        l.faixa_mudou
                                            ? `Faixa real nesta competência: ${faixaBonusLabel(l.faixa_bonus)}`
                                            : 'Mesma faixa do ranking real'
                                    }
                                >
                                    {faixaBonusLabel(l.faixa_simulada)}
                                </span>
                                {l.faixa_promovida_simulada && (
                                    <span
                                        className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full border bg-emerald-500/15 text-emerald-300 border-emerald-500/30"
                                        title="Promovida para Máximo pela regra dos 2 meses consecutivos em Intermediário (o mês anterior desta competência fechou nessa faixa)."
                                    >
                                        <Sparkles size={9} />
                                        PROMOVIDA
                                    </span>
                                )}
                            </div>

                            {/* Δ nota vs real */}
                            <div className="text-right">
                                <DeltaNota delta={l.delta_nota} />
                            </div>

                            {/* Os 3 pontos editáveis. Travados quando a carteira
                                foi mexida no detalhe: ali o número passa a ser
                                a média das lojas, e deixar os dois editáveis
                                daria dois donos ao mesmo valor. */}
                            {INDICADORES.map((campo) => (
                                <div key={campo} className="flex justify-end">
                                    <input
                                        type="text"
                                        inputMode="decimal"
                                        disabled={l.calculando || l.derivado_das_lojas}
                                        readOnly={l.derivado_das_lojas}
                                        value={
                                            textos[`${l.id}:${campo}`]
                                            ?? (l.pontos_simulados?.[campo] == null ? '' : fmt(l.pontos_simulados[campo]))
                                        }
                                        onChange={(e) => setPonto(l.id, campo, e.target.value)}
                                        onBlur={() => normalizar(l.id, campo)}
                                        onKeyDown={(e) => aoTeclar(e, l, campo)}
                                        onFocus={(e) => e.target.select()}
                                        placeholder="—"
                                        title={
                                            l.derivado_das_lojas
                                                ? 'Média das lojas — edite pelo detalhe por empresa (ou restaure a linha para voltar a editar aqui).'
                                                : 'Ponto de 0 a 5. ↑/↓ ajustam de 0,10 (0,50 com Shift). Campo vazio = indicador ausente, entra como 0,00 na nota.'
                                        }
                                        className={cn(
                                            'w-full max-w-[4.75rem] h-8 px-2 rounded-lg border text-right text-[12.5px] font-semibold tabular-nums transition-colors',
                                            'focus:outline-none focus:ring-1 focus:ring-ecf-yellow/50 focus:border-ecf-yellow/50',
                                            l.calculando && 'opacity-40 cursor-not-allowed',
                                            l.derivado_das_lojas
                                                ? 'border-dashed border-ecf-yellow/30 bg-ecf-yellow/[0.04] text-ecf-yellow/80 cursor-not-allowed'
                                                : (edicoes[l.id] ?? {})[campo] !== undefined
                                                    ? 'border-ecf-yellow/40 bg-ecf-yellow/[0.08] text-ecf-yellow'
                                                    : 'border-white/[0.1] bg-white/[0.03] text-white/80',
                                        )}
                                    />
                                </div>
                            ))}

                            {/* Reset da linha */}
                            <div className="flex items-center justify-end">
                                {l.editada && (
                                    <button
                                        type="button"
                                        onClick={() => resetLinha(l.id)}
                                        title="Voltar esta linha aos pontos reais (inclusive as edições por empresa)"
                                        className="grid h-6 w-6 place-items-center rounded-md text-white/30 hover:text-white hover:bg-white/[0.06] transition-colors"
                                    >
                                        <RotateCcw size={12} />
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Detalhe por empresa */}
                        {expandidos[l.id] && (
                            <DetalheEmpresas
                                linha={l}
                                carregando={carregando}
                                erro={erroCarga}
                                mesEmCurso={mesEmCurso}
                                textos={textos}
                                edicoesEmpresa={edicoesEmpresa[l.id] ?? {}}
                                onPonto={(companyId, campo, texto) => setPontoEmpresa(l.id, companyId, campo, texto)}
                                onBlurPonto={(companyId, campo) => normalizarEmpresa(l.id, companyId, campo)}
                                onAlternarInclusao={(companyId) => alternarInclusao(l.id, companyId)}
                            />
                        )}
                      </div>
                    ))}
                </div>
            </div>

            {/* Distribuição de faixas — antes x depois. É o número que
                interessa a quem simula custo de bonificação. */}
            <div className="rounded-2xl border border-white/[0.06] bg-white/[0.015] p-4">
                <p className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-3">
                    Distribuição de faixas · real → simulado
                </p>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    {resumo.map((f) => (
                        <div
                            key={f.slug}
                            className={cn('rounded-xl border px-3 py-2.5', faixaBonusCls(f.slug))}
                        >
                            <p className="text-[11px] font-semibold opacity-80">{f.nome}</p>
                            <p className="flex items-baseline gap-1.5 mt-1">
                                <span className="text-[15px] font-display font-extrabold tabular-nums opacity-50">{f.antes}</span>
                                <span className="text-[11px] opacity-40">→</span>
                                <span className="text-[19px] font-display font-extrabold tabular-nums">{f.depois}</span>
                                {f.delta !== 0 && (
                                    <span className={cn(
                                        'text-[11px] font-bold tabular-nums',
                                        f.delta > 0 ? 'text-emerald-300' : 'text-rose-300',
                                    )}>
                                        {f.delta > 0 ? '+' : ''}{f.delta}
                                    </span>
                                )}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// DetalheEmpresas — a carteira do profissional, loja a loja.
//
// A régua de 1 a 5 já foi aplicada empresa por empresa no fechamento; aqui
// se edita o resultado dela e a média sobe para o colaborador. O rodapé
// mostra as três médias e a nota que elas produzem — é a conta inteira
// visível, sem passo escondido.
//
// A lista vem do SNAPSHOT da competência, então só existe em mês fechado.
// No mês em curso a seção diz isso em vez de prometer cálculo que não vem.
// ═══════════════════════════════════════════════════════════════════════
function DetalheEmpresas({
    linha,
    carregando,
    erro,
    mesEmCurso,
    textos,
    edicoesEmpresa,
    onPonto,
    onBlurPonto,
    onAlternarInclusao,
}) {
    const carteira = linha.carteira;

    const aviso = (texto, Icone = AlertTriangle, girando = false) => (
        <div className="pl-12 pr-5 py-3 bg-black/20 border-t border-white/[0.04] flex items-center gap-2 text-white/45 text-[12px]">
            <Icone size={13} className={cn('shrink-0 text-white/30', girando && 'animate-spin')} />
            {texto}
        </div>
    );

    // Mês em curso vem antes do estado de carga: ali nem se tenta carregar.
    if (mesEmCurso) {
        return aviso('O detalhe por empresa só existe depois que o mês fecha — este é o mês em curso. Escolha uma competência fechada no seletor acima.');
    }
    if (carregando) {
        return aviso('Carregando as empresas…', Loader2, true);
    }
    if (erro) {
        return aviso(`Não foi possível carregar as empresas (${erro}).`);
    }
    if (!carteira || carteira.linhas.length === 0) {
        return aviso('Sem detalhe por empresa nesta competência para este profissional.');
    }

    return (
        <div className="bg-black/20 border-t border-white/[0.04] py-2">
            <div className={cn(GRID_EMPRESA, 'py-1.5 text-white/30 text-[10px] font-semibold uppercase tracking-wide')}>
                <span title="Desmarque para tirar a loja da conta — simula uma invalidação de bônus, sem gravar nada.">✓</span>
                <span>Empresa</span>
                <span className="text-right" title="Nota da loja: média dos componentes esperados. Vazia quando falta componente.">Nota</span>
                <span className="text-right">NPS</span>
                <span className="text-right">Faturamento</span>
                <span className="text-right">Margem</span>
                <span />
            </div>

            <div className="space-y-0.5">
                {carteira.linhas.map((e) => (
                    <div
                        key={e.company_id}
                        className={cn(
                            GRID_EMPRESA,
                            'py-1.5 items-center rounded-lg transition-colors',
                            !e.incluida && 'opacity-40',
                            e.editada && e.incluida && 'bg-ecf-yellow/[0.04]',
                        )}
                    >
                        {/* Inclusão na conta */}
                        <div className="flex items-center">
                            <input
                                type="checkbox"
                                checked={e.incluida}
                                onChange={() => onAlternarInclusao(e.company_id)}
                                title={
                                    e.incluida
                                        ? 'Na conta. Desmarque para simular esta loja fora da competência.'
                                        : 'Fora da conta — não entra em nenhuma das médias.'
                                }
                                className="h-3.5 w-3.5 rounded border-white/20 bg-white/[0.06] text-ecf-yellow focus:ring-1 focus:ring-ecf-yellow/50 cursor-pointer"
                            />
                        </div>

                        {/* Nome + marketplace + status */}
                        <div className="min-w-0">
                            <p className={cn(
                                'text-[12px] truncate',
                                e.incluida ? 'text-white/85' : 'text-white/50 line-through',
                            )}>
                                {e.company_name ?? `Empresa ${e.company_id}`}
                            </p>
                            <p className="text-white/30 text-[10px] flex items-center gap-1.5">
                                <span>{marketplaceLabel(e.fonte_financeira)}</span>
                                {e.status_simulado !== 'complete' && (
                                    <span
                                        className="text-amber-300/60"
                                        title={
                                            e.status_simulado === 'sem_dados'
                                                ? 'Sem nenhum componente — não entra em média nenhuma.'
                                                : `Faltam componentes: ${e.componentes_presentes} de ${e.componentes_esperados}. A loja segue contando nos indicadores que tem.`
                                        }
                                    >
                                        · {e.componentes_presentes}/{e.componentes_esperados}
                                    </span>
                                )}
                            </p>
                        </div>

                        {/* Nota da loja (estrita) */}
                        <div className="text-right">
                            <span className={cn(
                                'text-[12px] font-semibold tabular-nums',
                                e.nota_empresa_simulada == null ? 'text-white/20' : 'text-white/70',
                            )}>
                                {e.nota_empresa_simulada == null ? '—' : fmt(e.nota_empresa_simulada)}
                            </span>
                        </div>

                        {/* Os 3 pontos da loja */}
                        {INDICADORES.map((campo) => {
                            const chave = `${linha.id}:${e.company_id}:${campo}`;
                            const editado = (edicoesEmpresa[e.company_id] ?? {})[campo] !== undefined;
                            return (
                                <div key={campo} className="flex justify-end">
                                    <input
                                        type="text"
                                        inputMode="decimal"
                                        disabled={!e.incluida}
                                        value={
                                            textos[chave]
                                            ?? (e.pontos_simulados?.[campo] == null ? '' : fmt(e.pontos_simulados[campo]))
                                        }
                                        onChange={(ev) => onPonto(e.company_id, campo, ev.target.value)}
                                        onBlur={() => onBlurPonto(e.company_id, campo)}
                                        onFocus={(ev) => ev.target.select()}
                                        placeholder="—"
                                        title="Ponto desta loja, de 0 a 5. Vazio = a loja não tem o indicador; ela sai da média DELE e continua nas outras."
                                        className={cn(
                                            'w-full max-w-[4.75rem] h-7 px-2 rounded-md border text-right text-[12px] font-semibold tabular-nums transition-colors',
                                            'focus:outline-none focus:ring-1 focus:ring-ecf-yellow/50 focus:border-ecf-yellow/50',
                                            !e.incluida && 'cursor-not-allowed',
                                            editado
                                                ? 'border-ecf-yellow/40 bg-ecf-yellow/[0.08] text-ecf-yellow'
                                                : 'border-white/[0.08] bg-white/[0.02] text-white/70',
                                        )}
                                    />
                                </div>
                            );
                        })}
                        <div />
                    </div>
                ))}
            </div>

            {/* Rodapé: as médias que sobem para o colaborador */}
            <div className={cn(
                GRID_EMPRESA,
                'py-2 mt-1 items-center border-t border-white/[0.06] text-[11px]',
            )}>
                <span />
                <span className="text-white/45">
                    Média das {carteira.qtd_incluidas} {carteira.qtd_incluidas === 1 ? 'loja' : 'lojas'} na conta
                    {carteira.qtd_excluidas > 0 && (
                        <span className="text-amber-300/60"> · {carteira.qtd_excluidas} fora</span>
                    )}
                </span>
                <span className="text-right text-ecf-yellow font-display font-extrabold text-[13px] tabular-nums">
                    {fmt(carteira.nota)}
                </span>
                {INDICADORES.map((campo) => (
                    <span key={campo} className="text-right text-white/70 font-semibold tabular-nums">
                        {carteira.medias?.[campo] == null ? '—' : fmt(carteira.medias[campo])}
                    </span>
                ))}
                <span />
            </div>

            <p className="pl-12 pr-5 pt-1 text-white/25 text-[10.5px] leading-snug">
                Cada indicador tem denominador próprio: loja sem margem continua contando no faturamento e no NPS.
                A nota do profissional é a soma das três médias dividida por 3 — indicador que a carteira inteira não tem entra como 0,00.
            </p>
        </div>
    );
}

// Diferença entre nota simulada e nota real. `null` quando não há uma das
// duas (linha em cálculo, carteira sem nota oficial).
function DeltaNota({ delta }) {
    if (delta == null || Number.isNaN(Number(delta))) {
        return <span className="text-white/20 font-bold tabular-nums text-[11px]">—</span>;
    }
    const n = Number(delta);
    if (Math.abs(n) < 0.005) {
        return <span className="text-white/25 font-semibold tabular-nums text-[11px]">0,00</span>;
    }
    return (
        <span className={cn(
            'font-semibold tabular-nums text-[11px]',
            n > 0 ? 'text-emerald-300' : 'text-rose-300',
        )}>
            {n > 0 ? '+' : '−'}{Math.abs(n).toFixed(2).replace('.', ',')}
        </span>
    );
}
