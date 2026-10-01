import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { DialogTitle } from '@radix-ui/react-dialog';
import { CalendarPlus, Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import { Dialog, DialogContent } from '@/Components/ui/dialog';
import { Botao, EstoqueOferta, FotoProduto, PilulaSituacao, PrecosDaOferta, Vendas } from './comum';
import { useMetricasMl } from './MetricasMl';
import EstacaoTrilho from './EstacaoTrilho';
import EstacaoColuna from './EstacaoColuna';
import EstacaoInspetor from './EstacaoInspetor';
import EstacaoGrafico from './EstacaoGrafico';
import EstacaoAgenda from './EstacaoAgenda';
import { cn } from '@/lib/utils';

// ─── A estação do produto ───────────────────────────────────────────────────
//
// O lugar onde o seller OPERA uma oferta, quase em tela cheia (28/09). A gaveta
// lateral não cabia: 30 anúncios, métricas, fotos e agenda em 600 px viraram
// uma pilha impossível de analisar.
//
// O recorte é a FAMÍLIA — o produto e os combos dele, ou o kit e seus
// componentes —, porque é olhando a família que se responde "dá combo? dá
// kit?". Uma oferta fica em foco; o trilho da esquerda troca o foco. No
// centro, a régua do método vira layout: Clássico e Premium lado a lado, e o
// lado que falta é uma coluna vazia com a ação. À direita, o inspetor do
// anúncio clicado (fotos, SKU no ML, métricas dele).
//
// ### De onde vem cada coisa, e quando
// - A família vem de UMA resposta (`ofertas.estacao`), independente do filtro
//   da lista; relida depois de cada escrita (a prop `versao` muda quando o
//   Inertia recarrega a página).
// - SKU no ML e fotos de cada anúncio: uma leitura por oferta em foco.
// - Métricas (série de 30 dias, 7 dias, buy box): sob demanda, ou já ao chegar
//   pela Jardinagem. A parte lenta (pedidos da loja) é pré-aquecida ao abrir a
//   página, e a tela espera por ela sem travar o resto.
//
// Os formulários (anúncio, oferta, agendar) continuam sendo os da página:
// abrem por cima da estação, e a escrita recarrega a estação.

const LADO_QUE_FALTA = { falta_classico: 'classico', falta_premium: 'premium' };

const ABAS = [
    { chave: 'familia', rotulo: 'Família' },
    { chave: 'oferta',  rotulo: 'Anúncios' },
    { chave: 'metricas', rotulo: 'Métricas' },
    { chave: 'agenda',  rotulo: 'Agenda' },
];

export default function EstacaoProduto({ ofertaId, versao, onFechar, onTrocar, vocabulario, mlConectado = false, autoMetricas = false, acoes }) {
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(null);
    const [foco, setFoco] = useState(ofertaId);
    const [selecionado, setSelecionado] = useState(null);   // id do anúncio no inspetor
    const [detalhes, setDetalhes] = useState({});           // oferta id → { conectado, anuncios: { mlb: { sku, fotos } } }
    const [aba, setAba] = useState('oferta');               // só no celular

    // A família: uma resposta; relida depois de cada escrita.
    useEffect(() => {
        if (! ofertaId) { setDados(null); return undefined; }
        let viva = true;
        setCarregando(true);
        setErro(null);
        axios.get(route('portal.auth.estrutura.ofertas.estacao', ofertaId))
            .then(({ data }) => { if (viva) { setDados(data); setCarregando(false); } })
            .catch(() => { if (viva) { setErro('Não foi possível abrir a estação. Tente de novo.'); setCarregando(false); } });

        return () => { viva = false; };
    }, [ofertaId, versao]);

    useEffect(() => { setFoco(ofertaId); setSelecionado(null); setAba('oferta'); }, [ofertaId]);
    useEffect(() => { setDetalhes({}); }, [versao]);

    const ofertas = dados?.ofertas ?? [];
    const oferta = ofertas.find((o) => o.id === foco) ?? ofertas[0] ?? null;

    // SKU no ML e fotos da oferta em foco.
    useEffect(() => {
        if (! mlConectado || ! oferta || detalhes[oferta.id] || ! oferta.anuncios.some((a) => a.codigo_mlb)) return undefined;
        let viva = true;
        const id = oferta.id;
        axios.get(route('portal.auth.estrutura.anuncios_ml.detalhes', id))
            .then(({ data }) => viva && setDetalhes((d) => ({ ...d, [id]: data })))
            .catch(() => viva && setDetalhes((d) => ({ ...d, [id]: { conectado: true, anuncios: {}, erro: 'Não foi possível ler os anúncios no ML.' } })));

        return () => { viva = false; };
    }, [oferta?.id, mlConectado, detalhes]); // eslint-disable-line react-hooks/exhaustive-deps

    const metricas = useMetricasMl(mlConectado ? oferta?.id : null, autoMetricas && oferta?.id === ofertaId);
    const detalhe = detalhes[oferta?.id]?.anuncios ?? null;
    const mapaMetricas = metricas.estado?.metricas ?? null;

    const anuncioSelecionado = useMemo(() => oferta?.anuncios.find((a) => a.id === selecionado) ?? null, [oferta, selecionado]);

    const excluirOferta = () => {
        if (! oferta || ! window.confirm(`Excluir a oferta ${oferta.sku}? Os anúncios cadastrados nela também saem.`)) return;
        const eraPrincipal = oferta.id === dados.principal.id;
        router.delete(route('portal.auth.estrutura.ofertas.excluir', oferta.id), {
            preserveScroll: true, preserveState: true,
            onSuccess: () => (eraPrincipal ? onFechar() : setFoco(dados.principal.id)),
            onError: (e) => window.alert(e.oferta ?? 'Não foi possível excluir.'),
        });
    };

    const excluirAnuncio = (a) => {
        if (! window.confirm(`Excluir o anúncio ${vocabulario.tipos[a.tipo]}${a.codigo_mlb ? ` ${a.codigo_mlb}` : ''}?`)) return;
        router.delete(route('portal.auth.estrutura.anuncios.excluir', a.id), {
            preserveScroll: true, preserveState: true, onSuccess: () => setSelecionado(null),
        });
    };

    const pendente = oferta && oferta.situacao !== 'ok';
    const painel = (chave) => cn('min-h-0', aba !== chave && 'hidden lg:block');

    return (
        <Dialog open={!! ofertaId} onOpenChange={(v) => ! v && onFechar()}>
            <DialogContent className="grid-cols-1 h-[100dvh] w-screen max-w-none gap-0 overflow-hidden rounded-none border-white/[0.08] bg-ecf-bg p-0 text-white sm:h-[94vh] sm:w-[96vw] sm:rounded-2xl"
                data-estacao={ofertaId ?? ''}>
                <div className="flex h-full min-h-0 flex-col">
                    {/* ── Cabeçalho do produto ── */}
                    <header className="flex shrink-0 items-center gap-3 border-b border-white/[0.08] px-4 py-3 pr-14 sm:px-5">
                        <FotoProduto url={dados?.foto} className="h-12 w-12" />
                        <div className="min-w-0 flex-1">
                            <DialogTitle className="truncate font-mono text-[16px] font-bold text-white">
                                {dados?.principal.sku ?? '…'}
                                {dados?.principal.nome && <span className="ml-2 font-sans text-[14px] font-normal text-white/60">{dados.principal.nome}</span>}
                            </DialogTitle>
                            <p className="flex flex-wrap items-center gap-x-3 text-[12px] text-white/50">
                                {dados && <span>{dados.kit ? vocabulario.fases[dados.principal.fase] : `${ofertas.length} ${ofertas.length === 1 ? 'oferta' : 'ofertas'}`}</span>}
                                {dados?.vendas > 0 && <Vendas quantidade={dados.vendas} />}
                                <EstoqueOferta estoque={dados?.estoque} />
                                {carregando && <span className="inline-flex items-center gap-1 text-white/40"><Loader2 size={12} className="animate-spin" /> atualizando…</span>}
                            </p>
                        </div>
                        {dados && ! dados.kit && (
                            <Botao className="hidden sm:inline-flex" onClick={() => acoes.variacao(ofertas[0], dados.quantidades_combo)} data-acao="nova-variacao">
                                <Plus size={14} /> Variação
                            </Botao>
                        )}
                    </header>

                    {/* ── Abas do celular ── */}
                    <nav className="flex shrink-0 gap-1 border-b border-white/[0.08] px-2 py-1.5 lg:hidden" aria-label="Seções">
                        {ABAS.map((a) => (
                            <button key={a.chave} type="button" onClick={() => setAba(a.chave)} data-aba={a.chave}
                                className={cn('flex-1 rounded-lg px-2 py-1.5 text-[12.5px]', aba === a.chave ? 'bg-ecf-yellow font-semibold text-black' : 'text-white/60')}>
                                {a.rotulo}
                            </button>
                        ))}
                    </nav>

                    {erro && <p className="px-5 py-3 text-[13px] text-red-300">{erro}</p>}

                    {dados && oferta && (
                        <div className="relative flex min-h-0 flex-1">
                            {/* ── Trilho da família ── */}
                            <aside className={cn(painel('familia'), 'w-full shrink-0 overflow-y-auto border-r border-white/[0.08] lg:w-64')}>
                                <EstacaoTrilho dados={dados} foco={oferta.id} vocabulario={vocabulario}
                                    onFoco={(id) => { setFoco(id); setSelecionado(null); setAba('oferta'); }}
                                    onTrocar={onTrocar}
                                    onVariacao={() => acoes.variacao(ofertas[0], dados.quantidades_combo)} />
                            </aside>

                            {/* ── A oferta em foco ── */}
                            <main className="min-w-0 flex-1 overflow-y-auto">
                                <div className="space-y-5 p-4 sm:p-5">
                                    <section className={painel('oferta')} data-foco={oferta.id}>
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-[11.5px] uppercase tracking-wide text-white/40">
                                                    {vocabulario.fases[oferta.fase]} · {oferta.unidades} un.
                                                    {oferta.logistica && <> · {vocabulario.logisticas[oferta.logistica]}</>}
                                                </p>
                                                <h3 className="font-mono text-[18px] font-bold text-white">{oferta.sku}</h3>
                                                {oferta.nome && oferta.id !== dados.principal.id && <p className="text-[13px] text-white/60">{oferta.nome}</p>}
                                                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px]">
                                                    <PilulaSituacao situacao={oferta.situacao} longa vocabulario={vocabulario} />
                                                    <EstoqueOferta estoque={oferta.estoque} />
                                                    {oferta.vendas > 0 && <Vendas quantidade={oferta.vendas} />}
                                                    {/* Com os preços lidos no ML (promoção incluída), a comparação usa eles. */}
                                                    <PrecosDaOferta precos={detalhes[oferta.id]?.precos ?? oferta.precos} vocabulario={vocabulario} />
                                                    {oferta.sku_repetido && <span className="text-amber-300">SKU repetido em outra oferta</span>}
                                                </div>
                                                {oferta.observacoes && <p className="mt-1 text-[12px] text-white/45">{oferta.observacoes}</p>}
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {pendente && (
                                                    <Botao variante="primario" onClick={() => acoes.novoAnuncio(oferta, LADO_QUE_FALTA[oferta.situacao] ?? null)}
                                                        data-acao={oferta.situacao === 'publicar' ? 'publicar' : 'completar'}>
                                                        {oferta.situacao === 'publicar' ? 'Publicar' : `Completar ${vocabulario.tipos_curtos[LADO_QUE_FALTA[oferta.situacao]]}`}
                                                    </Botao>
                                                )}
                                                <Botao onClick={() => acoes.agendar(oferta)} data-acao="agendar"><CalendarPlus size={14} /> Agendar</Botao>
                                                <Botao variante="fantasma" onClick={() => acoes.editarOferta(oferta)} data-acao="editar-oferta"><Pencil size={14} /> Editar</Botao>
                                                <Botao variante="fantasma" className="text-white/45 hover:text-red-300" onClick={excluirOferta} aria-label="Excluir oferta" data-acao="excluir-oferta"><Trash2 size={14} /></Botao>
                                            </div>
                                        </div>

                                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                                            {['classico', 'premium'].map((tipo) => (
                                                <EstacaoColuna key={tipo} tipo={tipo} oferta={oferta} detalhe={detalhe} metricas={mapaMetricas}
                                                    selecionado={selecionado} onSelecionar={setSelecionado} vocabulario={vocabulario}
                                                    onNovoAnuncio={() => acoes.novoAnuncio(oferta, tipo)} onAgendar={() => acoes.agendar(oferta)} />
                                            ))}
                                        </div>
                                        {detalhes[oferta.id]?.erro && <p className="mt-2 text-[12px] text-red-300/80">{detalhes[oferta.id].erro}</p>}
                                    </section>

                                    <section className={painel('metricas')}>
                                        <EstacaoGrafico oferta={oferta} metricas={metricas} conectado={mlConectado} vocabulario={vocabulario} />
                                    </section>

                                    <section className={painel('agenda')}>
                                        <EstacaoAgenda oferta={oferta} vocabulario={vocabulario} onAgendar={() => acoes.agendar(oferta)} onConcluir={(tipo) => acoes.concluir(oferta, tipo)} />
                                    </section>
                                </div>
                            </main>

                            {/* ── Inspetor do anúncio ── */}
                            {anuncioSelecionado && (
                                <aside className="absolute inset-0 z-10 overflow-y-auto bg-ecf-bg lg:static lg:w-80 lg:shrink-0 lg:border-l lg:border-white/[0.08]">
                                    <EstacaoInspetor oferta={oferta} anuncio={anuncioSelecionado}
                                        detalhe={detalhe?.[anuncioSelecionado.codigo_mlb]} metrica={mapaMetricas?.[anuncioSelecionado.codigo_mlb]}
                                        vocabulario={vocabulario}
                                        onEditar={() => acoes.editarAnuncio(oferta, anuncioSelecionado)}
                                        onExcluir={() => excluirAnuncio(anuncioSelecionado)}
                                        onFechar={() => setSelecionado(null)} />
                                </aside>
                            )}
                        </div>
                    )}

                    {! dados && ! erro && (
                        <p className="flex items-center justify-center gap-2 py-16 text-[13px] text-white/45"><Loader2 size={15} className="animate-spin" /> Abrindo a estação…</p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
