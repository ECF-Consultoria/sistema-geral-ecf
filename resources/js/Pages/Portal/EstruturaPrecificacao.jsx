import { Fragment, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronDown, ChevronRight, Copy, List, Loader2, Percent, RefreshCw, Search, SlidersHorizontal, Target, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CLASSE_INPUT, CabecalhoEstrutura, Campo, Paginacao, fmtReais } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import SimuladorPrecificacao from '@/Components/Portal/Estrutura/Precificacao/SimuladorPrecificacao';
import { avisoSemSugestao, resumoDoEnvio, seloDoEnvio } from '@/lib/precificacaoEnvio';
import { deveCotarDeNovo, freteEmBranco, freteUnico, legendaDoFreteUnico, rotuloDoFrete, textoDaCotacao } from '@/lib/precificacaoFreteSugerido';
import { cartoesDaFamilia, filtrosDeTipo, montarFamilias, ondeEstaCadaOferta, pendentesDaFamilia, resumoDaFamilia, rotuloDaFase } from '@/lib/precificacaoPorFamilia';
import { cartoesDasFamilias } from '@/lib/precificacaoSimulador';
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
//   LL e acréscimo — com exceção por produto, no "Ajustar percentuais".
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
// A lista vem em grupos por família (a do cadastro de Produtos): cada produto uma
// vez, com os combos dele; kit e combit em "Conjuntos desta família", também uma vez
// só, e o produto que entra neles diz "Também entra em". O que não tem família fica
// em "Sem família". Quem agrupa e ordena é o servidor, antes de paginar; aqui é só
// desenho (`lib/precificacaoPorFamilia.js`).
//
// ### Linhas enxutas (13/10/2026)
// A tabela por família ficou "muito ruim e confusa": seis linhas de letra miúda por
// oferta, quatro preços com o mesmo peso e o motivo do envio repetido em todo combo.
// O usuário escolheu, entre quatro propostas, a que põe a unidade e os combos dela no
// MESMO cartão, uma linha por oferta. O que mudou no desenho:
// - a linha mostra só o essencial: código, custo, UM campo de frete (quando os dois
//   tipos valem o mesmo — `freteUnico`), o preço da promoção em destaque com o de
//   anunciar embaixo, e a situação;
// - a comissão saiu de cada linha e foi para o cabeçalho da coluna (é a da empresa);
//   o produto com percentuais próprios leva o selo "Ajustado";
// - o envio virou etiqueta ("Full", "ME1"); pacote, limites, os dois fretes com a
//   origem de cada um, os quatro preços para copiar e o "Ajustar percentuais" abrem
//   no detalhe da linha;
// - os parâmetros da empresa ficam numa linha, com "Editar".
// Nenhuma conta mudou e nada novo vem do servidor.
//
// ### Simulador, como no onboarding de Polos (20/10/2026)
// O usuário pediu a tela "igual à do onboarding": a vista padrão virou o SIMULADOR
// (`SimuladorPrecificacao`) — cartões em cima, uma oferta por vez embaixo, o preço em
// destaque e para onde vai cada real. A lista de antes continua como "Lista", o
// "Lote" do onboarding. E o que falta preço vem primeiro: o servidor põe na frente,
// antes de paginar, as famílias com pendência; o simulador recolhe o que já tem preço
// em "Já precificados", e na Lista a linha pronta fica apagada.
//
// `?vista=lista|simulador` abre direto numa das duas; a escolha fica no navegador.

const VISTAS = [['simulador', 'Simulador', Target], ['lista', 'Lista', List]];
const CHAVE_VISTA = 'ecf.portal.precificacao.vista';
const vistaGuardada = () => {
    try {
        const v = window.localStorage.getItem(CHAVE_VISTA);

        return VISTAS.some(([k]) => k === v) ? v : null;
    } catch {
        return null;
    }
};
const guardarVista = (v) => {
    try { window.localStorage.setItem(CHAVE_VISTA, v); } catch { /* sem armazenamento: vale só nesta visita */ }
};

const PARAMETROS = [
    ['comissao_classico', 'Comissão Clássico', 'Comissão Clássico'],
    ['comissao_premium', 'Comissão Premium', 'Comissão Premium'],
    ['imposto', 'Imposto', 'Imposto'],
    ['margem_contribuicao', 'Margem de contribuição', 'MC'],
    ['lucro_liquido', 'Lucro líquido', 'LL'],
    ['acrescimo', 'Acréscimo', 'Acréscimo'],
];

const PENDENCIA = {
    sem_custo:  { rotulo: 'Sem custo', classe: 'bg-white/[0.07] text-white/65' },
    sem_frete:  { rotulo: 'Sem frete', classe: 'bg-amber-500/[0.14] text-amber-300' },
    impossivel: { rotulo: 'Conta impossível', classe: 'bg-red-500/[0.14] text-red-300' },
};

const TIPOS_DE_ANUNCIO = [['classico', 'Clássico'], ['premium', 'Premium']];

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

const CELULA = 'w-full rounded-lg border border-white/[0.10] bg-white/[0.03] px-2 py-1.5 text-right text-[13px] tabular-nums text-white placeholder:text-white/40 hover:border-white/[0.18] focus:border-ecf-yellow/40 focus:bg-white/[0.05] focus:outline-none focus:ring-0';
const CELULA_ALERTA = 'border-amber-400/50 bg-amber-500/[0.06] placeholder:text-amber-200/60';
const SELO = 'shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold';
const LEGENDA = 'mt-0.5 block text-right text-[11px]';
const ROTULO = 'text-[10.5px] font-semibold uppercase tracking-wider text-white/45';

