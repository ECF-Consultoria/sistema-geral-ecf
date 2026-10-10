import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import {
    ROTULO_FASE_MONTADA, erroDaExclusao, frasesDaExclusao, listaMudou, nomesParaMostrar, textoDoBotaoExcluir, tituloDaExclusao,
} from '@/lib/exclusaoDeProdutos';

// ─── Excluir produtos inteiros (10/10/2026) ─────────────────────────────────
//
// Um produto (menu ⋮ do cartão ou a ficha) ou vários (a seleção da lista). Ao abrir, a janela
// pede ao servidor o que sai junto — as variações e as ofertas montadas (combo, kit, combit)
// que usam o produto — e só então oferece confirmar. A confirmação leva de volta as montadas
// que a pessoa VIU: se apareceu outra no meio do caminho, o servidor recusa e a janela mostra
// a lista de agora. Nada é decidido aqui.

/** O miolo da confirmação, desenhado a partir da prévia do servidor. */
export function ConfirmacaoDaExclusao({ previa }) {
    const frases = frasesDaExclusao(previa);
    const { nomes, resto } = nomesParaMostrar(previa);
    const montadas = previa.montadas ?? [];

    return (
        <div className="space-y-3" data-confirmacao-exclusao>
            {previa.produtos.length > 1 && (
                <ul className="list-disc space-y-0.5 pl-5 text-white/85" data-produtos-a-excluir>
                    {nomes.map((nome, k) => <li key={`${nome}-${k}`}>{nome}</li>)}
                    {resto > 0 && <li className="list-none text-white/55">e mais {resto}</li>}
                </ul>
            )}
            <p>{frases.variacoes}</p>
            {frases.montadas && (
                <div data-montadas-que-saem>
                    <p>{frases.montadas}</p>
                    <ul className="mt-1.5 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-white/[0.07] bg-white/[0.02] p-2">
                        {montadas.map((m) => (
                            <li key={m.id} className="flex items-baseline gap-2 text-[12.5px]">
                                <span className="shrink-0 rounded border border-white/[0.12] px-1.5 text-[11px] uppercase tracking-wide text-white/60">{ROTULO_FASE_MONTADA[m.fase] ?? m.fase}</span>
                                <span className="shrink-0 font-mono text-white/85">{m.sku}</span>
                                {m.nome && <span className="min-w-0 truncate text-white/50">{m.nome}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {frases.emUso && (
                <p className="flex items-start gap-2 rounded-lg bg-amber-500/[0.08] px-3 py-2 text-amber-200" data-em-uso>
                    <AlertTriangle size={15} className="mt-0.5 shrink-0" aria-hidden="true" />
                    <span>{frases.emUso}</span>
                </p>
            )}
            {frases.naoEncontrados && <p className="text-white/50" data-nao-encontrados>{frases.naoEncontrados}</p>}
        </div>
    );
}

/**
 * @param ids          ids dos produtos a excluir (a janela fica fechada com a lista vazia)
 * @param onExcluidos  recebe a resposta do servidor (`ids`, `produtos`, `montadas`, `mensagem`)
 */
export default function JanelaExcluirProdutos({ ids = [], aberta, onFechar, onExcluidos }) {
    const [previa, setPrevia] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);
    const [mudou, setMudou] = useState(false);
    const pedido = useRef(0);
    const chave = ids.join(',');

    const carregar = async () => {
        const id = ++pedido.current;
        setCarregando(true);
        setErro(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.exclusao.previa'), { produtos: ids });
            if (id === pedido.current) setPrevia(data);
        } catch (e) {
            if (id === pedido.current) setErro(erroDaExclusao(e));
        } finally {
            if (id === pedido.current) setCarregando(false);
        }
    };

    useEffect(() => {
        if (! aberta || ids.length === 0) return;
        setPrevia(null); setMudou(false); setEnviando(false);
        carregar();
    }, [aberta, chave]); // eslint-disable-line react-hooks/exhaustive-deps

    const excluir = async () => {
        if (! previa || enviando) return;
        setEnviando(true);
        setErro(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.exclusao'), {
                produtos: previa.produtos.map((p) => p.id),
                montadas: (previa.montadas ?? []).map((m) => m.id),
            });
            onExcluidos(data);
        } catch (e) {
            if (listaMudou(e)) {
                // Apareceu oferta montada nova entre a prévia e o clique: mostra a lista de agora.
                setMudou(true);
                await carregar();
            } else {
                setErro(erroDaExclusao(e));
            }
            setEnviando(false);
        }
    };

    const vazia = previa !== null && previa.produtos.length === 0;

    return (
        <Janela aberta={aberta && ids.length > 0} onFechar={onFechar} largura="max-w-lg"
            titulo={previa && ! vazia ? tituloDaExclusao(previa) : 'Excluir produtos'}>
            <div className="space-y-3 text-[13px] leading-relaxed text-white/70" data-janela-excluir-produtos>
                {carregando && ! previa && (
                    <p className="flex items-center gap-2 py-4 text-white/55" data-carregando-previa>
                        <Loader2 size={15} className="animate-spin" aria-hidden="true" /> Conferindo o que sai junto…
                    </p>
                )}
                {mudou && <p role="status" className="text-amber-200" data-lista-mudou>O que sai junto mudou enquanto a janela estava aberta. Confira de novo.</p>}
                {vazia && <p data-nada-a-excluir>Estes produtos não existem mais. Atualize a página.</p>}
                {previa && ! vazia && <ConfirmacaoDaExclusao previa={previa} />}
                {erro && <p role="alert" className="text-red-300" data-erro-exclusao>{erro}</p>}
                <div className="flex justify-end gap-2 pt-1">
                    {previa && ! vazia ? (
                        <>
                            <Botao variante="fantasma" onClick={onFechar} disabled={enviando} data-acao="manter-produtos">Manter</Botao>
                            <Botao variante="perigo" onClick={excluir} disabled={enviando || carregando} data-acao="confirmar-exclusao-produtos">
                                {enviando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                                {enviando ? 'Excluindo…' : textoDoBotaoExcluir(previa)}
                            </Botao>
                        </>
                    ) : (
                        <Botao onClick={onFechar} data-acao="fechar-exclusao">Fechar</Botao>
                    )}
                </div>
            </div>
        </Janela>
    );
}
