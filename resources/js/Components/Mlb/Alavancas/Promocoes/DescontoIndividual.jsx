import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { CAMPO } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { useLeitura } from '../useAlavancas';
import { fmtBRL, fmtData, fmtPct, hojeSP, lerNumero, somarDias } from '../formato';
import SeletorDeProdutos from '../SeletorDeProdutos';
import ModalConfirmacao from '../ModalConfirmacao';
import TabelaAnalise from '../TabelaAnalise';

/** Entrada pt-BR ("1.500" é mil e quinhentos): a leitura única fica em `lerNumero`. */
const numero = (texto) => lerNumero(texto, { positivo: true });

const paraCampo = (n) => (n === null || n === undefined ? '' : String(n).replace('.', ','));

/**
 * Uma linha por produto escolhido: desconto atual, faixa sugerida e os campos de preço.
 * O que a pessoa digitou sobe por `onLinha` (preço e preço do Mercado Pontos já em número).
 */
function LinhaDoProduto({ conta, produto, liberada, motivo, onLinha, onRemover }) {
    const { dados, erro, carregando } = useLeitura('produtos.promocoes', conta, { item: produto.id });
    const entradas = dados?.itens ?? dados?.promocoes ?? [];
    const atual = entradas.find((e) => e.tipo === 'PRICE_DISCOUNT' && (e.status === 'started' || e.status === 'pending'));
    const candidato = entradas.find((e) => e.tipo === 'PRICE_DISCOUNT' && e.status === 'candidate');

    const [preco, setPreco] = useState('');
    const [pontos, setPontos] = useState('');
    const [mexeu, setMexeu] = useState(false);

    // Pré-preenche com o preço sugerido enquanto a pessoa não digitou nada.
    useEffect(() => {
        if (! mexeu && candidato?.preco_sugerido !== undefined && candidato?.preco_sugerido !== null) setPreco(paraCampo(candidato.preco_sugerido));
    }, [candidato?.preco_sugerido, mexeu]);

    useEffect(() => {
        onLinha(produto.id, { preco: numero(preco), pontos: numero(pontos) });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preco, pontos]);

    const valor = numero(preco);
    const percentual = valor !== null && produto.preco ? (1 - valor / Number(produto.preco)) * 100 : null;

    return (
        <li className="space-y-3 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
            <div className="space-y-1">
                <p className="font-bold text-white/90">{produto.titulo ?? produto.id}</p>
                <p className="text-white/55">{produto.id} · preço do anúncio {fmtBRL(produto.preco)}</p>
                {carregando && <p className="text-white/55">Lendo as promoções do produto…</p>}
                {erro && <p className="text-white/55">{erro}</p>}
                {atual && (
                    <p>
                        Desconto atual: {fmtBRL(atual.preco)}
                        {atual.inicio ? ` · de ${fmtData(atual.inicio)}` : ''}{atual.fim ? ` até ${fmtData(atual.fim)}` : ''}
                    </p>
                )}
                {candidato && candidato.preco_sugerido !== null && candidato.preco_sugerido !== undefined && (
                    <p>
                        Sugerido {fmtBRL(candidato.preco_sugerido)}
                        {candidato.min_preco !== null && candidato.min_preco !== undefined ? ` (de ${fmtBRL(candidato.min_preco)} a ${fmtBRL(candidato.max_preco)})` : ''}
                    </p>
                )}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <label className="block w-40">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Preço com desconto</span>
                    <input
                        type="text"
                        inputMode="decimal"
                        value={preco}
                        onChange={(ev) => { setMexeu(true); setPreco(ev.target.value); }}
                        className={cn(CAMPO, 'h-10')}
                    />
                </label>
                <span className="pb-2 text-white/55">{percentual === null ? '' : `desconto de ${fmtPct(percentual)}`}</span>
                <label className="block w-48">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Preço para Mercado Pontos 3–6 (opcional)</span>
                    <input
                        type="text"
                        inputMode="decimal"
                        value={pontos}
                        onChange={(ev) => setPontos(ev.target.value)}
                        className={cn(CAMPO, 'h-10')}
                    />
                </label>
                {atual && (
                    <BotaoAcao
                        disabled={! liberada}
                        title={liberada ? undefined : motivo}
                        onClick={() => onRemover(produto)}
                    >
                        Remover desconto
                    </BotaoAcao>
                )}
            </div>
        </li>
    );
}

