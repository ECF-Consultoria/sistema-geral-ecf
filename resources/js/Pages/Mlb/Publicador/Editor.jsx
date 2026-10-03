import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Info, Loader2, Sparkles, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import usePublicador from '@/Components/Publicador/usePublicador';
import useIaDoPublicador from '@/Components/Publicador/useIaDoPublicador';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BarraDoEditor from '@/Components/Publicador/Mesa/BarraDoEditor';
import FaixaDeProdutos from '@/Components/Publicador/Mesa/FaixaDeProdutos';
import Trilho, { RodapeDaEtapa, resumoDaRevisao } from '@/Components/Publicador/Mesa/Trilho';
import CardProduto from '@/Components/Publicador/Mesa/CardProduto';
import CardFichaTecnica from '@/Components/Publicador/Mesa/CardFichaTecnica';
import CardVariacoes from '@/Components/Publicador/Mesa/CardVariacoes';
import CardTiposEPrecos from '@/Components/Publicador/Mesa/CardTiposEPrecos';
import CardLogistica from '@/Components/Publicador/Mesa/CardLogistica';
import CardDescricao from '@/Components/Publicador/Mesa/CardDescricao';
import EtapaRevisar from '@/Components/Publicador/Mesa/EtapaRevisar';
import { BASE_BOTAO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { conclusaoDaIa } from '@/Components/Publicador/derivados';
import { ETAPAS, ETAPA_INICIAL, TOTAL_ETAPAS_DE_CONTEUDO, contarEtapasCompletas, estadoDasEtapas, etapaValida } from '@/Components/Publicador/apoio';
import { cn } from '@/lib/utils';

// ─── Editor interno do Publicador: a "mesa de anúncio", passo a passo (D24/D25; 03/10/2026) ─
//
// Compõe barra, faixa de produtos, o TRILHO (as 7 etapas) e UM painel de etapa
// por vez. Toda a lógica mora em `usePublicador` (rascunho, conferência,
// publicação) e `useIaDoPublicador` (Anunciar por IA); aqui só há composição e
// o estado de tela (qual etapa está aberta).
//
// Os seis cards de conteúdo ficam todos montados e só o da etapa aberta aparece
// (`hidden`): o que a pessoa digitou numa etapa, uma "Nova variação" pela metade,
// o EAN gerado uma vez por variação — tudo continua como estava quando ela
// volta, e os efeitos de cada card rodam exatamente como na mesa de uma rolagem só.
//
// A etapa aberta sobrevive ao F5 e à troca de produto: vai para `?etapa=` na URL
// (`history.replaceState`, sem mexer no estado do Inertia) e para o sessionStorage
// por produto. Avançar nunca bloqueia; cada segmento do trilho leva à etapa.
//
// Um só amarelo sólido por tela: o "Continuar" do rodapé nas etapas 1–6 e, na
// revisão, Conferir ou Publicar (o painel decide qual).

const PARAMETRO_ETAPA = 'etapa';
const chaveGuardada = (produtoId) => `publicador.etapa.${produtoId}`;

/** A etapa inicial: a da URL, senão a guardada para o produto, senão a primeira. */
const etapaInicial = (produtoId) => {
    try {
        const daUrl = new URLSearchParams(window.location.search).get(PARAMETRO_ETAPA);
        if (daUrl) return etapaValida(daUrl);

        return etapaValida(window.sessionStorage.getItem(chaveGuardada(produtoId)));
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

function Esqueleto() {
    return (
        <div aria-hidden="true" className="animate-pulse space-y-4" data-esqueleto>
            <div className="h-[62px] rounded-xl border border-white/[0.08] bg-ecf-card" />
            <div className="h-[420px] rounded-xl border border-white/[0.08] bg-ecf-card" />
        </div>
    );
}

/** Faixa de aviso no topo do painel (IA, aviso do hook, erro). */
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

export default function Editor({ produto, empresa, produtos = [] }) {
    // CR-F02: a IA grava o rascunho no servidor. Enquanto ela trabalha a mesa é só leitura e o
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

    const [etapa, setEtapa] = useState(() => etapaInicial(produto.id));
    const [iaFechada, setIaFechada] = useState(false);

    // Troca de produto pela faixa: a etapa é a da URL nova (ou a guardada para aquele produto).
    useEffect(() => { setEtapa(etapaInicial(produto.id)); }, [produto.id]);

    // Uma análise nova reabre a faixa da IA que o usuário tinha dispensado.
    useEffect(() => { if (ia.estado === 'andamento') setIaFechada(false); }, [ia.estado]);

    /** Abre a etapa, guarda na URL, rola até o painel e põe o foco no título dele. */
    const irParaEtapa = useCallback((chave) => {
        const destino = etapaValida(chave);
        setEtapa(destino);
        guardarEtapa(produto.id, destino);
        setTimeout(() => {
            const painel = document.getElementById(`etapa-${destino}`);
            painel?.scrollIntoView({ block: 'start' });
            painel?.querySelector('h2')?.focus({ preventScroll: true });
        }, 0);
    }, [produto.id]);

    // Trocar de produto: descarrega o que ficou por salvar e navega sem recarregar a página inteira.
    const trocar = async (id) => {
        await pub.descarregar();
        router.get(route('mlb.anuncios.publicador.editor', { produto: id }), {}, { preserveScroll: false });
    };

    const estado = pub.m.estado;
    const tokenExpirado = Boolean(estado?.conta?.erro);
    const conclusao = conclusaoDaIa(ia.resumo, { pediuSubstituir: ia.pediuSubstituir });
    const estadosDasEtapas = estadoDasEtapas(pub.secoes);
    const rodape = (chave) => <RodapeDaEtapa atual={chave} onIr={irParaEtapa} />;

    const CONTEUDO = {
        produto: <CardProduto m={pub.m} rodape={rodape('produto')} />,
        ficha: <CardFichaTecnica m={pub.m} rodape={rodape('ficha')} />,
        variacoes: <CardVariacoes m={pub.m} rodape={rodape('variacoes')} />,
        tipos: <CardTiposEPrecos m={pub.m} rodape={rodape('tipos')} />,
        logistica: <CardLogistica m={pub.m} rodape={rodape('logistica')} />,
        descricao: <CardDescricao m={pub.m} rodape={rodape('descricao')} />,
        revisar: <EtapaRevisar pub={pub} empresa={empresa} produtoId={produto.id} onIrPara={irParaEtapa} rodape={rodape('revisar')} />,
    };

    return (
        <AppLayout title="Publicador MLB">
            <Head title={`Publicador — ${produto.nome}`} />

            <div className="-m-6" data-editor-publicador>
                <BarraDoEditor
                    pub={pub}
                    empresa={empresa}
                    produtoNome={produto.nome}
                    ia={ia}
                    onVoltar={() => pub.descarregar()}
                />
                <FaixaDeProdutos
                    produtos={produtos}
                    produtoId={produto.id}
                    prontas={estado ? contarEtapasCompletas(pub.secoes) : 0}
                    total={TOTAL_ETAPAS_DE_CONTEUDO}
                    conta={empresa.chave}
                    onTrocar={trocar}
                />
                {estado && <Trilho estados={estadosDasEtapas} revisao={resumoDaRevisao(pub)} atual={etapa} onIr={irParaEtapa} />}

                <div className="space-y-4 px-6 py-6 max-sm:px-4" data-coluna-principal>
                    {/* Avisos do topo: IA, aviso do hook, erro, token expirado. */}
                    <div aria-live="polite" className="space-y-3 empty:hidden">
                        {ia.estado === 'andamento' && (
                            <Faixa icone={Loader2} tom="azul">
                                <p><span className="font-bold">IA preparando…</span> {ia.textoEtapa}</p>
                                <p className="mt-1">Enquanto ela trabalha, a mesa fica só para leitura. O que ela preencher aparece aqui quando terminar.</p>
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
                                        A IA não montou as variações. Defina-as na etapa Variações e fotos.{' '}
                                        <button type="button" onClick={() => irParaEtapa('variacoes')} className="rounded font-bold text-white underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Ir para a etapa</button>
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

                    {/* Todos os painéis montados; só o da etapa aberta aparece (ver o comentário do topo). */}
                    {estado && ETAPAS.map((e) => (
                        <div key={e.chave} hidden={etapa !== e.chave} data-etapa={e.chave}>
                            {CONTEUDO[e.chave]}
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
