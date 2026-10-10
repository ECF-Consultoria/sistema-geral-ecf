import { Check, ChevronRight, Info, MoreVertical, Package } from 'lucide-react';
import * as Popover from '@radix-ui/react-popover';
import { router, usePage } from '@inertiajs/react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import Explicacao from '@/Components/Explicacao';
import { ESTILO_LOGISTICA, detalheDaVariacao, faltaDoProduto, iniciais, partesDaCategoria, renderFrete } from '@/lib/produtosEstrutura';
import { submoduloVisivel } from '@/lib/portalSubmodulos';
import { cn } from '@/lib/utils';

// ─── Peças pequenas da ficha do produto (167-19), reaproveitadas pela lista ──
//
// Só apresentação: o que mostram vem pronto do servidor (D-15/D-19/D-28).

const TAMANHOS_QUADRO = {
    grande: { caixa: 'h-[198px] w-full lg:w-[266px]', iniciais: 'text-[40px]', icone: 64 },
    cartao: { caixa: 'h-[108px] w-[106px]', iniciais: 'text-[28px]', icone: 44 },
    linha:  { caixa: 'h-[78px] w-[78px]', iniciais: 'text-[22px]', icone: 34 },
    mini:   { caixa: 'h-[62px] w-[62px]', iniciais: 'text-[16px]', icone: 28 },
    // Tamanhos da linha compacta das sugestões (168-18): só acréscimo, a ficha usa os de cima.
    sugestao:  { caixa: 'h-[64px] w-[100px]', iniciais: 'text-[18px]', icone: 30 },
    miniatura: { caixa: 'h-[30px] w-[34px]', iniciais: 'text-[11px]', icone: 16 },
    icone:     { caixa: 'h-[20px] w-[20px] rounded-[5px]', iniciais: 'text-[9px]', icone: 12 },
};

/** Quadro da foto (D-29): existe, mas sem upload — ícone apagado e as iniciais do produto. */
/**
 * D-32: contorno do cartão do produto de onde a pessoa acabou de voltar da ficha.
 * 'forte' nos primeiros segundos, depois 'leve' até sair da página.
 */
export function classeDestaque(destaque) {
    if (destaque === 'forte') return 'border-ecf-yellow ring-2 ring-ecf-yellow/50 shadow-[0_0_0_6px_rgba(255,230,0,0.10)]';
    if (destaque === 'leve') return 'border-ecf-yellow/50';

    return null;
}

/** D-32: etiqueta no topo do cartão destacado — diz em palavras qual produto acabou de ser aberto. */
export function EtiquetaUltimoAberto({ destaque }) {
    if (! destaque) return null;

    return (
        <span data-ultimo-aberto className="pointer-events-none absolute -top-2.5 left-4 z-10 rounded-full bg-ecf-yellow px-2 py-0.5 text-[11px] font-semibold leading-4 text-black">
            Último aberto
        </span>
    );
}

/**
 * O quadro da foto. Com `foto`, mostra a imagem; sem ela, as iniciais sobre o ícone.
 *
 * Continua SEM upload (D-29) — quem envia é a galeria da variação. Aqui a foto só
 * aparece: assim que a primeira imagem entra, o quadro deixa de ser um retângulo vazio.
 */
export function QuadroFotoProduto({ nome, foto = null, tamanho = 'grande', className }) {
    const t = TAMANHOS_QUADRO[tamanho] ?? TAMANHOS_QUADRO.grande;
    const caixa = cn('relative grid shrink-0 place-items-center overflow-hidden rounded-[10px] border border-white/[0.06] bg-white/[0.04]', t.caixa, className);

    if (foto) {
        return (
            // Fundo branco + `object-contain`: a foto de produto (quase sempre fundo branco) aparece
            // inteira em qualquer formato de quadro — `object-cover` cortava o topo e o pé do produto.
            <div className={cn(caixa, 'bg-white')} data-quadro-foto="com-foto">
                <img src={foto} alt={`Foto de ${nome || 'produto novo'}`} loading="lazy" draggable={false} className="h-full w-full object-contain" />
            </div>
        );
    }

    return (
        <div role="img" aria-label={`Sem foto de ${nome || 'produto novo'}`} data-quadro-foto="sem-foto"
            className={caixa}>
            <Package size={t.icone} strokeWidth={1.25} className="text-white/15" aria-hidden="true" />
            <span className={cn('absolute font-display font-semibold text-white/45', t.iniciais)}>{iniciais(nome)}</span>
        </div>
    );
}

