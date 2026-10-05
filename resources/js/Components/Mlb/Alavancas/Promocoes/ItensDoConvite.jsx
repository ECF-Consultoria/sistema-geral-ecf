import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { CAMPO } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { useLeitura } from '../useAlavancas';
import { ROTULO_STATUS_PROMOCAO } from '../rotulos';
import { fmtBRL } from '../formato';
import ModalConfirmacao from '../ModalConfirmacao';
import TabelaAnalise from '../TabelaAnalise';

// DOD e LIGHTNING só saem enquanto programados; os programados do vendedor também precisam aparecer.
const FILTROS = [
    { status: 'candidate', rotulo: 'Candidatos' },
    { status: 'pending', rotulo: 'Programados' },
    { status: 'started', rotulo: 'Ativos' },
];

/** Aceita "12,50" e "12.5"; vazio ou inválido vira null. */
const numero = (texto) => {
    const bruto = String(texto ?? '').trim();
    if (bruto === '') return null;
    // Com vírgula, o ponto é separador de milhar; sem vírgula, o ponto é o decimal.
    const n = Number(bruto.includes(',') ? bruto.split('.').join('').replace(',', '.') : bruto);

    return Number.isFinite(n) && n > 0 ? n : null;
};

/** O texto do campo de preço de uma linha: o sugerido ou, na falta, o preço da promoção. */
const valorInicial = (l) => String(l.preco_sugerido ?? l.preco ?? '').replace('.', ',');

/**
 * Itens de um convite do Mercado Livre, por situação, com paginação por cursor.
 * A capacidade de cada linha (preço, estoque, alterar, tirar) vem do servidor; a tela só obedece.
 */
