import { useRef, useState } from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import FotosDoPar from '@/Components/Portal/Estrutura/FotosDoPar';
import { GERAL } from './apoio';

// ─── Fotos por grupo (E6, `06`) ─────────────────────────────────────────────
//
// Uma coluna para a galeria geral e uma para cada valor do eixo que define a
// foto (Cor, Estampa…). A foto solta numa coluna entra NELA; dentro de cada
// coluna a ordem é a do anúncio (a 1ª é a capa), com o mesmo arrastar do par.
// Quem decide a lista final de cada variante (grupo + geral, limites) é o
// servidor (`ResolvedorGruposImagem`).
//
// Tirar a foto de um grupo só a tira dali; tirada do último lugar onde estava,
// ela é apagada (não sobra foto solta sem uso).

export default function FotosPorGrupo({ imagens, atribuicoes, grupos, maxFotos = 10, enviando, disabled, opcoes, onArquivos, onAtribuicoes, onExcluir, onReenviar, onOpcao }) {
    const arquivo = useRef(null);
    const [destino, setDestino] = useState(GERAL);
    const porId = Object.fromEntries(imagens.map((i) => [String(i.id), i]));

    const doGrupo = (grupo) => atribuicoes
        .filter((a) => a.grupo === grupo)
        .sort((a, b) => a.posicao - b.posicao)
        .map((a) => ({ id: String(a.imagem), url: porId[String(a.imagem)]?.url ?? null }));

    const reordenar = (grupo, lista) => onAtribuicoes([
        ...atribuicoes.filter((a) => a.grupo !== grupo),
        ...lista.map((f, i) => ({ imagem: f.id, grupo, posicao: i })),
    ]);

    const remover = (grupo, indice) => {
        const id = doGrupo(grupo)[indice]?.id;
        if (! id) return;
        const restantes = atribuicoes.filter((a) => ! (a.grupo === grupo && String(a.imagem) === id));
        if (restantes.some((a) => String(a.imagem) === id)) {
            reordenar(grupo, doGrupo(grupo).filter((f) => f.id !== id));
        } else {
            onExcluir(id);
        }
    };

    const colunas = [{ chave: GERAL, rotulo: 'Geral', nota: 'vale para todas as variações' }, ...grupos.map((g) => ({ chave: g.chave, rotulo: g.rotulo, nota: `só ${g.rotulo}` }))];
    const falhas = imagens.filter((i) => i.upload_status !== 'uploaded');

    return (
        <div className="space-y-3" data-fotos-por-grupo={colunas.length}>
            {falhas.length > 0 && (
                <div className="space-y-1 rounded-xl border border-amber-500/25 bg-amber-500/[0.06] p-3 text-[12.5px] text-amber-200" data-fotos-falhas={falhas.length}>
                    {falhas.map((f) => (
                        <p key={f.id} className="flex flex-wrap items-center gap-2">
                            <AlertTriangle size={13} className="shrink-0" /> Foto {f.id}: {f.erro ?? 'ainda não subiu para o Mercado Livre.'}
                            {! disabled && <Botao variante="fantasma" className="py-1" onClick={() => onReenviar(f.id)}><RefreshCw size={12} /> enviar de novo</Botao>}
                        </p>
                    ))}
                </div>
            )}

            {colunas.map((c) => (
                <section key={c.chave} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-grupo-foto={c.chave}>
                    <h4 className="mb-2 text-[13px] font-semibold text-white">{c.rotulo} <span className="text-[11.5px] font-normal text-white/40">· {c.nota}</span></h4>
                    <FotosDoPar fotos={doGrupo(c.chave)} editavel={! disabled} maxFotos={maxFotos} enviando={enviando === c.chave}
                        onReordenar={(lista) => reordenar(c.chave, lista)} onRemover={(i) => remover(c.chave, i)}
                        onAdicionar={() => { setDestino(c.chave); arquivo.current?.click(); }} onArquivos={(arquivos) => onArquivos(arquivos, c.chave)} />
                </section>
            ))}

            <input ref={arquivo} type="file" accept="image/jpeg,image/png" multiple className="hidden" data-campo="fotos"
                onChange={(e) => { onArquivos([...e.target.files], destino); e.target.value = ''; }} />

            <div className="flex flex-wrap gap-x-5 gap-y-2 text-[12.5px] text-white/65">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={opcoes.incluir_geral} disabled={disabled} onChange={(e) => onOpcao({ incluir_geral: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" data-opcao="incluir-geral" />
                    Repetir as fotos gerais no fim de cada variação
                </label>
                <label className="flex items-center gap-2" title="Uma coluna por combinação, em vez de uma por valor que define a foto">
                    <input type="checkbox" checked={opcoes.fotos_por_variante} disabled={disabled} onChange={(e) => onOpcao({ fotos_por_variante: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" data-opcao="fotos-por-variante" />
                    Fotos diferentes para cada combinação
                </label>
            </div>
            <p className="text-[11.5px] text-white/35">JPG ou PNG, até 10 MB, com pelo menos 500 px (ideal 1200 px). Cada foto sobe para o Mercado Livre na hora.</p>
        </div>
    );
}
