import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, ArrowLeft, ArrowRight, Info, Loader2, Sparkles, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import usePublicador from '@/Components/Publicador/usePublicador';
import useIaDoPublicador from '@/Components/Publicador/useIaDoPublicador';
import useCriativosDoPublicador, { CriativosDoPublicador } from '@/Components/Publicador/useCriativosDoPublicador';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BarraDoEditor from '@/Components/Publicador/Mesa/BarraDoEditor';
import Etapas from '@/Components/Publicador/Mesa/Etapas';
import EtapaProduto from '@/Components/Publicador/Mesa/EtapaProduto';
import EtapaDetalhes from '@/Components/Publicador/Mesa/EtapaDetalhes';
import EtapaImagens from '@/Components/Publicador/Mesa/EtapaImagens';
import EtapaCondicoes, { useEfeitosDoEnvio } from '@/Components/Publicador/Mesa/EtapaCondicoes';
import { useEfeitosDasVariacoes } from '@/Components/Publicador/Mesa/FotosEVariacoes';
import Publicar from '@/Components/Publicador/Mesa/Publicar';
import { ErrosDaEtapa } from '@/Components/Publicador/Mesa/comum';
import { BASE_BOTAO, BotaoAcao, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { conclusaoDaIa } from '@/Components/Publicador/derivados';
import { ETAPA_INICIAL, bloqueiosDaEtapa, etapaAnterior, etapaValida, proximaEtapa, tituloDaEtapa } from '@/Components/Publicador/apoio';
import { cn } from '@/lib/utils';

// ─── Editor interno do Publicador: 4 etapas, como no Mercado Livre ──────────
//
// 04/10/2026 — o cliente: "no Mercado Livre são 3 fases; aqui parecem muitas",
// "os campos nem parecem que são para preencher" e nada de "Estrutura 8/8".
// Então: no topo só os nomes das etapas; embaixo, uma coluna com as seções da
// etapa, campos de verdade e "Voltar"/"Continuar". O "Continuar" salva o
// pendente, pergunta ao servidor o que falta NESTA etapa e só avança sem
// bloqueio; senão marca os campos em vermelho e leva ao primeiro. Antes disso
// nenhum campo fica vermelho. Na última etapa a ação é Conferir/Publicar (o
// único amarelo dali).
//
// 07/10/2026 (D1, Fase 169) — quarta etapa Imagens, DE PROPÓSITO, não um
// esquecimento da decisão de 04/10 acima: causa raiz medida em `EtapaDetalhes`
// (`FotosEVariacoes` renderizava ANTES da ficha técnica/descrição) — o
// operador gerava imagens sem ter preenchido nenhum fato do produto. O
// usuário foi avisado de que isto reabre parcialmente a tensão das "muitas
// fases" e recebeu a alternativa mais barata (só inverter a ordem dentro de
// Detalhes); escolheu a quarta etapa mesmo assim — ela também ganha
// identidade visual (Fase 170) e acervo (Fase 171). Produto → Detalhes →
// Imagens → Condições de venda.
//
// 07/10/2026 (correção, mesma data) — ao sair de Detalhes, o cartão de
// variação levou junto estoque/SKU/código/AGID/MPN (o cartão era misto,
// fotos + dados); regressão relatada pelo usuário em produção. Imagens
// voltou a ser SÓ fotos (`CartaoFotosVariante`); os dados da variação
// voltaram para Detalhes, como primeira seção (`DadosDasVariacoes.jsx`,
// antes da ficha técnica). `etapaDoProblema` (apoio.js) já roteava E5
// (estoque/SKU/GTIN/atributo) para 'detalhes' e E6/grupo/imagem para
// 'imagens' — o bug era só de apresentação, não de roteamento.
//
// Toda a lógica mora em `usePublicador` e `useIaDoPublicador`; aqui há só
// composição e o estado de tela. Os efeitos que valem para o anúncio inteiro
// (EAN automático, "fotos por variação", regra do frete) rodam em hooks
// chamados aqui, sempre, seja qual for a etapa aberta.
//
// A etapa sobrevive ao F5 e à troca de produto: vai para `?etapa=` na URL
// (`history.replaceState`, sem mexer no estado do Inertia) e para o
// sessionStorage por produto.

const PARAMETRO_ETAPA = 'etapa';
const RESUMO_A_VISTA = 5;
const chaveGuardada = (produtoId) => `publicador.etapa.${produtoId}`;

/** A etapa lembrada: a da URL, senão a guardada para o produto, senão a primeira. */
const etapaLembrada = (produtoId) => {
    try {
        const daUrl = etapaValida(new URLSearchParams(window.location.search).get(PARAMETRO_ETAPA));
        if (daUrl) return daUrl;

        return etapaValida(window.sessionStorage.getItem(chaveGuardada(produtoId))) ?? ETAPA_INICIAL;
    } catch {
        return ETAPA_INICIAL;
    }
};

const guardarEtapa = (produtoId, chave) => {
    try {
        const url = new URL(window.location.href);
        url.searchParams.set(PARAMETRO_ETAPA, chave);
        // Mesmo `state`: o Inertia guarda a página ali e não pode perdê-la.
        window.history.replaceState(window.history.state, '', url);
        window.sessionStorage.setItem(chaveGuardada(produtoId), chave);
    } catch {
        // Sem history/sessionStorage (modo restrito): a etapa só não sobrevive ao F5.
    }
};

/** Leva ao primeiro campo marcado em vermelho da etapa (ou ao resumo, se nenhum campo for o culpado). */
const focarPrimeiroErro = () => {
    const alvo = document.getElementById('conteudo-etapa')?.querySelector('[aria-invalid="true"]');
    if (alvo) {
        alvo.scrollIntoView({ block: 'center' });
        alvo.focus?.({ preventScroll: true });

        return;
    }
    document.getElementById('resumo-erros')?.scrollIntoView({ block: 'center' });
};

function Esqueleto() {
    return (
        <div aria-hidden="true" className="animate-pulse space-y-6" data-esqueleto>
            <div className="h-10 rounded-lg bg-white/[0.04]" />
            <div className="h-[420px] rounded-xl border border-white/[0.08] bg-ecf-card" />
        </div>
    );
}

/** Faixa de aviso no topo (IA, aviso do hook, erro). */
function Faixa({ tom = 'azul', icone: Icone, children, acao, onFechar }) {
    const TOM = {
        azul: 'border-sky-400/25 bg-sky-400/10 text-sky-200',
        vermelho: 'border-red-500/30 bg-red-500/10 text-red-300',
        neutro: 'border-white/[0.08] bg-white/[0.03] text-white/70',
    };

    return (
        <div className={cn('flex items-start gap-3 rounded-xl border p-4 text-[13px] font-normal', TOM[tom])}>
            {Icone && <Icone size={16} className="mt-0.5 shrink-0" aria-hidden="true" />}
            <div className="min-w-0 flex-1">{children}</div>
            {acao}
            {onFechar && (
                <button type="button" onClick={onFechar} aria-label="Fechar aviso" className="shrink-0 rounded text-current opacity-70 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    <X size={16} aria-hidden="true" />
                </button>
            )}
        </div>
    );
}

/** O que falta na etapa, depois de um "Continuar" que não pôde avançar. Some quando tudo se resolve. */
function ResumoDosErros({ bloqueios }) {
    if (bloqueios.length === 0) return null;
    const resto = bloqueios.length - RESUMO_A_VISTA;

    return (
        <div id="resumo-erros" role="alert" className="scroll-mt-24 rounded-xl border border-red-400/40 bg-red-500/[0.07] p-4" data-resumo-erros>
            <p className="flex items-center gap-2 text-[15px] font-bold text-red-200"><AlertCircle size={16} aria-hidden="true" /> Para continuar, corrija os campos marcados em vermelho.</p>
            <ul className="mt-2 space-y-1 pl-6 text-[13px] text-red-200/90">
                {bloqueios.slice(0, RESUMO_A_VISTA).map((p, i) => <li key={`${p.regra}-${i}`} className="list-disc">{p.mensagem}</li>)}
                {resto > 0 && <li className="list-none text-red-200/70">e mais {resto}.</li>}
            </ul>
        </div>
    );
}

export default function Editor({ produto, empresa, produtos = [], criativos_ia = false }) {
    // CR-F02: a IA grava o rascunho no servidor. Enquanto ela trabalha o editor é só leitura e o
    // salvamento automático para; quando ela termina (bem ou com erro), o hook relê o servidor
    // ANTES de liberar a edição. A ref liga o fim da IA ao hook do editor, criado logo abaixo.
    const depoisDaIa = useRef(() => {});
    const ia = useIaDoPublicador({
        produtoId: produto.id,
        nomeProduto: produto.nome,
        onConcluiu: () => depoisDaIa.current(),
        onFalhou: () => depoisDaIa.current(),
    });
    const pub = usePublicador({
        produtoId: produto.id,
        onPublicou: () => router.reload({ only: ['produtos'] }),
        pausado: ia.estado === 'andamento',
    });
    depoisDaIa.current = pub.recarregarDepoisDaIa;
    const { m } = pub;
    const estado = m.estado;

    // Fase 165 (165-07): o kit de criativos por IA — `disponivel` é a flag do servidor (D-06);
    // ao usar uma imagem, o `BlocoDeFotos` (via contexto) relê o rascunho pelo caminho de
    // estrutura, nunca mesclando o resultado por fora (useCriativosDoPublicador.js, topo).
    const criativos = useCriativosDoPublicador({ produtoId: produto.id, disponivel: criativos_ia === true, onAprovou: () => pub.recarregar() });

    // Efeitos do anúncio inteiro (valem em qualquer etapa).
    useEfeitosDasVariacoes(m);
    useEfeitosDoEnvio(m);

    const [etapa, setEtapa] = useState(() => etapaLembrada(produto.id));
    // Etapas em que já houve "Continuar" (ou "Corrigir em…"): só nelas os campos ficam vermelhos.
    const [tentou, setTentou] = useState({});
    const [avancando, setAvancando] = useState(false);
    const [verificar, setVerificar] = useState(0);
    const [iaFechada, setIaFechada] = useState(false);

    // Troca de produto pela barra: a etapa é a da URL nova (ou a guardada para aquele produto).
    useEffect(() => {
        setEtapa(etapaLembrada(produto.id));
        setTentou({});
    }, [produto.id]);

    // Uma análise nova reabre a faixa da IA que o usuário tinha dispensado.
    useEffect(() => { if (ia.estado === 'andamento') setIaFechada(false); }, [ia.estado]);

    const temCategoria = Boolean(estado?.rascunho?.categoria_id);
    const bloqueios = estado ? bloqueiosDaEtapa(etapa, pub.problemas, { temCategoria }) : [];

    /** Abre a etapa. `marcar` = já com os campos que faltam em vermelho (vindo de "Corrigir em…"). */
    const irPara = useCallback((chave, { marcar = false } = {}) => {
        setEtapa(chave);
        guardarEtapa(produto.id, chave);
        if (marcar) setTentou((t) => ({ ...t, [chave]: true }));
        setTimeout(() => {
            if (marcar) {
                focarPrimeiroErro();

                return;
            }
            document.getElementById('topo-do-editor')?.scrollIntoView({ block: 'start' });
        }, 50);
    }, [produto.id]);

    // "Continuar": salva o pendente; com o estado novo do servidor na tela, confere a etapa.
    const continuar = async () => {
        setAvancando(true);
        await pub.descarregar();
        setVerificar((n) => n + 1);
    };
    useEffect(() => {
        if (verificar === 0) return;
        setAvancando(false);
        if (bloqueios.length === 0) {
            const proxima = proximaEtapa(etapa);
            if (proxima) irPara(proxima);

            return;
        }
        setTentou((t) => ({ ...t, [etapa]: true }));
        setTimeout(focarPrimeiroErro, 50);
    }, [verificar]); // eslint-disable-line react-hooks/exhaustive-deps

    // Trocar de produto: descarrega o que ficou por salvar e navega sem recarregar a página inteira.
    const trocar = async (id) => {
        await pub.descarregar();
        router.get(route('mlb.anuncios.publicador.editor', { produto: id }), {}, { preserveScroll: false });
    };

    const tokenExpirado = Boolean(estado?.conta?.erro);
    const conclusao = conclusaoDaIa(ia.resumo, { pediuSubstituir: ia.pediuSubstituir });
    const anterior = etapaAnterior(etapa);
    const proxima = proximaEtapa(etapa);
    const mostrar = Boolean(tentou[etapa]);

    return (
        <AppLayout title="Publicador MLB">
            <Head title={`Publicador — ${produto.nome}`} />

            <CriativosDoPublicador.Provider value={criativos}>
            <div className="-m-6" data-editor-publicador>
                <BarraDoEditor pub={pub} empresa={empresa} produto={produto} produtos={produtos} onTrocar={trocar} ia={ia} onVoltar={() => pub.descarregar()} />

                <div id="topo-do-editor" className="mx-auto w-full max-w-[1200px] scroll-mt-20 space-y-6 px-6 pt-6 max-sm:px-4" data-coluna-principal>
                    {/* Avisos do topo: IA, aviso do hook, erro, token expirado. */}
                    <div aria-live="polite" className="space-y-3 empty:hidden">
                        {ia.estado === 'andamento' && (
                            <Faixa icone={Loader2} tom="azul">
                                <p><span className="font-bold">IA preparando…</span> {ia.textoEtapa}</p>
                                <p className="mt-1">Enquanto ela trabalha, o anúncio fica só para leitura. O que ela preencher aparece aqui quando terminar.</p>
                            </Faixa>
                        )}
                        {/* WR-F04: `secoes` é número; o aviso e o "só o vazio" vêm do servidor. */}
                        {ia.estado === 'concluido' && ! iaFechada && (
                            <Faixa icone={Sparkles} tom="azul" onFechar={() => setIaFechada(true)}>
                                <p>
                                    {conclusao.secoes > 0
                                        ? `A IA preencheu ${conclusao.secoes === 1 ? '1 seção' : `${conclusao.secoes} seções`}. Revise antes de conferir no Mercado Livre.`
                                        : 'A IA não preencheu nenhuma seção.'}
                                </p>
                                {conclusao.aviso && <p className="mt-1">{conclusao.aviso}</p>}
                                {conclusao.soPreencheuOVazio && <p className="mt-1">Como houve edição durante a geração, a IA só preencheu o que estava vazio.</p>}
                                {conclusao.semVariacoes && (
                                    <p className="mt-1">
                                        A IA não montou as variações.{' '}
                                        <button type="button" onClick={() => irPara('detalhes')} className="rounded font-bold text-white underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Abrir Detalhes</button>
                                    </p>
                                )}
                            </Faixa>
                        )}
                        {/* Sem "Nada foi alterado": a IA pode ter gravado parte antes de cair (o hook relê ao terminar). */}
                        {ia.estado === 'erro' && (
                            <Faixa
                                icone={AlertTriangle}
                                tom="vermelho"
                                acao={<button type="button" onClick={ia.tentarDeNovo} className="shrink-0 rounded text-[13px] font-bold text-white hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Tentar de novo</button>}
                            >
                                <p>A IA não conseguiu preparar este anúncio.</p>
                                {ia.erro && <p className="mt-1">{ia.erro}</p>}
                                <p className="mt-1">Se ela chegou a preencher algo, já está nas etapas. Tente de novo ou preencha à mão.</p>
                            </Faixa>
                        )}
                        {pub.aviso && <Faixa icone={Info} tom="neutro" onFechar={() => pub.setAviso(null)}>{pub.aviso}</Faixa>}
                        {pub.erro && <Faixa icone={AlertTriangle} tom="vermelho" onFechar={() => pub.setErro(null)}>{pub.erro}</Faixa>}
                        {tokenExpirado && (
                            <Faixa icone={AlertTriangle} tom="vermelho">
                                <p>A conta do Mercado Livre precisa ser reconectada antes de conferir no Mercado Livre ou publicar.</p>
                                {empresa.link_reconexao && <div className="mt-2"><LinkReconexao link={empresa.link_reconexao} /></div>}
                            </Faixa>
                        )}
                    </div>

                    {pub.erroCarga && ! estado && (
                        <div role="alert" className="rounded-xl border border-white/[0.08] bg-ecf-card p-6">
                            <p className="text-[15px] font-bold text-white">Não foi possível abrir o produto.</p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">{pub.erroCarga}</p>
                            <div className="mt-4 flex gap-2">
                                <button type="button" onClick={pub.recarregar} className={cn(BASE_BOTAO, SECUNDARIO, 'font-normal')}>
                                    Tentar de novo
                                </button>
                                <Link href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })} className={cn(BASE_BOTAO, SECUNDARIO, 'font-normal')}>
                                    Voltar aos produtos
                                </Link>
                            </div>
                        </div>
                    )}

                    {! estado && ! pub.erroCarga && <Esqueleto />}

                    {estado && (
                        <>
                            <Etapas atual={etapa} onIr={(chave) => irPara(chave)} />

                            <ErrosDaEtapa value={{ mostrar, problemas: pub.problemas }}>
                                <div id="conteudo-etapa" className="space-y-6" data-etapa-aberta={etapa}>
                                    {mostrar && <ResumoDosErros bloqueios={bloqueios} />}
                                    {etapa === 'produto' && <EtapaProduto m={m} />}
                                    {etapa === 'detalhes' && <EtapaDetalhes m={m} />}
                                    {etapa === 'imagens' && <EtapaImagens m={m} produtoId={produto.id} />}
                                    {etapa === 'condicoes' && (
                                        <EtapaCondicoes m={m}>
                                            <Publicar pub={pub} empresa={empresa} produtoId={produto.id} onIrPara={(chave) => irPara(chave, { marcar: true })} />
                                        </EtapaCondicoes>
                                    )}
                                </div>
                            </ErrosDaEtapa>
                        </>
                    )}
                </div>

                {/* Rodapé fixo: Voltar e Continuar (o amarelo das etapas 1 e 2). */}
                {estado && (
                    <div className="sticky -bottom-6 z-20 mt-8 border-t border-white/[0.08] bg-ecf-bg/95 backdrop-blur" data-rodape-etapa>
                        <div className="mx-auto flex w-full max-w-[1200px] items-center justify-between gap-3 px-6 py-3 max-sm:px-4">
                            {anterior
                                ? <BotaoAcao onClick={() => irPara(anterior)} data-acao="voltar-etapa" className="h-11 px-5"><ArrowLeft size={16} aria-hidden="true" /> Voltar</BotaoAcao>
                                : <span />}
                            {proxima && (
                                <BotaoAcao primario onClick={continuar} disabled={avancando} data-acao="continuar" className="h-11 px-6" title={`Ir para ${tituloDaEtapa(proxima)}`}>
                                    {avancando && <Loader2 size={16} className="animate-spin" aria-hidden="true" />} Continuar <ArrowRight size={16} aria-hidden="true" />
                                </BotaoAcao>
                            )}
                        </div>
                    </div>
                )}
            </div>
            </CriativosDoPublicador.Provider>
        </AppLayout>
    );
}
