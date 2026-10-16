import { Fragment } from 'react';
import { AlertTriangle, ArrowRight, Box, ImageOff, Layers, Package, Pencil, Plus, Truck } from 'lucide-react';
import { fmtReais } from '@/Components/Portal/Estrutura/comum';
import { seloDoEnvio } from '@/lib/precificacaoEnvio';
import { pecasDaVitrine, resumoDasCriadas, rotuloDaPendencia, tamanhoDaPeca, textoDeQuantasCriadas } from '@/lib/sugestoesCriadas';
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
// a lado com "+"; o Combit, os dois jeitos juntos. O preço aparece em reais (antes só "Com preço"),
// o envio virou o selo curto da Precificação (o texto inteiro no balão) e os grupos ganharam a cor
// e o ícone de cada tipo dos cartões de resumo da aba Pendentes.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const ATALHO = 'inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-[12.5px] font-semibold transition-colors';
const ATALHO_NEUTRO = 'border border-white/[0.10] bg-white/[0.03] text-white/85 hover:bg-white/[0.08] hover:text-white';
const ATALHO_AMARELO = 'bg-ecf-yellow text-black hover:bg-ecf-yellow/90';
const ROTULO = 'text-[10.5px] font-semibold uppercase tracking-wider text-white/45';

// A identidade de cada tipo: a MESMA cor e o MESMO ícone dos cartões de resumo da aba Pendentes
// (ResumoSugestoes). Classes literais: o Tailwind só gera o que aparece escrito por inteiro.
const TIPO = {
    combo: { Icone: Box, nome: 'Combo', explica: 'o mesmo produto em mais unidades', cor: 'bg-violet-500/15 text-violet-300' },
    kit: { Icone: Package, nome: 'Kit', explica: 'produtos diferentes juntos', cor: 'bg-emerald-500/15 text-emerald-300' },
    combit: { Icone: Layers, nome: 'Combit', explica: 'um kit com mais unidades de um item', cor: 'bg-ecf-yellow/15 text-ecf-yellow' },
};
const TIPO_NEUTRO = { Icone: Package, nome: '', explica: '', cor: 'bg-white/[0.08] text-white/70' };

const TOM_DO_SELO = { ok: 'bg-white/[0.06] text-white/70', neutro: 'bg-white/[0.06] text-white/70', alerta: 'bg-amber-500/[0.14] text-amber-300' };

/**
 * Uma peça da vitrine: a foto do componente num quadro branco (as fotos de produto têm fundo claro,
 * como na lista de Produtos). Mais de uma unidade vira pilha — dois quadros atrás — e o selo "N×".
 */
function Peca({ componente, tamanho }) {
    const varias = componente.quantidade > 1;
    const nome = componente.nome ?? componente.sku ?? 'produto removido';

    return (
        <div className="relative shrink-0" style={{ width: tamanho, height: tamanho }} title={`${componente.quantidade}× ${nome}`}
            data-peca={componente.id} data-quantidade={componente.quantidade}>
            {/* A pilha acompanha o quadro: clara atrás da foto, escura atrás do "sem foto". */}
            {varias && (
                <>
                    <span aria-hidden="true" className={cn('absolute inset-0 translate-x-[10px] -translate-y-[10px] rounded-xl', componente.foto ? 'bg-white/25' : 'border border-white/[0.10] bg-white/[0.04]')} />
                    <span aria-hidden="true" className={cn('absolute inset-0 translate-x-[5px] -translate-y-[5px] rounded-xl', componente.foto ? 'bg-white/50' : 'border border-white/[0.12] bg-white/[0.07]')} />
                </>
            )}
            {componente.foto ? (
                <img src={componente.foto} alt={nome} loading="lazy" draggable="false"
                    className="relative h-full w-full rounded-xl bg-white object-contain p-1.5 shadow-[0_6px_18px_rgba(0,0,0,0.45)]" />
            ) : (
                <span className="relative flex h-full w-full flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-white/[0.16] bg-ecf-card px-1.5 text-center text-white/40" data-sem-foto>
                    <ImageOff size={tamanho >= 100 ? 22 : 16} aria-hidden="true" />
                    {tamanho >= 76 && <span className="line-clamp-2 break-all font-mono text-[10px] leading-tight text-white/55">{componente.sku ?? 'sem foto'}</span>}
                </span>
            )}
            {varias && (
                <span className="absolute -bottom-2 -right-2 rounded-full bg-ecf-yellow px-2 py-0.5 text-[13px] font-extrabold tabular-nums text-black shadow-[0_2px_8px_rgba(0,0,0,0.5)]" data-qtd>
                    {componente.quantidade}×
                </span>
            )}
        </div>
    );
}

