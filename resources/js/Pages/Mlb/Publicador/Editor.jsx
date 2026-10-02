import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, Info, Loader2, Sparkles, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import usePublicador from '@/Components/Publicador/usePublicador';
import useIaDoPublicador from '@/Components/Publicador/useIaDoPublicador';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BarraDoEditor from '@/Components/Publicador/Mesa/BarraDoEditor';
import FaixaDeProdutos from '@/Components/Publicador/Mesa/FaixaDeProdutos';
import LateralValidacao from '@/Components/Publicador/Mesa/LateralValidacao';
import LateralResumo from '@/Components/Publicador/Mesa/LateralResumo';
import CardProduto from '@/Components/Publicador/Mesa/CardProduto';
import CardFichaTecnica from '@/Components/Publicador/Mesa/CardFichaTecnica';
import CardVariacoes from '@/Components/Publicador/Mesa/CardVariacoes';
import CardFotos from '@/Components/Publicador/Mesa/CardFotos';
import CardTiposEPrecos from '@/Components/Publicador/Mesa/CardTiposEPrecos';
import CardLogistica from '@/Components/Publicador/Mesa/CardLogistica';
import CardDescricao from '@/Components/Publicador/Mesa/CardDescricao';
import { cn } from '@/lib/utils';

// ─── Editor interno do Publicador: a "mesa de anúncio" (D24/D25; UI-SPEC §8) ─
//
// Compõe barra, faixa de produtos, os 7 cards e a lateral. Toda a lógica mora
// em `usePublicador` (rascunho, conferência, publicação) e `useIaDoPublicador`
// (Anunciar por IA); aqui só há composição e o estado de tela.
//
// ≥ 1360px: coluna principal + lateral sticky de 320px, e o botão primário está
// no Resumo. Abaixo disso a lateral vira um card recolhido no topo e o primário
// passa para a barra — nunca existem dois amarelos sólidos ao mesmo tempo.

const FAIXA_LARGA = '(min-width: 1360px)';

function Esqueleto() {
    return (
        <div aria-hidden="true" className="animate-pulse space-y-6" data-esqueleto>
            {[0, 1, 2].map((i) => <div key={i} className="h-48 rounded-xl border border-white/[0.08] bg-ecf-card" />)}
        </div>
    );
}

