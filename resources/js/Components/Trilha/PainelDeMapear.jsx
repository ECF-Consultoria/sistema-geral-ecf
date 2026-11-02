import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { acharItem, chaveDaOferta, chaveDeMapear } from '@/lib/trilha';
import { carregarMapeamento, concluirMapeamento, mensagemDoErro, previaDoMapeamento } from '@/lib/trilhaEscrita';
import {
    QUANTIDADES_DO_PARCEIRO,
    alternarCombo,
    alternarJuntar,
    alternarParceiro,
    comboTravado,
    corpoDoMapeamento,
    escolhasIniciais,
    estadoDaLinha,
    maximoDeParceiros,
    mudarQuantidade,
    ofertasNovas,
    ordenarParceiros,
    podeJuntar,
    proximoParaMapear,
    quantidadeDe,
    quantosMarcados,
    textoDasCriadas,
    textoDasDescartadas,
    textoDoMapeamento,
} from '@/lib/trilhaMapeamento';
import { cn } from '@/lib/utils';
import { Botao, Caixa, CaixaDeMarcar, Etiqueta, Opcao, Pilula, SeloDoTipo, Seletor } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta } from '@/Components/Trilha/PainelLateral';
import { plural, skuDoProduto } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — o painel de mapear (`?abrir=m:{produtoId}`) ───────
//
// O `gavetaMapa()` do protótipo, ligado ao servidor (2ª entrega, 02/11/2026). Na
// ordem do artefato: o produto; "Vai ser criado"; os combos; os kits e combits
// com outros produtos (e "Juntar todos"); o "Concluir mapeamento" no rodapé.
//
// - O painel carrega as opções (GET) e pede a PRÉVIA a cada escolha, com espera
//   de 300 ms entre os cliques: quem diz o que nasce — cor por cor, com o SKU
//   do sistema, "já existe" pela composição e "não dá" com o motivo — é o
//   servidor. Resposta velha (de uma escolha que já mudou) é descartada.
// - Nada vem marcado. A sugestão do sistema só dá a ordem e a etiqueta; o combo
//   que já existe em todas as cores vem marcado e travado ("já existe").
// - "Concluir" grava tudo ou nada; o painel vira "Mapeamento concluído" e a
//   trilha recarrega. As ofertas novas ficam no Planejamento, "Sem onda".
//
// Só para a equipe com escrita: a página não abre este painel para o cliente
// nem no "Ver como: Cliente" (lá o `m:` cai no painel do produto).

/** A espera entre a última escolha e o pedido da prévia. */
export const ESPERA_DA_PREVIA = 300;

const LINHA_MARCAVEL = 'grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-1 rounded-[10px] border border-trilha-line px-2.5 py-2 text-[13px]';
// No celular a dica de SKU (combos e "Juntar todos") desce para baixo do texto, em vez de apertá-lo.
const LINHA_COM_DICA = 'max-[480px]:grid-cols-[auto_minmax(0,1fr)]';
const DICA_DE_SKU = 'font-pub-mono text-[12px] text-trilha-fg2 [overflow-wrap:anywhere] max-[480px]:col-start-2';

/** "As cores não casam…" → "as cores não casam…" (vem depois de "Não dá:"). */
const minusculaNoComeco = (texto) => (texto ? texto.charAt(0).toLowerCase() + texto.slice(1) : texto);
const LINHA_MARCADA = 'border-trilha-ok bg-trilha-ok-bg';
const LINHA_DA_LISTA = 'grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-2.5 gap-y-1 border-b border-trilha-line2 py-2 text-[13px] last:border-b-0';

/**
 * @param {{
 *   produto: object,
 *   dados: {produtos: Array, ofertas: Array, ondas?: Array},
 *   escrita: {produtos: string},
 *   onAbrir: (chave: string) => void,
 *   onAvisar?: (texto: string, tipo?: 'sucesso'|'erro', extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 *   onVerPlanejamento?: () => void,
 * }} props  `produto` é o da trilha (as props da página); o do painel vem do servidor.
 */
