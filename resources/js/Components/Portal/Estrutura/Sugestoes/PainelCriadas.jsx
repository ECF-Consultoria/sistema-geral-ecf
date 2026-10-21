import { Fragment, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Box, ImageOff, Layers, Package, Pencil, Plus, Search, Truck, X } from 'lucide-react';
import { Paginacao, fmtReais } from '@/Components/Portal/Estrutura/comum';
import SeletorVisualizacao from '@/Components/Portal/Estrutura/Produtos/SeletorVisualizacao';
import { seloDoEnvio } from '@/lib/precificacaoEnvio';
import {
    ROTULO_ENVIO, ROTULO_FASE, ROTULO_ORDEM, ROTULO_SITUACAO, acaoDaPendencia, filtroLigado, gravarModoCriadas, layoutDaVitrine,
    lerModoCriadas, nomeDoItem, paramsDaCriadas, rotuloDaPendencia, textoDoTotal,
} from '@/lib/sugestoesCriadas';
import { cn } from '@/lib/utils';

// ─── Aba "Criadas": o que já foi aceito ou montado (13/10/2026) ─────────────
//
// O pedido: "em Planejamento deveriam ficar os produtos que planejamos… aceitou, ele
// fica ali". A sugestão aceita sumia da tela; quem montava um combo não tinha onde
// vê-lo de novo. Aqui ficam os combos, kits e combits que existem, por tipo, com do
// que cada um é feito, o envio (o pacote é a soma das caixas) e como está o preço —
// os mesmos dados da Precificação — e os atalhos para o preço e para nome e composição.
//
// 16/10/2026 — redesenho pela FOTO. O usuário, olhando a lista de códigos: "não dá para saber
// quais são os produtos, quais foram os kits… visualmente pela foto". Cada cartão abre com a
// vitrine do que o compõe: o Combo é a foto do produto empilhada com "2×"; o Kit, as fotos lado
// a lado com "+"; o Combit, os dois jeitos juntos; de 4 produtos em diante, mosaico 2×2.
//
// 16/10/2026 — "deveria ter filtros, modo lista também". Filtros de tipo, situação do preço e
// envio (cada opção com a contagem dos outros filtros aplicados), busca, ordem e páginas: tudo no
// servidor. O modo Visual grande / Lista é o mesmo seletor de Produtos e fica no navegador. Cada
// pendência leva aonde se resolve: custo e medidas ao cadastro do produto, frete e percentuais à
// Precificação.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const ATALHO = 'inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-[12.5px] font-semibold transition-colors';
const ATALHO_NEUTRO = 'border border-white/[0.10] bg-white/[0.03] text-white/85 hover:bg-white/[0.08] hover:text-white';
const ATALHO_AMARELO = 'bg-ecf-yellow text-black hover:bg-ecf-yellow/90';
const ROTULO = 'text-[10.5px] font-semibold uppercase tracking-wider text-white/45';
const CHIP = 'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[12.5px] transition-colors';
const SELECT = 'h-9 rounded-lg border border-white/[0.10] bg-ecf-card px-3 text-[12.5px] text-white/85 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';

// A identidade de cada tipo: a mesma cor e o mesmo ícone nos cartões das criadas e das sugestões
// (CartaoSugestao usa esta vitrine). Classes literais: o Tailwind só gera o que aparece escrito por inteiro.
const TIPO = {
    combo: { Icone: Box, nome: 'Combo', explica: 'o mesmo produto em mais unidades', cor: 'bg-violet-500/15 text-violet-300' },
    kit: { Icone: Package, nome: 'Kit', explica: 'produtos diferentes juntos', cor: 'bg-emerald-500/15 text-emerald-300' },
    combit: { Icone: Layers, nome: 'Combit', explica: 'um kit com mais unidades de um item', cor: 'bg-ecf-yellow/15 text-ecf-yellow' },
};
const TIPO_NEUTRO = { Icone: Package, nome: '', explica: '', cor: 'bg-white/[0.08] text-white/70' };

