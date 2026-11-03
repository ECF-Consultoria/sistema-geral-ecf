import { useCallback, useEffect, useRef, useState } from 'react';
import { camposDaCategoria, fichaPelaIa, mensagemDoErro } from '@/lib/trilhaEscrita';
import { categoriaParaConsulta, montarAtributos } from '@/lib/fichaTecnica';
import { camposDeMedidas, gruposSemMedidas } from '@/lib/medidasDoProduto';
import { aplicarSugestoesNaFicha, gruposDaFicha, podePreencherComIa, textoDaIa } from '@/lib/trilhaCadastro';

// ─── Trilha de produtos — os campos da ficha técnica na gaveta (F2) ─────────
//
// Sem visual (arquivo `.js`, sem classe do Tailwind). Os VALORES da ficha
// moram no estado da gaveta (`estado.ficha`, em `useCadastroDoProduto`): é por
// lá que o "mexido", a guarda de saída e o "Salvar" os veem. Aqui fica o resto:
//
// - a definição dos campos da categoria que está NA TELA (escolhida ou salva),
//   com um respiro de 350 ms (quem troca de categoria várias vezes seguidas não
//   pede todas) e memória por categoria (voltar à anterior não pede de novo). Só
//   a resposta da categoria de agora vale. É a `definicao()` do servidor, sem
//   as explicações por IA (D10: abrir a tela não é pedir IA);
// - os grupos como valem para o produto: sem o campo que é eixo das variações
//   dele e com as medidas do produto montado à parte (`medidas`, mostradas na
//   caixa da Lista; gravam com a ficha);
// - o "Preencher com IA" (F2b): botão manual, só com a categoria SALVA. A IA
//   lê o que está na tela (título, descrição e a ficha ainda não salva) e as
//   sugestões entram SÓ nos campos vazios. Nada grava: quem grava é o "Salvar".
//   Os campos que ela preencheu ficam marcados até a pessoa mexer neles.

const ESPERA_MS = 350;

/** O que a gaveta mostra antes de o hook existir (o teste de render desenha sem ele): nada carregado. */
export const FICHA_PARADA = Object.freeze({
    definicao: null,
    doProduto: [],
    grupos: [],
    medidas: [],
    carregando: false,
    indisponivel: false,
    tentarDeNovo: () => {},
    sugeridos: new Set(),
    esquecerSugestao: () => {},
    ia: { rodando: false, resultado: null },
    podeIa: false,
    preencherComIa: async () => {},
});

/**
 * @param {{escrita: object, produtoId: number, retrato: ?object, estado: ?object,
 *   trocarFicha: (mudar: (ficha: object) => object) => void, ativo?: boolean}} opcoes
 *   `trocarFicha`: troca a ficha do estado da gaveta (a IA usa); `ativo`: falso no produto só para leitura.
 */
export default function useFichaDaTrilha({ escrita, produtoId, retrato, estado, trocarFicha, ativo = true }) {
    const categoria = categoriaParaConsulta(estado?.produto?.categoria?.id);
    const [definicao, setDefinicao] = useState(null);      // {categoria, grupos} — a última que chegou
    const [falhou, setFalhou] = useState(null);             // a categoria cujos campos não vieram
    const [tentativa, setTentativa] = useState(0);
    const [sugeridos, setSugeridos] = useState(() => new Set());
    const [ia, setIa] = useState({ rodando: false, resultado: null });
    const memoria = useRef(new Map());                      // categoria → grupos (sem repetir o pedido)

    const vivo = useRef(true);
    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);

    useEffect(() => {
        if (!categoria) return undefined;
        if (memoria.current.has(categoria)) {
            setDefinicao({ categoria, grupos: memoria.current.get(categoria) });
            setFalhou(null);

            return undefined;
        }
        setFalhou(null);
        let vale = true;
        const espera = setTimeout(async () => {
            try {
                const dados = await camposDaCategoria(escrita, categoria);
                if (!vale) return;
                const grupos = Array.isArray(dados?.grupos) ? dados.grupos : [];
                if (dados?.indisponivel || grupos.length === 0) {
                    setFalhou(categoria);

                    return;
                }
                memoria.current.set(categoria, grupos);
                setDefinicao({ categoria, grupos });
            } catch {
                if (vale) setFalhou(categoria);
            }
        }, ESPERA_MS);

        return () => {
            vale = false;
            clearTimeout(espera);
        };
    }, [categoria, tentativa]);

    // Só a definição da categoria de AGORA vale: a da anterior sai da tela enquanto a nova não chega.
    const daCategoria = categoria && definicao?.categoria === categoria ? definicao : null;
    const indisponivel = !!categoria && !daCategoria && falhou === categoria;
    const carregando = !!categoria && !daCategoria && !indisponivel;
    const doProduto = daCategoria ? gruposDaFicha(daCategoria.grupos, retrato) : [];

    const esquecerSugestao = useCallback((id) => {
        setSugeridos((atual) => {
            if (!atual.has(id)) return atual;
            const outra = new Set(atual);
            outra.delete(id);

            return outra;
        });
    }, []);

    // O de agora, para o clique que termina depois (a IA leva alguns segundos).
    const agora = useRef({});
    agora.current = { estado, retrato, doProduto, rodando: ia.rodando };

    const podeIa = ativo && !!daCategoria && podePreencherComIa(retrato, estado);

    const preencherComIa = useCallback(async () => {
        const { estado: e, retrato: r, doProduto: grupos, rodando } = agora.current;
        if (rodando || !podePreencherComIa(r, e)) return;
        agora.current.rodando = true;
        setIa({ rodando: true, resultado: null });
        let resultado;
        try {
            const dados = await fichaPelaIa(escrita, produtoId, {
                categoria: e.produto.categoria.id,
                titulo: e.produto.titulo,
                descricao: e.produto.descricao,
                atributos: montarAtributos(grupos, e.ficha ?? {}),
            });
            const sugestoes = Array.isArray(dados?.sugestoes) ? dados.sugestoes : [];
            // A ficha de AGORA (a pessoa pode ter digitado durante a espera): só os vazios dela recebem.
            const r2 = aplicarSugestoesNaFicha(agora.current.estado?.ficha ?? {}, sugestoes);
            if (r2.aplicados.length) {
                trocarFicha((ficha) => aplicarSugestoesNaFicha(ficha, sugestoes).ficha);
                setSugeridos((atual) => new Set([...atual, ...r2.aplicados]));
            }
            resultado = { aplicados: r2.aplicados.length, naoSeAplica: r2.naoSeAplica };
        } catch (erro) {
            resultado = { erro: mensagemDoErro(erro) };
        }
        agora.current.rodando = false;
        if (vivo.current) setIa({ rodando: false, resultado: { ...resultado, texto: textoDaIa(resultado) } });
    }, [escrita, produtoId, trocarFicha]);

    return {
        definicao: daCategoria,
        doProduto,
        grupos: gruposSemMedidas(doProduto),
        medidas: camposDeMedidas(doProduto),
        carregando,
        indisponivel,
        tentarDeNovo: () => setTentativa((n) => n + 1),
        sugeridos,
        esquecerSugestao,
        ia,
        podeIa,
        preencherComIa,
    };
}
