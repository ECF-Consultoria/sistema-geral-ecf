import { useState } from 'react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { Calculator, ChevronDown, ChevronRight, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Calculadora de Custo — o simulador de preço do portal de Polos, como módulo
 * do Portal do Cliente (14/09).
 *
 * ### A conta, idêntica à de lá
 *     preço  = (custo + frete) / (1 − comissão − imposto − MC − LL)
 *     anunciado = preço × (1 + acréscimo)
 *
 * É `calcPreco()` de `Pages/Mlb/ImplementacaoPublica.jsx`, sem desvio. Os
 * percentuais saem do PREÇO DE VENDA, não do custo — por isso entram no
 * divisor. Quem soma "30% ao custo" chega num preço que não paga a comissão.
 *
 * O acréscimo é markup para desconto: anuncia-se mais caro para ter espaço de
 * promoção sem furar a margem. Sem ele a conta fecharia no preço cheio e
 * qualquer desconto comeria o lucro.
 *
 * ### Um produto por vez, sem catálogo
 * O simulador de Polos vive sobre a Planilha de Produtos — famílias, variações,
 * chips, replicação. Aquilo depende do acervo de Polos, que o portal desta
 * empresa não tem. Aqui é a régua para UM produto, que é a pergunta que se faz
 * na reunião. SKU e Descrição ficaram de fora por decisão do negócio: são
 * campos da planilha, não da conta.
 *
 * ### Sem salvar
 * Régua de conversa, não registro do onboarding. Nada é gravado, e por isso não
 * há distinção entre equipe e cliente: os dois calculam.
 */

// Medidos em `MlbImplementacao` e `CFG_DEFAULT` do portal de Polos — não chute.
const TIERS = {
    classico: { rotulo: 'Clássico', comissao: 11.5, cor: 'text-blue-300' },
    premium:  { rotulo: 'Premium',  comissao: 16.5, cor: 'text-violet-300' },
};

const IMPOSTO_PADRAO   = 19;
const ACRESCIMO_PADRAO = 20;

const fmt = (n) =>
    n === null || n === undefined || isNaN(n)
        ? '—'
        : n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

/**
 * Campo de valor.
 *
 * ⚠️ `border-0 focus:ring-0` no input é OBRIGATÓRIO e foi o que faltou na
 * primeira versão: a borda arredondada é do `div` de fora, e sem isto o input
 * desenha a borda e o anel de foco dele por cima — o que aparece como borda
 * dupla, ou como um retângulo reto brigando com o canto arredondado. É o mesmo
 * cuidado que `CampoValor` do portal de Polos já tinha.
 */
function Campo({ rotulo, dica, valor, onChange, prefixo = null, sufixo = null, invalido = false }) {
    return (
        <div>
            {rotulo && (
                <label className={cn('block text-[13px] font-medium', invalido ? 'text-red-400' : 'text-white/75')}>
                    {rotulo}
                </label>
            )}
            {dica && <p className="text-white/35 text-[11px] mt-0.5 mb-1.5 leading-relaxed">{dica}</p>}

            <div className={cn(
                'flex items-center rounded-xl bg-white/[0.04] border transition-colors',
                invalido ? 'border-red-500' : 'border-white/[0.10] focus-within:border-ecf-yellow/40',
            )}>
                {prefixo && (
                    <span className={cn('pl-3 text-sm shrink-0', invalido ? 'text-red-400' : 'text-white/30')}>
                        {prefixo}
                    </span>
                )}
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    inputMode="decimal"
                    value={valor}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="0,00"
                    className="w-full h-11 px-2 bg-transparent border-0 focus:ring-0 focus:outline-none text-white text-base font-medium tabular-nums placeholder:text-white/20"
                />
                {sufixo && <span className="pr-3 text-sm text-white/30 shrink-0">{sufixo}</span>}
            </div>
        </div>
    );
}

/** Um parâmetro avançado — compacto, porque são quatro lado a lado. */
function CampoPct({ rotulo, valor, onChange }) {
    return (
        <div>
            <label className="text-white/30 text-[10px] uppercase tracking-wider block mb-1">{rotulo}</label>
            <div className="flex items-center rounded-lg bg-white/[0.04] border border-white/[0.10] focus-within:border-ecf-yellow/40 transition-colors">
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    inputMode="decimal"
                    value={valor}
                    onChange={(e) => onChange(e.target.value)}
                    className="w-full h-9 px-2 bg-transparent border-0 focus:ring-0 focus:outline-none text-white text-[13px] tabular-nums"
                />
                <span className="pr-2 text-white/30 text-[12px] shrink-0">%</span>
            </div>
        </div>
    );
}

