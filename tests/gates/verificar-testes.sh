#!/bin/sh
# ═══════════════════════════════════════════════════════════════════════════════
# verificar-testes.sh — o ÚNICO caminho pelo qual a Fase 186 lê saída de teste.
#
# POR QUE EXISTE (medido em 14/10/2026, verificação dos 9 planos da Fase 186:
# 18 dos 26 gates automatizados estavam quebrados pelos DOIS bugs abaixo, em
# direções OPOSTAS — ver `.planning/learnings/gates-do-redesign-e-o-medidor-que-
# mente.md` §4, casos 12 e 13):
#
#   G1 — `node --test | grep "^# tests"` NUNCA PASSA.
#        No Node v24.14.0 o reporter padrão é o `spec`, que emite `ℹ tests N`
#        (U+2139, bytes E2 84 B9), e NÃO `# tests N`. O grep não casa nada e,
#        sendo o último da pipeline, ela sai 1 SEMPRE — inclusive com tudo verde.
#        Reconferido nesta máquina em 14/10/2026 por `cat -A`: `M-bM-^DM-9 pass 2`.
#        ➜ A forma certa é `--test-reporter=tap` + comparar o NÚMERO.
#
#   G2 — `artisan test | grep -E "Tests:|OK"` PASSA COM TESTE FALHANDO.
#        O Collision imprime a linha `Tests:` em sucesso E em falha. O gate prova
#        só "o filtro casou alguma coisa", nunca "passou". E fica VERDE, então
#        ninguém desconfia.
#        ➜ A forma certa RECUSA `failed`/`error` E confere testes + asserções.
#        ⚠️ `error` é obrigatório no filtro: teste que lança exceção imprime
#        `1 error`, não `1 failed`.
#
# ⚠️ O dano dos dois não é o vermelho — é o CONSERTO. O executor seguinte vê
# vermelho com teste verde e "conserta o gate para passar", e o conserto barato é
# tirar o `grep`, perdendo a contagem (o único motivo de o gate existir). Por
# isso a contagem mora em MANIFESTO VERSIONADO (`contagens-186-NN.txt`): mudar um
# número é diff visível, e manifesto incompleto ABORTA.
#
# ⚠️ E por isso existe o `--autoteste`: um modo que alimenta as formas com saída
# adversa e EXIGE que elas REPROVEM. Sem ele, o próprio conserto deste arquivo
# vira o próximo caso da lista do learnings.
#
# USO
#   sh … verificar-testes.sh <fase>-<plano> <id>  # confere (187-04; `04` nu = 186)
#   sh tests/gates/verificar-testes.sh --suite <php|js>        # suíte INTEIRA (baseline)
#   sh tests/gates/verificar-testes.sh --autoteste             # prova que as formas REPROVAM
#
# MANIFESTO (`contagens-<fase>-<plano>.txt`; <plano> nu = 186), uma linha por gate:
#   <id>  js   <arquivos separados por espaco>  ::  tests=<N>[,<N-mutado>]
#   <id>  php  <filtro-por-metodo>              ::  tests=<N> assertions=<A>
#
# ⚠️ Dois regimes de contagem no JS (`tests=119,114`) porque sonda com `skip` por
# `mtime` reporta contagens diferentes com o arquivo pristino e com ele mutado
# (learnings §4 caso 11). Sem isso a campanha de mutação não roda sem mexer no
# gate — e mexer no gate durante a mutação invalida a mutação.
# ═══════════════════════════════════════════════════════════════════════════════

set -u

RAIZ=$(cd "$(dirname "$0")/../.." && pwd)
PHP_BIN="${PHP_BIN:-C:/xampp/php/php.exe}"