const TOM_DO_SELO = { ok: 'bg-white/[0.06] text-white/70', neutro: 'bg-white/[0.06] text-white/70', alerta: 'bg-amber-500/[0.14] text-amber-300' };

/** O endereço da ação da pendência: o cadastro do produto ou a Precificação, já buscando o que importa. */
function hrefDaAcao(a) {
    const nome = a.destino === 'produtos' ? 'portal.auth.estrutura.produtos' : 'portal.auth.estrutura.precificacao';

    return route(nome, a.q ? { q: a.q } : {});
}

// ─── Vitrine ─────────────────────────────────────────────────────────────────

/**
 * Uma peça da vitrine: a foto do componente num quadro branco (as fotos de produto têm fundo claro,
 * como na lista de Produtos). Mais de uma unidade vira pilha — dois quadros atrás — e o selo "N×".
 */
function Peca({ componente, tamanho, pequena = false }) {
    const varias = componente.quantidade > 1;
    const nome = componente.nome ?? componente.sku ?? 'produto removido';
    const passo = pequena ? 3 : 5;

    return (
        <div className="relative shrink-0" style={{ width: tamanho, height: tamanho }} title={`${componente.quantidade}× ${nome}`}
            data-peca={componente.id} data-quantidade={componente.quantidade}>
            {/* A pilha acompanha o quadro: clara atrás da foto, escura atrás do "sem foto". */}
            {varias && [2, 1].map((n) => (
                <span key={n} aria-hidden="true" style={{ transform: `translate(${passo * n}px, -${passo * n}px)` }}
                    className={cn('absolute inset-0 rounded-xl', componente.foto
                        ? (n === 2 ? 'bg-white/25' : 'bg-white/50')
                        : (n === 2 ? 'border border-white/[0.10] bg-white/[0.04]' : 'border border-white/[0.12] bg-white/[0.07]'))} />
            ))}
            {componente.foto ? (
                <img src={componente.foto} alt={nome} loading="lazy" decoding="async" draggable="false"
                    className={cn('relative h-full w-full bg-white object-contain shadow-[0_6px_18px_rgba(0,0,0,0.45)]', pequena ? 'rounded-lg p-0.5' : 'rounded-xl p-1.5')} />
            ) : (
                <span className={cn('relative flex h-full w-full flex-col items-center justify-center gap-1 border border-dashed border-white/[0.16] bg-ecf-card px-1 text-center text-white/40', pequena ? 'rounded-lg' : 'rounded-xl')} data-sem-foto>
                    <ImageOff size={tamanho >= 100 ? 22 : 14} aria-hidden="true" />
                    {tamanho >= 72 && <span className="line-clamp-2 break-all font-mono text-[10px] leading-tight text-white/55">{componente.sku ?? 'sem foto'}</span>}
                </span>
            )}
            {varias && (
                <span className={cn('absolute rounded-full bg-ecf-yellow font-extrabold tabular-nums text-black shadow-[0_2px_8px_rgba(0,0,0,0.5)]',
                    pequena ? '-bottom-1.5 -right-1.5 px-1.5 text-[10.5px]' : '-bottom-2 -right-2 px-2 py-0.5 text-[13px]')} data-qtd>
                    {componente.quantidade}×
                </span>
            )}
        </div>
    );
}

const MAIS = (
    <span aria-hidden="true" className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-white/[0.08] text-white/70">
        <Plus size={14} />
    </span>
);

/**
 * A vitrine do cartão: em linha com "+" entre produtos diferentes até 3; mosaico 2×2 a partir de 4.
 * Também é a do cartão da sugestão (21/10/2026), que passa o selo "Sugestão" em `selo`.
 */
