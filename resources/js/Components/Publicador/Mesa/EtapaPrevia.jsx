import { useEffect, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Images, Info, Loader2, RefreshCw, X } from 'lucide-react';
import { mensagemDe } from '../apoio';
import { anuncioEscolhido, notasDaPrevia } from '../previa';
import AnuncioNoMl from './AnuncioNoMl';
import CartaoFotosVariante from './CartaoFotosVariante';
import { FotosParaTodas, fotosDaVariante } from './FotosEVariacoes';
import { LINK, Secao } from './comum';
import { cn } from '@/lib/utils';

// ─── Etapa 5 — Prévia (20/10/2026) ──────────────────────────────────────────
//
// Pedido do usuário depois da reunião de 19/10: antes de publicar, quem cuida
// da publicação vê o anúncio como ele vai ficar no Mercado Livre — fotos na
// ordem, título, preço com a promoção da Central, frete, ficha e descrição — e
// publica DAQUI (Conferir e Publicar vieram da etapa 4). O agente revisor lê o
// mesmo retrato pelo servidor (`PreviaDoAnuncioService`).
//
// Um anúncio por vez (Clássico | Premium no topo; a variação, no seletor da
// própria página, como no ML). Ao abrir, salva o que estiver pendente e pede a
// prévia; ela se refaz quando a revisão do rascunho muda.

/** Pede a prévia ao servidor depois de descarregar o pendente; refaz a cada revisão nova ou a pedido. */
function usePrevia({ pub, produtoId }) {
    const [previa, setPrevia] = useState(null);
    const [carregando, setCarregando] = useState(true);
    const [erro, setErro] = useState(null);
    const [vez, setVez] = useState(0);
    const revisao = pub.m.estado?.rascunho?.revisao ?? null;

    useEffect(() => {
        let vivo = true;
        (async () => {
            setCarregando(true);
            try {
                await pub.descarregar();
                const { data } = await axios.get(route('mlb.anuncios.publicador.previa', { produto: produtoId }));
                if (vivo) {
                    setPrevia(data);
                    setErro(null);
                }
            } catch (e) {
                if (vivo) setErro(mensagemDe(e));
            } finally {
                if (vivo) setCarregando(false);
            }
        })();

        return () => { vivo = false; };
    }, [produtoId, revisao, vez]); // eslint-disable-line react-hooks/exhaustive-deps

    return { previa, carregando, erro, recarregar: () => setVez((n) => n + 1) };
}

/**
 * "Editar fotos" na Prévia (27/10/2026). Pedido do usuário: mudar, excluir e mover as fotos sem sair da Prévia. São os
 * MESMOS blocos da etapa Imagens (o da variação vista e, havendo variações, "Fotos para todas"), sobre o mesmo
 * rascunho: cada mudança sobe a revisão e a prévia se refaz sozinha (`usePrevia`). Nada de mexer na ordem pela galeria
 * do ML: ela junta as fotos da variação com as gerais e, no Premium, a capa pode trocar de lugar com uma ambientada
 * (`OrdemCapaPorAlvo`) — mover ali não diria em que grupo a foto mudou.
 */
function FotosDaPrevia({ m, anuncio, onFechar }) {
    const estado = m.estado;
    const eixos = estado?.eixos ?? [];
    const temVariacoes = eixos.some((e) => (e.valores ?? []).length > 0);
    const chave = anuncio?.variante?.chave ?? null;
    const v = (m.variantes ?? []).find((x) => x.chave === chave && ! x.orfa) ?? null;
    const rotulos = Object.fromEntries((m.variantes ?? []).map((x) => [x.chave, x.rotulo]));
    const { grupo, com } = v ? fotosDaVariante(estado, v, rotulos) : { grupo: null, com: [] };
    const premium = anuncio?.listing_type_id === 'gold_pro';

    return (
        <section className="space-y-4 rounded-xl border border-white/[0.12] bg-white/[0.02] p-5 max-sm:p-4" data-previa-editar-fotos={chave ?? ''}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-[15px] font-bold text-white">Fotos deste anúncio</h3>
                    <p className="mt-1 text-[13px] text-white/55">
                        Troque, exclua ou mude a ordem aqui mesmo; a prévia se atualiza sozinha. Vale para o Clássico e o Premium.
                        {premium && ' No Premium a 1ª foto pode trocar de lugar com uma ambientada, para o anúncio não abrir igual ao Clássico.'}
                    </p>
                </div>
                <button type="button" onClick={onFechar} className={LINK} data-acao="fechar-editar-fotos">
                    <X size={14} aria-hidden="true" /> Fechar
                </button>
            </div>
            {v ? (
                <CartaoFotosVariante m={m} v={v} eixos={eixos} grupo={grupo} fotosCom={com} />
            ) : (
                <p className="text-[13px] text-white/55">Esta variação não está mais no rascunho. Atualize a prévia.</p>
            )}
            {temVariacoes && <FotosParaTodas m={m} />}
        </section>
    );
}

