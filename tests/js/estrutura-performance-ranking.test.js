import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate de ESCOPO do ranking de /performance.
//
// POR QUE EXISTE (incidente 2026-08-07): o clique na linha do ranking passou a
// levar o mês selecionado para /performance/{user}. O helper que montava o
// parâmetro foi declarado dentro de `PerformanceIndex`, mas quem renderiza a
// linha clicável é `RankingConsultoria` — outro componente, outro escopo. O
// handler passou a lançar `ReferenceError` no clique e a linha simplesmente
// parou de navegar.
//
// Nada pegou: não há ESLint neste projeto (`no-undef` teria pego na hora), o
// `npm run build` passa porque bundler não é type-checker, e o identificador
// livre até SOBREVIVE à minificação — o que faz um grep no bundle buildado
// parecer confirmação quando é o contrário.
//
// O gate não conta ocorrências (gate falso da Fase 82): ele resolve os nomes.
// ═══════════════════════════════════════════════════════════════════════

const FONTE = lerSemComentarios('resources/js/Pages/Performance/Index.jsx');

/** Recorta o corpo de uma função de topo até a próxima declaração em coluna 0. */
function corpoDaFuncao(fonte, nome) {
    const inicio = fonte.search(new RegExp(`^(?:export default )?function ${nome}\\b`, 'm'));
    assert.notEqual(inicio, -1, `função ${nome} não encontrada em Index.jsx`);
    const resto = fonte.slice(inicio);
    // Próxima declaração de topo (coluna 0), pulando a própria.
    const fim = resto.slice(1).search(/^(?:export default )?(?:function|const|let) /m);
    return fim === -1 ? resto : resto.slice(0, fim + 1);
}

