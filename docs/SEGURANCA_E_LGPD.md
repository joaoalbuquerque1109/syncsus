# Revisão de segurança e LGPD

## Controles implementados

- mínimo privilégio por papel e validação no backend;
- unidade ativa revalidada a cada requisição;
- sessão em banco, criptografada em produção, `HttpOnly`, `SameSite` e `Secure`;
- limite configurável de sessões e invalidação de usuário inativo;
- CSRF no grupo web, rate limit em login, painel, verificação e exportações;
- HTTPS obrigatório configurável, hosts/proxies confiáveis, HSTS, CSP, anti-frame e `nosniff`;
- storage privado, download autorizado, nomes não previsíveis e SHA-256;
- registros clínicos finalizados imutáveis, com adendos e versões;
- auditoria pesquisável e acessos ao prontuário por finalidade;
- sanitização de senha, token, cookie, sessão e segredo no contexto da auditoria;
- relatórios mascarados e isolados por unidade;
- backup com hash, retenção, criptografia configurável e verificação registrada;
- containers sem privilégio, banco em rede interna e aplicação com raiz somente leitura.

## Decisões institucionais obrigatórias

- base legal e finalidade de cada tratamento;
- prazo de retenção por categoria documental;
- processo de atendimento aos direitos do titular;
- responsáveis por concessão e revisão periódica de acessos;
- uso ou não de acesso excepcional com justificativa;
- ferramenta antivírus para futuros anexos;
- autoridade certificadora, firewall, VPN, RPO e RTO;
- processo de descarte seguro de mídia e cópia de backup.

## Restrições do MVP

Não há upload genérico de anexos, 2FA, interoperabilidade externa ou anonimização automática de
bases reais. Dados reais não podem ser usados em desenvolvimento, treinamento ou serviços externos.
Qualquer ampliação exige avaliação de impacto, autorização, validação de MIME/extensão/tamanho e
varredura antivírus.

## Revisão periódica

Mensalmente: usuários, papéis, vínculos, falhas de login, exports e jobs.  
Trimestralmente: restauração, matriz de acesso, dependências e varredura de configuração.  
Anualmente: política LGPD, retenção, contingência, treinamento e teste de incidente.
