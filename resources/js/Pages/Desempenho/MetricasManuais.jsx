import { useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    SlidersHorizontal, Search, TriangleAlert, Check, X,
    Info, CheckCircle2, Building2, Pencil,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn, formatCurrency } from '@/lib/utils';
import { marketplaceLabel } from '@/lib/desempenhoLabels';

// ═══════════════════════════════════════════════════════════════════════════
// Lançamento manual de métricas financeiras — grade empresa × mês (Fase 136
// Plano 05 · D-01/D-02/D-07/D-09). Admin-only; a rota também é `role:admin`.
//
// Por que uma GRADE e não uma tela por empresa: o CMV chega em lote no
// fechamento do mês. Lançar empresa por empresa, uma tela de cada vez, não é
// o formato do trabalho real.
//
// O que esta tela NÃO faz, de propósito:
//  · não decide autorização — quem recusa é o servidor (`role:admin` na rota
//    + `StoreMetricaManualRequest::authorize()`);
//  · não trava a competência consolidada — desde 2026-08-31 NINGUÉM trava: o
//    read-only foi removido aqui, no FormRequest e no controller. O mês
//    consolidado só ganha um aviso amarelo, e segue editável;
//  · não exibe QUEM lançou. O autor existe no banco e no activity_log para
//    auditoria (D-12), nunca na tela (D-04) — as props nem carregam o dado.
// ═══════════════════════════════════════════════════════════════════════════

/** Rótulo de coluna por métrica. As chaves vêm da whitelist do backend. */
const METRICA_LABEL = {
    faturamento: 'Faturamento',
    margem_cmv:  'CMV',
};

/** Ajuda curta por métrica, no cabeçalho da coluna. */
const METRICA_AJUDA = {
    faturamento: 'Faturamento do mês cheio, em reais.',
    margem_cmv:  'Custo da mercadoria vendida no mês cheio, em reais. A margem % é derivada pelo sistema: (faturamento − CMV) ÷ faturamento.',
};

const AVISO_CONSOLIDADA =
    'Esta competência já passou pelo fechamento de bônus, e o que você lançar aqui MUDA a nota de quem atende a empresa — o recálculo roda em segundo plano e leva alguns instantes. Recarregue a tela de Desempenho para ver o valor novo. Todo lançamento fica registrado com autor e horário para auditoria.';

const TEXTO_API_FRIA = 'ainda não aquecido';

const TITULO_API_FRIA =
    'O valor da API não foi resolvido para esta célula agora — isso NÃO quer dizer que a empresa faturou zero. A consulta só acontece quando a janela já está em cache, para a grade não pendurar a resposta esperando a API.';

const TITULO_API_SEM_VALOR =
    'A API não devolve este número para esta empresa. É o caso da margem em loja Shopee: a Shopee não fornece CMV — que é exatamente a razão de o lançamento manual existir.';

const TITULO_DIVERGENCIA =
    'O valor da API diverge do que foi lançado à mão. O valor manual continua mandando na nota: o sistema nunca reverte sozinho. Voltar para automático é ato explícito do administrador (D-02).';

const TITULO_VALOR_PRESERVADO =
    'Último valor lançado à mão nesta célula. Ele foi preservado quando a métrica voltou para automático — religar a célula reaproveita este número.';

// ─── Conversão de texto ⇄ número ────────────────────────────────────────────
// O admin digita como quiser: "12345.67" ou "12.345,67". Havendo vírgula, ela
// é o separador decimal e o ponto é milhar.
function paraNumero(texto) {
    const limpo = String(texto ?? '').trim();
    if (limpo === '') return null;

    // O sinal é lido ANTES da limpeza e reaplicado depois: o filtro de
    // caracteres abaixo remove o '-', e sem isto "-12,5%" (queda de
    // faturamento, entrada perfeitamente válida no modo percentual) viraria
    // +12,5% — um crescimento inventado a partir de uma perda.
    const negativo = limpo.startsWith('-');

    const normalizado = limpo.includes(',')
        ? limpo.replace(/\./g, '').replace(',', '.')
        : limpo;

    const n = Number(normalizado.replace(/[^0-9.]/g, ''));
    if (!Number.isFinite(n)) return null;

    return negativo ? -n : n;
}

