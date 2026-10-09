import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react';

// ═══════════════════════════════════════════════════════════════════════════
// Rodapé de paginação da lista de Produtos (quick 261009-t03, tela 03 do
// pacote do Stitch): a frase de resumo à esquerda, "Linhas por página" no
// meio e os controles (primeira · anterior · números · próxima · última) à
// direita.
//
// ⚠️ A PAGINAÇÃO É DO CLIENTE: o servidor manda a lista INTEIRA
// (`ProgramasPublicadorService` não tem `paginate` nem `limit`), igual à
// ordenação que a tela já fazia. Nada aqui bate no backend.
//
// ⚠️ E ela é sobre LINHAS DE TOPO, nunca sobre as linhas da tabela. Os kits
// aparecem recuados SOB o seu base (`recuado` de `montarLinhas`): paginar as
// linhas cruas poria um base na página 1 e o kit dele na página 2, e um
// "Kit 2" solto no topo da página seguinte não se explica para ninguém. Por
// isso `paginar()` recebe FAMÍLIAS — `{ topo, kits }` — e corta entre elas,
// mesmo princípio que o `ordenarTopo` do layout v2 já usa.
//
// ⚠️ Toda função aqui é TOTAL: argumento em formato inesperado (objeto,
// array, nulo, ausente) devolve um default seguro e NUNCA estoura. Foi um
// objeto do presenter renderizado cru que derrubou a árvore React inteira em
// 07/10 ("Objects are not valid as a React child").
//
// ⚠️ O componente é BURRO de propósito: recebe o resultado de `paginar()`
// pronto e não toca em `localStorage` nem em `window`. Quem persiste o
// "linhas por página" é a página, no mesmo `try/catch` da densidade.
// ═══════════════════════════════════════════════════════════════════════════

/** A chave do `localStorage` do "linhas por página" (par da densidade). */
export const CHAVE_DAS_LINHAS = 'publicador.produtos.linhas';

/**
 * As opções do seletor. O mockup mostra 10/25/50; o 100 entra porque a conta
 * maior em produção passa de 50 e quem quer ver tudo não deve precisar de
 * três cliques.
 * ⚠️ Whitelist por ARRAY: `hasOwnProperty` deixaria `__proto__` passar.
 */
export const OPCOES_POR_PAGINA = [10, 25, 50, 100];

/** O padrão do mockup. */
export const POR_PAGINA_PADRAO = 10;

/** Quantos números de página cabem na janela antes de entrarem reticências. */
const JANELA = 7;

/** Objeto em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/**
 * Inteiro a partir de número ou string numérica; qualquer outra coisa cai no
 * padrão. ⚠️ Objeto e array ficam DE FORA na marra: `Number([25])` dá 25 e
 * deixaria um array vazar para dentro da whitelist.
 */
function inteiroSeguro(valor, padrao) {
    if (typeof valor !== 'number' && typeof valor !== 'string') return padrao;
    const numero = Number(valor);

    return Number.isFinite(numero) ? Math.floor(numero) : padrao;
}

/** Quantas linhas por página valem: só o que está na whitelist. */
export function porPaginaSegura(valor) {
    const numero = inteiroSeguro(valor, NaN);

    return OPCOES_POR_PAGINA.includes(numero) ? numero : POR_PAGINA_PADRAO;
}

/** Quantas páginas o resultado tem. ⚠️ Lista vazia devolve 1 página, nunca 0. */
export function totalDePaginas(totalDeTopos, porPagina) {
    const total = inteiroSeguro(totalDeTopos, 0);

    return total <= 0 ? 1 : Math.max(1, Math.ceil(total / porPaginaSegura(porPagina)));
}

/** A página pedida, presa no intervalo válido: fora dele cai na mais próxima. */
export function paginaSegura(valor, totalPaginas) {
    const teto = Math.max(1, inteiroSeguro(totalPaginas, 1));
    const pedida = inteiroSeguro(valor, 1);

    return Math.min(Math.max(1, pedida), teto);
}

/**
 * As linhas da tabela agrupadas em FAMÍLIAS: cada linha de topo e os kits
 * recuados que vêm logo abaixo dela. É a mesma leitura que a tela já fazia
 * em linha para reordenar os topos — aqui ela vira função pura porque é o
 * que torna a paginação testável sem DOM.
 *
 * ⚠️ Linha recuada SEM topo antes vira topo: nada pode sumir da lista por
 * causa do agrupamento (é o mesmo princípio do `montarLinhas`).
 */
