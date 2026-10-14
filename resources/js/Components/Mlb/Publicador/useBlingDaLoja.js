import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

// ─── Bling da loja — Configurações do Publicador (Fase 184, plano 12) ──────
//
// Hook que fala com as sete rotas do bloco `bling` (plano 11, controller
// `MlbPublicadorBlingController`). Pinta a partir do `inicial` que o Inertia
// já manda em `conexoes.bling` — a tela não espera NENHUMA requisição para a
// primeira renderização (mesmo espírito de `useIdentidadeDaContaPorConta`,
// que também fala com `route()` global + axios direto).
//
// Depois de uma ação que muda o estado da conexão mas devolve só o resultado
// da própria ação (gerar link, importar, atualizar estoque), o hook REFAZ o
// estado (GET da rota `estado`) em vez de tentar remontar o bloco `bling` à
// mão — é a mesma disciplina de fonte única do servidor (plano 11: nenhum
// cálculo de estado fora de `PainelVisaoGeralService::bling()`), só que do
// lado do front: o hook nunca inventa o formato do bloco, só o relê.
//
// ⚠️ Polling com TETO (T-184-84): `iniciarLeitura()` começa uma rodada e
// consulta o progresso a cada 3s até concluir, errar, ou atingir
// MAX_TENTATIVAS — sem teto, uma aba aberta bateria no servidor pra sempre.
// 500 produtos levam ~8,5min pra ler (seed §2.3); MAX_TENTATIVAS cobre ~10min.

const MAX_TENTATIVAS = 200;
const INTERVALO_POLLING_MS = 3000;

/** Extrator de mensagem de erro — a rota devolve 422 com `message` em pt-BR; nunca o erro cru. */
const mensagemDeErro = (e) => {
    const d = e?.response?.data;
    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};

/** @param {{ conta: string, inicial: object|null }} opcoes */
export default function useBlingDaLoja({ conta, inicial }) {
    const [bling, setBling] = useState(inicial ?? null);
    const [carregando, setCarregando] = useState(false);
    const [agindo, setAgindo] = useState(null);
    const [erro, setErro] = useState(null);
    const [previa, setPrevia] = useState(null);

    const timerRef = useRef(null);
    const tentativasRef = useRef(0);

    const limparTimer = () => {
        if (timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }
    };

    // Aba fechada ou tela desmontada no meio do polling não pode deixar
    // nenhum timer vivo batendo na rota.
    useEffect(() => () => limparTimer(), []);

    const buscarEstado = async () => {
        setCarregando(true);
        try {
            const { data } = await axios.get(route('mlb.anuncios.publicador.bling.estado', { conta }));
            setBling(data ?? null);
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setCarregando(false);
        }
    };

    const gerarLink = async () => {
        setAgindo('gerarLink');
        setErro(null);
        try {
            await axios.post(route('mlb.anuncios.publicador.bling.link', { conta }));
            await buscarEstado();
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setAgindo(null);
        }
    };

    const desconectar = async () => {
        if (!window.confirm('Desconectar o Bling desta loja? A equipe vai precisar gerar um novo link para reconectar.')) {
            return;
        }
        setAgindo('desconectar');
        setErro(null);
        try {
            const { data } = await axios.delete(route('mlb.anuncios.publicador.bling.desconectar', { conta }));
            setBling(data ?? null);
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setAgindo(null);
        }
    };

    const pararPolling = () => {
        limparTimer();
        tentativasRef.current = 0;
    };

    const consultarProgresso = async (rodada) => {
        tentativasRef.current += 1;

        try {
            const { data } = await axios.get(
                route('mlb.anuncios.publicador.bling.leitura.progresso', { conta, rodada }),
            );

            if (data?.erro) {
                pararPolling();
                setErro(data.erro);
                setAgindo(null);
                return;
            }

            if (data?.concluida) {
                pararPolling();
                setPrevia({ ...data, rodada });
                setAgindo(null);
                return;
            }

            if (tentativasRef.current >= MAX_TENTATIVAS) {
                pararPolling();
                setErro('A leitura do Bling demorou demais. Tente de novo em alguns minutos.');
                setAgindo(null);
            }
        } catch (e) {
            pararPolling();
            setErro(mensagemDeErro(e));
            setAgindo(null);
        }
    };

    const iniciarLeitura = async () => {
        setAgindo('iniciarLeitura');
        setErro(null);
        setPrevia(null);
        try {
            const { data } = await axios.post(route('mlb.anuncios.publicador.bling.leitura', { conta }));
            const rodada = data?.rodada;
            if (!rodada) {
                throw new Error('A leitura não começou — tente de novo.');
            }
            tentativasRef.current = 0;
            timerRef.current = setInterval(() => consultarProgresso(rodada), INTERVALO_POLLING_MS);
        } catch (e) {
            setErro(mensagemDeErro(e));
            setAgindo(null);
        }
    };

    const fecharPrevia = () => setPrevia(null);

    const importar = async () => {
        if (!previa?.rodada) return;
        setAgindo('importar');
        setErro(null);
        try {
            await axios.post(route('mlb.anuncios.publicador.bling.importar', { conta }), { rodada: previa.rodada });
            setPrevia(null);
            await buscarEstado();
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setAgindo(null);
        }
    };

    const atualizarEstoque = async () => {
        setAgindo('atualizarEstoque');
        setErro(null);
        try {
            await axios.post(route('mlb.anuncios.publicador.bling.estoque', { conta }));
            await buscarEstado();
        } catch (e) {
            setErro(mensagemDeErro(e));
        } finally {
            setAgindo(null);
        }
    };

    return {
        bling,
        carregando,
        agindo,
        erro,
        gerarLink,
        desconectar,
        iniciarLeitura,
        importar,
        atualizarEstoque,
        previa,
        fecharPrevia,
    };
}
