import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { CheckSquare, Plus, Send } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import ResumoDoSincronizar from '@/Components/Mlb/Publicador/ResumoDoSincronizar';
import { criarAcompanhamento } from '@/Components/Mlb/Publicador/acompanhamentoDoSincronizar.js';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import AvisoDaFila from '@/Components/Mlb/Publicador/AvisoDaFila';
import { destinoDoLote } from '@/Components/Mlb/Publicador/AcoesDaSelecaoEmLote';
import CartaoDaVitrine from '@/Components/Mlb/Publicador/vitrine/CartaoDaVitrine';
import { FILTROS_DA_VITRINE, filtrar, filtroInicial, listaSegura, resumoDaSelecao } from '@/Components/Mlb/Publicador/vitrine/vitrine';
import { cn } from '@/lib/utils';

// ─── Produtos da conta em VITRINE de cartões (20/10/2026) ───────────────────
//
// Protótipo pedido pelo usuário depois da reunião de 19/10 (a referência B do
// Gemini, "vitrine de cartões"), atrás de `publicador.vitrine`. O fluxo é o
// combinado: o robô prepara, a pessoa revisa pela Prévia e publica em massa —
// por isso o único BOTÃO amarelo do corpo é "Publicar N produtos", que aparece
// com a seleção e leva os marcados para a fila de publicação em lote (que
// confere antes de agendar). O amarelo do filtro ativo é só marcação.
//
// Ficaram de fora, de propósito (estavam na lista e repetiam informação): a
// faixa grande de "publicação não liberada" (o selo do cabeçalho já diz), a
// segunda busca, "Confortável / Compacto" e os cartões de explicação.

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

