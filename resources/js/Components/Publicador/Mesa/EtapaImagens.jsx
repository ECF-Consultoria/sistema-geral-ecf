import FotosEVariacoes from './FotosEVariacoes';

// ─── Etapa 3 — Imagens, SÓ imagens (D1, Fase 169, 07/10/2026) ───────────────
//
// "Fotos e variações" saiu de Detalhes e ganhou etapa própria: a causa raiz
// medida era `EtapaDetalhes` renderizar `FotosEVariacoes` ANTES da ficha
// técnica/descrição — o operador gerava imagens sem ter preenchido nenhum
// fato do produto, e por isso o Creative Engine (168/169-01/02) planejava
// slots `SEM_FATO` mesmo com dados já cadastrados (só que ainda não digitados).
// Produto → Detalhes → Imagens → Condições de venda: o fato primeiro, a
// imagem depois.
//
// Correção (D1, mesma data): ao sair de Detalhes, `FotosEVariacoes` tinha
// levado junto estoque/SKU/código/AGID/MPN (o cartão da variação era misto,
// fotos + dados) — regressão relatada pelo usuário em produção. Desde então
// `FotosEVariacoes` é fotos-apenas (fotos gerais e de cada variação via
// `CartaoFotosVariante`); os dados da variação voltaram para Detalhes, como
// primeira seção (`DadosDasVariacoes.jsx`). Grupos de fotos, EAN automático,
// painel de criativos por IA e o bloco de pontos fortes/medidas da 169-03
// continuam funcionando aqui — nada disso mudou, só o que é "campo" saiu.

export default function EtapaImagens({ m }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="imagens">
            <FotosEVariacoes m={m} />
        </div>
    );
}