export function familiasDasLinhas(linhas) {
    const familias = [];
    for (const linha of (Array.isArray(linhas) ? linhas : [])) {
        if (!linha || typeof linha !== 'object' || Array.isArray(linha)) continue;
        if (linha.recuado === true && familias.length > 0) {
            familias[familias.length - 1].kits.push(linha);
        } else {
            familias.push({ topo: linha, kits: [] });
        }
    }

    return familias;
}

/** O caminho de volta: famílias viram de novo a lista achatada de linhas. */
export function achatarFamilias(familias) {
    const linhas = [];
    for (const familia of (Array.isArray(familias) ? familias : [])) {
        const f = objetoSeguro(familia);
        if (f.topo === undefined) continue;
        linhas.push(f.topo);
        for (const kit of (Array.isArray(f.kits) ? f.kits : [])) linhas.push(kit);
    }

    return linhas;
}

/** Quantos PRODUTOS uma família ocupa na lista: o base mais os kits dele. */
const tamanhoDaFamilia = (familia) => {
    const f = objetoSeguro(familia);

    return (f.topo === undefined ? 0 : 1) + (Array.isArray(f.kits) ? f.kits.length : 0);
};

const somaDosTamanhos = (familias) => familias.reduce((soma, f) => soma + tamanhoDaFamilia(f), 0);

/**
 * Corta a lista de FAMÍLIAS na página pedida.
 *
 * @param {Array<{topo: Object, kits: Array}>} familias  as linhas de topo com os kits junto
 * @param {number|string} pagina                         fora do intervalo cai na válida mais próxima
 * @param {number|string} porPagina                      fora da whitelist cai no padrão
 * @returns {{pagina: number, porPagina: number, totalPaginas: number,
 *            totalDeTopos: number, totalDeProdutos: number,
 *            inicio: number, fim: number, itens: Array}}
 *          `inicio`/`fim` contam PRODUTOS (não famílias): é a faixa
 *          "Exibindo 1 - 7" do rodapé, e ela fecha porque as famílias são
 *          contíguas na lista achatada.
 */
export function paginar(familias, pagina, porPagina) {
    const lista = (Array.isArray(familias) ? familias : []).filter((f) => f && typeof f === 'object' && !Array.isArray(f));
    const quantas = porPaginaSegura(porPagina);
    const paginas = totalDePaginas(lista.length, quantas);
    const atual = paginaSegura(pagina, paginas);
    const corte = (atual - 1) * quantas;
    const itens = lista.slice(corte, corte + quantas);

    const antes = somaDosTamanhos(lista.slice(0, corte));
    const naPagina = somaDosTamanhos(itens);

    return {
        pagina: atual,
        porPagina: quantas,
        totalPaginas: paginas,
        totalDeTopos: lista.length,
        totalDeProdutos: somaDosTamanhos(lista),
        inicio: naPagina === 0 ? 0 : antes + 1,
        fim: antes + naPagina,
        itens,
    };
}

/**
 * Os números que aparecem nos controles, com as reticências do mockup.
 * Até `JANELA` páginas saem todas; acima disso a janela guarda sempre a
 * primeira, a última e a atual com as vizinhas.
 *
 * @returns {Array<number|'…'>}
 */
export function paginasVisiveis(pagina, total) {
    const paginas = Math.max(1, inteiroSeguro(total, 1));
    const atual = paginaSegura(pagina, paginas);

    if (paginas <= JANELA) return Array.from({ length: paginas }, (_, i) => i + 1);

    const escolhidas = new Set([1, paginas, atual, atual - 1, atual + 1]);
    // Perto das pontas a janela "desliza" para continuar com o mesmo tamanho:
    // sem isso a primeira página mostraria só "1 2 … 12" e sobraria espaço.
    if (atual <= 3) for (const n of [2, 3, 4]) escolhidas.add(n);
    if (atual >= paginas - 2) for (const n of [paginas - 1, paginas - 2, paginas - 3]) escolhidas.add(n);

    const numeros = [...escolhidas].filter((n) => n >= 1 && n <= paginas).sort((a, b) => a - b);

    const saida = [];
    let anterior = 0;
    for (const n of numeros) {
        if (anterior !== 0 && n - anterior > 1) saida.push('…');
        saida.push(n);
        anterior = n;
    }

    return saida;
}

