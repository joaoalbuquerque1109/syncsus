# Regras de Clean Code para Agente de IA

> Cada regra tem: **o que fazer**, **por quê** (racional) e **quando não se aplica** (exceção).
> Isso existe para evitar aplicação dogmática — a regra sem racional vira ritual, não engenharia.

---

## 1. Nomenclatura

- Use nomes descritivos e pronunciáveis. Funções = verbos (`calcularTotal`); variáveis/classes = substantivos (`pedido`).
- Booleans devem ser afirmativos e legíveis em `if`: `estaAtivo`, `podeEditar` — nunca `flag`, `check`, `status1`.
- Seja consistente com o vocabulário do projeto inteiro (não misture `getUser`/`fetchClient`/`obterCliente`).

**Racional:** nome ruim custa tempo de todo mundo que ler o código depois; é o investimento de menor custo e maior retorno em legibilidade.
**Exceção:** variáveis de escopo curto e óbvio (`i`, `j` em loop, `e` em catch) não precisam de nome longo — nomear demais aqui adiciona ruído, não clareza.

---

## 2. Funções

- Cada função deve ter **uma responsabilidade lógica coesa** — não "fazer uma coisa" no sentido literal, mas não misturar níveis de abstração diferentes (ex: não misturar validação de negócio com formatação de log).
- Priorize legibilidade sobre contagem de linhas. Uma função de 50 linhas totalmente linear e sequencial (ex: pipeline de validação) pode ser mais clara que a mesma lógica fragmentada em 6 funções de 8 linhas.
- Extraia uma função quando: (a) o bloco será reutilizado, (b) o bloco tem um nome óbvio que resume sua intenção melhor que os comentários, ou (c) o bloco muda a um ritmo diferente do resto (testável isoladamente).
- Evite parâmetros booleanos que alternam comportamento (`processar(true)`); prefira duas funções nomeadas ou um enum.
- Parâmetros: prefira poucos, mas o limite depende da linguagem — em linguagens com named/keyword arguments (Python, Kotlin, TS com objeto) 5-6 nomeados são aceitáveis; em linguagens posicionais (C, Go), 3-4 é o teto antes de agrupar em struct.

**Racional:** o limite de linhas é uma métrica famosa, mas empiricamente fraca — o que prediz manutenibilidade é *complexidade ciclomática* (número de caminhos de decisão), não tamanho físico.
**Exceção:** funções de infraestrutura (parsers, máquinas de estado, switch/case grandes de protocolo) podem legitimamente ser longas — dividir aqui só espalha a mesma complexidade em mais lugares.

---

## 3. Estrutura e legibilidade

- Use *early return* (guard clauses) em vez de `if/else` aninhado.
- Não misture lógica de alto nível (orquestração) com detalhes de baixo nível (parsing, I/O) na mesma função.
- Comentários explicam **por que** uma decisão foi tomada (trade-off, workaround, regra de negócio não óbvia) — nunca **o que** o código faz, isso o código já diz.
- Remova código morto, imports não usados e comentários desatualizados antes de finalizar. Não deixe TODO sem contexto (issue vinculada ou explicação do bloqueio).

**Racional:** aninhamento profundo aumenta a carga cognitiva de rastrear estados; comentário de "o quê" descola do código e mente com o tempo.

---

## 4. DRY (evite duplicação, mas com critério)

- Duplicação **essencial** (mesma regra de negócio escrita duas vezes) deve ser unificada.
- Duplicação **acidental** (dois trechos que hoje são iguais por coincidência, mas representam conceitos diferentes) deve **permanecer separada** — unificá-la cria acoplamento falso que quebra quando um dos dois precisa mudar sozinho.
- Regra prática: duplicação até a 2ª ocorrência é tolerável; abstraia a partir da 3ª, quando o padrão real já está claro.

**Racional:** abstração prematura é mais cara que duplicação — errar a abstração custa mais para desfazer do que copiar 5 linhas duas vezes.

---

## 5. Tratamento de erros