export function Vitrine({ item, selo = null }) {
    const tipo = TIPO[item.fase] ?? TIPO_NEUTRO;
    const { modo, pecas, resto, tamanho } = layoutDaVitrine(item.composicao);

    return (
        <div className="relative flex h-[196px] items-center justify-center bg-gradient-to-b from-white/[0.05] to-white/[0.015] px-5 pt-4" data-vitrine={modo}>
            <span className="absolute left-3 top-3 flex items-center gap-1.5">
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold', tipo.cor)} data-tipo>
                    <tipo.Icone size={12} aria-hidden="true" /> {tipo.nome}
                </span>
                {selo}
            </span>
            {pecas.length === 0 && <span className="text-[12px] text-white/45">Sem composição</span>}
            {modo === 'linha' ? (
                <div className="flex items-center gap-2.5">
                    {pecas.map((c, i) => (
                        <Fragment key={`${c.id}-${i}`}>
                            {i > 0 && MAIS}
                            <Peca componente={c} tamanho={tamanho} />
                        </Fragment>
                    ))}
                </div>
            ) : (
                <div className="grid grid-cols-2 gap-3" data-mosaico>
                    {pecas.map((c, i) => <Peca key={`${c.id}-${i}`} componente={c} tamanho={tamanho} />)}
                    {resto > 0 && (
                        <span className="grid place-items-center rounded-xl border border-white/[0.12] bg-white/[0.04] text-[15px] font-bold text-white/80"
                            style={{ width: tamanho, height: tamanho }} data-resto>+{resto}</span>
                    )}
                </div>
            )}
        </div>
    );
}

/** A vitrine compacta da linha: até 3 fotos sobrepostas e "+N". */
function VitrineCompacta({ item }) {
    const pecas = (item.composicao ?? []).slice(0, 3);
    const resto = (item.composicao ?? []).length - pecas.length;

    return (
        <div className="flex items-center pl-1" data-vitrine-compacta>
            {pecas.map((c, i) => (
                <div key={`${c.id}-${i}`} className={cn(i > 0 && '-ml-3')} style={{ zIndex: 10 - i }}>
                    <Peca componente={c} tamanho={44} pequena />
                </div>
            ))}
            {resto > 0 && <span className="ml-2 text-[11.5px] font-semibold text-white/55" data-resto>+{resto}</span>}
        </div>
    );
}

// ─── Peças comuns ao cartão e à linha ────────────────────────────────────────

function SeloEnvio({ envio }) {
    const s = seloDoEnvio(envio);
    if (! s) return <span className="text-[12px] text-white/35">—</span>;

    return (
        <span className={cn('inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-semibold', TOM_DO_SELO[s.tom])}
            title={s.dica} data-envio={s.chave}>
            <Truck size={13} aria-hidden="true" className="shrink-0" />
            <span className="truncate">{s.texto}</span>
        </span>
    );
}

/** `alinhado`: na Lista o botão principal tem largura fixa, para o lápis cair na mesma coluna em toda linha. */
function Atalhos({ item, alinhado = false }) {
    const a = acaoDaPendencia(item);

    return (
        <div className="flex shrink-0 gap-1.5">
            <a href={route('portal.auth.estrutura.mapeamento', { abrir: item.id })} className={cn(ATALHO, ATALHO_NEUTRO, FOCO, 'w-9 justify-center px-0')}
                data-acao="editar-criada" title="Nome e composição: mudar o nome, o código ou do que ele é feito" aria-label="Nome e composição">
                <Pencil size={14} aria-hidden="true" />
            </a>
            <a href={hrefDaAcao(a)} className={cn(ATALHO, a.destaque ? ATALHO_AMARELO : ATALHO_NEUTRO, FOCO, alinhado && 'min-w-[160px] justify-center')} data-acao="precificar-criada" data-destino={a.destino}>
                {a.acao} <ArrowRight size={13} aria-hidden="true" />
            </a>
        </div>
    );
}

