# Homologação, treinamento e piloto

## Pré-condições

- ambiente separado, sem dados reais;
- usuários fictícios para cada papel;
- HTTPS interno e relógio sincronizado;
- backup criptografado em equipamento diferente;
- restauração isolada executada e registrada;
- responsáveis clínico, enfermagem, recepção, LGPD e infraestrutura definidos.

## Roteiro de homologação

Registre responsável, data, evidência e resultado para cada cenário:

1. autenticação, troca de senha, bloqueio, usuário inativo e unidade ativa;
2. criação e busca de paciente sintético, duplicidade e mascaramento;
3. recepção idempotente, comprovante, senha e fila;
4. chamada, rechamada, ausência, retorno, transferência e painel sem dados sensíveis;
5. triagem, sinais vitais, valor incomum confirmado, risco profissional e adendo;
6. atendimento médico, diagnóstico, prescrição, exames, destinação e imutabilidade;
7. documento, PDF privado, hash, versão, anulação e verificação;
8. dashboard, filtros, CSV/PDF, mascaramento e limite de volume;
9. auditoria, acessos ao prontuário e isolamento entre unidades;
10. expiração e limite de sessões, CSRF, headers, HTTPS e rate limits;
11. backup, adulteração detectada, restauração e download após recuperação;
12. contingência manual e reconciliação.

Critérios de bloqueio do piloto:

- acesso entre unidades ou papéis sem autorização;
- perda ou sobrescrita silenciosa de registro final;
- painel ou log com dado clínico/documento pessoal;
- backup sem hash, criptografia exigida ou restauração comprovada;
- erro que impeça recepção, triagem, consulta ou destinação.

## Treinamento

- **Recepção:** identificação, duplicidade, abertura idempotente e contingência.
- **Enfermagem:** chamadas, sinais vitais, classificação profissional e adendos.
- **Médicos:** registro mínimo, imutabilidade, documentos e destinação.
- **Gestão:** indicadores, filtros, mascaramento e exportação responsável.
- **Auditoria:** finalidade, filtros, evidências e vedação de alterações.
- **Infraestrutura:** TLS, segredos, atualização, backup, restauração e incidentes.

Cada participante deve executar o próprio roteiro com paciente sintético e assinar ciência sobre
sigilo, credenciais individuais e proibição de dados reais no treinamento.

## Piloto

1. iniciar em uma unidade e turno com menor volume;
2. manter equipe de suporte local e formulários de contingência disponíveis;
3. acompanhar tempo de recepção, filas, erros, jobs, painéis, disco e backup;
4. realizar reunião diária curta com representantes de cada setor;
5. classificar incidentes por segurança clínica, privacidade, operação e usabilidade;
6. expandir somente após período definido sem pendência crítica.

## Go/no-go

O go-live exige aceite formal dos responsáveis clínico, enfermagem, recepção, infraestrutura e
proteção de dados. Pendência crítica resulta em **no-go**; pendência não crítica precisa de
responsável, prazo, mitigação e aprovação explícita.