/** Desconto individual (PRICE_DISCOUNT): escolher produtos, informar preço e datas, conferir e confirmar. */
export default function DescontoIndividual({ conta, liberada, motivo, limites }) {
    const hoje = hojeSP();
    const [produtos, setProdutos] = useState([]);
    const [linhas, setLinhas] = useState({});
    const [inicio, setInicio] = useState(hoje);
    const [fim, setFim] = useState(somarDias(hoje, 13));
    const [alvo, setAlvo] = useState(null);
    const [versao, setVersao] = useState(0);

    const porLote = limites?.itens_por_lote ?? 50;
    const porAnalise = limites?.itens_por_analise ?? 10;

    const datasOk = inicio >= hoje && fim >= inicio && fim <= somarDias(inicio, 13);
    const prontos = produtos.filter((p) => linhas[p.id]?.preco);
    const pedidos = prontos.map((p) => ({ item_id: p.id, preco_promocao: linhas[p.id].preco, promotion_type: 'PRICE_DISCOUNT' }));

    function aoMudarInicio(valor) {
        setInicio(valor);
        // Mantém o fim dentro dos 14 dias contados do novo início.
        if (valor && (fim < valor || fim > somarDias(valor, 13))) setFim(somarDias(valor, 13));
    }

    function aoConcluir() {
        setVersao((n) => n + 1);
    }

    return (
        <div className="space-y-4 border-t border-white/[0.06] px-3 py-4">
            <p className="text-[13px] font-normal text-white/55">
                Desconto de 5% a menos de 80%, por até 14 dias. O preço do Mercado Pontos precisa dar pelo menos 5 pontos
                percentuais a mais de desconto (10 acima de 35%).
            </p>
            <p className="text-[13px] font-normal text-white/55">Aumentar o preço do anúncio depois remove o desconto.</p>

            <SeletorDeProdutos conta={conta} soElegiveis maximo={porLote} selecionados={produtos} onMudar={setProdutos} />

            {produtos.length > 0 && (
                <div className="space-y-4 border-t border-white/[0.06] pt-4">
                    <div className="flex flex-wrap gap-3">
                        <label className="block w-44">
                            <span className="mb-1 block text-[11px] font-normal text-white/55">Início</span>
                            <input type="date" value={inicio} min={hoje} onChange={(ev) => aoMudarInicio(ev.target.value)} className={cn(CAMPO, 'h-10')} />
                        </label>
                        <label className="block w-44">
                            <span className="mb-1 block text-[11px] font-normal text-white/55">Fim</span>
                            <input type="date" value={fim} min={inicio} max={inicio ? somarDias(inicio, 13) : undefined} onChange={(ev) => setFim(ev.target.value)} className={cn(CAMPO, 'h-10')} />
                        </label>
                    </div>

                    <ul className="space-y-2">
                        {produtos.map((p) => (
                            <LinhaDoProduto
                                key={`${p.id}-${versao}`}
                                conta={conta}
                                produto={p}
                                liberada={liberada}
                                motivo={motivo}
                                onLinha={(id, v) => setLinhas((l) => ({ ...l, [id]: v }))}
                                onRemover={(produto) => setAlvo({
                                    acao: 'desconto.remover',
                                    titulo: 'Remover desconto individual',
                                    itens: [{ item_id: produto.id }],
                                })}
                            />
                        ))}
                    </ul>

                    {pedidos.length > 0 && <TabelaAnalise conta={conta} pedidos={pedidos} limite={porAnalise} />}

                    <BotaoAcao
                        primario
                        disabled={! liberada || prontos.length === 0 || ! datasOk}
                        title={liberada ? undefined : motivo}
                        onClick={() => setAlvo({
                            acao: 'desconto.criar',
                            titulo: 'Criar desconto individual',
                            itens: prontos.map((p) => {
                                const item = {
                                    item_id: p.id,
                                    deal_price: linhas[p.id].preco,
                                    start_date: inicio,
                                    finish_date: fim,
                                };
                                if (linhas[p.id].pontos) item.top_deal_price = linhas[p.id].pontos;

                                return item;
                            }),
                        })}
                    >
                        Revisar e criar desconto
                    </BotaoAcao>
                    {! datasOk && <p className="text-[13px] font-normal text-white/55">Confira as datas: o início não pode ser passado e o desconto dura no máximo 14 dias.</p>}
                </div>
            )}

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao={alvo.acao}
                    itens={alvo.itens}
                    titulo={alvo.titulo}
                    onFechar={() => setAlvo(null)}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
