import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { AlertCircle, Check, Copy, ExternalLink, Loader2 } from 'lucide-react';
import { fmtReais } from '@/Components/Portal/Estrutura/comum';
import { CampoPercentual, CampoReais, ResultadoDaOferta } from '@/Components/Portal/Estrutura/Precificacao/SimuladorPrecificacao';
import { avisoSemSugestao } from '@/lib/precificacaoEnvio';
import { freteEmBranco, rotuloDoFrete } from '@/lib/precificacaoFreteSugerido';
import {
    EXCECOES, dadosParaGravar, diferencasDaReferencia, faixaDoCartao, fmtPct, mudouDoGravado, paraNumero, paraTexto, pendentesPrimeiro,
    reaisParaTexto,
} from '@/lib/precificacaoSimulador';
import { cn } from '@/lib/utils';

// ─── Precificação dentro da ficha do produto (20/10/2026; refeita em 21/10) ─
//
// O usuário pediu a precificação "dentro também" da ficha, no desenho do
// simulador do onboarding de Polos. O caminho até aqui, todo pedido dele:
// - 1ª versão: o simulador inteiro da página, com combos e kits → "muito
//   grande e aparece precificação de outros produtos";
// - 2ª: uma tabela enxuta, uma linha por variação → "ficou ruim: não consigo
//   ajustar margem e etc, e não mostra onde está indo o dinheiro";
// - 3ª: o simulador do onboarding para UM produto. Só as variações dele (o
//   servidor não manda combo nem kit — esses ficam na página da Precificação),
//   em botões numa linha, em vez da grade de cartões; e, com mais de uma
//   variação, "Todas as N | Só esta", começando em Todas;
// - esta: "Todas" levava só o CAMPO digitado, e cada variação guardava o resto
//   ("coloco o custo para todas e não precifica igual" — a Verde tinha imposto
//   próprio). Agora "Todas" quer dizer TODAS IGUAIS: o formulário mostra os
//   números da primeira variação (a referência), e qualquer mudança grava o
//   conjunto inteiro — custo, fretes e percentuais — em todas. O que já diverge
//   aparece dito ("Verde: imposto") com o botão "Deixar todas iguais". Ajustar
//   uma variação é o "Só esta", aberto também clicando na variação.
//
// À esquerda o que se gasta (custo, frete do tipo aberto) e os percentuais
// deste produto (comissão, imposto, margem, lucro; em branco = o da empresa);
// à direita o preço em destaque, para onde vai cada real, lucro e margem.
//
// Duas gravações, de propósito:
// - o CUSTO é o da variação — o mesmo campo do cartão dela, acima. Digitar aqui
//   muda o formulário da ficha e grava com "Salvar produto"; enquanto isso o
//   preço já sai com ele (a prévia vai ao servidor com `custos`, que recalcula
//   sem gravar);
// - o FRETE e os PERCENTUAIS gravam na hora, pela rota da Precificação, por
//   JSON: recarregar a página apagaria o formulário aberto.
//
// A conta é do servidor (`EstruturaPrecificacaoService::doProduto`); daqui só
// sai o que se digitou.

const TIPOS = [['classico', 'Clássico'], ['premium', 'Premium']];
const nomeDoTipo = (tipo) => (tipo === 'premium' ? 'Premium' : 'Clássico');
const DIFERENCA_PREMIUM = 5;   // a comissão do Premium acompanha a do Clássico, +5 (regra do onboarding)
const SECAO = 'text-[11px] uppercase tracking-wider text-white/40';
const ROTULO_PEQUENO = 'mb-1 block text-[10px] uppercase tracking-wider text-white/30';

