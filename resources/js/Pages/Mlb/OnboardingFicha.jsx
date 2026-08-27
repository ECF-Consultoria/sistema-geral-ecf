import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Pencil, X, ExternalLink, Settings2 } from 'lucide-react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';

// ═══ CONSTANTES DE FALLBACK (usadas quando opcoes não chegam via props) ═══
const ONB_POLO_OPCOES     = ['Arapongas', 'S. J. Rio Preto', 'Bento Gonçalves', 'São Bento do Sul'];
const ONB_FASE_OPCOES     = ['Encaminhar Comercial', 'Aceite no Projeto', 'M0', 'M1', 'M2', 'M3', 'M4', 'Encerrado', 'Protocolo Churn', 'Churn'];
const ONB_ACESSO_COLABORADOR_OPCOES = ['Com acesso', 'Sem acesso'];
const ONB_PLANILHA_PRODUTOS_OPCOES  = ['Já enviado', 'Não enviado'];
const ONB_LISTAGEM_OPCOES           = ['Não', 'Pronto para listar', 'Já listado', 'Falta informação'];
const ONB_PUBLICACAO_OPCOES         = ['Não iniciado', 'Concluído', 'Estágio 2', 'Suspensa'];
const ONB_DECOLA_OPCOES             = ['Sim', 'Não', 'Mensagem Enviada'];
const ONB_CENTRAL_PROMOCAO_OPCOES   = ['Sim', 'Não'];
const ONB_ME1_OPCOES = [
    'Sem itens ainda', 'Não é necessário', 'Ativo', 'Em contratação', 'Precisa de ME1',
    'Aguardando contato', 'Conversando com cliente', 'Pendente com integradora',
    'Preenchendo tabela', 'Verificando',
];
const ONB_INTEGRADORA_OPCOES = ['Nenhuma', 'Frenet', 'Sisfrete', 'Intelispost', 'Frete Gestão', 'Em contratação', 'Any'];
const ONB_PLACES_OPCOES = [
    'Ativo', 'Solicitado', 'Falta emissor fiscal', 'Falta certificado A1',
    'Falta endereço fiscal', 'Checklist realizado', 'Realizando checklist', 'Não',
];
const ONB_ERP_OPCOES = [
    'Sem informação', 'Bling', 'Tiny', 'Magazord', 'Anymarket',
    'Olist', 'Tray', 'WeNext', 'Não utiliza', 'Em contratação',
];

// Sentinela para a opção "—" dos Selects: o Radix proíbe <SelectItem value="">
// (lança erro em runtime e derruba o render → tela preta). Usamos um valor não-vazio
// e mapeamos de volta para '' antes de enviar ao backend.
const SEM_VALOR = '__none__';
const limparSemValor = (obj) =>
    Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, v === SEM_VALOR ? '' : v]));

// ─── Paleta de status semântica ───
function corStatus(valor) {
    if (!valor) return 'text-white/30 bg-white/[0.04]';
    const positivos = ['Com acesso', 'Já enviado', 'Já listado', 'Concluído', 'Sim', 'Ativo', 'Checklist realizado'];
    const emProgresso = ['Pronto para listar', 'Estágio 2', 'Em contratação', 'Realizando checklist', 'Solicitado',
        'Precisa de ME1', 'Aguardando contato', 'Conversando com cliente', 'Pendente com integradora',
        'Preenchendo tabela', 'Verificando', 'Mensagem Enviada'];
    const negativos = ['Sem acesso', 'Banida', 'Protocolo Churn', 'Churn', 'Encerrado', 'Não', 'Falta informação',
        'Falta emissor fiscal', 'Falta certificado A1', 'Falta endereço fiscal'];
    const fasesAtivas = ['M1', 'M2', 'M3', 'M4'];
    if (positivos.includes(valor)) return 'text-emerald-300 bg-emerald-500/10';
    if (emProgresso.includes(valor)) return 'text-amber-300 bg-amber-500/10';
    if (negativos.includes(valor)) return 'text-red-300 bg-red-500/10';
    if (fasesAtivas.includes(valor)) return 'text-blue-300 bg-blue-500/10';
    if (valor === 'M0') return 'text-violet-300 bg-violet-500/10';
    return 'text-white/50 bg-white/[0.04]';
}

// ─── Badge de status reutilizável ───
function StatusBadge({ valor }) {
    if (!valor) return <span className="text-white/30 text-[11px]">—</span>;
    return (
        <span className={cn('inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium', corStatus(valor))}>
            {valor}
        </span>
    );
}

// ─── Barra de progresso (compatível com impl.progresso do controller) ───
function ProgressBar({ pct, feitos, total }) {
    const color = pct === 100 ? '#22c55e' : pct >= 60 ? '#eab308' : '#6366f1';
    return (
        <div className="flex items-center gap-2">
            <div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden">
                <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full transition-all" />
            </div>
            <span className="text-white/40 text-[11px] shrink-0">{feitos}/{total}</span>
        </div>
    );
}

