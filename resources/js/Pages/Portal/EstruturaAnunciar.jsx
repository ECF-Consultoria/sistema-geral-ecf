import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { MousePointerClick, PlugZap, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, CabecalhoEstrutura, LinkMl, Paginacao, Seletor, fmtReais } from '@/Components/Portal/Estrutura/comum';
import FormPublicacao from '@/Components/Portal/Estrutura/FormPublicacao';
import EditorPublicador from '@/Components/Publicador/EditorPublicador';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — submódulo Anunciar ─────────────────────────────
//
// Publicar no Mercado Livre o PAR Clássico + Premium de cada oferta, pelo
// portal (29/09, ADR PORTAL-03). À esquerda, as ofertas que faltam anunciar
// (e as já publicadas, apagadas); no centro, o formulário da oferta escolhida.
//
// Tudo o que decide mora no PHP: o que falta em cada card (`prontidao`), o
// preço (da Precificação), a conferência com o ML e a trava que impede
// publicar duas vezes. Esta tela desenha e chama — a lista vem paginada do
// servidor (Inertia), o formulário vem por JSON (`FormPublicacao`).
//
// Empresas do piloto do Publicador novo (`publicador_novo`, 01/10/2026) usam
// o `EditorPublicador`: variações, fotos por grupo, conferência e publicação
// em fila. As demais seguem no par.

const ESTILO_PRONTIDAO = {
    pronto:     'border-emerald-500/25 bg-emerald-500/10 text-emerald-300',
    conferir:   'border-sky-500/25 bg-sky-500/10 text-sky-300',
    publicado:  'border-white/10 bg-white/[0.05] text-white/45',
    publicando: 'border-ecf-yellow/30 bg-ecf-yellow/10 text-ecf-yellow',
    parcial:    'border-amber-500/25 bg-amber-500/10 text-amber-300',
    erro:       'border-red-500/25 bg-red-500/10 text-red-300',
};
const estiloProntidao = (chave) => ESTILO_PRONTIDAO[chave] ?? 'border-amber-500/25 bg-amber-500/10 text-amber-300';

function CardOferta({ oferta, selecionada, onSelecionar }) {
    const publicada = oferta.prontidao.chave === 'publicado';

    return (
        <button type="button" onClick={() => onSelecionar(oferta.id)} aria-pressed={selecionada}
            data-card-oferta={oferta.id} data-prontidao={oferta.prontidao.chave}
            className={cn('w-full rounded-2xl border p-3 text-left transition-colors',
                selecionada ? 'border-ecf-yellow bg-ecf-yellow/[0.04]' : 'border-white/[0.08] bg-ecf-card hover:border-white/20',
                publicada && ! selecionada && 'opacity-60')}>
            <div className="flex items-start justify-between gap-2">
                <span className="truncate rounded-md border border-white/10 bg-white/[0.03] px-1.5 py-0.5 font-mono text-[11.5px] text-white/80" title={oferta.sku}>{oferta.sku}</span>
                <span className={cn('inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap', estiloProntidao(oferta.prontidao.chave))}>
                    <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden />{oferta.prontidao.rotulo}
                </span>
            </div>
            <p className="mt-2 truncate text-[13.5px] font-semibold text-white" title={oferta.nome ?? ''}>{oferta.nome ?? oferta.sku}</p>
            <div className="mt-2 flex items-center justify-between gap-2 text-[12px] text-white/45">
                {publicada ? (
                    <span className="flex flex-wrap gap-x-3 gap-y-1">
                        <LinkMl mlb={oferta.anuncios.classico} className="text-[11.5px]" />
                        <LinkMl mlb={oferta.anuncios.premium} className="text-[11.5px]" />
                    </span>
                ) : (
                    <>
                        <span>Dupla: Clássico + Premium</span>
                        <span className="font-mono tabular-nums text-white/60" data-preco-classico>{oferta.preco_classico !== null ? `${fmtReais(oferta.preco_classico)}+` : '—'}</span>
                    </>
                )}
            </div>
        </button>
    );
}

function SemConta() {
    return (
        <section className="space-y-3 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-vazio="sem-conta">
            <PlugZap size={26} className="mx-auto text-ecf-yellow" />
            <p className="text-[15px] font-semibold text-white">Conecte a conta do Mercado Livre para anunciar por aqui</p>
            <p className="mx-auto max-w-lg text-[13px] leading-relaxed text-white/50">
                O Anunciar publica o Clássico e o Premium de cada oferta direto na sua conta. A conexão é feita no Onboarding, em um clique — depois é só voltar aqui.
            </p>
            <a href={route('portal.auth.onboarding')} className="inline-flex rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90" data-acao="conectar-ml">
                Ir para o Onboarding e conectar
            </a>
        </section>
    );
}

function SemOfertas() {
    return (
        <section className="space-y-3 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-vazio="sem-ofertas">
            <p className="text-[15px] font-semibold text-white">Os produtos vêm da Lista SKUs</p>
            <p className="mx-auto max-w-lg text-[13px] text-white/50">Liste os produtos, dê o preço na Precificação e escreva os títulos em Anúncios; cada oferta aparece aqui pronta para ir ao ar.</p>
            <a href={route('portal.auth.estrutura.lista')} className="inline-flex rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90">
                Ir para a Lista SKUs
            </a>
        </section>
    );
}

