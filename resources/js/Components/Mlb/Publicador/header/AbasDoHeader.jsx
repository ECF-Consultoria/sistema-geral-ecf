import { Link } from '@inertiajs/react';
import { LayoutDashboard, LayoutList, Package, Zap } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from '../BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 05 — a LINHA 2 do PublicadorHeader: as abas-pílula de 34px
// (HEAD-09/10/11/12).
//
// ⚠️⚠️ SÃO QUATRO ABAS, E ISSO É DE PROPÓSITO (HEAD-09) ⚠️⚠️
//
//   Visão geral · Produtos (N) · Publicações (N) · Alavancas
//
// O protótipo (`new_layout_publicador/PublicadorHeader.dc.html`, o array `T`
// na classe `Component`) e o README §0 "Linha 2 (nav, 48px)" especificam
// CINCO, com uma aba "Desempenho" entre Publicações e Alavancas. A quinta NÃO
// entra, por decisão do usuário em 11/10/2026 — palavras dele: "não mostre o
// link da tela desempenho por enquanto".
//
// O motivo é a régua dele: nada nasce decorativo. A tela de Desempenho não
// existe (a Fase 182 está registrada e não planejada, porque o dado de vendas
// por dia não existe ainda), e aba que leva a lugar nenhum é link morto
// (EXMP-04).
//
// ⚠️ É DESVIO DELIBERADO DA FIDELIDADE — NÃO "corrigir" para cinco. Quem
// reimplementar este header lendo só o README vai achar que faltou uma aba:
// NÃO FALTOU. A aba volta junto com a Fase 182, que carrega esta anotação.
// Há gate de fonte e de HTML em `tests/js/publicador-header-abas-render.test.js`
// reprovando a volta dela por descuido.
//
// ⚠️ TAMBÉM NÃO HÁ aba "Configurações" aqui (HEAD-08): ela saiu das abas e
// virou o ícone de 34px da linha 1, entregue no plano 177-04.
//
// ─── O QUE VEIO DE `AbasDaConta.jsx` (REG-01: nada pode se perder) ───
//   · as 4 abas da esquerda, com as MESMAS rotas e os MESMOS parâmetros;
//   · D23: Publicações desabilitada (nunca escondida) sem Company, com o
//     literal exato de `ModoAnuncioTabs.jsx`;
//   · o contador de produtos (agora na pílula nova, com `tabular-nums`);
//   · o badge de alavancas pendentes, só com número > 0, com o MESMO `title`
//     ("N publicados aguardando alavancas");
//   · `aria-current="page"` na aba ativa.
//
// ─── O QUE MUDOU DE LUGAR ───
//   · Configurações  → ícone da linha 1 (plano 177-04, HEAD-08);
//   · sub-abas "No ar | Histórico" → vão para as PÁGINAS (plano 177-08). O
//     protótipo não as tem e a remoção delas só é autorizada na Fase 180
//     (LIST-25); até lá `AbasDaConta` continua no ar com elas funcionando.
//   · a navegação virou `<Link href>` (`<a>`, como o protótipo) em vez do
//     `router.get` no `onClick` de um `<button>`.
//
// ⚠️ REND-04: TODA flag é calculada DENTRO de `pilula()`, que é chamada dentro
// do `.map()`. Variável de escopo do componente lida só dentro de um `.map()`
// já foi eliminada pelo Rollup no bundle de produção deste projeto
// (ReferenceError em tela) — foi por isso que `LinhaDeProduto.jsx` virou
// componente próprio e é por isso que `AbasDaConta.botaoAba` tem a mesma forma.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * D23: sem Company (MlbEmpresa solta), a aba Publicações fica DESABILITADA,
 * nunca escondida. Texto literal idêntico ao de `AbasDaConta.jsx` e
 * `ModoAnuncioTabs.jsx` — há teste de fonte procurando essa string em
 * `publicador-entrada.test.js`. NÃO VARIAR.
 */
const TITULO_SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

/**
 * Sem chave de conta usável não existe destino. Em vez de um `href` com
 * `undefined` (que levaria a uma URL inventada), a aba fica desabilitada
 * dizendo por quê — é o que EXMP-03 exige de todo controle sem destino.
 */
