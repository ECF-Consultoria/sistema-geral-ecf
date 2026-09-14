import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Area, AreaChart, CartesianGrid, ReferenceArea, ReferenceLine,
    ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { RefreshCw, TrendingDown, TrendingUp } from 'lucide-react';
import { cn } from '@/lib/utils';
import { rotaDoPortal } from '@/lib/rotasDoPortal';

const brl = (v) =>
    (v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });

const brlCheio = (v) =>
    (v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

const diaMes = (iso) => {
    const [, m, d] = (iso ?? '').split('-');

    return d && m ? `${d}/${m}` : '';
};

/**
 * Um número grande com rótulo. Três destes abrem o bloco.
 */
function Kpi({ rotulo, valor, detalhe, destaque = false }) {
    return (
        <div
            className={cn(
                'rounded-xl border px-4 py-3',
                destaque
                    ? 'border-ecf-yellow/25 bg-ecf-yellow/[0.06]'
                    : 'border-white/[0.08] bg-white/[0.02]',
            )}
        >
            <p className="text-white/40 text-[11px] uppercase tracking-wider">{rotulo}</p>
            <p className={cn(
                'font-display font-bold tabular-nums mt-0.5',
                destaque ? 'text-ecf-yellow text-[22px]' : 'text-white text-[20px]',
            )}>
                {valor}
            </p>
            {detalhe && <p className="text-white/35 text-[11px] mt-0.5">{detalhe}</p>}
        </div>
    );
}

/**
 * O balão do gráfico. O padrão do recharts mostra a chave crua da série e o
 * número sem formato — aqui ele diz a semana e o valor em real.
 */
function Balao({ active, payload }) {
    if (! active || ! payload?.length) return null;

    const s = payload[0].payload;

    return (
        <div className="rounded-lg border border-white/[0.12] bg-[#0b1220] px-3 py-2 shadow-xl">
            <p className="text-white/45 text-[11px]">
                {diaMes(s.inicio)} a {diaMes(s.fim)}
                {s.apos_ecf && <span className="text-ecf-yellow"> · com a ECF</span>}
            </p>
            <p className="text-white font-semibold text-[14px] tabular-nums mt-0.5">
                {brlCheio(s.faturamento)}
            </p>
            <p className="text-white/40 text-[11px]">
                {s.pedidos} {s.pedidos === 1 ? 'pedido' : 'pedidos'} · {s.itens} {s.itens === 1 ? 'item' : 'itens'}
            </p>
        </div>
    );
}

/**
 * Snapshot — faturamento do Mercado Livre nas últimas 13 semanas.
 *
 * O bloco se chamava "Fotografia da Conta" e virou "Snapshot" em 14/09, por
 * pedido do negócio. Só o RÓTULO mudou: componente, arquivo, tabela, rota e
 * prop seguem `fotografia*` — renomear tudo isso mexeria em backend, migration
 * e testes para ganhar nada na tela.
 *
 * ### O que o desenho tenta responder
 * Uma pergunta só: "a conta melhorou depois que a ECF entrou?". Por isso a
 * linha de corte é o elemento mais forte depois da própria curva, e o período
 * anterior à ECF fica visivelmente apagado — o olho separa os dois lados antes
 * de ler qualquer número.
 *
 * ### Por que média semanal, e não total
 * Os dois lados quase nunca têm o mesmo número de semanas. Comparar a soma de 3
 * com a soma de 10 diria qualquer coisa; a média por semana é a única
 * comparação honesta enquanto o "depois" ainda é curto — que é justamente o
 * caso na entrada da empresa.
 *
 * ### Estados que não são o gráfico
 * Sem coleta, com erro de API e conta sem venda são TRÊS coisas diferentes, e
 * cada uma diz algo distinto a quem está na reunião. Um estado vazio genérico
 * faria "o Mercado Livre recusou o token" parecer "seu cliente não vendeu
 * nada".
 */
export default function FotografiaDaConta({ fotografia, token, ehEquipe, rota = null }) {
    const [coletando, setColetando] = useState(false);

    // `rota` so e passada pela ficha INTERNA, que nao tem sessao de portal
    // nenhuma. Sem ela, vale o caminho do portal — as duas portas dele ja
    // resolvidas por `rotaDoPortal`.
    const tirar = () => {
        if (coletando) return;
        setColetando(true);
        router.post(rota ?? rotaDoPortal('onboarding.fotografia', token), {}, {
            preserveScroll: true,
            onFinish: () => setColetando(false),
        });
    };

    const botao = ehEquipe && (
        <button
            type="button"
            onClick={tirar}
            disabled={coletando}
            className="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 py-1.5 text-[12px] text-white/75 hover:text-white transition-colors disabled:opacity-40"
        >
            <RefreshCw size={13} className={cn(coletando && 'animate-spin')} />
            {coletando ? 'Buscando…' : fotografia ? 'Atualizar' : 'Gerar snapshot'}
        </button>
    );

    const cabecalho = (extra = null) => (
        <div className="flex items-start justify-between gap-3 flex-wrap">
            <h2 className="text-white font-display font-bold text-[15px]">Snapshot</h2>
            {extra ?? botao}
        </div>
    );

    const moldura = 'rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5 space-y-4';

    // ─── Ainda não tirada ───────────────────────────────────────────────────
    if (! fotografia) {
        if (! ehEquipe) return null;

        return (
            <section className={moldura}>
                {cabecalho()}
                <p className="text-white/40 text-[12.5px] leading-relaxed">
                    Nenhum snapshot ainda. Ele busca o faturamento das últimas 13 semanas
                    direto do Mercado Livre e mostra como a conta estava antes de começarmos.
                </p>
            </section>
        );
    }

    // ─── A coleta falhou ────────────────────────────────────────────────────
    if (fotografia.erro) {
        return (
            <section className={moldura}>
                {cabecalho()}
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.07] px-3 py-2">
                    <p className="text-amber-300 text-[12.5px] font-semibold">
                        Não consegui falar com o Mercado Livre.
                    </p>
                    <p className="text-amber-300/70 text-[12px] mt-0.5 leading-relaxed">{fotografia.erro}</p>
                    <p className="text-white/35 text-[11px] mt-1.5">
                        Costuma ser a autorização da conta — o item “Grant com o Sistema ECF” acima.
                    </p>
                </div>
            </section>
        );
    }

    const serie = fotografia.serie ?? [];
    const j30 = fotografia.janelas?.['30'];
    const j60 = fotografia.janelas?.['60'];
    const j90 = fotografia.janelas?.['90'];

    const vendeu = serie.some((s) => s.faturamento > 0);

    const antes  = fotografia.media_antes;
    const depois = fotografia.media_depois;
    const temComparacao = antes !== null && depois !== null && antes > 0;
    const variacao = temComparacao ? Math.round(((depois - antes) / antes) * 100) : null;
    const subiu = variacao !== null && variacao >= 0;

    // A linha de corte só é desenhável se cair dentro do período coberto.
    const corteVisivel = fotografia.corte_em
        && serie.length > 0
        && fotografia.corte_em >= serie[0].inicio
        && fotografia.corte_em <= serie[serie.length - 1].fim;

    const primeiraComEcf = serie.find((s) => s.apos_ecf);

    return (
        <section className={moldura}>
            {cabecalho()}

            {! vendeu ? (
                <p className="text-white/45 text-[12.5px] leading-relaxed">
                    A conta não registrou vendas nas últimas 13 semanas. O retrato foi tirado
                    e está correto — simplesmente não há faturamento no período.
                </p>
            ) : (
                <>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <Kpi rotulo="Últimas 4 semanas"  valor={brl(j30?.faturamento)} detalhe={`${j30?.pedidos ?? 0} pedidos`} destaque />
                        <Kpi rotulo="Últimas 9 semanas"  valor={brl(j60?.faturamento)} detalhe={`${j60?.pedidos ?? 0} pedidos`} />
                        <Kpi rotulo="Últimas 13 semanas" valor={brl(j90?.faturamento)} detalhe={`${j90?.pedidos ?? 0} pedidos`} />
                    </div>

                    <div className="h-[220px] -ml-2">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={serie} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                                <defs>
                                    <linearGradient id="fotoAntes" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stopColor="#64748b" stopOpacity={0.35} />
                                        <stop offset="100%" stopColor="#64748b" stopOpacity={0.02} />
                                    </linearGradient>
                                    <linearGradient id="fotoDepois" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stopColor="#ffe600" stopOpacity={0.45} />
                                        <stop offset="100%" stopColor="#ffe600" stopOpacity={0.03} />
                                    </linearGradient>
                                </defs>

                                {/* O período anterior à ECF fica apagado: o olho
                                    separa os dois lados antes de ler número. */}
                                {corteVisivel && primeiraComEcf && (
                                    <ReferenceArea
                                        x1={serie[0].inicio}
                                        x2={primeiraComEcf.inicio}
                                        fill="#ffffff"
                                        fillOpacity={0.02}
                                    />
                                )}

                                <CartesianGrid stroke="#ffffff" strokeOpacity={0.05} vertical={false} />

                                <XAxis
                                    dataKey="inicio"
                                    tickFormatter={diaMes}
                                    tick={{ fill: '#ffffff66', fontSize: 11 }}
                                    axisLine={false}
                                    tickLine={false}
                                    interval="preserveStartEnd"
                                    minTickGap={24}
                                />
                                <YAxis
                                    tickFormatter={(v) => (v >= 1000 ? `${Math.round(v / 1000)}k` : v)}
                                    tick={{ fill: '#ffffff66', fontSize: 11 }}
                                    axisLine={false}
                                    tickLine={false}
                                    width={46}
                                />

                                <Tooltip content={<Balao />} cursor={{ stroke: '#ffffff22' }} />

                                <Area
                                    type="monotone"
                                    dataKey="faturamento"
                                    stroke="#ffe600"
                                    strokeWidth={2}
                                    fill={corteVisivel ? 'url(#fotoDepois)' : 'url(#fotoAntes)'}
                                    dot={{ r: 2.5, fill: '#ffe600', strokeWidth: 0 }}
                                    activeDot={{ r: 4.5 }}
                                />

                                {corteVisivel && primeiraComEcf && (
                                    <ReferenceLine
                                        x={primeiraComEcf.inicio}
                                        stroke="#ffe600"
                                        strokeDasharray="4 4"
                                        strokeOpacity={0.7}
                                        label={{
                                            value: 'ECF começa',
                                            position: 'insideTopLeft',
                                            fill: '#ffe600',
                                            fontSize: 11,
                                        }}
                                    />
                                )}
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>

                    {temComparacao && (
                        <div className="flex items-center gap-3 flex-wrap rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3">
                            <span className={cn(
                                'grid place-items-center h-9 w-9 rounded-lg shrink-0',
                                subiu ? 'bg-emerald-400/10 text-emerald-300' : 'bg-red-400/10 text-red-300',
                            )}>
                                {subiu ? <TrendingUp size={17} /> : <TrendingDown size={17} />}
                            </span>

                            <div className="min-w-0">
                                <p className="text-white text-[13px] font-semibold">
                                    Média por semana: {brl(antes)} → {brl(depois)}
                                    <span className={cn('ml-1.5', subiu ? 'text-emerald-300' : 'text-red-300')}>
                                        ({subiu ? '+' : ''}{variacao}%)
                                    </span>
                                </p>
                                <p className="text-white/35 text-[11.5px] mt-0.5">
                                    {fotografia.semanas_antes} {fotografia.semanas_antes === 1 ? 'semana' : 'semanas'} antes
                                    {' · '}
                                    {fotografia.semanas_depois} {fotografia.semanas_depois === 1 ? 'semana' : 'semanas'} com a ECF
                                </p>
                            </div>
                        </div>
                    )}

                    {! temComparacao && fotografia.corte_em && (
                        <p className="text-white/35 text-[12px]">
                            Ainda não há semanas suficientes depois do início para comparar — este é o
                            retrato de partida.
                        </p>
                    )}
                </>
            )}

            <p className="text-white/25 text-[11px]">
                Tirada em {new Date(fotografia.coletado_em).toLocaleString('pt-BR', {
                    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
                })}
                {fotografia.coletado_por && ` por ${fotografia.coletado_por}`}
                {' · dados do Mercado Livre'}
            </p>
        </section>
    );
}