/** A vitrine do cartão: as peças lado a lado, com "+" entre produtos diferentes, e o tipo no canto. */
function Vitrine({ item }) {
    const tipo = TIPO[item.fase] ?? TIPO_NEUTRO;
    const { pecas, resto } = pecasDaVitrine(item.composicao);
    const tamanho = tamanhoDaPeca(pecas.length + (resto > 0 ? 1 : 0));

    return (
        <div className="relative flex h-[196px] items-center justify-center gap-3 bg-gradient-to-b from-white/[0.05] to-white/[0.015] px-5 pt-4" data-vitrine>
            <span className={cn('absolute left-3 top-3 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold', tipo.cor)} data-tipo>
                <tipo.Icone size={12} aria-hidden="true" /> {tipo.nome}
            </span>
            {pecas.length === 0 && <span className="text-[12px] text-white/45">Sem composição</span>}
            {pecas.map((c, i) => (
                <Fragment key={`${c.id}-${i}`}>
                    {i > 0 && (
                        <span aria-hidden="true" className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-white/[0.08] text-white/70">
                            <Plus size={14} />
                        </span>
                    )}
                    <Peca componente={c} tamanho={tamanho} />
                </Fragment>
            ))}
            {resto > 0 && (
                <>
                    <span aria-hidden="true" className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-white/[0.08] text-white/70"><Plus size={14} /></span>
                    <span className="grid shrink-0 place-items-center rounded-xl border border-white/[0.12] bg-white/[0.04] text-[15px] font-bold text-white/80"
                        style={{ width: tamanho, height: tamanho }} data-resto>+{resto}</span>
                </>
            )}
        </div>
    );
}

/** O preço em reais quando fecha; quando não, o que falta, em destaque. */
function Preco({ item }) {
    const p = rotuloDaPendencia(item);

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
        <div className={cn('flex min-h-[54px] items-center gap-2 rounded-lg px-3 py-2 text-[13px] font-semibold',
            p.tom === 'falta' ? 'border border-amber-400/25 bg-amber-500/[0.08] text-amber-200' : 'bg-white/[0.04] text-white/60')} data-preco={p.chave}>
            {p.tom === 'falta' && <AlertTriangle size={15} aria-hidden="true" className="shrink-0" />}
            {p.texto}
        </div>
    );
}

