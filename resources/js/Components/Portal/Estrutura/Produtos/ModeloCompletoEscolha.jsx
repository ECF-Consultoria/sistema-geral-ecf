import { useState } from 'react';
import axios from 'axios';
import { Download, Loader2, X } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import PickerCategoria from '@/Components/Portal/Estrutura/Produtos/PickerCategoria';
import {
    MAX_CATEGORIAS, adicionarCategoria, cabeMaisCategoria, erroDoDownload, nomeDoArquivoBaixado, parametrosDoModelo, removerCategoria, textoDoBaixar,
} from '@/lib/modeloCompleto';

// ─── "Baixar modelo": primeiro a categoria, depois a planilha ───────────────
//
// O modelo é sempre o completo: o produto e a ficha técnica na mesma linha (não há
// mais a opção de baixar só as colunas do produto). Como a ficha muda com a
// categoria, a pessoa escolhe a categoria aqui — pelo nome do produto ou navegando,
// com o mesmo seletor da ficha — uma ou mais, e o arquivo vem com uma aba para cada
// uma, já com todos os campos dela.
//
// A janela é larga de propósito: à esquerda, achar a categoria (a busca e a árvore
// precisam de espaço); à direita, o que já foi escolhido e o botão de baixar.

/** Entrega o arquivo recebido ao navegador como download, com o nome que o servidor deu. */
function salvar(blob, nome) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/** O conteúdo da janela: a escolha das categorias e o download. */
export function EscolhaDeCategorias() {
    const [categorias, setCategorias] = useState([]);
    const [baixando, setBaixando] = useState(false);
    const [erro, setErro] = useState(null);
    const cabeMais = cabeMaisCategoria(categorias);

    const somar = (patch) => {
        setErro(null);
        setCategorias((lista) => adicionarCategoria(lista, { id: patch.categoria_ml_id, nome: patch.categoria_ml_nome, caminho: patch.categoria_ml_caminho }));
    };

    const baixar = async () => {
        if (categorias.length === 0 || baixando) return;
        setBaixando(true);
        setErro(null);
        try {
            const resposta = await axios.get(route('portal.auth.estrutura.produtos.modelo_completo'), { params: parametrosDoModelo(categorias), responseType: 'blob' });
            // O nome vem com a categoria na frente ("poltronas-modelo.xlsx"), para a pessoa saber qual é qual.
            salvar(resposta.data, nomeDoArquivoBaixado(resposta.headers?.['content-disposition']));
        } catch (e) {
            // O motivo vem como JSON dentro do blob.
            const corpo = e.response?.data && typeof e.response.data.text === 'function' ? await e.response.data.text() : null;
            setErro(erroDoDownload(corpo) ?? 'Não foi possível montar a planilha agora. Tente de novo.');
        } finally {
            setBaixando(false);
        }
    };

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]" data-modelo-completo>
            <section data-achar-categoria>
                <h3 className="text-[13px] font-semibold text-white">1. Ache a categoria do produto</h3>
                <p className="mb-2 mt-1 text-[12px] text-white/55">
                    Digite o nome do produto para ver sugestões, ou navegue pelas categorias. Só a última categoria de cada caminho pode ser escolhida.
                </p>
                {cabeMais
                    ? <PickerCategoria largo textoInicial="" onCommit={somar} onClose={() => {}} />
                    : <p className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 text-[13px] text-white/60">Cabem até {MAX_CATEGORIAS} categorias por arquivo. Tire uma para escolher outra.</p>}
            </section>

            <section className="flex min-h-[280px] flex-col rounded-xl border border-white/[0.08] bg-white/[0.02] p-4" data-categorias-do-arquivo>
                <h3 className="text-[13px] font-semibold text-white">2. Categorias da planilha</h3>
                <p className="mt-1 text-[12px] text-white/55">
                    Cada categoria vira uma aba, com as colunas do produto e todos os campos da ficha técnica dela.
                </p>

                {categorias.length === 0 ? (
                    <p className="mt-4 flex-1 text-[13px] text-white/40" data-nenhuma-categoria>Nenhuma categoria escolhida ainda.</p>
                ) : (
                    <ul className="mt-3 flex-1 space-y-1.5 overflow-y-auto" data-categorias-escolhidas>
                        {categorias.map((c) => (
                            <li key={c.id} className="flex items-center justify-between gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2">
                                <span className="min-w-0">
                                    <span className="block truncate text-[13px] font-semibold text-white">{c.nome}</span>
                                    {c.caminho && <span className="block text-[11.5px] leading-snug text-white/45">{c.caminho}</span>}
                                </span>
                                <button type="button" onClick={() => setCategorias((lista) => removerCategoria(lista, c.id))} aria-label={`Tirar ${c.nome}`}
                                    className="shrink-0 rounded p-1 text-white/50 hover:bg-white/10 hover:text-white">
                                    <X size={14} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {erro && <p role="alert" className="mt-3 text-[13px] text-red-300" data-erro-modelo-completo>{erro}</p>}

                <Botao variante="primario" onClick={baixar} disabled={categorias.length === 0 || baixando} data-acao="baixar-modelo-completo" className="mt-4 w-full justify-center">
                    {baixando ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />} {baixando ? 'Montando a planilha…' : textoDoBaixar(categorias)}
                </Botao>
                <p className="mt-2 text-[11.5px] text-white/40">Depois de preencher, envie em "Importar planilha". As fotos entram em "Enviar fotos em lote".</p>
            </section>
        </div>
    );
}

/** A janela do "Baixar modelo", larga, aberta pela barra de Produtos e pela janela de importação. */
export default function JanelaBaixarModelo({ aberta, onFechar }) {
    return (
        <Janela aberta={aberta} onFechar={onFechar} largura="max-w-5xl" titulo="Baixar modelo"
            descricao="A planilha vem com os dados do produto e a ficha técnica na mesma linha. A ficha muda com a categoria, então escolha a categoria antes de baixar.">
            {aberta && <EscolhaDeCategorias />}
        </Janela>
    );
}