/** Número → texto editável (vírgula decimal, sem símbolo de moeda). */
function paraTextoEditavel(valor) {
    if (valor == null) return '';
    return String(Number(valor)).replace('.', ',');
}

const numeroOuNulo = (v) => (v == null || v === '' ? null : Number(v));

// ─── Modos de lançamento (espelho de DesempenhoMetricaManual::TIPOS) ────────
// Três andares diferentes do mesmo cálculo, e é por isso que são três modos e
// não três formatações: `valor` vira faturamento/CMV e o motor deriva a
// variação; `percentual` JÁ é a variação; `ponto` JÁ é a saída da régua.
const TIPO_VALOR      = 'valor';
const TIPO_PERCENTUAL = 'percentual';
const TIPO_PONTO      = 'ponto';

const MODOS = [
    { tipo: TIPO_VALOR,      chip: 'R$', rotulo: 'Valor cheio em reais' },
    { tipo: TIPO_PERCENTUAL, chip: '%',  rotulo: 'Quanto cresceu' },
    { tipo: TIPO_PONTO,      chip: 'pt', rotulo: 'O ponto direto, de 0 a 5' },
];

/** Ajuda longa por modo, exibida no input aberto. */
const AJUDA_MODO = {
    [TIPO_VALOR]: 'Valor do mês cheio, em reais.',
    [TIPO_PERCENTUAL]: {
        faturamento: 'Quanto o faturamento cresceu, em % (aceita negativo para queda). Ex: 12,5',
        margem_cmv:  'Quanto a margem cresceu, em PONTOS PERCENTUAIS (aceita negativo). Ex: 3,2 significa que a margem subiu 3,2 p.p.',
    },
    [TIPO_PONTO]: 'A nota desta loja neste indicador, de 0 a 5. Não passa pela régua — entra direto na média da carteira.',
};

const ajudaDoModo = (tipo, metrica) => {
    const ajuda = AJUDA_MODO[tipo];
    return typeof ajuda === 'string' ? ajuda : (ajuda?.[metrica] ?? '');
};

/** Formata o número conforme o modo em que a célula foi lançada. */
function formatarPorTipo(valor, tipo) {
    if (valor == null) return '—';

    if (tipo === TIPO_PONTO) {
        return `${Number(valor).toFixed(2).replace('.', ',')} pt`;
    }

    if (tipo === TIPO_PERCENTUAL) {
        const sinal = Number(valor) > 0 ? '+' : '';
        return `${sinal}${Number(valor).toFixed(2).replace('.', ',')}%`;
    }

    return formatCurrency(valor);
}

// O canal entra na chave: a mesma empresa pode aparecer em duas linhas (uma
// por marketplace), e sem a fonte as duas compartilhariam estado de edição,
// de erro e de "enviando" — mexer numa mexeria visualmente na outra.
const chaveCelula = (companyId, fonte, metrica) => `${companyId}:${fonte}:${metrica}`;

