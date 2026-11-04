import { Suspense, lazy, useMemo, useState } from 'react';
import { chaveDeCadastro, chaveDoProduto, etapaDaTrilha } from '@/lib/trilha';
import { alvoDaColuna, irParaOCadastro } from '@/lib/trilhaFaltas';
import { cn } from '@/lib/utils';
import { AcoesComNovoProduto, AcoesDoPainel, BotaoNovoProduto, MolduraDoPainel, RegraDePronto, sobrelinhaDaEtapa } from '@/Components/Trilha/PainelDaEtapa';
import PilulaDaFalta from '@/Components/Trilha/PilulaDaFalta';
import { FOTOS_MINIMAS, avisosEmTexto, fmtCusto, plural, situacaoNaPlanilha, skuDoProduto } from '@/Components/Trilha/formatos';

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
//
// Com a trilha de trabalho (3ª entrega, 02/11/2026) quem cadastra é a EQUIPE,
// aqui dentro: "+ Produto na lista" cria (a gaveta `e:novo`) e a linha abre a
// gaveta de cadastro do produto (`e:{id}`) — a do produto que já andou
// (`cadastro_travado`), o painel de leitura (`p:{id}`). O quadro "Pelo sistema"
// da equipe mora em `Cadastro/` e chega por `lazy` (só quem escreve baixa o JS
// da pasta); o cliente e o "Ver como: Cliente" leem só uma nota neutra, e o
// "Pela planilha" do Portal sai. Da F7, o "Pela planilha" da equipe (a planilha
// por categoria, gaveta `e:planilha`) vem no mesmo quadro `lazy`; da F8, a
// importação dos anúncios da loja (gaveta `e:ml`) também.
//
// Do 1º teste do usuário (04/11/2026):
// - R2: a coluna "Fotos" mostra a MINIATURA da capa (a 1ª foto da 1ª cor com
//   foto), com a contagem no canto. Antes ela era o marcador do protótipo — um
//   quadrado com o número — e nenhum endereço de foto chegava à Lista. O
//   endereço vem do servidor, já na rota da porta de quem olha (`capa`); sem ele
//   (ou se a imagem não carregar), volta o quadrado com a contagem.
// - R11: para quem escreve, cada "falta" da planilha e cada falta da coluna
//   "Situação" é um botão que abre a gaveta do produto já no campo daquela falta
//   (`lib/trilhaFaltas.js`). Para o cliente e o produto que já andou, texto.

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

// O quadro da equipe: só quem escreve baixa a pasta `Cadastro/` (os textos dela são da equipe).
const QuadrosDaLista = lazy(() => import('@/Components/Trilha/Cadastro/QuadrosDaLista'));

function CelulaOk({ texto = null }) {
    return (
        <>
            <span className="font-semibold text-trilha-ok">✓</span>
            <span className="sr-only">ok</span>
            {texto && <span className="text-[12.5px] text-trilha-muted"> {texto}</span>}
        </>
    );
}

/**
 * "falta" numa coluna da planilha. Com `onIr` (quem escreve, R11) o "falta" é um botão que leva ao campo da coluna
 * (`coluna`: custo, embalagem, descricao, fotos, ficha); sem, o texto de sempre.
 */
function CelulaFalta({ texto = null, coluna = null, produto = null, onIr = null }) {
    const alvo = coluna ? alvoDaColuna(coluna, produto) : null;

    return (
        <>
            {onIr && alvo ? (
                <PilulaDaFalta falta="falta" alvo={alvo} onIr={onIr} acessivel={ROTULO_DA_COLUNA[coluna] ?? coluna} />
            ) : (
                <span className="inline-block rounded-md bg-trilha-warn-bg px-[7px] py-px text-[12px] font-semibold text-trilha-warn">falta</span>
            )}
            {texto && <span className="text-[12.5px] text-trilha-muted"> {texto}</span>}
        </>
    );
}

/** O que o leitor de tela diz no "falta" de cada coluna (o botão leva ao campo dela). */
const ROTULO_DA_COLUNA = { custo: 'custo', embalagem: 'embalagem', descricao: 'descrição', fotos: 'fotos', ficha: 'ficha técnica' };

/**
 * A coluna "Fotos" (R2): a miniatura da capa com a contagem no canto. Sem capa — ou a imagem que não carregou —, o
 * quadrado com a contagem de antes (vazio e tracejado quando não há foto nenhuma).
 */
