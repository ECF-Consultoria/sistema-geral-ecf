import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from './apoio.js';
import { deveDispararAuto, podeAplicarDescricao } from './descricaoIa.js';

// Descrição por IA (Fase 172, D-11): o servidor roda o MAG T8 na fila; aqui a página pede
// (sozinha uma vez, no rascunho vazio com descrição do cliente; ou pelo botão), acompanha só o
// PRÓPRIO pedido e aplica o texto pelo caminho normal de edição (`m.mudarRasc`) — nunca grava
// por fora, para não haver segunda escrita concorrente no rascunho.

const INTERVALO = 2500;
const LIMITE = 5 * 60 * 1000;

const rota = criarRota('mlb.anuncios.publicador', 'produto');
const PARADO = { status: 'parado', pedido: null, automatico: false, textoNoPedido: '', valor: null, erro: null, desde: 0 };

export default function useDescricaoIa({ m, produtoId }) {
    const [s, setS] = useState(PARADO);
    const ref = useRef(PARADO);
    const mRef = useRef(m);
    mRef.current = m;
    const disparou = useRef(false);

    const mudar = (patch) => {
        ref.current = { ...ref.current, ...patch };
        setS(ref.current);
    };

    const pedir = async ({ automatico = false } = {}) => {
        mudar({ status: 'rodando', erro: null, pedido: null, valor: null, automatico, textoNoPedido: mRef.current.rasc?.descricao ?? '', desde: Date.now() });
        try {
            const { data } = await axios.post(rota('descricao-ia', produtoId), { automatico });
            if (data.status === 'ja_pedido' || data.status === 'nao_se_aplica') {
                mudar({ status: 'parado' });

                return;
            }
            mudar({ pedido: data.pedido });
        } catch (e) {
            mudar({ status: 'erro', erro: mensagemDe(e) });
        }
    };

    const aplicar = () => {
        const valor = ref.current.valor;
        if (valor == null) return;
        mRef.current.mudarRasc({ descricao: valor });
        mudar({ status: 'parado', valor: null });
    };

    // Pedido automático: uma vez por montagem (o servidor garante uma vez por rascunho).
    const carregou = !! m.estado && !! m.rasc;
    const pede = carregou && deveDispararAuto({ descricao: m.rasc?.descricao, descricaoCliente: m.estado?.portal?.descricao_cliente, disabled: m.disabled });
    useEffect(() => {
        if (! pede || disparou.current) return;
        disparou.current = true;
        pedir({ automatico: true });
    }, [pede]); // eslint-disable-line react-hooks/exhaustive-deps

    const rodando = s.status === 'rodando' && !! s.pedido;
    useEffect(() => {
        if (! rodando) return undefined;
        const t = setInterval(async () => {
            const atual = ref.current;
            if (atual.status !== 'rodando' || ! atual.pedido) return;
            if (Date.now() - atual.desde > LIMITE) {
                mudar({ status: 'erro', erro: 'A IA demorou demais. Tente de novo.' });

                return;
            }
            try {
                const { data } = await axios.get(rota('descricao-ia.status', produtoId));
                if (data.pedido !== atual.pedido) return;
                if (data.status === 'pronto') {
                    const pode = podeAplicarDescricao({ automatico: atual.automatico, textoNoPedido: atual.textoNoPedido, textoAgora: mRef.current.rasc?.descricao ?? '' });
                    if (pode) {
                        mRef.current.mudarRasc({ descricao: data.valor });
                        mudar({ status: 'parado', valor: null });
                    } else {
                        mudar({ status: 'pronto', valor: data.valor });
                    }
                } else if (data.status === 'erro') {
                    mudar({ status: 'erro', erro: data.erro ?? 'A IA não conseguiu agora. Tente de novo.' });
                }
            } catch {
                // Leitura que falha não para o acompanhamento: tenta na próxima volta.
            }
        }, INTERVALO);

        return () => clearInterval(t);
    }, [rodando, produtoId]);

    return { ...s, pedir, aplicar };
}
