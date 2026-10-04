import { AvisosDasFotos, BlocoDeFotos } from '../FotosPorGrupo';
import { GERAL } from '../apoio';
import { regraDaFoto } from './CardVariacoes';

// ─── Item "Fotos": a galeria geral e a regra das fotos ──────────────────────
//
// As fotos de cada variação moram no item da variação (pedido do cliente:
// "variações e fotos juntas"). Aqui ficam as fotos para TODAS as variações
// (galeria geral), a opção de pô-las no fim das fotos de cada variação e a
// regra da categoria. No produto sem variação a galeria geral É a dele.

export default function CardFotos({ m }) {
    const { estado, schema } = m;
    const limites = schema?.limites ?? {};
    const eixos = estado.eixos ?? [];
    const temVariacoes = eixos.some((e) => e.valores.length > 0);

    if (! schema) return <p className="text-[13px] text-white/55">Escolha a categoria para definir as fotos.</p>;

    return (
        <div className="space-y-4">
            <p className="text-[13px] text-white/55" data-regra-da-foto>
                Regra da categoria: {regraDaFoto(limites)}; até {limites.max_pictures_per_item ?? 10} por anúncio{limites.min_pictures ?? limites.recommended_pictures ? `, recomendado ${limites.min_pictures ?? limites.recommended_pictures} ou mais` : ''}. A 1ª foto é a capa.
            </p>

            <AvisosDasFotos imagens={estado.imagens} atribuicoes={estado.atribuicoes} envioAoMl={estado.publicacao_liberada === true} disabled={m.disabled} onReenviar={m.reenviarFoto} />

            <BlocoDeFotos grupo={GERAL} titulo={temVariacoes ? 'Fotos para todas as variações' : 'Fotos do produto'} nota={temVariacoes ? 'valem para todas' : null}
                obrigatorio={! temVariacoes}
                imagens={estado.imagens} atribuicoes={estado.atribuicoes} maxFotos={limites.max_pictures_per_item ?? 10}
                minimo={temVariacoes ? null : (limites.min_pictures ?? limites.recommended_pictures ?? null)}
                enviando={m.enviandoFoto} disabled={m.disabled} envioAoMl={estado.publicacao_liberada === true}
                onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos} onExcluir={m.removerFoto} onReenviar={m.reenviarFoto}>
                {temVariacoes && (
                    <label className="mt-3 flex items-center gap-2 text-[13px] text-white/55">
                        <input type="checkbox" checked={!! (m.rasc?.incluir_geral ?? estado.rascunho.incluir_geral)} disabled={m.disabled}
                            onChange={(e) => m.mudarRasc({ incluir_geral: e.target.checked })}
                            className="rounded border-white/20 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-opcao="incluir-geral" />
                        Colocar estas fotos no fim das fotos de cada variação
                    </label>
                )}
            </BlocoDeFotos>

            {temVariacoes && <p className="text-[13px] text-white/55">As fotos de cada cor ficam no item da variação, em "Variações".</p>}
        </div>
    );
}