export default function ItensDoConvite({ conta, convite, liberada, motivo, limites }) {
    const [status, setStatus] = useState('candidate');
    const [cursor, setCursor] = useState(null);
    const [linhas, setLinhas] = useState([]);
    const [precos, setPrecos] = useState({});
    const [estoques, setEstoques] = useState({});
    const [marcados, setMarcados] = useState([]);
    const [alvo, setAlvo] = useState(null);

    const { dados, erro, carregando, recarregar } = useLeitura('promocoes.itens', conta, {
        promocao: convite.id, tipo: convite.tipo, status, cursor,
    });

    const porLote = limites?.itens_por_lote ?? 50;
    const porAnalise = limites?.itens_por_analise ?? 10;

    // Acumula as páginas: sem cursor substitui, com cursor acrescenta (sem repetir produto).
    useEffect(() => {
        if (! dados) return;
        setLinhas((antes) => {
            if (cursor === null) return dados.itens ?? [];
            const vistos = new Set(antes.map((l) => l.item_id));

            return [...antes, ...(dados.itens ?? []).filter((l) => ! vistos.has(l.item_id))];
        });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dados]);

    function trocarFiltro(novo) {
        setStatus(novo);
        setCursor(null);
        setLinhas([]);
        setMarcados([]);
    }

    function marcar(id) {
        setMarcados((m) => (m.includes(id) ? m.filter((x) => x !== id) : (m.length >= porLote ? m : [...m, id])));
    }

    const precoDe = (l) => precos[l.item_id] ?? valorInicial(l);
    const estoqueDe = (l) => estoques[l.item_id] ?? String(l.estoque_min ?? '');

    function itemDeInscricao(l) {
        const item = { item_id: l.item_id, promotion_id: convite.id, promotion_type: convite.tipo };
        // O preço só vai quando o tipo aceita; nos demais o Mercado Livre define.
        if (l.capacidades.preco) item.deal_price = numero(precoDe(l));
        if (l.capacidades.pede_estoque && numero(estoqueDe(l)) !== null) item.stock = Math.floor(numero(estoqueDe(l)));

        return item;
    }

    const selecionadas = linhas.filter((l) => marcados.includes(l.item_id));
    const pedidos = selecionadas.map((l) => ({
        item_id: l.item_id,
        preco_promocao: numero(precoDe(l)),
        promotion_type: convite.tipo,
        meli_percentage: l.meli_percentage,
        seller_percentage: l.seller_percentage,
        boost: l.boost,
        estoque_minimo: l.estoque_min,
    }));

    function aoConcluir() {
        setMarcados([]);
        if (cursor === null) recarregar(); else setCursor(null);
    }

    return (
        <div className="space-y-4 border-t border-white/[0.06] px-3 py-4">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Situação dos produtos">
                {FILTROS.map((f) => (
                    <button
                        key={f.status}
                        type="button"
                        aria-pressed={status === f.status}
                        onClick={() => trocarFiltro(f.status)}
                        className={cn(
                            'inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                            status === f.status
                                ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
                        )}
                    >
                        {f.rotulo}
                    </button>
                ))}
            </div>

            {dados?.reiniciado && (
                <p className="text-[13px] font-normal text-white/55">A lista foi recarregada: o Mercado Livre expira a paginação em 5 minutos.</p>
            )}
            {erro && <p className="text-[13px] font-normal text-white/55">{erro}</p>}
            {carregando && linhas.length === 0 && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {! carregando && ! erro && linhas.length === 0 && (
                <p className="text-[13px] font-normal text-white/55">Nenhum produto nesta situação.</p>
            )}

            {linhas.length > 0 && (
                <ul className="space-y-2">
                    {linhas.map((l) => {
                        const noAr = status === 'pending' || status === 'started';

                        return (
                            <li key={l.item_id} className="flex flex-wrap items-start gap-3 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
                                {l.capacidades.inscrever && (
                                    <input
                                        type="checkbox"
                                        aria-label={`Selecionar ${l.item_id}`}
                                        checked={marcados.includes(l.item_id)}
                                        disabled={! liberada}
                                        onChange={() => marcar(l.item_id)}
                                        className="mt-1 h-4 w-4"
                                    />
                                )}
                                <div className="min-w-[220px] flex-1 space-y-1">
                                    <p className="font-bold text-white/90">{l.titulo ?? l.item_id}</p>
                                    <p className="text-white/55">{l.item_id} · {ROTULO_STATUS_PROMOCAO[l.status] ?? l.status}</p>
                                    <p>Preço atual {fmtBRL(l.preco_atual ?? l.preco)}</p>
                                    {l.preco_sugerido !== null && l.preco_sugerido !== undefined && (
                                        <p>Sugerido {fmtBRL(l.preco_sugerido)} (de {fmtBRL(l.min_preco)} a {fmtBRL(l.max_preco)})</p>
                                    )}
                                    {l.capacidades.motivo && <p className="text-white/55">{l.capacidades.motivo}</p>}
                                </div>

                                {(l.capacidades.preco || l.capacidades.alterar) && (
                                    <label className="block w-32">
                                        <span className="mb-1 block text-[11px] font-normal text-white/55">Preço na promoção</span>
                                        <input
                                            type="text"
                                            inputMode="decimal"
                                            value={precoDe(l)}
                                            onChange={(ev) => setPrecos((p) => ({ ...p, [l.item_id]: ev.target.value }))}
                                            className={cn(CAMPO, 'h-10')}
                                        />
                                    </label>
                                )}
                                {l.capacidades.pede_estoque && (
                                    <label className="block w-32">
                                        <span className="mb-1 block text-[11px] font-normal text-white/55">
                                            Estoque ({l.estoque_min ?? '—'} a {l.estoque_max ?? '—'})
                                        </span>
                                        <input
                                            type="text"
                                            inputMode="numeric"
                                            value={estoqueDe(l)}
                                            onChange={(ev) => setEstoques((p) => ({ ...p, [l.item_id]: ev.target.value }))}
                                            className={cn(CAMPO, 'h-10')}
                                        />
                                    </label>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    {l.capacidades.alterar && (
                                        <BotaoAcao
                                            disabled={! liberada || numero(precoDe(l)) === null}
                                            title={liberada ? undefined : motivo}
                                            onClick={() => setAlvo({
                                                acao: 'convite.alterar',
                                                titulo: 'Alterar preço na promoção',
                                                itens: [{ item_id: l.item_id, promotion_id: convite.id, promotion_type: convite.tipo, deal_price: numero(precoDe(l)) }],
                                            })}
                                        >
                                            Alterar preço
                                        </BotaoAcao>
                                    )}
                                    {noAr && (
                                        <BotaoAcao
                                            disabled={! liberada || ! l.capacidades.remover}
                                            title={! liberada ? motivo : (l.capacidades.remover ? undefined : l.capacidades.motivo)}
                                            onClick={() => setAlvo({
                                                acao: 'convite.remover',
                                                titulo: 'Tirar da promoção',
                                                itens: [{ item_id: l.item_id, promotion_id: convite.id, promotion_type: convite.tipo }],
                                            })}
                                        >
                                            Tirar
                                        </BotaoAcao>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {dados?.proximo && (
                <BotaoAcao disabled={carregando} onClick={() => setCursor(dados.proximo)}>Carregar mais</BotaoAcao>
            )}

            {selecionadas.length > 0 && (
                <div className="space-y-3 border-t border-white/[0.06] pt-4">
                    <p className="text-[13px] font-normal text-white/55">
                        Selecionados: {selecionadas.length}. O máximo por vez é {porLote} produtos.
                    </p>
                    <TabelaAnalise conta={conta} pedidos={pedidos} limite={porAnalise} />
                    <BotaoAcao
                        primario
                        disabled={! liberada}
                        title={liberada ? undefined : motivo}
                        onClick={() => setAlvo({
                            acao: 'convite.inscrever',
                            titulo: 'Inscrever na promoção',
                            itens: selecionadas.map(itemDeInscricao),
                        })}
                    >
                        Revisar e inscrever
                    </BotaoAcao>
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
