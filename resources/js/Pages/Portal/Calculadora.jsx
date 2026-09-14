import { useState } from 'react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { Calculator, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Calculadora de Custo — o simulador de preço do portal de Polos, como módulo
 * do Portal do Cliente (14/09).
 *
 * ### A conta
 *     preço = (custo + frete) / (1 − comissão − imposto − margem − lucro)
 *
 * É a mesma de `calcPreco()` em `Pages/Mlb/ImplementacaoPublica.jsx`. Os
 * percentuais são todos sobre o PREÇO DE VENDA, não sobre o custo — é por isso
 * que eles entram no divisor em vez de multiplicar o custo. Quem tenta "somar
 * 30% ao custo" chega num preço que não paga a comissão, e é exatamente o erro
 * que esta tela existe para evitar.
 *
 * ### O que NÃO veio do Polos
 * Catálogo de produtos, famílias, planilha, replicação em massa e tabela de
 * frete por tier. Tudo aquilo depende do acervo de Polos, que o portal desta
 * empresa não tem — traria uma tela com a maioria dos campos vazios. Aqui a
 * pergunta é uma só: "por quanto preciso vender ISTO?".
 *
 * ### Sem salvar
 * É régua de conversa, não registro do onboarding. Nada é gravado, e por isso
 * não há distinção entre equipe e cliente: os dois calculam.
 */

// Medidos em produção (`MlbImplementacao`): comissão do Mercado Livre por tipo
// de anúncio, e o imposto que o Polos usa como partida.
const TIERS = {
    classico: { rotulo: 'Clássico', comissao: 11.5 },
    premium:  { rotulo: 'Premium',  comissao: 16.5 },
};

const IMPOSTO_PADRAO = 19;

const brl = (n) =>
    n === null || n === undefined || isNaN(n)
        ? '—'
        : n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

/** Campo de dinheiro ou percentual, com a explicação embaixo do rótulo. */
function Campo({ rotulo, dica, valor, onChange, sufixo = null, prefixo = null, invalido = false }) {
    return (
        <div>
            <label className={cn('block text-[13px] font-medium', invalido ? 'text-red-400' : 'text-white/75')}>
                {rotulo}
            </label>
            {dica && <p className="text-white/35 text-[11px] mt-0.5 mb-1.5 leading-relaxed">{dica}</p>}

            <div className={cn(
                'flex items-center rounded-xl border bg-white/[0.04] transition-colors',
                invalido ? 'border-red-500/60' : 'border-white/[0.10] focus-within:border-ecf-yellow/40',
            )}>
                {prefixo && <span className="pl-3 text-white/35 text-[13px]">{prefixo}</span>}
                <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={valor}
                    onChange={(e) => onChange(e.target.value)}
                    className="w-full bg-transparent px-3 py-2.5 text-[15px] text-white tabular-nums focus:outline-none"
                />
                {sufixo && <span className="pr-3 text-white/35 text-[13px]">{sufixo}</span>}
            </div>
        </div>
    );
}

/** Uma fatia do preço, na barra e na lista. */
function Fatia({ rotulo, valor, preco, cor }) {
    const pct = preco > 0 ? (valor / preco) * 100 : 0;

    return (
        <li className="flex items-center gap-3">
            <span className="h-2.5 w-2.5 rounded-sm shrink-0" style={{ background: cor }} />
            <span className="text-white/70 text-[13px] flex-1 min-w-0">{rotulo}</span>
            <span className="text-white/40 text-[12px] tabular-nums w-14 text-right">{pct.toFixed(1)}%</span>
            <span className="text-white text-[13px] font-semibold tabular-nums w-24 text-right">{brl(valor)}</span>
        </li>
    );
}

