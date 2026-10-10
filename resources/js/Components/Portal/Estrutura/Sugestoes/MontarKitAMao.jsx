import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2, Minus, Package, Plus, Search, Trash2, Truck } from 'lucide-react';
import Janela from '@/Components/Portal/Estrutura/Janela';
import { Botao, fmtReais } from '@/Components/Portal/Estrutura/comum';
import { PilulaLogistica, QuadroFotoProduto } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { SeloFase } from './PecasDaSugestao';
import {
    MAX_COMPONENTES_PADRAO, METODOLOGIA, ROTULO_FASE_MONTAGEM, adicionarItem, chaveDoItem, corpoDaMontagem, filtrarCatalogo,
    itemInicialDoProduto, mudarQuantidade, removerItem, rotuloDaVariacao, textoDaCriada, textoDoCriar, textoDoEstoque,
} from '@/lib/montarKit';
import { cn } from '@/lib/utils';

// ─── Montar combo, kit ou combit à mão no Planejamento (09/10/2026) ─────────
//
// 10/10/2026: a janela nasceu "Montar kit" e escondia a metodologia — o tipo só aparecia num selo
// pequeno da prévia. Agora os três tipos ficam sempre à vista (`LegendaDaMetodologia`), o da
// composição de agora aceso, e o botão diz o que nasce ("Criar Combit"). O tipo continua saindo
// da composição, no servidor.
//
// Em reunião com o cliente ("faz sentido esse kit? vai ter estoque?"), a pessoa pega um
// produto, pega o outro e define as quantidades. A prévia ao vivo vem do servidor
// (montar/previa): o tipo da oferta, o nome e o SKU sugeridos (editáveis), "já existe",
// a logística provável, o frete estimado, o custo do conjunto e se o estoque monta.
// Nada é calculado aqui. Criar grava pela mesma regra da Lista e oferece "Precificar agora".

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const CAMPO = 'h-11 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 text-[13.5px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-9';
const ROTULO = 'text-[12px] text-white/55';

/** A mensagem que a pessoa lê quando a prévia ou a gravação não passam. */
function mensagemDoErro(e) {
    const status = e?.response?.status;
    if (status === 422) {
        const erros = e.response.data?.errors;
        const primeiro = erros ? Object.values(erros).flat()[0] : null;

        return primeiro ?? e.response.data?.message ?? 'Confira os itens escolhidos.';
    }
    if (status === 429) return 'Muitas tentativas seguidas. Espere um minuto e tente de novo.';

    return 'Não foi possível calcular agora. Tente de novo.';
}

const Aviso = ({ children, tom = 'alerta' }) => (
    <p className={cn('mt-1 flex items-start gap-1.5 text-[12px]', tom === 'erro' ? 'text-red-300' : 'text-amber-300/90')}>
        <AlertTriangle size={14} className="mt-px shrink-0" aria-hidden="true" />
        <span>{children}</span>
    </p>
);

/** Quantidade com − e +; o campo aceita digitar (1 a 999). */
function Quantidade({ valor, rotulo, onMudar }) {
    return (
        <div className="inline-flex items-center rounded-xl border border-white/[0.10] bg-white/[0.03]" data-quantidade>
            <button type="button" onClick={() => onMudar(valor - 1)} disabled={valor <= 1} aria-label={`Menos uma unidade de ${rotulo}`}
                className={cn('grid h-11 w-11 place-items-center text-white/70 hover:text-white disabled:opacity-30 lg:h-8 lg:w-8', FOCO)}>
                <Minus size={14} aria-hidden="true" />
            </button>
            <input type="number" inputMode="numeric" min={1} max={999} value={valor} aria-label={`Quantidade de ${rotulo}`}
                onChange={(e) => onMudar(e.target.value)}
                className="h-11 w-12 border-0 bg-transparent p-0 text-center text-[14px] tabular-nums text-white focus:outline-none focus:ring-0 lg:h-8" />
            <button type="button" onClick={() => onMudar(valor + 1)} disabled={valor >= 999} aria-label={`Mais uma unidade de ${rotulo}`}
                className={cn('grid h-11 w-11 place-items-center text-white/70 hover:text-white disabled:opacity-30 lg:h-8 lg:w-8', FOCO)}>
                <Plus size={14} aria-hidden="true" />
            </button>
        </div>
    );
}

