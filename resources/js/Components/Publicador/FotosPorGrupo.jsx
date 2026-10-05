import { useContext, useEffect, useId, useMemo, useRef } from 'react';
import { AlertTriangle, Lock, RefreshCw, Sparkles } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import FotosDoPar from '@/Components/Portal/Estrutura/FotosDoPar';
import { GERAL } from './apoio';
import { ErroDoCampo, LINK } from './Mesa/comum';
import PainelCriativos from './Mesa/PainelCriativos';
import { CriativosDoPublicador } from './useCriativosDoPublicador';
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
 * O bloco de fotos de UM grupo. `erro` = a mensagem depois do "Continuar" (borda vermelha; antes
 * disso o bloco vazio fica neutro); `minimo` = recomendado pela categoria (só o texto da contagem).
 *
 * Fase 165 (165-07): a IA mora AQUI porque o bloco está em todo lugar onde se cuida de foto — o
 * cartão de cada variação e "Fotos para todas as variações" — sem precisar mudar nenhum dos dois.
 * O contexto `CriativosDoPublicador` vem da página (Editor.jsx); sem ele (fora do editor, ou chave
 * desligada) nada aparece. `useId()` distingue a instância: duas variações que dividem o mesmo
 * grupo de fotos montam cada uma o próprio bloco, e só uma delas mostra o painel por vez
 * (`reivindicar`, depois de um F5). O painel não se desmonta com o editor só-leitura — a releitura
 * do rascunho depois de "Usar no anúncio" deixa `disabled` true por um instante, mas o painel
 * continua montado (só as ações ficam desabilitadas).
 */
export function BlocoDeFotos({
    grupo, titulo, nota = null, imagens, atribuicoes, maxFotos = 10, minimo = null, erro = null, enviando, disabled,
    envioAoMl = true, onArquivos, onAtribuicoes, onExcluir, onReenviar, children,
}) {
    const arquivo = useRef(null);
    // `useMemo`: sem isto, `fotos`/`sugeridas` eram arrays NOVOS a cada render (mesmo sem mudar
    // conteúdo) e o `useEffect([c.fase, sugeridas])` do PainelCriativos (Mesa/PainelCriativos.jsx)
    // reabria toda vez que QUALQUER coisa não relacionada reenderizava a página (o contexto
    // `CriativosDoPublicador` reconstrói um objeto novo a cada render de quem chama o hook) —
    // apagando em silêncio o que a pessoa tinha marcado/desmarcado no painel (261005-si3).
    const fotos = useMemo(() => fotosDoGrupo(imagens, atribuicoes, grupo), [imagens, atribuicoes, grupo]);
    const falhas = falhasDe(imagens, envioAoMl).filter((f) => (atribuicoes ?? []).some((a) => a.grupo === grupo && String(a.imagem) === String(f.id)));

    const criativos = useContext(CriativosDoPublicador);
    const instancia = useId();
    const visivel = !! criativos?.disponivel;
    const aberto = visivel && criativos.alvo?.grupo === grupo && criativos.alvo.instancia === instancia;

    useEffect(() => {
        if (criativos?.alvo?.grupo === grupo && criativos.alvo.instancia === null) criativos.reivindicar(grupo, instancia);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [criativos?.alvo?.grupo, criativos?.alvo?.instancia, grupo, instancia]);

    // As fotos deste bloco que já têm arquivo guardado; sem nenhuma, cai para a galeria geral.
    const comArquivo = (lista) => lista.filter((f) => (imagens ?? []).find((i) => String(i.id) === String(f.id))?.tem_arquivo);
    const sugeridas = useMemo(() => {
        const doProprioBloco = comArquivo(fotos);

        return (doProprioBloco.length > 0 ? doProprioBloco : comArquivo(fotosDoGrupo(imagens, atribuicoes, GERAL))).slice(0, 14);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fotos, imagens, atribuicoes]);

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
        <>
            <section id={`fotos-${grupo}`} className={cn('scroll-mt-24 rounded-lg border p-4', erro ? 'border-red-400 bg-red-500/[0.04]' : 'border-white/20 bg-black/40')}
                data-grupo-foto={grupo} data-fotos-no-grupo={fotos.length} aria-invalid={erro ? true : undefined}>
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h5 className="text-[13px] font-bold text-white/90">
                        {titulo}
                        {nota && <span className="ml-1 font-normal text-white/50">· {nota}</span>}
                    </h5>
                    <div className="flex items-center gap-3">
                        {visivel && (
                            <button type="button" data-gerar-com-ia={grupo} aria-expanded={aberto} disabled={disabled} className={LINK}
                                onClick={() => (aberto ? criativos.fechar() : criativos.abrir(grupo, titulo, instancia))}>
                                <Sparkles size={14} aria-hidden="true" /> Gerar com IA
                            </button>
                        )}
                        <span className="text-[13px] tabular-nums text-white/45">{fotos.length} de até {maxFotos}{minimo ? ` · recomendado ${minimo} ou mais` : ''}</span>
                    </div>
                </div>
                <FotosDoPar mesa fotos={fotos} editavel={! disabled} maxFotos={maxFotos} enviando={enviando === grupo}
                    onReordenar={reordenar} onRemover={remover}
                    onAdicionar={() => arquivo.current?.click()} onArquivos={(lista) => onArquivos(lista, grupo)} />
                {falhas.length > 0 && <div className="mt-3 space-y-1">{falhas.map((f) => <Falha key={f.id} f={f} disabled={disabled} onReenviar={onReenviar} />)}</div>}
                <ErroDoCampo>{erro}</ErroDoCampo>
                {children}
                <input ref={arquivo} type="file" accept="image/jpeg,image/png" multiple className="hidden" data-campo="fotos" data-campo-fotos={grupo}
                    onChange={(e) => { onArquivos([...e.target.files], grupo); e.target.value = ''; }} />
            </section>
            {aberto && (
                <PainelCriativos c={criativos} titulo={titulo} sugeridas={sugeridas} fotosNoGrupo={fotos.length} maxFotos={maxFotos} disabled={disabled} />
            )}
        </>
    );
}