/** Remove literais de string/template para não colher nome de dentro de texto. */
function semLiterais(src) {
    return src
        .replace(/`(?:[^`\\]|\\.)*`/g, '``')
        .replace(/'(?:[^'\\\n]|\\.)*'/g, "''")
        .replace(/"(?:[^"\\\n]|\\.)*"/g, '""');
}

/** Nomes declarados no topo de um componente: const/let/function + props destruturadas. */
function nomesDeclarados(corpo) {
    const src = semLiterais(corpo);
    const nomes = new Set();

    for (const m of src.matchAll(/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)/g)) nomes.add(m[1]);
    for (const m of src.matchAll(/\bfunction\s+([A-Za-z_$][\w$]*)/g)) nomes.add(m[1]);

    // Props destruturadas na assinatura: function X({ a, b = 1, c: d })
    const assinatura = src.match(/function\s+[A-Za-z_$][\w$]*\s*\(\s*\{([\s\S]*?)\}\s*\)/);
    if (assinatura) {
        for (const parte of assinatura[1].split(',')) {
            const m = parte.trim().match(/^([A-Za-z_$][\w$]*)\s*(?::\s*([A-Za-z_$][\w$]*))?/);
            if (m) nomes.add(m[2] ?? m[1]);
        }
    }
    // Destruturação no corpo: const { a, b } = ...
    for (const m of src.matchAll(/\b(?:const|let|var)\s*\{([^}]*)\}\s*=/g)) {
        for (const parte of m[1].split(',')) {
            const n = parte.trim().match(/^([A-Za-z_$][\w$]*)\s*(?::\s*([A-Za-z_$][\w$]*))?/);
            if (n) nomes.add(n[2] ?? n[1]);
        }
    }
    return nomes;
}

/** Identificadores referenciados, ignorando nomes de propriedade (`obj.x`, `{ x: ... }`). */
function nomesReferenciados(corpo) {
    // A lookbehind `(?<!\.)` existe por causa do SPREAD: em `...paramsDoMes()` o
    // terceiro ponto seria lido como acesso a propriedade e o identificador
    // sumiria da análise — foi exatamente assim que a primeira versão deste gate
    // passou no código quebrado que ele deveria pegar.
    const src = semLiterais(corpo)
        // Tag JSX em MINÚSCULA é elemento nativo (`<p>`, `<div>`), não
        // identificador — sem isto o `<p>` do card era acusado de vazar o
        // `const p` do componente pai. Tag Capitalizada é componente React e
        // FICA, porque referenciar um componente fora de escopo é bug de verdade.
        .replace(/<\/?[a-z][\w-]*/g, '<')
        .replace(/(?<!\.)\.\s*[A-Za-z_$][\w$]*/g, '')  // acesso a propriedade (não spread)
        .replace(/([A-Za-z_$][\w$]*)\s*:/g, '');       // chave de objeto / atributo JSX

    const nomes = new Set();
    for (const m of src.matchAll(/\b([A-Za-z_$][\w$]*)\b/g)) nomes.add(m[1]);
    return nomes;
}

describe('/performance — escopo do ranking (Index.jsx)', () => {
    const indexBody   = corpoDaFuncao(FONTE, 'PerformanceIndex');
    const rankingBody = corpoDaFuncao(FONTE, 'RankingConsultoria');

    test('RankingConsultoria não referencia nada declarado dentro de PerformanceIndex', () => {
        const doIndex     = nomesDeclarados(indexBody);
        const doRanking   = nomesDeclarados(rankingBody);
        const referidos   = nomesReferenciados(rankingBody);

        // Vazamento = nome que só existe no escopo do PerformanceIndex, referenciado
        // aqui e NÃO redeclarado localmente (redeclarar é sombrear, e é legítimo).
        const vazando = [...referidos].filter((n) => doIndex.has(n) && !doRanking.has(n));

        assert.deepEqual(
            vazando,
            [],
            `RankingConsultoria referencia identificador(es) do escopo de PerformanceIndex: `
            + `${vazando.join(', ')}. Em runtime isso é ReferenceError e o clique da linha `
            + `para de funcionar em silêncio. Passe por PROP.`,
        );
    });

    test('a linha do ranking navega para performance.show usando o mês recebido por prop', () => {
        assert.match(
            rankingBody,
            /router\.visit\(\s*route\(\s*['"]performance\.show['"]/,
            'a linha do ranking deve navegar para performance.show',
        );
        // Dentro da própria expressão de navegação — não basta o nome aparecer
        // na assinatura (a versão quebrada satisfazia um /mesDetalhe/ solto).
        const navegacao = rankingBody.match(/route\(\s*['"]performance\.show['"][\s\S]{0,200}?\)\s*\)/);
        assert.ok(navegacao, 'expressão de navegação não encontrada');
        assert.match(
            navegacao[0],
            /mesDetalhe/,
            'o clique deve levar o mês selecionado (prop mesDetalhe) para a tela de detalhe',
        );
        assert.match(
            corpoDaFuncao(FONTE, 'RankingConsultoria').split('\n')[0],
            /mesDetalhe/,
            'mesDetalhe precisa estar na ASSINATURA de RankingConsultoria, não vir de fora',
        );
    });

    test('PerformanceIndex passa mesDetalhe ao RankingConsultoria', () => {
        assert.match(
            indexBody,
            /<RankingConsultoria[^>]*mesDetalhe=\{/,
            'o JSX precisa repassar mesDetalhe — sem isso a prop chega undefined e o mês se perde',
        );
    });

    test('mesDetalhe é null no mês corrente (não manda ?mes= à toa)', () => {
        // `?mes=` do mês em curso resolveria pelo ramo YYYY-MM do
        // MetricPeriodResolver em vez do current_month (mode=operational),
        // trocando o modo da tela sem o usuário ter pedido.
        assert.match(
            indexBody,
            /const\s+mesDetalhe\s*=\s*\(?\s*mes_selecionado\s*&&\s*!\s*mes_em_curso/,
            'mesDetalhe deve ser null quando mes_em_curso',
        );
    });
});

// ── Mesma armadilha no Show.jsx: o link "Ver operação da carteira" vive dentro
//    de `FaixaBonusCard`, não de `PerformanceShow`. ────────────────────────────
describe('/performance/{id} — escopo do card de faixa (Show.jsx)', () => {
    const SHOW      = lerSemComentarios('resources/js/Pages/Performance/Show.jsx');
    const showBody  = corpoDaFuncao(SHOW, 'PerformanceShow');
    const cardBody  = corpoDaFuncao(SHOW, 'FaixaBonusCard');

    test('FaixaBonusCard não referencia nada declarado dentro de PerformanceShow', () => {
        const doShow    = nomesDeclarados(showBody);
        const doCard    = nomesDeclarados(cardBody);
        const referidos = nomesReferenciados(cardBody);
        const vazando   = [...referidos].filter((n) => doShow.has(n) && !doCard.has(n));

        assert.deepEqual(
            vazando,
            [],
            `FaixaBonusCard referencia identificador(es) do escopo de PerformanceShow: `
            + `${vazando.join(', ')}. Em runtime isso é ReferenceError. Passe por PROP.`,
        );
    });

    test('o link da carteira leva o mês recebido por prop', () => {
        const link = cardBody.match(/route\(\s*['"]portfolio\.show['"][\s\S]{0,160}/);
        assert.ok(link, 'link para portfolio.show não encontrado em FaixaBonusCard');
        assert.match(
            link[0],
            /mesDetalhe/,
            'o link "Ver operação da carteira" deve levar o mês — o controller já aceita ?mes=',
        );
        assert.match(
            cardBody.split('\n')[0],
            /mesDetalhe/,
            'mesDetalhe precisa estar na ASSINATURA de FaixaBonusCard, não vir de fora',
        );
        assert.match(
            showBody,
            /<FaixaBonusCard[^>]*mesDetalhe=\{/,
            'PerformanceShow precisa repassar mesDetalhe ao FaixaBonusCard',
        );
    });

    test('o "Ranking" volta preservando o mês', () => {
        assert.match(
            showBody,
            /route\(\s*['"]performance\.index['"]\s*,\s*mesDetalhe\s*\?/,
            'o botão Ranking deve devolver o mês visto aqui',
        );
    });
});
