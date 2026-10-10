import * as Popover from '@radix-ui/react-popover';
import { Check, Loader2 } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import PickerCategoria from '@/Components/Portal/Estrutura/Produtos/PickerCategoria';
import { sugestoesPendentes, textoDeProdutos } from '@/lib/categoriasDaImportacao';
import { cn } from '@/lib/utils';

// ─── Categorias a confirmar, na prévia da importação (09/10/2026) ───────────
//
// Um item por NOME de categoria digitado na planilha, com quantos produtos o
// usam. Para cada nome: a sugestão (ou "buscando…"), e a pessoa confirma ou
// escolhe outra no MESMO seletor da ficha. A escolha vale para todos os produtos
// daquele nome e só vai ao servidor com a confirmação da importação. Nada é
// aceito sozinho; o que ficar sem confirmar continua "a confirmar" no produto.
// Só nome e caminho aparecem: código de categoria nenhum vai para a tela.

const ACAO = 'inline-flex items-center rounded-lg px-2 py-1 text-[12px] font-medium text-white/70 transition-colors hover:bg-white/[0.06] hover:text-white disabled:pointer-events-none disabled:opacity-40';

function EscolherCategoria({ rotulo, texto, aberto, onAbrir, onFechar, onEscolher, desabilitado }) {
    return (
        <Popover.Root open={aberto} onOpenChange={(v) => (v ? onAbrir() : onFechar())}>
            <Popover.Trigger asChild>
                <button type="button" className={ACAO} disabled={desabilitado} data-acao="escolher-categoria">{rotulo}</button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content align="end" sideOffset={6} collisionPadding={12} className="z-[60]">
                    <PickerCategoria textoInicial={texto} onClose={onFechar}
                        onCommit={(c) => onEscolher({ id: c.categoria_ml_id, nome: c.categoria_ml_nome, caminho_texto: c.categoria_ml_caminho })} />
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

function Categoria({ nome, caminho, destaque = false }) {
    return (
        <span className="min-w-0">
            <span className={cn('font-semibold', destaque ? 'text-emerald-300' : 'text-white/85')}>{nome}</span>
            {caminho && <span className="ml-1.5 text-white/45">{caminho}</span>}
        </span>
    );
}

function ItemNome({ item, sugestao, escolha, escolhendo, onEscolhendo, onEscolher, desabilitado }) {
    const abrir = () => onEscolhendo(item.chave);
    const fechar = () => onEscolhendo(null);
    const escolher = (categoria) => { onEscolher(item.chave, categoria); fechar(); };
    const aberto = escolhendo === item.chave;

    return (
        <li className="rounded-lg border border-white/[0.06] bg-white/[0.015] px-3 py-2" data-nome-categoria={item.chave}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <p className="text-[13px] text-white/85">
                    “{item.texto}” <span className="text-white/40">· {textoDeProdutos(item.produtos)}</span>
                </p>
                {(item.exemplos ?? []).length > 0 && (
                    <p className="max-w-full truncate text-[12px] text-white/35">{item.exemplos.join(', ')}{item.produtos > item.exemplos.length ? '…' : ''}</p>
                )}
            </div>

            {escolha ? (
                <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-[12.5px]" data-escolhida>
                    <p className="flex min-w-0 items-start gap-1.5"><Check size={14} className="mt-0.5 shrink-0 text-emerald-300" aria-hidden="true" /><Categoria nome={escolha.nome} caminho={escolha.caminho_texto} destaque /></p>
                    <div className="flex shrink-0 gap-1">
                        <EscolherCategoria rotulo="Trocar" texto={item.texto} aberto={aberto} onAbrir={abrir} onFechar={fechar} onEscolher={escolher} desabilitado={desabilitado} />
                        <button type="button" className={ACAO} onClick={() => onEscolher(item.chave, null)} disabled={desabilitado} data-acao="desfazer-categoria">Desfazer</button>
                    </div>
                </div>
            ) : sugestao?.estado === 'buscando' ? (
                <p className="mt-1.5 flex items-center gap-1.5 text-[12px] text-white/45" role="status">
                    <Loader2 size={12} className="animate-spin" aria-hidden="true" /> Buscando sugestão…
                </p>
            ) : sugestao?.sugestao ? (
                <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-[12.5px]">
                    <p className="min-w-0"><span className="text-white/50">Sugestão: </span><Categoria nome={sugestao.sugestao.nome} caminho={sugestao.sugestao.caminho_texto} /></p>
                    <div className="flex shrink-0 gap-1">
                        <Botao variante="secundario" className="px-2.5 py-1 text-[12px]" onClick={() => onEscolher(item.chave, sugestao.sugestao)} disabled={desabilitado} data-acao="confirmar-categoria">
                            Confirmar
                        </Botao>
                        <EscolherCategoria rotulo="Escolher outra" texto={item.texto} aberto={aberto} onAbrir={abrir} onFechar={fechar} onEscolher={escolher} desabilitado={desabilitado} />
                    </div>
                </div>
            ) : (
                <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-[12px]">
                    <p className="text-white/45">{sugestao?.estado === 'sem' ? 'Sem sugestão para este nome.' : 'Escolha a categoria deste nome, se quiser.'}</p>
                    <EscolherCategoria rotulo="Escolher categoria" texto={item.texto} aberto={aberto} onAbrir={abrir} onFechar={fechar} onEscolher={escolher} desabilitado={desabilitado} />
                </div>
            )}
        </li>
    );
}

export default function CategoriasAConfirmar({ bloco, sugestoes = {}, escolhas = {}, indisponivel = false, escolhendo = null, onEscolhendo, onEscolher, desabilitado = false }) {
    const nomes = bloco?.nomes ?? [];
    if (nomes.length === 0) return null;

    const pendentes = sugestoesPendentes(nomes, sugestoes, escolhas);
    const confirmarTodas = () => pendentes.forEach((n) => onEscolher(n.chave, sugestoes[n.chave].sugestao));
    const totalNomes = bloco.total_nomes ?? nomes.length;

    return (
        <section className="space-y-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-categorias-a-confirmar>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-[13px] font-semibold text-white">Categorias a confirmar</p>
                    <p className="text-[12px] text-white/50">
                        {totalNomes === 1 ? '1 nome' : `${totalNomes} nomes`} em {textoDeProdutos(bloco.total_produtos)}. A categoria confirmada vale para todos os produtos com o mesmo nome.
                    </p>
                </div>
                {pendentes.length > 0 && (
                    <Botao variante="secundario" onClick={confirmarTodas} disabled={desabilitado} data-acao="confirmar-todas-categorias">
                        {pendentes.length === 1 ? 'Confirmar a sugestão' : `Confirmar as ${pendentes.length} sugestões`}
                    </Botao>
                )}
            </div>

            <ul className="max-h-72 space-y-1.5 overflow-y-auto pr-1">
                {nomes.map((item) => (
                    <ItemNome key={item.chave} item={item} sugestao={sugestoes[item.chave]} escolha={escolhas[item.chave]}
                        escolhendo={escolhendo} onEscolhendo={onEscolhendo} onEscolher={onEscolher} desabilitado={desabilitado} />
                ))}
            </ul>

            {totalNomes > nomes.length && (
                <p className="text-[12px] text-white/40">E mais {totalNomes - nomes.length} nomes: a categoria deles se confirma depois, no produto.</p>
            )}
            {indisponivel && (
                <p className="text-[12px] text-amber-300" role="status" data-sugestoes-indisponiveis>
                    Não deu para buscar todas as sugestões agora. Você pode escolher a categoria de cada nome ou deixar para depois.
                </p>
            )}
            <p className="text-[12px] text-white/40">O que ficar sem confirmar continua “a confirmar” no produto.</p>
        </section>
    );
}