/** Um pedaço de chave que nunca vira `[object Object]` colidindo com outro. */
const pedacoDaChave = (valor) => {
    if (typeof valor === 'string') return `s:${valor}`;
    if (typeof valor === 'number' || typeof valor === 'boolean') return `n:${String(valor)}`;
    if (valor === null) return 'nulo';
    if (valor === undefined) return 'ausente';

    return 'obj';
};

/**
 * A identidade da VISTA em vigor: filtro + fase + busca + ordenação.
 *
 * ⚠️ É isto que mata o beco sem saída clássico da paginação — ficar na
 * página 7 de um resultado que agora tem 2 páginas. A página não é estado
 * solto: ela vale para a vista em que foi escolhida. Mudou qualquer coisa da
 * vista, `paginaDaVista()` devolve 1.
 *
 * Derivado no render, sem `useEffect`: effect zerando página pisca a página
 * errada por um frame.
 */
export function chaveDaVista(estado) {
    const e = objetoSeguro(estado);
    const ordem = objetoSeguro(e.ordem);

    return [
        pedacoDaChave(e.filtro),
        pedacoDaChave(e.fase),
        pedacoDaChave(e.busca),
        pedacoDaChave(ordem.coluna),
        pedacoDaChave(ordem.direcao),
    ].join('');
}

/** A página que vale agora: a guardada só sobrevive na MESMA vista. */
export function paginaDaVista(vista, chave) {
    const v = objetoSeguro(vista);
    if (v.chave !== chave) return 1;

    return Math.max(1, inteiroSeguro(v.pagina, 1));
}

/**
 * Os pedaços da frase "Exibindo 1 - 7 de 22 produtos cadastrados".
 *
 * ⚠️ O rótulo muda com filtro/busca ativos: dizer "de 22 produtos
 * cadastrados" enquanto a lista mostra o recorte de um filtro seria mentira
 * do mesmo tipo que o card do ERP do mockup.
 */
export function resumoDaExibicao(paginacao, filtrado) {
    const p = objetoSeguro(paginacao);
    const inicio = inteiroSeguro(p.inicio, 0);
    const fim = inteiroSeguro(p.fim, 0);
    const total = Math.max(0, inteiroSeguro(p.totalDeProdutos, 0));
    const singular = total === 1;

    return {
        faixa: total === 0 || fim === 0 ? '0' : `${inicio} - ${fim}`,
        total: String(total),
        rotulo: filtrado === true
            ? (singular ? 'produto no filtro' : 'produtos no filtro')
            : (singular ? 'produto cadastrado' : 'produtos cadastrados'),
    };
}

const CONTROLE = 'inline-flex h-7 w-7 items-center justify-center rounded-lg border border-white/[0.10] bg-white/[0.03] text-white/70 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-40';

const SEPARADOR = <span aria-hidden="true" className="text-white/20">·</span>;

/**
 * O rodapé pronto. `paginacao` é o retorno de `paginar()`; `filtrado` diz se
 * há filtro ou busca em vigor (muda só o rótulo da frase); `rascunhos` e
 * `publicados` vêm das CONTAGENS do servidor — a tela não recalcula nada que
 * já recebeu pronto.
 */
