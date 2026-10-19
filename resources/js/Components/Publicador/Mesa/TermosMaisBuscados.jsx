import { useEffect } from 'react';
import TermosDaCategoria from '@/Components/Titulo/TermosDaCategoria';
import { NOME_TIPO } from '../apoio';
import { cn } from '@/lib/utils';

// ─── Termos mais buscados da categoria, para montar o título (docx §3) ──────
//
// O painel é o mesmo do cadastro do produto no Portal (`TermosDaCategoria`, 19/10/2026):
// todos os termos à vista (o filtro dos que citam o produto começa desligado), o chip
// marcado pelo próprio texto do título e o clique que acrescenta ou tira o termo.
// Aqui ficam só o que é do editor: a leitura pela rota do rascunho, a escolha de qual
// título (Clássico ou Premium) recebe o termo e os textos que citam o Mercado Livre.

const TEXTOS = {
    carregando: 'Buscando os termos no Mercado Livre…',
    vazio: 'O Mercado Livre não tem termos em alta para esta categoria agora.',
    rodape: 'Lista da semana no Mercado Livre, por posição. Confira se o termo descreve este produto antes de usar — vem com marca de concorrente e produto parecido.',
};

export default function TermosMaisBuscados({ m, alvos, destino, onDestino, onAlternar, limite }) {
    const categoria = m.estado.rascunho?.categoria_id ?? null;
    const { dados, carregando, erro } = m.termos ?? {};

    // Uma leitura por categoria: trocar a categoria relê (o título fica como está).
    useEffect(() => {
        if (categoria && m.termos?.categoria !== categoria && ! m.termos?.carregando) m.carregarTermos();
    }, [categoria]); // eslint-disable-line react-hooks/exhaustive-deps

    const tituloDestino = alvos.find((a) => a.listing_type_id === destino);
    const textoDestino = tituloDestino ? (tituloDestino.titulo || tituloDestino.titulo_efetivo || '') : '';

    const escolhaDoTipo = alvos.length > 1 && (
        <div role="radiogroup" aria-label="Acrescentar no título do" className="inline-flex items-center gap-1 rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-1 text-[11px]" data-destino-termos>
            <span className="px-1.5 text-white/40">Acrescentar no</span>
            {alvos.map((a) => (
                <button key={a.listing_type_id} type="button" role="radio" aria-checked={destino === a.listing_type_id} onClick={() => onDestino(a.listing_type_id)}
                    className={cn('rounded-lg border px-2 py-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                        destino === a.listing_type_id ? 'border-ecf-yellow/40 bg-ecf-yellow/10 font-bold text-ecf-yellow' : 'border-transparent text-white/55 hover:text-white')}>
                    {NOME_TIPO[a.listing_type_id]}
                </button>
            ))}
        </div>
    );

    return (
        <TermosDaCategoria dados={dados} carregando={carregando} erro={erro} onTentarDeNovo={() => m.carregarTermos()}
            titulo={textoDestino} limite={limite} onAlternar={onAlternar} desabilitado={m.disabled || ! tituloDestino?.ativo}
            textos={TEXTOS} cabecalho={escolhaDoTipo || null} />
    );
}