export default function Calculadora({ empresa, modulos = [] }) {
    const [tier, setTier]         = useState('classico');
    const [custo, setCusto]       = useState('');
    const [frete, setFrete]       = useState('0');
    const [comissao, setComissao] = useState(String(TIERS.classico.comissao));
    const [imposto, setImposto]   = useState(String(IMPOSTO_PADRAO));
    const [margem, setMargem]     = useState('10');
    const [lucro, setLucro]       = useState('10');

    const trocarTier = (novo) => {
        setTier(novo);
        setComissao(String(TIERS[novo].comissao));
    };

    const n = (v) => {
        const x = parseFloat(String(v).replace(',', '.'));

        return isNaN(x) ? 0 : x;
    };

    const custoN = n(custo);
    const freteN = n(frete);

    // Os quatro percentuais somados: se chegarem a 100%, não sobra preço que
    // pague tudo — a conta não tem solução e a tela precisa dizer isso em vez
    // de mostrar um número gigante ou negativo.
    const somaPct = n(comissao) + n(imposto) + n(margem) + n(lucro);
    const divisor = 1 - somaPct / 100;
    const impossivel = divisor <= 0;

    const preco = ! impossivel && custoN > 0 ? (custoN + freteN) / divisor : null;

    const fatias = preco === null ? [] : [
        { rotulo: 'Custo do produto',       valor: custoN,                     cor: '#64748b' },
        { rotulo: 'Frete',                  valor: freteN,                     cor: '#0ea5e9' },
        { rotulo: 'Comissão do Mercado Livre', valor: preco * (n(comissao) / 100), cor: '#a855f7' },
        { rotulo: 'Imposto',                valor: preco * (n(imposto) / 100), cor: '#f97316' },
        { rotulo: 'Margem de contribuição', valor: preco * (n(margem) / 100),  cor: '#22c55e' },
        { rotulo: 'Lucro líquido',          valor: preco * (n(lucro) / 100),   cor: '#ffe600' },
    ];

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Calculadora de Custo">
            <div className="max-w-2xl mx-auto px-4 py-6 space-y-4">
                <header>
                    <h1 className="text-white font-display font-bold text-2xl tracking-tight flex items-center gap-2">
                        <Calculator size={22} className="text-ecf-yellow" />
                        Calculadora de Custo
                    </h1>
                    <p className="text-white/45 text-[13.5px] mt-1 leading-relaxed">
                        Descubra por quanto precisa vender para pagar tudo e ainda sobrar o que você quer.
                    </p>
                </header>

                {/* ─── Tipo de anúncio ─────────────────────────────────── */}
                <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5 space-y-4">
                    <div>
                        <p className="text-white/75 text-[13px] font-medium">Tipo de anúncio</p>
                        <p className="text-white/35 text-[11px] mt-0.5">
                            Muda a comissão que o Mercado Livre cobra. Você pode ajustar o valor depois.
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

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Campo
                            rotulo="Custo do produto"
                            dica="Quanto você paga pelo produto, sem frete."
                            prefixo="R$"
                            valor={custo}
                            onChange={setCusto}
                            invalido={custo !== '' && custoN <= 0}
                        />
                        <Campo
                            rotulo="Frete"
                            dica="O que você paga para entregar. Zero se o cliente paga."
                            prefixo="R$"
                            valor={frete}
                            onChange={setFrete}
                        />
                        <Campo
                            rotulo="Comissão"
                            dica="O que o Mercado Livre desconta da venda."
                            sufixo="%"
                            valor={comissao}
                            onChange={setComissao}
                        />
                        <Campo
                            rotulo="Imposto"
                            dica="O que sai em tributo sobre a venda."
                            sufixo="%"
                            valor={imposto}
                            onChange={setImposto}
                        />
                        <Campo
                            rotulo="Margem de contribuição"
                            dica="O que sobra para pagar as despesas fixas."
                            sufixo="%"
                            valor={margem}
                            onChange={setMargem}
                        />
                        <Campo
                            rotulo="Lucro líquido"
                            dica="O que você quer levar no fim."
                            sufixo="%"
                            valor={lucro}
                            onChange={setLucro}
                        />
                    </div>
                </section>

                {/* ─── O resultado ─────────────────────────────────────── */}
                {impossivel ? (
                    <section className="rounded-2xl border border-amber-500/25 bg-amber-500/[0.07] p-5">
                        <p className="text-amber-300 font-semibold text-[14px]">
                            Os percentuais somam {somaPct.toFixed(1)}%.
                        </p>
                        <p className="text-amber-300/75 text-[12.5px] mt-1 leading-relaxed">
                            Comissão, imposto, margem e lucro saem todos do preço de venda. Somando 100%
                            ou mais, não existe preço que pague tudo — nenhum valor resolve, por maior
                            que seja. Reduza algum deles.
                        </p>
                    </section>
                ) : preco === null ? (
                    <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
                        <p className="text-white/40 text-[13px]">
                            Informe o custo do produto para ver o preço.
                        </p>
                    </section>
                ) : (
                    <section className="rounded-2xl border border-ecf-yellow/20 bg-ecf-yellow/[0.05] p-5 space-y-4">
                        <div>
                            <p className="text-white/50 text-[12px] uppercase tracking-wider">Preço de venda</p>
                            <p className="text-ecf-yellow font-display font-extrabold text-[34px] leading-tight tabular-nums">
                                {brl(preco)}
                            </p>
                        </div>

                        {/* A barra dá a proporção antes de qualquer número —
                            é o que mostra, de relance, que a maior fatia é o
                            custo (ou não é). */}
                        <div className="flex h-3 rounded-full overflow-hidden">
                            {fatias.map((f) => (
                                <span
                                    key={f.rotulo}
                                    title={`${f.rotulo}: ${brl(f.valor)}`}
                                    style={{ background: f.cor, width: `${(f.valor / preco) * 100}%` }}
                                />
                            ))}
                        </div>

                        <ul className="space-y-2">
                            {fatias.map((f) => (
                                <Fatia key={f.rotulo} rotulo={f.rotulo} valor={f.valor} preco={preco} cor={f.cor} />
                            ))}
                        </ul>
                    </section>
                )}

                <div className="flex items-start gap-2.5 rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
                    <Info size={15} className="text-white/30 shrink-0 mt-0.5" />
                    <p className="text-white/40 text-[12px] leading-relaxed">
                        Comissão, imposto, margem e lucro são calculados sobre o <strong className="text-white/60">preço
                        de venda</strong>, não sobre o custo. É por isso que somar percentuais ao custo dá
                        um preço que não fecha a conta. Nada aqui é salvo.
                    </p>
                </div>
            </div>
        </PortalClienteLayout>
    );
}
