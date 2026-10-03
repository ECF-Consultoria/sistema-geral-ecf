import { useRef } from 'react';
import { AlertTriangle, Lock, RefreshCw } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import FotosDoPar from '@/Components/Portal/Estrutura/FotosDoPar';
import { cn } from '@/lib/utils';

// ─── Fotos de um grupo (E6, `06`) ───────────────────────────────────────────
//
// Desde 03/10/2026 as fotos moram DENTRO de cada variação, como no Mercado
// Livre: cada cartão de variação mostra o bloco do grupo dela. O grupo é do
// servidor (`ResolvedorGruposImagem`): o valor do eixo que define a foto (as
// variações Preto/P e Preto/M dividem as fotos do Preto) ou a própria
// variação. A galeria geral (`GENERAL`) vale para todas.
//
// A foto solta num bloco entra NELE; dentro do bloco a ordem é a do anúncio
// (a 1ª é a capa), com o mesmo arrastar do par. Tirar a foto de um grupo só a
// tira dali; tirada do último lugar onde estava, ela é apagada.
//
// `envioAoMl` (D26): em conta ainda não liberada a foto fica guardada aqui e
// NÃO sobe ao Mercado Livre. A pendência então não é falha: aparece uma nota
// neutra, sem "enviar de novo". Falha real (com `erro`) continua avisada.

/** As fotos de um grupo, na ordem do anúncio. */
export const fotosDoGrupo = (imagens, atribuicoes, grupo) => {
    const porId = Object.fromEntries((imagens ?? []).map((i) => [String(i.id), i]));

    return (atribuicoes ?? [])
        .filter((a) => a.grupo === grupo)
        .sort((a, b) => a.posicao - b.posicao)
        .map((a) => ({ id: String(a.imagem), url: porId[String(a.imagem)]?.url ?? null }));
};

/** Falha = não subiu E (há erro OU a conta envia ao ML). Pendente sem erro em conta não liberada é só "guardada". */
const falhasDe = (imagens, envioAoMl) => (imagens ?? []).filter((i) => i.upload_status !== 'uploaded').filter((i) => i.erro || envioAoMl);

function Falha({ f, disabled, onReenviar }) {
    return (
        <p className="flex flex-wrap items-center gap-2 text-[13px] text-amber-200" data-foto-falha={f.id}>
            <AlertTriangle size={14} className="shrink-0" /> Foto {f.id}: {f.erro ?? 'ainda não subiu para o Mercado Livre.'}
            {! disabled && <Botao variante="fantasma" className="py-1" onClick={() => onReenviar(f.id)}><RefreshCw size={12} /> enviar de novo</Botao>}
        </p>
    );
}

/** Avisos que não são de um grupo: fotos guardadas (D26) e falhas de foto que não está em grupo nenhum. */
export function AvisosDasFotos({ imagens, atribuicoes, envioAoMl = true, disabled, onReenviar }) {
    const guardadas = envioAoMl ? [] : (imagens ?? []).filter((i) => i.upload_status === 'pending' && ! i.erro);
    const soltas = falhasDe(imagens, envioAoMl).filter((f) => ! (atribuicoes ?? []).some((a) => String(a.imagem) === String(f.id)));

    return (
        <>
            {guardadas.length > 0 && (
                <p className="flex items-start gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] text-white/55" data-fotos-guardadas={guardadas.length}>
                    <Lock size={14} className="mt-0.5 shrink-0" /> As fotos ficam guardadas aqui e sobem para o Mercado Livre quando a publicação for liberada para esta conta.
                </p>
            )}
            {soltas.length > 0 && (
                <div className="space-y-1 rounded-xl border border-amber-500/25 bg-amber-500/[0.06] p-3" data-fotos-falhas={soltas.length}>
                    {soltas.map((f) => <Falha key={f.id} f={f} disabled={disabled} onReenviar={onReenviar} />)}
                </div>
            )}
        </>
    );
}

/**
 * O bloco de fotos de UM grupo. `obrigatorio` = a variação precisa de foto própria (borda âmbar
 * vazia); `minimo` = recomendado pela categoria (só o texto da contagem).
 */
export function BlocoDeFotos({
    grupo, titulo, nota = null, imagens, atribuicoes, maxFotos = 10, minimo = null, obrigatorio = false, enviando, disabled,
    envioAoMl = true, onArquivos, onAtribuicoes, onExcluir, onReenviar, children,
}) {
    const arquivo = useRef(null);
    const fotos = fotosDoGrupo(imagens, atribuicoes, grupo);
    const falhas = falhasDe(imagens, envioAoMl).filter((f) => (atribuicoes ?? []).some((a) => a.grupo === grupo && String(a.imagem) === String(f.id)));

    const reordenar = (lista) => onAtribuicoes([
        ...(atribuicoes ?? []).filter((a) => a.grupo !== grupo),
        ...lista.map((f, i) => ({ imagem: f.id, grupo, posicao: i })),
    ]);
    const remover = (indice) => {
        const id = fotos[indice]?.id;
        if (! id) return;
        const restantes = (atribuicoes ?? []).filter((a) => ! (a.grupo === grupo && String(a.imagem) === id));
        if (restantes.some((a) => String(a.imagem) === id)) reordenar(fotos.filter((f) => f.id !== id));
        else onExcluir(id);
    };

    return (
        <section id={`fotos-${grupo}`} className={cn('scroll-mt-20 rounded-[10px] border bg-white/[0.02] p-3', obrigatorio && fotos.length === 0 ? 'border-amber-400/50' : 'border-white/[0.08]')}
            data-grupo-foto={grupo} data-fotos-no-grupo={fotos.length}>
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h5 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                    {titulo}{obrigatorio && fotos.length === 0 && <span className="ml-1 normal-case tracking-normal text-amber-300">(obrigatório)</span>}
                    {nota && <span className="ml-1 font-normal normal-case tracking-normal text-white/40">· {nota}</span>}
                </h5>
                <span className="font-mono text-[11px] tabular-nums text-white/40">{fotos.length}/{maxFotos}{minimo ? ` · recomendado ${minimo}+` : ''}</span>
            </div>
            <FotosDoPar mesa fotos={fotos} editavel={! disabled} maxFotos={maxFotos} enviando={enviando === grupo}
                onReordenar={reordenar} onRemover={remover}
                onAdicionar={() => arquivo.current?.click()} onArquivos={(lista) => onArquivos(lista, grupo)} />
            {falhas.length > 0 && <div className="mt-3 space-y-1">{falhas.map((f) => <Falha key={f.id} f={f} disabled={disabled} onReenviar={onReenviar} />)}</div>}
            {children}
            <input ref={arquivo} type="file" accept="image/jpeg,image/png" multiple className="hidden" data-campo="fotos" data-campo-fotos={grupo}
                onChange={(e) => { onArquivos([...e.target.files], grupo); e.target.value = ''; }} />
        </section>
    );
}