function Criada({ item }) {
    const pendente = rotuloDaPendencia(item).chave !== 'ok';
    const envio = seloDoEnvio(item.envio);

    return (
        <li className="flex flex-col overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card transition-colors hover:border-white/[0.18]"
            data-criada={item.id} data-fase={item.fase}>
            <Vitrine item={item} />
            <div className="flex flex-1 flex-col gap-3 px-4 pb-4 pt-3.5">
                <div className="min-w-0">
                    <p className="line-clamp-2 min-h-[40px] text-[14.5px] font-semibold leading-snug text-white" title={item.nome ?? undefined}>{item.nome || item.sku}</p>
                    <p className="mt-1 truncate font-mono text-[11.5px] text-white/45" title={item.sku}>{item.sku}</p>
                    <p className="mt-2 flex flex-wrap gap-1.5" data-composicao>
                        {(item.composicao ?? []).map((c) => (
                            <span key={c.id} title={c.nome ?? undefined} className="rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[11px] text-white/65">
                                {c.quantidade}× {c.sku ?? 'produto removido'}
                            </span>
                        ))}
                    </p>
                </div>
                <Preco item={item} />
                <div className="mt-auto flex items-center justify-between gap-2 border-t border-white/[0.06] pt-3">
                    {envio ? (
                        <span className={cn('inline-flex min-w-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-semibold', TOM_DO_SELO[envio.tom])}
                            title={envio.dica} data-envio={envio.chave}>
                            <Truck size={13} aria-hidden="true" className="shrink-0" />
                            <span className="truncate">{envio.texto}</span>
                        </span>
                    ) : <span />}
                    <div className="flex shrink-0 gap-1.5">
                        <a href={route('portal.auth.estrutura.mapeamento', { abrir: item.id })} className={cn(ATALHO, ATALHO_NEUTRO, FOCO, 'w-9 justify-center px-0')}
                            data-acao="editar-criada" title="Nome e composição: mudar o nome, o código ou do que ele é feito" aria-label="Nome e composição">
                            <Pencil size={14} aria-hidden="true" />
                        </a>
                        <a href={route('portal.auth.estrutura.precificacao', { q: item.sku })} className={cn(ATALHO, pendente ? ATALHO_AMARELO : ATALHO_NEUTRO, FOCO)} data-acao="precificar-criada">
                            {pendente ? 'Completar preço' : 'Preço'} <ArrowRight size={13} aria-hidden="true" />
                        </a>
                    </div>
                </div>
            </div>
        </li>
    );
}

export default function PainelCriadas({ criadas }) {
    const grupos = Array.isArray(criadas?.grupos) ? criadas.grupos : [];

    if (grupos.length === 0) {
        return (
            <div className="mt-6 rounded-2xl border border-white/[0.08] bg-white/[0.02] px-6 py-10 text-center" data-criadas-vazio>
                <p className="text-[15px] font-semibold text-white">Nada criado ainda</p>
                <p className="mx-auto mt-1 max-w-md text-[13px] text-white/60">
                    Os combos, kits e combits que você aceitar em Pendentes ou montar à mão ficam aqui.
                </p>
            </div>
        );
    }

    const { comPreco, pendentes } = resumoDasCriadas(criadas);

    return (
        <div className="mt-5 space-y-9" data-criadas>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px] text-white/60">
                <span data-criadas-total>{textoDeQuantasCriadas(criadas)}</span>
                <span className="inline-flex items-center gap-1.5" data-criadas-com-preco>
                    <span aria-hidden="true" className="h-2 w-2 rounded-full bg-emerald-400" /> {comPreco} com preço
                </span>
                {pendentes > 0 && (
                    <span className="inline-flex items-center gap-1.5 text-amber-200/90" data-criadas-pendentes>
                        <span aria-hidden="true" className="h-2 w-2 rounded-full bg-amber-400" /> {pendentes} com pendência
                    </span>
                )}
            </div>
            {grupos.map((g) => {
                const tipo = TIPO[g.fase] ?? TIPO_NEUTRO;

                return (
                    <section key={g.fase} data-grupo-criadas={g.fase}>
                        <header className="mb-3.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-full', tipo.cor)}>
                                <tipo.Icone size={18} aria-hidden="true" />
                            </span>
                            <h2 className="text-[17px] font-semibold text-white">
                                {g.rotulo} <span className="ml-0.5 text-[13px] font-normal tabular-nums text-white/45">{g.itens.length}</span>
                            </h2>
                            {tipo.explica && <span className="text-[13px] text-white/45">{tipo.explica}</span>}
                        </header>
                        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                            {g.itens.map((item) => <Criada key={item.id} item={item} />)}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}