/** Selo de logística (ME2 · Full, ME2, ME1, Pendente). O `ponto` é o da célula "Logística provável". */
export function PilulaLogistica({ chave, rotulos = {}, ponto = false, className }) {
    const estilo = ESTILO_LOGISTICA[chave] ?? ESTILO_LOGISTICA.pendente;

    return (
        <span className={cn('inline-flex h-7 items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 text-[13px] font-semibold', estilo, className)}
            title={chave === 'pendente' ? 'Pendente: completar cadastro' : undefined}>
            {ponto && <span className="h-2.5 w-2.5 rounded-full bg-current" aria-hidden="true" />}
            {rotulos[chave] ?? chave}
        </span>
    );
}

/** Asterisco dos campos obrigatórios (só Ref e nome do produto). Cor do sistema: vermelho é só erro. */
export function Obrigatorio() {
    return (
        <>
            <span aria-hidden="true" className="ml-0.5 text-white/50">*</span>
            <span className="sr-only"> (obrigatório)</span>
        </>
    );
}

/**
 * Rótulo de campo com o "o que é isto?" ao lado (pedido do usuário, 08/10/2026: explicar todo
 * campo ao passar o mouse). O ícone fica FORA do <label>: clicar nele não pode focar o campo.
 * Sem texto, fica só o rótulo. `como="span"` para o rótulo de um gatilho que não é campo
 * (Família, Ambientes, Categoria) e `className` sem margem: a margem é da linha.
 */
export function RotuloComExplicacao({ htmlFor, id, explicacao, nome, className, como = 'label', children }) {
    const Rotulo = como;

    return (
        <div className="mb-1 flex min-w-0 items-center gap-1" data-rotulo>
            <Rotulo id={id} htmlFor={como === 'label' ? htmlFor : undefined} className={className}>{children}</Rotulo>
            <Explicacao texto={explicacao} nome={nome} />
        </div>
    );
}

/** Caminho da categoria "A › B › C"; `curto` mostra só a 1ª e a última parte. */
export function CaminhoCategoria({ linha, curto = false, className }) {
    const { partes, estado } = partesDaCategoria(linha);

    if (partes.length === 0) {
        return <span className={cn('text-white/45', className)}>Sem categoria</span>;
    }
    const mostradas = curto && partes.length > 2 ? [partes[0], partes[partes.length - 1]] : partes;
    const apoio = estado === 'a_confirmar' ? ' · a confirmar' : estado === 'nao_validada' ? ' · não validada' : '';

    return (
        <span className={cn('inline-flex min-w-0 flex-wrap items-center gap-x-1.5 text-white', className)} title={partes.join(' › ')}>
            {mostradas.map((parte, i) => (
                <span key={`${parte}-${i}`} className="inline-flex min-w-0 items-center gap-1.5">
                    {i > 0 && <ChevronRight size={13} className="shrink-0 text-white/40" aria-hidden="true" />}
                    <span className="truncate">{parte}</span>
                </span>
            ))}
            {apoio && <span className="text-white/45">{apoio}</span>}
        </span>
    );
}

// ─── Peças dos cartões da lista (167-20, D-25/D-30) ─────────────────────────

/**
 * Clique no cartão abre a ficha. Ignora o que nasceu dentro de `[data-nao-abrir]` (menu, pílula) e o
 * clique com Ctrl/Meta/Shift/Alt ou botão do meio: aí o navegador abre o link do nome em outra aba.
 */
export function aoClicarNoCartao(e, abrir) {
    if (e.target?.closest?.('[data-nao-abrir]')) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || (e.button ?? 0) !== 0) return;
    e.preventDefault();
    abrir();
}

/**
 * Uma variação: Ref, valor, selo de logística e frete. Medidas, peso cubado e custo ficam na dica.
 * Sem bolinha de cor (D-31): o palpite de cor pelo nome errava e poluía o cartão.
 */
export function LinhaVariacao({ variacao, vocabulario, consultando, modo = 'grande' }) {
    const lista = modo === 'lista';
    const emConsulta = consultando?.has?.(variacao.id) ?? false;

    return (
        <li data-variacao-cartao title={detalheDaVariacao(variacao)}
            className={cn('grid items-center',
                lista ? 'grid-cols-[minmax(0,120px)_minmax(0,64px)_auto_minmax(0,1fr)] gap-x-4 py-1'
                    : 'grid-cols-[minmax(0,108px)_minmax(0,1fr)_auto_minmax(72px,auto)] gap-x-3.5 py-3.5')}>
            <span className="truncate text-[14px] font-medium text-white">{variacao.codigo}</span>
            <span className="truncate text-[14px] text-white/75">{variacao.valor || '—'}</span>
            <PilulaLogistica chave={variacao.logistica ?? 'pendente'} rotulos={vocabulario?.logisticas} className="h-8 px-3" />
            <span className={cn('min-w-0', lista ? 'text-left' : 'text-right')}>
                {renderFrete(variacao, { consultando: emConsulta }, 'pilha')}
            </span>
        </li>
    );
}