/** Faixa de aviso no topo da coluna principal (IA, aviso do hook, erro). */
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

    const [abertos, setAbertos] = useState({});
    const [largo, setLargo] = useState(() => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(FAIXA_LARGA).matches : true));
    const [lateralAberta, setLateralAberta] = useState(false);
    const [iaFechada, setIaFechada] = useState(false);

    useEffect(() => {
        const mq = window.matchMedia(FAIXA_LARGA);
        const mudou = (e) => setLargo(e.matches);
        setLargo(mq.matches);
        mq.addEventListener('change', mudou);

        return () => mq.removeEventListener('change', mudou);
    }, []);

    // Uma análise nova reabre a faixa da IA que o usuário tinha dispensado.
    useEffect(() => { if (ia.estado === 'andamento') setIaFechada(false); }, [ia.estado]);

    const alternar = (id) => setAbertos((a) => ({ ...a, [id]: a[id] === false }));

    /** Abre o card e rola até ele (scroll-margin de 80px nos cards). */
    const irPara = useCallback((id) => {
        setAbertos((a) => ({ ...a, [id]: true }));
        setTimeout(() => document.getElementById(`card-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 0);
    }, []);

    // Trocar de produto: descarrega o que ficou por salvar e navega sem recarregar a página inteira.
    const trocar = async (id) => {
        await pub.descarregar();
        router.get(route('mlb.anuncios.publicador.editor', { produto: id }), {}, { preserveScroll: false });
    };

    const estado = pub.m.estado;
    const tokenExpirado = Boolean(estado?.conta?.erro);

    return (
        <AppLayout title="Publicador MLB">
            <Head title={`Publicador — ${produto.nome}`} />

            <div className="-m-6" data-editor-publicador>
                <BarraDoEditor
                    pub={pub}
                    empresa={empresa}
                    produtoNome={produto.nome}
                    primarioNaLateral={largo}
                    ia={ia}
                    onVoltar={() => pub.descarregar()}
                />
                <FaixaDeProdutos
                    produtos={produtos}
                    produtoId={produto.id}
                    prontas={pub.prontas}
                    conta={empresa.chave}
                    onTrocar={trocar}
                />

                <div className="grid grid-cols-1 gap-6 px-8 py-8 min-[1360px]:grid-cols-[minmax(0,800px)_320px] min-[1360px]:gap-8">
                    <div className="min-w-0 space-y-6" data-coluna-principal>
                        {/* Avisos do topo: IA, aviso do hook, erro, token expirado. */}
                        <div aria-live="polite" className="space-y-3">
                            {ia.estado === 'andamento' && (
                                <Faixa icone={Loader2} tom="azul">
                                    <p><span className="font-bold">IA preparando…</span> {ia.textoEtapa}</p>
                                    <p className="mt-1">Enquanto ela trabalha, a mesa fica só para leitura. O que ela preencher aparece aqui quando terminar.</p>
                                </Faixa>
                            )}
                            {ia.estado === 'concluido' && ! iaFechada && (
                                <Faixa icone={Sparkles} tom="azul" onFechar={() => setIaFechada(true)}>
                                    <p>A IA preencheu {ia.resumo?.secoes?.length ?? 0} seções. Revise antes de conferir no Mercado Livre.</p>
                                    {! ia.resumo?.variacoes && <p className="mt-1">A IA não montou as variações. Defina-as no card Variações.</p>}
                                </Faixa>
                            )}
                            {ia.estado === 'erro' && (
                                <Faixa
                                    icone={AlertTriangle}
                                    tom="vermelho"
                                    acao={<button type="button" onClick={ia.tentarDeNovo} className="shrink-0 rounded text-[13px] font-bold text-white hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Tentar de novo</button>}
                                >
                                    A IA não conseguiu preparar este anúncio. Nada foi alterado. Tente de novo ou preencha à mão.
                                </Faixa>
                            )}
                            {pub.aviso && <Faixa icone={Info} tom="neutro" onFechar={() => pub.setAviso(null)}>{pub.aviso}</Faixa>}
                            {pub.erro && <Faixa icone={AlertTriangle} tom="vermelho" onFechar={() => pub.setErro(null)}>{pub.erro}</Faixa>}
                            {tokenExpirado && (
                                <Faixa icone={AlertTriangle} tom="vermelho">
                                    <p>A conta do Mercado Livre precisa ser reconectada antes de conferir ou publicar.</p>
                                    {empresa.link_reconexao && <div className="mt-2"><LinkReconexao link={empresa.link_reconexao} /></div>}
                                </Faixa>
                            )}
                        </div>

                        {pub.erroCarga && ! estado && (
                            <div role="alert" className="rounded-xl border border-white/[0.08] bg-ecf-card p-6">
                                <p className="text-[15px] font-bold text-white">Não foi possível abrir o produto.</p>
                                <p className="mt-1 text-[13px] font-normal text-white/55">{pub.erroCarga}</p>
                                <div className="mt-4 flex gap-2">
                                    <button type="button" onClick={pub.recarregar} className="inline-flex h-10 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                        Tentar de novo
                                    </button>
                                    <Link href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })} className="inline-flex h-10 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                        Voltar aos produtos
                                    </Link>
                                </div>
                            </div>
                        )}

                        {! estado && ! pub.erroCarga && <Esqueleto />}

                        {estado && (
                            <>
                                <CardProduto m={pub.m} aberto={abertos.produto !== false} onAlternar={() => alternar('produto')} />
                                <CardFichaTecnica m={pub.m} aberto={abertos.ficha !== false} onAlternar={() => alternar('ficha')} />
                                <CardVariacoes m={pub.m} aberto={abertos.variacoes !== false} onAlternar={() => alternar('variacoes')} />
                                <CardFotos m={pub.m} aberto={abertos.fotos !== false} onAlternar={() => alternar('fotos')} />
                                <CardTiposEPrecos m={pub.m} aberto={abertos.tipos !== false} onAlternar={() => alternar('tipos')} />
                                <CardLogistica m={pub.m} aberto={abertos.logistica !== false} onAlternar={() => alternar('logistica')} />
                                <CardDescricao m={pub.m} aberto={abertos.descricao !== false} onAlternar={() => alternar('descricao')} />
                            </>
                        )}
                    </div>

                    {/* Lateral: coluna sticky em ≥ 1360px; abaixo, card recolhido no topo (uma só árvore, para o aria-describedby achar a nota). */}
                    {estado && (
                        <aside className="order-first min-[1360px]:order-none" aria-label="Validação e resumo">
                            <div className="min-[1360px]:sticky min-[1360px]:top-[56px] min-[1360px]:max-h-[calc(100vh-164px)] min-[1360px]:overflow-y-auto">
                                <button
                                    type="button"
                                    onClick={() => setLateralAberta((v) => ! v)}
                                    aria-expanded={lateralAberta}
                                    aria-controls="lateral-corpo"
                                    className="flex h-12 w-full items-center gap-3 rounded-xl border border-white/[0.08] bg-ecf-card px-4 text-left text-[13px] font-normal text-white/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow min-[1360px]:hidden"
                                >
                                    <span className="min-w-0 flex-1 truncate">
                                        <span className="font-bold text-white">{pub.prontas} de {pub.totalSecoes} prontos</span> · {pub.conferencia.texto}
                                    </span>
                                    <ChevronDown size={16} className={cn('shrink-0 transition-transform', lateralAberta && 'rotate-180')} aria-hidden="true" />
                                </button>
                                <div id="lateral-corpo" className={cn('space-y-6 min-[1360px]:block', lateralAberta ? 'mt-3 block' : 'hidden')}>
                                    <LateralValidacao pub={pub} onIrPara={irPara} />
                                    <LateralResumo pub={pub} empresa={empresa} produtoId={produto.id} primario={largo} />
                                </div>
                            </div>
                        </aside>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