# Referências MEDIDAS no commit de partida da Fase 187 — `75b9f82f`, 15/10/2026,
# por `--suite js` e `--suite php` ANTES de qualquer edição do plano 187-01:
#   JS  4836 / 4835 pass / 1 fail / 0 skipped
#   PHP 2346 / 20243 / 1
# (Ver `.planning/phases/187-…/187-BASELINES.md` §1.)
#
# ⚠️ Contagem ACIMA da referência é ESPERADA (a sessão paralela acrescenta
# testes) e passa. Contagem ABAIXO é regressão e reprova. NUNCA "ajustar" para
# baixo: registrar a diferença.
#
# ⚠️⚠️ OS VALORES ANTIGOS DA FASE 186 (`4482` e `2103`, medidos em d4aeaa30)
# **SAÍRAM**, e isso é de propósito (learnings §4b.2): quando a régua tem de
# subir, mede-se as duas pontas, sobe-se MEDINDO e o valor antigo **sai**.
# Manter o antigo aceito faria o gate passar se alguém APAGASSE os 243 testes
# que a Fase 186 acrescentou — exatamente o furo que a régua existe para fechar.
REF_JS_TESTS="${REF_JS_TESTS:-4836}"
REF_JS_FALHAS="${REF_JS_FALHAS:-1}"   # a pré-existente de `estrutura-grade-glide.test.js`
REF_PHP_TESTS="${REF_PHP_TESTS:-2346}"
# ⚠️ NÃO existe `REF_PHP_FALHAS`, e NÃO deve passar a existir. No commit de
# partida da Fase 187 a suíte PHP tem **1 falha herdada** — o gate de território
# por RANGE da Fase 186 (`VinculoDuravelDaOriginalTest::terr_11`) acusando o
# commit `67d6a005` (`feat(196)`) da SESSÃO PARALELA. Criar um limiar de falhas
# aceito para calar isso seria afrouxar a única régua que prova "nada quebrou".
# A falha está REGISTRADA em `187-BASELINES.md` §2.2, não silenciada aqui.

ESC=$(printf '\033')

# Tira o ANSI ANTES de qualquer filtro. NÃO é cosmético: a linha REAL do
# Collision medida nesta máquina é
#   <ESC>[90mTests:<ESC>[39m    <ESC>[32;1m1 passed<ESC>[39;22m<ESC>[90m (22 assertions)<ESC>[39m
# e `grep -E "Tests:[[:space:]]+1 passed \(22 assertions\)"` NÃO casa nela com os
# escapes no meio. As sequências de cor já comeram 12 linhas de veredicto neste
# projeto, com a campanha parecendo verde sem provar nada.
limpar_ansi() {
    sed -E "s/${ESC}\[[0-9;]*[A-Za-z]//g"
}

abortar() {
    echo "ABORTOU: $*" >&2
    exit 2
}