function CapaDoProduto({ capa, fotos }) {
    const [quebrada, setQuebrada] = useState(false);
    const rotulo = plural(fotos, 'foto', 'fotos');

    if (capa && !quebrada) {
        return (
            <span className="relative inline-flex h-10 w-10 flex-none overflow-hidden rounded-lg border border-trilha-line bg-trilha-surface2" data-capa-da-lista="">
                <img src={capa} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" onError={() => setQuebrada(true)} />
                <span
                    aria-label={rotulo}
                    className="absolute bottom-0.5 right-0.5 rounded bg-trilha-surface/90 px-[3px] font-pub-mono text-[10px] font-semibold leading-tight text-trilha-fg2"
                >
                    {fotos}
                </span>
            </span>
        );
    }

    return (
        <span
            aria-label={rotulo}
            className={cn(
                'inline-flex h-10 w-10 flex-none items-end justify-end rounded-lg p-[3px] font-pub-mono text-[10px] font-semibold text-trilha-fg2',
                fotos ? 'bg-gradient-to-br from-trilha-line2 to-trilha-line' : 'border border-dashed border-trilha-line bg-trilha-surface2',
            )}
            data-sem-capa=""
        >
            {fotos}
        </span>
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
function CelulaDaFicha({ ficha, produto = null, onIr = null }) {
    if (ficha === 'completa') return <CelulaOk />;
    if (ficha === 'incompleta') return <CelulaFalta coluna="ficha" produto={produto} onIr={onIr} />;
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

function LinhaDaPlanilha({ produto, onAbrir, podeEditar = false }) {
    const chave = chaveDoProduto(produto);
    // Quem escreve abre a gaveta de cadastro; o produto que já andou, só o painel de leitura.
    const chaveAoAbrir = podeEditar && !produto.cadastro_travado ? chaveDeCadastro(produto) : chave;
    const fotos = produto.fotos_min ?? 0;
    const custo = fmtCusto(produto);
    const situacao = situacaoNaPlanilha(produto);
    const ambientes = produto.ambientes ?? [];
    // O que não segura o produto na Lista (com a trilha de trabalho: "vai por ME1 (transportadora)"): AVISO, nunca
    // "falta" — a barrinha e o "Completo" não mudam por ele.
    const avisos = avisosEmTexto(produto.avisos);
    // R11: quem escreve vai da falta direto ao campo dela, na gaveta do produto (o travado só se lê: texto).
    const irPara = irParaOCadastro(onAbrir, produto, podeEditar);

    // A linha inteira abre o painel (mouse); o nome é o botão de verdade (teclado e
    // leitor de tela) e é para ele que o foco volta quando o painel fecha.
    const abrir = (evento) => onAbrir(chaveAoAbrir, evento.currentTarget.querySelector('[data-chave]'));

    return (
        <tr data-produto={produto.id} onClick={abrir} className="cursor-pointer hover:bg-trilha-surface2">
            <td className={TD}>
                {/* A chave: a capa nova (outra foto virou a 1ª) tenta carregar de novo, mesmo depois de uma que falhou. */}
                <CapaDoProduto key={produto.capa ?? ''} capa={produto.capa ?? null} fotos={fotos} />
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
                    data-abre={chaveAoAbrir}
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
            <td className={cn(TD, 'text-right tabular-nums')}>{custo ?? <CelulaFalta coluna="custo" produto={produto} onIr={irPara} />}</td>
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
            <td className={TD}>{produto.embalagem ? <TextoDeMedida texto={produto.embalagem} /> : <CelulaFalta coluna="embalagem" produto={produto} onIr={irPara} />}</td>
            <td className={TD}>{produto.tem_descricao ? <CelulaOk /> : <CelulaFalta coluna="descricao" produto={produto} onIr={irPara} />}</td>
            <td className={cn(TD, 'whitespace-nowrap')}>
                {fotos >= FOTOS_MINIMAS ? (
                    <CelulaOk texto={plural(fotos, 'foto', 'fotos')} />
                ) : (
                    <CelulaFalta texto={`${fotos} de ${FOTOS_MINIMAS}`} coluna="fotos" produto={produto} onIr={irPara} />
                )}
            </td>
            <td className={TD}>
                <CelulaDaFicha ficha={produto.ficha} produto={produto} onIr={irPara} />
            </td>
            <td className={TD}>
                {/* Barrinha de largura fixa (como no protótipo): com `flex-1` ela encolhia conforme o texto ao lado. */}
                <div className="flex min-w-[120px] items-center gap-2">
                    <span className="block h-1.5 w-12 shrink-0 overflow-hidden rounded-full bg-trilha-line2">
                        <i className={cn('block h-full', situacao.tom === 'warn' ? 'bg-trilha-warn' : 'bg-trilha-ok')} style={{ width: `${situacao.porcento}%` }} />
                    </span>
                    <span className="whitespace-nowrap text-[12.5px]">{situacao.texto}</span>
                </div>
                {/* A lista de faltas (pílulas clicáveis) fica SÓ no painel lateral e na gaveta — na linha ela empilhava
                    8 pílulas por produto (pedido do usuário, 04/11). Aqui: a barrinha e o "N faltam". */}
                {avisos.length > 0 && (
                    <span className="mt-1 block text-[12px] text-trilha-run" data-aviso-do-produto="">
                        <span className="font-semibold">aviso:</span> {avisos.join(' · ')}
                    </span>
                )}
            </td>
        </tr>
    );
}

/** Os dois quadros de como a Lista se preenche, sem a trilha de trabalho (1ª entrega): o Portal faz. */
function QuadrosDoPortal() {
    return (
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
    );
}

/** Com a trilha de trabalho, quem não cadastra (o cliente, o "Ver como: Cliente") lê só isto. */
function NotaDaLista() {
    return (
        <div className="border-b border-trilha-line px-[18px] py-4">
            <div className="flex flex-col gap-1.5 rounded-xl border border-dashed border-trilha-line px-[14px] py-3" data-nota="lista">
                <span className="text-[12.5px] text-trilha-fg2">A equipe ECF cadastra os produtos da sua loja aqui.</span>
            </div>
        </div>
    );
}

/**
 * @param {{
 *   dados: {produtos: Array},
 *   visao: 'equipe'|'cliente',
 *   links: object,
 *   onAbrir: (chave: string, origem: ?HTMLElement) => void,
 *   trabalho?: boolean,
 *   podeEditar?: boolean,
 *   comPlanilha?: boolean,
 *   comImportacao?: boolean,
 * }} props  `comPlanilha`: veio o endereço da planilha por categoria (`escrita.planilha`, F7); `comImportacao`: vieram
 *   os da importação dos anúncios da loja (`escrita.mercado_livre`, F8).
 */
export default function PainelDaLista({ dados, links, onAbrir, trabalho = false, podeEditar = false, comPlanilha = false, comImportacao = false }) {
    const etapa = etapaDaTrilha('lista', trabalho);
    // Os que mais faltam primeiro. O sort é estável: no empate fica a ordem do servidor (etapa, nome).
    const produtos = useMemo(
        () => [...(dados.produtos ?? [])].sort((a, b) => (b.faltas?.length ?? 0) - (a.faltas?.length ?? 0)),
        [dados.produtos],
    );

    return (
        <MolduraDoPainel
            data-painel="lista"
            sobrelinha={sobrelinhaDaEtapa('lista', trabalho)}
            titulo={etapa.titulo}
            texto={etapa.faz}
            acoes={podeEditar ? <AcoesComNovoProduto chave="lista" links={links} onAbrir={onAbrir} /> : <AcoesDoPainel chave="lista" links={links} />}
        >
            <RegraDePronto chave="lista" trabalho={trabalho} />

            {!trabalho && <QuadrosDoPortal />}
            {trabalho && podeEditar && (
                <Suspense fallback={null}>
                    <QuadrosDaLista onAbrir={onAbrir} comPlanilha={comPlanilha} comMercadoLivre={comImportacao} />
                </Suspense>
            )}
            {trabalho && !podeEditar && <NotaDaLista />}

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
                                <LinhaDaPlanilha key={produto.id} produto={produto} onAbrir={onAbrir} podeEditar={podeEditar} />
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="flex flex-col items-center gap-3 px-[18px] py-[30px] text-center text-trilha-muted" data-lista-vazia="">
                    <span>Nenhum produto cadastrado ainda.</span>
                    {podeEditar && <BotaoNovoProduto onAbrir={onAbrir} />}
                </div>
            )}
        </MolduraDoPainel>
    );
}