export default function PainelDeMapear({ produto, dados, escrita, onAbrir, onAvisar = () => {}, onRecarregar = async () => {}, onVerPlanejamento = () => {} }) {
    const [opcoes, setOpcoes] = useState(null);
    const [erroAoCarregar, setErroAoCarregar] = useState(null);
    const [tentativa, setTentativa] = useState(0);
    const [escolhas, setEscolhas] = useState(() => escolhasIniciais(null));
    const [previa, setPrevia] = useState(null);
    const [pendente, setPendente] = useState(false);
    const [erroDaPrevia, setErroDaPrevia] = useState(null);
    // "Calcular de novo" depois de uma prévia que falhou (rede, sessão): pede a mesma escolha outra vez.
    const [refazer, setRefazer] = useState(0);
    const [concluindo, setConcluindo] = useState(false);
    const [erroAoConcluir, setErroAoConcluir] = useState(null);
    const [concluido, setConcluido] = useState(null);
    const tituloDoResultado = useRef(null);
    const pedido = useRef(0);
    const jaPediu = useRef(false);

    // 1. As opções do painel (e, com elas, o começo das escolhas).
    useEffect(() => {
        let vivo = true;
        setOpcoes(null);
        setErroAoCarregar(null);
        // Opções novas: a primeira prévia delas sai na hora.
        jaPediu.current = false;
        carregarMapeamento(escrita, produto.id)
            .then((r) => {
                if (!vivo) return;
                setOpcoes(r);
                setEscolhas(escolhasIniciais(r));
            })
            .catch((e) => {
                if (vivo) setErroAoCarregar(mensagemDoErro(e));
            });

        return () => {
            vivo = false;
        };
    }, [produto.id, tentativa]);

    // 2. A prévia: a primeira na hora; depois, 300 ms depois da última escolha. Cada pedido tem número, e só a
    // resposta do último vale (a de uma escolha que já mudou chegaria fora de ordem).
    const corpo = useMemo(() => (opcoes ? corpoDoMapeamento(escolhas, opcoes) : null), [escolhas, opcoes]);
    const chaveDoCorpo = corpo ? JSON.stringify(corpo) : null;
    useEffect(() => {
        if (!chaveDoCorpo || concluido) return undefined;
        const meu = ++pedido.current;
        setPendente(true);
        const espera = jaPediu.current ? ESPERA_DA_PREVIA : 0;
        jaPediu.current = true;
        const relogio = setTimeout(() => {
            previaDoMapeamento(escrita, produto.id, JSON.parse(chaveDoCorpo))
                .then((r) => {
                    if (meu !== pedido.current) return;
                    setPrevia(r);
                    setErroDaPrevia(null);
                })
                .catch((e) => {
                    if (meu === pedido.current) setErroDaPrevia(mensagemDoErro(e));
                })
                .finally(() => {
                    if (meu === pedido.current) setPendente(false);
                });
        }, espera);

        return () => clearTimeout(relogio);
    }, [chaveDoCorpo, refazer]);

    // Concluído: o foco vai para o resultado (o botão clicado sumiu com o formulário).
    useEffect(() => {
        if (concluido) tituloDoResultado.current?.focus();
    }, [concluido]);

    // Concluir depende da prévia em dia; durante o envio o botão fica "ocupado" (mantém o foco), não desligado.
    const podeConcluir = !!(opcoes && previa && previa.pode_concluir && !pendente && !erroDaPrevia);

    const concluir = async () => {
        if (!podeConcluir || concluindo) return;
        setConcluindo(true);
        setErroAoConcluir(null);
        try {
            const r = await concluirMapeamento(escrita, produto.id, corpo);
            setConcluido(r);
            onAvisar(r.mensagem || textoDasCriadas((r.criadas ?? []).length));
            onRecarregar();
        } catch (e) {
            const texto = mensagemDoErro(e);
            setErroAoConcluir(texto);
            onAvisar(texto, 'erro');
        } finally {
            setConcluindo(false);
        }
    };

    const doServidor = opcoes?.produto ?? {};
    const nome = doServidor.nome || produto.nome || 'Produto sem nome';
    const codigo = doServidor.codigo || skuDoProduto(produto) || 'sem SKU';

    if (concluido) {
        const proximo = proximoParaMapear(dados, produto.id);

        return (
            <MioloDaGaveta
                sobrelinha="Mapeamento concluído"
                titulo={nome}
                sub={
                    <>
                        <span className="font-pub-mono font-semibold text-trilha-fg">{codigo}</span> · mapeado agora
                    </>
                }
                rodape={[
                    proximo && (
                        <Botao key="continuar" data-acao="continuar-mapeando" onClick={() => onAbrir(chaveDeMapear(proximo))}>
                            Continuar mapeando
                        </Botao>
                    ),
                    <Botao key="planejamento" primario data-acao="ver-no-planejamento" onClick={onVerPlanejamento}>
                        Ver no Planejamento
                    </Botao>,
                ].filter(Boolean)}
            >
                <ResultadoDoMapeamento resultado={concluido} dados={dados} onAbrir={onAbrir} tituloRef={tituloDoResultado} />
            </MioloDaGaveta>
        );
    }

    let rodape = null;
    if (opcoes) {
        const bloqueio = previa && !previa.pode_concluir ? previa.mensagem || 'Uma das combinações não dá: confira “Vai ser criado”.' : null;
        rodape = [
            erroAoConcluir ? (
                <span key="dica" role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-bad">
                    {erroAoConcluir}
                </span>
            ) : (
                <span key="dica" className={cn('mr-auto min-w-0 self-center text-[12.5px]', bloqueio ? 'font-semibold text-trilha-bad' : 'text-trilha-muted')}>
                    {bloqueio ?? 'As ofertas vão para o Planejamento.'}
                </span>
            ),
            <Botao key="concluir" primario disabled={!podeConcluir} ocupado={concluindo} onClick={concluir} data-acao="concluir-mapeamento">
                {concluindo ? 'Concluindo…' : 'Concluir mapeamento'}
            </Botao>,
        ];
    }

    return (
        <MioloDaGaveta
            sobrelinha="Mapeamento estrutural"
            titulo={nome}
            sub={
                <>
                    <span className="font-pub-mono font-semibold text-trilha-fg">{codigo}</span> · as escolhas aparecem em “Vai ser criado”
                </>
            }
            rodape={rodape}
            ocupado={!opcoes && !erroAoCarregar}
        >
            {erroAoCarregar && (
                <div className="flex flex-col items-start gap-2 rounded-xl border border-trilha-bad/40 bg-trilha-bad-bg px-[14px] py-3" data-erro-do-mapeamento="">
                    <p role="alert" className="m-0 text-[13px] font-semibold text-trilha-bad">
                        {erroAoCarregar}
                    </p>
                    <Botao onClick={() => setTentativa((n) => n + 1)}>Tentar de novo</Botao>
                </div>
            )}
            {!opcoes && !erroAoCarregar && (
                <p className="m-0 text-[13px] text-trilha-muted" data-carregando-mapeamento="">
                    Carregando o produto e as opções de combinação…
                </p>
            )}
            {opcoes && (
                <CorpoDoMapeamento
                    opcoes={opcoes}
                    previa={previa}
                    pendente={pendente}
                    erroDaPrevia={erroDaPrevia}
                    escolhas={escolhas}
                    travado={concluindo}
                    onRefazer={() => setRefazer((n) => n + 1)}
                    aoMudar={{
                        combo: (q, ligado) => setEscolhas((e) => alternarCombo(e, opcoes, q, ligado)),
                        parceiro: (id, ligado) => setEscolhas((e) => alternarParceiro(e, opcoes, id, ligado)),
                        quantidade: (id, q) => setEscolhas((e) => mudarQuantidade(e, opcoes, id, q)),
                        juntar: (ligado) => setEscolhas((e) => alternarJuntar(e, opcoes, ligado)),
                    }}
                />
            )}
        </MioloDaGaveta>
    );
}

