# Fase 1C-A — probe de contratos Enhance

Preparação, não homologação. Base: `f19f5f6d549f397c778969357d15118280b79b67`.
Nenhuma chamada externa foi feita nesta entrega. Os contratos produtivos não mudam.
Consulte [o inventário e a matriz](contracts.md) antes de preparar qualquer execução.

## Arquitetura e limites

`probe.php` é a única entrada CLI. Nenhum arquivo do módulo inclui esta ferramenta.
Todos os PHP recusam execução web; **não copie tools/ para o document root/WHMCS**.
`Cli.php` interpreta somente opções conhecidas e imprime erros fixos, sem mensagens
ou traces de exceções. `Engine.php` orquestra uma única requisição por execução.
`Catalog.php` fixa operações, métodos, rotas e payloads; não há URL, ID de DELETE,
header, senha ou token arbitrário na CLI. `Transport.php` contém a implementação
cURL preparada para uso futuro. Os testes injetam exclusivamente `FakeTransport`.
`Structure.php` converte a resposta em estrutura. `Session.php` mantém um journal
privado autenticado e bloqueado durante a execução.

São importadas somente as classes puras `EnhanceLog` e `EnhanceHttpResult` para
sanitizar/classificar. Não se instancia `EnhanceApi`, não se carrega configuração,
banco, credenciais ou runtime WHMCS. Não há dependência nova de produção.

Default: dry-run, sem rede, sem leitura de configuração/credenciais e sem manifesto:

```sh
php tools/enhance-contract-probe/probe.php
php tools/enhance-contract-probe/probe.php --operation delete-org
```

## Salvaguardas da futura execução

Todas as requisições exigem `--execute`, configuração com `environment=homologation`
e `ENHANCE_PROBE_ENV=homologation`. A configuração de exemplo contém SOMENTE valores
fictícios. Configure fora do repositório ou em `config.local.json` ignorado.

A API key vem exclusivamente de `ENHANCE_PROBE_API_KEY`. A chave HMAC local vem de
`ENHANCE_PROBE_SESSION_KEY` (mínimo 32 caracteres aleatórios, distinta da API key).
Injete ambas por ambiente seguro/supervisor de processos; nunca em argumentos,
comandos com valor literal, histórico, arquivo `.env`, relatório ou manifesto.
Não execute com tracing de shell (`set -x`) ou dump de ambiente. O probe não carrega
`.env` nem `serveraccesshash`. Perder a chave HMAC impede retomar a sessão: não há
bypass; use reconciliação e limpeza supervisionadas.

Configuração obrigatória:

- Hostname exclusivo do laboratório e IP fixado em `pinned_ip`.
- Listas **completas e não vazias** de hostnames e IPs de produção. Host bloqueado
  inclui seus subdomínios. IPv4-mapped IPv6 é convertido para IPv4; IPs são comparados em forma binária canônica; preencha todos os
  endereços de produção relevantes. A ferramenta não descobre inventário de rede nem todos os aliases DNS possíveis.
- TLS verificado, HTTPS/443, sem redirects, sem proxy herdado e conexão fixada via
  `CURLOPT_RESOLVE`. Não há `--insecure`, CA insegura ou opção de desativar TLS.
- Timeout de conexão 10 s, total 45 s, corpo limitado a 1 MiB. Sem retry.
- Organização mestre e dois planos **do laboratório descartável**, preparados e
  conferidos manualmente. Eles são âncoras de criação, nunca alvos de DELETE.
- Diretório privado existente, modo 0700, fora do repositório e de qualquer raiz
  publicável. Manifestos são 0600. Não há criação automática desse diretório.

Marcadores, listas e assinatura não comprovam sozinhos que um operador configurou
um laboratório real: a exclusividade do ambiente, completude da lista e posse do
host devem ser verificadas presencialmente antes da homologação. O usuário com
acesso à chave HMAC é confiável; não há proteção contra esse usuário forjar arquivos.
O journal recusa edição sem assinatura, sessão de outro alvo/mestre/plano, recursos
não registrados, duplicação de criação e IDs fornecidos manualmente. A proveniência
é requisição de criação pelo probe + resposta com ID aceita pelo contrato mínimo;
a criação remota e o isolamento real ainda exigem confirmação em homologação.

## Procedimento futuro — não executado nesta fase

Primeiro revise a configuração, inventário, bloqueios, orçamento de resíduos e plano
de limpeza no laboratório. Não use estas instruções em servidor de produção.

Exemplo de consulta (credenciais já injetadas no ambiente, sem valores na CLI):

```sh
php tools/enhance-contract-probe/probe.php --execute --config /private/lab-config.json --operation licence
```