// As colunas da lista: oferta, custo, frete, Clássico, Premium, situação e o botão do detalhe. O cabeçalho e
// cada linha usam a MESMA grade, senão os números desalinham entre um cartão e outro.
const COLUNAS = 'grid grid-cols-[minmax(0,1fr)_128px_144px_160px_160px_124px_32px] items-center gap-x-4 px-4';

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

/**
 * O conjunto da empresa: seis percentuais, salvos juntos. Fechado, é uma linha com os valores; "Editar" abre os
 * campos. Eles mudam raramente e ocupavam a primeira dobra inteira da tela (13/10/2026).
 */
function ParametrosEmpresa({ parametros, padroes }) {
    const [valores, setValores] = useState(() => Object.fromEntries(PARAMETROS.map(([k]) => [k, paraTexto(parametros[k])])));
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);
    const [editando, setEditando] = useState(false);

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
            onSuccess: () => setEditando(false),
            onError: setErros,
        });

    const cancelar = () => {
        setValores(Object.fromEntries(PARAMETROS.map(([k]) => [k, paraTexto(parametros[k])])));
        setErros({});
        setEditando(false);
    };

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-3 sm:px-5" data-parametros-empresa data-editando={editando ? 'sim' : 'nao'}>
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div className="flex min-w-0 flex-wrap items-center gap-x-5 gap-y-1.5">
                    <h2 className="flex items-center gap-2 text-[13.5px] font-semibold text-white"><Percent size={15} className="text-ecf-yellow" /> Parâmetros da empresa</h2>
                    {! editando && PARAMETROS.map(([k, , curto]) => (
                        <span key={k} className="whitespace-nowrap text-[12.5px] text-white/55" data-parametro-resumo={k}>
                            {curto} <strong className="font-semibold tabular-nums text-white">{fmtPct(parametros[k])}</strong>
                        </span>
                    ))}
                </div>
                {editando ? (
                    <div className="flex items-center gap-2">
                        <Botao variante="fantasma" onClick={cancelar} disabled={enviando}>Cancelar</Botao>
                        <Botao variante="primario" onClick={salvar} disabled={! mudou || enviando} data-acao="salvar-parametros">
                            {enviando && <Loader2 size={14} className="animate-spin" />} Salvar parâmetros
                        </Botao>
                    </div>
                ) : (
                    <Botao onClick={() => setEditando(true)} data-acao="editar-parametros"><SlidersHorizontal size={14} /> Editar</Botao>
                )}
            </div>
            {editando && (
                <>
                    <p className="mt-1 text-[12px] text-white/50">Valem para todos os produtos. Um produto diferente se ajusta no detalhe da linha dele.</p>
                    <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
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
                </>
            )}
        </section>
    );
}

/**
 * Copia o valor no formato que o Mercado Livre aceita ("131.22"). Sozinho, é o ícone ao lado do valor; com
 * `children`, o próprio valor é o botão e o ícone aparece ao passar o mouse, fora da coluna — o número
 * continua alinhado à direita com os das outras linhas.
 */
function Copiar({ valor, rotulo, children = null, className, ...props }) {
    const [copiado, setCopiado] = useState(false);
    const copiar = () => {
        try {
            navigator.clipboard.writeText(Number(valor).toFixed(2));
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        } catch { /* sem clipboard: o valor segue visível ao lado */ }
    };
    const Icone = copiado ? Check : Copy;

    if (children === null) {
        return (
            <button type="button" onClick={copiar} title={`Copiar ${rotulo}`} aria-label={`Copiar ${rotulo}`}
                className={cn('shrink-0 rounded p-1 text-white/40 transition hover:bg-white/[0.06] hover:text-white', className)} {...props}>
                <Icone size={13} className={copiado ? 'text-emerald-400' : undefined} />
            </button>
        );
    }

    return (
        <button type="button" onClick={copiar} title={`Copiar ${rotulo}`}
            className={cn('group/copiar relative inline-flex items-center rounded transition hover:text-ecf-yellow focus-visible:text-ecf-yellow', className)} {...props}>
            {children}
            <Icone size={11} aria-hidden="true"
                className={cn('absolute left-full top-1/2 ml-1 -translate-y-1/2', copiado
                    ? 'text-emerald-400'
                    : 'text-white/50 opacity-0 group-hover/copiar:opacity-100 group-focus-visible/copiar:opacity-100')} />
        </button>
    );
}

/**
 * Os dois preços de um tipo, na linha: o da PROMOÇÃO em destaque — é o que o comprador paga e o que fecha a
 * conta — e o de ANUNCIAR (com o acréscimo) embaixo. Clicar em qualquer um copia. Sem o frete o preço aparece
 * apagado e não se copia: ainda não é o preço.
 */
function PrecoDaLinha({ calculo, tipo, nome, semFrete }) {
    if (calculo.minimo === null) return <span className="block text-right text-[13px] text-white/30">—</span>;

    if (semFrete) {
        return (
            <span className="block text-right" data-preco={tipo} data-incompleto>
                <span className="block text-[15px] font-bold tabular-nums text-white/45">{fmtReais(calculo.minimo)}</span>
                <span className="block text-[11.5px] text-amber-200/75">ainda sem o frete</span>
            </span>
        );
    }

    return (
        <span className="block text-right" data-preco={tipo}>
            <Copiar valor={calculo.minimo} rotulo={`o preço da promoção do ${nome}`} className="text-[15px] font-bold tabular-nums text-white" data-promocao>
                {fmtReais(calculo.minimo)}
            </Copiar>
            <span className="flex items-center justify-end gap-1 text-[11.5px] text-white/50">
                anunciar
                <Copiar valor={calculo.anunciado} rotulo={`o preço de anunciar do ${nome}`} className="tabular-nums text-white/75" data-anunciar>
                    {fmtReais(calculo.anunciado)}
                </Copiar>
            </span>
        </span>
    );
}

