# Testes isolados de logging

Requer PHP 8.1 ou posterior. Não requer Composer nem qualquer pacote novo.
Para validar a versão alvo, execute com o binário PHP 8.1.34 de homologação:

```sh
php -n tests/run.php
```

`-n` ignora o php.ini, evitando extensões de instrumentação e o cURL normalmente
carregado como extensão. Se cURL estiver compilado estaticamente, o runner recusa
a execução; use outro binário de teste sem cURL. Ele não tenta instalar nada.
Nunca remova essa recusa para executar a suíte com HTTP real.

## Estrutura

- `run.php`: 47 cenários, com sentinelas fictícias, executando os métodos reais de
  transporte, login, recuperação e SSO, além das funções que persistem snapshots.
- `fakes.php`: funções cURL em memória, fila obrigatória de respostas, captura dos
  argumentos do logger e implementação mínima de banco para os snapshots.
- A instância da API é criada por reflexão, sem executar o construtor que altera
  campos e templates no WHMCS. Chamadas inesperadas a `localAPI()` falham.
- Os testes de Activity Log executam apenas as expressões de logging extraídas
  dos hooks, com mensagens de exceção/resultados contendo sentinelas. Não executam
  os hooks nem corrigem os defeitos de cron existentes.

## Cobertura

- Debug ligado/desligado, nenhuma saída de debug e nenhum escritor de arquivo
  no código de produção; comparação dos arquivos do repositório antes/depois.
- Senha, API key, Authorization, e-mail, URL/token de SSO, JSON aninhado/listas,
  variações de caixa nos nomes dos campos, strings serializadas e Base64.
- Preservação do body e cabeçalhos originais na requisição falsa e da resposta
  completa para os chamadores, incluindo quatro formatos de resposta SSO.
- HTTP 200, 204, 400, 401, 403, 429, 500, respostas JSON/não JSON e erro cURL.
- Rotas conhecidas, desconhecidas, IDs sensíveis no caminho e valores na query.
- Snapshots em inserções e atualizações, mensagens do Activity Log e lista
  permitida de campos do Module Log, inclusive dados processados vazios.

Os primeiros cinco argumentos de `logModuleCall` são verificados como candidatos
à persistência. O sexto é capturado separadamente: ele necessariamente contém a
API key/senha para a substituição adicional exigida pelo contrato do logger. Os
testes verificam sua presença ali, mas nunca imprimem essa lista. Isso não equivale
a persistir essas credenciais no log. A implementação real do WHMCS não está na suíte.

Nenhuma sentinela é uma credencial real. Falhas imprimem o nome do cenário, sem
valores nem mensagens potencialmente sensíveis. A fila cURL vazia causa falha, sem
fallback de rede. Esta suíte não valida as demais regras de negócio do módulo.

## Estado da execução nesta entrega

Não executada: o ambiente não disponibiliza PHP no PATH/caminhos usuais e o socket
Docker negou acesso à consulta de imagens locais. Também não foi possível executar
lint PHP. Revisão estática realizada; execução em PHP 8.1.34 continua pendente.
