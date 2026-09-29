import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Percent, Search, SlidersHorizontal, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CLASSE_INPUT, CabecalhoEstrutura, Campo, Paginacao, fmtReais } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
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

const CELULA = 'w-full rounded-lg border border-white/[0.06] bg-white/[0.02] px-2 py-1.5 text-right text-[13px] tabular-nums text-white placeholder:text-white/30 hover:border-white/[0.14] focus:border-ecf-yellow/40 focus:bg-white/[0.04] focus:outline-none focus:ring-0';

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
                        dica={k === 'acrescimo' ? `espaço p/ promoção · padrão ${fmtPct(padroes[k])}` : `padrão ${fmtPct(padroes[k])}`}>
                        <div className="relative">
                            <input value={valores[k]} onChange={(e) => setValores({ ...valores, [k]: e.target.value })} inputMode="decimal"
                                className={cn(CLASSE_INPUT, 'pr-7 text-right tabular-nums')} data-parametro={k} />
                            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-white/35">%</span>
                        </div>
                    </Campo>
                ))}
            </div>
        </section>
    );
}

/** Preço de um tipo: o anunciado em destaque, o mínimo embaixo. */
function Preco({ calculo, tipo }) {
    if (calculo.minimo === null) return <span className="text-[12.5px] text-white/25">—</span>;

    return (
        <span className="block text-right" data-preco={tipo}>
            <span className="block text-[13.5px] font-semibold tabular-nums text-white">{fmtReais(calculo.anunciado)}</span>
            <span className="block text-[11px] tabular-nums text-white/40" title="O preço que paga custo, frete, comissão, imposto, MC e LL — sem o acréscimo">
                mín. {fmtReais(calculo.minimo)} · {fmtPct(calculo.comissao)}
            </span>
        </span>
    );
}

