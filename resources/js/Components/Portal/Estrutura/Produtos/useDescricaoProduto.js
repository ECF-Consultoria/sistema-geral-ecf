import { useRef, useState } from 'react';
import axios from 'axios';

// ─── Estado da descrição do produto ─────────────────────────────────────────
//
// O texto é livre e vai junto no "Salvar produto": o PUT só acontece depois que o
// produto existe e só se o texto mudou. Quem digita avisa a ficha (`aoAlterar`),
// que então protege a saída sem salvar.

export const LIMITE_DESCRICAO = 5000;

/** Mudou de verdade? Compara sem os espaços das pontas (o servidor também faz trim). */
export const deveGravarDescricao = (inicial, atual) => String(inicial ?? '').trim() !== String(atual ?? '').trim();

export default function useDescricaoProduto({ inicial = '', aoAlterar = () => {} }) {
    const [texto, setTexto] = useState(inicial ?? '');
    const [erro, setErro] = useState(null);
    const salvoRef = useRef(inicial ?? '');
    const textoRef = useRef(texto);
    textoRef.current = texto;

    const alterar = (valor) => {
        aoAlterar();
        setErro(null);
        setTexto(valor);
    };

    /** Grava no produto já existente. Devolve { ok, pulou }. */
    const gravar = async (produtoId) => {
        if (! produtoId || ! deveGravarDescricao(salvoRef.current, textoRef.current)) {
            return { ok: true, pulou: true };
        }
        setErro(null);
        try {
            const { data } = await axios.put(route('portal.auth.estrutura.produtos.descricao', { produto: produtoId }), { descricao: textoRef.current });
            salvoRef.current = data?.descricao ?? '';

            return { ok: true, pulou: false };
        } catch (e) {
            const msg = e?.response?.data?.errors?.descricao?.[0] ?? e?.response?.data?.message ?? 'Não foi possível salvar a descrição agora.';
            setErro(String(msg));

            return { ok: false, pulou: false };
        }
    };

    return { texto, erro, alterar, gravar };
}