export default function EtapaPrevia({ pub, empresa, produtoId, children }) {
    const { previa, carregando, erro, recarregar } = usePrevia({ pub, produtoId });
    const [tipo, setTipo] = useState(null);
    const [variante, setVariante] = useState(null);
    const [editandoFotos, setEditandoFotos] = useState(false);
    // Demo 23/10 (A1): DESMARCADA de início — em 22/10 o ML recusou a promoção de todo anúncio novo
    // ("No candidates"); mostrar o preço riscado por padrão prometia um desconto que não sai.
    const [comPromocao, setComPromocao] = useState(false);

    const tipos = Array.isArray(previa?.tipos) ? previa.tipos : [];
    const anuncio = anuncioEscolhido(previa, tipo ?? tipos[0]?.listing_type_id ?? null, variante);
    const notas = notasDaPrevia(previa, anuncio, comPromocao);
    // Demo 23/10 (A9): o comprador vê o APELIDO da conta ("MGSTOREL"), não o nome cadastrado da empresa;
    // sem o apelido lido (conta lida antes de 23/10, ou leitura falhou), o nome cadastrado.
    const vendedor = pub.m.estado?.conta?.nickname || empresa?.conta_nome || empresa?.nome || null;

    return (
        <div className="space-y-6" data-etapa-conteudo="previa">
            <Secao
                id="previa"
                titulo="Prévia"
                descricao="Assim o comprador vai ver o anúncio no Mercado Livre. É o mesmo conteúdo que vai na publicação: confira e publique logo abaixo."
                acao={(
                    <div className="flex flex-wrap items-center gap-4">
                        {previa?.pronta && anuncio && (
                            <button type="button" onClick={() => setEditandoFotos((x) => ! x)} aria-expanded={editandoFotos} className={LINK} data-acao="editar-fotos-previa">
                                {editandoFotos ? <X size={14} aria-hidden="true" /> : <Images size={14} aria-hidden="true" />} {editandoFotos ? 'Fechar fotos' : 'Editar fotos'}
                            </button>
                        )}
                        <button type="button" onClick={recarregar} disabled={carregando} className={LINK} data-acao="atualizar-previa">
                            {carregando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <RefreshCw size={14} aria-hidden="true" />} Atualizar prévia
                        </button>
                    </div>
                )}
            >
                {previa?.pronta && anuncio && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            {tipos.length > 0 && (
                                <div role="group" aria-label="Tipo de anúncio" className="inline-flex rounded-lg border border-white/[0.10] bg-white/[0.03] p-1" data-previa-tipos>
                                    {tipos.map((t) => (
                                        <button key={t.listing_type_id} type="button" onClick={() => setTipo(t.listing_type_id)}
                                            aria-pressed={anuncio.listing_type_id === t.listing_type_id}
                                            className={cn('h-8 rounded-md px-4 text-[13px] font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                                anuncio.listing_type_id === t.listing_type_id ? 'bg-white/[0.12] text-white' : 'text-white/55 hover:text-white')}
                                            data-previa-tipo={t.listing_type_id}>
                                            {t.nome}
                                        </button>
                                    ))}
                                </div>
                            )}
                            {anuncio.promocao && (
                                <label className="inline-flex cursor-pointer items-center gap-2 text-[13px] text-white/80">
                                    <input type="checkbox" checked={comPromocao} onChange={(e) => setComPromocao(e.target.checked)}
                                        className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-previa-com-promocao />
                                    Com a promoção da Central
                                </label>
                            )}
                        </div>
                        {notas.length > 0 && (
                            <ul className="space-y-1 text-[13px] text-white/60" data-previa-notas>
                                {notas.map((n) => (
                                    <li key={n} className="flex items-start gap-2"><Info size={14} className="mt-0.5 shrink-0 text-white/40" aria-hidden="true" /> <span>{n}</span></li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {erro && (
                    <p role="alert" className="flex items-start gap-2 text-[13px] text-red-300" data-previa-erro>
                        <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" /> Não foi possível montar a prévia. {erro}
                    </p>
                )}
                {! erro && previa && ! previa.pronta && (
                    <p className="flex items-start gap-2 text-[15px] text-white/70" data-previa-motivo>
                        <Info size={16} className="mt-0.5 shrink-0 text-white/45" aria-hidden="true" /> {previa.motivo ?? 'A prévia ainda não tem o que mostrar.'}
                    </p>
                )}
                {! previa && carregando && <div aria-hidden="true" className="h-[480px] animate-pulse rounded-xl bg-white/[0.04]" data-previa-carregando />}
            </Secao>

            {previa?.pronta && anuncio && editandoFotos && (
                <FotosDaPrevia m={pub.m} anuncio={anuncio} onFechar={() => setEditandoFotos(false)} />
            )}

            {previa?.pronta && anuncio && (
                <div className={cn('transition-opacity', carregando && 'opacity-60')} aria-busy={carregando}>
                    <AnuncioNoMl key={anuncio.indice} previa={previa} anuncio={anuncio} comPromocao={comPromocao} vendedor={vendedor} onVariante={setVariante} />
                </div>
            )}

            {children}
        </div>
    );
}