/**
 * A metodologia sempre à vista: Combo, Kit e Combit, com o que cada um é e como se monta. O tipo que
 * a composição de agora dá vem aceso (`fase` da prévia do servidor; null = ainda não é uma oferta).
 */
export function LegendaDaMetodologia({ fase = null }) {
    return (
        <ul className="grid gap-2 sm:grid-cols-3" data-metodologia aria-label="Tipos de oferta que dá para montar">
            {METODOLOGIA.map((m) => {
                const atual = m.fase === fase;

                return (
                    <li key={m.fase} data-tipo={m.fase} data-atual={atual ? 'sim' : undefined} aria-current={atual ? 'true' : undefined}
                        className={cn('rounded-xl border px-3 py-2', atual ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.07]' : 'border-white/[0.07] bg-white/[0.02]')}>
                        <p className="flex flex-wrap items-baseline gap-x-2">
                            <span className={cn('text-[13.5px] font-semibold', atual ? 'text-ecf-yellow' : 'text-white/90')}>{ROTULO_FASE_MONTAGEM[m.fase]}</span>
                            <span className="text-[11.5px] text-white/45">Fase {m.numero}</span>
                            {atual && <span className="text-[11.5px] font-medium text-ecf-yellow/90">é o que você está montando</span>}
                        </p>
                        <p className="mt-0.5 text-[12.5px] leading-snug text-white/70">{m.oQueE}</p>
                        <p className="mt-0.5 text-[12px] leading-snug text-white/45">{m.comoMontar}</p>
                    </li>
                );
            })}
        </ul>
    );
}

/** Uma linha da lista de escolha: clicar acrescenta o item à composição. */
function BotaoDoItem({ item, titulo, sub, estoque, cheio, onAdicionar }) {
    return (
        <button type="button" onClick={() => onAdicionar(item)} disabled={cheio} data-acao="adicionar-item"
            aria-label={`Adicionar ${titulo}`}
            className={cn('flex min-h-[44px] w-full items-center gap-3 rounded-lg px-2.5 py-1.5 text-left hover:bg-white/[0.05] disabled:opacity-40 lg:min-h-9', FOCO)}>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] text-white/90">{titulo}</span>
                <span className="block truncate font-mono text-[11.5px] text-white/45">{sub}</span>
            </span>
            {estoque !== undefined && (
                <span className="shrink-0 text-[12px] tabular-nums text-white/50">{estoque === null ? 'sem estoque informado' : `${estoque} em estoque`}</span>
            )}
            <Plus size={15} className="shrink-0 text-ecf-yellow" aria-hidden="true" />
        </button>
    );
}

