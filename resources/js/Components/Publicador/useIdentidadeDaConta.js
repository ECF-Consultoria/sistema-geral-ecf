import { useEffect, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from './apoio.js';

// ─── Identidade visual da CONTA do produto aberto (Fase 170, D2, IDENT-01/04) ───
//
// Endereçada por `{produto}` só para o servidor descobrir qual CONTA — o
// texto lido/gravado é sempre o da conta (`company_id`/`mlb_empresa_id` do
// produto), nunca do produto em si. Trocar de produto da MESMA conta mostra
// o MESMO texto; trocar de conta mostra o texto daquela outra conta (ou
// vazio). Uma instância só por página (EtapaImagens.jsx monta uma vez no
// topo) — por isso o hook é chamado direto aqui, sem Context.

const rota = criarRota('mlb.anuncios.publicador', 'produto');

/** @param {{ produtoId: number }} opcoes */
export default function useIdentidadeDaConta({ produtoId }) {
    const [texto, setTexto] = useState(null);
    const [carregando, setCarregando] = useState(true);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);

    const carregar = async () => {
        setCarregando(true);
        setErro(null);

        try {
            const { data } = await axios.get(rota('identidade.mostrar', produtoId));
            setTexto(data.texto ?? null);
        } catch (e) {
            setErro(mensagemDe(e));
        } finally {
            setCarregando(false);
        }
    };

    const salvar = async (novoTexto) => {
        setSalvando(true);
        setErro(null);

        try {
            const { data } = await axios.put(rota('identidade.salvar', produtoId), { texto: novoTexto });
            setTexto(data.texto ?? null);
        } catch (e) {
            setErro(mensagemDe(e));
        } finally {
            setSalvando(false);
        }
    };

    const limparErro = () => setErro(null);

    // Troca de produto (mesma conta ou outra) relê o texto daquela conta.
    useEffect(() => {
        carregar();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [produtoId]);

    return { texto, carregando, salvando, erro, salvar, limparErro };
}
