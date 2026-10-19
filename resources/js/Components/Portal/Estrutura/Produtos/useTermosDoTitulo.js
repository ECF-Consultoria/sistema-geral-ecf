import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';

// ─── Termos da categoria e limite do título, na ficha do produto (19/10/2026) ──
//
// Com a categoria escolhida, lê os termos mais buscados dela e o limite de caracteres
// do título. Trocar a categoria relê a lista e o limite; o título digitado fica como
// está. Sem categoria não há o que ler (o limite é o padrão). Se a leitura falhar,
// `erro` explica e o título continua sendo digitado à mão — nada aqui trava a ficha.
// O nome do produto vai junto só para marcar os termos que o citam (`relacionado`);
// mudar o nome relê depois de uma pausa na digitação.

const ESPERA_MS = 600;
const ERRO = 'Não conseguimos carregar os termos agora. Você pode digitar o título normalmente.';

export default function useTermosDoTitulo({ categoria, nome }) {
    const [estado, setEstado] = useState({ dados: null, limite: null, carregando: false, erro: null });
    const [tentativa, setTentativa] = useState(0);
    const pedido = useRef(0);
    const id = String(categoria ?? '').trim();
    const texto = String(nome ?? '').trim().slice(0, 120);

    useEffect(() => {
        if (! id) {
            pedido.current += 1;
            setEstado({ dados: null, limite: null, carregando: false, erro: null });

            return undefined;
        }
        const meu = ++pedido.current;
        setEstado((atual) => ({ ...atual, carregando: true, erro: null }));
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('portal.auth.estrutura.produtos.termos_titulo'), { params: { categoria: id, nome: texto } });
                if (meu !== pedido.current) return;
                setEstado({
                    dados: data?.indisponivel ? null : data,
                    limite: Number(data?.limite) > 0 ? Number(data.limite) : null,
                    carregando: false,
                    erro: data?.indisponivel ? ERRO : null,
                });
            } catch {
                if (meu !== pedido.current) return;
                setEstado((atual) => ({ ...atual, dados: null, carregando: false, erro: ERRO }));
            }
        }, ESPERA_MS);

        return () => clearTimeout(t);
    }, [id, texto, tentativa]);

    const tentarDeNovo = useCallback(() => setTentativa((n) => n + 1), []);

    return { ...estado, temCategoria: id !== '', tentarDeNovo };
}
