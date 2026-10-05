import { createContext, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from './apoio.js';

// ─── Kit de criativos por IA do Publicador (Fase 165, D-08) ────────────────
//
// Um kit por grupo de fotos por vez: quem acompanha é este hook, não o
// componente de tela (`Mesa/PainelCriativos.jsx`), que é apresentação pura.
// As rotas moram só aqui — o editor nunca chama `route(` diretamente.
//
// O kit é endereçado pelo id numérico (`kit_id`); o servidor nunca manda
// token nenhum (D-13) — nem no sessionStorage, nem em lugar algum do front.
//
// Ao usar uma imagem (`aprovar`/`aprovarKit`), quem chamou o hook (o
// `BlocoDeFotos`, no 165-07) relê o rascunho — este hook só avisa via
// `aoAprovar`, nunca aplica o resultado em outro estado por fora.
//
// O painel pertence a UMA instância do bloco de fotos: duas variações podem
// dividir o mesmo grupo (ex.: Preto/P e Preto/M), e cada uma delas monta o
// próprio `BlocoDeFotos` — `reivindicar()` decide qual delas, das que
// montaram, é quem mostra o painel depois de um F5.

const INTERVALO = 5000;
// O mesmo teto do kit no painel antigo (`PainelCriativosIa.jsx`,
// `LIMITE_ESPERA_KIT_MS`) — o SERVIDOR já encerra o kit antes disso
// (`MlAnuncioCriativoKit::LIMITE_MINUTOS`); isto só cobre o caso em que nem
// ele responde.
const LIMITE = 27 * 60 * 1000;

const rota = criarRota('mlb.anuncios.publicador', 'produto');
const chaveDo = (produtoId) => `publicador.criativos.${produtoId}`;

const guardado = (produtoId) => {
    try {
        const bruto = window.sessionStorage.getItem(chaveDo(produtoId));
        if (! bruto) return null;
        const v = JSON.parse(bruto);

        return v && typeof v.grupo === 'string' ? { grupo: v.grupo, titulo: v.titulo ?? '' } : null;
    } catch {
        return null;
    }
};
const guardar = (produtoId, alvo) => {
    try {
        if (alvo) window.sessionStorage.setItem(chaveDo(produtoId), JSON.stringify({ grupo: alvo.grupo, titulo: alvo.titulo ?? '' }));
        else window.sessionStorage.removeItem(chaveDo(produtoId));
    } catch {
        // Sem sessionStorage (modo restrito): a retomada depois de F5 só não funciona.
    }
};

/** Contexto pelo qual o `BlocoDeFotos` (165-07) chega ao hook sem prop-drilling. */
export const CriativosDoPublicador = createContext(null);

/**
 * @param {{ produtoId: number, disponivel?: boolean, onAprovou?: Function }} opcoes
 *   `disponivel` = a chave do Creative Engine está ligada para este produto (D-09); com `false`,
 *   `abrir()` não faz nada e a retomada por sessionStorage é limpa. `onAprovou` avisa quem montou
 *   o hook para reler o rascunho (a imagem usada entra nele por fora deste hook).
 */
export default function useCriativosDoPublicador({ produtoId, disponivel = false, onAprovou }) {
    // alvo = { grupo, titulo, instancia } | null — instancia é só em memória (nunca vai ao sessionStorage).
    const [alvo, setAlvo] = useState(null);
    const [kit, setKit] = useState(null);
    const [fase, setFase] = useState('parado'); // 'parado' | 'carregando' | 'escolhendo' | 'kit'
    const [erro, setErro] = useState(null);
    const [processando, setProcessando] = useState(null);
    const [confirmacaoRecusada, setConfirmacaoRecusada] = useState(false);
    const [motivos, setMotivos] = useState({});

    const aoAprovar = useRef(onAprovou);
    aoAprovar.current = onAprovou;
    const desde = useRef(null);

    /** Abre o painel para `grupo`: retoma o kit ativo (ou o último aprovado), ou vai para 'escolhendo'. */
    const abrir = async (grupo, titulo, instancia = null) => {
        if (! disponivel) return;

        setAlvo({ grupo, titulo, instancia });
        guardar(produtoId, { grupo, titulo });
        setFase('carregando');
        setErro(null);
        setConfirmacaoRecusada(false);
        setMotivos({});

        try {
            const { data } = await axios.get(rota('criativos.atual', produtoId), { params: { grupo } });
            if (data.kit) {
                setKit(data.kit);
                setFase('kit');
            } else {
                setKit(null);
                setFase('escolhendo');
            }
        } catch (e) {
            setKit(null);
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));
            setFase('escolhendo');
        }
    };

    /** Depois de um F5, o primeiro `BlocoDeFotos` daquele grupo a montar reivindica o painel. */
    const reivindicar = (grupo, instancia) => {
        setAlvo((a) => (a && a.grupo === grupo && a.instancia === null ? { ...a, instancia } : a));
    };

    /** O kit continua no servidor e é retomado ao abrir de novo (D-15) — fechar é só a tela local. */
    const fechar = () => {
        setAlvo(null);
        setKit(null);
        setErro(null);
        setMotivos({});
        setFase('parado');
        guardar(produtoId, null);
    };

    const planejar = async ({ imagens = [], arquivos = [] }) => {
        if (! alvo) return;
        setProcessando('planejar');
        setErro(null);

        try {
            const fd = new FormData();
            fd.append('grupo', alvo.grupo);
            imagens.forEach((id) => fd.append('imagens[]', id));
            arquivos.forEach((arquivo) => fd.append('referencias[]', arquivo));

            const { data } = await axios.post(rota('criativos.kit.planejar', produtoId), fd);
            const { data: doKit } = await axios.get(rota('criativos.kit.status', produtoId, { kit: data.kit_id }));

            setKit(doKit);
            setFase('kit');
            setConfirmacaoRecusada(false);
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));
        } finally {
            setProcessando(null);
        }
    };

    const relerKit = async () => {
        if (! kit?.kit_id) return;

        try {
            const { data } = await axios.get(rota('criativos.kit.status', produtoId, { kit: kit.kit_id }));
            setKit(data);
        } catch (e) {
            if (e?.response?.status === 404) {
                setKit(null);
                setFase('escolhendo');
            }
        }
    };

    const gerar = async () => {
        if (! kit?.kit_id) return;
        setProcessando('gerar');
        setErro(null);

        try {
            await axios.post(rota('criativos.kit.gerar', produtoId, { kit: kit.kit_id }));
            await relerKit();
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));
        } finally {
            setProcessando(null);
        }
    };

    // "Agora não" só esconde a pergunta (não cancela nem apaga o kit no servidor) — mostrarConfirmacao traz de volta.
    const recusarConfirmacao = () => setConfirmacaoRecusada(true);
    const mostrarConfirmacao = () => setConfirmacaoRecusada(false);

    const mudarMotivo = (indice, texto) => setMotivos((m) => ({ ...m, [indice]: texto.slice(0, 300) }));

    const regenerar = async (indice) => {
        if (! kit?.kit_id) return;
        setProcessando(`regenerar-${indice}`);
        setErro(null);

        try {
            await axios.post(rota('criativos.slot.regenerar', produtoId, { kit: kit.kit_id, indice }), { motivo: motivos[indice] ?? '' });
            setMotivos((m) => ({ ...m, [indice]: '' }));
            await relerKit();
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));
        } finally {
            setProcessando(null);
        }
    };

    const aprovar = async (indice) => {
        if (! kit?.kit_id) return;
        setProcessando(`aprovar-${indice}`);
        setErro(null);

        try {
            const { data } = await axios.post(rota('criativos.slot.aprovar', produtoId, { kit: kit.kit_id, indice }));
            setKit(data.kit);
            aoAprovar.current?.();
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));
        } finally {
            setProcessando(null);
        }
    };

    const aprovarKit = async () => {
        if (! kit?.kit_id) return;
        setProcessando('aprovar-kit');
        setErro(null);

        try {
            const { data } = await axios.post(rota('criativos.kit.aprovar', produtoId, { kit: kit.kit_id }));
            setKit(data.kit);
            if (data.ok === false) setErro(data.mensagem);
            if (data.aprovadas > 0) aoAprovar.current?.();
        } catch (e) {
            setErro(e?.response?.data?.erros?.[0]?.mensagem ?? mensagemDe(e));
        } finally {
            setProcessando(null);
        }
    };

    /** Kit fechado (aprovado ou com erro): volta a 'escolhendo'. O servidor só bloqueia quando há kit retomável. */
    const novoKit = () => {
        if (kit?.status === 'aprovado' || kit?.status === 'erro') {
            setFase('escolhendo');
            setKit(null);
        }
    };

    const limparErro = () => setErro(null);

    // Polling — só enquanto o kit está em andamento; para sozinho (kit terminado, 404 ou teto de tempo).
    useEffect(() => {
        if (! kit?.em_andamento) {
            desde.current = null;

            return undefined;
        }

        let vivo = true;
        desde.current ??= Date.now();

        const t = setInterval(async () => {
            if (Date.now() - desde.current > LIMITE) {
                clearInterval(t);
                setKit((k) => (k ? { ...k, em_andamento: false, status: 'erro' } : k));
                setErro('A geração demorou demais. Feche e abra de novo para ver como ficou.');

                return;
            }

            try {
                const { data } = await axios.get(rota('criativos.kit.status', produtoId, { kit: kit.kit_id }));
                if (! vivo) return;
                setKit(data);
            } catch (e) {
                if (! vivo) return;
                if (e?.response?.status === 404) {
                    clearInterval(t);
                    setKit(null);
                    setFase('escolhendo');
                }
            }
        }, INTERVALO);

        return () => { vivo = false; clearInterval(t); };
    }, [kit?.kit_id, kit?.em_andamento, produtoId]);

    // Retomada: troca de produto ou F5 na mesma aba — o alvo guardado reabre o painel; a
    // instância é reivindicada pelo próprio bloco quando ele montar (`reivindicar`).
    useEffect(() => {
        if (! disponivel) {
            setAlvo(null);
            setKit(null);
            setFase('parado');

            return;
        }

        const salvo = guardado(produtoId);
        if (salvo) abrir(salvo.grupo, salvo.titulo, null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [produtoId, disponivel]);

    return {
        disponivel, alvo, kit, fase, erro, processando, confirmacaoRecusada, motivos,
        abrir, reivindicar, fechar, planejar, gerar, recusarConfirmacao, mostrarConfirmacao,
        mudarMotivo, regenerar, aprovar, aprovarKit, novoKit, limparErro,
    };
}
