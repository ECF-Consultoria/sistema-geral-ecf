import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from './apoio.js';

// ─── Acervo de imagens da CONTA (v28.0 Fase 198; nasceu na Fase 171, D4) ───
//
// Fonte: os ativos do acervo da conta (`acervo_imagens`) — fotos enviadas e imagens de IA
// APROVADAS. Endereçado por `{produto}` só para o servidor descobrir a CONTA; a lista é da conta
// inteira, a não ser que "só deste produto" esteja marcado. Trocar de produto relê do zero com os
// filtros limpos — nunca herda o estado do produto anterior (T-171-02-02).

const rota = criarRota('mlb.anuncios.publicador', 'produto');

export const FILTROS_INICIAIS = { origem: '', so_este_produto: false, busca: '', de: '', ate: '', ordem: 'recentes' };

const numero = (v, padrao) => (Number.isFinite(v) ? v : padrao);

/** @param {{ produtoId: number }} opcoes */
export default function useAcervoDaConta({ produtoId }) {
    const [itens, setItens] = useState([]);
    const [total, setTotal] = useState(0);
    const [pagina, setPagina] = useState(1);
    const [paginas, setPaginas] = useState(1);
    const [filtros, setFiltros] = useState(FILTROS_INICIAIS);
    const [carregando, setCarregando] = useState(true);
    const [reaproveitando, setReaproveitando] = useState(null);
    const [erro, setErro] = useState(null);
    // Só a resposta do pedido MAIS RECENTE vale: digitar na busca dispara vários pedidos.
    const ultimoPedido = useRef(0);

    const carregar = async (f = filtros, p = pagina) => {
        const pedido = ++ultimoPedido.current;
        setCarregando(true);
        setErro(null);

        const params = { pagina: p, ordem: f.ordem };
        if (f.origem) params.origem = f.origem;
        if (f.so_este_produto) params.so_este_produto = 1;
        if (f.busca.trim()) params.busca = f.busca.trim();
        if (f.de) params.de = f.de;
        if (f.ate) params.ate = f.ate;

        try {
            const { data } = await axios.get(rota('acervo.ativos.listar', produtoId, params));
            if (pedido !== ultimoPedido.current) return;
            // Nunca confia que o array vem sempre certo (REND-01/02) — formato inesperado vira lista vazia.
            setItens(Array.isArray(data?.itens) ? data.itens : []);
            setTotal(numero(data?.total, 0));
            setPagina(numero(data?.pagina, 1));
            setPaginas(numero(data?.paginas, 1));
        } catch (e) {
            if (pedido !== ultimoPedido.current) return;
            setItens([]);
            setErro(mensagemDe(e));
        } finally {
            if (pedido === ultimoPedido.current) setCarregando(false);
        }
    };

    /** Muda um filtro e volta para a página 1. */
    const filtrar = (mudanca) => {
        const novos = { ...filtros, ...mudanca };
        setFiltros(novos);
        carregar(novos, 1);
    };

    const limparFiltros = () => {
        setFiltros(FILTROS_INICIAIS);
        carregar(FILTROS_INICIAIS, 1);
    };

    const irParaPagina = (p) => carregar(filtros, Math.min(Math.max(1, p), paginas));

    /** Usa a imagem `ativoId` no grupo `grupo` do anúncio atual — o mesmo arquivo, sem cópia nem geração. */
    const reaproveitar = async (ativoId, grupo) => {
        setReaproveitando(ativoId);
        setErro(null);

        try {
            const { data } = await axios.post(rota('acervo.ativos.usar', produtoId, { ativo: ativoId }), { grupo });

            return data;
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));

            return null;
        } finally {
            setReaproveitando(null);
        }
    };

    const limparErro = () => setErro(null);

    useEffect(() => {
        setFiltros(FILTROS_INICIAIS);
        carregar(FILTROS_INICIAIS, 1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [produtoId]);

    return { itens, total, pagina, paginas, filtros, carregando, reaproveitando, erro, carregar, filtrar, limparFiltros, irParaPagina, reaproveitar, limparErro };
}
