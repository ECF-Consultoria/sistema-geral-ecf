import { useState } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { ATUALIZANDO, ATUALIZAR, TITULO_SAUDE, linhasDaSaude } from './acoes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 07 — "Saúde da conta" (VG-13).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — linhas MEDIDAS:
//   · card            → :235  (cabeçalho :236, H2 :237, status geral :238)
//   · linha           → :240  (tile :241, coluna :242, nome :243, meta :244)
//   · "Atualizar"     → :246  (spinner :247)
//   · pílula da linha → :250
//   · a aritmética    → :528-:540 (`health`, `okRow`, `healthIssue`)
//
// ⚠️ O ERP **NÃO é integração neste sistema**. Ele é DECLARADO (ficha de
// onboarding ou sync da planilha de Polos), e a Fase 177, plano 03, mediu e
// travou isso com asserção NEGATIVA no servidor (`assertArrayNotHasKey(
// 'conectado', $erp)`); o 179-03 repetiu (`problema` é sempre `false` na linha
// do ERP, mutações M04/M05). Por isso, nesta tela, a linha do ERP:
//   · diz `Declarado: X` ou `Não informado`, e **NUNCA `Conectado`**;
//   · **não** ganha dot nem pílula de status (o protótipo dá os dois, `:529`);
//   · **não** move o `geral` do card;
//   · **não** gera CTA "Reconectar ERP" — seria botão sem destino (EXMP-03).
// Quem for "consertar" isto: o dado não existe. Leia a 177-03-SUMMARY antes.
//
// ⚠️ A meta do ML **não inventa prazo**. O protótipo escreve *"Conta ECFDEV02 ·
// token renova em 4 h"* (`:528`); o contrato expõe só `estado`, e a 177-03
// travou a AUSÊNCIA de `renova_em`.
//
// ⚠️ "Atualizar" **nunca coleta no request**: a rota (`mlb.anuncios.meus.
// atualizar`, Fase 134) ENFILEIRA `SyncMlAcervoCompanyJob` (D-05/REG-06,
// T-179-34). A mensagem de retorno é a flash que a action já devolve; este
// bloco não a duplica.
//
// ⚠️ TODA classe do Tailwind mora NESTE arquivo (o `content` do
// `tailwind.config.js:12` não varre `*.js`).
// ⚠️ REND-04: o único `.map()` deste arquivo lê só o próprio parâmetro, os
// mapas de módulo abaixo e os `setState`.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Cor do rótulo E do dot do status geral (`:238`, `:540`).
 *
 * ⚠️ MEDIDO: o protótipo usa `{{ healthColor }}` nos DOIS (texto e dot), e
 * `healthColor` é a cor CLARA (`#4ADE80`/`#FBBF24`/`#F87171`). O `<action>` do
 * plano prescreveu o dot na tinta sólida (`bg-pub-ok`/`bg-pub-warn`/
 * `bg-pub-danger`); aqui o protótipo ganha.
 *
 * ⚠️ E isto NÃO se uniformiza com a pílula da linha (`:250`), que usa `h.color`
 * (clara) no texto e `h.dot` (sólida) no dot. São duas medidas diferentes no
 * mesmo card, de propósito.
 */
const COR_DO_GERAL = {
    tudo_conectado: 'text-pub-ok-txt',
    atencao: 'text-pub-warn-txt',
    requer_acao: 'text-pub-danger-txt',
};

const DOT_DO_GERAL = {
    tudo_conectado: 'bg-pub-ok-txt',
    atencao: 'bg-pub-warn-txt',
    requer_acao: 'bg-pub-danger-txt',
};

/** Pílula de status da linha do ML (`:250`, `:528`): texto claro, dot sólido. */
const COR_DO_ESTADO = {
    ativo: 'text-pub-ok-txt',
    expirado: 'text-pub-danger-txt',
    sem_token: 'text-pub-danger-txt',
};

const DOT_DO_ESTADO = {
    ativo: 'bg-pub-ok',
    expirado: 'bg-pub-danger',
    sem_token: 'bg-pub-danger',
};