`licence` deverá gerar relatório e exit 3 enquanto seu contrato continuar desconhecido.
Isso é uma observação, não confirmação positiva.

Para recursos descartáveis, inicialize um manifesto sem fazer requisição:

```sh
php tools/enhance-contract-probe/probe.php --execute --config /private/lab-config.json --init-session
```

Guarde o identificador local retornado. Cada mutação exige `--allow-mutation` e
leitura de `CONFIRM <identificador>` pelo stdin. Não há flag para pular confirmação.
A confirmação digitada é de consentimento local, não autenticação remota.

```sh
php tools/enhance-contract-probe/probe.php --execute --config /private/lab-config.json --session ID_DA_SESSAO --operation create-org --allow-mutation
```

`ID_DA_SESSAO` é placeholder; somente IDs locais aleatórios de 32 hex são aceitos.
Não há argumento para IDs remotos. Consultas a recursos também usam os IDs do
manifesto, sem possibilidade de importar organização/assinatura/website preexistente.

Crie somente dependências necessárias ao cenário escolhido:

1. `create-org` registra organização antes de continuar.
2. `create-login` somente para Owner/recuperação; e-mail gerado em `example.invalid`,
   senha aleatória em memória, nunca persistida nem impressa.
3. `create-subscription` usa `plan_a`; registra assinatura antes de criar website.
4. `create-website` usa domínio gerado `.invalid` e assinatura da sessão.
5. Faça as consultas estruturais necessárias.
6. Execute **uma** operação de contrato desconhecido a observar.

Owner não é vinculado automaticamente antes de assinatura/website: seu ACK
indeterminado encerraria a sessão antes desses cenários. Se a API exigir Owner,
e-mail/domínio resolvível, licença específica ou outro pré-requisito não comprovado,
**pare**. Não substitua por usuário real, domínio real ou ID manual. Essa preparação
exige execução manual supervisionada em laboratório e uma decisão de escopo futura;
a ferramenta não contorna a dependência.

Reativação exige primeiro recurso suspenso no laboratório. Como o PATCH de suspensão
encerra a sessão por contrato desconhecido, não existe ciclo automático suspender /
reativar nesta entrega. As duas operações estão preparadas, mas sua sequência real
fica bloqueada até homologação do ACK ou preparação manual supervisionada. Não edite
o manifesto para reabrir uma sessão.

## Estado parcial e limpeza

Antes de enviar mutação, persiste `pending` sob lock exclusivo e `fsync`. Se o processo
cair, a sessão permanece bloqueada. Resposta não confirmada, erro ou timeout muda
para `indeterminate`; não há próxima mutação, compensação nem comando de reset.
Uma resposta com ID confirmado registra o recurso antes de liberar a próxima ação.
Consulta não confirmada associada a uma sessão também bloqueia novas mutações;
consultas posteriores para investigação continuam possíveis sem desbloquear a sessão.
Falha ao gravar o journal também interrompe o processo. Uma queda pode deixar arquivo
incompleto, que será recusado, e recurso remoto sem ID local: use o prefixo de sessão
no laboratório para investigação supervisionada, nunca busca automática por nome.

DELETE só usa recursos criados/registrados pela própria sessão, com assinatura válida,
mesmo alvo e confirmação digitada. Enquanto seus ACKs forem desconhecidos, **o primeiro
DELETE encerra a sessão**, ainda que aplicado remotamente. Assim, limpeza completa não
é automatizada nesta fase. Ordem futura sob supervisão: website → assinatura →
organização; login pode sobreviver e não existe DELETE login no módulo/probe. Verifique
manualmente todos os resíduos e cascatas. Teste soft e hard em sessões independentes;
nunca faça hard automaticamente como fallback de soft.

## Relatório estrutural e fixtures

Saída JSON contém correlação aleatória local, método, rota sem query/IDs, HTTP, errno,
duração, presença/formato do corpo, validade JSON, tipo da raiz, estrutura tipada,
contagem de itens, presença de campos consumidos e categoria **inalterada da Fase 1B**.
Mutação acrescenta estado local de sessão. Não imprime request, headers, valores,
corpo, URL, mensagens remotas ou erro textual do cURL. Não é registrado hash do corpo
(evita fingerprint desnecessário de dados de baixa entropia).

Somente nomes de campos da allowlist estrutural são preservados. Chaves desconhecidas
podem conter segredos: são contadas e seus valores viram esquemas anônimos em
`unknown_value_shapes`. Arrays registram contagem e esquemas distintos dos primeiros
100 itens, com `sampled=true` se incompletos. Profundidade máxima 12; truncamento
impede considerar a observação completa. HTML/texto inválido vira `<omitted>`.
Dados privados ficam apenas em memória. O manifesto é a exceção operacional necessária:
contém IDs **dos recursos fictícios criados**, sessão, estados e assinatura, nunca
credenciais, nomes, e-mails ou corpo. Não é relatório para compartilhar/versionar.

