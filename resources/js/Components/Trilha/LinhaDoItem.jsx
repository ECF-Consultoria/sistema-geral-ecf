import { ETAPA_POR_CHAVE, acaoDoItem, estaNoAr } from '@/lib/trilha';
import { cn } from '@/lib/utils';
import { BolinhasDeProgresso, Pilula, SeloDoTipo } from '@/Components/Trilha/Pecas';
import { composicao, situacaoDoItem, skuDoProduto } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — uma linha da lista ────────────────────────────────
//
// A `linha()` do protótipo: SKU | nome (+ selo do tipo, e a família ou a
// composição embaixo) | progresso na trilha | situação | ação. A linha inteira é
// um botão que abre o painel lateral do item; o `data-chave` é por onde o foco
// volta quando o painel fecha.
//
// No celular (até 760px) vira duas colunas: SKU e ação no alto; nome,
// progresso e situação embaixo, na largura toda.

const LINHA = cn(
    'grid w-full grid-cols-[120px_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.7fr)_116px] items-center gap-x-4 gap-y-2',
    'border-b border-trilha-line2 px-[18px] py-3 text-left last:border-b-0 hover:bg-trilha-surface2',
    'focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run',
    'max-[760px]:grid-cols-[minmax(0,1fr)_auto]',
);

/**
 * @param {{
 *   entrada: {tipo: 'p'|'o', chave: string, etapa: string, item: object},
 *   visao: 'equipe'|'cliente',
 *   onAbrir: (chave: string, origem: ?HTMLElement) => void,
 * }} props
 */
export default function LinhaDoItem({ entrada, visao, onAbrir }) {
    const { tipo, chave, etapa, item } = entrada;
    const ehOferta = tipo === 'o';
    const situacao = situacaoDoItem(entrada);
    const noAr = ehOferta && estaNoAr(item);
    const acao = acaoDoItem(etapa, visao, noAr);
    // A cor da ação segue a do protótipo: âmbar quando falta algo, verde no ar, azul no resto. "No ar" é o que
    // o status diz: a oferta da etapa 6 que não está à venda não fica verde (com alerta, já fica âmbar).
    const tomDaAcao = situacao.alerta || etapa === 'lista' ? 'warn' : noAr ? 'ok' : 'run';
    const sku = ehOferta ? item.sku : skuDoProduto(item);
    const sub = ehOferta ? composicao(item) : item.familia || 'sem família';
    // Anúncio no ar com situação (pausado, em revisão…): a linha avisa, o detalhe fica no painel.
    const anunciosComSituacao = ehOferta ? (item.anuncios ?? []).filter((a) => a.situacao) : [];

    return (
        <button type="button" data-chave={chave} onClick={(evento) => onAbrir(chave, evento.currentTarget)} className={LINHA}>
            <span className="min-w-0 max-[760px]:col-start-1 max-[760px]:row-start-1">
                <span className="font-pub-mono text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{sku || '—'}</span>
            </span>

            <span className="min-w-0 max-[760px]:col-span-full">
                <span className="block font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                    {ehOferta && <SeloDoTipo tipo={item.tipo} />}
                    {item.nome || 'Produto sem nome'}
                </span>
                <span className="block font-pub-mono text-[12px] text-trilha-muted [overflow-wrap:anywhere]">{sub}</span>
                {ehOferta && item.sem_produto && (
                    <Pilula tom="neutro" className="mt-1">
                        sem produto na lista
                    </Pilula>
                )}
            </span>

            <span className="flex min-w-0 flex-col gap-1 max-[760px]:col-span-full">
                <BolinhasDeProgresso etapa={etapa} />
                <span className="text-[12px] text-trilha-muted">{ETAPA_POR_CHAVE[etapa]?.titulo}</span>
            </span>

            <span className={cn('min-w-0 text-[12.5px] max-[760px]:col-span-full', situacao.alerta ? 'text-trilha-warn' : 'text-trilha-fg2')}>
                {situacao.texto}
                {anunciosComSituacao.length > 0 && (
                    <span className="block text-[12px] text-trilha-muted">
                        {anunciosComSituacao.map((a) => `${a.tipo}: ${a.situacao}`).join(' · ')}
                    </span>
                )}
            </span>

            <Pilula
                tom={tomDaAcao}
                data-acao-da-linha={acao}
                className="justify-self-end max-[760px]:col-start-2 max-[760px]:row-start-1"
            >
                {acao}
            </Pilula>
        </button>
    );
}