export default function PaginacaoDaLista({
    paginacao,
    filtrado = false,
    rascunhos = 0,
    publicados = 0,
    aoMudarPagina,
    aoMudarPorPagina,
}) {
    const p = objetoSeguro(paginacao);
    const pagina = Math.max(1, inteiroSeguro(p.pagina, 1));
    const paginas = Math.max(1, inteiroSeguro(p.totalPaginas, 1));
    const porPagina = porPaginaSegura(p.porPagina);
    const resumo = resumoDaExibicao(p, filtrado);
    const numeros = paginasVisiveis(pagina, paginas);

    const irPara = (destino) => {
        const alvo = paginaSegura(destino, paginas);
        if (alvo !== pagina) aoMudarPagina?.(alvo);
    };

    const CONTROLES = [
        { chave: 'primeira', rotulo: 'Primeira página', Icone: ChevronsLeft, destino: 1, trava: 'inicio' },
        { chave: 'anterior', rotulo: 'Página anterior', Icone: ChevronLeft, destino: pagina - 1, trava: 'inicio' },
    ];
    const CONTROLES_FIM = [
        { chave: 'proxima', rotulo: 'Próxima página', Icone: ChevronRight, destino: pagina + 1, trava: 'fim' },
        { chave: 'ultima', rotulo: 'Última página', Icone: ChevronsRight, destino: paginas, trava: 'fim' },
    ];

    return (
        <div className="flex flex-col gap-3 border-t border-white/[0.06] p-4 lg:flex-row lg:items-center lg:justify-between">

            {/* A frase do mockup. ⚠️ Rascunhos e publicados vêm das contagens
                do servidor e NÃO mudam com a página nem com o filtro. */}
            <p className="flex flex-wrap items-center gap-2 text-[11px] font-normal text-white/55">
                <span>
                    Exibindo
                    {' '}
                    <strong className="font-bold text-white">{resumo.faixa}</strong>
                    {' de '}
                    <strong className="font-bold text-white">{resumo.total}</strong>
                    {` ${resumo.rotulo}`}
                </span>
                {SEPARADOR}
                <span>
                    <strong className="font-bold text-ecf-yellow">{inteiroSeguro(rascunhos, 0)}</strong>
                    {' rascunhos'}
                </span>
                {SEPARADOR}
                <span>
                    <strong className="font-bold text-emerald-400">{inteiroSeguro(publicados, 0)}</strong>
                    {' publicados no Meli'}
                </span>
            </p>

            <div className="flex flex-wrap items-center gap-4">
                <label className="flex items-center gap-2 text-[11px] font-normal text-white/55">
                    Linhas por página:
                    <select
                        value={porPagina}
                        onChange={(ev) => aoMudarPorPagina?.(porPaginaSegura(ev.target.value))}
                        className="h-7 rounded-lg border border-white/[0.10] bg-white/[0.03] px-2 text-[11px] font-normal text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        {OPCOES_POR_PAGINA.map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                </label>

                <nav aria-label="Paginação da lista" className="flex items-center gap-1">
                    {CONTROLES.map((controle) => {
                        // ⚠️ Flags calculadas DENTRO do callback: variável de
                        // escopo do componente lida dentro de `.map()` já foi
                        // eliminada pelo Rollup no bundle deste projeto
                        // (feedback_rollup_map_scope_bug.md).
                        const travado = pagina <= 1;
                        const Icone = controle.Icone;

                        return (
                            <button
                                key={controle.chave}
                                type="button"
                                disabled={travado}
                                aria-label={controle.rotulo}
                                onClick={() => irPara(controle.destino)}
                                className={CONTROLE}
                            >
                                <Icone className="h-4 w-4" aria-hidden="true" />
                            </button>
                        );
                    })}

                    <span className="flex items-center gap-1 px-1">
                        {numeros.map((item, indice) => {
                            // ⚠️ Idem: tudo dentro do callback.
                            const ehNumero = typeof item === 'number';
                            const ativa = ehNumero && item === pagina;

                            if (!ehNumero) {
                                return (
                                    <span key={`reticencia-${indice}`} aria-hidden="true" className="px-1 text-[11px] font-normal text-white/30">
                                        …
                                    </span>
                                );
                            }

                            return (
                                <button
                                    key={`pagina-${item}`}
                                    type="button"
                                    aria-label={`Página ${item}`}
                                    aria-current={ativa ? 'page' : undefined}
                                    onClick={() => irPara(item)}
                                    className={cn(
                                        'inline-flex h-7 w-7 items-center justify-center rounded-lg border text-[11px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                        ativa
                                            ? 'border-ecf-yellow/40 bg-ecf-yellow/10 font-bold text-ecf-yellow'
                                            : 'border-white/[0.10] bg-white/[0.03] font-normal text-white/70 hover:bg-white/[0.06]',
                                    )}
                                >
                                    {item}
                                </button>
                            );
                        })}
                    </span>

                    {CONTROLES_FIM.map((controle) => {
                        // ⚠️ Idem: tudo dentro do callback.
                        const travado = pagina >= paginas;
                        const Icone = controle.Icone;

                        return (
                            <button
                                key={controle.chave}
                                type="button"
                                disabled={travado}
                                aria-label={controle.rotulo}
                                onClick={() => irPara(controle.destino)}
                                className={CONTROLE}
                            >
                                <Icone className="h-4 w-4" aria-hidden="true" />
                            </button>
                        );
                    })}
                </nav>
            </div>
        </div>
    );
}