/** O preço em reais quando fecha; quando não, o que falta e de quem, em destaque. */
function Preco({ item }) {
    const p = rotuloDaPendencia(item);
    const a = acaoDaPendencia(item);

    if (p.chave === 'ok' && item.preco) {
        return (
            <div className="grid grid-cols-2 gap-2" data-preco="ok">
                {[['Clássico', item.preco.classico], ['Premium', item.preco.premium]].map(([rotulo, valor]) => (
                    <div key={rotulo} className="rounded-lg bg-white/[0.04] px-3 py-2">
                        <p className={ROTULO}>{rotulo}</p>
                        <p className="mt-0.5 text-[15px] font-bold tabular-nums text-white">{fmtReais(valor)}</p>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className={cn('flex min-h-[54px] items-start gap-2 rounded-lg px-3 py-2',
            p.tom === 'falta' ? 'border border-amber-400/25 bg-amber-500/[0.08] text-amber-200' : 'bg-white/[0.04] text-white/60')} data-preco={p.chave}>
            {p.tom === 'falta' && <AlertTriangle size={15} aria-hidden="true" className="mt-0.5 shrink-0" />}
            <span className="min-w-0">
                <span className="block text-[13px] font-semibold">{a.texto}</span>
                {a.detalhe && <span className="mt-0.5 block text-[11.5px] leading-snug text-amber-200/70" data-detalhe>{a.detalhe}</span>}
            </span>
        </div>
    );
}

// ─── Visual grande ───────────────────────────────────────────────────────────

function Criada({ item }) {
    return (
        <li className="flex flex-col overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card transition-colors hover:border-white/[0.18]"
            data-criada={item.id} data-fase={item.fase}>
            <Vitrine item={item} />
            <div className="flex flex-1 flex-col gap-3 px-4 pb-4 pt-3.5">
                <div className="min-w-0">
                    <p className="line-clamp-2 min-h-[40px] text-[14.5px] font-semibold leading-snug text-white" title={nomeDoItem(item)} data-nome>{nomeDoItem(item)}</p>
                    <p className="mt-1 truncate font-mono text-[11.5px] text-white/45" title={item.sku}>{item.sku}</p>
                    <p className="mt-2 flex flex-wrap gap-1.5" data-composicao>
                        {(item.composicao ?? []).map((c) => (
                            <span key={c.id} title={c.nome ?? undefined} className="max-w-full truncate rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[11px] text-white/65">
                                {c.quantidade}× {c.sku ?? 'produto removido'}
                            </span>
                        ))}
                    </p>
                </div>
                <Preco item={item} />
                <div className="mt-auto flex items-center justify-between gap-2 border-t border-white/[0.06] pt-3">
                    <SeloEnvio envio={item.envio} />
                    <Atalhos item={item} />
                </div>
            </div>
        </li>
    );
}

// ─── Lista ───────────────────────────────────────────────────────────────────

// Foto, nome, tipo, envio, Clássico, Premium e ações: o cabeçalho e cada linha usam a MESMA grade.
const COLUNAS = 'grid grid-cols-[132px_minmax(0,1fr)_92px_150px_112px_112px_212px] items-center gap-x-4 px-4';

function LinhaCriada({ item }) {
    const tipo = TIPO[item.fase] ?? TIPO_NEUTRO;
    const p = rotuloDaPendencia(item);
    const a = acaoDaPendencia(item);

    return (
        <li className={cn(COLUNAS, 'min-h-[76px] border-t border-white/[0.06] py-2.5 transition-colors first:border-t-0 hover:bg-white/[0.025]')}
            data-criada={item.id} data-fase={item.fase} data-linha>
            <VitrineCompacta item={item} />
            <div className="min-w-0">
                <p className="truncate text-[13.5px] font-semibold text-white" title={nomeDoItem(item)} data-nome>{nomeDoItem(item)}</p>
                <p className="mt-0.5 truncate font-mono text-[11px] text-white/45" title={item.sku}>{item.sku}</p>
                <p className="mt-1 truncate text-[11.5px] text-white/55" data-composicao
                    title={(item.composicao ?? []).map((c) => `${c.quantidade}× ${c.nome ?? c.sku}`).join(' + ')}>
                    {(item.composicao ?? []).map((c) => `${c.quantidade}× ${c.sku ?? 'produto removido'}`).join(' · ')}
                </p>
            </div>
            <span className={cn('inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold', tipo.cor)} data-tipo>
                <tipo.Icone size={12} aria-hidden="true" /> {tipo.nome}
            </span>
            <div className="min-w-0"><SeloEnvio envio={item.envio} /></div>
            {p.chave === 'ok' && item.preco ? (
                <>
                    <span className="text-right text-[13.5px] font-bold tabular-nums text-white" data-preco="ok">{fmtReais(item.preco.classico)}</span>
                    <span className="text-right text-[13.5px] font-bold tabular-nums text-white">{fmtReais(item.preco.premium)}</span>
                </>
            ) : (
                <span className={cn('col-span-2 flex min-w-0 items-start gap-1.5 text-[12.5px]', p.tom === 'falta' ? 'text-amber-200' : 'text-white/55')} data-preco={p.chave}
                    title={a.detalhe ?? undefined}>
                    {p.tom === 'falta' && <AlertTriangle size={14} aria-hidden="true" className="mt-0.5 shrink-0" />}
                    <span className="min-w-0">
                        <span className="block font-semibold">{a.texto}</span>
                        {a.detalhe && <span className="block truncate text-[11px] text-amber-200/65" data-detalhe>{a.detalhe}</span>}
                    </span>
                </span>
            )}
            <div className="flex justify-end"><Atalhos item={item} alinhado /></div>
        </li>
    );
}

// ─── Barra de filtros ────────────────────────────────────────────────────────

function Chip({ ativo, onClick, children, contagem, dados }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={ativo} {...dados}
            className={cn(CHIP, FOCO, ativo ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.12] font-semibold text-ecf-yellow' : 'border-white/[0.10] bg-white/[0.03] text-white/75 hover:bg-white/[0.07] hover:text-white')}>
            {children}
            <span className={cn('rounded-full px-1.5 text-[11px] tabular-nums', ativo ? 'bg-ecf-yellow/20' : 'bg-white/[0.08] text-white/60')}>{contagem ?? 0}</span>
        </button>
    );
}

function BarraDeFiltros({ filtros, contagens, ocupado, modo, onModo, onFiltro, busca, onBusca }) {
    const c = contagens ?? {};
    // Opção de situação/envio com zero não aparece, salvo a que está ligada (para dar para desligar).
    const situacoes = ['com_preco', 'pendente', 'sem_custo', 'sem_frete', 'impossivel'].filter((s) => (c.situacao?.[s] ?? 0) > 0 || filtros.situacao === s);
    const envios = Object.keys(ROTULO_ENVIO).filter((e) => (c.envio?.[e] ?? 0) > 0 || filtros.envio === e);

    return (
        <section className={cn('space-y-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4', ocupado && 'opacity-70')} data-filtros-criadas aria-busy={ocupado}>
            <div className="flex flex-wrap items-center gap-3">
                <label className="relative min-w-[260px] flex-1">
                    <span className="sr-only">Buscar</span>
                    <Search size={15} aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
                    <input type="search" value={busca} onChange={(e) => onBusca(e.target.value)} placeholder="Buscar por nome ou código, do item ou de um produto dele…"
                        className="h-9 w-full rounded-lg border border-white/[0.10] bg-ecf-card pl-9 pr-3 text-[13px] text-white placeholder:text-white/35 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40"
                        data-busca-criadas />
                </label>
                <label className="flex items-center gap-2 text-[12.5px] text-white/55">
                    Envio
                    <select value={filtros.envio ?? ''} onChange={(e) => onFiltro({ envio: e.target.value || null })} className={SELECT} data-filtro-envio>
                        <option value="">Todos ({c.envio?.todas ?? 0})</option>
                        {envios.map((e) => <option key={e} value={e}>{ROTULO_ENVIO[e]} ({c.envio?.[e] ?? 0})</option>)}
                    </select>
                </label>
                <label className="flex items-center gap-2 text-[12.5px] text-white/55">
                    Ordem
                    <select value={filtros.ordem ?? 'recentes'} onChange={(e) => onFiltro({ ordem: e.target.value })} className={SELECT} data-ordem>
                        {Object.entries(ROTULO_ORDEM).map(([v, r]) => <option key={v} value={v}>{r}</option>)}
                    </select>
                </label>
                <div className="ml-auto"><SeletorVisualizacao modo={modo} onModo={onModo} /></div>
            </div>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Tipo" data-filtro-fase>
                    <span className="mr-1 text-[11.5px] font-semibold uppercase tracking-wider text-white/40">Tipo</span>
                    <Chip ativo={! filtros.fase} onClick={() => onFiltro({ fase: null })} contagem={c.fase?.todas} dados={{ 'data-fase-opcao': 'todas' }}>Todos</Chip>
                    {Object.entries(ROTULO_FASE).map(([f, r]) => (
                        <Chip key={f} ativo={filtros.fase === f} onClick={() => onFiltro({ fase: filtros.fase === f ? null : f })} contagem={c.fase?.[f]} dados={{ 'data-fase-opcao': f }}>{r}</Chip>
                    ))}
                </div>
                <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Situação do preço" data-filtro-situacao>
                    <span className="mr-1 text-[11.5px] font-semibold uppercase tracking-wider text-white/40">Preço</span>
                    <Chip ativo={! filtros.situacao} onClick={() => onFiltro({ situacao: null })} contagem={c.situacao?.todas} dados={{ 'data-situacao-opcao': 'todas' }}>Todas</Chip>
                    {situacoes.map((s) => (
                        <Chip key={s} ativo={filtros.situacao === s} onClick={() => onFiltro({ situacao: filtros.situacao === s ? null : s })} contagem={c.situacao?.[s]} dados={{ 'data-situacao-opcao': s }}>
                            {ROTULO_SITUACAO[s]}
                        </Chip>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── O painel ────────────────────────────────────────────────────────────────

/**
 * `outrosParams`: os parâmetros das sugestões na URL (21/10/2026: as duas listas na mesma tela), para
 * a visita das criadas não apagá-los.
 */
export default function PainelCriadas({ criadas, filtros: filtrosDoServidor = null, outrosParams = {} }) {
    const filtros = { fase: null, situacao: null, envio: null, ordem: 'recentes', q: '', ...(filtrosDoServidor ?? {}) };
    const [modo, setModo] = useState(() => lerModoCriadas());
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [ocupado, setOcupado] = useState(false);
    const topo = useRef(null);
    const primeira = useRef(true);

    const ir = (mudancas, { rolar = false } = {}) => {
        router.get(route('portal.auth.estrutura.sugestoes'), { ...outrosParams, ...paramsDaCriadas(filtros, mudancas) }, {
            preserveState: true, preserveScroll: true, replace: true, only: ['criadas', 'filtros_criadas'],
            onStart: () => setOcupado(true),
            onFinish: () => {
                setOcupado(false);
                if (rolar) topo.current?.scrollIntoView({ block: 'start', behavior: 'smooth' });
            },
        });
    };

    // A busca vai ao servidor 350 ms depois da última tecla.
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return undefined; }
        if (busca.trim() === (filtros.q ?? '').trim()) return undefined;
        const t = setTimeout(() => ir({ q: busca }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    const trocarModo = (m) => {
        setModo(m);
        gravarModoCriadas(m);
    };

    const limpar = () => {
        setBusca('');
        ir({ fase: null, situacao: null, envio: null, q: '' });
    };

    if (! criadas || (criadas.total ?? 0) === 0) {
        return (
            <div className="mt-6 rounded-2xl border border-white/[0.08] bg-white/[0.02] px-6 py-10 text-center" data-criadas-vazio>
                <p className="text-[15px] font-semibold text-white">Nada criado ainda</p>
                <p className="mx-auto mt-1 max-w-md text-[13px] text-white/60">
                    Os combos, kits e combits que você aceitar nas sugestões ou montar à mão ficam aqui.
                </p>
            </div>
        );
    }

    const grupos = Array.isArray(criadas.grupos) ? criadas.grupos : [];
    const itens = grupos.flatMap((g) => g.itens ?? []);

    return (
        <div className="mt-5 space-y-5" data-criadas data-modo={modo} ref={topo}>
            <BarraDeFiltros filtros={filtros} contagens={criadas.contagens} ocupado={ocupado} modo={modo} onModo={trocarModo}
                onFiltro={(m) => ir(m)} busca={busca} onBusca={setBusca} />

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-white/60">
                <span data-criadas-total>{textoDoTotal(criadas, filtros)}</span>
                {filtroLigado(filtros) && (
                    <button type="button" onClick={limpar} className={cn('inline-flex items-center gap-1 text-[12.5px] text-white/60 underline-offset-2 hover:text-white hover:underline', FOCO)} data-acao="limpar-filtros-criadas">
                        <X size={13} aria-hidden="true" /> Limpar filtros
                    </button>
                )}
            </div>

            {itens.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-white/[0.12] px-6 py-10 text-center" data-criadas-filtro-vazio>
                    <p className="text-[15px] font-semibold text-white">Nada com esses filtros</p>
                    <button type="button" onClick={limpar} className={cn(ATALHO, ATALHO_NEUTRO, FOCO, 'mt-3')}>Limpar filtros</button>
                </div>
            ) : (
                <div className={cn('space-y-9 transition-opacity', ocupado && 'opacity-60')}>
                    {grupos.map((g) => {
                        const tipo = TIPO[g.fase] ?? TIPO_NEUTRO;
                        const total = criadas.contagens?.fase?.[g.fase];

                        return (
                            <section key={g.fase} data-grupo-criadas={g.fase}>
                                <header className="mb-3.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-full', tipo.cor)}>
                                        <tipo.Icone size={18} aria-hidden="true" />
                                    </span>
                                    <h2 className="text-[17px] font-semibold text-white">
                                        {g.rotulo} <span className="ml-0.5 text-[13px] font-normal tabular-nums text-white/45">{total ?? g.itens.length}</span>
                                    </h2>
                                    {tipo.explica && <span className="text-[13px] text-white/45">{tipo.explica}</span>}
                                </header>
                                {modo === 'lista' ? (
                                    <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card">
                                        <div className={cn(COLUNAS, 'h-9 border-b border-white/[0.06] bg-white/[0.02]')} aria-hidden="true">
                                            <span className={ROTULO}>Fotos</span>
                                            <span className={ROTULO}>Item</span>
                                            <span className={ROTULO}>Tipo</span>
                                            <span className={ROTULO}>Envio</span>
                                            <span className={cn(ROTULO, 'text-right')}>Clássico</span>
                                            <span className={cn(ROTULO, 'text-right')}>Premium</span>
                                            <span />
                                        </div>
                                        <ul>{g.itens.map((item) => <LinhaCriada key={item.id} item={item} />)}</ul>
                                    </div>
                                ) : (
                                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                                        {g.itens.map((item) => <Criada key={item.id} item={item} />)}
                                    </ul>
                                )}
                            </section>
                        );
                    })}
                </div>
            )}

            {(criadas.paginacao?.paginas ?? 1) > 1 && (
                <Paginacao rotulo="criadas" paginacao={criadas.paginacao} onIr={(pagina) => ir({ pagina }, { rolar: true })} />
            )}
        </div>
    );
}