const TITULO_SEM_CONTA = 'Abra uma empresa do Publicador para navegar por esta aba — esta tela está fora do fluxo por empresa.';

/**
 * O `company_id` vira parâmetro de URL. Aceita número finito ou texto não
 * vazio (é o que o servidor manda hoje, e `AbasDaConta.jsx` passava adiante sem
 * conferir) e devolve `null` para qualquer outra coisa — objeto, booleano,
 * vazio e `NaN` inclusive. `NaN` precisa ser barrado explicitamente porque
 * `typeof NaN === 'number'` e ele escreveria "NaN" dentro do `href`.
 */
function idUtil(valor) {
    if (typeof valor === 'number') return Number.isFinite(valor) ? valor : null;
    if (typeof valor === 'string') return valor.trim() === '' ? null : valor;
    return null;
}

/**
 * As quatro abas, nesta ordem. Exportado para o gate e para os testes
 * conferirem ordem e rotas sem precisar renderizar.
 *
 * Os ícones são os equivalentes diretos dos SVG do protótipo (TOK-14: não
 * copiar SVG cru quando o Lucide tem equivalente) — quatro retângulos = painel,
 * caixa 3D = pacote, retângulo com linhas = lista, raio = `Zap` (o mesmo que o
 * `AppLayout` deste projeto já usa). Todos a 15px com traço 1,6.
 */
export const ABAS = [
    { chave: 'visao-geral', rotulo: 'Visão geral', rota: 'mlb.anuncios.publicador.visao-geral', icone: LayoutDashboard },
    { chave: 'produtos', rotulo: 'Produtos', rota: 'mlb.anuncios.publicador.produtos', icone: Package },
    { chave: 'publicacoes', rotulo: 'Publicações', rota: 'mlb.anuncios.meus', icone: LayoutList },
    { chave: 'alavancas', rotulo: 'Alavancas', rota: 'mlb.anuncios.publicador.alavancas.index', icone: Zap },
];

/**
 * @param {object} props
 * @param {string} [props.aba] a chave da aba ativa ('visao-geral' | 'produtos'
 *   | 'publicacoes' | 'alavancas'). Qualquer outro valor (inclusive
 *   'configuracoes', que agora é o ícone da linha 1) deixa as quatro inativas.
 * @param {string|null} [props.conta] a chave da empresa do Publicador. Pode ser
 *   nula nas três páginas do assistente antigo.
 * @param {number|null} [props.companyId] o id da Company. `null` dispara D23.
 * @param {object|null} [props.contadores] `cabecalho.contadores` do servidor
 *   (plano 177-03): `{ produtos, publicacoes, publicacoes_pedem_acao,
 *   alavancas }`. ⚠️ `publicacoes` e `publicacoes_pedem_acao` são NULOS quando
 *   o acervo nunca foi coletado — e `null` NÃO é zero: nesse caso não se
 *   desenha pílula nenhuma, porque um "0" afirmaria "esta conta não tem
 *   publicação", quando a verdade é "ninguém mediu ainda".
 */
