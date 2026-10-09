import { useEffect, useState } from 'react';
import axios from 'axios';

// ─── Identidade visual por CONTA (Fase 173, plano 07) ───────────────────────
//
// Hook NOVO e paralelo ao hook de identidade por produto (que fica na pasta
// do editor, território do outro desenvolvedor) — fala com as rotas por
// CONTA da Fase 173-01 (`mlb.anuncios.publicador.conta.identidade.mostrar/
// salvar`). Mesma forma de estado do hook por produto (texto, carregando,
// salvando, erro, salvar, limparErro), mas parametrizado por `{ conta }`
// (string `empresa-N`/`company-N`) em vez de `{ produtoId }`.
//
// Usa `route()` global (Ziggy) direto via `axios`, sem o helper genérico de
// montagem de rota do editor — uma rota só não precisa dele. Este arquivo
// NUNCA importa nada da pasta do editor (hooks, pasta "Mesa" ou o helper de
// apoio dela) — é 100% novo e isolado, para não arriscar regredir o editor
// (território do outro dev).
//
// O servidor devolve `{ texto, atualizado_em }` nas duas rotas (GET e PUT);
// `atualizado_em` fica guardado aqui porque a página de Configurações usa
// para o "Salvo em" — a identidade por produto não precisa disso hoje.

/**
 * Extrator de mensagem de erro local — equivalente ao do editor, mas
 * copiado aqui de propósito (nunca importar o helper do editor).
 */
export const mensagemDeErro = (e) => {
    if (e?.code === 'ECONNABORTED') return 'O servidor demorou demais. Recarregue a página e confira antes de tentar de novo.';
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat()[0];

    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};

/** @param {{ conta: string }} opcoes */
export default function useIdentidadeDaContaPorConta({ conta }) {
    const [texto, setTexto] = useState(null);
    const [atualizadoEm, setAtualizadoEm] = useState(null);
    const [carregando, setCarregando] = useState(true);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);

    const carregar = async () => {
        setCarregando(true);
        setErro(null);

        try {
            const { data } = await axios.get(route('mlb.anuncios.publicador.conta.identidade.mostrar', { conta }));
            setTexto(data.texto ?? null);
            setAtualizadoEm(data.atualizado_em ?? null);
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setCarregando(false);
        }
    };

    const salvar = async (novoTexto) => {
        setSalvando(true);
        setErro(null);

        try {
            const { data } = await axios.put(route('mlb.anuncios.publicador.conta.identidade.salvar', { conta }), { texto: novoTexto });
            setTexto(data.texto ?? null);
            setAtualizadoEm(data.atualizado_em ?? null);
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setSalvando(false);
        }
    };

    const limparErro = () => setErro(null);

    // Troca de conta (prop `conta` muda) relê o texto daquela conta nova.
    useEffect(() => {
        carregar();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conta]);

    return { texto, atualizadoEm, carregando, salvando, erro, salvar, limparErro };
}
