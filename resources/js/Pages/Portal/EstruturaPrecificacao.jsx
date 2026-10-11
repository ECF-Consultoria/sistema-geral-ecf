import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronDown, ChevronRight, Copy, Loader2, Percent, RefreshCw, Search, SlidersHorizontal, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CLASSE_INPUT, CabecalhoEstrutura, Campo, Paginacao, fmtReais } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { deveCotarDeNovo, freteEmBranco, rotuloDoFrete, textoDaCotacao } from '@/lib/precificacaoFreteSugerido';
import { filtrosDeTipo, linhasDaFamilia, montarFamilias, ondeEstaCadaOferta, pendentesDaFamilia, resumoDaFamilia } from '@/lib/precificacaoPorFamilia';
import { submoduloVisivel } from '@/lib/portalSubmodulos';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — submódulo Precificação ─────────────────────────
//
// Os produtos da Lista SKUs com o preço de cada um (29/09, ADR PORTAL-02). A
// conta é a da Calculadora de Custo — (custo + frete) / (1 − comissão −
// imposto − MC − LL), e o anunciado com o acréscimo — e é feita NO PHP
// (`PrecificacaoEstrutura`). Esta tela só recebe os números e os desenha:
// recalcular aqui é como o onboarding chegou a publicar preço 43% errado
// (`precificacao-onboarding-duas-telas.md` §1).
//
// ### O que o cliente digita
// - Os parâmetros da EMPRESA (uma vez): comissão de cada tipo, imposto, MC,
//   LL e acréscimo — com exceção por produto, no "Ajustar".
// - Por produto: custo e os dois fretes. Combo, kit e combit já chegam com o
//   custo SOMADO dos componentes (CB4 = 4 × o da CAD-01) — dá para corrigir.
// Tudo salva ao sair do campo, como nas outras abas.
//
// ### Frete sugerido (09/10/2026, D-19 revogada)
// Frete em branco não é zero: o servidor sugere o do Mercado Envios de CADA tipo, no
// preço daquele tipo — a cotação da conta do cliente, quando já cotada, ou a tabela de
// custos do ML. O campo mostra o sugerido apagado, com a origem embaixo; digitar por
// cima vence. "Cotar agora" pede a cotação real das ofertas desta página.
//
// ### Por família (11/10/2026)
// A lista era corrida — "uma lista inteira sem saber o que é". Agora vem em grupos
// por família (a do cadastro de Produtos): cada produto uma vez, com os combos dele
// pendurados embaixo; kit e combit em "Conjuntos desta família", também uma vez
// só, e o produto que entra neles diz "Também entra em". O que não tem família
// fica no grupo "Sem família". Quem agrupa e ordena é o servidor, antes de
// paginar; aqui é só desenho (`lib/precificacaoPorFamilia.js`).

const FASE_CURTA = { simples: 'Simples', combo: 'Combo', kit: 'Kit', combit: 'Combit' };

const PARAMETROS = [
    ['comissao_classico', 'Comissão Clássico'],
    ['comissao_premium', 'Comissão Premium'],
    ['imposto', 'Imposto'],
    ['margem_contribuicao', 'Margem de contribuição'],
    ['lucro_liquido', 'Lucro líquido'],
    ['acrescimo', 'Acréscimo'],
];

const PENDENCIA = {
    sem_custo:  { rotulo: 'Sem custo', classe: 'text-white/40' },
    sem_frete:  { rotulo: 'Sem frete', classe: 'bg-amber-500/10 text-amber-300' },
    impossivel: { rotulo: 'Conta impossível', classe: 'bg-red-500/10 text-red-300' },
};