// ─── Célula de uma métrica de uma empresa ───────────────────────────────────
// Ciclo de edição idêntico ao do `CustIdCell` do Painel Polos (exibição →
// clique vira input → Enter salva, Escape cancela, blur salva), com os mesmos
// dois guards que evitam request inútil.
function CelulaMetrica({ metrica, celula, enviando, erros, onEnviar }) {
    const [editando, setEditando] = useState(false);
    const [texto, setTexto] = useState('');

    const ativo       = celula?.ativo === true;
    const valor       = numeroOuNulo(celula?.valor);
    const apiValor    = numeroOuNulo(celula?.api_valor);
    const apiAquecida = celula?.api_aquecida === true;
    const tipoSalvo   = celula?.tipo ?? TIPO_VALOR;

    // Modo em edição. Nasce do que está salvo, mas é estado próprio: trocar de
    // modo é uma decisão que precede o número, não uma consequência dele.
    const [modo, setModo] = useState(tipoSalvo);

    // A comparação com a API só existe em reais. O backend já devolve
    // `api_valor = null` fora do modo valor; a checagem aqui evita depender
    // disso para não exibir "divergente" comparando 4,5 pontos com R$ 30 mil.
    const divergente = ativo && tipoSalvo === TIPO_VALOR && valor != null
        && apiAquecida && apiValor != null && Math.abs(apiValor - valor) >= 0.01;

    const abrirEdicao = (novoModo = null) => {
        const modoAlvo = novoModo ?? tipoSalvo;
        setModo(modoAlvo);
        // Reaproveitar o número só faz sentido no MESMO modo — 4,5 pontos
        // reaproveitados como reais viram R$ 4,50, e R$ 30.000 reaproveitados
        // como ponto seriam recusados pelo teto de 5. Trocar de modo abre
        // vazio, de propósito.
        setTexto(modoAlvo === tipoSalvo ? paraTextoEditavel(valor) : '');
        setEditando(true);
    };

    const salvar = () => {
        setEditando(false);
        const novo = paraNumero(texto);

        if (novo == null) return; // campo em branco → nada a gravar
        // Só é "não mudou" quando o MODO também é o mesmo: o número 3 significa
        // coisas diferentes em cada modo, então 3 pt sobre 3 R$ é uma mudança.
        if (ativo && modo === tipoSalvo && valor != null && Math.abs(novo - valor) < 0.005) return;

        onEnviar({ ativo: true, valor: novo, tipo: modo });
    };

    const alternar = (paraManual) => {
        if (!paraManual) {
            if (!ativo) return;
            onEnviar({ ativo: false, valor: null, tipo: tipoSalvo });
            return;
        }

        if (ativo) return;
        // Religar aproveita o valor preservado; sem valor guardado, o backend
        // exige um número — então abrimos o input em vez de mandar vazio.
        if (valor != null) onEnviar({ ativo: true, valor, tipo: tipoSalvo });
        else abrirEdicao();
    };

    return (
        <td className="px-3 py-3 align-top">
            {editando ? (
                <div className="flex items-center gap-1">
                    <input
                        autoFocus
                        type="text"
                        inputMode="decimal"
                        value={texto}
                        onChange={(ev) => setTexto(ev.target.value)}
                        onBlur={salvar}
                        onKeyDown={(ev) => {
                            if (ev.key === 'Enter') salvar();
                            if (ev.key === 'Escape') setEditando(false);
                        }}
                        placeholder={modo === TIPO_PONTO ? '0,00 a 5,00' : '0,00'}
                        title={ajudaDoModo(modo, metrica)}
                        className="h-7 w-32 rounded-md border border-white/15 bg-white/[0.05] px-2 text-right font-mono text-xs tabular-nums text-white outline-none focus:border-ecf-yellow/40"
                    />
                    <span className="shrink-0 text-[10px] font-semibold text-white/40">
                        {MODOS.find((m) => m.tipo === modo)?.chip}
                    </span>
                    <button
                        type="button"
                        onMouseDown={(ev) => ev.preventDefault()}
                        onClick={salvar}
                        title="Salvar valor manual"
                        className="text-emerald-300 transition hover:text-emerald-200"
                    >
                        <Check size={13} />
                    </button>
                    <button
                        type="button"
                        onMouseDown={(ev) => ev.preventDefault()}
                        onClick={() => setEditando(false)}
                        title="Cancelar"
                        className="text-white/40 transition hover:text-white/70"
                    >
                        <X size={13} />
                    </button>
                </div>
            ) : (
                <div className="flex flex-col gap-0.5">
                    <button
                        type="button"
                        onClick={abrirEdicao}
                        title="Clique para lançar/editar o valor manual"
                        className={cn(
                            'group/val inline-flex items-center gap-1.5 rounded-md px-1 py-0.5 text-left tabular-nums transition hover:bg-white/[0.05]',
                            ativo ? 'font-semibold text-ecf-yellow' : 'text-white/70',
                        )}
                    >
                        <span>
                            {ativo && valor != null
                                ? formatarPorTipo(valor, tipoSalvo)
                                : (apiAquecida && apiValor != null ? formatCurrency(apiValor) : '—')}
                        </span>
                        {divergente && (
                            <TriangleAlert size={12} className="shrink-0 text-amber-300" aria-label="Valor da API diverge do manual" />
                        )}
                        <Pencil size={10} className="shrink-0 text-white/20 opacity-0 transition group-hover/val:opacity-100" />
                    </button>

                    {/* Contexto: de onde veio o número que está sendo exibido, e
                        o que a API diz. `api_aquecida = false` NUNCA vira zero. */}
                    <div className="text-[10px] leading-tight text-white/35">
                        {ativo ? (
                            // Fora do modo valor não existe comparação com a
                            // API: ela não devolve "o ponto desta loja", e o
                            // faturamento em reais embaixo de "4,50 pt" leria
                            // como divergência onde não há o que comparar.
                            tipoSalvo !== TIPO_VALOR
                                ? <span title={ajudaDoModo(tipoSalvo, metrica)}>
                                    {tipoSalvo === TIPO_PONTO ? 'ponto lançado à mão' : 'variação lançada à mão'}
                                </span>
                                : (apiAquecida
                                    ? (apiValor != null
                                        ? <span title={divergente ? TITULO_DIVERGENCIA : undefined}>API: {formatCurrency(apiValor)}</span>
                                        : <span title={TITULO_API_SEM_VALOR}>API sem valor para esta métrica</span>)
                                    : <span title={TITULO_API_FRIA}>API: {TEXTO_API_FRIA}</span>)
                        ) : (
                            <span>
                                automático
                                {valor != null && (
                                    <span className="text-white/25" title={TITULO_VALOR_PRESERVADO}>
                                        {' '}· último manual {formatarPorTipo(valor, tipoSalvo)}
                                    </span>
                                )}
                            </span>
                        )}
                    </div>
                </div>
            )}

            {/* Alternância auto/manual — independente por eixo (D-07). */}
            <div className="mt-1.5 inline-flex overflow-hidden rounded-md border border-white/[0.08]">
                <button
                    type="button"
                    onClick={() => alternar(false)}
                    disabled={enviando}
                    title="Usar o valor da API (automático)"
                    className={cn(
                        'px-1.5 py-0.5 text-[10px] font-medium transition disabled:opacity-40',
                        !ativo ? 'bg-white/[0.1] text-white/80' : 'text-white/35 hover:text-white/70',
                    )}
                >
                    auto
                </button>
                <button
                    type="button"
                    onClick={() => alternar(true)}
                    disabled={enviando}
                    title="Usar o valor lançado à mão nesta competência"
                    className={cn(
                        'px-1.5 py-0.5 text-[10px] font-medium transition disabled:opacity-40',
                        ativo ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'text-white/35 hover:text-white/70',
                    )}
                >
                    manual
                </button>
            </div>

            {/* Como o número desta célula deve ser lido. Clicar num modo abre o
                input já naquele modo — o valor não é reaproveitado entre modos
                porque 3 significa coisas diferentes em cada um. */}
            <div className="mt-1 inline-flex overflow-hidden rounded-md border border-white/[0.08]">
                {MODOS.map((m) => (
                    <button
                        key={m.tipo}
                        type="button"
                        onClick={() => abrirEdicao(m.tipo)}
                        disabled={enviando}
                        title={`${m.rotulo} — ${ajudaDoModo(m.tipo, metrica)}`}
                        className={cn(
                            'px-1.5 py-0.5 text-[10px] font-medium transition disabled:opacity-40',
                            (editando ? modo : tipoSalvo) === m.tipo && ativo
                                ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                : 'text-white/30 hover:text-white/70',
                        )}
                    >
                        {m.chip}
                    </button>
                ))}
            </div>

            {enviando && <div className="mt-1 text-[10px] text-white/30">salvando…</div>}

            {/* O erro do backend precisa aparecer NA TELA — empresa inativa ou
                canal que a empresa não atende ainda são recusados, e a recusa
                não pode passar despercebida. */}
            {Array.isArray(erros) && erros.length > 0 && (
                <div className="mt-1 max-w-[220px] text-[10px] leading-tight text-rose-300">
                    {erros.map((msg, i) => <div key={`${metrica}-erro-${i}`}>{msg}</div>)}
                </div>
            )}
        </td>
    );
}

