import { BlocoDeFotos, fotosDoGrupo } from '../FotosPorGrupo';
import { CabecalhoVariante, corDaVariante } from './CartaoVariante';
import { useErroDoCampo } from './comum';
import { cn } from '@/lib/utils';

// ─── As FOTOS de uma variação (etapa Imagens, D1, Fase 169, 07/10/2026) ─────
//
// SÓ fotos — nada de estoque, SKU, código ou atributo extra: isso é
// `CartaoVariante.jsx`, na etapa Detalhes (ver o comentário lá para a causa
// raiz da regressão que este arquivo corrige). `CabecalhoVariante` é o mesmo
// cabeçalho do cartão de dados (nome, cor, badge "publicada"), sem
// "Vender esta variação" nem "Tirar" — esses controles de gestão da variação
// ficam só em Detalhes.
//
// `grupo` = a chave do grupo de fotos da variação (nulo enquanto o servidor
// ainda não o deu); `fotosCom` = as outras variações que dividem as mesmas
// fotos (ex.: Preto/P e Preto/M).
//
// Os erros só aparecem depois do "Continuar" (ver `useErroDoCampo`).

export default function CartaoFotosVariante({ m, v, eixos, grupo = null, fotosCom = [] }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    const limites = schema?.limites ?? {};
    const semVariacao = Object.keys(v.valores ?? {}).length === 0;
    const fotos = grupo ? fotosDoGrupo(estado.imagens, estado.atribuicoes, grupo).length : 0;
    const cor = corDaVariante(v, eixos);
    const nome = semVariacao ? 'Produto' : eixos.filter((e) => v.valores?.[e.chave]).map((e) => `${e.nome}: ${v.valores[e.chave].nome}`).join(' · ');
    const ativa = v.ativa;

    // Só depois do "Continuar" (e só na variação que vai ao anúncio).
    const erroFotos = useErroDoCampo((x) => !! grupo && x.grupo === grupo, { vazio: ativa && !! grupo && fotos === 0 });

    return (
        <article className={cn('rounded-xl border p-5 max-sm:p-4', ativa ? 'border-white/[0.12] bg-white/[0.02]' : 'border-white/[0.08] bg-transparent')} data-cartao-fotos-variante={v.chave}>
            <CabecalhoVariante v={v} cor={cor} nome={nome} travada={travada} semVariacao={semVariacao} />

            {! ativa ? (
                <p className="text-[13px] text-white/45">Fora do anúncio. Vá em Detalhes e marque "Vender esta variação" antes de enviar as fotos.</p>
            ) : grupo ? (
                <BlocoDeFotos grupo={grupo} titulo={semVariacao ? 'Fotos do produto' : 'Fotos'} erro={erroFotos}
                    nota={fotosCom.length ? `as mesmas de ${fotosCom.join(', ')}` : null}
                    imagens={estado.imagens} atribuicoes={estado.atribuicoes}
                    maxFotos={limites.max_pictures_per_item_var ?? limites.max_pictures_per_item ?? 10}
                    minimo={limites.min_pictures ?? limites.recommended_pictures ?? null}
                    enviando={m.enviandoFoto} disabled={travada} envioAoMl={estado.publicacao_liberada === true}
                    onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos} onExcluir={m.removerFoto} onReenviar={m.reenviarFoto} />
            ) : (
                <p className="rounded-lg border border-white/20 bg-black/40 p-4 text-[13px] text-white/45" data-fotos-variante={v.chave}>Preparando as fotos desta variação…</p>
            )}
        </article>
    );
}