# ─────────────────────────────────────────────────────────────────────────────
# Onde mora o manifesto do plano. — Fase 187, plano 01.
#
# O PROBLEMA MEDIDO: até aqui o caminho era `contagens-186-${PLANO}.txt`, com a
# fase CRAVADA no código e o número do plano como 1º argumento. Nos dez planos
# da Fase 187 o manifesto é `contagens-187-NN.txt`, e só se chegava nele por
# `MANIFESTO_FORCADO=…` — env var que, esquecida, faz o harness ler o manifesto
# da **186** com o mesmo número e rodar o gate ERRADO em silêncio.
#
# A mudança é MÍNIMA e ADITIVA:
#   · `<fase>-<plano>` (`187-04`, `186-01`) resolve `contagens-<fase>-<plano>.txt`;
#   · o número NU (`04`) continua resolvendo para a **186** — é o que mantém
#     funcionando os **163 ids** já versionados nos nove manifestos daquela fase,
#     que são prova de uma fase EM PRODUÇÃO (deploy `a4c4187f`);
#   · `MANIFESTO_FORCADO` **permanece** e tem precedência (é o que o `--autoteste`
#     usa para alimentar manifesto sintético);
#   · manifesto inexistente continua ABORTANDO.
#
# ⚠️ As duas formas são conferidas pelos casos 11 e 12 do `--autoteste`, e o 12 é
# ISCA: ele exige que `01` e `186-01` resolvam o MESMO caminho. Se um dia
# divergirem, a compatibilidade com a 186 quebrou.
# ─────────────────────────────────────────────────────────────────────────────
caminho_do_manifesto() {
    # `<fase>-<plano>`: dois grupos de dígitos separados por UM hífen. Nada mais.
    # (`case` com glob não distingue `187-01` de `18-7-01`; o `sed` distingue.)
    _fp=$(printf '%s' "$1" | sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p')
    if [ -n "$_fp" ]; then
        echo "${RAIZ}/tests/gates/contagens-${_fp}.txt"
    else
        echo "${RAIZ}/tests/gates/contagens-186-$1.txt"
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# A forma do JS. Exige `# fail 0` E uma das contagens aceitas.
# ⚠️ `# fail 0` sozinho é satisfeito por ARQUIVO QUE NÃO CARREGOU — trocar um
# gate vazio por outro. A contagem é o que fecha o buraco.
# ─────────────────────────────────────────────────────────────────────────────
veredicto_js() {
    _saida="$1"
    _spec="$2"

    if ! grep -qE '^# fail 0$' "$_saida"; then
        echo "  REPROVOU: nao achei a linha '# fail 0'."
        grep -E '^# (tests|pass|fail)' "$_saida" 2>/dev/null \
            || echo "    (nenhuma linha '# ' na saida — reporter errado? use --test-reporter=tap)"
        return 1
    fi

    for _n in $(echo "$_spec" | tr ',' ' '); do
        if grep -qE "^# tests ${_n}\$" "$_saida"; then
            return 0
        fi
    done

    echo "  REPROVOU: nenhuma das contagens aceitas (${_spec}) casou."
    grep -E '^# (tests|pass|fail|skipped)' "$_saida" 2>/dev/null \
        || echo "    (nenhuma linha '# tests' na saida — reporter errado? use --test-reporter=tap)"
    return 1
}

# ─────────────────────────────────────────────────────────────────────────────
# A forma do PHP. Recusa `failed`/`error` E confere testes + asserções.
# ─────────────────────────────────────────────────────────────────────────────
veredicto_php() {
    _saida="$1"
    _n="$2"
    _a="$3"

    if grep -qE 'Tests:.*(failed|error)' "$_saida"; then
        echo "  REPROVOU: a linha 'Tests:' contem 'failed' ou 'error'."
        grep -E 'Tests:' "$_saida" 2>/dev/null
        return 1
    fi

    if ! grep -qE "Tests:[[:space:]]+${_n} passed \(${_a} assertions\)" "$_saida"; then
        echo "  REPROVOU: nao casou 'Tests: ${_n} passed (${_a} assertions)'."
        grep -E 'Tests:|No tests found' "$_saida" 2>/dev/null \
            || echo "    (nenhuma linha 'Tests:' — o filtro nao casou nada?)"
        return 1
    fi

    return 0
}

# ═══════════════════════════════════════════════════════════════════════════════
# MODO --autoteste
# ═══════════════════════════════════════════════════════════════════════════════
modo_autoteste() {
    tmp=$(mktemp -d 2>/dev/null) || abortar "nao consegui criar diretorio temporario"
    falhas=0
    casos=0

    conferir() {   # $1=rotulo  $2=esperado(PASSA|REPROVA)  $3=veredicto obtido(0|1)
        casos=$((casos + 1))
        if [ "$2" = "PASSA" ]; then _ok=0; else _ok=1; fi
        if [ "$3" = "$_ok" ]; then
            echo "  OK   caso ${casos}: $1 -> $2 (como esperado)"
        else
            echo "  XXX  caso ${casos}: $1 -> esperava $2 e NAO foi isso"
            falhas=$((falhas + 1))
        fi
    }

    echo "== JS: saida REAL de 'node --test --test-reporter=tap' =="

    cat > "$tmp/verde.test.js" <<'FIM'
import { test } from 'node:test';
import assert from 'node:assert/strict';
test('um', () => { assert.ok(true); });
test('dois', () => { assert.ok(true); });
FIM
    cat > "$tmp/vermelho.test.js" <<'FIM'
import { test } from 'node:test';
import assert from 'node:assert/strict';
test('um', () => { assert.ok(true); });
test('dois: falha de proposito', () => { assert.ok(false, 'de proposito'); });
FIM

    node --test --test-reporter=tap "$tmp/verde.test.js" > "$tmp/verde.out" 2>&1
    limpar_ansi < "$tmp/verde.out" > "$tmp/verde.limpo"
    node --test --test-reporter=tap "$tmp/vermelho.test.js" > "$tmp/vermelho.out" 2>&1
    limpar_ansi < "$tmp/vermelho.out" > "$tmp/vermelho.limpo"

    veredicto_js "$tmp/verde.limpo" "2" >/dev/null 2>&1; conferir "2 verdes, N=2" PASSA $?
    veredicto_js "$tmp/vermelho.limpo" "2" >/dev/null 2>&1; conferir "1 de 2 falhando" REPROVA $?
    veredicto_js "$tmp/verde.limpo" "344" >/dev/null 2>&1; conferir "2 verdes mas N=344 (arquivo nao carregou)" REPROVA $?

    # ISCA DA FORMA: prova que o reporter PADRAO cega o gate (G1). Sem o `tap` o
    # caso 1 — tudo verde — teria de REPROVAR. Se esta isca passar, a forma do JS
    # deixou de depender do `tap` e o gate virou outra coisa.
    node --test "$tmp/verde.test.js" > "$tmp/verde-spec.out" 2>&1
    limpar_ansi < "$tmp/verde-spec.out" > "$tmp/verde-spec.limpo"
    veredicto_js "$tmp/verde-spec.limpo" "2" >/dev/null 2>&1
    conferir "ISCA G1: 2 verdes com reporter PADRAO (sem tap)" REPROVA $?

    echo "== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada =="
    # O envelope abaixo e a captura REAL desta maquina (14/10/2026), com os
    # escapes de cor onde o Collision os pos. So a linha `Tests:` varia por caso.
    php_fixture() {   # $1 = conteudo da linha Tests: (ou a string 'SEM')
        {
            printf '   \033[30;43;1m WARN \033[39;49;22m Metadata found in doc-comment ...\n\n'
            printf '   \033[30;42;1m PASS \033[39;49;22m\033[39mTests\\Feature\\Publicador\\PublicacoesDaTelaTest\033[39m\n'
            if [ "$1" = "SEM" ]; then
                printf '  \033[90mNo tests found.\033[39m\n'
            else
                # %b (nao %s): o argumento TRAZ escapes de cor, porque a linha
                # real do Collision tem cor no meio do numero e do texto.
                printf '  \033[90mTests:\033[39m    \033[32;1m%b\n' "$1"
            fi
            printf '  \033[90mDuration:\033[39m \033[39m7.36s\033[39m\n'
        }
    }

    php_fixture '1 passed\033[39;22m\033[90m (22 assertions)' | limpar_ansi > "$tmp/php1.limpo"
    php_fixture '2 failed, 1 passed\033[39;22m\033[90m (9 assertions)' | limpar_ansi > "$tmp/php2.limpo"
    php_fixture '1 error, 1 passed\033[39;22m\033[90m (3 assertions)' | limpar_ansi > "$tmp/php3.limpo"
    php_fixture '1 passed\033[39;22m\033[90m (5 assertions)' | limpar_ansi > "$tmp/php4.limpo"
    php_fixture 'SEM' | limpar_ansi > "$tmp/php5.limpo"

    veredicto_php "$tmp/php1.limpo" 1 22 >/dev/null 2>&1; conferir "Tests: 1 passed (22 assertions), N=1 A=22" PASSA $?
    veredicto_php "$tmp/php2.limpo" 1 9  >/dev/null 2>&1; conferir "Tests: 2 failed, 1 passed (9 assertions)" REPROVA $?
    veredicto_php "$tmp/php3.limpo" 1 3  >/dev/null 2>&1; conferir "Tests: 1 error, 1 passed (3 assertions)" REPROVA $?
    veredicto_php "$tmp/php4.limpo" 1 22 >/dev/null 2>&1; conferir "Tests: 1 passed (5 assertions) com A=22 esperado" REPROVA $?
    veredicto_php "$tmp/php5.limpo" 1 22 >/dev/null 2>&1; conferir "No tests found." REPROVA $?

    # ISCA DO ANSI: o MESMO caso que passa limpo tem de REPROVAR sem a limpeza.
    # E a prova de que `limpar_ansi` nao e cosmetico.
    php_fixture '1 passed\033[39;22m\033[90m (22 assertions)' > "$tmp/php1.cru"
    veredicto_php "$tmp/php1.cru" 1 22 >/dev/null 2>&1
    conferir "ISCA ANSI: o caso que passa limpo, SEM limpar_ansi" REPROVA $?

    echo "== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 =="
    # O que precisaria estar errado para o caso 11 falhar: alguem voltar a cravar
    # a fase no caminho (ou trocar o `sed` por algo que nao case `187-01`), ou o
    # `contagens-187-01.txt` desaparecer. Os dois sao erros REAIS, nao decorativos.
    _esp187="${RAIZ}/tests/gates/contagens-187-01.txt"
    _obt187=$(caminho_do_manifesto 187-01)
    [ "$_obt187" = "$_esp187" ] && [ -f "$_obt187" ]
    conferir "'187-01' resolve contagens-187-01.txt E o arquivo existe" PASSA $?

    # ⚠️ ISCA DA COMPATIBILIDADE. Os **163 ids** dos nove manifestos da Fase 186
    # sao chamados pelo numero NU (`01`..`09`), e aquela fase esta EM PRODUCAO.
    # Esta isca exige que `01` e `186-01` resolvam o MESMO caminho — e que
    # `187-01` resolva o OUTRO. Se alguem "simplificar" a resolucao exigindo
    # sempre o prefixo da fase, os 163 ids param de resolver e este caso reprova
    # ANTES de o gate errado rodar em silencio.
    _nu=$(caminho_do_manifesto 01)
    _cheio=$(caminho_do_manifesto 186-01)
    [ "$_nu" = "$_cheio" ] \
        && [ "$_nu" = "${RAIZ}/tests/gates/contagens-186-01.txt" ] \
        && [ "$_nu" != "$_obt187" ] \
        && [ -f "$_nu" ]
    conferir "ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO" PASSA $?

    echo "== MANIFESTO: incompleto tem de ABORTAR =="
    for _mal in "g js a.test.js :: tests=0" "g js a.test.js ::" "g php test_x :: tests=1"; do
        echo "$_mal" > "$tmp/manifesto.txt"
        ( MANIFESTO_FORCADO="$tmp/manifesto.txt" modo_gate_interno g ) >/dev/null 2>&1
        _r=$?
        casos=$((casos + 1))
        if [ "$_r" = "2" ]; then
            echo "  OK   caso ${casos}: manifesto '${_mal}' -> ABORTOU (como esperado)"
        else
            echo "  XXX  caso ${casos}: manifesto '${_mal}' -> esperava ABORTAR (exit 2), veio ${_r}"
            falhas=$((falhas + 1))
        fi
    done
    echo "g js a.test.js :: tests=5" > "$tmp/manifesto.txt"
    ( MANIFESTO_FORCADO="$tmp/manifesto.txt" modo_gate_interno naoexiste ) >/dev/null 2>&1
    _r=$?
    casos=$((casos + 1))
    if [ "$_r" = "2" ]; then
        echo "  OK   caso ${casos}: id ausente no manifesto -> ABORTOU (como esperado)"
    else
        echo "  XXX  caso ${casos}: id ausente -> esperava ABORTAR (exit 2), veio ${_r}"
        falhas=$((falhas + 1))
    fi

    rm -rf "$tmp"

    echo "---"
    if [ "$falhas" = "0" ]; then
        echo "AUTOTESTE: ${casos} casos, 0 divergencia. As duas formas REPROVAM quando devem."
        return 0
    fi
    echo "AUTOTESTE: ${casos} casos, ${falhas} DIVERGENCIA(S). A sonda esta cega — nao use os gates."
    return 1
}

# ═══════════════════════════════════════════════════════════════════════════════
# MODO gate (<plano> <id>)
# ═══════════════════════════════════════════════════════════════════════════════
modo_gate_interno() {
    _id="$1"
    _manifesto="${MANIFESTO_FORCADO:-}"
    if [ -z "$_manifesto" ]; then
        _manifesto=$(caminho_do_manifesto "$PLANO")
    fi

    [ -f "$_manifesto" ] || abortar "manifesto nao existe: ${_manifesto}"

    _linha=$(grep -vE '^[[:space:]]*(#|$)' "$_manifesto" | awk -v id="$_id" '$1 == id { print; exit }')
    [ -n "$_linha" ] || abortar "id '${_id}' nao esta em ${_manifesto} (manifesto incompleto)"

    _modo=$(printf '%s\n' "$_linha" | awk '{ print $2 }')
    _alvos=$(printf '%s\n' "$_linha" | sed -E 's/^[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]+//; s/[[:space:]]*::.*$//')
    _params=$(printf '%s\n' "$_linha" | sed -nE 's/^.*::[[:space:]]*//p')

    _tests=$(printf '%s\n' "$_params" | sed -nE 's/.*(^|[[:space:]])tests=([0-9,]+).*/\2/p')
    _asserts=$(printf '%s\n' "$_params" | sed -nE 's/.*(^|[[:space:]])assertions=([0-9]+).*/\2/p')

    # ⚠️ Manifesto incompleto ABORTA. E a trava contra "deixa o numero para
    # depois" — sem ela o gate roda sem regua e parece verde.
    [ -n "$_tests" ] || abortar "gate '${_id}': falta 'tests=' no manifesto"
    [ -n "$_alvos" ] || abortar "gate '${_id}': falta o alvo no manifesto"
    for _n in $(echo "$_tests" | tr ',' ' '); do
        [ "$_n" = "0" ] && abortar "gate '${_id}': 'tests=0' nao e contagem, e numero de fora"
    done

    _tmp=$(mktemp -d 2>/dev/null) || abortar "nao consegui criar diretorio temporario"

    if [ "$_modo" = "js" ]; then
        for _f in $_alvos; do
            # test -e em CADA alvo: arquivo ausente faria o gate reportar 0
            # falhas e parecer verde (learnings §4: caminho fantasma).
            [ -e "${RAIZ}/${_f}" ] || abortar "gate '${_id}': alvo nao existe: ${_f}"
        done
        _abs=""
        for _f in $_alvos; do _abs="${_abs} ${RAIZ}/${_f}"; done
        # shellcheck disable=SC2086
        ( cd "$RAIZ" && node --test --test-reporter=tap $_abs ) > "${_tmp}/out" 2>&1
        limpar_ansi < "${_tmp}/out" > "${_tmp}/limpo"
        echo "gate '${_id}' (js): $(echo "$_alvos" | wc -w | tr -d ' ') arquivo(s), contagens aceitas: ${_tests}"
        if veredicto_js "${_tmp}/limpo" "$_tests"; then
            grep -E '^# (tests|pass|fail|skipped)' "${_tmp}/limpo"
            echo "PASSOU: gate '${_id}'"
            rm -rf "$_tmp"
            return 0
        fi
        echo "--- ultimas linhas da saida ---"
        tail -30 "${_tmp}/limpo"
        rm -rf "$_tmp"
        return 1

    elif [ "$_modo" = "php" ]; then
        # ⚠️ `artisan test --filter=<Classe>` passa dos 120 s do shell deste
        # ambiente. So filtro por METODO e aceito, e por isso o nome tem de
        # comecar com `test_`.
        case "$_alvos" in
            test_*) : ;;
            *) abortar "gate '${_id}': filtro '${_alvos}' nao comeca com 'test_' — filtro de CLASSE inteira passa dos 120 s do shell e nao e aceito" ;;
        esac
        [ -n "$_asserts" ] || abortar "gate '${_id}': modo php exige 'assertions=<A>' no manifesto"
        [ "$_asserts" = "0" ] && abortar "gate '${_id}': 'assertions=0' nao e contagem"

        ( cd "$RAIZ" && "$PHP_BIN" artisan test --filter="$_alvos" ) > "${_tmp}/out" 2>&1
        limpar_ansi < "${_tmp}/out" > "${_tmp}/limpo"
        echo "gate '${_id}' (php): --filter=${_alvos}, esperado ${_tests} passed / ${_asserts} assertions"
        if veredicto_php "${_tmp}/limpo" "$_tests" "$_asserts"; then
            grep -E 'Tests:' "${_tmp}/limpo"
            echo "PASSOU: gate '${_id}'"
            rm -rf "$_tmp"
            return 0
        fi
        echo "--- ultimas linhas da saida ---"
        tail -20 "${_tmp}/limpo"
        rm -rf "$_tmp"
        return 1
    fi

    rm -rf "$_tmp"
    abortar "gate '${_id}': modo '${_modo}' desconhecido (use 'js' ou 'php')"
}