/** A lista de escolha: produtos com as variações que já têm oferta, e as ofertas sem produto cadastrado. */
function ListaDeEscolha({ catalogo, busca, escolhidos, cheio, onAdicionar }) {
    const lista = useMemo(() => filtrarCatalogo(catalogo, busca, escolhidos), [catalogo, busca, escolhidos]);
    const vazio = lista.produtos.length === 0 && lista.avulsas.length === 0;

    if (! catalogo) {
        return (
            <p className="flex items-center gap-2 py-6 text-[13px] text-white/55" data-carregando-catalogo>
                <Loader2 size={15} className="animate-spin" aria-hidden="true" /> Carregando seus produtos…
            </p>
        );
    }

    return (
        <>
            <ul className="mt-3 max-h-[52vh] space-y-2 overflow-y-auto pr-1" data-lista-escolha>
                {lista.produtos.map((p) => (
                    <li key={p.produto_id} className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-1.5">
                        <p className="flex items-center gap-2 px-1.5 pb-1 pt-0.5">
                            <QuadroFotoProduto nome={p.nome} tamanho="icone" />
                            <span className="min-w-0 flex-1 truncate text-[13.5px] font-medium text-white">{p.nome}</span>
                            {p.tipo_nome && <span className="shrink-0 text-[11.5px] text-white/45">{p.tipo_nome}</span>}
                        </p>
                        <ul>
                            {p.variacoes.map((v) => (
                                <li key={v.variacao_id}>
                                    <BotaoDoItem item={{ variacao_id: v.variacao_id, nome: rotuloDaVariacao(p, v), sku: v.sku }}
                                        titulo={v.valor ?? 'Variação única'} sub={v.sku} estoque={v.estoque} cheio={cheio} onAdicionar={onAdicionar} />
                                </li>
                            ))}
                        </ul>
                    </li>
                ))}
                {lista.avulsas.length > 0 && (
                    <li className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-1.5" data-avulsas>
                        <p className="px-1.5 pb-1 pt-0.5 text-[12px] text-white/50">Outras ofertas, sem produto cadastrado</p>
                        <ul>
                            {lista.avulsas.map((o) => (
                                <li key={o.oferta_id}>
                                    <BotaoDoItem item={{ oferta_id: o.oferta_id, nome: o.nome || o.sku, sku: o.sku }} titulo={o.nome || o.sku} sub={o.sku}
                                        cheio={cheio} onAdicionar={onAdicionar} />
                                </li>
                            ))}
                        </ul>
                    </li>
                )}
            </ul>
            {vazio && <p className="py-6 text-center text-[13px] text-white/50">{busca ? 'Nada com essa busca.' : 'Nenhum produto com oferta ainda. Cadastre e salve os produtos primeiro.'}</p>}
            {lista.cortados > 0 && <p className="mt-2 text-[12px] text-white/45">Mais {lista.cortados} itens. Refine a busca para ver.</p>}
        </>
    );
}