/** Pílula "Falta: …" com o detalhe por variação num popover. Cor neutra: pendência não é alarme. */
export function PilulaFalta({ variacoes, rotulos, nome }) {
    const falta = faltaDoProduto(variacoes, rotulos);
    if (! falta.texto) return null;

    return (
        <Popover.Root>
            <Popover.Trigger asChild>
                <button type="button" data-nao-abrir aria-label={`Ver o que falta em ${nome}`}
                    className="inline-flex h-9 max-w-[240px] shrink-0 items-center gap-2 rounded-lg bg-white/[0.06] px-3 text-[13px] text-white/80 hover:bg-white/[0.09] focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                    <span className="truncate">Falta: {falta.texto}</span>
                    <Info size={16} className="shrink-0" aria-hidden="true" />
                </button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content data-nao-abrir align="end" sideOffset={6}
                    className="z-50 w-72 rounded-xl border border-white/[0.08] bg-ecf-card p-3 text-[12px] text-white/75 shadow-xl">
                    <p className="font-semibold text-white">O que falta</p>
                    <ul className="mt-2 space-y-1">
                        {falta.porVariacao.map((v) => <li key={v.codigo}>{v.codigo}: {v.texto}</li>)}
                    </ul>
                    <p className="mt-2 text-white/50">Abra o produto para completar.</p>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

/**
 * Caixa de seleção do cartão (10/10/2026): marca o produto para a ação em lote da lista (excluir).
 * Não abre a ficha (`data-nao-abrir`). É um botão com papel de caixa: a área de toque tem 36 px.
 */
export function CaixaDeSelecao({ marcado = false, nome, onMudar, className }) {
    return (
        <button type="button" role="checkbox" aria-checked={marcado} aria-label={`Selecionar ${nome}`} data-nao-abrir data-selecionar-produto
            onClick={() => onMudar(! marcado)}
            className={cn('grid h-9 w-9 place-items-center rounded-lg hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40', className)}>
            <span className={cn('grid h-[18px] w-[18px] place-items-center rounded-[5px] border', marcado ? 'border-ecf-yellow bg-ecf-yellow text-black' : 'border-white/30 bg-black/30')}>
                {marcado && <Check size={13} strokeWidth={3} aria-hidden="true" />}
            </span>
        </button>
    );
}

/**
 * Menu ⋮ (D-30): só ações que existem — abrir a ficha, ver a oferta de cada variação na Lista SKUs,
 * montar um combo, kit ou combit com o produto e, desde 10/10/2026, excluir o produto (a confirmação é
 * da página). Quem não vê a Lista SKUs (o cliente, desde 09/10/2026) vê o SKU na Precificação; o
 * "Montar" abre o Planejamento com o produto já escolhido.
 */
export function MenuDoProduto({ produtoId, nome, variacoes, onAbrir, onExcluir = null, className }) {
    const comOferta = (variacoes ?? []).filter((v) => v.oferta?.sku);
    const { modulos = [] } = usePage().props;
    const listaVisivel = submoduloVisivel(modulos, 'lista');
    const planejamentoVisivel = submoduloVisivel(modulos, 'sugestoes');

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" data-nao-abrir aria-label={`Ações de ${nome}`}
                    className={cn('grid h-9 w-9 place-items-center rounded-lg text-white/60 hover:bg-white/[0.06] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40', className)}>
                    <MoreVertical size={20} aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" data-nao-abrir className="border-white/[0.08] bg-ecf-card text-white">
                <DropdownMenuItem onSelect={() => onAbrir(produtoId)}>Abrir a ficha</DropdownMenuItem>
                {planejamentoVisivel && comOferta.length > 0 && (
                    <DropdownMenuItem onSelect={() => router.visit(route('portal.auth.estrutura.sugestoes', { montar: produtoId }))} data-acao="montar-kit-do-produto">
                        Montar combo, kit ou combit com este produto
                    </DropdownMenuItem>
                )}
                {comOferta.map((v) => (listaVisivel ? (
                    <DropdownMenuItem key={v.id} onSelect={() => router.visit(route('portal.auth.estrutura.lista', { q: v.oferta.sku }))}>
                        Ver {v.oferta.sku} na Lista SKUs
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuItem key={v.id} onSelect={() => router.visit(route('portal.auth.estrutura.precificacao', { q: v.oferta.sku }))}>
                        Ver {v.oferta.sku} na Precificação
                    </DropdownMenuItem>
                )))}
                {onExcluir && (
                    <>
                        <DropdownMenuSeparator className="bg-white/[0.08]" />
                        <DropdownMenuItem onSelect={() => onExcluir(produtoId)} data-acao="excluir-produto">
                            Excluir produto
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
