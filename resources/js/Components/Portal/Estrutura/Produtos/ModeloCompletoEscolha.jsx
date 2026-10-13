import { useState } from 'react';
import axios from 'axios';
import { Download, Loader2, Plus, X } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import PickerCategoria from '@/Components/Portal/Estrutura/Produtos/PickerCategoria';
import {
    MAX_CATEGORIAS, adicionarCategoria, cabeMaisCategoria, erroDoDownload, parametrosDoModelo, removerCategoria, textoDoBaixar,
} from '@/lib/modeloCompleto';

// ─── Baixar o modelo completo: primeiro a categoria, depois a planilha ──────
//
// A planilha completa traz o produto e a ficha técnica na mesma linha, e a ficha
// muda com a categoria. Por isso a pessoa escolhe a categoria aqui (pelo nome do
// produto ou navegando, com o mesmo seletor da ficha) — uma ou mais — e o arquivo
// vem com uma aba para cada uma, já com todos os campos dela.

const ARQUIVO = 'modelo-completo.xlsx';

/** Entrega o arquivo recebido ao navegador como download. */
function salvar(blob) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = ARQUIVO;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

export default function ModeloCompletoEscolha({ onFechar }) {
    const [categorias, setCategorias] = useState([]);
    const [escolhendo, setEscolhendo] = useState(true);
    const [baixando, setBaixando] = useState(false);
    const [erro, setErro] = useState(null);

    const somar = (patch) => {
        setErro(null);
        setCategorias((lista) => adicionarCategoria(lista, { id: patch.categoria_ml_id, nome: patch.categoria_ml_nome, caminho: patch.categoria_ml_caminho }));
    };

    const baixar = async () => {
        if (categorias.length === 0 || baixando) return;
        setBaixando(true);
        setErro(null);
        try {
            const { data } = await axios.get(route('portal.auth.estrutura.produtos.modelo_completo'), { params: parametrosDoModelo(categorias), responseType: 'blob' });
            salvar(data);
        } catch (e) {
            // O motivo vem como JSON dentro do blob.
            const corpo = e.response?.data && typeof e.response.data.text === 'function' ? await e.response.data.text() : null;
            setErro(erroDoDownload(corpo) ?? 'Não foi possível montar a planilha agora. Tente de novo.');
        } finally {
            setBaixando(false);
        }
    };

    return (
        <div className="space-y-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-modelo-completo>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-[13px] font-semibold text-white">Modelo completo: produto e ficha técnica na mesma planilha</p>
                    <p className="mt-1 text-[12px] text-white/55">
                        A ficha técnica muda com a categoria. Escolha a categoria dos produtos que você vai cadastrar (pode ser mais de uma):
                        a planilha vem com uma aba para cada uma, já com todos os campos dela.
                    </p>
                </div>
                <button type="button" onClick={onFechar} aria-label="Fechar o modelo completo" className="rounded-lg p-1 text-white/45 hover:bg-white/[0.06] hover:text-white">
                    <X size={15} />
                </button>
            </div>

            {categorias.length > 0 && (
                <ul className="space-y-1.5" data-categorias-escolhidas>
                    {categorias.map((c) => (
                        <li key={c.id} className="flex items-center justify-between gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-1.5">
                            <span className="min-w-0">
                                <span className="block truncate text-[13px] font-semibold text-white">{c.nome}</span>
                                {c.caminho && <span className="block truncate text-[11.5px] text-white/45">{c.caminho}</span>}
                            </span>
                            <button type="button" onClick={() => setCategorias((lista) => removerCategoria(lista, c.id))} aria-label={`Tirar ${c.nome}`}
                                className="shrink-0 rounded p-1 text-white/50 hover:bg-white/10 hover:text-white">
                                <X size={14} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {escolhendo && cabeMaisCategoria(categorias) ? (
                <PickerCategoria textoInicial="" onCommit={somar} onClose={() => setEscolhendo(false)} />
            ) : (
                cabeMaisCategoria(categorias)
                    ? (
                        <button type="button" onClick={() => setEscolhendo(true)} data-acao="adicionar-categoria-do-modelo"
                            className="inline-flex items-center gap-1.5 text-[12px] text-ecf-yellow hover:underline">
                            <Plus size={13} /> Adicionar outra categoria
                        </button>
                    )
                    : <p className="text-[12px] text-white/45">Cabem até {MAX_CATEGORIAS} categorias por arquivo.</p>
            )}

            {erro && <p role="alert" className="text-[13px] text-red-300" data-erro-modelo-completo>{erro}</p>}

            <div className="flex justify-end">
                <Botao variante="primario" onClick={baixar} disabled={categorias.length === 0 || baixando} data-acao="baixar-modelo-completo">
                    {baixando ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />} {baixando ? 'Montando a planilha…' : textoDoBaixar(categorias)}
                </Botao>
            </div>
        </div>
    );
}
