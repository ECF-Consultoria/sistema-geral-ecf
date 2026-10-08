import FotosEVariacoes from './FotosEVariacoes';
import IdentidadeDaConta from './IdentidadeDaConta';

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
//
// Fase 170 (D2, IDENT-01/04, 07/10/2026): `IdentidadeDaConta` entra UMA VEZ,
// acima de "Fotos e variações" — é da CONTA do produto (não deste produto
// só), por isso fica fora de `FotosEVariacoes`/`PainelCriativos` (que
// remontam por grupo/variação e duplicariam o campo na tela).

export default function EtapaImagens({ m, produtoId }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="imagens">
            <IdentidadeDaConta produtoId={produtoId} />
            <FotosEVariacoes m={m} />
        </div>
    );
}