/** Os quatro preços no detalhe, cada um com o botão de copiar e a comissão do tipo (a do produto, se ajustada). */
function PrecosParaCopiar({ calculo }) {
    return (
        <div className="grid grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)] items-center gap-x-4 gap-y-2 text-[13px]" data-precos-para-copiar>
            <span />
            <span className="text-right text-[11.5px] text-white/50">Anunciar</span>
            <span className="text-right text-[11.5px] text-white/50">Promoção</span>
            {TIPOS_DE_ANUNCIO.map(([chave, nome]) => {
                const c = calculo[chave];

                return (
                    <Fragment key={chave}>
                        <span className="text-white/70">
                            {nome}
                            <span className="block text-[11px] tabular-nums text-white/45">comissão {fmtPct(c.comissao)}</span>
                        </span>
                        {c.minimo === null ? (
                            <>
                                <span className="text-right text-white/30">—</span>
                                <span className="text-right text-white/30">—</span>
                            </>
                        ) : (
                            <>
                                <span className="flex items-center justify-end gap-1 tabular-nums text-white/80">
                                    {fmtReais(c.anunciado)} <Copiar valor={c.anunciado} rotulo={`o preço de anunciar do ${nome}`} />
                                </span>
                                <span className="flex items-center justify-end gap-1 font-bold tabular-nums text-white">
                                    {fmtReais(c.minimo)} <Copiar valor={c.minimo} rotulo={`o preço da promoção do ${nome}`} />
                                </span>
                            </>
                        )}
                    </Fragment>
                );
            })}
        </div>
    );
}

/** Como publicar com esses dois preços — o mesmo passo a passo do link do Publicador — e o que é o frete sugerido. */
function ComoPublicar({ acrescimo, mlConectado, freteTabela }) {
    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-3.5 sm:px-5" data-como-publicar>
            <p className="text-[13px] font-semibold text-white">Como usar os dois preços</p>
            <ol className="mt-1.5 space-y-1 text-[12.5px] leading-relaxed text-white/60">
                <li>
                    <span className="font-semibold text-white/80">1.</span> Publique o anúncio no Mercado Livre pelo preço{' '}
                    <span className="font-semibold text-white">Anunciar</span> — ele já tem os {fmtPct(acrescimo)} de acréscimo.
                </li>
                <li>
                    <span className="font-semibold text-white/80">2.</span> Na <span className="font-semibold text-white/80">Central de Promoções</span>, crie o desconto
                    até o preço da <span className="font-semibold text-white">Promoção</span> (o número grande da linha), que é o valor sem os {fmtPct(acrescimo)}.
                </li>
                <li>
                    <span className="font-semibold text-white/80">3.</span> O comprador vê o produto com desconto, e o preço da promoção ainda paga custo, frete, comissão,
                    imposto e as margens dos parâmetros. Não desça abaixo dele. Clique em um preço da linha para copiá-lo no formato do Mercado Livre.
                </li>
            </ol>
            <p className="mt-2.5 border-t border-white/[0.06] pt-2.5 text-[12.5px] leading-relaxed text-white/55" data-explica-frete>
                Frete em branco vem sugerido para cada tipo, no preço dele: <span className="text-white/75">sugerido pela sua conta</span> quando
                já cotado no Mercado Livre{mlConectado ? '' : ' (conecte a sua conta para cotar)'}, ou <span className="text-white/75">estimado pela tabela</span> de
                custos do Mercado Livre{freteTabela?.vigente_desde ? ` vigente desde ${dataBr(freteTabela.vigente_desde)}` : ''}. Digite por cima se o seu frete for outro:
                o campo da linha vale para os dois tipos, e no detalhe dá para pôr um frete em cada um.
            </p>
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
        : (s?.fonte === 'conta'
            ? 'Cotado na sua conta do Mercado Livre, no preço deste tipo de anúncio.'
            : `Tabela de custos de envio do Mercado Livre${vigencia}${reputacao}.`)
            + (r.alerta ? ' O Mercado Livre cobra mais do que o frete digitado: a margem fica menor que a planejada. O valor digitado não é trocado.' : '');

    return (
        <span className={cn(LEGENDA, r.alerta ? 'text-amber-300' : 'text-white/45')} title={dica}
            data-frete-origem={r.tipo} data-frete-fonte={r.fonte ?? ''} data-frete-alerta={r.alerta ? 'sim' : undefined}>
            {r.texto}
        </span>
    );
}

const TOM_DO_ENVIO = { ok: 'text-white/80', neutro: 'text-white/80', alerta: 'text-amber-300' };
const TOM_DO_SELO = { ok: 'bg-white/[0.07] text-white/70', neutro: 'bg-white/[0.07] text-white/70', alerta: 'bg-amber-500/[0.14] text-amber-300' };

/** O envio em uma etiqueta ao lado do código; o texto inteiro fica no balão e no detalhe da linha. */
function SeloDoEnvio({ envio }) {
    const s = seloDoEnvio(envio);
    if (! s) return null;

    return <span className={cn(SELO, TOM_DO_SELO[s.tom])} title={s.dica} data-selo-envio={s.chave}>{s.texto}</span>;
}

