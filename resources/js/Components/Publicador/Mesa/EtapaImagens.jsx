import FotosEVariacoes from './FotosEVariacoes';

// ─── Etapa 3 — Imagens (D1, Fase 169, 07/10/2026) ───────────────────────────
//
// "Fotos e variações" saiu de Detalhes e ganhou etapa própria: a causa raiz
// medida era `EtapaDetalhes` renderizar `FotosEVariacoes` ANTES da ficha
// técnica/descrição — o operador gerava imagens sem ter preenchido nenhum
// fato do produto, e por isso o Creative Engine (168/169-01/02) planejava
// slots `SEM_FATO` mesmo com dados já cadastrados (só que ainda não digitados).
// Produto → Detalhes → Imagens → Condições de venda: o fato primeiro, a
// imagem depois.
//
// `FotosEVariacoes` é o MESMO componente de antes, só mudou de etapa — nenhum
// comportamento dele muda aqui (grupos de fotos, variações, EAN automático,
// painel de criativos por IA e o bloco de pontos fortes/medidas da 169-03
// continuam exatamente como eram em Detalhes).

export default function EtapaImagens({ m }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="imagens">
            <FotosEVariacoes m={m} />
        </div>
    );
}