A ferramenta não grava relatórios automaticamente. Redirecionamento de stdout deve
ser feito somente para diretório privado previamente revisado. Não cole respostas
brutas; não use ferramentas de captura de tráfego como parte deste procedimento.

[Modelo de relatório](examples/report.synthetic.json) e
[fixture estrutural](examples/fixture.synthetic.json) são inteiramente fictícios.
Para converter uma observação aprovada em fixture:

1. Verifique HTTP, rota, campos consumidos, ausência de valores e ausência de limites
   de amostragem/profundidade. Documente versão Enhance e operação fora do payload.
2. Revise separadamente o efeito no laboratório (por exemplo suspensão aplicada),
   sem copiar IDs/valores. Um ACK estrutural não prova o efeito nem a inexistência.
3. Copie apenas `http`, `body_present`, `format`, `json_valid`, `root`, `structure`,
   `consumed_fields_present` e `phase_1b_category` para JSON versionável no formato
   `fixture.synthetic.json`. Remova correlação/duração, que variam a cada execução.
4. O teste deve comparar a estrutura gerada contra essa fixture. Para testar um
   consumidor, construa OUTRO payload sintético com os mesmos tipos e valores falsos.
   Tipos `<boolean>` não comprovam qual booleano veio; `<string>` não comprova enum,
   URL válida, UUID, mensagem ou estado. Não deduza esses contratos do esquema.
5. A aprovação da fixture é humana. Nesta fase nenhum relatório altera contratos
   produtivos nem converte `indeterminate` em sucesso. Proponha isso separadamente,
   com evidência revisada e testes positivos/negativos.

## Testes locais e códigos de saída

- Exit 0: dry-run, inicialização sem rede ou resposta aceita pelo contrato atual
  com sessão liberada (quando houver sessão).
- Exit 2: recusa/local error; mensagem fixa, sem eco dos argumentos.
- Exit 3: relatório de resposta não confirmada; não repetir mutação automaticamente.

Comandos no contêiner PHP 8.1.34, imagem local `php:8.1-cli`:

```sh
docker run --rm --pull=never --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --tmpfs /tmp:rw,noexec,nosuid,size=16m \
  --mount "type=bind,source=$PWD,target=/work,readonly" --workdir /work \
  php:8.1-cli sh tools/enhance-contract-probe/tests/validate.sh
```

O script usa `tests/php-isolated.sh`: cURL/sockets/streams de rede e subprocessos
nativos desabilitados, URL fopen desligado, transporte falso injetado. O tmpfs serve
somente aos manifestos fictícios/configurações de teste e é descartado ao sair.
A suíte de 192 testes do módulo permanece independente e sem alteração.
A suíte do probe verifica recusas, manifesto, interrupção, sanitização e CLI.
Não testa cURL real, certificado, entrega de e-mail, API Enhance nem WHMCS reais.

## Evidência local desta entrega

Worktree inicial limpo; Fase 1B commitada no hash informado acima. Validação em PHP
8.1.34: **63 testes do probe + 192 testes anteriores = 255 aprovados**, nenhum
reprovado. Lint de todos os PHP novos, sintaxe shell e verificação de whitespace
aprovados. Dry-run real da CLI: `requests: 0`. Execução com `--execute` testada apenas
por injeção do transporte falso. Nenhum serviço externo ou credencial real utilizado.
Na entrega inicial, nenhum arquivo produtivo nem teste anterior foi alterado; nenhum commit foi criado.

## Correção exclusiva dos três P1 — configuração e destinos

`LocalConfig.php` rejeita a entrada lexicalmente **antes de qualquer operação no
caminho**: aceita apenas letras ASCII, números, `_`, `-`, `.`, `/`, sem `..`, barras
duplicadas, espaços ou controles. `:`, `%`, barra invertida e Unicode são rejeitados;
isso inclui qualquer esquema/wrapper, inclusive os registrados dinamicamente. Não
há dependência de `allow_url_fopen`. Caminhos relativos simples e absolutos são aceitos.

Todos os componentes são inspecionados com `lstat`; links simbólicos, diretórios no
lugar do arquivo e arquivos especiais são recusados. Diretórios ancestrais graváveis
por grupo/outros são recusados, exceto diretórios sticky como `/tmp`. O arquivo precisa
existir, ser regular e ter de 1 a 65.536 bytes. Após `realpath`, a abertura é somente
leitura; tipo, dispositivo, inode, modo, tamanho e timestamps são conferidos com
`fstat` e nova inspeção do caminho **antes de ler qualquer byte**, e novamente após
leitura limitada. Há lock compartilhado não bloqueante. JSON não é executado nem
incluído; somente as dez propriedades do exemplo são aceitas, com tipos obrigatórios.
Não há propriedade para incluir outra configuração, credencial ou arquivo.