/**
 * O envio da oferta por extenso, no detalhe: o que ela é (Mercado Envios, fora dele, faltam medidas), o pacote —
 * o SOMADO, quando é combo ou kit — e, fora do Mercado Envios, por quê. Sem isto a oferta sem frete sugerido
 * ficava com o campo em branco e nenhuma explicação (11/10/2026).
 */
function EnvioDaOferta({ envio }) {
    const r = resumoDoEnvio(envio);
    if (! r) return null;

    return (
        <span className="block text-[12.5px] leading-snug text-white/55" data-envio={r.chave} title={r.dica ?? undefined}>
            <span className={cn('block font-semibold', TOM_DO_ENVIO[r.tom])}>{r.rotulo}</span>
            {r.pacote && <span className="block" data-envio-pacote>{r.pacote}</span>}
            {r.motivo && r.tom === 'alerta' && <span className="block text-amber-200/75" data-envio-motivo>{r.motivo}</span>}
        </span>
    );
}

/** Sob o campo de frete em branco e sem nada a sugerir: por que não há valor ali. */
function SemSugestaoDeFrete({ envio, tipo }) {
    const texto = avisoSemSugestao(envio, tipo);
    if (! texto) return null;

    return <span className={cn(LEGENDA, 'text-amber-200/75')} title={resumoDoEnvio(envio)?.dica ?? undefined} data-frete-sem-sugestao>{texto}</span>;
}

/** Sob o campo ÚNICO de frete da linha: a origem do valor ou, sem nada a sugerir, por que está em branco. */
function LegendaDoFreteUnico({ calculo }) {
    const r = legendaDoFreteUnico(calculo, fmtReais);
    if (r) {
        return (
            <span className={cn(LEGENDA, 'truncate', r.alerta ? 'text-amber-300' : 'text-white/45')} title={r.texto}
                data-frete-origem={r.tipo} data-frete-alerta={r.alerta ? 'sim' : undefined}>{r.texto}</span>
        );
    }

    const aviso = avisoSemSugestao(calculo.envio, calculo.classico) ?? avisoSemSugestao(calculo.envio, calculo.premium);

    return aviso ? <span className={cn(LEGENDA, 'text-amber-200/75')} title={resumoDoEnvio(calculo.envio)?.dica ?? undefined} data-frete-sem-sugestao>{aviso}</span> : null;
}

/** Um atalho para outra oferta da lista. Fora desta página (ou do filtro) é só o texto. */
function Atalho({ alvo, rotulo, onIrPara }) {
    const classe = 'max-w-full truncate rounded-md border border-white/[0.10] bg-white/[0.03] px-2 py-0.5 text-[11.5px] text-white/70';
    const dica = alvo.nome ? `${alvo.nome} (${alvo.sku})` : alvo.sku;
    if (! onIrPara?.pode(alvo.id)) return <span className={classe} title={dica}>{rotulo}</span>;

    return (
        <button type="button" onClick={() => onIrPara.ir(alvo.id)} title={`Ir para ${dica}`} data-acao="ir-para-oferta"
            className={cn(classe, 'hover:border-ecf-yellow/40 hover:text-white')}>
            {rotulo}
        </button>
    );
}

/** O título de uma família: abre e fecha o grupo e resume o que há nele. Os cartões dos produtos vêm embaixo. */
function CabecalhoDaFamilia({ familia, aberta, pendentes, fixa, onAlternar }) {
    const Seta = aberta ? ChevronDown : ChevronRight;

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-1 pt-2" data-familia-cabecalho={familia.chave}>
            <button type="button" onClick={onAlternar} disabled={fixa} aria-expanded={aberta} data-acao="alternar-familia"
                className="flex min-w-0 flex-wrap items-baseline gap-x-2.5 gap-y-1 text-left disabled:cursor-default">
                <Seta size={15} className={cn('shrink-0 self-center', fixa ? 'text-white/20' : 'text-white/50')} />
                <span className={cn('font-display text-[15px] font-bold', familia.semFamilia ? 'text-white/65' : 'text-white')}>{familia.nome}</span>
                <span className="text-[12px] text-white/50">{resumoDaFamilia(familia)}</span>
                {familia.semFamilia && <span className="text-[12px] text-white/40">Defina a família na ficha do produto para agrupar.</span>}
            </button>
            <span className="flex-1" />
            {pendentes > 0 && (
                <span className={cn(SELO, 'bg-amber-500/[0.14] text-amber-300')} data-pendentes={pendentes}>
                    {pendentes} {pendentes === 1 ? 'pendente' : 'pendentes'}
                </span>
            )}
        </div>
    );
}

/** Onde começam os kits e combits da família. */
function TituloConjuntos() {
    return (
        <p className="flex flex-wrap items-baseline gap-x-2 px-1 pt-1" data-titulo-conjuntos>
            <span className="text-[12px] font-semibold text-white/70">Conjuntos desta família</span>
            <span className="text-[11.5px] text-white/40">kits e combits, que juntam mais de um produto</span>
        </p>
    );
}

/**
 * Uma oferta em uma linha: código, custo (digitado ou dos componentes), frete, os dois preços e a situação. O
 * detalhe — envio por extenso, um frete por tipo, os quatro preços para copiar, do que é feita e o "Ajustar
 * percentuais" — abre embaixo. `filho` = combo no cartão do produto dele; `tambemEm` e `itens` são os atalhos
 * (em que conjuntos o produto entra / do que o conjunto é feito).
 */
