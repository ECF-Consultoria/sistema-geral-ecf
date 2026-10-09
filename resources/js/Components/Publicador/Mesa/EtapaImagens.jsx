import { Link } from '@inertiajs/react';
import AcervoDaConta from './AcervoDaConta';
import FotosEVariacoes from './FotosEVariacoes';
import IdentidadeDaConta from './IdentidadeDaConta';
import { LINK } from './comum';

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
//
// Fase 171 (D4, ACERVO-01..05, 08/10/2026): `AcervoDaConta` entra DEPOIS de
// "Fotos e variações" — "aqui estão as suas fotos de hoje; aqui está o que
// você já gerou antes e pode reaproveitar". Também montado UMA VEZ, pelo
// mesmo motivo de `IdentidadeDaConta`.

// Fase 173, plano 07 (08/10/2026): a identidade visual da conta também ganhou
// lugar próprio em "Configurações da conta" — os dois lugares leem e gravam
// o MESMO registro. Único acréscimo nesta etapa: o link para lá, logo acima
// do campo. `empresa` chega como prop nova (de `Editor.jsx`, mesmo objeto que
// a página já usa para outras rotas por conta) só para montar essa rota.

export default function EtapaImagens({ m, produtoId, empresa }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="imagens">
            {empresa?.chave && (
                <div className="flex justify-end">
                    <Link href={route('mlb.anuncios.publicador.configuracoes', { conta: empresa.chave })} className={LINK}>
                        Gerenciar em Configurações da conta
                    </Link>
                </div>
            )}
            <IdentidadeDaConta produtoId={produtoId} />
            <FotosEVariacoes m={m} />
            <AcervoDaConta m={m} produtoId={produtoId} />
        </div>
    );
}
