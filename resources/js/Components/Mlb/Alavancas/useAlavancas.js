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
