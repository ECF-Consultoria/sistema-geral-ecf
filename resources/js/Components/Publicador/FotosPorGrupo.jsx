import { useRef, useState } from 'react';
import { AlertTriangle, Lock, RefreshCw } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import FotosDoPar from '@/Components/Portal/Estrutura/FotosDoPar';
import { GERAL } from './apoio';

// ─── Fotos por grupo (E6, `06`) ─────────────────────────────────────────────
//
// Um bloco para a galeria geral e um para cada valor do eixo que define a
// foto (Cor, Estampa…). A foto solta num bloco entra NELE; dentro de cada
// bloco a ordem é a do anúncio (a 1ª é a capa), com o mesmo arrastar do par.
// Quem decide a lista final de cada variante (grupo + geral, limites) é o
// servidor (`ResolvedorGruposImagem`).
//
// Tirar a foto de um grupo só a tira dali; tirada do último lugar onde estava,
// ela é apagada (não sobra foto solta sem uso).
//
// `envioAoMl` (D26): em conta ainda não liberada a foto fica guardada aqui e
// NÃO sobe ao Mercado Livre. A pendência então não é falha: aparece uma nota
// neutra, sem "enviar de novo". Falha real (com `erro`) continua avisada.
// O editor do Portal não passa a prop e segue como era (padrão true).

export default function FotosPorGrupo({ imagens, atribuicoes, grupos, maxFotos = 10, enviando, disabled, opcoes, onArquivos, onAtribuicoes, onExcluir, onReenviar, onOpcao, envioAoMl = true }) {
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

    // Falha = não subiu E (há erro OU a conta envia ao ML). Pendente sem erro em conta não liberada é só "guardada".
    const naoSubiu = imagens.filter((i) => i.upload_status !== 'uploaded');
    const falhas = naoSubiu.filter((i) => i.erro || envioAoMl);
    const guardadas = envioAoMl ? [] : naoSubiu.filter((i) => ! i.erro && i.upload_status === 'pending');
    const falhasDoGrupo = (grupo) => falhas.filter((f) => atribuicoes.some((a) => a.grupo === grupo && String(a.imagem) === String(f.id)));
    const falhasSoltas = falhas.filter((f) => ! atribuicoes.some((a) => String(a.imagem) === String(f.id)));

    const Falha = ({ f }) => (
        <p className="flex flex-wrap items-center gap-2 text-[13px] text-amber-200" data-foto-falha={f.id}>
            <AlertTriangle size={14} className="shrink-0" /> Foto {f.id}: {f.erro ?? 'ainda não subiu para o Mercado Livre.'}
            {! disabled && <Botao variante="fantasma" className="py-1" onClick={() => onReenviar(f.id)}><RefreshCw size={12} /> enviar de novo</Botao>}
        </p>
    );

    return (
        <div className="space-y-4" data-fotos-por-grupo={colunas.length}>
            {guardadas.length > 0 && (
                <p className="flex items-start gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] text-white/55" data-fotos-guardadas={guardadas.length}>
                    <Lock size={14} className="mt-0.5 shrink-0" /> As fotos ficam guardadas aqui e sobem para o Mercado Livre quando a publicação for liberada para esta conta.
                </p>
            )}
            {falhasSoltas.length > 0 && (
                <div className="space-y-1 rounded-xl border border-amber-500/25 bg-amber-500/[0.06] p-3" data-fotos-falhas={falhasSoltas.length}>
                    {falhasSoltas.map((f) => <Falha key={f.id} f={f} />)}
                </div>
            )}

            {colunas.map((c) => (
                <section key={c.chave} id={`fotos-${c.chave}`} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4" data-grupo-foto={c.chave}>
                    <h4 className="mb-3 text-[13px] font-bold text-white">{c.rotulo} <span className="font-normal text-white/40">· {c.nota}</span></h4>
                    <FotosDoPar mesa fotos={doGrupo(c.chave)} editavel={! disabled} maxFotos={maxFotos} enviando={enviando === c.chave}
                        onReordenar={(lista) => reordenar(c.chave, lista)} onRemover={(i) => remover(c.chave, i)}
                        onAdicionar={() => { setDestino(c.chave); arquivo.current?.click(); }} onArquivos={(arquivos) => onArquivos(arquivos, c.chave)} />
                    {falhasDoGrupo(c.chave).length > 0 && (
                        <div className="mt-3 space-y-1">{falhasDoGrupo(c.chave).map((f) => <Falha key={f.id} f={f} />)}</div>
                    )}
                </section>
            ))}

            <input ref={arquivo} type="file" accept="image/jpeg,image/png" multiple className="hidden" data-campo="fotos"
                onChange={(e) => { onArquivos([...e.target.files], destino); e.target.value = ''; }} />

            <div className="flex flex-wrap gap-x-5 gap-y-2 text-[13px] text-white/55">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={opcoes.incluir_geral} disabled={disabled} onChange={(e) => onOpcao({ incluir_geral: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" data-opcao="incluir-geral" />
                    Repetir as fotos gerais no fim de cada variação
                </label>
                <label className="flex items-center gap-2" title="Um bloco por combinação, em vez de um por valor que define a foto">
                    <input type="checkbox" checked={opcoes.fotos_por_variante} disabled={disabled} onChange={(e) => onOpcao({ fotos_por_variante: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" data-opcao="fotos-por-variante" />
                    Fotos diferentes para cada combinação
                </label>
            </div>
            <p className="text-[11px] text-white/40">JPG ou PNG, até 10 MB, com pelo menos 500 px (ideal 1200 px). {envioAoMl ? 'Cada foto sobe para o Mercado Livre na hora.' : 'Cada foto é guardada na hora.'}</p>
        </div>
    );
}
