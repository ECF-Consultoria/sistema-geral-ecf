import { useEffect, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from './apoio.js';

// ─── Acervo navegável das imagens já geradas da CONTA (Fase 171, D4, ACERVO-01..05) ───
//
// Endereçado por `{produto}` só para o servidor descobrir a CONTA (mesmo padrão de
// `useIdentidadeDaConta.js`) — a lista é sempre escopada por conta, nunca por este
// produto sozinho (a não ser que "ver de toda a conta" esteja desmarcado). Trocar de
// produto (mesma conta ou outra) sempre relê do zero com "toda a conta" desmarcado —
// nunca herda o estado visual do produto anterior (T-171-02-02).

const rota = criarRota('mlb.anuncios.publicador', 'produto');

/** @param {{ produtoId: number }} opcoes */
export default function useAcervoDaConta({ produtoId }) {
    const [itens, setItens] = useState([]);
    const [total, setTotal] = useState(0);
    const [limite, setLimite] = useState(0);
    const [todaConta, setTodaConta] = useState(false);
    const [carregando, setCarregando] = useState(true);
    const [reaproveitando, setReaproveitando] = useState(null);
    const [erro, setErro] = useState(null);

    const carregar = async (toda = todaConta) => {
        setCarregando(true);
        setErro(null);

        try {
            const { data } = await axios.get(rota('acervo.listar', produtoId, { toda_conta: toda ? 1 : 0 }));
            // Nunca confia que o array vem sempre certo (REND-01/02) — formato inesperado vira lista vazia.
            setItens(Array.isArray(data?.itens) ? data.itens : []);
            setTotal(Number.isFinite(data?.total) ? data.total : 0);
            setLimite(Number.isFinite(data?.limite) ? data.limite : 0);
        } catch (e) {
            setItens([]);
            setErro(mensagemDe(e));
        } finally {
            setCarregando(false);
        }
    };

    const alternarTodaConta = () => {
        const novoValor = ! todaConta;
        setTodaConta(novoValor);
        carregar(novoValor);
    };

    /** Copia a imagem de `criativoId` para o grupo `grupo` do anúncio atual — nunca gera nada novo. */
    const reaproveitar = async (criativoId, grupo) => {
        setReaproveitando(criativoId);
        setErro(null);

        try {
            const { data } = await axios.post(rota('acervo.usar', produtoId, { criativo: criativoId }), { grupo });

            return data;
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));

            return null;
        } finally {
            setReaproveitando(null);
        }
    };

    const limparErro = () => setErro(null);

    // Troca de produto (mesma conta ou outra) sempre volta para "só este produto" e relê.
    useEffect(() => {
        setTodaConta(false);
        carregar(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [produtoId]);

    return { itens, total, limite, todaConta, carregando, reaproveitando, erro, carregar, alternarTodaConta, reaproveitar, limparErro };
}