export default function Calculadora({ empresa, modulos = [] }) {
    const [tier, setTier]           = useState('classico');
    const [custo, setCusto]         = useState('');
    const [freteClassico, setFrC]   = useState('');
    const [fretePremium, setFrP]    = useState('');
    const [comissao, setComissao]   = useState(String(TIERS.classico.comissao));
    const [imposto, setImposto]     = useState(String(IMPOSTO_PADRAO));
    const [mc, setMc]               = useState('0');
    const [ll, setLl]               = useState('0');
    const [acrescimo, setAcrescimo] = useState(String(ACRESCIMO_PADRAO));
    // Já nasce aberto, como no Polos: aumenta a chance de o cliente conferir.
    const [avancado, setAvancado]   = useState(true);

    const trocarTier = (novo) => {
        setTier(novo);
        setComissao(String(TIERS[novo].comissao));
    };

    const n = (v) => {
        const x = parseFloat(String(v).replace(',', '.'));

        return isNaN(x) ? 0 : x;
    };

    const custoN = n(custo);
    const freteN = n(tier === 'classico' ? freteClassico : fretePremium);
    const setFrete = tier === 'classico' ? setFrC : setFrP;
    const freteValor = tier === 'classico' ? freteClassico : fretePremium;

    const somaPct   = n(comissao) + n(imposto) + n(mc) + n(ll);
    const divisor   = 1 - somaPct / 100;
    const impossivel = divisor <= 0;

    const preco     = ! impossivel && custoN > 0 ? (custoN + freteN) / divisor : null;
    const anunciado = preco !== null ? preco * (1 + n(acrescimo) / 100) : null;

    // Frete obrigatório: tem custo mas não tem frete. Mesma régua do Polos —
    // frete zerado por engano é o erro que mais estraga a conta, porque some
    // sem deixar rastro no resultado.
    const freteFaltando = custoN > 0 && freteN <= 0;

    const comp = preco === null ? [] : [
        { label: 'Custo do produto',       valor: custoN,                     cor: '#64748b' },
        { label: 'Frete',                  valor: freteN,                     cor: '#0ea5e9' },
        { label: 'Comissão do site',       valor: preco * (n(comissao) / 100), cor: '#a855f7' },
        { label: 'Impostos',               valor: preco * (n(imposto) / 100),  cor: '#f97316' },
        { label: 'Margem de contribuição', valor: preco * (n(mc) / 100),       cor: '#38bdf8' },
        { label: 'Lucro líquido',          valor: preco * (n(ll) / 100),       cor: '#22c55e' },
    ].filter((f) => f.valor > 0);

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Calculadora de Custo">
            <div className="max-w-2xl mx-auto px-4 py-6 space-y-4">
                <header>
                    <h1 className="text-white font-display font-bold text-2xl tracking-tight flex items-center gap-2">
                        <Calculator size={22} className="text-ecf-yellow" />
                        Calculadora de Custo
                    </h1>
                    <p className="text-white/45 text-[13.5px] mt-1 leading-relaxed">
                        Descubra por quanto vender para pagar tudo e ainda sobrar o que você quer.
                    </p>
                </header>

                <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5 space-y-4">
                    {/* ── Tipo de anúncio ─────────────────────────────── */}
                    <div>
                        <p className="text-white/75 text-[13px] font-medium">Tipo de anúncio</p>
                        <p className="text-white/35 text-[11px] mt-0.5">
                            Muda a comissão do Mercado Livre e o frete considerado.
                        </p>

                        <div className="flex gap-2 mt-2">
                            {Object.entries(TIERS).map(([chave, t]) => (
                                <button
                                    key={chave}
                                    type="button"
                                    onClick={() => trocarTier(chave)}
                                    className={cn(
                                        'rounded-lg px-4 py-2 text-[13px] font-semibold transition-colors',
                                        tier === chave
                                            ? 'bg-ecf-yellow text-ecf-bg'
                                            : 'border border-white/[0.10] bg-white/[0.03] text-white/70 hover:text-white',
                                    )}
                                >
                                    {t.rotulo}
                                    <span className="ml-1.5 opacity-60 tabular-nums">{t.comissao}%</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* ── Os dois campos que mudam por produto ────────── */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Campo
                            rotulo="Custo do produto"
                            dica="Quanto você paga pelo produto, sem frete."
                            prefixo="R$"
                            valor={custo}
                            onChange={setCusto}
                        />
                        <Campo
                            rotulo={`Frete ${TIERS[tier].rotulo}`}
                            dica="O que você paga para entregar neste tipo de anúncio."
                            prefixo="R$"
                            valor={freteValor}
                            onChange={setFrete}
                            invalido={freteFaltando}
                        />
                    </div>

                    {freteFaltando && (
                        <p className="text-red-400/80 text-[12px]">
                            Frete zerado. Se a entrega sai de graça para você, deixe 0 — mas confira:
                            frete esquecido some no resultado e derruba a margem inteira.
                        </p>
                    )}

                    {/* ── Parâmetros avançados ────────────────────────── */}
                    <div className="pt-1">
                        <button
                            type="button"
                            onClick={() => setAvancado((v) => ! v)}
                            className="inline-flex items-center gap-1.5 text-white/50 hover:text-white/80 text-[12px] transition-colors"
                        >
                            {avancado ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                            Parâmetros
                        </button>

                        {avancado && (
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mt-2.5">
                                <CampoPct rotulo="Comissão"  valor={comissao}  onChange={setComissao} />
                                <CampoPct rotulo="Imposto"   valor={imposto}   onChange={setImposto} />
                                <CampoPct rotulo="Margem"    valor={mc}        onChange={setMc} />
                                <CampoPct rotulo="Lucro"     valor={ll}        onChange={setLl} />
                                <div className="col-span-2 sm:col-span-1">
                                    <CampoPct rotulo="Acréscimo" valor={acrescimo} onChange={setAcrescimo} />
                                </div>
                            </div>
                        )}
                    </div>
                </section>

                {/* ── Resultado ──────────────────────────────────────── */}
                {impossivel ? (
                    <section className="rounded-2xl border border-amber-500/25 bg-amber-500/[0.07] p-5">
                        <p className="text-amber-300 font-semibold text-[14px]">
                            Comissão, imposto, margem e lucro somam {somaPct.toFixed(1)}%.
                        </p>
                        <p className="text-amber-300/75 text-[12.5px] mt-1 leading-relaxed">
                            Os quatro saem do preço de venda. Somando 100% ou mais, não existe preço que
                            pague tudo — nenhum valor resolve, por maior que seja. Reduza algum deles.
                        </p>
                    </section>
                ) : preco === null ? (
                    <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
                        <p className="text-white/40 text-[13px]">Informe o custo do produto para ver o preço.</p>
                    </section>
                ) : (
                    <section className="rounded-2xl border border-ecf-yellow/20 bg-gradient-to-b from-ecf-yellow/[0.06] to-transparent p-5 space-y-4">
                        <div>
                            <p className="text-white/40 text-[11px] uppercase tracking-wider">Preço de venda sugerido</p>
                            <p className="text-ecf-yellow font-display font-extrabold text-4xl mt-1 leading-none tabular-nums">
                                {fmt(preco)}
                            </p>
                            <p className="text-white/40 text-[12px] mt-1.5">
                                Anuncie por <span className="text-white/70 font-semibold">{fmt(anunciado)}</span> e
                                dê o desconto até {fmt(preco)}.
                            </p>
                        </div>

                        <div>
                            <p className="text-white/40 text-[11px] uppercase tracking-wider mb-1.5">
                                Para onde vai cada real
                            </p>

                            <div className="flex h-3 rounded-full overflow-hidden">
                                {comp.map((f) => (
                                    <span
                                        key={f.label}
                                        title={`${f.label}: ${fmt(f.valor)}`}
                                        style={{ background: f.cor, width: `${(f.valor / preco) * 100}%` }}
                                    />
                                ))}
                            </div>

                            <ul className="space-y-2 mt-3">
                                {comp.map((f) => (
                                    <li key={f.label} className="flex items-center gap-3">
                                        <span className="h-2.5 w-2.5 rounded-sm shrink-0" style={{ background: f.cor }} />
                                        <span className="text-white/70 text-[13px] flex-1 min-w-0">{f.label}</span>
                                        <span className="text-white/40 text-[12px] tabular-nums w-14 text-right">
                                            {((f.valor / preco) * 100).toFixed(1)}%
                                        </span>
                                        <span className="text-white text-[13px] font-semibold tabular-nums w-24 text-right">
                                            {fmt(f.valor)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                )}

                <div className="flex items-start gap-2.5 rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
                    <Info size={15} className="text-white/30 shrink-0 mt-0.5" />
                    <p className="text-white/40 text-[12px] leading-relaxed">
                        Comissão, imposto, margem e lucro são calculados sobre o{' '}
                        <strong className="text-white/60">preço de venda</strong>, não sobre o custo — por isso
                        somar percentuais ao custo dá um preço que não fecha a conta. O acréscimo é o espaço
                        para dar desconto sem furar a margem. Nada aqui é salvo.
                    </p>
                </div>
            </div>
        </PortalClienteLayout>
    );
}