- Nunca capture exceção silenciosamente (proibido `catch {}` vazio ou `except: pass`).
- Erros esperados (validação, regra de negócio) devem usar tipos/exceções específicas, não genéricas (`Exception`, `Error` cru).
- Erros de programação (bug, invariante quebrada) devem falhar rápido e alto (assert, exceção não capturada) — não devem ser "tratados" com fallback silencioso.
- Mensagens de erro devem conter contexto acionável: o que falhou, com qual valor, e (quando possível) o que fazer a seguir.

**Racional:** engolir erro silenciosamente transforma um bug óbvio em corrupção de dados silenciosa, muito mais cara de investigar depois.

---

## 6. Estado e imutabilidade

- Prefira dados imutáveis por padrão; mutação só onde há razão explícita (performance medida, não suposta).
- Uma função não deve mutar um argumento que recebeu, a menos que isso esteja no nome (`sortInPlace` vs `sorted`).
- Evite estado compartilhado mutável entre módulos — isso é a maior fonte de bugs de concorrência e de "efeito colateral invisível".

**Racional:** mutação é a causa mais comum de bugs difíceis de reproduzir (o valor mudou, mas não se sabe onde). Esta é uma omissão comum em listas de clean code mais antigas.

---

## 7. Organização

- Um arquivo/módulo por responsabilidade (SRP a nível de arquivo, não só de função).
- Separe camadas: domínio/negócio, acesso a dados, apresentação/interface não devem se misturar no mesmo arquivo.
- Dependências devem apontar para dentro (camada de interface depende do domínio, não o contrário).

**Racional:** isso é o que permite testar regra de negócio sem precisar de banco, rede ou UI — e trocar uma camada sem quebrar as outras.

---

## 8. Testes

- Teste **comportamento observável** (entrada → saída esperada), não detalhes de implementação interna — testes não devem quebrar ao refatorar sem mudar comportamento.
- Todo caminho de erro relevante deve ter teste, não só o caminho feliz.
- Não usar "% de cobertura" como meta — isso gera testes triviais que só existem para marcar linha como coberta, sem validar nada.
- Testes devem ser independentes e determinísticos (sem depender de ordem de execução, tempo real ou rede).

**Racional:** cobertura alta com testes ruins passa falsa sensação de segurança; teste de comportamento sobrevive a refatoração, teste de implementação não.

---

## 9. Formatação

- Use o formatador/linter padrão da linguagem sem overrides manuais de estilo (Prettier, Black, gofmt, rustfmt, ESLint).
- Limite de linha: 100-120 caracteres (80 é excessivamente restritivo para código moderno com nomes descritivos).

**Racional:** formatação automática elimina debate de estilo em code review — tempo de time deve ir para lógica, não indentação.

---

## 10. Checklist obrigatório antes de finalizar (self-review)

1. Rodei o linter/formatter do projeto.
2. Cada função tem uma responsabilidade coesa — não apenas "poucas linhas".
3. Todo erro tem tratamento explícito, incluindo casos-limite (nulo, vazio, timeout).
4. Não há mutação de dados que o nome da função não anuncie.
5. Testei o comportamento, não a implementação.
6. Justifiquei por escrito qualquer decisão de design não óbvia (trade-off de performance, escolha de padrão, exceção a alguma regra acima).
7. Não deixei código morto, TODO sem contexto, ou comentário que descreve o óbvio.

---

## O que mudou da v1 para esta versão (para efeito de auditoria)

| Regra v1 | Problema | Correção nesta versão |
|---|---|---|
| "Máx 20-30 linhas por função" | Métrica arbitrária, incentiva fragmentação artificial | Critério passou a ser coesão + complexidade ciclomática |
| "Máx 3-4 parâmetros" (geral) | Ignora recursos da linguagem | Diferenciado por linguagem (named args vs posicional) |
| DRY sem nuance | Incentiva abstração prematura | Distinção entre duplicação essencial vs acidental + regra das 3 ocorrências |
| Testes "cobertura mínima" | Vago, gera testes inúteis | Critério objetivo: comportamento observável, não % |
| — (ausente) | Nenhuma regra sobre mutação/estado | Seção 6 adicionada — maior fonte de bugs difíceis |
| — (ausente) | Nenhum racional por regra | Toda regra agora tem "por quê" e "exceção" explícitos |