/**
 * O miolo do painel com as opções carregadas: o produto, "Vai ser criado", os
 * combos e os outros produtos. Só desenha — as escolhas e a prévia chegam de
 * fora (exportado para o teste de render, que não faz pedido nenhum).
 */
export function CorpoDoMapeamento({ opcoes, previa = null, pendente = false, erroDaPrevia = null, escolhas, travado = false, aoMudar, onRefazer = null }) {
    const produto = opcoes.produto ?? {};
    const combos = opcoes.combos ?? [];
    const parceiros = ordenarParceiros(opcoes.parceiros);
    const marcados = quantosMarcados(escolhas);
    const limite = maximoDeParceiros(opcoes);
    const juntarPossivel = podeJuntar(marcados, opcoes);
    const mapeado = textoDoMapeamento(opcoes.mapeado_em, opcoes.mapeado_por);

    return (
        <>
            <ProdutoDoMapeamento produto={produto} mapeado={mapeado} />

            <PreviaDoMapeamento previa={previa} pendente={pendente} erro={erroDaPrevia} onRefazer={onRefazer} />

            <Caixa titulo="Combos: o mesmo produto em mais unidades" data-caixa="combos">
                {combos.length ? (
                    <div role="group" aria-label="Combos" className="flex flex-col gap-2">
                        {combos.map((combo) => (
                            <LinhaDeCombo
                                key={combo.quantidade}
                                combo={combo}
                                produto={produto}
                                marcado={escolhas.combos.includes(Number(combo.quantidade))}
                                travado={travado}
                                onMudar={aoMudar.combo}
                            />
                        ))}
                    </div>
                ) : (
                    <span className="text-[12.5px] text-trilha-muted">Nenhuma quantidade de combo para este produto.</span>
                )}
                <span className="text-[12.5px] text-trilha-muted">Cada cor ganha o seu combo; no Publicador as cores viram um anúncio só (o Kit N).</span>
            </Caixa>

            <Caixa titulo="Kits e combits: este produto com outros" data-caixa="parceiros">
                <span className="text-[12.5px] text-trilha-muted">
                    Com 1 unidade de cada, vira <b className="text-trilha-fg2">kit</b> (KT-SKU1-SKU2). Se algum for em mais unidades, vira{' '}
                    <b className="text-trilha-fg2">combit</b> (CT4-SKU1-SKU2). Cada par de cores que casa vira uma oferta. Não precisa esperar
                    a simples vender: os sugeridos e os do mesmo ambiente aparecem primeiro.
                </span>
                {parceiros.length ? (
                    <div role="group" aria-label="Outros produtos" className="flex flex-col gap-2">
                        {parceiros.map((parceiro) => (
                            <LinhaDeParceiro
                                key={parceiro.produto_id}
                                parceiro={parceiro}
                                marcado={!!escolhas.marcados[parceiro.produto_id]}
                                quantidade={quantidadeDe(escolhas, parceiro.produto_id)}
                                cheio={marcados >= limite}
                                travado={travado}
                                onMarcar={aoMudar.parceiro}
                                onQuantidade={aoMudar.quantidade}
                            />
                        ))}
                    </div>
                ) : (
                    <span className="text-[12.5px] text-trilha-muted">Nenhum outro produto completo na lista para combinar com este.</span>
                )}
                {marcados >= limite && parceiros.length > limite && (
                    <span className="text-[12.5px] font-semibold text-trilha-fg2" data-limite-de-parceiros="">
                        Até {limite} produtos por mapeamento: desmarque um para escolher outro.
                    </span>
                )}
                {parceiros.length >= 2 && (
                    <label
                        className={cn(LINHA_MARCAVEL, LINHA_COM_DICA, escolhas.juntar && juntarPossivel && LINHA_MARCADA, juntarPossivel && !travado ? 'cursor-pointer' : 'cursor-default')}
                        data-juntar=""
                    >
                        <CaixaDeMarcar
                            checked={!!escolhas.juntar && juntarPossivel}
                            disabled={!juntarPossivel || travado}
                            onChange={(e) => aoMudar.juntar(e.target.checked)}
                        />
                        <span className={cn('min-w-0', !juntarPossivel && 'text-trilha-muted')}>
                            <b className="font-semibold text-trilha-fg">Juntar todos numa oferta só</b>{' '}
                            <span className="text-trilha-muted">· este produto com todos os marcados acima (2 ou mais): vira kit ou combit pela regra</span>
                        </span>
                        <span className={DICA_DE_SKU}>KT- / CT-</span>
                    </label>
                )}
            </Caixa>
        </>
    );
}

