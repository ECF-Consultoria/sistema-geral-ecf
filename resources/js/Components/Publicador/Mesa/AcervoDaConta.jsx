import { useEffect, useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import { GERAL } from '../apoio';
import useAcervoDaConta from '../useAcervoDaConta';
import { BotaoAcao } from './botoes';
import { CAMPO, SELECT } from './comum';

// ─── Acervo de imagens da conta, na etapa Imagens (v28.0 Fase 198; nasceu na Fase 171, D4) ───
//
// Pedido do usuário (Fase 171): "poder criar outro anúncio com as mesmas fotos que já criamos em
// outro anúncio". Na v28.0 o acervo virou a biblioteca da CONTA do Mercado Livre: fotos enviadas
// e imagens de IA aprovadas, com busca por produto/SKU, filtros e páginas. "Usar esta foto" põe o
// MESMO arquivo neste anúncio — sem cópia, sem gerar nada e sem custo.
//
// Montado DEPOIS de "Fotos e variações" (EtapaImagens.jsx), uma vez por página — nunca dentro de
// PainelCriativos.jsx, que remonta por grupo/variação.

/**
 * O campo pode chegar do servidor em formato inesperado (objeto, array, número, booleano) — nunca
 * derruba a tela (REND-02, mesma lição da tela preta 261005-si3).
 */
const textoSeguro = (v) => (typeof v === 'string' || typeof v === 'number' ? v : null);
const urlSegura = (v) => (typeof v === 'string' && v !== '' ? v : null);

const ORIGENS = [
    { valor: '', rotulo: 'Todas as origens' },
    { valor: 'upload', rotulo: 'Fotos enviadas' },
    { valor: 'ia', rotulo: 'Geradas por IA' },
];

/**
 * Um item do acervo: a miniatura (clique amplia), a origem, o produto/SKU, a data, onde está em uso
 * e o botão para usar no grupo escolhido. Apresentação pura — sem hook, sem rota crua — testável
 * por render real com o JSON que o servidor pode mandar.
 */
export function CartaoAcervo({ item, grupos, onUsar, ocupado, onAmpliar = () => {} }) {
    const [grupoEscolhido, setGrupoEscolhido] = useState(() => grupos[0]?.chave ?? GERAL);
    const rotulo = textoSeguro(item?.rotulo);
    const criadoEm = textoSeguro(item?.criado_em);
    const sku = textoSeguro(item?.sku);
    const nomeProduto = typeof item?.produto_nome === 'string' && item.produto_nome ? item.produto_nome : null;
    const deOutroProduto = item?.do_produto_atual !== true;
    const emUso = Number.isFinite(item?.em_uso) ? item.em_uso : null;
    const imagem = urlSegura(item?.miniatura_url) ?? urlSegura(item?.imagem_url);
    const jaNesteAnuncio = item?.no_rascunho_atual === true;

    return (
        <div className="overflow-hidden rounded-lg border border-white/[0.08] bg-black/20" data-item-acervo={item?.id}>
            {imagem ? (
                <button type="button" onClick={() => onAmpliar(item)} className="block w-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    aria-label="Ver a imagem em tamanho maior">
                    <img src={imagem} alt={rotulo ?? 'Imagem do acervo'} loading="lazy" className="aspect-square w-full object-cover" />
                </button>
            ) : (
                <div className="flex aspect-square w-full items-center justify-center bg-white/[0.04] text-[11px] font-normal text-white/40">
                    Sem imagem
                </div>
            )}
            <div className="space-y-2 p-2.5">
                <p className="truncate text-[13px] font-bold text-white/90">{rotulo ?? 'Imagem'}</p>
                <p className="text-[11px] font-normal text-white/50">
                    {criadoEm ?? '—'}
                    {deOutroProduto && (nomeProduto ? ` — de "${nomeProduto}"` : ' — de um anúncio que não existe mais')}
                </p>
                {(sku !== null || emUso !== null) && (
                    <p className="text-[11px] font-normal text-white/40">
                        {sku !== null && <>SKU {sku}</>}
                        {sku !== null && emUso !== null && ' · '}
                        {emUso !== null && (emUso === 0 ? 'Em nenhum anúncio' : emUso === 1 ? 'Em 1 anúncio' : `Em ${emUso} anúncios`)}
                    </p>
                )}
                {grupos.length > 1 && (
                    <select value={grupoEscolhido} onChange={(e) => setGrupoEscolhido(e.target.value)}
                        className={cn(SELECT, 'h-8 py-0 text-[12px]')} aria-label="Em qual grupo de fotos usar esta imagem"
                        data-select-grupo={item?.id}>
                        {grupos.map((g) => <option key={g.chave} value={g.chave}>{g.rotulo}</option>)}
                    </select>
                )}
                <BotaoAcao disabled={ocupado} onClick={() => onUsar(item?.id, grupoEscolhido)} className="h-8 w-full text-[12px]" data-acao="usar-do-acervo">
                    {ocupado ? 'Adicionando…' : jaNesteAnuncio ? 'Usar de novo neste grupo' : 'Usar esta foto'}
                </BotaoAcao>
            </div>
        </div>
    );
}

/** A imagem original em tela cheia; fecha no Esc, no clique fora ou no botão. */
function ImagemAmpliada({ item, onFechar }) {
    useEffect(() => {
        const aoTeclar = (e) => e.key === 'Escape' && onFechar();
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, [onFechar]);

    const url = urlSegura(item?.imagem_url);
    const largura = Number.isFinite(item?.largura) ? item.largura : null;
    const altura = Number.isFinite(item?.altura) ? item.altura : null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4" role="dialog" aria-modal="true" onClick={onFechar}>
            <div className="max-h-full max-w-4xl" onClick={(e) => e.stopPropagation()}>
                {url && <img src={url} alt={textoSeguro(item?.rotulo) ?? 'Imagem do acervo'} className="max-h-[80vh] w-auto rounded-lg object-contain" />}
                <div className="mt-3 flex items-center justify-between gap-3 text-[12px] font-normal text-white/60">
                    <span>{largura && altura ? `${largura} × ${altura} px` : ''}</span>
                    <BotaoAcao onClick={onFechar} className="h-8 text-[12px]">Fechar</BotaoAcao>
                </div>
            </div>
        </div>
    );
}

export default function AcervoDaConta({ produtoId, m }) {
    const c = useAcervoDaConta({ produtoId });
    const [ampliada, setAmpliada] = useState(null);
    const [busca, setBusca] = useState('');

    // A busca espera o usuário parar de digitar (400 ms) antes de pedir ao servidor.
    useEffect(() => {
        if (busca === c.filtros.busca) return undefined;
        const t = setTimeout(() => c.filtrar({ busca }), 400);

        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca]);
    useEffect(() => setBusca(''), [produtoId]);

    // A lista de grupos válidos do rascunho ATUAL, derivada do estado já carregado pela Mesa
    // (mesma fonte que `FotosEVariacoes.jsx` usa em `fotosDaVariante()`) — nenhuma chamada nova.
    const grupos = useMemo(() => {
        const base = [{ chave: GERAL, rotulo: 'Fotos gerais' }];
        const doEstado = Array.isArray(m?.estado?.grupos_imagem) ? m.estado.grupos_imagem : [];
        for (const g of doEstado) {
            if (! g || typeof g.chave !== 'string' || g.chave === GERAL) continue;
            base.push({ chave: g.chave, rotulo: typeof g.rotulo === 'string' && g.rotulo ? g.rotulo : g.chave });
        }

        return base;
    }, [m?.estado?.grupos_imagem]);

    const aoUsar = async (ativoId, grupo) => {
        if (! ativoId) return;
        const resultado = await c.reaproveitar(ativoId, grupo);
        if (resultado?.ok) {
            c.carregar(c.filtros, c.pagina);
            m?.recarregar?.();
        }
    };

    const filtrando = c.filtros.origem || c.filtros.so_este_produto || c.filtros.busca || c.filtros.de || c.filtros.ate;
    const filtro = 'h-9 py-0 text-[13px]';

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4" data-bloco-acervo>
            <div>
                <h3 className="text-[13px] font-bold text-white/90">Acervo de imagens da conta</h3>
                <p className="mt-1 max-w-[70ch] text-[11px] font-normal text-white/50">
                    As fotos enviadas e as imagens de IA aprovadas desta conta do Mercado Livre. Usar uma delas aqui
                    coloca a mesma foto neste anúncio — sem gerar nenhuma imagem nova e sem nenhum custo.
                </p>
            </div>

            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6">
                <input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar por produto ou SKU"
                    className={cn(CAMPO, filtro, 'lg:col-span-2')} aria-label="Buscar por produto ou SKU" data-filtro="busca" />
                <select value={c.filtros.origem} onChange={(e) => c.filtrar({ origem: e.target.value })} className={cn(SELECT, filtro)}
                    aria-label="Origem da imagem" data-filtro="origem">
                    {ORIGENS.map((o) => <option key={o.valor} value={o.valor}>{o.rotulo}</option>)}
                </select>
                <input type="date" value={c.filtros.de} onChange={(e) => c.filtrar({ de: e.target.value })} className={cn(CAMPO, filtro)}
                    aria-label="Criadas a partir de" data-filtro="de" />
                <input type="date" value={c.filtros.ate} onChange={(e) => c.filtrar({ ate: e.target.value })} className={cn(CAMPO, filtro)}
                    aria-label="Criadas até" data-filtro="ate" />
                <select value={c.filtros.ordem} onChange={(e) => c.filtrar({ ordem: e.target.value })} className={cn(SELECT, filtro)}
                    aria-label="Ordem" data-filtro="ordem">
                    <option value="recentes">Mais recentes</option>
                    <option value="antigas">Mais antigas</option>
                </select>
            </div>
            <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                <label className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                    <input type="checkbox" checked={c.filtros.so_este_produto} onChange={() => c.filtrar({ so_este_produto: ! c.filtros.so_este_produto })}
                        className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        data-opcao="so-este-produto" />
                    Só deste produto
                </label>
                {filtrando && (
                    <button type="button" onClick={() => { setBusca(''); c.limparFiltros(); }}
                        className="text-[12px] font-bold text-white/60 underline-offset-4 hover:text-ecf-yellow hover:underline">
                        Limpar filtros
                    </button>
                )}
            </div>

            {c.erro && <p className="mt-3 text-[13px] font-normal text-red-300">{c.erro}</p>}

            {c.carregando && c.itens.length === 0 ? (
                <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => <div key={i} className="aspect-square animate-pulse rounded-lg bg-white/[0.04]" />)}
                </div>
            ) : c.itens.length === 0 ? (
                <p className="mt-4 text-[13px] font-normal text-white/50">
                    {filtrando ? 'Nenhuma imagem com esses filtros.' : 'Nenhuma imagem no acervo desta conta ainda.'}
                </p>
            ) : (
                <>
                    <div className={cn('mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4', c.carregando && 'opacity-60')}>
                        {c.itens.map((item) => (
                            <CartaoAcervo key={item.id} item={item} grupos={grupos} ocupado={c.reaproveitando === item.id} onUsar={aoUsar} onAmpliar={setAmpliada} />
                        ))}
                    </div>
                    <div className="mt-3 flex items-center justify-between text-[12px] font-normal text-white/50">
                        <span>{c.total === 1 ? '1 imagem' : `${c.total} imagens`}</span>
                        {c.paginas > 1 && (
                            <div className="flex items-center gap-2">
                                <BotaoAcao disabled={c.pagina <= 1 || c.carregando} onClick={() => c.irParaPagina(c.pagina - 1)} className="h-8 text-[12px]">Anterior</BotaoAcao>
                                <span>Página {c.pagina} de {c.paginas}</span>
                                <BotaoAcao disabled={c.pagina >= c.paginas || c.carregando} onClick={() => c.irParaPagina(c.pagina + 1)} className="h-8 text-[12px]">Próxima</BotaoAcao>
                            </div>
                        )}
                    </div>
                </>
            )}

            {ampliada && <ImagemAmpliada item={ampliada} onFechar={() => setAmpliada(null)} />}
        </div>
    );
}