function LinhaOferta({ oferta, calculo, onAjustar, freteTabela, filho = false, tambemEm = [], itens = [], onIrPara = null }) {
    // D-10: na oferta que veio do Produtos o custo mora no produto (167-08); a
    // tela mostra o valor e não manda custo no salvar (o servidor o recusaria).
    const doProduto = calculo.do_produto === true;
    const [custo, setCusto] = useState(paraTexto(calculo.custo.origem === 'digitado' ? calculo.custo.valor : null));
    const [freteC, setFreteC] = useState(paraTexto(calculo.frete_classico));
    const [freteP, setFreteP] = useState(paraTexto(calculo.frete_premium));
    const [erro, setErro] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [aberto, setAberto] = useState(false);

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
    const semFrete = calculo.pendencia === 'sem_frete';
    const ajustado = Object.values(calculo.excecoes).some((v) => v !== null);

    // Um campo de frete ou dois? Quem decide é o que o SERVIDOR tem gravado, não o que se digita agora: senão o
    // campo trocaria de forma no meio da digitação e o cursor se perderia.
    const sugeridoC = placeholderFrete(calculo.classico);
    const sugeridoP = placeholderFrete(calculo.premium);
    const sugeridoUnico = sugeridoC === sugeridoP ? sugeridoC : (sugeridoC !== 'R$' && sugeridoP !== 'R$' ? `${sugeridoC} · ${sugeridoP}` : null);
    const gravado = freteUnico(paraTexto(calculo.frete_classico), paraTexto(calculo.frete_premium), calculo);
    const umCampo = gravado.unico && (gravado.valor !== '' || sugeridoUnico !== null);
    const digitarNosDois = (e) => { setFreteC(e.target.value); setFreteP(e.target.value); };

    const custoDaOrigem = calculo.custo.origem === 'componentes' ? 'soma dos componentes'
        : calculo.custo.origem === 'digitado' ? (calculo.custo.calculado !== null ? `componentes: ${fmtReais(calculo.custo.calculado)}` : 'digitado')
            : 'falta o custo de um componente';

    return (
        // Pronta, a linha fica apagada (20/10/2026): o olho vai para o que falta. Volta inteira ao passar o mouse ou editar.
        <div id={`oferta-${oferta.id}`} className={cn('scroll-mt-24 border-white/[0.06] transition-opacity [&:not(:first-child)]:border-t', filho && 'bg-white/[0.018]',
            ! calculo.pendencia && ! salvando && 'opacity-60 focus-within:opacity-100 hover:opacity-100')}
            data-linha-preco={oferta.id} data-pendencia={calculo.pendencia ?? 'ok'} data-nivel={filho ? 'combo' : 'produto'}>
            <div className={cn(COLUNAS, 'py-3')}>
                <div className={cn('min-w-0', filho && 'pl-4')}>
                    {/* O código nunca é cortado (é por ele que se acha o produto): quebra de linha. */}
                    <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        {oferta.fase !== 'simples' && <span className={cn(SELO, 'bg-white/[0.07] text-white/75')} data-fase={oferta.fase}>{rotuloDaFase(oferta)}</span>}
                        <span className={cn('min-w-0 break-words font-mono text-[13px] text-white', ! filho && 'font-semibold')} title={filho ? (oferta.nome ?? undefined) : undefined}>{oferta.sku}</span>
                        <SeloDoEnvio envio={calculo.envio} />
                    </span>
                    {! filho && <span className="mt-0.5 block truncate text-[12.5px] text-white/55" title={oferta.nome ?? ''}>{oferta.nome}</span>}
                </div>

                <div>
                    {doProduto ? (
                        <span className="block text-right" data-custo-do-produto>
                            <span className="block text-[13px] tabular-nums text-white">
                                {calculo.custo.valor !== null && calculo.custo.valor !== undefined ? fmtReais(calculo.custo.valor) : 'sem custo'}
                            </span>
                            <a href={route('portal.auth.estrutura.produtos', { q: oferta.sku })} title="Alterar no Produtos" data-acao="alterar-no-produtos"
                                className="text-[11px] text-white/45 underline-offset-2 hover:text-ecf-yellow hover:underline">
                                vem do produto
                            </a>
                        </span>
                    ) : (
                        <input value={custo} onChange={(e) => setCusto(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                            placeholder={temComponentes && calculo.custo.calculado !== null ? paraTexto(calculo.custo.calculado) : 'R$'}
                            className={cn(CELULA, calculo.pendencia === 'sem_custo' && CELULA_ALERTA)} aria-label={`Custo de ${oferta.sku}`} data-celula="custo" />
                    )}
                    {! doProduto && temComponentes && (
                        <span className={cn(LEGENDA, 'truncate text-white/45')} title={custoDaOrigem} data-custo-origem={calculo.custo.origem ?? ''}>{custoDaOrigem}</span>
                    )}
                </div>

                <div data-frete-da-linha={umCampo ? 'unico' : 'por-tipo'}>
                    {umCampo ? (
                        <>
                            <input value={freteC !== '' ? freteC : freteP} onChange={digitarNosDois} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                                placeholder={sugeridoUnico ?? 'R$'} className={cn(CELULA, semFrete && CELULA_ALERTA)}
                                aria-label={`Frete de ${oferta.sku}, no Clássico e no Premium`} data-celula="frete" />
                            <LegendaDoFreteUnico calculo={calculo} />
                        </>
                    ) : (
                        <>
                            <span className="grid grid-cols-2 gap-1.5">
                                {[['C', 'Clássico', freteC, setFreteC, sugeridoC], ['P', 'Premium', freteP, setFreteP, sugeridoP]].map(([letra, nome, valor, mudar, sugerido]) => (
                                    <span key={letra} className="relative block">
                                        <span aria-hidden="true" className="pointer-events-none absolute left-1.5 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-white/45">{letra}</span>
                                        <input value={valor} onChange={(e) => mudar(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                                            placeholder={sugerido} className={cn(CELULA, 'pl-4 pr-1.5 text-[12.5px]', semFrete && CELULA_ALERTA)}
                                            aria-label={`Frete do ${nome} de ${oferta.sku}`} data-celula={`frete-${letra === 'C' ? 'classico' : 'premium'}`} />
                                    </span>
                                ))}
                            </span>
                            <span className={cn(LEGENDA, 'text-white/45')}>um frete por tipo</span>
                        </>
                    )}
                </div>

                <PrecoDaLinha calculo={calculo.classico} tipo="classico" nome="Clássico" semFrete={semFrete} />
                <PrecoDaLinha calculo={calculo.premium} tipo="premium" nome="Premium" semFrete={semFrete} />

                <div className="flex flex-col items-start gap-1">
                    {salvando
                        ? <Loader2 size={14} className="animate-spin text-white/40" />
                        : pendencia
                            ? <span className={cn(SELO, pendencia.classe)}>{pendencia.rotulo}</span>
                            : <span className={cn(SELO, 'bg-emerald-500/10 text-emerald-300')}>Precificado</span>}
                    {ajustado && <span className={cn(SELO, 'border border-ecf-yellow/40 text-ecf-yellow')} title="Este produto tem percentuais próprios" data-ajustado>Ajustado</span>}
                </div>

                <button type="button" onClick={() => setAberto((a) => ! a)} aria-expanded={aberto} data-acao="alternar-detalhe"
                    aria-label={`${aberto ? 'Fechar' : 'Abrir'} o detalhe de ${oferta.sku}`} title={aberto ? 'Fechar o detalhe' : 'Envio, um frete por tipo e preços para copiar'}
                    className={cn('flex h-8 w-8 items-center justify-center rounded-lg transition', aberto ? 'bg-ecf-yellow/10 text-ecf-yellow' : 'text-white/45 hover:bg-white/[0.06] hover:text-white')}>
                    <ChevronDown size={16} className={cn('transition-transform', aberto && 'rotate-180')} />
                </button>
            </div>

            {erro && <p className="px-4 pb-2 text-right text-[11.5px] text-red-300">{erro}</p>}

            {aberto && (
                <div className={cn('grid gap-x-8 gap-y-5 border-t border-white/[0.06] bg-white/[0.025] px-4 pb-5 pt-4 md:grid-cols-3', filho && 'pl-8')} data-detalhe={oferta.id}>
                    <div className="space-y-3">
                        <div>
                            <p className={ROTULO}>Envio</p>
                            <div className="mt-1.5">
                                {resumoDoEnvio(calculo.envio)
                                    ? <EnvioDaOferta envio={calculo.envio} />
                                    : <span className="text-[12.5px] text-white/45">Sem medidas para dizer o envio.</span>}
                            </div>
                        </div>
                        {filho && oferta.nome && <p className="text-[12.5px] text-white/55">{oferta.nome}</p>}
                        {itens.length > 0 && (
                            <div data-composicao>
                                <p className={ROTULO}>Feito de</p>
                                <span className="mt-1.5 flex flex-wrap gap-1.5">
                                    {itens.map((c) => <Atalho key={c.id} alvo={c} rotulo={`${c.quantidade}× ${c.nome ?? c.sku}`} onIrPara={onIrPara} />)}
                                </span>
                            </div>
                        )}
                        {tambemEm.length > 0 && (
                            <div data-tambem-em>
                                <p className={ROTULO}>Também entra em</p>
                                <span className="mt-1.5 flex flex-wrap gap-1.5">
                                    {/* Tipo + código: o nome do conjunto é comprido demais para caber aqui (vai na dica). */}
                                    {tambemEm.map((k) => <Atalho key={k.id} alvo={k} rotulo={`${rotuloDaFase(k)} ${k.sku}`.trim()} onIrPara={onIrPara} />)}
                                </span>
                            </div>
                        )}
                    </div>

                    <div>
                        <p className={ROTULO}>Frete que você paga</p>
                        <div className="mt-1.5 grid grid-cols-2 gap-3">
                            <label className="block">
                                <span className="mb-1 block text-[11.5px] text-white/55">Clássico</span>
                                <input value={freteC} onChange={(e) => setFreteC(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                                    placeholder={sugeridoC} className={CELULA} data-celula="frete-classico" />
                                <OrigemDoFrete calculo={calculo.classico} de="Premium" freteTabela={freteTabela} />
                                <SemSugestaoDeFrete envio={calculo.envio} tipo={calculo.classico} />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-[11.5px] text-white/55">Premium</span>
                                <input value={freteP} onChange={(e) => setFreteP(e.target.value)} onBlur={salvar} onKeyDown={enter} inputMode="decimal"
                                    placeholder={sugeridoP} className={CELULA} data-celula="frete-premium" />
                                <OrigemDoFrete calculo={calculo.premium} de="Clássico" freteTabela={freteTabela} />
                                <SemSugestaoDeFrete envio={calculo.envio} tipo={calculo.premium} />
                            </label>
                        </div>
                        <p className="mt-2 text-[11.5px] leading-snug text-white/45">Em branco vale o sugerido. Com valores diferentes aqui, a linha passa a mostrar um campo por tipo.</p>
                    </div>

                    <div>
                        <p className={ROTULO}>Preços para copiar</p>
                        <div className="mt-1.5"><PrecosParaCopiar calculo={calculo} /></div>
                        <button type="button" onClick={() => onAjustar(oferta, calculo)} title="Percentuais só deste produto" data-acao="ajustar-preco"
                            className={cn('mt-3 inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12.5px] transition',
                                ajustado ? 'border-ecf-yellow/40 text-ecf-yellow hover:bg-ecf-yellow/10' : 'border-white/[0.10] text-white/75 hover:bg-white/[0.06] hover:text-white')}>
                            <SlidersHorizontal size={13} /> {ajustado ? 'Percentuais ajustados' : 'Ajustar percentuais'}
                        </button>
                    </div>
                </div>
            )}
        </div>
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

/** O andamento em uma linha: quantas ofertas já têm preço e, só quando há, o que falta em cada tipo de pendência. */
function Resumo({ resumo }) {
    const faltas = [
        [resumo.sem_custo, 'sem custo', 'text-white/70'],
        [resumo.sem_frete, 'sem frete', 'text-amber-300'],
        [resumo.impossivel, 'conta impossível', 'text-red-300'],
    ].filter(([n]) => n > 0);

    return (
        <section className="flex flex-wrap items-baseline gap-x-5 gap-y-1 px-1 text-[13px] text-white/55" data-resumo-precificacao>
            <span>
                <strong className="font-display text-[18px] font-bold tabular-nums text-emerald-300">{resumo.precificadas.toLocaleString('pt-BR')}</strong>{' '}
                de {resumo.total.toLocaleString('pt-BR')} com preço
            </span>
            {faltas.map(([n, rotulo, classe]) => (
                <span key={rotulo}><strong className={cn('font-display text-[15px] font-bold tabular-nums', classe)}>{n.toLocaleString('pt-BR')}</strong> {rotulo}</span>
            ))}
        </section>
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
    const [ajuda, setAjuda] = useState(false);       // "Como usar os dois preços"
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [cotando, setCotando] = useState(false);
    const [avisoCotacao, setAvisoCotacao] = useState(null);
    const [fechadas, setFechadas] = useState({});     // família → fechada
    const [vista, setVista] = useState(() => filtros.vista ?? vistaGuardada() ?? 'simulador');
    const trocarVista = (v) => { setVista(v); guardarVista(v); };

    const { painel, blocos, paginacao } = estrutura;
    const { parametros, padroes, resumo, por_oferta: porOferta } = precificacao;
    const tipo = filtros.tipo ?? null;
    const familias = montarFamilias(blocos, (id) => !! porOferta[id]);
    const cartoesDoSimulador = cartoesDasFamilias(familias, porOferta, cartoesDaFamilia);

    // O simulador grava como a linha da Lista: a linha inteira, e a página volta com a conta nova.
    const salvarOferta = (oferta, dados) => new Promise((resolve) => {
        let erro = null;
        router.put(route('portal.auth.estrutura.precificacao.oferta', oferta.id), dados, {
            preserveScroll: true, preserveState: true,
            onError: (e) => { erro = Object.values(e)[0] ?? 'Não foi possível salvar.'; },
            onFinish: () => resolve(erro ? { erro } : null),
        });
    });
    const onde = ondeEstaCadaOferta(familias);
    const nOfertas = Object.keys(onde).length;
    // Com busca ou tipo escolhido as famílias ficam todas abertas: o que casou aparece direto.
    const filtrando = !! (filtros.q || tipo);
    const todasFechadas = familias.length > 0 && familias.every((f) => fechadas[f.chave]);

    // O atalho de "Também entra em" e do "Feito de": abre a família que estiver fechada, rola e acende a linha.
    const irParaOferta = {
        pode: (id) => !! onde[id],
        ir: (id) => {
            const lugar = onde[id];
            if (! lugar) return;
            setFechadas((f) => ({ ...f, [lugar.familia]: false }));
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
                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                            <Resumo resumo={resumo} />
                            <button type="button" onClick={() => setAjuda((a) => ! a)} aria-expanded={ajuda} data-acao="como-usar-os-precos"
                                className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[12.5px] text-white/60 hover:bg-white/[0.05] hover:text-white">
                                Como usar os dois preços <ChevronDown size={14} className={cn('transition-transform', ajuda && 'rotate-180')} />
                            </button>
                        </div>

                        {ajuda && <ComoPublicar acrescimo={parametros.acrescimo} mlConectado={ml_conectado} freteTabela={frete_tabela} />}

                        {resumo.impossivel > 0 && (
                            <p className="flex items-center gap-2 rounded-xl border border-red-500/30 bg-red-500/[0.06] px-4 py-3 text-[13px] text-red-200">
                                <AlertTriangle size={15} className="shrink-0" />
                                Comissão + imposto + MC + LL somam 100% ou mais: não existe preço que feche essa conta. Revise os percentuais.
                            </p>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative w-64 max-w-full">
                                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/35" />
                                    <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU ou nome…" aria-label="Buscar SKU ou nome"
                                        className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] py-2 pl-8 pr-8 text-[13px] text-white placeholder:text-white/35 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                        data-busca />
                                    {busca && (
                                        <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/40 hover:text-white" aria-label="Limpar busca">
                                            <X size={14} />
                                        </button>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-1.5" data-filtros-de-tipo>
                                    {filtrosDeTipo(painel, tipo).map((t) => (
                                        <button key={t.chave ?? 'todos'} type="button" onClick={() => visitar({ q: busca || undefined, tipo: t.chave || undefined })}
                                            aria-pressed={t.ativo} disabled={t.quantos === 0 && ! t.ativo} data-tipo={t.chave ?? 'todos'}
                                            className={cn('rounded-full border px-3 py-1.5 text-[12px] disabled:opacity-40',
                                                t.ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/10 font-semibold text-ecf-yellow' : 'border-white/[0.10] text-white/65 hover:border-white/25 hover:text-white')}>
                                            {t.rotulo} <span className="tabular-nums opacity-70">{t.quantos}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                {/* Simulador (uma oferta por vez, como no onboarding) ou Lista (todas em linhas). */}
                                <div className="inline-flex rounded-xl border border-white/[0.08] bg-white/[0.04] p-1" role="group" aria-label="Como ver" data-vistas>
                                    {VISTAS.map(([k, l, Icone]) => (
                                        <button key={k} type="button" onClick={() => trocarVista(k)} aria-pressed={vista === k} data-vista={k}
                                            className={cn('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12.5px] font-semibold transition',
                                                vista === k ? 'bg-white/[0.1] text-white' : 'text-white/45 hover:text-white/75')}>
                                            <Icone size={13} /> {l}
                                        </button>
                                    ))}
                                </div>
                                {vista === 'lista' && familias.length > 0 && (
                                    <div className="flex items-center gap-3 text-[12px] text-white/50">
                                        <span data-resumo-da-pagina>
                                            {nOfertas} {nOfertas === 1 ? 'oferta' : 'ofertas'} · {familias.length} {familias.length === 1 ? 'grupo' : 'grupos'}{paginacao.paginas > 1 ? ' nesta página' : ''}
                                        </span>
                                        {! filtrando && (
                                            <button type="button" data-acao="recolher-tudo" className="text-white/65 underline-offset-4 hover:text-white hover:underline"
                                                onClick={() => setFechadas(todasFechadas ? {} : Object.fromEntries(familias.map((f) => [f.chave, true])))}>
                                                {todasFechadas ? 'Abrir tudo' : 'Recolher tudo'}
                                            </button>
                                        )}
                                    </div>
                                )}
                                {ml_conectado && (
                                    <Botao onClick={cotarAgora} disabled={cotando} data-acao="cotar-fretes"
                                        title="Pergunta ao Mercado Livre, pela sua conta, o frete dos produtos desta página que estão sem frete digitado">
                                        {cotando ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
                                        {cotando ? 'Cotando…' : 'Cotar agora'}
                                    </Botao>
                                )}
                            </div>
                        </div>

                        {avisoCotacao && (
                            <div role="status" className="flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12.5px] text-white/65" data-aviso-cotacao>
                                <span>{avisoCotacao}</span>
                                <button type="button" onClick={() => setAvisoCotacao(null)} className="text-white/35 hover:text-white" aria-label="Dispensar aviso"><X size={13} /></button>
                            </div>
                        )}

                        {vista === 'simulador' ? (
                            familias.length === 0
                                ? <p className="rounded-2xl border border-white/[0.08] bg-ecf-card py-10 text-center text-[13px] text-white/50">Nenhuma oferta com esse filtro.</p>
                                : <SimuladorPrecificacao cartoes={cartoesDoSimulador} parametros={parametros} freteTabela={frete_tabela} onSalvar={salvarOferta} />
                        ) : (
                        <div className="overflow-x-auto pb-1">
                            <div className="min-w-[1120px] space-y-2.5" data-lista-precos>
                                <div className={cn(COLUNAS, ROTULO, 'pt-1')} data-colunas>
                                    <span>Oferta</span>
                                    <span className="text-right">Custo</span>
                                    <span className="text-right">Frete</span>
                                    {TIPOS_DE_ANUNCIO.map(([chave, nome]) => (
                                        <span key={chave} className="text-right" title="O número grande é o preço da promoção; embaixo, o de anunciar">
                                            {nome}
                                            <span className="block text-[11px] font-normal normal-case tracking-normal text-white/40">promoção · comissão {fmtPct(parametros[`comissao_${chave}`])}</span>
                                        </span>
                                    ))}
                                    <span>Situação</span>
                                    <span />
                                </div>

                                {familias.map((f) => {
                                    const aberta = filtrando || ! fechadas[f.chave];

                                    return (
                                        <section key={f.chave} className="space-y-2.5" data-familia={f.chave} data-aberta={aberta ? 'sim' : 'nao'}>
                                            <CabecalhoDaFamilia familia={f} aberta={aberta} fixa={filtrando} pendentes={pendentesDaFamilia(f, porOferta)}
                                                onAlternar={() => setFechadas((x) => ({ ...x, [f.chave]: ! x[f.chave] }))} />
                                            {aberta && cartoesDaFamilia(f).map((c, i) => (c.tipo === 'titulo'
                                                ? <TituloConjuntos key={`titulo-${i}`} />
                                                : (
                                                    <div key={c.chave} className="overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card" data-cartao={c.chave}>
                                                        {c.linhas.map((l) => (
                                                            <LinhaOferta key={l.oferta.id} oferta={l.oferta} calculo={porOferta[l.oferta.id]} freteTabela={frete_tabela}
                                                                filho={l.filho} tambemEm={l.tambemEm} itens={l.itens} onIrPara={irParaOferta}
                                                                onAjustar={(oferta, calculo) => setAjustar({ oferta, calculo })} />
                                                        ))}
                                                    </div>
                                                )))}
                                        </section>
                                    );
                                })}
                            </div>
                            {familias.length === 0 && (
                                <p className="rounded-2xl border border-white/[0.08] bg-ecf-card py-10 text-center text-[13px] text-white/50">Nenhuma oferta com esse filtro.</p>
                            )}
                        </div>
                        )}

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
