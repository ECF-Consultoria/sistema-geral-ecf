import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { mensagemDe } from './apoio.js';
import { estadoDaIa } from './derivados.js';

// ─── "Anunciar por IA" dentro do editor do Publicador (D14) ─────────────────
//
// Dispara a análise do produto na fila, acompanha as etapas e avisa a casca ao
// concluir (que relê o rascunho). Guarda só o id da análise no sessionStorage,
// para o acompanhamento sobreviver a um F5. A confirmação "Substituir o que já
// está preenchido?" é da casca, que chama `disparar(true)` depois do Dialog.

const INTERVALO = 2500;
const LIMITE = 15 * 60 * 1000;
const chaveDo = (produtoId) => `publicador.ia.${produtoId}`;

const guardado = (produtoId) => {
    try {
        const v = Number(window.sessionStorage.getItem(chaveDo(produtoId)));

        return Number.isFinite(v) && v > 0 ? v : null;
    } catch {
        return null;
    }
};
const guardar = (produtoId, id) => {
    try {
        if (id) window.sessionStorage.setItem(chaveDo(produtoId), String(id));
        else window.sessionStorage.removeItem(chaveDo(produtoId));
    } catch {
        // Sem sessionStorage (modo restrito): o acompanhamento só não sobrevive ao F5.
    }
};

/**
 * @param {{ produtoId: number, nomeProduto?: string, onConcluiu?: Function, onFalhou?: Function }} opcoes
 *   `onFalhou` = a análise terminou com erro no servidor; a IA pode ter gravado parte do
 *   rascunho antes de cair, então quem chama relê (CR-F02).
 */
export default function useIaDoPublicador({ produtoId, nomeProduto = '', onConcluiu, onFalhou }) {
    const [analiseId, setAnaliseId] = useState(null);
    const [resposta, setResposta] = useState(null);
    const [erroDisparo, setErroDisparo] = useState(null);
    const desde = useRef(Date.now());
    const aoConcluir = useRef(onConcluiu);
    aoConcluir.current = onConcluiu;
    const aoFalhar = useRef(onFalhou);
    aoFalhar.current = onFalhou;

    // Troca de produto na faixa (ou F5): retoma a análise guardada, se houver.
    useEffect(() => {
        setResposta(null);
        setErroDisparo(null);
        const id = guardado(produtoId);
        desde.current = Date.now();
        setAnaliseId(id);
    }, [produtoId]);

    // Acompanhamento.
    useEffect(() => {
        if (! analiseId) return undefined;
        let vivo = true;
        const t = setInterval(async () => {
            if (Date.now() - desde.current > LIMITE) {
                guardar(produtoId, null);
                setAnaliseId(null);
                setResposta({ status: 'erro', erro: 'A análise demorou demais. Tente de novo.' });

                return;
            }
            try {
                const { data } = await axios.get(route('mlb.anuncios.ia.analise.status', analiseId));
                if (! vivo) return;
                setResposta(data);
                if (data.status === 'concluido' || data.status === 'erro') {
                    guardar(produtoId, null);
                    setAnaliseId(null);
                    if (data.status === 'concluido') aoConcluir.current?.(data.publicador ?? null);
                    else aoFalhar.current?.(data.erro ?? null);
                }
            } catch (e) {
                // Uma leitura que falha não para o acompanhamento; 404 = análise que não existe mais.
                if (vivo && e?.response?.status === 404) {
                    guardar(produtoId, null);
                    setAnaliseId(null);
                    setResposta(null);
                }
            }
        }, INTERVALO);

        return () => { vivo = false; clearInterval(t); };
    }, [analiseId, produtoId]);

    const disparar = async (substituir = false) => {
        setErroDisparo(null);
        setResposta({ status: 'pendente', etapa: 'analise' });
        try {
            const { data } = await axios.post(route('mlb.anuncios.ia.analise.store'), { produto_id: produtoId, produto: nomeProduto, substituir });
            guardar(produtoId, data.id);
            desde.current = Date.now();
            setAnaliseId(data.id);
        } catch (e) {
            setResposta(null);
            setErroDisparo(mensagemDe(e));
        }
    };

    const e = estadoDaIa(resposta);

    return {
        estado: erroDisparo ? 'erro' : e.estado,
        etapa: e.etapa,
        textoEtapa: e.texto,
        resumo: e.resumo,
        erro: erroDisparo ?? e.erro,
        disparar,
        tentarDeNovo: () => disparar(false),
    };
}