export default function EstruturaAnunciar({ empresa, modulos = [], anunciar, filtros, vocabulario, ml_conectado = false, publicador_novo = false }) {
    const { filtro, contagens, ofertas, paginacao } = anunciar;
    const [selecionada, setSelecionada] = useState(null);
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [aula, setAula] = useState(false);

    const visitar = (params) => router.get(route('portal.auth.estrutura.anunciar'), params, {
        preserveState: true, preserveScroll: true, replace: true, only: ['anunciar', 'filtros'],
    });

    const primeiraVez = useRef(true);
    useEffect(() => {
        if (primeiraVez.current) { primeiraVez.current = false; return; }
        const t = setTimeout(() => visitar({ filtro, q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    // A primeira da página abre sozinha. Depois de publicar, a oferta troca de
    // lado e some desta lista — mas o formulário fica, com os dois MLB.
    useEffect(() => {
        if (selecionada === null && ofertas.length) setSelecionada(ofertas[0].id);
    }, [ofertas]); // eslint-disable-line react-hooks/exhaustive-deps

    const trocarFiltro = (f) => { setSelecionada(null); visitar({ filtro: f, q: busca || undefined }); };
    const recarregarLista = () => router.reload({ only: ['anunciar'] });

    const semNada = contagens.a_anunciar + contagens.publicados === 0;

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Anunciar">
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="anunciar" onComoFunciona={() => setAula(true)}
                    descricao="Publique no Mercado Livre o Clássico e o Premium de cada oferta, juntos. Categoria, fotos e ficha uma vez; título e preço de cada tipo já vêm de Anúncios e Precificação." />

                {! ml_conectado ? <SemConta /> : semNada ? <SemOfertas /> : (
                    <div className="lg:grid lg:grid-cols-[320px_minmax(0,1fr)] lg:items-start lg:gap-4">
                        <aside className="space-y-3 lg:sticky lg:top-4" data-anunciar-lista>
                            <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="text-[13.5px] font-semibold text-white">Prontos para anunciar</h2>
                                    <span className="font-mono text-[11px] text-white/40" data-total-lista>{paginacao.total} {paginacao.total === 1 ? 'item' : 'itens'}</span>
                                </div>
                                <div className="mt-2 grid grid-cols-2 gap-1 rounded-xl bg-white/[0.03] p-1" role="tablist" data-filtro-anunciar={filtro}>
                                    {[['a_anunciar', 'A anunciar'], ['publicados', 'Publicados']].map(([f, r]) => (
                                        <button key={f} type="button" role="tab" aria-selected={filtro === f} onClick={() => trocarFiltro(f)} data-filtro={f}
                                            className={cn('rounded-lg px-2 py-1.5 text-[12.5px] transition-colors',
                                                filtro === f ? 'bg-white/[0.08] font-semibold text-white' : 'text-white/50 hover:text-white')}>
                                            {r} <span className="font-mono text-[11px] text-white/40">({contagens[f]})</span>
                                        </button>
                                    ))}
                                </div>
                                <div className="relative mt-2">
                                    <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                                    <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU ou nome…" data-busca
                                        className="w-full rounded-lg border border-white/[0.10] bg-white/[0.04] py-1.5 pl-7 pr-7 text-[12.5px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0" />
                                    {busca && (
                                        <button type="button" onClick={() => setBusca('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                            <X size={13} />
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* No celular a lista vira um seletor; o formulário ocupa a largura. */}
                            <div className="lg:hidden">
                                <Seletor valor={selecionada === null ? '' : String(selecionada)} onChange={(v) => setSelecionada(v === null ? null : Number(v))}
                                    opcoes={Object.fromEntries(ofertas.map((o) => [o.id, `${o.sku} — ${o.nome ?? ''} · ${o.prontidao.rotulo}`]))}
                                    vazio="Escolha uma oferta…" aria-label="Oferta" data-seletor-oferta />
                            </div>

                            <div className="hidden space-y-2 lg:block">
                                {ofertas.map((o) => <CardOferta key={o.id} oferta={o} selecionada={o.id === selecionada} onSelecionar={setSelecionada} />)}
                                {ofertas.length === 0 && <p className="py-6 text-center text-[13px] text-white/45">Nenhuma oferta com essa busca.</p>}
                            </div>

                            {paginacao.paginas > 1 && (
                                <Paginacao paginacao={{ ...paginacao, blocos: paginacao.total }} rotulo="ofertas"
                                    onIr={(pagina) => { setSelecionada(null); visitar({ filtro, q: busca || undefined, pagina }); }} />
                            )}
                        </aside>

                        <section className="mt-4 min-w-0 lg:mt-0">
                            {selecionada !== null ? (
                                publicador_novo
                                    ? <EditorPublicador key={selecionada} ofertaId={selecionada} onPublicou={recarregarLista} />
                                    : <FormPublicacao key={selecionada} ofertaId={selecionada} vocabulario={vocabulario} onPublicou={recarregarLista} />
                            ) : (
                                <div className="flex items-center gap-3 rounded-2xl border border-white/[0.08] bg-ecf-card p-5 text-[13px] text-white/50" data-form-vazio>
                                    <MousePointerClick size={18} className="shrink-0 text-ecf-yellow" />
                                    Selecione uma oferta à esquerda para preencher os dados e publicar o Clássico + Premium de uma vez.
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