const norma = (v) => (v === '' || v === null || v === undefined ? '' : String(Number(v)));
/** O texto do campo para um valor gravado: vazio quando não há valor (vale o sugerido ou o da empresa). */
const textoDoGravado = (valor, formatar) => (valor === null || valor === undefined || valor === '' ? '' : formatar(Number(valor)));
/** O que o servidor tem gravado na oferta, como texto — a base de toda gravação (o servidor grava a linha inteira). */
const gravadoComoTexto = (c) => ({
    frete_classico: paraTexto(c.frete_classico), frete_premium: paraTexto(c.frete_premium),
    ...Object.fromEntries(EXCECOES.map((k) => [k, paraTexto(c.excecoes?.[k])])),
});

/**
 * O lado de quem digita: custo, frete do tipo aberto e os percentuais da REFERÊNCIA (em "Todas", a primeira
 * variação; em "Só esta", a escolhida). Grava só o que mudou em relação ao que aparece — e quem decide em
 * quantas variações é o `onGravar` de quem chama ("Todas" iguala o conjunto inteiro).
 */
function Entrada({ referencia, tipo, parametros, custo, dicaDoCusto, onCusto, onCustoBlur, onGravar }) {
    const freteK = `frete_${tipo}`;
    const c = referencia.calculo;
    const inicial = () => ({
        frete: textoDoGravado(c[freteK] ?? '', reaisParaTexto),
        ...Object.fromEntries(EXCECOES.map((k) => [k, textoDoGravado(c.excecoes?.[k] ?? '', paraTexto)])),
    });
    const [texto, setTexto] = useState(inicial);
    const [avancado, setAvancado] = useState(true);

    // Relê quando o GRAVADO mudou: a prévia do custo recalcula a conta e não pode apagar o que se digita.
    const assinatura = JSON.stringify([c[freteK], c.excecoes]);
    useEffect(() => { setTexto(inicial()); }, [assinatura]); // eslint-disable-line react-hooks/exhaustive-deps

    const mudar = (chave, valor) => setTexto((t) => {
        const novo = { ...t, [chave]: valor };
        const classico = parseFloat(paraNumero(valor));
        if (chave === 'comissao_classico' && Number.isFinite(classico)) {
            novo.comissao_premium = paraTexto(Math.round((classico + DIFERENCA_PREMIUM) * 100) / 100);
        }

        return novo;
    });

    /** Grava um campo se ele mudou em relação ao que aparecia (o gravado da referência). */
    const gravar = (chave, campo, antes) => {
        const n = paraNumero(texto[chave]);
        if (norma(n) === norma(antes)) return;
        const mudanca = { [campo]: texto[chave] };
        if (campo === 'comissao_classico' && n !== '' && Number.isFinite(Number(n))) mudanca.comissao_premium = texto.comissao_premium;
        onGravar(mudanca);
    };

    const t = c[tipo];
    const placeholderFrete = freteEmBranco(t) ? reaisParaTexto(t.frete) : '0,00';
    const origem = c[freteK] == null ? rotuloDoFrete(t, tipo === 'classico' ? 'Premium' : 'Clássico', fmtReais) : null;
    const semFrete = !! t?.sem_frete;
    const semSugestao = avisoSemSugestao(c.envio, t);
    const corTipo = tipo === 'classico' ? 'text-blue-300' : 'text-violet-300';
    const percentual = (rotulo, k) => (
        <CampoPercentual rotulo={rotulo} chave={k} valor={texto[k]} empresa={parametros?.[k]}
            onChange={(v) => mudar(k, v)} onBlur={() => gravar(k, k, c.excecoes?.[k])} />
    );

    return (
        <div className="space-y-4 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5" data-entrada-produto>
            <p className={SECAO}>Quanto você gasta</p>
            <CampoReais rotulo="Custo do produto" dica={dicaDoCusto} valor={custo} onChange={onCusto} onBlur={onCustoBlur}
                invalido={c.pendencia === 'sem_custo'} data-campo="custo" />

            <div>
                <CampoReais rotulo={`Frete (${nomeDoTipo(tipo)})`} dica="Quanto custa enviar. Em branco vale o sugerido."
                    valor={texto.frete} onChange={(v) => mudar('frete', v)} onBlur={() => gravar('frete', freteK, c[freteK])}
                    placeholder={placeholderFrete} invalido={semFrete} data-campo={`frete-${tipo}`} />
                {origem && <p className="mt-1.5 text-[11.5px] text-white/45" data-frete-origem={origem.tipo}>{origem.texto}</p>}
                {semFrete && (
                    <p className="mt-1.5 flex items-center gap-1.5 text-[12px] font-medium text-red-400">
                        <AlertCircle size={13} className="shrink-0" />
                        Frete não informado{semSugestao ? ` (${semSugestao})` : ''} — informe o frete deste tipo para o preço sair certo.
                    </p>
                )}
            </div>

            <button type="button" onClick={() => setAvancado((v) => ! v)} aria-expanded={avancado}
                className="flex items-center gap-2 pt-1 text-[12px] text-white/40 transition-colors hover:text-white/70">
                <span className={cn('text-[10px] transition-transform', avancado && 'rotate-180')}>▼</span>
                Ajustar percentuais — em branco vale o da empresa
            </button>
            {avancado && (
                <div className="space-y-3 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3" data-percentuais-produto>
                    <p className={cn('text-[11px] font-bold uppercase tracking-wider', corTipo)}>{nomeDoTipo(tipo)} — taxas do site</p>
                    <div className="grid grid-cols-2 gap-2">
                        {percentual('Comissão %', `comissao_${tipo}`)}
                        {percentual('Imposto %', 'imposto')}
                    </div>
                    <p className="pt-1 text-[10px] uppercase tracking-wider text-white/30">Seus alvos (valem p/ Clássico e Premium)</p>
                    <div className="grid grid-cols-3 gap-2">
                        {percentual('Margem Contrib. %', 'margem_contribuicao')}
                        {percentual('Lucro Líquido %', 'lucro_liquido')}
                        <div title="O acréscimo é o mesmo para todos os produtos da empresa">
                            <span className={ROTULO_PEQUENO}>Acréscimo %</span>
                            <div className="flex h-8 items-center rounded-lg border border-white/[0.05] bg-white/[0.02] px-2 text-[12px] tabular-nums text-white/50">
                                {fmtPct(c.percentuais?.acrescimo ?? parametros?.acrescimo)} <span className="ml-1 text-white/30">da empresa</span>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function PrecificacaoDoProduto({ ficha, produtoId = null, nomeDoProduto = '', linhas = [] }) {
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [falhou, setFalhou] = useState(false);
    const [salvando, setSalvando] = useState(0);
    const [erro, setErro] = useState(null);
    const [tipo, setTipo] = useState('classico');
    const [selId, setSelId] = useState(null);
    const [modo, setModo] = useState('todas');   // mais de uma variação: o padrão é "Todas" (21/10)
    const pedido = useRef(0);
    const custoMexido = useRef(false);   // o custo foi digitado em "Todas" desde o último sair do campo

    // O custo que a ficha tem agora, de cada variação já gravada. Texto que não é número fica de fora (vale o salvo).
    const custos = useMemo(() => {
        const r = {};
        for (const v of ficha.vars) {
            if (! v.id) continue;
            const n = paraNumero(v.custo);
            if (n === '') r[v.id] = '';
            else if (Number.isFinite(Number(n)) && Number(n) >= 0) r[v.id] = n;
        }

        return r;
    }, [ficha.vars]);
    const assinatura = JSON.stringify(custos);

    // O custo digitado e ainda não salvo muda o preço já, mas só grava com "Salvar produto": a tela avisa.
    const salvos = useMemo(() => new Map(linhas.map((l) => [l.id, l.custo])), [linhas]);
    const custoPorSalvar = Object.entries(custos).some(([id, n]) => salvos.has(Number(id)) && norma(n) !== norma(salvos.get(Number(id))));

    const carregar = async () => {
        if (! produtoId) return;
        const meu = ++pedido.current;
        setCarregando(true);
        try {
            const r = await axios.get(route('portal.auth.estrutura.produtos.precificacao', produtoId), {
                params: { custos }, headers: { Accept: 'application/json' },
            });
            if (meu === pedido.current) {
                setDados(r.data);
                setFalhou(false);
            }
        } catch {
            if (meu === pedido.current) setFalhou(true);
        } finally {
            if (meu === pedido.current) setCarregando(false);
        }
    };

    // Abre já com a conta; depois, cada custo digitado recalcula com uma pausa curta.
    useEffect(() => {
        if (! produtoId) return undefined;
        const t = setTimeout(carregar, dados ? 400 : 0);

        return () => clearTimeout(t);
    }, [produtoId, assinatura]); // eslint-disable-line react-hooks/exhaustive-deps

    /** Grava uma ou várias ofertas ("Todas" manda uma por variação) e relê a conta uma vez só. */
    const salvarVarias = async (lista) => {
        if (lista.length === 0) return;
        setSalvando((n) => n + 1);
        setErro(null);
        try {
            const r = await Promise.allSettled(lista.map(({ oferta, dados: valores }) => axios.put(
                route('portal.auth.estrutura.precificacao.oferta', oferta.id), valores, { headers: { Accept: 'application/json' } },
            )));
            const falha = r.find((x) => x.status === 'rejected')?.reason;
            if (falha) {
                const erros = falha.response?.data?.errors ?? {};
                setErro(Object.values(erros)[0]?.[0] ?? falha.response?.data?.message ?? 'Não foi possível salvar.');
            }
            await carregar();
        } finally {
            setSalvando((n) => n - 1);
        }
    };

    /** Aplica `mudanca` (campo do servidor → texto) sobre o que cada alvo tem gravado e grava só quem mudou. */
    const gravar = (alvos, mudanca) => salvarVarias(alvos.flatMap((i) => {
        const novo = { ...gravadoComoTexto(i.calculo), ...mudanca };
        const valores = dadosParaGravar({ doProduto: true, freteClassico: novo.frete_classico, fretePremium: novo.frete_premium, excecoes: novo });
        const antes = { frete_classico: i.calculo.frete_classico, frete_premium: i.calculo.frete_premium, ...i.calculo.excecoes };

        return mudouDoGravado(valores, antes) ? [{ oferta: i.oferta, dados: valores }] : [];
    }));

    const itens = useMemo(() => pendentesPrimeiro(dados?.ofertas ?? []), [dados]);
    const resumo = dados?.resumo;
    const parametros = dados?.parametros;
    const porVariacao = new Map(ficha.vars.filter((v) => v.id).map((v) => [v.id, v]));
    const nomeDaVariacao = (i) => porVariacao.get(i.oferta.variacao_id)?.valor || (itens.length > 1 ? i.oferta.sku : 'Variação única');

    // A variação aberta em "Só esta": a escolhida, ou a primeira sem preço (a lista já vem com elas na frente).
    const selecionado = itens.find((i) => i.oferta.id === selId) ?? itens[0] ?? null;
    const varias = itens.length > 1;
    const emMassa = varias && modo === 'todas';
    // A referência de "Todas": a primeira variação do produto (a ordem do cadastro, não a de pendência).
    const referencia = dados?.ofertas?.[0] ?? null;
    const custoDe = (i) => porVariacao.get(i?.oferta.variacao_id)?.custo ?? '';

    /**
     * "Todas" = TODAS IGUAIS: o custo da referência vai para o campo de cada variação do formulário, e o
     * gravado dela (fretes e percentuais), com a `mudanca` por cima, para cada oferta — só as que diferem.
     */
    const unificar = (mudanca = {}) => {
        if (! referencia) return;
        const custoRef = custoDe(referencia);
        ficha.vars.forEach((v) => { if (norma(paraNumero(v.custo)) !== norma(paraNumero(custoRef))) ficha.alterar(v._k, 'custo', custoRef); });
        gravar(itens, { ...gravadoComoTexto(referencia.calculo), ...mudanca });
    };
    const diferencas = emMassa ? diferencasDaReferencia(itens, referencia, custoDe) : [];

    // "Só esta" → "Copiar estes números para as outras": o custo no formulário e o resto gravado na hora.
    const copiarParaAsOutras = () => {
        if (! selecionado) return;
        const outras = itens.filter((i) => i !== selecionado);
        const custoDaOrigem = custoDe(selecionado);
        outras.forEach((i) => { const v = porVariacao.get(i.oferta.variacao_id); if (v) ficha.alterar(v._k, 'custo', custoDaOrigem); });
        gravar(outras, gravadoComoTexto(selecionado.calculo));
    };

    const proxima = ! emMassa && selecionado
        ? (itens.find((i) => i !== selecionado && i.calculo.pendencia)?.oferta.id ?? null)
        : null;
    const faixa = varias ? faixaDoCartao({ membros: itens }, tipo) : null;
    const todasIguais = faixa !== null && faixa.max - faixa.min <= 0.005 && itens.every((i) => ! i.calculo.pendencia);
    const aberta = emMassa ? referencia : selecionado;

    // As props da Entrada nos dois modos: em "Todas" mexe em todas; em "Só esta", só na escolhida.
    const entrada = emMassa ? {
        referencia,
        custo: custoDe(referencia),
        dicaDoCusto: `Vale para as ${itens.length} variações (o campo Custo de cada uma, acima). Grava com "Salvar produto".`,
        onCusto: (texto) => { custoMexido.current = true; ficha.vars.forEach((v) => ficha.alterar(v._k, 'custo', texto)); },
        // Mexeu no custo de todas: os outros números também ficam iguais, senão o preço não sai igual.
        onCustoBlur: () => { if (custoMexido.current) { custoMexido.current = false; unificar(); } },
        onGravar: (mudanca) => unificar(mudanca),
    } : {
        referencia: selecionado,
        custo: custoDe(selecionado),
        dicaDoCusto: 'O custo desta variação (o mesmo campo de Variações, acima). Grava com "Salvar produto".',
        onCusto: (texto) => { const v = porVariacao.get(selecionado?.oferta.variacao_id); if (v) ficha.alterar(v._k, 'custo', texto); },
        onCustoBlur: () => {},
        onGravar: (mudanca) => gravar([selecionado], mudanca),
    };

    return (
        <section className="mt-2.5 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:px-5 lg:pb-4 lg:pt-3" data-precificacao-do-produto>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 className="text-[20px] font-bold text-white">Precificação</h2>
                    {resumo && resumo.total > 0 && (
                        <span className="text-[13px] text-white/55" data-resumo-precificacao-produto>
                            <strong className="font-semibold tabular-nums text-emerald-300">{resumo.precificadas}</strong> de {resumo.total} com preço
                            {resumo.pendentes > 0 && <> · <strong className="font-semibold tabular-nums text-red-300">{resumo.pendentes}</strong> {resumo.pendentes === 1 ? 'falta' : 'faltam'}</>}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-3">
                    <span className="inline-flex items-center gap-1.5 text-[11.5px] text-white/40" data-estado-salvo>
                        {salvando > 0 || carregando
                            ? <><Loader2 size={11} className="animate-spin text-ecf-yellow" /> {salvando > 0 ? 'Salvando…' : 'Calculando…'}</>
                            : erro
                                ? <span className="text-red-300">{erro}</span>
                                : dados && <><span className="h-1.5 w-1.5 rounded-full bg-green-400" /> Salvo automaticamente</>}
                    </span>
                    {dados && itens.length > 0 && (
                        <div className="inline-flex rounded-xl border border-white/[0.08] bg-white/[0.04] p-1" role="group" aria-label="Tipo de anúncio">
                            {TIPOS.map(([k, l]) => (
                                <button key={k} type="button" onClick={() => setTipo(k)} aria-pressed={tipo === k} data-tipo-anuncio={k}
                                    className={cn('rounded-lg px-4 py-1.5 text-[13px] font-semibold transition', tipo === k ? 'bg-ecf-yellow text-black' : 'text-white/50 hover:text-white/80')}>
                                    {l}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
            {custoPorSalvar && (
                <p className="mt-2 rounded-lg border border-ecf-yellow/25 bg-ecf-yellow/[0.06] px-3 py-1.5 text-[12.5px] text-ecf-yellow/90" data-custo-por-salvar>
                    Os preços já usam o custo que você digitou — ele só fica gravado quando você clicar em "Salvar produto".
                </p>
            )}

            {/* Durante o "Salvando…" do produto nada se edita aqui (mesma regra dos outros blocos da ficha). */}
            <fieldset disabled={ficha.salvando} className="mt-3 min-w-0 border-0 p-0" data-campos-precificacao>
                {! produtoId ? (
                    <p className="rounded-xl border border-dashed border-white/[0.12] px-4 py-4 text-center text-[13px] text-white/50" data-precificacao-sem-produto>
                        Salve o produto para ver o preço de cada variação aqui.
                    </p>
                ) : ! dados ? (
                    falhou ? (
                        <p className="rounded-xl border border-red-500/25 bg-red-500/[0.06] px-4 py-3 text-[13px] text-red-200">
                            Não foi possível carregar a precificação.{' '}
                            <button type="button" onClick={carregar} className="font-semibold underline-offset-2 hover:underline">Tentar de novo</button>
                        </p>
                    ) : (
                        <p className="flex items-center justify-center gap-2 py-5 text-[13px] text-white/50"><Loader2 size={15} className="animate-spin" /> Calculando os preços…</p>
                    )
                ) : ! selecionado || ! aberta ? (
                    <p className="py-4 text-center text-[13px] text-white/50">Nenhuma variação gravada ainda.</p>
                ) : (
                    <div className="space-y-3">
                        {varias && (
                            <div className={cn('rounded-xl border p-3', emMassa ? 'border-ecf-yellow/25 bg-ecf-yellow/[0.05]' : 'border-white/[0.08] bg-white/[0.02]')} data-modo-variacoes={modo}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-[13px] text-white/80" data-titulo-modo>
                                        {emMassa
                                            ? <>Precificando as <span className="font-semibold text-ecf-yellow">{itens.length} variações juntas</span> — todas ficam com os mesmos números e o mesmo preço.</>
                                            : <>Precificando <span className="font-semibold text-white">só {nomeDaVariacao(selecionado)}</span> — as outras ficam como estão.</>}
                                    </p>
                                    <div className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.04] p-0.5">
                                        {[['todas', `Todas as ${itens.length}`], ['individual', 'Só uma']].map(([k, l]) => (
                                            <button key={k} type="button" onClick={() => setModo(k)} aria-pressed={modo === k} data-modo={k}
                                                className={cn('rounded-md px-3 py-1 text-[12px] font-semibold transition', modo === k ? 'bg-ecf-yellow text-black' : 'text-white/50 hover:text-white/80')}>
                                                {l}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {/* As variações com o preço de cada uma. Em "Todas", clicar numa abre o "Só uma" nela. */}
                                <div className="mt-2.5 flex flex-wrap items-center gap-1.5" data-variacoes-precificacao>
                                    {itens.map((i) => {
                                        const sel = ! emMassa && i === selecionado;
                                        const pend = !! i.calculo.pendencia;
                                        const preco = ! pend ? i.calculo[tipo]?.minimo : null;
                                        const diferente = diferencas.some((d) => d.item === i);

                                        return (
                                            <button key={i.oferta.id} type="button" onClick={() => { setSelId(i.oferta.id); setModo('individual'); }} aria-pressed={sel} data-variacao-chip={i.oferta.id}
                                                title={emMassa ? `Ajustar só ${nomeDaVariacao(i)}` : i.oferta.sku}
                                                className={cn('inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[12.5px] font-medium transition',
                                                    sel ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/75 hover:bg-white/[0.1]',
                                                    diferente && 'ring-1 ring-inset ring-amber-400/50')}>
                                                {nomeDaVariacao(i)}
                                                <span className={cn('text-[11px] tabular-nums', sel ? 'text-black/60' : 'text-white/45')}>{preco != null ? fmtReais(preco) : 'sem preço'}</span>
                                                {pend ? <AlertCircle size={12} className={sel ? 'text-red-700' : 'text-red-400'} /> : <Check size={12} className={sel ? 'text-black/60' : 'text-emerald-400'} />}
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* O que impede o preço de sair igual, dito por variação, com o botão de igualar. */}
                                {diferencas.length > 0 && (
                                    <div className="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-500/20 bg-amber-500/[0.08] px-2.5 py-2 text-[12px] text-amber-200" data-aviso-divergencia>
                                        <span className="flex items-start gap-1.5">
                                            <AlertCircle size={13} className="mt-0.5 shrink-0" />
                                            <span>
                                                {diferencas.map((d) => `${nomeDaVariacao(d.item)} (${d.campos.join(', ')})`).join(' · ')}
                                                {diferencas.length === 1 ? ' está diferente' : ' estão diferentes'} de {nomeDaVariacao(referencia)}. Ao mudar qualquer número aqui, todas ficam iguais.
                                            </span>
                                        </span>
                                        <button type="button" onClick={() => unificar()} data-acao="deixar-todas-iguais"
                                            className="rounded-lg bg-amber-400 px-2.5 py-1 text-[12px] font-semibold text-black hover:brightness-95">
                                            Deixar todas iguais a {nomeDaVariacao(referencia)}
                                        </button>
                                    </div>
                                )}

                                {! emMassa && (
                                    <button type="button" onClick={copiarParaAsOutras} data-acao="copiar-para-as-outras"
                                        className="mt-2.5 inline-flex items-center gap-1.5 rounded-lg border border-white/[0.12] bg-white/[0.04] px-2.5 py-1 text-[11.5px] font-medium text-white/60 transition hover:border-white/25 hover:text-white">
                                        <Copy size={11} /> Copiar os números de {nomeDaVariacao(selecionado)} para as outras {itens.length - 1}
                                    </button>
                                )}
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <Entrada key={`${emMassa ? 'todas' : aberta.oferta.id}-${tipo}`} tipo={tipo} parametros={parametros} {...entrada} />
                            <ResultadoDaOferta membro={aberta} tipo={tipo}
                                titulo={emMassa ? `Todas as ${itens.length} variações` : (varias ? nomeDaVariacao(aberta) : null)}
                                proxima={proxima} onProxima={() => proxima !== null && setSelId(proxima)}
                                nota={emMassa && faixa && (todasIguais
                                    ? <p className="mt-1 text-[12px] text-emerald-300/80" data-nota-todas>As {itens.length} variações saem por este preço.</p>
                                    : <p className="mt-1 text-[12px] text-amber-200/85" data-nota-todas>Hoje elas ainda não estão iguais: de {fmtReais(faixa.min)} a {fmtReais(faixa.max)}.</p>)} />
                        </div>
                    </div>
                )}
            </fieldset>

            {produtoId && (
                <a href={route('portal.auth.estrutura.precificacao', { q: nomeDoProduto || undefined })} target="_blank" rel="noopener noreferrer"
                    className="mt-2.5 inline-flex items-center gap-1.5 text-[12.5px] text-white/50 hover:text-ecf-yellow" data-acao="abrir-precificacao">
                    Combos e kits deste produto ficam na Precificação <ExternalLink size={12} />
                </a>
            )}
        </section>
    );
}