// ─── Toggle booleano Sim/Não ───
function ToggleSimNao({ value, onChange }) {
    return (
        <div className="flex gap-2">
            {[true, false].map(v => (
                <button
                    key={String(v)}
                    type="button"
                    onClick={() => onChange(v)}
                    className={cn(
                        'px-4 py-2 rounded-lg text-[13px] font-medium transition-colors',
                        value === v
                            ? 'bg-ecf-yellow text-[#252525]'
                            : 'bg-white/[0.04] border border-white/[0.08] text-white/50 hover:text-white'
                    )}
                >
                    {v ? 'Sim' : 'Não'}
                </button>
            ))}
        </div>
    );
}

// ─── Label de campo (padrão uppercase do projeto) ───
function FieldLabel({ children }) {
    return (
        <label className="text-white/50 text-[11px] font-semibold uppercase tracking-wide block mb-1">
            {children}
        </label>
    );
}

// ─── Badge de reputação ML — classes Tailwind HARDCODED ──────────────────────
// NÃO interpolar bg-${nivel} — o JIT do Tailwind purga classes dinâmicas
// (Pitfall confirmado Phase 25 D-11). green e light_green mapeiam para verde.
const REP_NIVEL = {
    green:       { label: 'Verde',   bg: 'bg-emerald-500/15', text: 'text-emerald-300', border: 'border-emerald-500/30' },
    light_green: { label: 'Verde',   bg: 'bg-emerald-500/15', text: 'text-emerald-300', border: 'border-emerald-500/30' },
    yellow:      { label: 'Âmbar',   bg: 'bg-amber-500/15',   text: 'text-amber-300',   border: 'border-amber-500/30' },
    red:         { label: 'Vermelho', bg: 'bg-red-500/15',     text: 'text-red-300',     border: 'border-red-500/30' },
};

// ─── Badge de medalha ML — classes Tailwind HARDCODED ────────────────────────
// Cópia literal da Phase 25 (EmpresaHeader.jsx). NÃO interpolar bg-${nivel}-500/15
// — o JIT do Tailwind purga classes dinâmicas (Pitfall confirmado Phase 25 D-11).
const MEDALHA_BADGE = {
    PLATINUM: { label: 'Platinum', bg: 'bg-cyan-500/15',   text: 'text-cyan-300',   border: 'border-cyan-500/30' },
    GOLD:     { label: 'Gold',     bg: 'bg-yellow-500/15', text: 'text-yellow-300', border: 'border-yellow-500/30' },
    SILVER:   { label: 'Silver',   bg: 'bg-slate-500/15',  text: 'text-slate-300',  border: 'border-slate-500/30' },
    BRONZE:   { label: 'Bronze',   bg: 'bg-amber-700/15',  text: 'text-amber-400',  border: 'border-amber-700/30' },
};

// Resolve o badge da medalha. O ECF Drive manda o nível em `nivelSolucion` (ex.: PLATINUM,
// GOLD, mas também ONBOARDING, FARMING LEGADO...). Conhecidos usam a cor do MEDALHA_BADGE;
// os demais caem num badge neutro exibindo o próprio nível (não some).
function badgeMedalha(nivel) {
    if (!nivel) return null;
    const known = MEDALHA_BADGE[nivel] ?? MEDALHA_BADGE[String(nivel).toUpperCase()];
    if (known) return known;
    return { label: nivel, bg: 'bg-white/[0.06]', text: 'text-white/70', border: 'border-white/[0.12]' };
}

// Formata valor BRL de forma compacta (ex: R$ 18,5mil) — padrão fmtBRL do projeto
const fmtBRL = (n) => n == null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact' }).format(n);

// Formata número inteiro em pt-BR (ex: 1.234)
const fmtInt = (n) => n == null ? '—' : new Intl.NumberFormat('pt-BR').format(n);

// Calcula tendência comparando o último valor não-nulo com o anterior não-nulo.
// Retorna { seta, cor, valor } ou null quando há menos de 2 pontos válidos.
// Ignorar nulos é essencial: o mês corrente costuma vir com gmv/visitas null.
function calcTendencia(serie, campo) {
    if (!serie || serie.length === 0) return null;
    // Filtra apenas entradas com valor não-nulo para o campo solicitado
    const validos = serie.filter(m => m[campo] != null).map(m => m[campo]);
    if (validos.length < 2) return null;
    const ultimo   = validos[validos.length - 1];
    const anterior = validos[validos.length - 2];
    if (ultimo > anterior) return { seta: '↑', cor: 'text-emerald-400', valor: ultimo };
    if (ultimo < anterior) return { seta: '↓', cor: 'text-red-400',     valor: ultimo };
    return { seta: '→', cor: 'text-white/50', valor: ultimo };
}