# ═══════════════════════════════════════════════════════════════════════════════
# MODO --suite (baseline: a suite INTEIRA, sem filtro)
#
# Imprime a LINHA DE FORMA FIXA que vai direto para o `186-BASELINES.md`, e
# recusa sair 0 quando ha falha acima do esperado. E o unico modo que aceita
# contagem ACIMA da referencia (a sessao paralela acrescenta testes) e NUNCA
# abaixo.
# ═══════════════════════════════════════════════════════════════════════════════
modo_suite() {
    _qual="$1"
    _tmp=$(mktemp -d 2>/dev/null) || abortar "nao consegui criar diretorio temporario"

    if [ "$_qual" = "js" ]; then
        ( cd "$RAIZ" && node --test --test-reporter=tap "tests/js/**/*.test.js" ) > "${_tmp}/out" 2>&1
        limpar_ansi < "${_tmp}/out" > "${_tmp}/limpo"

        _t=$(sed -nE 's/^# tests ([0-9]+)$/\1/p' "${_tmp}/limpo" | tail -1)
        _p=$(sed -nE 's/^# pass ([0-9]+)$/\1/p' "${_tmp}/limpo" | tail -1)
        _f=$(sed -nE 's/^# fail ([0-9]+)$/\1/p' "${_tmp}/limpo" | tail -1)
        _s=$(sed -nE 's/^# skipped ([0-9]+)$/\1/p' "${_tmp}/limpo" | tail -1)

        [ -n "$_t" ] || { echo "ABORTOU: nao achei '# tests' na saida do tap" >&2; tail -20 "${_tmp}/limpo" >&2; rm -rf "$_tmp"; exit 2; }

        echo "JS: ${_t} / ${_p} pass / ${_f} fail / ${_s} skipped"
        echo "--- quais falharam (por semantica, nao por numero de linha) ---" >&2
        grep -E '^not ok ' "${_tmp}/limpo" >&2 || echo "(nenhuma)" >&2
        _log="${TMPDIR:-/tmp}/ultima-suite-js.log"; cp "${_tmp}/limpo" "$_log" 2>/dev/null && echo "(saida completa: $_log)" >&2

        _erro=0
        [ "$_t" -lt "$REF_JS_TESTS" ] && { echo "REGRESSAO: ${_t} testes, abaixo da referencia ${REF_JS_TESTS}" >&2; _erro=1; }
        [ "$_f" -gt "$REF_JS_FALHAS" ] && { echo "REGRESSAO: ${_f} falhas, acima das ${REF_JS_FALHAS} esperadas" >&2; _erro=1; }
        rm -rf "$_tmp"
        return "$_erro"

    elif [ "$_qual" = "php" ]; then
        ( cd "$RAIZ" && "$PHP_BIN" artisan test tests/Unit/Publicador tests/Feature/Publicador ) > "${_tmp}/out" 2>&1
        limpar_ansi < "${_tmp}/out" > "${_tmp}/limpo"

        _linha=$(grep -E '^[[:space:]]*Tests:' "${_tmp}/limpo" | tail -1)
        [ -n "$_linha" ] || { echo "ABORTOU: nao achei a linha 'Tests:' na saida" >&2; tail -30 "${_tmp}/limpo" >&2; rm -rf "$_tmp"; exit 2; }

        _p=$(printf '%s\n' "$_linha" | sed -nE 's/.*[^0-9]([0-9]+) passed.*/\1/p')
        _a=$(printf '%s\n' "$_linha" | sed -nE 's/.*\(([0-9]+) assertions\).*/\1/p')
        _ff=$(printf '%s\n' "$_linha" | sed -nE 's/.*[^0-9]([0-9]+) failed.*/\1/p'); _ff=${_ff:-0}
        _ee=$(printf '%s\n' "$_linha" | sed -nE 's/.*[^0-9]([0-9]+) error.*/\1/p');  _ee=${_ee:-0}
        _f=$((_ff + _ee))
        _t=$((_p + _f))

        echo "PHP: ${_t} / ${_a} / ${_f}"
        _log="${TMPDIR:-/tmp}/ultima-suite-php.log"; cp "${_tmp}/limpo" "$_log" 2>/dev/null && echo "(saida completa: $_log)" >&2

        _erro=0
        [ "$_f" -gt 0 ] && { echo "REGRESSAO: ${_f} teste(s) failed/error na suite do Publicador" >&2; _erro=1; }
        [ "$_t" -lt "$REF_PHP_TESTS" ] && { echo "REGRESSAO: ${_t} testes, abaixo da referencia ${REF_PHP_TESTS}" >&2; _erro=1; }
        rm -rf "$_tmp"
        return "$_erro"
    fi

    rm -rf "$_tmp"
    abortar "--suite aceita 'php' ou 'js', veio '${_qual}'"
}

# ═══════════════════════════════════════════════════════════════════════════════
case "${1:-}" in
    --autoteste)
        modo_autoteste
        exit $?
        ;;
    --suite)
        [ "$#" -ge 2 ] || abortar "uso: --suite <php|js>"
        modo_suite "$2"
        exit $?
        ;;
    '' | -h | --help)
        sed -n '36,47p' "$0"
        exit 2
        ;;
    *)
        [ "$#" -ge 2 ] || abortar "uso: <plano> <id-do-gate>  |  --suite <php|js>  |  --autoteste"
        PLANO="$1"
        modo_gate_interno "$2"
        exit $?
        ;;
esac