// O cliente digita "12,50" — o servidor recebe 12.50. Vazio é "não informado".
const paraNumero = (texto) => {
    const t = String(texto ?? '').trim().replace(/\s|R\$|%/g, '');
    if (t === '') return '';

    return t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t;
};
const paraTexto = (n) => (n === null || n === undefined ? '' : String(n).replace('.', ','));
const fmtPct = (n) => `${Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;
const dataBr = (iso) => {
    if (! iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');

    return `${d}/${m}/${a}`;
};

const CELULA = 'w-full rounded-lg border border-white/[0.06] bg-white/[0.02] px-2 py-1.5 text-right text-[13px] tabular-nums text-white placeholder:text-white/30 hover:border-white/[0.14] focus:border-ecf-yellow/40 focus:bg-white/[0.04] focus:outline-none focus:ring-0';

// Regra do onboarding de Polos (`ImplementacaoPublica.jsx`, updateCfg): mexer na
// comissão do Clássico leva a do Premium junto, 5 pontos acima. O Premium segue
// editável depois — só não fica para trás esquecido abaixo do Clássico.
const DIFERENCA_PREMIUM = 5;
const comPremiumAcompanhando = (valores, chave, texto) => {
    const novo = { ...valores, [chave]: texto };
    const classico = parseFloat(paraNumero(texto));
    if (chave === 'comissao_classico' && Number.isFinite(classico)) {
        novo.comissao_premium = paraTexto(Math.round((classico + DIFERENCA_PREMIUM) * 100) / 100);
    }

    return novo;
};

/** O conjunto da empresa: seis percentuais, salvos juntos. */
function ParametrosEmpresa({ parametros, padroes }) {
    const [valores, setValores] = useState(() => Object.fromEntries(PARAMETROS.map(([k]) => [k, paraTexto(parametros[k])])));
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);

    // Pela assinatura dos valores, não pela identidade do objeto: salvar uma
    // linha recarrega a página e não pode apagar o que se digita aqui.
    const assinatura = PARAMETROS.map(([k]) => parametros[k]).join('|');
    useEffect(() => {
        setValores(Object.fromEntries(PARAMETROS.map(([k]) => [k, paraTexto(parametros[k])])));
    }, [assinatura]); // eslint-disable-line react-hooks/exhaustive-deps

    const mudou = PARAMETROS.some(([k]) => paraNumero(valores[k]) !== String(parametros[k]));

    const salvar = () => router.put(route('portal.auth.estrutura.precificacao.parametros'),
        Object.fromEntries(PARAMETROS.map(([k]) => [k, paraNumero(valores[k])])), {
            preserveScroll: true, preserveState: true,
            onStart: () => { setEnviando(true); setErros({}); },
            onFinish: () => setEnviando(false),
            onError: setErros,
        });

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5" data-parametros-empresa>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="flex items-center gap-2 text-[13.5px] font-semibold text-white"><Percent size={15} className="text-ecf-yellow" /> Parâmetros da empresa</h2>
                    <p className="text-[12px] text-white/45">Valem para todos os produtos. Um produto diferente se ajusta no "Ajustar" da linha dele.</p>
                </div>
                <Botao variante="primario" onClick={salvar} disabled={! mudou || enviando} data-acao="salvar-parametros">
                    {enviando && <Loader2 size={14} className="animate-spin" />} Salvar parâmetros
                </Botao>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {PARAMETROS.map(([k, rotulo]) => (
                    <Campo key={k} rotulo={rotulo} erro={erros[k]}
                        dica={k === 'acrescimo' ? `o desconto da promoção · padrão ${fmtPct(padroes[k])}` : `padrão ${fmtPct(padroes[k])}`}>
                        <div className="relative">
                            <input value={valores[k]} onChange={(e) => setValores(comPremiumAcompanhando(valores, k, e.target.value))} inputMode="decimal"
                                className={cn(CLASSE_INPUT, 'pr-7 text-right tabular-nums')} data-parametro={k} />
                            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-white/35">%</span>
                        </div>
                    </Campo>
                ))}
            </div>
        </section>
    );
}

/** Copia o valor no formato que o Mercado Livre aceita ("131.22"). */
function Copiar({ valor, rotulo }) {
    const [copiado, setCopiado] = useState(false);
    const copiar = () => {
        try {
            navigator.clipboard.writeText(Number(valor).toFixed(2));
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        } catch { /* sem clipboard: o valor segue visível ao lado */ }
    };

    return (
        <button type="button" onClick={copiar} title={`Copiar ${rotulo}`} aria-label={`Copiar ${rotulo}`}
            className="shrink-0 text-white/25 transition hover:text-white">
            {copiado ? <Check size={12} className="text-emerald-400" /> : <Copy size={12} />}
        </button>
    );
}

/**
 * Preço de um tipo, como no link do Publicador do onboarding: o preço de
 * ANUNCIAR (com o acréscimo) e o preço na PROMOÇÃO (sem ele). O acréscimo é o
 * desconto que se dá na Central de Promoções do ML — o "sem acréscimo" não é
 * um mínimo abstrato, é o preço que o comprador paga na promoção.
 */
function Preco({ calculo, tipo }) {
    if (calculo.minimo === null) return <span className="text-[12.5px] text-white/25">—</span>;

    return (
        <span className="block space-y-0.5 text-right" data-preco={tipo}>
            <span className="flex items-center justify-end gap-1.5" title="Preço para publicar o anúncio no Mercado Livre">
                <span className="text-[9.5px] font-semibold uppercase tracking-wider text-amber-300/60">Anunciar</span>
                <span className="text-[13.5px] font-bold tabular-nums text-amber-300" data-anunciar>{fmtReais(calculo.anunciado)}</span>
                <Copiar valor={calculo.anunciado} rotulo="o preço de anunciar" />
            </span>
            <span className="flex items-center justify-end gap-1.5" title="Preço com o desconto da Central de Promoções: paga custo, frete, comissão, imposto, MC e LL">
                <span className="text-[9.5px] font-semibold uppercase tracking-wider text-emerald-300/60">Promoção</span>
                <span className="text-[13.5px] font-bold tabular-nums text-emerald-300" data-promocao>{fmtReais(calculo.minimo)}</span>
                <Copiar valor={calculo.minimo} rotulo="o preço da promoção" />
            </span>
            <span className="block text-[10.5px] tabular-nums text-white/30">comissão {fmtPct(calculo.comissao)}</span>
        </span>
    );
}

/** Como publicar com esses dois preços — o mesmo passo a passo do link do Publicador. */
function ComoPublicar({ acrescimo }) {
    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-3.5 sm:px-5" data-como-publicar>
            <p className="text-[13px] font-semibold text-white">Como usar os dois preços</p>
            <ol className="mt-1.5 space-y-1 text-[12.5px] leading-relaxed text-white/60">
                <li>
                    <span className="font-semibold text-white/80">1.</span> Publique o anúncio no Mercado Livre pelo preço{' '}
                    <span className="font-semibold text-amber-300">Anunciar</span> — ele já tem os {fmtPct(acrescimo)} de acréscimo.
                </li>
                <li>
                    <span className="font-semibold text-white/80">2.</span> Na <span className="font-semibold text-white/80">Central de Promoções</span>, crie o desconto
                    até o preço <span className="font-semibold text-emerald-300">Promoção</span>, que é o valor sem os {fmtPct(acrescimo)}.
                </li>
                <li>
                    <span className="font-semibold text-white/80">3.</span> O comprador vê o produto com desconto, e o preço da promoção ainda paga custo, frete, comissão,
                    imposto e as margens dos parâmetros. Não desça abaixo dele.
                </li>
            </ol>
        </section>
    );
}

// Frete em branco (`PrecificacaoEstrutura::fretes`): o campo mostra, apagado, o que a conta
// usa — o sugerido do próprio tipo ou, sem sugestão (ME1, sem medidas), o do outro tipo.
const placeholderFrete = (tipo) => (freteEmBranco(tipo) ? paraTexto(tipo.frete) : 'R$');

/** De onde veio o frete daquele tipo, com rótulo neutro; a tabela diz a vigência na dica. */
function OrigemDoFrete({ calculo, de, freteTabela }) {
    const r = rotuloDoFrete(calculo, de, fmtReais);
    if (! r) return null;

    const s = calculo.frete_sugerido;
    const vigencia = freteTabela?.vigente_desde ? ` vigente desde ${dataBr(freteTabela.vigente_desde)}` : '';
    const reputacao = freteTabela?.reputacao ? `, reputação ${freteTabela.reputacao}` : '';
    const dica = r.tipo === 'herdado'
        ? `Sem frete do Mercado Envios para sugerir: vale o que você digitou no ${de}.`
        : s?.fonte === 'conta'
            ? 'Cotado na sua conta do Mercado Livre, no preço deste tipo de anúncio.'
            : `Tabela de custos de envio do Mercado Livre${vigencia}${reputacao}.`;

    return (
        <span className="mt-0.5 block text-right text-[10.5px] text-white/35" title={dica}
            data-frete-origem={r.tipo} data-frete-fonte={r.fonte ?? ''}>
            {r.texto}
        </span>
    );
}

/** Um atalho para outra oferta da lista. Fora desta página (ou do filtro) é só o texto. */
function Atalho({ alvo, rotulo, onIrPara }) {
    const classe = 'max-w-full truncate rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[10.5px] text-white/60';
    const dica = alvo.nome ? `${alvo.nome} (${alvo.sku})` : alvo.sku;
    if (! onIrPara?.pode(alvo.id)) return <span className={classe} title={dica}>{rotulo}</span>;

    return (
        <button type="button" onClick={() => onIrPara.ir(alvo.id)} title={`Ir para ${dica}`} data-acao="ir-para-oferta"
            className={cn(classe, 'hover:border-ecf-yellow/40 hover:text-white')}>
            {rotulo}
        </button>
    );
}

/** O cabeçalho de uma família: abre e fecha o grupo e resume o que há nele. */
function CabecalhoDaFamilia({ familia, aberta, pendentes, fixa, onAlternar }) {
    const Seta = aberta ? ChevronDown : ChevronRight;

    return (
        <tr className="border-t border-white/[0.08] bg-white/[0.03]" data-familia-cabecalho={familia.chave}>
            <td colSpan={8} className="px-3 py-2">
                <button type="button" onClick={onAlternar} disabled={fixa} aria-expanded={aberta} data-acao="alternar-familia"
                    className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-left disabled:cursor-default">
                    <Seta size={15} className={cn('shrink-0', fixa ? 'text-white/20' : 'text-white/50')} />
                    <span className={cn('text-[13.5px] font-semibold', familia.semFamilia ? 'text-white/60' : 'text-white')}>{familia.nome}</span>
                    <span className="text-[12px] text-white/45">{resumoDaFamilia(familia)}</span>
                    {familia.semFamilia && <span className="text-[12px] text-white/35">Defina a família na ficha do produto para agrupar.</span>}
                    <span className="flex-1" />
                    {pendentes > 0 && (
                        <span className="whitespace-nowrap rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-300" data-pendentes={pendentes}>
                            {pendentes} {pendentes === 1 ? 'pendente' : 'pendentes'}
                        </span>
                    )}
                </button>
            </td>
        </tr>
    );
}

/** Onde começam os kits e combits da família. */
function TituloConjuntos() {
    return (
        <tr className="border-t border-white/[0.06]" data-titulo-conjuntos>
            <td colSpan={8} className="px-3 pb-1 pt-3">
                <span className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-[11.5px] font-semibold text-white/65">Conjuntos desta família</span>
                    <span className="text-[11px] text-white/35">kits e combits, que juntam mais de um produto</span>
                </span>
            </td>
        </tr>
    );
}

/**
 * Uma linha: a oferta, o custo (digitado ou dos componentes), os fretes e os dois preços.
 * `filho` = combo pendurado no produto; `alternar` = o produto abre/fecha os combos dele; `tambemEm` e
 * `itens` são os atalhos (em que conjuntos o produto entra / do que o conjunto é feito).
 */
function LinhaPreco({ oferta, calculo, onAjustar, freteTabela, filho = false, alternar = null, aberto = false, tambemEm = [], itens = [], onIrPara = null }) {
    // D-10: na oferta que veio do Produtos o custo mora no produto (167-08); a
    // tela mostra o valor e não manda custo no salvar (o servidor o recusaria).
    const doProduto = calculo.do_produto === true;
    const [custo, setCusto] = useState(paraTexto(calculo.custo.origem === 'digitado' ? calculo.custo.valor : null));
    const [freteC, setFreteC] = useState(paraTexto(calculo.frete_classico));
    const [freteP, setFreteP] = useState(paraTexto(calculo.frete_premium));
    const [erro, setErro] = useState(null);
    const [salvando, setSalvando] = useState(false);

    // Relê só quando o que ESTA linha gravou mudou: salvar outra linha recarrega
    // a página, e isso não pode apagar o que se está digitando aqui.
    const custoDigitado = calculo.custo.origem === 'digitado' ? calculo.custo.valor : null;
    useEffect(() => {
        setCusto(paraTexto(custoDigitado));
        setFreteC(paraTexto(calculo.frete_classico));
        setFreteP(paraTexto(calculo.frete_premium));
    }, [custoDigitado, calculo.frete_classico, calculo.frete_premium]);

    const salvar = () => {
        const dados = { ...(doProduto ? {} : { custo: paraNumero(custo) }), frete_classico: paraNumero(freteC), frete_premium: paraNumero(freteP) };
        const norma = (v) => (v === '' || v === null || v === undefined ? '' : String(Number(v)));
        const antes = { ...(doProduto ? {} : { custo: custoDigitado }), frete_classico: calculo.frete_classico, frete_premium: calculo.frete_premium };
        if (Object.keys(dados).every((k) => norma(dados[k]) === norma(antes[k]))) return;

        router.put(route('portal.auth.estrutura.precificacao.oferta', oferta.id), { ...dados, ...calculo.excecoes }, {
            preserveScroll: true, preserveState: true,
            onStart: () => { setSalvando(true); setErro(null); },
            onFinish: () => setSalvando(false),
            onError: (e) => setErro(Object.values(e)[0] ?? 'Não foi possível salvar.'),
        });
    };

    const enter = (e) => e.key === 'Enter' && e.currentTarget.blur();
    const temComponentes = oferta.componentes.length > 0;
    const pendencia = PENDENCIA[calculo.pendencia];
    const ajustado = Object.values(calculo.excecoes).some((v) => v !== null);

    return (
        <tr id={`oferta-${oferta.id}`} className={cn('scroll-mt-24 border-t border-white/[0.06] align-top', filho && 'bg-white/[0.015]')}
            data-linha-preco={oferta.id} data-pendencia={calculo.pendencia ?? 'ok'} data-nivel={filho ? 'combo' : 'produto'}>
            <td className="px-3 py-2.5">
                <span className={cn('flex items-start gap-1.5', filho && 'pl-4')}>
                    {/* O combo pende do produto dele: um "L" liga os dois. */}
                    {filho && <span aria-hidden="true" className="mt-0.5 h-3 w-3 shrink-0 rounded-bl border-b border-l border-white/25" data-ligacao />}
                    {alternar ? (
                        <button type="button" onClick={alternar} aria-expanded={aberto} data-acao="alternar-combos"
                            aria-label={`${aberto ? 'Esconder' : 'Mostrar'} os combos de ${oferta.sku}`}
                            className="mt-px shrink-0 rounded p-0.5 text-white/45 hover:bg-white/[0.06] hover:text-white">
                            {aberto ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                        </button>
                    ) : (! filho && <span aria-hidden="true" className="w-[18px] shrink-0" />)}
                    <span className="min-w-0 flex-1">
                        {/* O código nunca é cortado (é por ele que se acha o produto): quebra de linha. O nome mostra até duas. */}
                        <span className="flex items-baseline gap-2">
                            <span className="min-w-0 break-words font-mono text-[12.5px] font-semibold text-white">{oferta.sku}</span>
                            <span className="shrink-0 text-[10.5px] text-white/35">{FASE_CURTA[oferta.fase]}</span>
                        </span>
                        <span className="line-clamp-2 text-[11.5px] text-white/40" title={oferta.nome ?? ''}>{oferta.nome}</span>
                        {itens.length > 0 && (
                            <span className="mt-1 flex flex-wrap gap-1" data-composicao>
                                {itens.map((c) => <Atalho key={c.id} alvo={c} rotulo={`${c.quantidade}× ${c.nome ?? c.sku}`} onIrPara={onIrPara} />)}
                            </span>
                        )}
                        {tambemEm.length > 0 && (
                            <span className="mt-1 flex flex-wrap items-center gap-1" data-tambem-em>
                                <span className="text-[10.5px] text-white/35">Também entra em</span>
                                {/* Tipo + código: o nome do conjunto é comprido demais para caber aqui (vai na dica). */}
                                {tambemEm.map((k) => <Atalho key={k.id} alvo={k} rotulo={`${FASE_CURTA[k.fase] ?? ''} ${k.sku}`.trim()} onIrPara={onIrPara} />)}
                            </span>
                        )}
                    </span>
                </span>
            </td>
            <td className="px-1.5 py-1.5">
                {doProduto ? (
                    <span className="block text-right" data-custo-do-produto>
                        <span className="block text-[13px] tabular-nums text-white/80">
                            {calculo.custo.valor !== null && calculo.custo.valor !== undefined ? fmtReais(calculo.custo.valor) : 'sem custo'}
                        </span>
                        <span className="mt-0.5 block text-[12px] text-white/40">vem do produto</span>
                        <a href={route('portal.auth.estrutura.produtos', { q: oferta.sku })} className="text-[12px] text-ecf-yellow hover:underline" data-acao="alterar-no-produtos">
                            Alterar no Produtos
                        </a>
                    </span>
                ) : (
                    <input value={custo} onChange={(e) => setCusto(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                        placeholder={temComponentes && calculo.custo.calculado !== null ? paraTexto(calculo.custo.calculado) : 'R$'}
                        className={CELULA} aria-label={`Custo de ${oferta.sku}`} data-celula="custo" />
                )}
                {! doProduto && temComponentes && (
                    <span className="mt-0.5 block text-right text-[10.5px] text-white/35" data-custo-origem={calculo.custo.origem ?? ''}>
                        {calculo.custo.origem === 'componentes' ? 'soma dos componentes'
                            : calculo.custo.origem === 'digitado' ? (calculo.custo.calculado !== null ? `componentes: ${fmtReais(calculo.custo.calculado)}` : 'digitado')
                            : 'falta o custo de um componente'}
                    </span>
                )}
                {erro && <span className="mt-0.5 block text-right text-[11px] text-red-300">{erro}</span>}
            </td>
            <td className="px-1.5 py-1.5">
                <input value={freteC} onChange={(e) => setFreteC(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                    placeholder={placeholderFrete(calculo.classico)} className={CELULA} aria-label={`Frete do Clássico de ${oferta.sku}`} data-celula="frete-classico" />
                <OrigemDoFrete calculo={calculo.classico} de="Premium" freteTabela={freteTabela} />
            </td>
            <td className="px-1.5 py-1.5">
                <input value={freteP} onChange={(e) => setFreteP(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                    placeholder={placeholderFrete(calculo.premium)} className={CELULA} aria-label={`Frete do Premium de ${oferta.sku}`} data-celula="frete-premium" />
                <OrigemDoFrete calculo={calculo.premium} de="Clássico" freteTabela={freteTabela} />
            </td>
            <td className="px-3 py-2"><Preco calculo={calculo.classico} tipo="classico" /></td>
            <td className="px-3 py-2"><Preco calculo={calculo.premium} tipo="premium" /></td>
            <td className="px-3 py-2.5">
                {salvando
                    ? <Loader2 size={14} className="animate-spin text-white/40" />
                    : pendencia
                        ? <span className={cn('whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold', pendencia.classe)}>{pendencia.rotulo}</span>
                        : <span className="whitespace-nowrap rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-300">Precificado</span>}
            </td>
            <td className="px-2 py-2 text-right">
                <button type="button" onClick={() => onAjustar(oferta, calculo)} title="Percentuais só deste produto" data-acao="ajustar-preco"
                    className={cn('inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-[12px] hover:bg-white/[0.06]', ajustado ? 'text-ecf-yellow' : 'text-white/40 hover:text-white')}>
                    <SlidersHorizontal size={13} /> {ajustado ? 'Ajustado' : 'Ajustar'}
                </button>
            </td>
        </tr>
    );
}

/** As exceções de um produto: vazio = vale o da empresa. */
function AjustarProduto({ alvo, parametros, onFechar }) {
    const chaves = PARAMETROS.filter(([k]) => k !== 'acrescimo');
    const [valores, setValores] = useState({});
    const [erros, setErros] = useState({});

    useEffect(() => {
        if (alvo) setValores(Object.fromEntries(chaves.map(([k]) => [k, paraTexto(alvo.calculo.excecoes[k])])));
        setErros({});
    }, [alvo]); // eslint-disable-line react-hooks/exhaustive-deps

    const salvar = () => {
        const c = alvo.calculo;
        router.put(route('portal.auth.estrutura.precificacao.oferta', alvo.oferta.id), {
            ...(c.do_produto ? {} : { custo: c.custo.origem === 'digitado' ? c.custo.valor : '' }),
            frete_classico: c.frete_classico ?? '',
            frete_premium: c.frete_premium ?? '',
            ...Object.fromEntries(chaves.map(([k]) => [k, paraNumero(valores[k])])),
        }, { preserveScroll: true, preserveState: true, onSuccess: onFechar, onError: setErros });
    };

    return (
        <Janela aberta={!! alvo} onFechar={onFechar} titulo={`Percentuais de ${alvo?.oferta.sku ?? ''}`}
            descricao="Só para este produto. Deixe vazio para usar o da empresa.">
            <div className="grid gap-3 sm:grid-cols-2" data-ajustar-produto>
                {chaves.map(([k, rotulo]) => (
                    <Campo key={k} rotulo={rotulo} erro={erros[k]} dica={`empresa: ${fmtPct(parametros[k])}`}>
                        <div className="relative">
                            <input value={valores[k] ?? ''} onChange={(e) => setValores(comPremiumAcompanhando(valores, k, e.target.value))} inputMode="decimal"
                                placeholder={paraTexto(parametros[k])} className={cn(CLASSE_INPUT, 'pr-7 text-right tabular-nums')} data-excecao={k} />
                            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-white/35">%</span>
                        </div>
                    </Campo>
                ))}
            </div>
            <div className="flex justify-end gap-2 pt-3">
                <Botao variante="fantasma" onClick={onFechar}>Cancelar</Botao>
                <Botao variante="primario" onClick={salvar} data-acao="salvar-ajuste">Salvar</Botao>
            </div>
        </Janela>
    );
}

function Numero({ valor, rotulo, classe }) {
    return (
        <div>
            <span className={cn('block font-display text-[22px] font-bold leading-none', classe)}>{valor.toLocaleString('pt-BR')}</span>
            <span className="mt-1 block text-[12px] text-white/50">{rotulo}</span>
        </div>
    );
}

/**
 * Nada para precificar ainda: as ofertas vêm da Lista SKUs para quem a vê; quem não a vê (o
 * cliente, desde 09/10/2026) cadastra em Produtos e monta os kits no Planejamento.
 */
function EstadoVazio({ listaVisivel = true }) {
    const classe = 'inline-flex rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90';

    return (
        <section className="space-y-3 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-vazio>
            {listaVisivel ? (
                <>
                    <p className="text-[15px] font-semibold text-white">Os produtos vêm da Lista SKUs</p>
                    <p className="mx-auto max-w-lg text-[13px] text-white/50">Liste os produtos primeiro; cada um aparece aqui para você informar custo e frete.</p>
                    <a href={route('portal.auth.estrutura.lista')} className={classe} data-acao="ir-lista">
                        Ir para a Lista SKUs
                    </a>
                </>
            ) : (
                <>
                    <p className="text-[15px] font-semibold text-white">Os produtos vêm de Produtos e do Planejamento</p>
                    <p className="mx-auto max-w-lg text-[13px] text-white/50">Cadastre os produtos e monte os kits no Planejamento; cada oferta aparece aqui para você informar custo e frete.</p>
                    <a href={route('portal.auth.estrutura.produtos')} className={classe} data-acao="ir-produtos">
                        Ir para Produtos
                    </a>
                </>
            )}
        </section>
    );
}

export default function EstruturaPrecificacao({ empresa, modulos = [], estrutura, precificacao, filtros, ml_conectado = false, frete_tabela = null }) {
    // O vazio só aponta para a Lista SKUs para quem a vê (o servidor decide; `portalSubmodulos`).
    const listaVisivel = submoduloVisivel(modulos, 'lista');
    const [ajustar, setAjustar] = useState(null);   // { oferta, calculo }
    const [aula, setAula] = useState(false);
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [cotando, setCotando] = useState(false);
    const [avisoCotacao, setAvisoCotacao] = useState(null);
    const [fechadas, setFechadas] = useState({});     // família → fechada
    const [recolhidos, setRecolhidos] = useState({}); // produto → combos escondidos

    const { painel, blocos, paginacao } = estrutura;
    const { parametros, padroes, resumo, por_oferta: porOferta } = precificacao;
    const tipo = filtros.tipo ?? null;
    const familias = montarFamilias(blocos, (id) => !! porOferta[id]);
    const onde = ondeEstaCadaOferta(familias);
    const nOfertas = Object.keys(onde).length;
    // Com busca ou tipo escolhido a lista fica rasa e toda aberta: o que casou aparece direto.
    const filtrando = !! (filtros.q || tipo);
    const todasFechadas = familias.length > 0 && familias.every((f) => fechadas[f.chave]);

    // O atalho de "Também entra em" e da composição: abre o que estiver fechado, rola e acende a linha.
    const irParaOferta = {
        pode: (id) => !! onde[id],
        ir: (id) => {
            const lugar = onde[id];
            if (! lugar) return;
            setFechadas((f) => ({ ...f, [lugar.familia]: false }));
            if (lugar.produto !== null) setRecolhidos((r) => ({ ...r, [lugar.produto]: false }));
            setTimeout(() => {
                const linha = document.getElementById(`oferta-${id}`);
                if (! linha) return;
                linha.scrollIntoView({ block: 'center' });
                linha.setAttribute('data-aceso', '');
                setTimeout(() => linha.removeAttribute('data-aceso'), 3500);
            }, 60);
        },
    };

    const visitar = (params) => router.get(route('portal.auth.estrutura.precificacao'), params, {
        preserveState: true, preserveScroll: false, replace: true, only: ['estrutura', 'precificacao', 'filtros'],
    });

    // "Cotar agora": a mesma página com `cotar=1` — o servidor cota na conta do cliente o frete
    // das ofertas DESTA página e devolve o resumo em `cotacao`. `preserveUrl` mantém o endereço
    // limpo; repete sozinho enquanto sobra pendência (o lote do servidor é por chamada).
    const visitarCotando = () => new Promise((resolve) => {
        let resultado = null;
        router.get(route('portal.auth.estrutura.precificacao'), {
            q: filtros.q || undefined, tipo: tipo || undefined, pagina: paginacao.pagina > 1 ? paginacao.pagina : undefined, cotar: 1,
        }, {
            preserveState: true, preserveScroll: true, preserveUrl: true, only: ['precificacao', 'cotacao'],
            onSuccess: (page) => { resultado = page.props.cotacao ?? null; },
            onFinish: () => resolve(resultado),
        });
    });

    const cotarAgora = async () => {
        if (cotando) return;
        setCotando(true);
        setAvisoCotacao(null);
        let c = null;
        for (let voltas = 1; ; voltas++) {
            c = await visitarCotando();
            if (! deveCotarDeNovo(c, voltas)) break;
        }
        setCotando(false);
        setAvisoCotacao(textoDaCotacao(c));
    };

    const primeiraVez = useRef(true);
    useEffect(() => {
        if (primeiraVez.current) { primeiraVez.current = false; return; }
        const t = setTimeout(() => visitar({ q: busca || undefined, tipo: tipo || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Precificação">
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="precificacao" onComoFunciona={() => setAula(true)}
                    descricao="Quanto cobrar em cada produto, no Clássico e no Premium. Informe o custo; o frete do Mercado Envios vem sugerido e você corrige quando o seu for outro. A conta é a da Calculadora de Custo." />

                <ParametrosEmpresa parametros={parametros} padroes={padroes} />

                {painel.ofertas === 0 ? (
                    <EstadoVazio listaVisivel={listaVisivel} />
                ) : (
                    <>
                        <section className="grid grid-cols-2 gap-4 rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-4 sm:grid-cols-4 sm:px-5" data-resumo-precificacao>
                            <Numero valor={resumo.precificadas} rotulo={`de ${resumo.total} com preço`} classe="text-emerald-300" />
                            <Numero valor={resumo.sem_custo} rotulo="sem custo" classe="text-white/60" />
                            <Numero valor={resumo.sem_frete} rotulo="sem frete" classe="text-amber-300" />
                            <Numero valor={resumo.impossivel} rotulo="conta impossível" classe="text-red-300" />
                        </section>

                        <ComoPublicar acrescimo={parametros.acrescimo} />

                        {resumo.impossivel > 0 && (
                            <p className="flex items-center gap-2 rounded-xl border border-red-500/30 bg-red-500/[0.06] px-4 py-3 text-[13px] text-red-200">
                                <AlertTriangle size={15} className="shrink-0" />
                                Comissão + imposto + MC + LL somam 100% ou mais: não existe preço que feche essa conta. Revise os percentuais.
                            </p>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="relative w-full max-w-md">
                                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                                <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU ou nome…"
                                    className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] py-2 pl-8 pr-8 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                    data-busca />
                                {busca && (
                                    <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                        <X size={14} />
                                    </button>
                                )}
                            </div>
                            {ml_conectado && (
                                <Botao onClick={cotarAgora} disabled={cotando} data-acao="cotar-fretes"
                                    title="Pergunta ao Mercado Livre, pela sua conta, o frete dos produtos desta página que estão sem frete digitado">
                                    {cotando ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
                                    {cotando ? 'Cotando…' : 'Cotar agora'}
                                </Botao>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3" data-filtros-de-tipo>
                            <div className="flex flex-wrap gap-1.5">
                                {filtrosDeTipo(painel, tipo).map((t) => (
                                    <button key={t.chave ?? 'todos'} type="button" onClick={() => visitar({ q: busca || undefined, tipo: t.chave || undefined })}
                                        aria-pressed={t.ativo} disabled={t.quantos === 0 && ! t.ativo} data-tipo={t.chave ?? 'todos'}
                                        className={cn('rounded-full border px-3 py-1 text-[12px] disabled:opacity-40',
                                            t.ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/10 font-semibold text-ecf-yellow' : 'border-white/[0.10] text-white/60 hover:border-white/25 hover:text-white')}>
                                        {t.rotulo} <span className="tabular-nums opacity-70">{t.quantos}</span>
                                    </button>
                                ))}
                            </div>
                            {familias.length > 0 && (
                                <div className="flex items-center gap-3 text-[12px] text-white/45">
                                    <span data-resumo-da-pagina>
                                        {nOfertas} {nOfertas === 1 ? 'oferta' : 'ofertas'} · {familias.length} {familias.length === 1 ? 'grupo' : 'grupos'}{paginacao.paginas > 1 ? ' nesta página' : ''}
                                    </span>
                                    {! filtrando && (
                                        <button type="button" data-acao="recolher-tudo" className="text-white/60 underline-offset-4 hover:text-white hover:underline"
                                            onClick={() => setFechadas(todasFechadas ? {} : Object.fromEntries(familias.map((f) => [f.chave, true])))}>
                                            {todasFechadas ? 'Abrir tudo' : 'Recolher tudo'}
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        <p className="text-[12px] leading-relaxed text-white/45" data-explica-frete>
                            Frete em branco vem sugerido para cada tipo, no preço dele: <span className="text-white/65">sugerido pela sua conta</span> quando
                            já cotado no Mercado Livre{ml_conectado ? '' : ' (conecte a sua conta para cotar)'}, ou <span className="text-white/65">estimado pela tabela</span> de
                            custos do Mercado Livre{frete_tabela?.vigente_desde ? ` vigente desde ${dataBr(frete_tabela.vigente_desde)}` : ''}. Digite por cima se o seu frete for outro.
                        </p>

                        {avisoCotacao && (
                            <div role="status" className="flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12.5px] text-white/65" data-aviso-cotacao>
                                <span>{avisoCotacao}</span>
                                <button type="button" onClick={() => setAvisoCotacao(null)} className="text-white/35 hover:text-white" aria-label="Dispensar aviso"><X size={13} /></button>
                            </div>
                        )}

                        <div className="overflow-x-auto rounded-2xl border border-white/[0.08] bg-ecf-card">
                            <table className="w-full min-w-[1140px] table-fixed border-collapse text-left" data-tabela-precos>
                                <colgroup>
                                    <col /><col className="w-[140px]" /><col className="w-[120px]" /><col className="w-[120px]" />
                                    <col className="w-[200px]" /><col className="w-[200px]" /><col className="w-[140px]" /><col className="w-[104px]" />
                                </colgroup>
                                <thead>
                                    <tr className="text-[10.5px] font-semibold uppercase tracking-wider text-white/40">
                                        <th className="px-3 py-2.5">Produto</th>
                                        <th className="px-3 py-2.5 text-right">Custo</th>
                                        <th className="px-3 py-2.5 text-right">Frete Clássico</th>
                                        <th className="px-3 py-2.5 text-right">Frete Premium</th>
                                        <th className="px-3 py-2.5 text-right">Preço Clássico</th>
                                        <th className="px-3 py-2.5 text-right">Preço Premium</th>
                                        <th className="px-3 py-2.5">Situação</th>
                                        <th className="px-3 py-2.5" aria-label="Ajustes" />
                                    </tr>
                                </thead>
                                {familias.map((f) => {
                                    const aberta = filtrando || ! fechadas[f.chave];

                                    return (
                                        <tbody key={f.chave} data-familia={f.chave} data-aberta={aberta ? 'sim' : 'nao'}>
                                            <CabecalhoDaFamilia familia={f} aberta={aberta} fixa={filtrando} pendentes={pendentesDaFamilia(f, porOferta)}
                                                onAlternar={() => setFechadas((x) => ({ ...x, [f.chave]: ! x[f.chave] }))} />
                                            {aberta && linhasDaFamilia(f, { recolhidos, filtrando }).map((l, i) => (l.tipo === 'titulo'
                                                ? <TituloConjuntos key={`titulo-${i}`} />
                                                : (
                                                    <LinhaPreco key={l.oferta.id} oferta={l.oferta} calculo={porOferta[l.oferta.id]} freteTabela={frete_tabela}
                                                        filho={l.filho} aberto={l.aberto} tambemEm={l.tambemEm} itens={l.itens} onIrPara={irParaOferta}
                                                        alternar={l.alternar !== null ? () => setRecolhidos((r) => ({ ...r, [l.alternar]: ! r[l.alternar] })) : null}
                                                        onAjustar={(oferta, calculo) => setAjustar({ oferta, calculo })} />
                                                )))}
                                        </tbody>
                                    );
                                })}
                            </table>
                            {familias.length === 0 && <p className="py-10 text-center text-[13px] text-white/45">Nenhuma oferta com esse filtro.</p>}
                        </div>

                        {paginacao.paginas > 1 && (
                            <Paginacao paginacao={paginacao} onIr={(pagina) => visitar({ q: busca || undefined, tipo: tipo || undefined, pagina })} />
                        )}
                    </>
                )}
            </div>

            <AjustarProduto alvo={ajustar} parametros={parametros} onFechar={() => setAjustar(null)} />
            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
