import { useMemo } from 'react';
import { ETAPA_POR_CHAVE, chaveDoProduto } from '@/lib/trilha';
import { cn } from '@/lib/utils';
import { AcoesDoPainel, MolduraDoPainel, RegraDePronto, sobrelinhaDaEtapa } from '@/Components/Trilha/PainelDaEtapa';
import { FOTOS_MINIMAS, fmtCusto, plural, situacaoNaPlanilha, skuDoProduto } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — etapa 1: a lista de produtos como planilha ────────
//
// O `painelLista()` do protótipo: TODOS os produtos do cadastro (não só os que
// estão na etapa 1), os que mais faltam primeiro, cada coluna com ✓ ou "falta".
// Clicar na linha abre o painel lateral do produto.
//
// A planilha só MOSTRA. Quem preenche é o cadastro de produtos do Portal (pela
// ficha do produto ou pela planilha-modelo importada) — daí os dois quadros
// "Pelo sistema" / "Pela planilha", com o texto do protótipo trocado pelo que o
// Portal faz de verdade.

const COLUNAS = [
    { rotulo: 'Fotos' },
    { rotulo: 'SKU' },
    { rotulo: 'Produto' },
    { rotulo: 'Custo', numero: true },
    { rotulo: 'Medidas do produto' },
    { rotulo: 'Embalagem' },
    { rotulo: 'Descrição' },
    { rotulo: `Fotos (mín. ${FOTOS_MINIMAS})` },
    { rotulo: 'Ficha técnica' },
    { rotulo: 'Situação' },
];

const TD = 'border-b border-trilha-line2 px-3 py-2.5 align-middle';

function CelulaOk({ texto = null }) {
    return (
        <>
            <span className="font-semibold text-trilha-ok">✓</span>
            <span className="sr-only">ok</span>
            {texto && <span className="text-[12.5px] text-trilha-muted"> {texto}</span>}
        </>
    );
}

function CelulaFalta({ texto = null }) {
    return (
        <>
            <span className="inline-block rounded-md bg-trilha-warn-bg px-[7px] py-px text-[12px] font-semibold text-trilha-warn">falta</span>
            {texto && <span className="text-[12.5px] text-trilha-muted"> {texto}</span>}
        </>
    );
}

/**
 * Uma medida da planilha ("186 × 96 × 18 cm · 42 kg · 2 volumes"): as dimensões numa linha só e o resto
 * (peso, volumes) embaixo. Sem isto a célula estreita quebrava no meio ("… × 18" / "cm · 42 kg").
 */
function TextoDeMedida({ texto }) {
    const [dimensoes, ...resto] = String(texto).split(' · ');

    return (
        <span className="font-pub-mono text-[12.5px]">
            <span className="whitespace-nowrap">{dimensoes}</span>
            {resto.length > 0 && <span className="block whitespace-nowrap text-[12px] text-trilha-muted">{resto.join(' · ')}</span>}
        </span>
    );
}

/** A ficha técnica numa célula: ✓, "falta", "—" (sem categoria, ainda não há ficha) ou "a conferir". */
function CelulaDaFicha({ ficha }) {
    if (ficha === 'completa') return <CelulaOk />;
    if (ficha === 'incompleta') return <CelulaFalta />;
    if (ficha === 'sem_categoria') {
        return (
            <span className="text-trilha-muted" title="Sem categoria: a ficha técnica aparece quando a categoria é escolhida">
                —
            </span>
        );
    }

    return (
        <span className="text-[12.5px] text-trilha-muted" title="Os campos desta categoria ainda não estão guardados no sistema">
            a conferir
        </span>
    );
}

