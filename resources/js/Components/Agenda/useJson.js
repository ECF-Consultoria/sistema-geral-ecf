import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Leitura em JSON que acompanha a URL: troca de semana cancela o pedido da
 * semana anterior, para a resposta velha não sobrescrever a nova.
 *
 * `url` nulo não busca nada (ex.: o drawer ainda sem onboarding escolhido).
 * Falha vira `erro` em texto — a Agenda decora o trabalho, não pode derrubá-lo.
 */
export default function useJson(url) {
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState(null);
    const [carregando, setCarregando] = useState(Boolean(url));
    const controle = useRef(null);

    const buscar = useCallback(() => {
        controle.current?.abort();

        if (! url) {
            setCarregando(false);

            return;
        }

        const atual = new AbortController();
        controle.current = atual;
        setCarregando(true);
        setErro(null);

        fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: atual.signal,
        })
            .then(async (r) => {
                if (r.ok) return r.json();
                const corpo = await r.json().catch(() => ({}));
                const mensagem = r.status === 403
                    ? 'Você não tem acesso a esta agenda.'
                    : corpo.message || `Não deu para carregar a agenda (${r.status}).`;
                throw new Error(mensagem);
            })
            .then((json) => {
                if (! atual.signal.aborted) setDados(json);
            })
            .catch((e) => {
                if (e.name === 'AbortError') return;
                setErro(e.message || 'Não deu para carregar a agenda agora.');
            })
            .finally(() => {
                if (! atual.signal.aborted) setCarregando(false);
            });
    }, [url]);

    useEffect(() => {
        buscar();

        return () => controle.current?.abort();
    }, [buscar]);

    return { dados, erro, carregando, recarregar: buscar };
}