// ─── Card assíncrono "Dados do ML" (Phase 36) ────────────────────────────────
// Carregado via useEffect após a ficha montar — NÃO bloqueia abertura da ficha.
// custId = empresa.cust_id (coluna direta de mlb_empresas, não accessor Company).
function CardDadosMl({ implId, custId }) {
    const [dados, setDados] = useState(null);  // null = loading, objeto = carregado
    const [erro, setErro]   = useState(null);

    useEffect(() => {
        // Se empresa não tem Cust ID, mostra estado especial sem fazer fetch
        if (!custId) {
            setDados({ semCustId: true });
            return;
        }
        fetch(route('mlb.implementacao.dados-ml', implId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => r.json())
            .then(setDados)
            .catch(() => setErro('Erro de conexão.'));
    }, [implId]);

    // ── Estado: carregando ──
    if (!dados && !erro) {
        return (
            <div className="card-ecf rounded-2xl p-6 mt-6">
                <h2 className="text-white text-[13px] font-semibold mb-3">Dados do ML</h2>
                <p className="text-white/40 text-[12px]">Carregando dados do ML…</p>
            </div>
        );
    }

    // ── Estado: erro de conexão ou erro retornado pelo backend ──
    if (erro || dados?.erro) {
        const mensagem = dados?.erro ?? erro;
        return (
            <div className="card-ecf rounded-2xl p-6 mt-6">
                <h2 className="text-white text-[13px] font-semibold mb-3">Dados do ML</h2>
                <p className="text-amber-300 text-[12px]">{mensagem}</p>
                <button
                    type="button"
                    onClick={() => { setDados(null); setErro(null); fetch(route('mlb.implementacao.dados-ml', implId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.json()).then(setDados).catch(() => setErro('Erro de conexão.')); }}
                    className="mt-2 text-white/40 hover:text-white text-[12px] underline transition-colors"
                >
                    Recarregar
                </button>
            </div>
        );
    }

    // ── Estado: empresa sem Cust ID cadastrado ──
    if (dados?.semCustId) {
        return (
            <div className="card-ecf rounded-2xl p-6 mt-6">
                <h2 className="text-white text-[13px] font-semibold mb-3">Dados do ML</h2>
                <p className="text-white/40 text-[12px]">
                    Empresa sem Cust ID do ML. Cadastre o Cust ID para visualizar os dados do seller.
                </p>
            </div>
        );
    }

    // ── Estado: seller não encontrado no ECF Drive ──
    if (dados?.naoEncontrada) {
        return (
            <div className="card-ecf rounded-2xl p-6 mt-6">
                <h2 className="text-white text-[13px] font-semibold mb-3">Dados do ML</h2>
                <p className="text-white/40 text-[12px]">
                    Seller não encontrado no ML (Cust ID <span className="font-mono text-white/60">{custId}</span>).
                </p>
            </div>
        );
    }

    // ── Estado: seller existe mas ainda sem meses no programa POLOS ──
    if (dados?.anunciosAtivos == null && dados?.gmvPolos == null) {
        const medalhaConfig = badgeMedalha(dados?.medalha);
        return (
            <div className="card-ecf rounded-2xl p-6 mt-6">
                <h2 className="text-white text-[13px] font-semibold mb-3">Dados do ML</h2>
                {medalhaConfig ? (
                    <span className={cn(
                        'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-semibold border mb-3',
                        medalhaConfig.bg, medalhaConfig.text, medalhaConfig.border,
                    )}>
                        {medalhaConfig.label} · {dados.programa ?? 'POLOS'}
                    </span>
                ) : null}
                <p className="text-white/40 text-[12px]">Sem dados POLOS ainda.</p>
            </div>
        );
    }

    // ── Estado: sucesso — exibe medalha, anúncios, GMV POLOS, canais e novos blocos ──
    const medalhaConfig = dados?.medalha ? (MEDALHA_BADGE[dados.medalha] ?? null) : null;
    const canais = dados?.canais ?? {};

    // ── Bloco Reputação (ONB-15) ──
    const rep = dados?.reputacao ?? null;
    const repConfig = rep?.nivel ? (REP_NIVEL[rep.nivel] ?? {
        label: rep.nivel, bg: 'bg-white/[0.06]', text: 'text-white/70', border: 'border-white/[0.12]',
    }) : null;
    // Taxas com problema (alerta=true): listar quais são > 0 para exibir no chip
    const taxasComProblema = rep?.alerta && rep?.taxas
        ? Object.entries(rep.taxas)
            .filter(([, v]) => parseFloat(v) > 0)
            .map(([k]) => ({ claims: 'Reclamações', disputas: 'Disputas', cancelamentos: 'Cancelamentos', atraso: 'Atraso' }[k] ?? k))
        : [];

    // ── Bloco Maturidade (ONB-16) ──
    const mat = dados?.maturidade ?? null;
    const matCluster = mat
        ? [mat.cluster, mat.subCluster].filter(Boolean).join(' · ') || null
        : null;

    // ── Bloco Tendência (ONB-17) ──
    const serie = dados?.serie ?? [];
    const tendGmv     = calcTendencia(serie, 'gmv');
    const tendVisitas = calcTendencia(serie, 'visitas');

    return (
        <div className="card-ecf rounded-2xl p-6 mt-6">
            <h2 className="text-white text-[13px] font-semibold mb-4">Dados do ML</h2>

            {/* ─── Medalha + programa ─── */}
            <div className="mb-4">
                {medalhaConfig ? (
                    <span className={cn(
                        'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-semibold border',
                        medalhaConfig.bg, medalhaConfig.text, medalhaConfig.border,
                    )}>
                        {medalhaConfig.label} · {dados.programa ?? 'POLOS'}
                    </span>
                ) : (
                    <span className="text-white/30 text-[11px]">Sem medalha cadastrada</span>
                )}
            </div>

            {/* ─── Métricas: anúncios ativos + GMV POLOS ─── */}
            <div className="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-1">Anúncios Ativos</p>
                    <p className="text-white text-[18px] font-semibold">
                        {dados.anunciosAtivos != null
                            ? new Intl.NumberFormat('pt-BR').format(dados.anunciosAtivos)
                            : '—'
                        }
                    </p>
                </div>
                <div>
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-1">
                        GMV POLOS
                        {dados.mesRef && (
                            <span className="text-white/30 font-normal normal-case tracking-normal ml-1">
                                ref. {dados.mesRef}
                            </span>
                        )}
                    </p>
                    <p className="text-white text-[18px] font-semibold">{fmtBRL(dados.gmvPolos)}</p>
                </div>
            </div>

            {/* ─── Ativação por canal: ME2 / Places / Flex ─── */}
            <div>
                <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Canais</p>
                <div className="flex flex-wrap gap-2">
                    {[
                        { key: 'me2',    label: 'ME2' },
                        { key: 'places', label: 'Places' },
                        { key: 'flex',   label: 'Flex' },
                    ].map(({ key, label }) => {
                        const canal = canais[key] ?? { ativo: false, gmv: 0 };
                        return (
                            <div
                                key={key}
                                className={cn(
                                    'flex items-center gap-2 px-3 py-1.5 rounded-lg border text-[12px]',
                                    canal.ativo
                                        ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300'
                                        : 'bg-white/[0.03] border-white/[0.08] text-white/30',
                                )}
                            >
                                <span className={cn('w-1.5 h-1.5 rounded-full shrink-0', canal.ativo ? 'bg-emerald-400' : 'bg-white/20')} />
                                <span className="font-medium">{label}</span>
                                {canal.ativo && (
                                    <span className="text-emerald-400/70">{fmtBRL(canal.gmv)}</span>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* ─── Sub-bloco REPUTAÇÃO (ONB-15) ─────────────────────────────────────── */}
            {/* Oculta-se quando dados.reputacao for null — degradação graciosa (M0 etc.) */}
            {rep && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Reputação</p>
                    {repConfig ? (
                        <span className={cn(
                            'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-semibold border',
                            repConfig.bg, repConfig.text, repConfig.border,
                        )}>
                            {repConfig.label}
                        </span>
                    ) : (
                        <span className="text-white/30 text-[11px]">—</span>
                    )}
                    {/* Chip de alerta: exibido apenas quando alguma taxa de problema é > 0 */}
                    {rep.alerta && taxasComProblema.length > 0 && (
                        <div className="mt-2 flex items-start gap-1.5 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/20">
                            <span className="text-amber-400 text-[12px] shrink-0">⚠</span>
                            <p className="text-amber-300 text-[11px] leading-snug">
                                Taxas com problema: {taxasComProblema.join(', ')}
                            </p>
                        </div>
                    )}
                </div>
            )}

            {/* ─── Sub-bloco MATURIDADE (ONB-16) ───────────────────────────────────── */}
            {/* Oculta-se quando dados.maturidade for null */}
            {mat && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Maturidade</p>
                    <p className="text-white text-[13px] font-medium">
                        {mat.meses === 1 ? '1 mês no programa' : `${mat.meses} meses no programa`}
                    </p>
                    {matCluster && (
                        <p className="text-white/50 text-[12px] mt-0.5">{matCluster}</p>
                    )}
                </div>
            )}

            {/* ─── Sub-bloco TENDÊNCIA 3 MESES (ONB-17) ────────────────────────────── */}
            {/* Oculta-se quando a série não tem pontos suficientes para comparar.      */}
            {/* calcTendencia() ignora meses com valor null (mês corrente em curso).    */}
            {(tendGmv || tendVisitas) && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Tendência</p>
                    <div className="space-y-1.5">
                        {tendGmv && (
                            <div className="flex items-center gap-2 text-[12px]">
                                <span className="text-white/40 w-12 shrink-0">GMV</span>
                                <span className={cn('font-semibold text-[14px]', tendGmv.cor)}>{tendGmv.seta}</span>
                                <span className="text-white/70">{fmtBRL(tendGmv.valor)}</span>
                            </div>
                        )}
                        {tendVisitas && (
                            <div className="flex items-center gap-2 text-[12px]">
                                <span className="text-white/40 w-12 shrink-0">Visitas</span>
                                <span className={cn('font-semibold text-[14px]', tendVisitas.cor)}>{tendVisitas.seta}</span>
                                <span className="text-white/70">{fmtInt(tendVisitas.valor)}</span>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ─── Sub-bloco VENDAS & FORECAST ──────────────────────────────────────── */}
            {/* Some quando itens E forecastGmv forem ambos null (seller ONBOARDING).   */}
            {dados?.vendas && (dados.vendas.itens != null || dados.vendas.forecastGmv != null) && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Vendas &amp; Forecast</p>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Itens Vendidos</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.vendas.itens != null ? fmtInt(dados.vendas.itens) : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Forecast GMV</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.vendas.forecastGmv != null ? fmtBRL(dados.vendas.forecastGmv) : '—'}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* ─── Sub-bloco TRÁFEGO ────────────────────────────────────────────────── */}
            {/* Some quando visitas E clips forem ambos null.                           */}
            {dados?.trafego && (dados.trafego.visitas != null || dados.trafego.clips != null) && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Tráfego</p>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Visitas</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.trafego.visitas != null ? fmtInt(dados.trafego.visitas) : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Clips</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.trafego.clips != null ? fmtInt(dados.trafego.clips) : '—'}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* ─── Sub-bloco CONTEXTO ───────────────────────────────────────────────── */}
            {/* Some quando estado E subPolo E atendimento forem todos null.            */}
            {dados?.contexto && (dados.contexto.estado != null || dados.contexto.subPolo != null || dados.contexto.atendimento != null) && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Contexto</p>
                    <div className="flex flex-wrap gap-2">
                        {dados.contexto.estado && (
                            <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-medium bg-blue-500/10 text-blue-300 border border-blue-500/20">
                                {dados.contexto.estado}
                            </span>
                        )}
                        {dados.contexto.subPolo && (
                            <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-medium bg-violet-500/10 text-violet-300 border border-violet-500/20">
                                {dados.contexto.subPolo}
                            </span>
                        )}
                        {dados.contexto.atendimento && (
                            <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-medium bg-white/[0.06] text-white/60 border border-white/[0.10]">
                                {dados.contexto.atendimento}
                            </span>
                        )}
                    </div>
                </div>
            )}

            {/* ─── Sub-bloco ANÚNCIOS & QUALIDADE ──────────────────────────────────── */}
            {/* Oculta inteiro quando novosListadores, itensFull e scoreQualidade são   */}
            {/* todos null — caso típico sellers ONBOARDING (ainda sem dados de ML).    */}
            {dados?.qualidade && (
                dados.qualidade.novosListadores != null ||
                dados.qualidade.itensFull       != null ||
                dados.qualidade.scoreQualidade  != null
            ) && (
                <div className="mt-4 pt-4 border-t border-white/[0.06]">
                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-2">Anúncios &amp; Qualidade</p>
                    <div className="grid grid-cols-3 gap-4">
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Novos Listadores</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.qualidade.novosListadores != null ? fmtInt(dados.qualidade.novosListadores) : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Itens Full</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.qualidade.itensFull != null ? fmtInt(dados.qualidade.itensFull) : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-white/40 text-[11px] mb-0.5">Score Qualidade</p>
                            <p className="text-white text-[15px] font-semibold">
                                {dados.qualidade.scoreQualidade != null
                                    ? new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(dados.qualidade.scoreQualidade)
                                    : '—'
                                }
                            </p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ═══ MODAL ACESSOS & SETUP ═══
function ModalAcessos({ impl, opcoes, onClose }) {
    const [form, setForm] = useState({
        acesso_colaborador: impl.acesso_colaborador ?? '',
        gmail_colaborador:  impl.gmail_colaborador  ?? '',
        grupo_whatsapp:     impl.grupo_whatsapp     ?? null,
    });
    const [saving, setSaving] = useState(false);

    const poloOpts = opcoes?.acesso_colaborador ?? ONB_ACESSO_COLABORADOR_OPCOES;

    function submit(e) {
        e.preventDefault();
        setSaving(true);
        router.patch(route('mlb.implementacao.bloco.acessos', impl.id), limparSemValor(form), {
            preserveScroll: true,
            onSuccess: () => { setSaving(false); onClose(); },
            onError:   () => setSaving(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" onClick={onClose}>
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6" onClick={e => e.stopPropagation()}>
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <h2 className="text-white font-semibold text-base">Acessos &amp; Setup</h2>
                    <button
                        type="button"
                        aria-label="Fechar"
                        onClick={onClose}
                        className="p-2 rounded-lg text-white/40 hover:text-white transition-colors"
                    >
                        <X size={16} />
                    </button>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <FieldLabel>Acesso Colaborador</FieldLabel>
                        <Select value={form.acesso_colaborador} onValueChange={v => setForm(f => ({ ...f, acesso_colaborador: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {poloOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Gmail Colaborador</FieldLabel>
                        <input
                            type="email"
                            value={form.gmail_colaborador}
                            onChange={e => setForm(f => ({ ...f, gmail_colaborador: e.target.value }))}
                            placeholder="colaborador@gmail.com"
                            className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                        />
                    </div>

                    <div>
                        <FieldLabel>Grupo WhatsApp criado?</FieldLabel>
                        <ToggleSimNao
                            value={form.grupo_whatsapp}
                            onChange={v => setForm(f => ({ ...f, grupo_whatsapp: v }))}
                        />
                    </div>

                    {/* Footer */}
                    <div className="flex items-center justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">
                            Descartar
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                        >
                            {saving ? 'Salvando Acessos…' : 'Salvar Acessos'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ═══ MODAL PRODUTOS & PUBLICAÇÃO ═══
function ModalProdutos({ impl, opcoes, onClose }) {
    const [form, setForm] = useState({
        planilha_produtos: impl.planilha_produtos ?? '',
        listagem:          impl.listagem          ?? '',
        publicacao:        impl.publicacao        ?? '',
        decola:            impl.decola            ?? '',
        central_promocao:  impl.central_promocao  ?? '',
    });
    const [saving, setSaving] = useState(false);

    const planilhaOpts  = opcoes?.planilha_produtos ?? ONB_PLANILHA_PRODUTOS_OPCOES;
    const listagemOpts  = opcoes?.listagem          ?? ONB_LISTAGEM_OPCOES;
    const publicacaoOpts = opcoes?.publicacao       ?? ONB_PUBLICACAO_OPCOES;
    const decolaOpts    = opcoes?.decola            ?? ONB_DECOLA_OPCOES;
    const centralOpts   = opcoes?.central_promocao  ?? ONB_CENTRAL_PROMOCAO_OPCOES;

    function submit(e) {
        e.preventDefault();
        setSaving(true);
        router.patch(route('mlb.implementacao.bloco.produtos', impl.id), limparSemValor(form), {
            preserveScroll: true,
            onSuccess: () => { setSaving(false); onClose(); },
            onError:   () => setSaving(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" onClick={onClose}>
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between mb-6">
                    <h2 className="text-white font-semibold text-base">Produtos &amp; Publicação</h2>
                    <button
                        type="button"
                        aria-label="Fechar"
                        onClick={onClose}
                        className="p-2 rounded-lg text-white/40 hover:text-white transition-colors"
                    >
                        <X size={16} />
                    </button>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <FieldLabel>Planilha de Produtos</FieldLabel>
                        <Select value={form.planilha_produtos} onValueChange={v => setForm(f => ({ ...f, planilha_produtos: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {planilhaOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Listagem</FieldLabel>
                        <Select value={form.listagem} onValueChange={v => setForm(f => ({ ...f, listagem: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {listagemOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Publicação</FieldLabel>
                        <Select value={form.publicacao} onValueChange={v => setForm(f => ({ ...f, publicacao: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {publicacaoOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Decola</FieldLabel>
                        <Select value={form.decola} onValueChange={v => setForm(f => ({ ...f, decola: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {decolaOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Central de Promoção</FieldLabel>
                        <Select value={form.central_promocao} onValueChange={v => setForm(f => ({ ...f, central_promocao: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {centralOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">
                            Descartar
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                        >
                            {saving ? 'Salvando Produtos…' : 'Salvar Produtos'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ═══ MODAL LOGÍSTICA & INTEGRAÇÕES ═══
function ModalLogistica({ impl, opcoes, onClose }) {
    const [form, setForm] = useState({
        contextos_logistica: impl.contextos_logistica ?? '',
        me1:                 impl.me1                ?? '',
        integradora:         impl.integradora        ?? '',
        places:              impl.places             ?? '',
        erp:                 impl.erp                ?? '',
    });
    const [saving, setSaving] = useState(false);

    const me1Opts          = opcoes?.me1         ?? ONB_ME1_OPCOES;
    const integradoraOpts  = opcoes?.integradora ?? ONB_INTEGRADORA_OPCOES;
    const placesOpts       = opcoes?.places      ?? ONB_PLACES_OPCOES;
    const erpOpts          = opcoes?.erp         ?? ONB_ERP_OPCOES;

    function submit(e) {
        e.preventDefault();
        setSaving(true);
        router.patch(route('mlb.implementacao.bloco.logistica', impl.id), limparSemValor(form), {
            preserveScroll: true,
            onSuccess: () => { setSaving(false); onClose(); },
            onError:   () => setSaving(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" onClick={onClose}>
            <div className="card-ecf rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6" onClick={e => e.stopPropagation()}>
                <div className="flex items-center justify-between mb-6">
                    <h2 className="text-white font-semibold text-base">Logística &amp; Integrações</h2>
                    <button
                        type="button"
                        aria-label="Fechar"
                        onClick={onClose}
                        className="p-2 rounded-lg text-white/40 hover:text-white transition-colors"
                    >
                        <X size={16} />
                    </button>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <FieldLabel>Contextos Logística</FieldLabel>
                        <textarea
                            value={form.contextos_logistica}
                            onChange={e => setForm(f => ({ ...f, contextos_logistica: e.target.value }))}
                            placeholder="Descreva os contextos de logística..."
                            className="w-full px-3 py-2.5 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 resize-none min-h-[80px]"
                        />
                    </div>

                    <div>
                        <FieldLabel>ME1</FieldLabel>
                        <Select value={form.me1} onValueChange={v => setForm(f => ({ ...f, me1: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {me1Opts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Integradora</FieldLabel>
                        <Select value={form.integradora} onValueChange={v => setForm(f => ({ ...f, integradora: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {integradoraOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>Places</FieldLabel>
                        <Select value={form.places} onValueChange={v => setForm(f => ({ ...f, places: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {placesOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <FieldLabel>ERP</FieldLabel>
                        <Select value={form.erp} onValueChange={v => setForm(f => ({ ...f, erp: v }))}>
                            <SelectTrigger className="w-full h-10 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>—</SelectItem>
                                {erpOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-[13px] text-white/50 hover:text-white transition-colors">
                            Descartar
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="px-4 py-2 rounded-lg bg-ecf-yellow text-[#252525] font-semibold text-[13px] hover:brightness-110 transition-all disabled:opacity-50"
                        >
                            {saving ? 'Salvando Logística…' : 'Salvar Logística'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ═══ PÁGINA PRINCIPAL — FICHA DE ONBOARDING ═══
export default function OnboardingFicha({ impl, empresa, opcoes }) {
    // Estado do modal aberto: null | 'acessos' | 'produtos' | 'logistica'
    const [modalAberto, setModalAberto] = useState(null);

    // Estado do bloco de Identificação (edição inline)
    const [idForm, setIdForm] = useState({
        polo:             empresa.polo             ?? '',
        fase:             empresa.fase             ?? '',
        data_solicitacao: impl.data_solicitacao    ?? '',
    });
    const [savingId, setSavingId] = useState(false);

    const poloOpts = opcoes?.polo ?? ONB_POLO_OPCOES;
    const faseOpts = opcoes?.fase ?? ONB_FASE_OPCOES;

    // Salva o bloco de Identificação ao mudar qualquer campo
    function salvarIdentificacao(novoForm) {
        setSavingId(true);
        router.patch(route('mlb.implementacao.bloco.identificacao', impl.id), limparSemValor(novoForm), {
            preserveScroll: true,
            onFinish: () => setSavingId(false),
        });
    }

    function onChangeId(campo, valor) {
        const novoForm = { ...idForm, [campo]: valor };
        setIdForm(novoForm);
        salvarIdentificacao(novoForm);
    }

    // Progresso do checklist
    const progresso = impl.progresso ?? { pct: 0, feitos: 0, total: 0 };
    // Usa o helper Ziggy (não caminho hardcoded) para respeitar a base URL do app
    const workspaceUrl = impl.token ? route('implementacao.workspace', impl.token) : null;

    return (
        <AppLayout title={`Onboarding — ${empresa.nome}`}>
            <div className="max-w-5xl mx-auto px-6 py-8">

                {/* Breadcrumb / Voltar */}
                <div className="mb-6">
                    <Link
                        href={route('mlb.implementacao.index')}
                        className="inline-flex items-center gap-2 text-white/40 hover:text-white text-[13px] transition-colors"
                    >
                        <ArrowLeft size={14} />
                        Onboarding
                    </Link>
                </div>

                {/* ─── CABEÇALHO DE IDENTIFICAÇÃO ─── */}
                <div className="card-ecf rounded-2xl p-6 mb-8">
                    <div className="flex flex-col gap-4">
                        {/* Linha 1: Loja + Cust ID */}
                        <div className="flex items-center gap-3 flex-wrap">
                            <h1 className="text-white font-semibold text-lg leading-tight">{empresa.nome}</h1>
                            {empresa.cust_id ? (
                                <span className="text-[11px] font-mono bg-white/[0.04] border border-white/[0.08] px-2 py-0.5 rounded text-white/60">
                                    {empresa.cust_id}
                                </span>
                            ) : (
                                <span className="text-white/30 text-[11px]">sem Cust ID</span>
                            )}
                            {savingId && (
                                <span className="text-white/30 text-[11px] italic">Salvando Identificação…</span>
                            )}
                        </div>

                        {/* Linha 2: Polo, Fase, Data de Solicitação */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label className="text-white/50 text-[11px] font-semibold uppercase tracking-wide block mb-1">Polos</label>
                                <Select
                                    value={idForm.polo}
                                    onValueChange={v => onChangeId('polo', v)}
                                >
                                    <SelectTrigger className="w-full h-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                        <SelectValue placeholder="—" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={SEM_VALOR}>—</SelectItem>
                                        {poloOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="text-white/50 text-[11px] font-semibold uppercase tracking-wide block mb-1">Fase</label>
                                <Select
                                    value={idForm.fase}
                                    onValueChange={v => onChangeId('fase', v)}
                                >
                                    <SelectTrigger className="w-full h-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px]">
                                        <SelectValue placeholder="—" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={SEM_VALOR}>—</SelectItem>
                                        {faseOpts.map(o => <SelectItem key={o} value={o}>{o}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="text-white/50 text-[11px] font-semibold uppercase tracking-wide block mb-1">Data de Solicitação</label>
                                <input
                                    type="date"
                                    value={idForm.data_solicitacao}
                                    onChange={e => onChangeId('data_solicitacao', e.target.value)}
                                    className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                {/* ─── GRID DE 3 CARDS DE BLOCO ─── */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">

                    {/* Card 🟩 Acessos & Setup */}
                    <div className="card-ecf rounded-2xl p-6 relative">
                        <button
                            type="button"
                            onClick={() => setModalAberto('acessos')}
                            className="absolute top-4 right-4 flex items-center gap-1 text-white/30 hover:text-white text-[12px] transition-colors"
                        >
                            <Pencil size={12} />
                            Editar Acessos
                        </button>
                        <h2 className="text-white text-[13px] font-semibold mb-3">Acessos &amp; Setup</h2>
                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <StatusBadge valor={impl.acesso_colaborador} />
                            </div>
                            <div className="text-[12px] text-white/40 truncate">
                                {impl.gmail_colaborador
                                    ? <span className="text-white/60">{impl.gmail_colaborador}</span>
                                    : <span className="text-white/20">—</span>
                                }
                            </div>
                            <div className="flex items-center gap-1.5 text-[12px]">
                                <span className={cn('w-2 h-2 rounded-full shrink-0', impl.grupo_whatsapp ? 'bg-emerald-400' : 'bg-white/20')} />
                                <span className={impl.grupo_whatsapp ? 'text-emerald-300' : 'text-white/30'}>
                                    Grupo WhatsApp {impl.grupo_whatsapp ? 'criado' : 'não criado'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Card 🟨 Produtos & Publicação */}
                    <div className="card-ecf rounded-2xl p-6 relative">
                        <button
                            type="button"
                            onClick={() => setModalAberto('produtos')}
                            className="absolute top-4 right-4 flex items-center gap-1 text-white/30 hover:text-white text-[12px] transition-colors"
                        >
                            <Pencil size={12} />
                            Editar Produtos
                        </button>
                        <h2 className="text-white text-[13px] font-semibold mb-3">Produtos &amp; Publicação</h2>
                        <div className="space-y-2">
                            <div className="flex items-center gap-2 flex-wrap">
                                <StatusBadge valor={impl.listagem} />
                                <StatusBadge valor={impl.publicacao} />
                            </div>
                            <div className="text-[12px] text-white/40">
                                Planilha: {impl.planilha_produtos
                                    ? <span className="text-white/60">{impl.planilha_produtos}</span>
                                    : <span className="text-white/20">—</span>
                                }
                            </div>
                            <div>
                                <StatusBadge valor={impl.decola ?? null} />
                                {impl.decola !== null && impl.decola !== undefined && (
                                    <span className="text-white/30 text-[11px] ml-1">Decola</span>
                                )}
                            </div>
                            <div>
                                <StatusBadge valor={impl.central_promocao ?? null} />
                                {impl.central_promocao !== null && impl.central_promocao !== undefined && (
                                    <span className="text-white/30 text-[11px] ml-1">Central de Promoção</span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Card 🟧 Logística & Integrações */}
                    <div className="card-ecf rounded-2xl p-6 relative">
                        <button
                            type="button"
                            onClick={() => setModalAberto('logistica')}
                            className="absolute top-4 right-4 flex items-center gap-1 text-white/30 hover:text-white text-[12px] transition-colors"
                        >
                            <Pencil size={12} />
                            Editar Logística
                        </button>
                        <h2 className="text-white text-[13px] font-semibold mb-3">Logística &amp; Integrações</h2>
                        <div className="space-y-2">
                            <div className="flex items-center gap-2 flex-wrap">
                                <StatusBadge valor={impl.me1} />
                                <StatusBadge valor={impl.integradora} />
                            </div>
                            <div className="flex items-center gap-2 flex-wrap">
                                <StatusBadge valor={impl.places} />
                                <StatusBadge valor={impl.erp} />
                            </div>
                            {impl.contextos_logistica && (
                                <p className="text-white/40 text-[12px] truncate">{impl.contextos_logistica}</p>
                            )}
                        </div>
                    </div>
                </div>

                {/* ─── CARD DO CHECKLIST DE IMPLEMENTAÇÃO ─── */}
                <div className="card-ecf rounded-2xl p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-white text-[13px] font-semibold">Checklist de Implementação</h2>
                        {workspaceUrl && (
                            <a
                                href={workspaceUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="flex items-center gap-1.5 text-ecf-yellow text-[13px] hover:brightness-110 transition-all"
                            >
                                Abrir workspace →
                                <ExternalLink size={13} />
                            </a>
                        )}
                    </div>
                    <ProgressBar pct={progresso.pct} feitos={progresso.feitos} total={progresso.total} />
                    <p className="text-white/30 text-[12px] mt-2">
                        {progresso.feitos} de {progresso.total} itens concluídos
                    </p>
                </div>

                {/* ─── CARD DADOS DO ML (carrega assincronamente — Phase 36) ─── */}
                {/* Fetch ocorre após a ficha montar; abertura da ficha NÃO é bloqueada */}
                <CardDadosMl implId={impl.id} custId={empresa.cust_id} />

            </div>

            {/* ─── MODAIS ─── */}
            {modalAberto === 'acessos' && (
                <ModalAcessos impl={impl} opcoes={opcoes} onClose={() => setModalAberto(null)} />
            )}
            {modalAberto === 'produtos' && (
                <ModalProdutos impl={impl} opcoes={opcoes} onClose={() => setModalAberto(null)} />
            )}
            {modalAberto === 'logistica' && (
                <ModalLogistica impl={impl} opcoes={opcoes} onClose={() => setModalAberto(null)} />
            )}
        </AppLayout>
    );
}