/**
 * Tile da linha (`:241`). O do ML é amarelo sólido com texto escuro; os outros
 * são a divisória com o texto da Fase 1 (`:528-:529`).
 */
const TILE_DA_LINHA = {
    mercado_livre: 'bg-pub-accent text-pub-page',
    erp: 'bg-pub-line-subtle text-pub-fase1',
    sincronizacao: 'bg-pub-line-subtle text-pub-fase1',
};

/**
 * "Saúde da conta": o status geral e as três linhas (Mercado Livre, ERP
 * declarado e Sincronização), com o "Atualizar" que enfileira a coleta.
 *
 * @param {object} props
 * @param {unknown} props.saude  o payload de `CockpitDaContaService::saude()`
 */
export default function SaudeDaConta({ saude }) {
    const dados = linhasDaSaude(saude);
    const [atualizando, setAtualizando] = useState(false);

    return (
        <section className="overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface">
            <div className="flex items-center justify-between px-[18px] pb-[10px] pt-[16px]">
                <h2 className="m-0 text-[15px] font-semibold">{TITULO_SAUDE}</h2>
                {/* Sem `geral` medido não há pílula: "Tudo conectado" por omissão
                    seria afirmação sobre o mundo que ninguém mediu. */}
                {dados.geral !== null && (
                    <span
                        className={cn(
                            'flex items-center gap-[6px] text-[12px] font-medium',
                            COR_DO_GERAL[dados.geral],
                        )}
                    >
                        <span
                            aria-hidden="true"
                            className={cn('h-[7px] w-[7px] rounded-full', DOT_DO_GERAL[dados.geral])}
                        />
                        {dados.rotulo_geral}
                    </span>
                )}
            </div>

            {dados.linhas.map((linha) => (
                <div
                    key={linha.chave}
                    className={cn(
                        'flex items-center gap-[12px] border-t border-pub-line-subtle-2 px-[18px] py-[11px]',
                        linha.problema && 'bg-pub-danger/[0.07]',
                        linha.atencao && 'bg-pub-warn/[0.07]',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'grid h-[32px] w-[32px] flex-none place-items-center rounded-[8px] text-[10.5px] font-bold',
                            TILE_DA_LINHA[linha.chave],
                        )}
                    >
                        {linha.tile}
                    </span>

                    <span className="flex min-w-0 flex-1 flex-col gap-[2px]">
                        <span className="text-[13px] font-medium">{linha.nome}</span>
                        {linha.meta !== null && (
                            <span className="truncate text-[11.5px] text-pub-txt-3">{linha.meta}</span>
                        )}
                    </span>

                    {/* "Atualizar" só nasce com `atualizar_url` (T-179-34): botão
                        sem destino é proibido, e sem Company não há coleta. */}
                    {linha.atualizar_url !== null && (
                        <button
                            type="button"
                            disabled={atualizando}
                            onClick={() =>
                                router.post(
                                    linha.atualizar_url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setAtualizando(true),
                                        onFinish: () => setAtualizando(false),
                                    },
                                )
                            }
                            className="flex h-[28px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-pub-control-sm border border-pub-line-strong bg-pub-surface px-[10px] text-[12px] text-pub-fase1 hover:border-pub-line-off"
                        >
                            {atualizando && (
                                <span
                                    aria-hidden="true"
                                    className="h-[11px] w-[11px] animate-pub-spin rounded-full border-[1.5px] border-pub-line-off border-t-pub-accent"
                                />
                            )}
                            {atualizando ? ATUALIZANDO : ATUALIZAR}
                        </button>
                    )}

                    {/* ⚠️ Pílula de status SÓ na linha do ML (`rotulo_estado` só
                        existe lá). O protótipo a dá também ao ERP, com
                        "Conectado" — e é exatamente isso que o ERP não é. */}
                    {linha.rotulo_estado !== null && (
                        <span
                            className={cn(
                                'flex items-center gap-[6px] text-[12px] font-medium',
                                COR_DO_ESTADO[linha.estado],
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn('h-[7px] w-[7px] rounded-full', DOT_DO_ESTADO[linha.estado])}
                            />
                            {linha.rotulo_estado}
                        </span>
                    )}
                </div>
            ))}
        </section>
    );
}