export default function MetricasManuais({
    mes,
    mes_label,
    meses = [],
    consolidada = false,
    busca = '',
    empresas = [],
    metricas = [],
}) {
    const { props } = usePage();
    const flash = props?.flash;

    const [textoBusca, setTextoBusca] = useState(busca ?? '');
    const [enviandoChave, setEnviandoChave] = useState(null);
    const [errosPorCelula, setErrosPorCelula] = useState({});
    const [avisoGlobal, setAvisoGlobal] = useState(null);

    // Contas distintas por trás das linhas — ver a nota no cabeçalho.
    const totalEmpresasDistintas = useMemo(
        () => new Set(empresas.map((e) => e.company_id)).size,
        [empresas],
    );

    // Busca com debounce simples — o input devolve a prop `busca` para o texto
    // não se perder na volta do servidor.
    useEffect(() => {
        const atual = busca ?? '';
        if (textoBusca === atual) return undefined;

        const t = setTimeout(() => {
            router.get(
                route('desempenho.metricas-manuais.index'),
                { mes, busca: textoBusca },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 400);

        return () => clearTimeout(t);
    }, [textoBusca, busca, mes]);

    const trocarCompetencia = (valor) => {
        setErrosPorCelula({});
        setAvisoGlobal(null);
        router.get(
            route('desempenho.metricas-manuais.index'),
            { mes: valor, busca: textoBusca },
            { preserveState: true, preserveScroll: true },
        );
    };

    const enviarCelula = (empresa, metrica, { ativo, valor, tipo }) => {
        const chave = chaveCelula(empresa.company_id, empresa.fonte, metrica);
        setEnviandoChave(chave);
        setErrosPorCelula((atual) => ({ ...atual, [chave]: null }));

        router.post(
            route('desempenho.metricas-manuais.lancar'),
            {
                company_id:     empresa.company_id,
                // O canal DESTA linha. É o que separa o lançamento do time do
                // Mercado Livre do lançamento do time da Shopee na mesma conta.
                fonte:          empresa.fonte,
                mes_referencia: mes,
                metrica,
                // COMO o número deve ser lido. Vai sempre, inclusive na
                // reversão: sem ele o backend cairia no default `valor` e o
                // ponto preservado seria relido como reais ao religar.
                tipo:           tipo ?? TIPO_VALOR,
                valor:          ativo ? valor : null,
                ativo,
            },
            {
                preserveScroll: true,
                preserveState:  true,
                onError: (erros) => {
                    setErrosPorCelula((atual) => ({
                        ...atual,
                        [chave]: Object.values(erros).filter(Boolean),
                    }));
                    if (erros?.mes_referencia) setAvisoGlobal(erros.mes_referencia);
                },
                onSuccess: () => setAvisoGlobal(null),
                onFinish:  () => setEnviandoChave(null),
            },
        );
    };

    return (
        <AppLayout>
            <Head title="Métricas manuais — Desempenho" />

            <div className="mx-auto max-w-6xl px-4 py-6">
                {/* ─── Cabeçalho ─────────────────────────────────────────── */}
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-ecf-yellow">
                            <SlidersHorizontal className="h-5 w-5" />
                            <h1 className="text-xl font-bold text-white">Métricas manuais</h1>
                        </div>
                        <p className="mt-1 max-w-2xl text-sm text-white/50">
                            Lance faturamento e CMV à mão quando a API não entrega o número da competência — cada
                            eixo alterna entre automático e manual de forma independente, e o valor manual manda na
                            nota até alguém devolvê-lo para automático.
                        </p>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label className="text-[11px] uppercase tracking-wide text-white/40">Competência</label>
                        <select
                            value={mes}
                            onChange={(e) => trocarCompetencia(e.target.value)}
                            className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-2 text-sm text-white/90 focus:border-ecf-yellow/40 focus:outline-none"
                        >
                            {meses.map((m) => (
                                <option key={m.valor} value={m.valor}>
                                    {m.label}{m.consolidada ? ' · consolidada' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* ─── Flash de sucesso ──────────────────────────────────── */}
                {flash?.success && (
                    <div className="mb-4 flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">
                        <CheckCircle2 className="h-4 w-4 shrink-0" /> {flash.success}
                    </div>
                )}

                {/* ─── Recusa do servidor (competência congelada na corrida) ─ */}
                {avisoGlobal && (
                    <div className="mb-4 flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" /> <span>{avisoGlobal}</span>
                    </div>
                )}

                {/* ─── Aviso de competência já consolidada ───────────────────
                    Informativo apenas: desde 2026-08-31 a grade NÃO trava mais
                    o mês consolidado — o aviso existe para o admin saber que
                    está mexendo num mês que já passou pelo fechamento. */}
                {consolidada && (
                    <div className="mb-4 flex items-start gap-3 rounded-xl border border-amber-500/25 bg-amber-500/10 px-4 py-3">
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-300" />
                        <div>
                            <div className="text-sm font-semibold text-amber-200">
                                {mes_label} já foi consolidada — editar aqui altera a nota
                            </div>
                            <div className="mt-1 text-xs leading-relaxed text-amber-200/70">
                                {AVISO_CONSOLIDADA}
                            </div>
                        </div>
                    </div>
                )}

                {/* ─── Busca ─────────────────────────────────────────────── */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                        <input
                            type="text"
                            value={textoBusca}
                            onChange={(e) => setTextoBusca(e.target.value)}
                            placeholder="Buscar empresa…"
                            maxLength={100}
                            className="w-64 rounded-lg border border-white/[0.08] bg-ecf-card py-2 pl-8 pr-3 text-sm text-white/90 placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none"
                        />
                    </div>
                    <span className="text-xs text-white/40">
                        {/* `empresas` são LINHAS (empresa × canal). Contar o
                            array direto diria "46 empresas" onde há 26 — conta
                            atendida nos dois marketplaces rende duas linhas. */}
                        {totalEmpresasDistintas} empresa{totalEmpresasDistintas === 1 ? '' : 's'} em {mes_label}
                        {empresas.length !== totalEmpresasDistintas && (
                            <span className="text-white/25"> · {empresas.length} linhas por marketplace</span>
                        )}
                    </span>
                </div>

                {/* ─── Grade empresa × métrica ───────────────────────────── */}
                <div className="rounded-2xl border border-white/[0.08] bg-ecf-card">
                    {empresas.length === 0 ? (
                        <div className="flex items-start gap-3 px-4 py-10 sm:px-5">
                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-white/40" />
                            <div className="text-sm text-white/50">
                                Nenhuma empresa nesta competência
                                {textoBusca ? <> para a busca <strong className="text-white/70">“{textoBusca}”</strong></> : null}.
                                A grade lista apenas empresas ativas com vínculo em serviço de Performance ou Shopee —
                                as demais nunca produzem linha de Desempenho.
                            </div>
                        </div>
                    ) : (
                        <div className="overflow-x-auto p-4 sm:p-5">
                            <table className="w-full min-w-[720px] text-sm">
                                <thead>
                                    <tr className="border-b border-white/[0.08] text-left text-[10px] uppercase tracking-wider text-white/40">
                                        <th className="px-3 py-3 font-semibold">Empresa</th>
                                        {metricas.map((metrica) => (
                                            <th
                                                key={metrica}
                                                className="px-3 py-3 font-semibold"
                                                title={METRICA_AJUDA[metrica]}
                                            >
                                                {METRICA_LABEL[metrica] ?? metrica}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {empresas.map((empresa) => (
                                        <tr
                                            key={`${empresa.company_id}:${empresa.fonte}`}
                                            className="border-b border-white/[0.05] hover:bg-white/[0.02]"
                                        >
                                            <td className="px-3 py-3 align-top">
                                                <div className="flex items-start gap-2">
                                                    <Building2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-white/25" />
                                                    <div className="min-w-0">
                                                        <div className="max-w-[240px] truncate text-white/80">
                                                            {empresa.company_name}
                                                        </div>
                                                        {/* O canal DESTA linha, não a lista de canais da
                                                            empresa: cada linha lança num marketplace só. Em
                                                            conta atendida nos dois, o selo avisa que existe
                                                            outra linha — e que ela é de outro time. */}
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="text-[10px] font-medium tracking-wide text-ecf-yellow/70">
                                                                {empresa.fonte_label ?? marketplaceLabel(empresa.fonte) ?? 'sem fonte financeira'}
                                                            </span>
                                                            {empresa.multi_canal && (
                                                                <span
                                                                    title="Esta conta é atendida nos dois marketplaces, por profissionais diferentes. O valor lançado aqui vale só para este canal."
                                                                    className="rounded border border-white/10 px-1 py-px text-[9px] text-white/35"
                                                                >
                                                                    2 canais
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            {metricas.map((metrica) => {
                                                const chave = chaveCelula(empresa.company_id, empresa.fonte, metrica);
                                                return (
                                                    <CelulaMetrica
                                                        key={chave}
                                                        metrica={metrica}
                                                        celula={empresa[metrica]}
                                                        enviando={enviandoChave === chave}
                                                        erros={errosPorCelula[chave]}
                                                        onEnviar={(payload) => enviarCelula(empresa, metrica, payload)}
                                                    />
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