function LinhaDaPlanilha({ produto, onAbrir }) {
    const chave = chaveDoProduto(produto);
    const fotos = produto.fotos_min ?? 0;
    const custo = fmtCusto(produto);
    const situacao = situacaoNaPlanilha(produto);
    const ambientes = produto.ambientes ?? [];

    // A linha inteira abre o painel (mouse); o nome é o botão de verdade (teclado e
    // leitor de tela) e é para ele que o foco volta quando o painel fecha.
    const abrir = (evento) => onAbrir(chave, evento.currentTarget.querySelector('[data-chave]'));

    return (
        <tr data-produto={produto.id} onClick={abrir} className="cursor-pointer hover:bg-trilha-surface2">
            <td className={TD}>
                <span
                    aria-label={plural(fotos, 'foto', 'fotos')}
                    className={cn(
                        'inline-flex h-10 w-10 flex-none items-end justify-end rounded-lg p-[3px] font-pub-mono text-[10px] font-semibold text-trilha-fg2',
                        fotos ? 'bg-gradient-to-br from-trilha-line2 to-trilha-line' : 'border border-dashed border-trilha-line bg-trilha-surface2',
                    )}
                >
                    {fotos}
                </span>
            </td>
            {/* Sem quebra: o SKU partido no hífen ("CRIADO-" / "01") parecia dois códigos. */}
            <td className={cn(TD, 'whitespace-nowrap')}>
                <span className="font-pub-mono text-[12.5px] font-semibold text-trilha-fg">{skuDoProduto(produto) ?? '—'}</span>
                {produto.variacoes > 1 && <span className="block text-[12px] text-trilha-muted">{produto.variacoes} variações</span>}
            </td>
            <td className={cn(TD, 'min-w-[200px]')}>
                <button
                    type="button"
                    data-chave={chave}
                    className="text-left font-bold text-trilha-fg hover:underline focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                >
                    {produto.nome || 'Produto sem nome'}
                </button>
                <span className="block text-[12.5px] text-trilha-muted">
                    {produto.familia || 'sem família'}
                    {ambientes.length > 0 && ` · ${ambientes.join(', ')}`}
                </span>
            </td>
            {/* Sem `whitespace-nowrap`: a faixa "R$ 290,00 a R$ 310,00" quebra no "a" (dentro de cada valor o
                espaço já é inquebrável), e a planilha cabe inteira na tela de 1440 px. */}
            <td className={cn(TD, 'text-right tabular-nums')}>{custo ?? <CelulaFalta />}</td>
            <td className={TD}>
                {produto.medidas ? (
                    <TextoDeMedida texto={produto.medidas} />
                ) : (
                    // A medida do produto montado vem da ficha técnica e não é obrigatória na lista.
                    <span className="text-trilha-muted" title="Não informada na ficha técnica">
                        —
                    </span>
                )}
            </td>
            <td className={TD}>{produto.embalagem ? <TextoDeMedida texto={produto.embalagem} /> : <CelulaFalta />}</td>
            <td className={TD}>{produto.tem_descricao ? <CelulaOk /> : <CelulaFalta />}</td>
            <td className={cn(TD, 'whitespace-nowrap')}>
                {fotos >= FOTOS_MINIMAS ? <CelulaOk texto={plural(fotos, 'foto', 'fotos')} /> : <CelulaFalta texto={`${fotos} de ${FOTOS_MINIMAS}`} />}
            </td>
            <td className={TD}>
                <CelulaDaFicha ficha={produto.ficha} />
            </td>
            <td className={TD}>
                {/* Barrinha de largura fixa (como no protótipo): com `flex-1` ela encolhia conforme o texto ao lado. */}
                <div className="flex min-w-[120px] items-center gap-2">
                    <span className="block h-1.5 w-12 shrink-0 overflow-hidden rounded-full bg-trilha-line2">
                        <i className={cn('block h-full', situacao.tom === 'warn' ? 'bg-trilha-warn' : 'bg-trilha-ok')} style={{ width: `${situacao.porcento}%` }} />
                    </span>
                    <span className="whitespace-nowrap text-[12.5px]">{situacao.texto}</span>
                </div>
            </td>
        </tr>
    );
}

/**
 * @param {{
 *   dados: {produtos: Array},
 *   visao: 'equipe'|'cliente',
 *   links: object,
 *   onAbrir: (chave: string, origem: ?HTMLElement) => void,
 * }} props
 */
export default function PainelDaLista({ dados, links, onAbrir }) {
    const etapa = ETAPA_POR_CHAVE.lista;
    // Os que mais faltam primeiro. O sort é estável: no empate fica a ordem do servidor (etapa, nome).
    const produtos = useMemo(
        () => [...(dados.produtos ?? [])].sort((a, b) => (b.faltas?.length ?? 0) - (a.faltas?.length ?? 0)),
        [dados.produtos],
    );

    return (
        <MolduraDoPainel
            data-painel="lista"
            sobrelinha={sobrelinhaDaEtapa('lista')}
            titulo={etapa.titulo}
            texto={etapa.faz}
            acoes={<AcoesDoPainel chave="lista" links={links} />}
        >
            <RegraDePronto chave="lista" />

            <div className="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-3 border-b border-trilha-line px-[18px] py-4">
                <div className="flex flex-col gap-1.5 rounded-xl border border-trilha-line px-[14px] py-3">
                    <b className="text-[14px] text-trilha-fg">Pelo sistema</b>
                    <span className="text-[12.5px] text-trilha-muted">
                        Um produto por vez, na ficha do produto no cadastro do Portal. Ao escolher a categoria, a ficha técnica dela aparece para preencher.
                    </span>
                </div>
                <div className="flex flex-col gap-1.5 rounded-xl border border-trilha-line px-[14px] py-3">
                    <b className="text-[14px] text-trilha-fg">Pela planilha</b>
                    <span className="text-[12.5px] text-trilha-muted">
                        1. Baixe a planilha-modelo na tela de Produtos do Portal. 2. Preencha fora do sistema. 3. Importe de volta: o Portal mostra uma prévia antes de gravar.
                    </span>
                </div>
            </div>

            {produtos.length ? (
                // `relative` de propósito: as células têm `sr-only` (posição absoluta). Sem um ancestral posicionado,
                // elas escapam deste contêiner rolável e alargam a PÁGINA no celular (+639 px a 390 px de largura).
                <div className="relative overflow-x-auto">
                    <table className="w-full min-w-[980px] border-collapse text-[13px]">
                        <thead>
                            <tr>
                                {COLUNAS.map((coluna) => (
                                    <th
                                        key={coluna.rotulo}
                                        scope="col"
                                        className={cn(
                                            'whitespace-nowrap border-b border-trilha-line bg-trilha-surface2 px-3 py-2.5 text-left font-pub-mono text-[11px] font-semibold uppercase tracking-[0.06em] text-trilha-muted',
                                            coluna.numero && 'text-right',
                                        )}
                                    >
                                        {coluna.rotulo}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {produtos.map((produto) => (
                                <LinhaDaPlanilha key={produto.id} produto={produto} onAbrir={onAbrir} />
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="px-[18px] py-[30px] text-center text-trilha-muted">Nenhum produto cadastrado ainda.</div>
            )}
        </MolduraDoPainel>
    );
}
