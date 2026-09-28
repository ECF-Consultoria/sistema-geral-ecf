import { useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { DialogTitle } from '@radix-ui/react-dialog';
import { CalendarPlus, Pencil, Plus, Trash2 } from 'lucide-react';
import { Sheet, SheetContent } from '@/Components/ui/sheet';
import { Botao, EstoqueOferta, Indicadores, Lado, PilulaSituacao, PrecosDaOferta, Vendas, fmtData } from './comum';
import { BotaoMetricas, useMetricasMl } from './MetricasMl';
import TabelaAnuncios from './TabelaAnuncios';

// ─── A gaveta de uma oferta ─────────────────────────────────────────────────
//
// Tudo o que é DESTA oferta: dados, composição, os anúncios que ela tem no ar
// e a agenda dela. A oferta vem sempre da página (props), nunca de cópia
// local — depois de cada escrita o Inertia recarrega a página e a gaveta
// mostra o estado novo sem precisar de F5 (`portal-do-cliente.md` §10).

const FASE_ROTULO = { simples: 'Produto', combo: 'Combo', kit: 'Kit', combit: 'Combit' };

export default function GavetaOferta({ oferta, onFechar, vocabulario, onEditar, onNovoAnuncio, onEditarAnuncio, onAgendar, mlConectado = false, autoMetricas = false }) {
    const [confirmandoExclusao, setConfirmandoExclusao] = useState(false);
    const [erro, setErro] = useState(null);
    const [skus, setSkus] = useState({ mapa: {}, lendo: false, erro: null });
    const metricas = useMetricasMl(mlConectado ? oferta?.id : null, autoMetricas);
    const totalMetricas = (campo) => Object.values(metricas.estado?.metricas ?? {}).reduce((t, m) => t + (m[campo] ?? 0), 0);

    // O SKU de cada anúncio vem do ML ao abrir a gaveta (e de novo quando os
    // anúncios da oferta mudam). O mapa é por MLB — único na empresa —, então
    // trocar de oferta não mostra SKU de outra.
    const mlbs = oferta ? oferta.anuncios.map((a) => a.codigo_mlb).filter(Boolean).join(',') : '';
    useEffect(() => {
        if (! oferta || ! mlConectado || ! mlbs) return undefined;
        let viva = true;
        setSkus((s) => ({ ...s, lendo: true, erro: null }));
        axios.get(route('portal.auth.estrutura.anuncios_ml.skus', oferta.id))
            .then(({ data }) => viva && setSkus((s) => ({ mapa: { ...s.mapa, ...(data.skus ?? {}) }, lendo: false, erro: data.erro ?? null })))
            .catch(() => viva && setSkus((s) => ({ ...s, lendo: false, erro: 'Não foi possível ler os SKUs no Mercado Livre agora.' })));

        return () => { viva = false; };
    }, [oferta?.id, mlbs, mlConectado]); // eslint-disable-line react-hooks/exhaustive-deps

    const aberta = !! oferta;

    const excluirOferta = () => router.delete(route('portal.auth.estrutura.ofertas.excluir', oferta.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { setConfirmandoExclusao(false); onFechar(); },
        onError: (e) => setErro(e.oferta ?? 'Não foi possível excluir.'),
    });

    const excluirAnuncio = (a) => {
        if (! window.confirm(`Excluir o anúncio ${vocabulario.tipos[a.tipo]}${a.codigo_mlb ? ` ${a.codigo_mlb}` : ''}?`)) return;
        router.delete(route('portal.auth.estrutura.anuncios.excluir', a.id), { preserveScroll: true, preserveState: true });
    };

    const excluirAgenda = (item) => router.delete(route('portal.auth.estrutura.agenda.excluir', item.id), { preserveScroll: true, preserveState: true });

    return (
        <Sheet open={aberta} onOpenChange={(v) => { if (! v) { setConfirmandoExclusao(false); setErro(null); onFechar(); } }}>
            <SheetContent className="overflow-y-auto sm:max-w-3xl">
                {oferta && (
                    <div className="px-6 py-5 space-y-5 text-white" data-gaveta={oferta.id}>
                        <div className="pr-8">
                            <p className="text-[11.5px] uppercase tracking-wide text-white/40">
                                {FASE_ROTULO[oferta.fase]} · {oferta.unidades} un.
                            </p>
                            <DialogTitle className="font-display text-xl font-bold font-mono break-all">{oferta.sku}</DialogTitle>
                            {oferta.nome && <p className="text-white/65 text-[14px]">{oferta.nome}</p>}
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <PilulaSituacao situacao={oferta.situacao} longa vocabulario={vocabulario} />
                                {oferta.sku_repetido && <span className="text-[11.5px] text-amber-300">SKU repetido em outra oferta</span>}
                            </div>
                        </div>

                        {oferta.componentes.length > 0 && (
                            <section>
                                <h3 className="text-[12px] uppercase tracking-wide text-white/40 mb-1.5">Composição</h3>
                                <p className="text-[13px] text-white/80">
                                    {oferta.componentes.map((c) => `${c.nome ?? c.sku} ×${c.quantidade}`).join(' + ')}
                                </p>
                            </section>
                        )}

                        <section className="grid grid-cols-2 gap-3 text-[13px]">
                            <div>
                                <p className="text-[12px] text-white/40">Logística</p>
                                <p className="text-white/80">{vocabulario.logisticas[oferta.logistica] ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-[12px] text-white/40">Observações</p>
                                <p className="text-white/80 whitespace-pre-line">{oferta.observacoes || '—'}</p>
                            </div>
                        </section>

                        <section>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-[12px] uppercase tracking-wide text-white/40">Anúncios no Mercado Livre</h3>
                                <Botao variante="fantasma" onClick={() => onNovoAnuncio(oferta)} data-acao="novo-anuncio"><Plus size={14} /> Anúncio</Botao>
                            </div>
                            <div className="flex flex-wrap items-center gap-3 mb-2">
                                <Lado rotulo={vocabulario.tipos_curtos.classico} quantidade={oferta.classicos} />
                                <Lado rotulo={vocabulario.tipos_curtos.premium} quantidade={oferta.premiums} />
                                <Indicadores catalogos={oferta.catalogos} kitsVirtuais={oferta.kits_virtuais} />
                                <EstoqueOferta estoque={oferta.estoque} className="text-[12px]" />
                                {oferta.vendas > 0 && <Vendas quantidade={oferta.vendas} className="text-[12px]" />}
                                {skus.lendo && <span className="text-[11.5px] text-white/35" data-lendo-skus>lendo os SKUs no Mercado Livre…</span>}
                                {skus.erro && <span className="text-[11.5px] text-red-300/80">{skus.erro}</span>}
                            </div>
                            {oferta.precos?.invertido && <PrecosDaOferta precos={oferta.precos} vocabulario={vocabulario} className="mb-2 text-[12px]" />}
                            {mlConectado && oferta.anuncios.some((a) => a.codigo_mlb) && (
                                <div className="mb-1"><BotaoMetricas estado={metricas.estado} carregar={metricas.carregar} /></div>
                            )}
                            {oferta.anuncios.length === 0
                                ? <p className="text-[12.5px] text-white/40">Nenhum anúncio cadastrado. Publique em Clássico e Premium.</p>
                                : (
                                    <TabelaAnuncios oferta={oferta} vocabulario={vocabulario} skus={skus.mapa}
                                        metricas={metricas.estado?.metricas ?? null}
                                        onEditar={(a) => onEditarAnuncio(oferta, a)} onExcluir={excluirAnuncio} />
                                )}
                            {metricas.estado?.metricas && (
                                <p className="mt-2 text-[11.5px] text-white/45" data-metricas-total>
                                    Últimos 7 dias: <strong className="text-white/80">{totalMetricas('visitas').toLocaleString('pt-BR')}</strong> visitas ·{' '}
                                    <strong className="text-white/80">{totalMetricas('vendas').toLocaleString('pt-BR')}</strong> vendas
                                    {metricas.estado.limitado && ' · nos 30 primeiros anúncios'}
                                </p>
                            )}
                        </section>

                        <section>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-[12px] uppercase tracking-wide text-white/40">Agenda</h3>
                                <Botao variante="fantasma" onClick={() => onAgendar(oferta)}><CalendarPlus size={14} /> Agendar</Botao>
                            </div>
                            {oferta.agenda.length === 0
                                ? <p className="text-[12.5px] text-white/40">Nada agendado.</p>
                                : (
                                    <ul className="space-y-1">
                                        {oferta.agenda.map((i) => (
                                            <li key={i.id} className="flex items-center gap-2 text-[12.5px]">
                                                <span className="w-12 text-white/70">{fmtData(i.data)}</span>
                                                <span className="text-white/85">{vocabulario.acoes[i.acao]}</span>
                                                {i.feita && <span className="text-emerald-300 text-[11.5px]">feita</span>}
                                                <button type="button" onClick={() => excluirAgenda(i)} className="ml-auto p-1 text-white/35 hover:text-red-300" aria-label="Remover da agenda"><Trash2 size={13} /></button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                        </section>

                        <section className="flex flex-wrap gap-2 border-t border-white/[0.06] pt-4">
                            <Botao onClick={() => onEditar(oferta)}><Pencil size={14} /> Editar oferta</Botao>
                            {! confirmandoExclusao
                                ? <Botao variante="perigo" onClick={() => setConfirmandoExclusao(true)} disabled={oferta.usada_em > 0}
                                    title={oferta.usada_em > 0 ? 'Esta oferta entra em outras variações' : undefined}>
                                    <Trash2 size={14} /> Excluir
                                </Botao>
                                : <Botao variante="perigo" onClick={excluirOferta} data-acao="confirmar-exclusao">
                                    Confirmar exclusão{oferta.anuncios.length ? ` (os ${oferta.anuncios.length} anúncios vão para os colados em espera)` : ''}
                                </Botao>}
                            {oferta.usada_em > 0 && (
                                <p className="w-full text-[11.5px] text-white/40">
                                    Esta oferta entra em {oferta.usada_em} variação(ões) — exclua-as antes.
                                </p>
                            )}
                            {erro && <p className="w-full text-[12px] text-red-400">{erro}</p>}
                        </section>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