**Limite portátil:** PHP 8.1 não expõe `O_NOFOLLOW` em `fopen`. Assim, um symlink já
presente nunca é aceito, e uma troca detectada após abertura impede a leitura; não
se promete eliminar a janela entre `lstat` e `fopen`. Um escritor concorrente com
permissões suficientes ainda pode substituir o caminho nessa janela, inclusive
provocar bloqueio na abertura de arquivo especial. Use configuração e diretórios
sob controle exclusivo do operador confiável. A ferramenta falha fechada ao detectar
qualquer divergência e não libera transporte. Eliminar integralmente essa janela
requer primitivas não portáveis que não foram adicionadas nesta correção.

`IpPolicy.php` é a representação única de IP: validação textual estrita, conversão
binária, conversão de `::ffff:0:0/96` para IPv4, normalização com `inet_ntop` e comparação
binária. A forma canônica também é a enviada ao adaptador para `CURLOPT_RESOLVE`.
Notações IPv4 decimal inteiro, hex, octal, abreviada e zeros ambíguos são rejeitadas,
assim como zone IDs. Entradas numéricas inválidas não viram hostnames. Hostnames usam
labels DNS ASCII, sem porta, URI, fragmento, ponto final ou espaços. Hostname literal
IPv6 não é aceito; IPv6 é suportado como IP fixado de um hostname DNS.

Política IPv4 de negação por CIDR:

```text
0.0.0.0/8       10.0.0.0/8       100.64.0.0/10    127.0.0.0/8
169.254.0.0/16  172.16.0.0/12    192.0.0.0/24     192.0.2.0/24
192.88.99.0/24  192.168.0.0/16   198.18.0.0/15    198.51.100.0/24
203.0.113.0/24  224.0.0.0/4      240.0.0.0/4
```

Broadcast está incluído em `240.0.0.0/4`. Mapped IPv6 recebe a mesma política IPv4.
IPv6 aceita apenas o envelope global-unicast `2000::/3`, excluindo ainda `2001::/23`,
`2001:db8::/32`, `2002::/16`, `3ffe::/16` e `3fff::/20`. Isso bloqueia, entre outros,
unspecified, loopback, NAT64, discard, ULA, link-local, multicast, documentação,
ORCHID, Teredo e 6to4. É uma política conservadora: não comprova anúncio de rota,
alocação atual, posse do servidor ou autorização de uso. Não houve consulta externa
a registros de endereçamento nesta entrega.

Todas as entradas das listas de produção são validadas. IP inválido não é ignorado.
IPv4 e mapped equivalente bloqueiam um ao outro. Nome exato e subdomínios são
comparados sem distinção de caixa; um alias que use IP listado também é bloqueado.
Uma lista estática incompleta **não** identifica todos os aliases de produção.

A ordem agora é: argumentos → execute → caminho local → leitura limitada → esquema
completo → ambiente exato → hostname → IP canônico → faixas → listas de produção →
demais gates → criação tardia do transporte. A entrada CLI fornece uma factory;
`CurlTransport` só é construído depois de todos os gates. Os testes usam somente
factories/doubles falsos. Nenhuma opção permite contornar a política de faixas.

O exemplo continua usando IPs de documentação, portanto **não é executável** contra
transporte real. A configuração dos 63 cenários anteriores de testes foi ajustada
para IPs de formato público tratados exclusivamente como dados do transporte falso;
nenhuma asserção ou cenário foi removido. Acrescentados testes de wrappers em memória,
FIFO, dispositivo, symlinks, arquivos inexistentes/grandes, caminhos locais válidos,
canonicalização, limites das faixas, listas, aliases, argumentos e construção tardia.

Validação após correção: **225 testes do probe + 192 do módulo = 417 aprovados**, zero
reprovados, PHP 8.1.34 isolado; os 255 cenários anteriores permanecem. Todos os PHP
passaram no lint e os scripts shell na validação sintática. Nenhum transporte real,
credencial real ou serviço externo utilizado; nenhum commit criado.

### Pendências antes de mutações reais — não tratadas aqui

Escrita atômica, proteção contra replay/expiração e TOCTOU do manifesto, máquina
completa de estados, contratos positivos Enhance, limpeza supervisionada e homologação
real continuam pendentes. A sanitização anterior, endpoints, payloads e módulo
produtivo não foram alterados. Esta correção não autoriza executar o probe em nenhum
ambiente e não libera implantação em produção.