export default function ProdutosVitrine({ empresa, liberada = false, cartoes = [], contagens = {}, cabecalho = null, fila_publicacao = null }) {
    const [filtro, setFiltro] = useState(() => filtroInicial(contagens));
    const [selecao, setSelecao] = useState(() => new Set());
    const [modal, setModal] = useState(false);
    const [status, setStatus] = useState(null);
    const [resumo, setResumo] = useState(null);
    const [acompanhando, setAcompanhando] = useState(false);
    const resumoPronto = useRef(false);

    const lista = listaSegura(cartoes);
    const visiveis = useMemo(() => filtrar(lista, filtro), [lista, filtro]);
    const selecionados = resumoDaSelecao(lista, selecao);
    const prontosAVista = visiveis.filter((c) => c.selecionavel && c.grupo === 'prontos');
    const skus = useMemo(() => lista.map((c) => c.sku).filter(Boolean), [lista]);
    const podeSincronizar = Boolean(empresa?.company_id) && empresa?.portal?.situacao !== 'sem_portal';

    // O mesmo acompanhamento do Sincronizar da lista: lê o resumo até ficar pronto e recarrega os cartões.
    const conta = useRef(empresa?.chave);
    conta.current = empresa?.chave;
    const acompanhamento = useRef(null);
    if (acompanhamento.current === null) {
        acompanhamento.current = criarAcompanhamento({
            ler: async (pedido) => (await axios.get(route('mlb.anuncios.publicador.sincronizar.resumo', { conta: conta.current, pedido }))).data,
            aoLer: (r) => {
                setResumo(r);
                if (r?.status === 'pronto' && ! resumoPronto.current) {
                    resumoPronto.current = true;
                    router.reload({ only: ['cartoes', 'contagens'] });
                }
            },
            aoMudar: setAcompanhando,
            aoExpirar: () => setResumo((r) => (r ? { ...r, status: 'expirou' } : r)),
        });
    }
    useEffect(() => () => acompanhamento.current.cancelar(), []);

    function aoConcluirSync(json) {
        resumoPronto.current = false;
        if (json?.pedido) {
            setResumo({ status: 'preenchendo', total: json.preenchendo ?? 0, concluidos: 0 });
            acompanhamento.current.acompanhar(json.pedido);
        } else {
            setResumo(null);
        }
        setStatus({ tipo: 'ok', texto: json?.criados > 0 ? json.mensagem : 'Nada novo: todos os produtos do Portal já estão aqui.' });
        router.reload({ only: ['cartoes', 'contagens', 'empresa'] });
    }

    const marcar = (id) => setSelecao((s) => {
        const nova = new Set(s);
        if (nova.has(id)) {
            nova.delete(id);
        } else {
            nova.add(id);
        }

        return nova;
    });
    const marcarProntos = () => setSelecao(new Set(prontosAVista.map((c) => Number(c.produto_id))));

    return (
        <AppLayout title={`Publicador — ${empresa?.nome ?? ''}`}>
            <PublicadorHeader
                aba="produtos"
                cabecalho={cabecalho ?? null}
                liberada={liberada}
                acoes={(
                    <>
                        {podeSincronizar && (
                            <BotaoSincronizarPortal conta={empresa.chave} onConcluido={aoConcluirSync} desabilitado={acompanhando}
                                onErro={(texto) => setStatus({ tipo: 'erro', texto })} />
                        )}
                        <button type="button" onClick={() => setModal(true)} className={BOTAO_SECUNDARIO}>
                            <Plus className="h-4 w-4" aria-hidden="true" /> Produto
                        </button>
                    </>
                )}
            />

            <div className="mx-auto max-w-[1320px] px-8 pb-32 pt-6 font-pub" data-vitrine>
                <AvisoDaFila fila={fila_publicacao} />
                <ResumoDoSincronizar resumo={resumo} onFechar={() => { acompanhamento.current.cancelar(); setResumo(null); }} />
                {status && (
                    <p role="status" className={cn('mb-4 text-[13px]', status.tipo === 'erro' ? 'text-pub-danger-txt' : 'text-pub-txt-2')}>{status.texto}</p>
                )}

                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Filtrar produtos" className="flex flex-wrap items-center gap-2" data-vitrine-filtros>
                        {FILTROS_DA_VITRINE.map((f) => {
                            const ativo = filtro === f.chave;
                            const n = Number(contagens?.[f.chave] ?? 0);

                            return (
                                <button key={f.chave} type="button" onClick={() => setFiltro(f.chave)} aria-pressed={ativo}
                                    className={cn('inline-flex h-9 items-center gap-2 rounded-full border px-4 text-[13px] font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent',
                                        ativo ? 'border-pub-accent bg-pub-accent text-pub-page' : 'border-pub-line bg-pub-surface text-pub-txt-2 hover:bg-pub-hover-2 hover:text-pub-txt')}
                                    data-vitrine-filtro={f.chave}>
                                    {f.rotulo}
                                    <span className={cn('rounded-full px-1.5 text-[11px] tabular-nums', ativo ? 'bg-pub-page/15' : 'bg-pub-hover-3')}>{n}</span>
                                </button>
                            );
                        })}
                    </div>
                    {prontosAVista.length > 0 && (
                        <button type="button" onClick={marcarProntos} className="inline-flex items-center gap-2 rounded text-[13px] font-medium text-pub-txt-2 hover:text-pub-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent" data-acao="marcar-prontos">
                            <CheckSquare size={15} aria-hidden="true" /> Marcar os {prontosAVista.length} prontos
                        </button>
                    )}
                </div>

                {visiveis.length === 0 ? (
                    <div className="rounded-pub-panel border border-pub-line bg-pub-surface p-10 text-center" data-vitrine-vazia>
                        <p className="text-[15px] font-semibold text-pub-txt">{lista.length === 0 ? 'Nenhum produto nesta conta ainda.' : 'Nenhum produto neste filtro.'}</p>
                        <p className="mt-1 text-[13px] text-pub-txt-3">
                            {lista.length === 0 ? 'Os produtos que o cliente cadastra no Portal entram aqui pelo "Sincronizar do Portal", ou crie um em "+ Produto".' : 'Troque o filtro acima para ver os outros.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(250px,1fr))] gap-4" data-vitrine-grade>
                        {visiveis.map((c) => (
                            <CartaoDaVitrine key={c.produto_id} cartao={c} marcado={selecao.has(Number(c.produto_id))} onMarcar={(id) => marcar(Number(id))} />
                        ))}
                    </div>
                )}
            </div>

            {selecionados.produtos > 0 && (
                <div className="fixed inset-x-0 bottom-0 z-30 border-t border-pub-line bg-pub-page/95 backdrop-blur" data-vitrine-selecao>
                    <div className="mx-auto flex max-w-[1320px] flex-wrap items-center justify-between gap-3 px-8 py-3 font-pub">
                        <p className="text-[14px] text-pub-txt">
                            <span className="font-semibold">{selecionados.produtos} {selecionados.produtos === 1 ? 'selecionado' : 'selecionados'}</span>
                            <span className="text-pub-txt-3"> · {selecionados.anuncios} {selecionados.anuncios === 1 ? 'anúncio' : 'anúncios'} (Clássico e Premium)</span>
                            <button type="button" onClick={() => setSelecao(new Set())} className="ml-4 rounded text-[13px] font-medium text-pub-accent hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent" data-acao="limpar-selecao">
                                Limpar seleção
                            </button>
                        </p>
                        <Link href={destinoDoLote(empresa.chave, selecionados.ids)}
                            className="inline-flex h-11 items-center gap-2 rounded-pub-control bg-pub-accent px-5 text-[14px] font-semibold text-pub-page hover:bg-pub-accent-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent"
                            data-acao="publicar-selecionados">
                            <Send size={16} aria-hidden="true" /> Publicar {selecionados.produtos} {selecionados.produtos === 1 ? 'produto' : 'produtos'}
                        </Link>
                    </div>
                </div>
            )}

            <ModalNovoProduto aberto={modal} onFechar={() => setModal(false)} conta={empresa?.chave} skusExistentes={skus} />
        </AppLayout>
    );
}
