import { ETAPA_POR_CHAVE, acaoDoItem, chaveDaPrevia, chaveDeCadastro, chaveDeMapear, estaEmAnalise, estaNoAr } from '@/lib/trilha';
import { quandoDaOfertaNaOnda } from '@/lib/trilhaOndas';
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
// Com a trilha de trabalho (2ª entrega): a oferta numa onda ganha a linha
// "{onda} · começa 05/11" (ou "começou 03/11"; "já começou" para a que andou
// antes da data da onda), e o produto da etapa 2 abre
// direto o painel de mapear para quem escreve (`m:{id}`) — para os outros, o
// painel do produto, só leitura.
//
// Da 3ª entrega (o cadastro na trilha): o produto da Lista abre a gaveta de
// cadastro (`e:{id}`) para quem escreve, com a ação "Completar"; o que já andou
// (`cadastro_travado`) abre o painel de leitura (`p:{id}`), com a ação "Ver".
//
// Da 4ª entrega (4a): o "Revisar" da oferta em revisão abre a prévia do anúncio
// (`v:{item}`) para a equipe com `escrita.revisao` (`comPrevia`); para os outros,
// o painel da oferta, como antes.
//
// No celular (até 760px) vira duas colunas: SKU e ação no alto; nome,
// progresso e situação embaixo, na largura toda.

const LINHA = cn(
    'grid w-full grid-cols-[120px_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.7fr)_116px] items-center gap-x-4 gap-y-2',
    'border-b border-trilha-line2 px-[18px] py-3 text-left last:border-b-0 hover:bg-trilha-surface2',
    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run',
    'max-[760px]:grid-cols-[minmax(0,1fr)_auto]',
);

/**
 * @param {{
 *   entrada: {tipo: 'p'|'o', chave: string, etapa: string, item: object},
 *   visao: 'equipe'|'cliente',
 *   onAbrir: (chave: string, origem: ?HTMLElement) => void,
 *   trabalho?: boolean,
 *   podeEditar?: boolean,
 *   comPrevia?: boolean,
 *   ondaPorId?: ?Map<number, object>,
 * }} props
 */
export default function LinhaDoItem({ entrada, visao, onAbrir, trabalho = false, podeEditar = false, comPrevia = false, ondaPorId = null }) {
    const { tipo, chave, etapa, item } = entrada;
    const ehOferta = tipo === 'o';
    const situacao = situacaoDoItem(entrada, { trabalho });
    const onda = ehOferta && item.onda_id != null ? ondaPorId?.get(item.onda_id) ?? null : null;
    const quandoNaOnda = quandoDaOfertaNaOnda(item, onda);
    // O produto da etapa 2 abre o painel de mapear para quem escreve, e o da Lista, a gaveta de cadastro (o
    // travado, o painel de leitura). A linha continua sendo a do produto (`data-chave`): o foco volta para ela.
    const travado = !ehOferta && !!item.cadastro_travado;
    let chaveAoAbrir = chave;
    if (!ehOferta && podeEditar && etapa === 'mapa') chaveAoAbrir = chaveDeMapear(item);
    if (!ehOferta && podeEditar && etapa === 'lista' && !travado) chaveAoAbrir = chaveDeCadastro(item);
    // A oferta em revisão: o "Revisar" abre a prévia do anúncio (a linha continua sendo a da oferta, `o:`).
    if (ehOferta && podeEditar && comPrevia && etapa === 'revisao') chaveAoAbrir = chaveDaPrevia(item);
    const noAr = ehOferta && estaNoAr(item);
    // Recém-publicado em análise (D59): ação neutra "Em análise" — nem verde de "No ar", nem âmbar de "Conferir".
    const emAnalise = ehOferta && estaEmAnalise(item);
    const acao = acaoDoItem(etapa, visao, noAr, { semProduto: ehOferta && !!item.sem_produto, trabalho, travado: podeEditar && travado, emAnalise });
    // A cor da ação segue a do protótipo: âmbar quando falta algo, verde no ar, azul no resto. "No ar" é o que
    // o status diz: a oferta da etapa 6 que não está à venda não fica verde (com alerta, já fica âmbar).
    const tomDaAcao = situacao.alerta || etapa === 'lista' ? 'warn' : noAr ? 'ok' : 'run';
    const sku = ehOferta ? item.sku : skuDoProduto(item);
    const sub = ehOferta ? composicao(item) : item.familia || 'sem família';
    // Anúncio no ar com situação (pausado, em revisão…): a linha avisa, o detalhe fica no painel.
    const anunciosComSituacao = ehOferta ? (item.anuncios ?? []).filter((a) => a.situacao) : [];

    return (
        <button type="button" data-chave={chave} onClick={(evento) => onAbrir(chaveAoAbrir, evento.currentTarget)} className={LINHA}>
            <span className="min-w-0 max-[760px]:col-start-1 max-[760px]:row-start-1">
                <span className="font-pub-mono text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{sku || '—'}</span>
            </span>

            <span className="min-w-0 max-[760px]:col-span-full">
                <span className="block font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                    {ehOferta && <SeloDoTipo tipo={item.tipo} />}
                    {item.nome || 'Produto sem nome'}
                </span>
                <span className="block font-pub-mono text-[12px] text-trilha-muted [overflow-wrap:anywhere]">{sub}</span>
                {onda && (
                    <span className="mt-0.5 flex items-center gap-1.5 text-[12px] font-semibold text-trilha-fg2 [overflow-wrap:anywhere]" data-onda-da-linha={onda.id}>
                        <i className="block h-2 w-2 shrink-0 rounded-[2px] bg-trilha-s-plano" aria-hidden="true" />
                        {/* A que já andou (ou não tem produto) numa onda por começar não "começa dd/mm": ela não espera a data. */}
                        {onda.nome}
                        {quandoNaOnda && ` · ${quandoNaOnda}`}
                    </span>
                )}
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