/** A prévia que o servidor devolveu: tipo, nome, SKU, avisos, logística, frete, custo e estoque. */
export function PreviaDoKit({ previa, calculando, nome, sku, onNome, onSku, vocabulario }) {
    if (! previa) return null;

    if (! previa.pronto) {
        return <p className="mt-3 text-[13px] text-white/60" data-previa-incompleta>{previa.mensagem}</p>;
    }

    const limites = previa.limites ?? {};
    const tituloLongo = (previa.avisos ?? []).find((a) => a.codigo === 'titulo_longo');
    const skuLongo = (previa.avisos ?? []).some((a) => a.codigo === 'sku_longo');
    const logistica = previa.logistica;
    const semMedida = logistica?.sem_medida ?? [];
    const temFrete = (logistica?.chave === 'me2' || logistica?.chave === 'me2_full') && previa.frete?.valor !== null && previa.frete?.valor !== undefined;
    const estoque = textoDoEstoque(previa.estoque);
    const editadoNome = nome !== null;
    const editadoSku = sku !== null;

    return (
        <div className={cn('mt-4 space-y-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3.5', calculando && 'opacity-70')} data-previa aria-busy={calculando}>
            <div className="flex flex-wrap items-center gap-2">
                <SeloFase fase={previa.fase} />
                {calculando && <Loader2 size={14} className="animate-spin text-white/50" aria-label="Calculando" />}
            </div>

            {previa.ja_existe && (
                <Aviso tom="erro"><span data-ja-existe>Essa combinação já existe: SKU <span className="font-mono">{previa.ja_existe.sku}</span>.</span></Aviso>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="min-w-0">
                    <label htmlFor="montagem-nome" className={ROTULO}>Nome sugerido{editadoNome && ' · editado'}</label>
                    <input id="montagem-nome" className={CAMPO} value={editadoNome ? nome : (previa.nome ?? '')} onChange={(e) => onNome(e.target.value)} data-campo="nome" />
                    {tituloLongo && <Aviso>O nome tem {tituloLongo.valor} caracteres; o ideal é até {limites.max_titulo}.</Aviso>}
                    {editadoNome && String(nome).trim() === '' && <Aviso tom="erro">Dê um nome à oferta ou use o sugerido.</Aviso>}
                    {editadoNome && previa.sugerido && (
                        <button type="button" onClick={() => onNome(null)} className={cn('mt-1 text-[12px] text-white/55 hover:text-white hover:underline', FOCO)} data-acao="nome-sugerido">Usar o sugerido</button>
                    )}
                </div>
                <div className="min-w-0">
                    <label htmlFor="montagem-sku" className={ROTULO}>SKU sugerido{editadoSku && ' · editado'}</label>
                    <input id="montagem-sku" className={cn(CAMPO, 'font-mono')} value={editadoSku ? sku : (previa.sku ?? '')} onChange={(e) => onSku(e.target.value)} data-campo="sku" />
                    {skuLongo && <Aviso tom="erro">O SKU passa de {limites.max_sku} caracteres. Encurte para poder criar.</Aviso>}
                    {editadoSku && String(sku).trim() === '' && <Aviso tom="erro">Dê um SKU à oferta ou use o sugerido.</Aviso>}
                    {previa.sku_repetido && ! skuLongo && <Aviso>Já existe uma oferta com este SKU. Você pode criar assim mesmo.</Aviso>}
                    {editadoSku && previa.sugerido && (
                        <button type="button" onClick={() => onSku(null)} className={cn('mt-1 text-[12px] text-white/55 hover:text-white hover:underline', FOCO)} data-acao="sku-sugerido">Usar o sugerido</button>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[12.5px] text-white/65" data-logistica-do-kit>
                {logistica && (
                    <span className="inline-flex items-center gap-1.5">
                        <Package size={14} aria-hidden="true" />
                        <PilulaLogistica chave={logistica.chave} rotulos={vocabulario?.logisticas} className="h-auto bg-transparent px-0" />
                    </span>
                )}
                {temFrete && (
                    <span className="inline-flex items-center gap-1.5" data-frete>
                        <Truck size={14} aria-hidden="true" /> Frete estimado <strong className="tabular-nums text-white">{fmtReais(previa.frete.valor)}</strong>
                    </span>
                )}
                {logistica?.chave === 'me1' && <span data-frete>Frete pela sua transportadora</span>}
                <span data-custo>Custo do conjunto <strong className="tabular-nums text-white">{fmtReais(previa.custo)}</strong></span>
            </div>
            {logistica?.chave === 'pendente' && semMedida.length > 0 && (
                <p className="text-[12px] text-white/60">
                    Faltam medidas em{' '}
                    {semMedida.map((p, k) => (
                        <span key={`${p.id ?? 'x'}-${k}`}>
                            {k > 0 && ', '}
                            {p.id ? <a href={route('portal.auth.estrutura.produtos.ficha', p.id)} className="text-white/85 underline underline-offset-2 hover:text-white">{p.nome}</a> : p.nome}
                        </span>
                    ))}
                    . Sem elas, o frete fica em aberto.
                </p>
            )}
            {previa.custo === null && <p className="text-[12px] text-white/50">Falta o custo de algum item para somar o conjunto.</p>}

            <div data-estoque={estoque.tom} className={cn('rounded-lg px-3 py-2 text-[12.5px]',
                estoque.tom === 'ok' && 'bg-emerald-500/[0.08] text-emerald-200',
                estoque.tom === 'alerta' && 'bg-amber-500/[0.08] text-amber-200',
                estoque.tom === 'neutro' && 'bg-white/[0.03] text-white/65')}>
                <p className="font-medium">{estoque.titulo}</p>
                {estoque.detalhe && <p className="mt-0.5 opacity-80">{estoque.detalhe}</p>}
            </div>
        </div>
    );
}

/**
 * @param catalogo  a prop `montagem` da página (null até a janela pedir)
 * @param onCarregar pede o catálogo ao servidor (recarga parcial da página)
 * @param produtoInicial id do produto que já entra escolhido (aberto pela ficha ou pela lista de Produtos)
 * @param onCriada  avisa a página (recarregar as sugestões)
 */
export default function MontarKitAMao({ aberta, onFechar, catalogo = null, onCarregar, produtoInicial = null, vocabulario, onCriada }) {
    const [itens, setItens] = useState([]);
    const [busca, setBusca] = useState('');
    const [nome, setNome] = useState(null);           // null = o sugerido
    const [sku, setSku] = useState(null);
    const [previa, setPrevia] = useState(null);
    const [calculando, setCalculando] = useState(false);
    const [erro, setErro] = useState(null);
    const [gravando, setGravando] = useState(false);
    const [criada, setCriada] = useState(null);       // resposta da gravação
    const [avisoTeto, setAvisoTeto] = useState(false);
    const pedido = useRef(0);
    const usouInicial = useRef(false);

    const max = previa?.limites?.max_componentes ?? catalogo?.max_componentes ?? MAX_COMPONENTES_PADRAO;

    // Cada abertura começa do zero e pede o catálogo, se ainda não veio.
    useEffect(() => {
        if (! aberta) {
            usouInicial.current = false;

            return;
        }
        setItens([]); setBusca(''); setNome(null); setSku(null); setPrevia(null); setErro(null); setCriada(null); setAvisoTeto(false);
        if (! catalogo) onCarregar?.();
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    // Aberto a partir de um produto: ele já entra escolhido.
    useEffect(() => {
        if (! aberta || usouInicial.current || ! produtoInicial || ! catalogo) return;
        usouInicial.current = true;
        const item = itemInicialDoProduto(catalogo, produtoInicial);
        if (item) setItens([item]);
    }, [aberta, catalogo, produtoInicial]);

    // Prévia ao vivo, com respiro; a resposta antiga que chega depois da nova é ignorada.
    useEffect(() => {
        if (! aberta || criada) return undefined;
        const id = ++pedido.current;
        if (itens.length === 0) {
            setPrevia(null);
            setCalculando(false);

            return undefined;
        }
        const t = setTimeout(async () => {
            setCalculando(true);
            try {
                const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.montar.previa'), corpoDaMontagem(itens, { nome, sku }));
                if (id === pedido.current) {
                    setPrevia(data);
                    setErro(null);
                }
            } catch (e) {
                if (id === pedido.current) setErro(mensagemDoErro(e));
            } finally {
                if (id === pedido.current) setCalculando(false);
            }
        }, 350);

        return () => clearTimeout(t);
    }, [aberta, itens, nome, sku, criada]);

    const adicionar = (item) => {
        const r = adicionarItem(itens, item, max);
        setAvisoTeto(r.recusou);
        if (! r.recusou) setItens(r.itens);
    };

    const remover = (chave) => {
        setAvisoTeto(false);
        setItens(removerItem(itens, chave));
    };

    // O tipo que a composição de agora dá (do servidor); sem oferta ainda, nenhum.
    const faseAtual = previa?.pronto ? (previa.fase ?? null) : null;
    const skuLongo = (previa?.avisos ?? []).some((a) => a.codigo === 'sku_longo');
    const campoVazio = (v) => v !== null && String(v).trim() === '';
    const podeCriar = Boolean(previa?.pronto) && ! previa?.ja_existe && ! skuLongo && ! campoVazio(nome) && ! campoVazio(sku)
        && ! calculando && ! gravando && ! erro;

    const criar = async () => {
        if (! podeCriar) return;
        setGravando(true);
        setErro(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.montar'), corpoDaMontagem(itens, { nome, sku }));
            setCriada(data);
            onCriada?.(data);
        } catch (e) {
            setErro(mensagemDoErro(e));
        } finally {
            setGravando(false);
        }
    };

    const montarOutro = () => {
        setItens([]); setNome(null); setSku(null); setPrevia(null); setErro(null); setCriada(null); setAvisoTeto(false);
    };

    return (
        <Janela aberta={aberta} onFechar={onFechar} titulo="Montar combo, kit ou combit" largura="max-w-5xl"
            descricao="Escolha os produtos e as quantidades: o tipo sai do que você escolher. A prévia mostra o nome, o SKU, o frete estimado e se o estoque dá para montar.">
            {criada ? (
                <section className="space-y-4 py-2 text-center" data-montagem-criada>
                    <CheckCircle2 size={36} className="mx-auto text-emerald-300" aria-hidden="true" />
                    <div>
                        <p className="text-[16px] font-semibold text-white" data-criada-tipo={criada.oferta.fase ?? undefined}>{textoDaCriada(criada.oferta.fase)}: <span className="font-mono">{criada.oferta.sku}</span></p>
                        {criada.oferta.nome && <p className="mt-1 text-[13px] text-white/60">{criada.oferta.nome}</p>}
                        <p className="mt-2 text-[13px] text-white/60">O próximo passo é o preço.</p>
                    </div>
                    <div className="flex flex-col-reverse justify-center gap-2 sm:flex-row">
                        <Botao variante="secundario" onClick={montarOutro} className="h-11" data-acao="montar-outro">Montar outro</Botao>
                        <Link href={criada.precificar_url} data-acao="precificar-agora"
                            className={cn('inline-flex h-11 items-center justify-center gap-1.5 rounded-xl bg-ecf-yellow px-4 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90', FOCO)}>
                            Precificar agora
                        </Link>
                    </div>
                </section>
            ) : (
                <>
                <LegendaDaMetodologia fase={faseAtual} />
                <div className="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]" data-montar-kit>
                    <section aria-label="Produtos para escolher" data-montar-escolha>
                        <label className="relative block">
                            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/35" aria-hidden="true" />
                            <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar produto, cor ou SKU"
                                aria-label="Buscar produto, cor ou SKU" className={cn(CAMPO, 'pl-9')} data-busca-montagem />
                        </label>
                        <ListaDeEscolha catalogo={catalogo} busca={busca} escolhidos={itens} cheio={itens.length >= max} onAdicionar={adicionar} />
                    </section>

                    <section aria-label="Composição" data-montar-composicao>
                        <p className="text-[14px] font-semibold text-white">Composição</p>
                        {itens.length === 0 ? (
                            <p className="mt-2 text-[13px] text-white/55">Escolha ao lado os produtos que vão juntos e ajuste as quantidades. O tipo acende acima conforme você monta.</p>
                        ) : (
                            <ul className="mt-2 space-y-2" data-itens-escolhidos>
                                {itens.map((i) => {
                                    const chave = chaveDoItem(i);

                                    return (
                                        <li key={chave} className="flex flex-wrap items-center gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-2.5 py-2" data-item={chave}>
                                            <QuadroFotoProduto nome={i.nome} tamanho="miniatura" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[13px] text-white">{i.nome}</span>
                                                <span className="block truncate font-mono text-[11.5px] text-white/45">{i.sku}</span>
                                            </span>
                                            <Quantidade valor={i.quantidade} rotulo={i.nome} onMudar={(q) => setItens(mudarQuantidade(itens, chave, q))} />
                                            <button type="button" onClick={() => remover(chave)} aria-label={`Tirar ${i.nome}`} data-acao="tirar-item"
                                                className={cn('grid h-11 w-11 place-items-center rounded-lg text-white/45 hover:bg-white/[0.05] hover:text-red-300 lg:h-8 lg:w-8', FOCO)}>
                                                <Trash2 size={15} aria-hidden="true" />
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {avisoTeto && <Aviso>Uma oferta pode juntar até {max} produtos diferentes.</Aviso>}

                        <PreviaDoKit previa={previa} calculando={calculando} nome={nome} sku={sku} onNome={setNome} onSku={setSku} vocabulario={vocabulario} />

                        {erro && <p role="alert" className="mt-3 text-[13px] text-red-300" data-erro-montagem>{erro}</p>}

                        <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Botao variante="secundario" onClick={onFechar} className="h-11">Cancelar</Botao>
                            <Botao variante="primario" onClick={criar} disabled={! podeCriar} className="h-11" data-acao="criar-oferta-montada">
                                {gravando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                                {gravando ? 'Criando…' : textoDoCriar(faseAtual)}
                            </Botao>
                        </div>
                    </section>
                </div>
                </>
            )}
        </Janela>
    );
}