function ProdutoDoMapeamento({ produto, mapeado }) {
    const cores = produto.cores ?? [];
    const ambientes = produto.ambientes ?? [];

    return (
        <Caixa titulo="O produto" data-caixa="produto">
            <div className="flex items-start gap-3">
                {produto.foto ? (
                    <img src={produto.foto} alt="" loading="lazy" className="h-14 w-14 shrink-0 rounded-lg border border-trilha-line object-cover" />
                ) : (
                    <span aria-hidden="true" className="h-14 w-14 shrink-0 rounded-lg border border-dashed border-trilha-line bg-trilha-surface2" />
                )}
                <div className="min-w-0 text-[13px]">
                    <div className="text-trilha-fg [overflow-wrap:anywhere]">
                        <span className="font-pub-mono font-semibold">{produto.codigo || 'sem código'}</span> · {produto.nome}
                    </div>
                    <div className="text-[12.5px] text-trilha-muted [overflow-wrap:anywhere]">
                        {produto.familia || 'sem família'}
                        {ambientes.length > 0 && ` · ${ambientes.join(', ')}`}
                    </div>
                    {mapeado && (
                        <div className="mt-1 text-[12.5px] font-semibold text-trilha-fg2" data-mapeado="">
                            {mapeado}. Mapear de novo só acrescenta ofertas.
                        </div>
                    )}
                </div>
            </div>
            {cores.length > 0 && (
                <ul aria-label="Cores do produto" className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2 p-0">
                    {cores.map((cor) => (
                        <li key={cor.variacao_id} className="flex min-w-0 items-center gap-2 rounded-lg border border-trilha-line px-2 py-1.5" data-cor={cor.variacao_id}>
                            {cor.foto ? (
                                <img src={cor.foto} alt="" loading="lazy" className="h-8 w-8 shrink-0 rounded-md object-cover" />
                            ) : (
                                <span aria-hidden="true" className="h-8 w-8 shrink-0 rounded-md border border-dashed border-trilha-line bg-trilha-surface2" />
                            )}
                            <span className="min-w-0 text-[12.5px]">
                                <span className="block font-semibold text-trilha-fg [overflow-wrap:anywhere]">{cor.valor || 'Cor única'}</span>
                                <span className="block font-pub-mono text-[11.5px] text-trilha-fg2 [overflow-wrap:anywhere]">{cor.sku}</span>
                                {!cor.tem_oferta && (
                                    <Etiqueta tom="warn" className="mt-0.5">
                                        sem oferta
                                    </Etiqueta>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Caixa>
    );
}

/** "Vai ser criado": uma linha por item da trilha que vai nascer (ou que já existe), com o estado de cada uma. */
function PreviaDoMapeamento({ previa, pendente, erro, onRefazer = null }) {
    const linhas = previa?.linhas ?? [];
    const novas = Number(previa?.novas) || 0;

    return (
        <Caixa
            titulo="Vai ser criado"
            extra={previa && <Pilula tom={novas ? 'ok' : 'neutro'}>{ofertasNovas(novas)}</Pilula>}
            aria-live="polite"
            aria-busy={pendente || undefined}
            data-caixa="previa"
        >
            {!previa && !erro && <span className="text-[12.5px] text-trilha-muted">Calculando o que vai ser criado…</span>}
            {linhas.length > 0 && (
                <div className="flex flex-col">
                    {linhas.map((linha, i) => (
                        <LinhaDaPrevia key={`${linha.chave}-${i}`} linha={linha} />
                    ))}
                </div>
            )}
            {erro && (
                <div className="flex flex-wrap items-center gap-2">
                    <p className="m-0 text-[12.5px] font-semibold text-trilha-bad" data-erro-da-previa="">
                        {erro}
                    </p>
                    {onRefazer && !pendente && <Botao onClick={onRefazer}>Calcular de novo</Botao>}
                </div>
            )}
            {pendente && previa && (
                <span aria-hidden="true" className="text-[12px] text-trilha-muted">
                    Atualizando…
                </span>
            )}
        </Caixa>
    );
}

function LinhaDaPrevia({ linha }) {
    const estado = estadoDaLinha(linha);
    const skus = (linha.skus ?? []).filter(Boolean);
    const naoDa = linha.estado === 'nao_da';

    return (
        <div className={LINHA_DA_LISTA} data-linha-da-previa={linha.estado}>
            <span className="min-w-0">
                <span className="block text-trilha-fg [overflow-wrap:anywhere]">
                    <SeloDoTipo tipo={linha.tipo} />
                    {linha.nome}
                </span>
                {(skus.length > 0 || linha.composicao) && (
                    <span className="block text-[12.5px] text-trilha-muted [overflow-wrap:anywhere]">
                        {skus.length > 0 && <span className="font-pub-mono font-semibold text-trilha-fg">{skus.join(' · ')}</span>}
                        {skus.length > 0 && linha.composicao && ' = '}
                        {linha.composicao}
                    </span>
                )}
                {linha.detalhe && (
                    <span className={cn('block text-[12.5px] [overflow-wrap:anywhere]', naoDa ? 'font-semibold text-trilha-bad' : 'text-trilha-muted')}>
                        {linha.detalhe}
                    </span>
                )}
                {(linha.avisos ?? []).map((aviso) => (
                    <span key={aviso} className="block text-[12.5px] text-trilha-warn [overflow-wrap:anywhere]">
                        {aviso}
                    </span>
                ))}
            </span>
            <Pilula tom={estado.tom}>{estado.rotulo}</Pilula>
        </div>
    );
}

function LinhaDeCombo({ combo, produto, marcado, travado, onMudar }) {
    const fixo = comboTravado(combo);
    const q = Number(combo.quantidade);
    const cores = produto.cores ?? [];
    // O SKU do sistema é por cor: `{SKU da simples da cor}-CB{n}`.
    const sku = cores.length === 1 && cores[0].sku ? `${cores[0].sku}-CB${q}` : `cada cor: …-CB${q}`;

    return (
        <label
            className={cn(LINHA_MARCAVEL, LINHA_COM_DICA, (marcado || fixo) && LINHA_MARCADA, fixo || travado ? 'cursor-default' : 'cursor-pointer')}
            data-combo={q}
        >
            <CaixaDeMarcar checked={marcado || fixo} disabled={fixo || travado} onChange={(e) => onMudar(q, e.target.checked)} />
            <span className="min-w-0">
                <b className="font-semibold text-trilha-fg">Combo {q}</b> <span className="text-trilha-muted">· {q} unidades deste produto</span>
                {(combo.sugerido || combo.existe === 'todas' || combo.existe === 'algumas') && (
                    <span className="mt-1 flex flex-wrap gap-1">
                        {combo.sugerido && <Etiqueta tom="roxo">sugerido</Etiqueta>}
                        {combo.existe === 'todas' && <Etiqueta tom="neutro">já existe</Etiqueta>}
                        {combo.existe === 'algumas' && <Etiqueta tom="neutro">já existe em algumas cores</Etiqueta>}
                    </span>
                )}
            </span>
            <span className={DICA_DE_SKU}>{sku}</span>
        </label>
    );
}

function LinhaDeParceiro({ parceiro, marcado, quantidade, cheio, travado, onMarcar, onQuantidade }) {
    const id = useId();
    const naoCasa = parceiro.casa === false;
    // Sem casar, ou com o limite de produtos já cheio (este fora), a linha não se marca.
    const fechada = naoCasa || travado || (cheio && !marcado);

    return (
        <div className={cn(LINHA_MARCAVEL, marcado && LINHA_MARCADA, naoCasa && 'bg-trilha-surface2')} data-parceiro={parceiro.produto_id}>
            <CaixaDeMarcar id={id} checked={marcado} disabled={fechada} onChange={(e) => onMarcar(parceiro.produto_id, e.target.checked)} />
            <label htmlFor={id} className={cn('min-w-0', fechada ? 'cursor-default' : 'cursor-pointer')}>
                <span className="flex items-center gap-2">
                    {parceiro.foto && <img src={parceiro.foto} alt="" loading="lazy" className="h-7 w-7 shrink-0 rounded-md border border-trilha-line object-cover" />}
                    <span className="min-w-0">
                        <b className={cn('block font-semibold [overflow-wrap:anywhere]', naoCasa ? 'text-trilha-muted' : 'text-trilha-fg')}>{parceiro.nome}</b>
                        {parceiro.codigo && <span className="block font-pub-mono text-[12px] text-trilha-fg2 [overflow-wrap:anywhere]">{parceiro.codigo}</span>}
                    </span>
                </span>
                {(parceiro.ambiente || parceiro.sugerido != null) && (
                    <span className="mt-1 flex flex-wrap gap-1">
                        {parceiro.ambiente && <Etiqueta>combina · {parceiro.ambiente}</Etiqueta>}
                        {parceiro.sugerido != null && <Etiqueta tom="roxo">sugerido · {parceiro.sugerido}×</Etiqueta>}
                    </span>
                )}
                {naoCasa && (
                    <span className="mt-1 block text-[12.5px] text-trilha-warn" data-motivo="">
                        Não dá: {minusculaNoComeco(parceiro.motivo) || 'as cores não casam com as deste produto.'}
                    </span>
                )}
            </label>
            <Seletor
                aria-label={`Quantidade de ${parceiro.nome}`}
                value={String(quantidade)}
                disabled={fechada}
                onChange={(e) => onQuantidade(parceiro.produto_id, Number(e.target.value))}
                className="py-1"
                envolveClassName="w-[74px]"
            >
                {QUANTIDADES_DO_PARCEIRO.map((n) => (
                    <Opcao key={n} value={String(n)}>
                        {n}×
                    </Opcao>
                ))}
            </Seletor>
        </div>
    );
}

/**
 * "Mapeamento concluído": o que o servidor criou (com o SKU de cada uma) e as
 * sugestões que foram para Descartadas. A oferta que já está na trilha abre
 * pelo clique. Exportado para o teste de render.
 */
export function ResultadoDoMapeamento({ resultado, dados, onAbrir, tituloRef = null }) {
    const criadas = resultado?.criadas ?? [];
    const descartadas = textoDasDescartadas(Number(resultado?.descartadas) || 0);

    return (
        <>
            <div className="flex flex-col gap-1.5 rounded-xl border border-trilha-ok/40 bg-trilha-ok-bg px-[14px] py-3" data-mapeamento-concluido="">
                <b ref={tituloRef} tabIndex={-1} className="text-[14px] text-trilha-ok outline-none">
                    {resultado?.mensagem || textoDasCriadas(criadas.length)}
                </b>
                {descartadas && <span className="text-[12.5px] text-trilha-fg2">{descartadas}</span>}
            </div>

            {criadas.length > 0 && (
                <Caixa titulo="Criadas" extra={<span className="text-[12.5px] font-normal text-trilha-muted">{plural(criadas.length, 'oferta', 'ofertas')}</span>} data-caixa="criadas">
                    <div className="flex flex-col">
                        {criadas.map((criada) => {
                            const naTrilha = acharItem(dados ?? {}, chaveDaOferta({ id: criada.chave }));
                            const conteudo = (
                                <>
                                    <span className="min-w-0">
                                        <span className="block text-trilha-fg [overflow-wrap:anywhere]">
                                            <SeloDoTipo tipo={criada.tipo} />
                                            {criada.nome}
                                        </span>
                                        <span className="block font-pub-mono text-[12.5px] font-semibold text-trilha-fg2 [overflow-wrap:anywhere]">
                                            {(criada.skus ?? []).join(' · ')}
                                        </span>
                                    </span>
                                    {/* Na trilha (depois da recarga), o status dela: "Sem onda · falta escolher a data". */}
                                    <span className="text-[12.5px] text-trilha-muted">{naTrilha?.item.status ?? ''}</span>
                                </>
                            );

                            return naTrilha ? (
                                <button
                                    key={criada.chave}
                                    type="button"
                                    data-abrir={naTrilha.chave}
                                    onClick={() => onAbrir(naTrilha.chave)}
                                    className={cn(LINHA_DA_LISTA, 'w-full text-left hover:bg-trilha-surface2 focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run')}
                                >
                                    {conteudo}
                                </button>
                            ) : (
                                <div key={criada.chave} className={LINHA_DA_LISTA}>
                                    {conteudo}
                                </div>
                            );
                        })}
                    </div>
                </Caixa>
            )}
        </>
    );
}
