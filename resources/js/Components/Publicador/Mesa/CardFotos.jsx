import { Images } from 'lucide-react';
import FotosPorGrupo from '../FotosPorGrupo';
import { estadoDasSecoes } from '../apoio';
import { CardMesa, ChipSecao } from './comum';

// ─── Card 4 — Fotos (check "Fotos") ─────────────────────────────────────────
//
// Os grupos seguem o eixo que define a foto (não necessariamente a cor); o
// servidor entrega a chave e o rótulo em `grupos_imagem`, e "Geral" é a galeria
// de todas as variações. A regra de tamanho vem de `schema.limites`, nunca fixa.

/** "Fundo branco, {largura}×{altura}px no mínimo" — os números só aparecem se o schema os trouxer. */
const regraDaFoto = (limites) => {
    const largura = limites?.min_picture_width ?? limites?.minimum_picture_width;
    const altura = limites?.min_picture_height ?? limites?.minimum_picture_height;

    return largura && altura ? `Fundo branco, ${largura}×${altura}px no mínimo` : 'Fundo branco';
};

export default function CardFotos({ m, aberto = true, onAlternar }) {
    const { estado, schema } = m;
    const faltam = estadoDasSecoes(m.problemasDaSecao('fotos'), schema).fotos.faltam;
    const limites = schema?.limites;

    return (
        <CardMesa id="card-fotos" icone={Images} titulo="Fotos" apoio={regraDaFoto(limites)} chip={<ChipSecao faltam={faltam} />} aberto={aberto} onAlternar={onAlternar}>
            <FotosPorGrupo imagens={estado.imagens} atribuicoes={estado.atribuicoes} grupos={estado.grupos_imagem}
                maxFotos={limites?.max_pictures_per_item_var ?? limites?.max_pictures_per_item ?? 10}
                enviando={m.enviandoFoto} disabled={m.disabled}
                opcoes={{ incluir_geral: estado.rascunho.incluir_geral, fotos_por_variante: estado.rascunho.fotos_por_variante }}
                onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos}
                onExcluir={m.removerFoto} onReenviar={m.reenviarFoto}
                onOpcao={(o) => m.mudarRasc(o)}
                envioAoMl={estado.publicacao_liberada === true} />
        </CardMesa>
    );
}
