import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio';

/** Gerador de rotas das Alavancas: `rota('panorama', 'empresa-7', { atualizar: 1 })`. */
export const rota = criarRota('mlb.anuncios.publicador.alavancas', 'conta');

const limpar = (params) => Object.fromEntries(Object.entries(params ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== ''));

/**
 * Leitura sob demanda de uma rota JSON das Alavancas.
 * Os parâmetros vão TODOS pelo Ziggy: o que é parâmetro da rota entra no caminho,
 * o resto vira query. Resposta velha (de uma chamada anterior) é descartada.
 */
export function useLeitura(nome, conta, params = {}, { ativo = true } = {}) {
    const [estado, setEstado] = useState({ dados: null, erro: null, carregando: ativo });
    const contador = useRef(0);
    const chave = JSON.stringify(limpar(params));

    const buscar = useCallback((extra = {}) => {
        const numero = ++contador.current;
        setEstado((e) => ({ ...e, erro: null, carregando: true }));

        return axios.get(rota(nome, conta, { ...JSON.parse(chave), ...limpar(extra) }))
            .then((r) => {
                if (numero === contador.current) setEstado({ dados: r.data, erro: null, carregando: false });
            })
            .catch((e) => {
                if (numero === contador.current) setEstado({ dados: null, erro: mensagemDe(e), carregando: false });
            });
    }, [nome, conta, chave]);

    useEffect(() => {
        if (! ativo) return undefined;
        buscar();

        return () => { contador.current += 1; };
    }, [ativo, buscar]);

    return { dados: estado.dados, erro: estado.erro, carregando: estado.carregando, recarregar: buscar };
}

// ─── Escrita: toda ação passa pela prévia assinada e só então pela confirmação ───

/** Intervalo entre duas leituras do andamento de um lote, e o tempo máximo acompanhando. */
const INTERVALO_LOTE = 2500;
const LIMITE_LOTE = 4 * 60 * 1000;

/** Prévia: só lê no Mercado Livre e devolve o resumo com a assinatura. */
export const previa = (conta, acao, itens) => axios.post(rota('escritas.previa', conta), { acao, itens });

/** Confirmação: o servidor confere a assinatura antes de escrever. */
export const confirmar = (conta, acao, itens, assinatura) => axios.post(rota('escritas.confirmar', conta), { acao, itens, assinatura });

/**
 * Acompanha um lote até terminar (ou até o tempo limite). Sem `lote` não faz nada.
 * Devolve `{ dados, erro, esgotou }`; `dados` é a resposta de `lotes/{lote}`.
 */
export function useLote(conta, lote) {
    const [estado, setEstado] = useState({ dados: null, erro: null, esgotou: false });

    useEffect(() => {
        if (! lote) return undefined;
        setEstado({ dados: null, erro: null, esgotou: false });

        let vivo = true;
        const inicio = Date.now();
        let intervalo = null;

        const ler = () => axios.get(rota('lotes', conta, { lote }))
            .then((r) => {
                if (! vivo) return;
                setEstado({ dados: r.data, erro: null, esgotou: false });
                if (r.data?.terminado) clearInterval(intervalo);
            })
            .catch((e) => {
                if (vivo) setEstado((s) => ({ ...s, erro: mensagemDe(e) }));
            })
            .finally(() => {
                if (vivo && Date.now() - inicio > LIMITE_LOTE) {
                    clearInterval(intervalo);
                    setEstado((s) => ({ ...s, esgotou: true }));
                }
            });

        ler();
        intervalo = setInterval(ler, INTERVALO_LOTE);

        return () => {
            vivo = false;
            clearInterval(intervalo);
        };
    }, [conta, lote]);

    return estado;
}