export default function AbasDoHeader({ aba, conta = null, companyId = null, contadores = null }) {
    // `textoSeguro(..., null)` em vez de `String()`: a chave vai para `route()`
    // como parâmetro de URL e um objeto no lugar dela montaria um destino
    // inventado (`/publicador/empresas/[object Object]`).
    const contaSegura = textoSeguro(conta, null);
    const companyIdSeguro = idUtil(companyId);

    /**
     * Devolve a pílula de UMA aba. Chamada dentro do `.map()`; todas as flags
     * nascem aqui dentro (REND-04).
     */
    function pilula(item) {
        const ativa = item.chave === aba;

        // ─── Contador (HEAD-10: nenhum número fixo no JSX) ───
        // `Number.isFinite` e não `typeof === 'number'`: `NaN` é number e
        // escreveria "NaN" na tela.
        let contador = null;
        let tituloContador;
        if (item.chave === 'produtos' && Number.isFinite(contadores?.produtos)) {
            contador = contadores.produtos;
        } else if (item.chave === 'publicacoes' && Number.isFinite(contadores?.publicacoes)) {
            contador = contadores.publicacoes;
        } else if (item.chave === 'alavancas' && Number.isFinite(contadores?.alavancas) && contadores.alavancas > 0) {
            // Badge opcional, igual ao de hoje: só com número > 0, e com o
            // MESMO title de `AbasDaConta.jsx` (REG-01).
            contador = contadores.alavancas;
            tituloContador = contador === 1
                ? '1 publicado aguardando alavancas'
                : `${contador} publicados aguardando alavancas`;
        }

        // ─── Dot vermelho de alerta ───
        const pedemAcao = Number.isFinite(contadores?.publicacoes_pedem_acao)
            ? contadores.publicacoes_pedem_acao
            : 0;
        const alerta = item.chave === 'publicacoes' && pedemAcao > 0;
        const tituloAlerta = pedemAcao === 1
            ? '1 publicação pede ação'
            : `${pedemAcao} publicações pedem ação`;

        // ─── Destino e habilitação ───
        // Publicações é a única que depende da Company (D23); as outras três
        // dependem da chave da conta. 'visao-geral' e 'alavancas' NUNCA passam
        // por D23: elas tratam "sem Company" por dentro das próprias telas,
        // como hoje.
        const dependeDeCompany = item.chave === 'publicacoes';
        const desabilitada = dependeDeCompany ? companyIdSeguro === null : contaSegura === null;
        const motivo = dependeDeCompany ? TITULO_SEM_COMPANY : TITULO_SEM_CONTA;
        const parametros = dependeDeCompany ? { company: companyIdSeguro } : { conta: contaSegura };

        const classe = cn(
            'relative flex h-[34px] items-center gap-2 whitespace-nowrap rounded-pub-control px-[12px] text-[13.5px] transition-[background-color,color] duration-150',
            ativa && 'bg-pub-hover-2 font-semibold text-pub-txt shadow-pub-aba-ativa',
            !ativa && 'font-medium text-pub-txt-3',
            !ativa && !desabilitada && 'hover:bg-pub-raised hover:text-pub-txt',
            desabilitada && 'cursor-not-allowed opacity-40',
        );

        const Icone = item.icone;

        const miolo = (
            <>
                <span className={cn('grid flex-none place-items-center', ativa ? 'text-pub-accent' : 'text-pub-txt-4')}>
                    <Icone size={15} strokeWidth={1.6} aria-hidden="true" />
                </span>
                {item.rotulo}
                {contador !== null && (
                    <span
                        title={tituloContador}
                        className={cn(
                            'grid h-[18px] min-w-[22px] flex-none place-items-center rounded-full px-[6px] text-[11px] font-semibold tabular-nums',
                            ativa ? 'bg-pub-accent/[0.14] text-pub-accent' : 'bg-pub-hover-3 text-pub-txt-3',
                        )}
                    >
                        {contador}
                    </span>
                )}
                {alerta && (
                    <span
                        title={tituloAlerta}
                        aria-label={tituloAlerta}
                        className="h-[6px] w-[6px] flex-none rounded-full bg-pub-danger"
                    />
                )}
                {ativa && (
                    <span
                        aria-hidden="true"
                        className="absolute bottom-[-7px] left-[12px] right-[12px] h-[2px] rounded-[2px] bg-pub-accent"
                    />
                )}
            </>
        );

        if (desabilitada) {
            return (
                <span
                    key={item.chave}
                    role="link"
                    aria-disabled="true"
                    aria-current={ativa ? 'page' : undefined}
                    title={motivo}
                    aria-label={`${item.rotulo} — ${motivo}`}
                    className={classe}
                >
                    {miolo}
                </span>
            );
        }

        return (
            <Link
                key={item.chave}
                href={route(item.rota, parametros)}
                aria-current={ativa ? 'page' : undefined}
                className={classe}
            >
                {miolo}
            </Link>
        );
    }

    // O `aria-label="Abas da conta"` fica na `<nav>` do `PublicadorHeader`
    // (plano 177-04) — aqui sai só o grupo, com o gap de 2px do protótipo
    // (PublicadorHeader.dc.html:109). O `min-w-0` é o do ponto de montagem:
    // impede que o nome longo da empresa (plano 177-06) estoure a linha.
    return (
        <div className="flex min-w-0 items-center gap-[2px]">
            {ABAS.map((item) => pilula(item))}
        </div>
    );
}