/** Uma linha: a oferta, o custo (digitado ou dos componentes), os fretes e os dois preços. */
function LinhaPreco({ oferta, calculo, onAjustar }) {
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
        const dados = { custo: paraNumero(custo), frete_classico: paraNumero(freteC), frete_premium: paraNumero(freteP) };
        const norma = (v) => (v === '' || v === null || v === undefined ? '' : String(Number(v)));
        const antes = { custo: custoDigitado, frete_classico: calculo.frete_classico, frete_premium: calculo.frete_premium };
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
        <tr className="border-t border-white/[0.06] align-top" data-linha-preco={oferta.id} data-pendencia={calculo.pendencia ?? 'ok'}>
            <td className="px-3 py-2.5">
                <span className="flex items-center gap-2">
                    <span className="truncate font-mono text-[12.5px] font-semibold text-white" title={oferta.sku}>{oferta.sku}</span>
                    <span className="shrink-0 text-[10.5px] text-white/35">{FASE_CURTA[oferta.fase]}</span>
                </span>
                <span className="block truncate text-[11.5px] text-white/40" title={oferta.nome ?? ''}>{oferta.nome}</span>
            </td>
            <td className="px-1.5 py-1.5">
                <input value={custo} onChange={(e) => setCusto(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                    placeholder={temComponentes && calculo.custo.calculado !== null ? paraTexto(calculo.custo.calculado) : 'R$'}
                    className={CELULA} aria-label={`Custo de ${oferta.sku}`} data-celula="custo" />
                {temComponentes && (
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
                    placeholder="R$" className={CELULA} aria-label={`Frete do Clássico de ${oferta.sku}`} data-celula="frete-classico" />
            </td>
            <td className="px-1.5 py-1.5">
                <input value={freteP} onChange={(e) => setFreteP(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                    placeholder="R$" className={CELULA} aria-label={`Frete do Premium de ${oferta.sku}`} data-celula="frete-premium" />
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
            custo: c.custo.origem === 'digitado' ? c.custo.valor : '',
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
                            <input value={valores[k] ?? ''} onChange={(e) => setValores({ ...valores, [k]: e.target.value })} inputMode="decimal"
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

export default function EstruturaPrecificacao({ empresa, modulos = [], estrutura, precificacao, filtros }) {
    const [ajustar, setAjustar] = useState(null);   // { oferta, calculo }
    const [aula, setAula] = useState(false);
    const [busca, setBusca] = useState(filtros.q ?? '');

    const { painel, blocos, paginacao } = estrutura;
    const { parametros, padroes, resumo, por_oferta: porOferta } = precificacao;
    const ofertas = blocos.flatMap((b) => b.ofertas);

    const visitar = (params) => router.get(route('portal.auth.estrutura.precificacao'), params, {
        preserveState: true, preserveScroll: false, replace: true, only: ['estrutura', 'precificacao', 'filtros'],
    });

    const primeiraVez = useRef(true);
    useEffect(() => {
        if (primeiraVez.current) { primeiraVez.current = false; return; }
        const t = setTimeout(() => visitar({ q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Precificação">
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="precificacao" onComoFunciona={() => setAula(true)}
                    descricao="Quanto cobrar em cada produto, no Clássico e no Premium. Informe o custo e o frete; a conta é a da Calculadora de Custo." />

                <ParametrosEmpresa parametros={parametros} padroes={padroes} />

                {painel.ofertas === 0 ? (
                    <section className="space-y-3 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-vazio>
                        <p className="text-[15px] font-semibold text-white">Os produtos vêm da Lista SKUs</p>
                        <p className="mx-auto max-w-lg text-[13px] text-white/50">Liste os produtos primeiro; cada um aparece aqui para você informar custo e frete.</p>
                        <a href={route('portal.auth.estrutura.lista')} className="inline-flex rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90">
                            Ir para a Lista SKUs
                        </a>
                    </section>
                ) : (
                    <>
                        <section className="grid grid-cols-2 gap-4 rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-4 sm:grid-cols-4 sm:px-5" data-resumo-precificacao>
                            <Numero valor={resumo.precificadas} rotulo={`de ${resumo.total} com preço`} classe="text-emerald-300" />
                            <Numero valor={resumo.sem_custo} rotulo="sem custo" classe="text-white/60" />
                            <Numero valor={resumo.sem_frete} rotulo="sem frete" classe="text-amber-300" />
                            <Numero valor={resumo.impossivel} rotulo="conta impossível" classe="text-red-300" />
                        </section>

                        {resumo.impossivel > 0 && (
                            <p className="flex items-center gap-2 rounded-xl border border-red-500/30 bg-red-500/[0.06] px-4 py-3 text-[13px] text-red-200">
                                <AlertTriangle size={15} className="shrink-0" />
                                Comissão + imposto + MC + LL somam 100% ou mais: não existe preço que feche essa conta. Revise os percentuais.
                            </p>
                        )}

                        <div className="relative max-w-md">
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

                        <div className="overflow-x-auto rounded-2xl border border-white/[0.08] bg-ecf-card">
                            <table className="w-full min-w-[1040px] table-fixed border-collapse text-left" data-tabela-precos>
                                <colgroup>
                                    <col /><col className="w-[140px]" /><col className="w-[120px]" /><col className="w-[120px]" />
                                    <col className="w-[150px]" /><col className="w-[150px]" /><col className="w-[140px]" /><col className="w-[104px]" />
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
                                <tbody>
                                    {ofertas.filter((o) => porOferta[o.id]).map((o) => (
                                        <LinhaPreco key={o.id} oferta={o} calculo={porOferta[o.id]} onAjustar={(oferta, calculo) => setAjustar({ oferta, calculo })} />
                                    ))}
                                </tbody>
                            </table>
                            {ofertas.length === 0 && <p className="py-10 text-center text-[13px] text-white/45">Nenhum produto com essa busca.</p>}
                        </div>

                        {paginacao.paginas > 1 && (
                            <Paginacao paginacao={paginacao} onIr={(pagina) => visitar({ q: busca || undefined, pagina })} />
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
