# Wallet API

API de carteira digital desenvolvida em **PHP puro, sem framework**, com foco em fundamentos de engenharia de backend, modelagem financeira, integridade de dados, transações, concorrência, segurança e testes automatizados.

> 🚧 Projeto em desenvolvimento.

O objetivo não é apenas implementar endpoints CRUD, mas explorar problemas encontrados em sistemas financeiros, como controle de saldo, trilha de auditoria, atomicidade, autorização transacional, concorrência, idempotência e histórico imutável de movimentações.

---

## Tecnologias

- PHP 8.4
- MySQL 8.4
- Docker
- Docker Compose
- Composer
- PDO
- PSR-4
- PHPUnit 13

O ambiente é executado em containers e não depende de PHP ou MySQL instalados diretamente na máquina do desenvolvedor.

---

## Arquitetura atual

```text
wallet-api/
├── database/
│   └── migrations/
├── src/
│   ├── Application/
│   │   ├── Financial/
│   │   ├── Security/
│   │   └── User/
│   ├── Domain/
│   │   └── Account/
│   ├── Infrastructure/
│   │   ├── Database/
│   │   └── Persistence/
│   └── Support/
│       └── Identifier/
├── tests/
│   ├── Integration/
│   │   └── Application/
│   └── Unit/
├── Dockerfile
├── compose.yaml
├── composer.json
├── composer.lock
└── phpunit.xml
```

O projeto utiliza autoload PSR-4:

```text
App\ → src/
```

As responsabilidades são separadas de forma gradual conforme surgem necessidades reais no domínio, evitando criar camadas apenas por convenção.

---

## Decisões de domínio

### Valores monetários

Valores financeiros são persistidos como:

```sql
DECIMAL(19,2)
```

`FLOAT` e `DOUBLE` não são utilizados para dinheiro devido à representação aproximada desses tipos.

Durante cálculos financeiros da aplicação, os valores são convertidos para centavos inteiros, evitando cálculos monetários com ponto flutuante.

---

### Identificação das contas

A identidade técnica da conta é separada da identidade apresentada externamente.

```text
ID interno      → BIGINT UNSIGNED AUTO_INCREMENT
Public ID       → ULID de 26 caracteres
Agência         → 0001
Conta           → 6 dígitos
Dígito          → dígito verificador calculado
```

O número da conta não é utilizado como chave primária nem como relacionamento interno entre tabelas.

---

### Integridade no banco

Regras importantes também são protegidas pelo banco de dados.

Exemplos:

```text
saldo negativo             → bloqueado
agência + conta duplicadas → bloqueadas
valor financeiro <= 0      → bloqueado
```

Entre as constraints existentes:

```sql
CHECK (balance >= 0)
```

```sql
UNIQUE (agency, account_number)
```

```sql
CHECK (amount > 0)
```

A validação na aplicação melhora o comportamento para o usuário, enquanto as constraints do banco funcionam como última linha de defesa da integridade.

---

## Account Number Generator

A geração do número da conta está isolada no componente:

```text
AccountNumberGenerator
```

Responsabilidades:

- gerar conta de 6 dígitos;
- calcular dígito verificador;
- validar número e dígito;
- permitir substituição futura do algoritmo sem alterar o restante da aplicação.

O dígito verificador utiliza atualmente uma regra baseada em módulo 11.

---

## Identificadores públicos

Entidades expostas externamente utilizam ULID como identificador público.

A geração está isolada em:

```text
PublicIdGenerator
```

A implementação atual utiliza `symfony/uid`, mas o restante da aplicação não depende diretamente da biblioteca.

Isso permite substituir o mecanismo de geração futuramente sem espalhar essa dependência pelo domínio.

---

## Cadastro de usuário e conta

Usuário e conta são criados dentro da mesma transação de banco.

```text
BEGIN
 ↓
criação do usuário
 ↓
geração da conta
 ↓
criação da conta
 ↓
COMMIT
```

Se qualquer etapa falhar:

```text
ROLLBACK
```

Isso impede a existência de usuários cadastrados parcialmente, sem a conta correspondente.

O comportamento de rollback é validado por teste de integração.

---

## Modelo financeiro

O saldo não é tratado como um valor que pode ser alterado arbitrariamente.

Toda movimentação financeira deve possuir uma operação correspondente e produzir registros no ledger.

```text
Operation
   │
   ├── DEPOSIT
   ├── TRANSFER
   └── REVERSAL (planejado)
        │
        ▼
Ledger Entries
   ├── CREDIT
   └── DEBIT
```

As tabelas principais são:

```text
accounts
operations
ledger_entries
```

`accounts.balance` representa o estado atual otimizado para leitura.

`operations` representa o evento financeiro.

`ledger_entries` registra o impacto financeiro da operação sobre cada conta.

Os lançamentos do ledger armazenam:

```text
balance_before
balance_after
```

permitindo reconstrução e auditoria da movimentação.

O histórico financeiro é tratado como imutável por regra arquitetural. Correções futuras deverão ocorrer por operações compensatórias, e não pela alteração silenciosa do passado.

---

## Depósitos

O depósito é tratado como uma operação financeira atômica.

```text
BEGIN
 ↓
SELECT ... FOR UPDATE
 ↓
leitura do saldo
 ↓
criação da operação DEPOSIT
 ↓
atualização do saldo
 ↓
criação do lançamento CREDIT
 ↓
COMMIT
```

Caso qualquer etapa falhe:

```text
ROLLBACK
```

Um depósito gera:

```text
operations
type = DEPOSIT

ledger_entries
entry_type = CREDIT
```

O ledger registra o saldo antes e depois da movimentação.

Depósitos com valores inválidos são rejeitados antes da alteração do estado financeiro.

---

## Transferências

A transferência entre duas contas é executada dentro de uma única transação de banco.

As duas contas são bloqueadas antes da movimentação utilizando:

```sql
SELECT ... FOR UPDATE
```

Os locks são adquiridos em ordem crescente de ID das contas para reduzir o risco de deadlocks quando operações concorrentes envolvem as mesmas contas.

O fluxo atual é:

```text
BEGIN
 ↓
bloqueio das duas contas
 ↓
validação de propriedade da conta de origem
 ↓
autorização transacional
 ↓
validação de status
 ↓
validação de saldo disponível
 ↓
criação da operação TRANSFER
 ↓
débito da origem
 ↓
crédito do destino
 ↓
ledger DEBIT
 ↓
ledger CREDIT
 ↓
COMMIT
```

Se qualquer etapa falhar:

```text
ROLLBACK
```

Uma transferência de `25.50`, por exemplo, produz:

```text
Conta origem
DEBIT 25.50

Conta destino
CREDIT 25.50
```

Os dois lançamentos pertencem à mesma operação financeira.

Transferências com saldo insuficiente são rejeitadas sem alteração dos saldos e sem criação de histórico parcial.

---

## Idempotência de transferências

Transferências utilizam uma `idempotency_key` para impedir que a repetição da mesma requisição financeira movimente dinheiro mais de uma vez.

A chave é persistida em `operations` e possui constraint `UNIQUE` no banco de dados.

```text
mesma idempotency_key
+
mesma transferência
        ↓
operação original é reutilizada
        ↓
nenhum novo débito
nenhum novo crédito
nenhuma nova operação
```

O retorno identifica quando a requisição foi tratada como repetição idempotente:

```text
idempotent_replay = true
```

A primeira execução retorna:

```text
idempotent_replay = false
```

A chave também não pode ser reutilizada para representar outra movimentação.

```text
mesma idempotency_key
+
valor, origem, destino ou ator diferente
        ↓
requisição rejeitada
```

Isso protege cenários comuns em sistemas financeiros, como:

```text
cliente envia transferência
 ↓
servidor processa
 ↓
resposta sofre timeout
 ↓
cliente repete requisição
 ↓
sistema recupera a operação original
em vez de transferir novamente
```

Além da validação na aplicação, o banco possui uma constraint `UNIQUE` sobre `idempotency_key`, funcionando como última linha de defesa contra duplicidade.

---

## Autenticação e autorização transacional

Autenticação de acesso e autorização de uma operação financeira são responsabilidades diferentes.

```text
Autenticação
→ quem é o usuário?

Autorização transacional
→ este usuário pode executar esta operação?
```

Na V1, a mesma senha utilizada no acesso é reutilizada para revalidação da transferência.

Isso não significa que autenticação e autorização sejam a mesma responsabilidade.

Antes de uma transferência:

```text
usuário
 ↓
é proprietário da conta de origem?
 ↓
senha válida?
 ↓
conta ativa?
 ↓
saldo suficiente?
 ↓
transferência autorizada
```

A autorização atual é registrada como:

```text
authorization_method = PASSWORD
```

Uma senha válida não autoriza o usuário a movimentar uma conta pertencente a outra pessoa.

Tentativas com:

```text
senha inválida
ou
usuário não proprietário
```

são rejeitadas antes de qualquer movimentação financeira.

A arquitetura permite substituir ou complementar o mecanismo futuramente com:

- OTP;
- 2FA;
- biometria;
- autorização por dispositivo.

---

## Concorrência e consistência

O projeto já utiliza transações MySQL e row locking nas movimentações financeiras.

Depósitos utilizam lock da conta antes da leitura e atualização do saldo.

Transferências bloqueiam as duas contas envolvidas.

Isso evita o padrão vulnerável:

```text
ler saldo
calcular
gravar
```

quando duas operações concorrentes poderiam utilizar o mesmo saldo antigo e sobrescrever resultados.

A ordenação dos locks por ID reduz a possibilidade de deadlock entre transferências em sentidos opostos.

Testes específicos de concorrência executando operações simultâneas ainda fazem parte das próximas etapas.

---

## Testes

Os testes são executados com PHPUnit.

Atualmente:

```text
17 testes
77 assertions
100% passando
```

Executar toda a suíte:

```bash
docker compose run --rm app ./vendor/bin/phpunit
```

A cobertura comportamental atual inclui:

- geração de número de conta;
- cálculo e validação do dígito;
- geração de ULIDs;
- criação integrada de usuário e conta;
- rollback na criação de usuário e conta;
- depósito;
- atualização de saldo;
- operação `DEPOSIT`;
- ledger `CREDIT`;
- rejeição de depósito inválido;
- transferência entre duas contas;
- operação `TRANSFER`;
- ledger `DEBIT` e `CREDIT`;
- validação de saldo insuficiente;
- rollback de transferências inválidas;
- autorização transacional por senha;
- rejeição de senha incorreta;
- validação de propriedade da conta de origem;
- bloqueio da tentativa de movimentar conta de outro usuário.
- repetição idempotente de transferência;
- prevenção de débito duplicado;
- reutilização da operação financeira original;
- rejeição de `idempotency_key` reutilizada com dados diferentes.

Os testes de integração criam seus próprios dados e realizam limpeza respeitando as relações de foreign key.

---

## Executando o projeto

Clone o repositório e crie o arquivo de ambiente:

```bash
cp .env.example .env
```

Configure as variáveis necessárias.

Construa a imagem:

```bash
docker compose build
```

Suba o banco:

```bash
docker compose up -d database
```

Instale as dependências:

```bash
docker compose run --rm app composer install
```

Execute os testes:

```bash
docker compose run --rm app ./vendor/bin/phpunit
```

---

## Status do desenvolvimento

### Concluído

- [x] Ambiente PHP 8.4 containerizado
- [x] Composer e PSR-4
- [x] MySQL 8.4
- [x] PDO MySQL
- [x] Configuração por variáveis de ambiente
- [x] Migration de usuários e contas
- [x] Migration de operações e ledger
- [x] Constraints de integridade
- [x] ULID para identificadores públicos
- [x] Geração de número de conta
- [x] Dígito verificador
- [x] Criação transacional de usuário e conta
- [x] UserRepository
- [x] AccountRepository
- [x] OperationRepository
- [x] LedgerEntryRepository
- [x] Depósitos
- [x] Transferências
- [x] Ledger financeiro
- [x] Lançamentos CREDIT
- [x] Lançamentos DEBIT
- [x] Registro de balance_before e balance_after
- [x] Rollback automático em falhas
- [x] Validação de saldo disponível
- [x] Row locking com SELECT FOR UPDATE
- [x] Ordenação de locks para redução de deadlocks
- [x] Proteção inicial contra race conditions
- [x] Separação entre autenticação e autorização transacional
- [x] Revalidação de senha em transferências
- [x] Validação de propriedade da conta de origem
- [x] Bloqueio de transferência com senha inválida
- [x] Bloqueio de movimentação de conta de terceiro
- [x] PHPUnit
- [x] Testes unitários
- [x] Testes de integração
- [x] Idempotency key em operações financeiras
- [x] Constraint UNIQUE para idempotency_key
- [x] Replay idempotente de transferências
- [x] Prevenção de débito/crédito duplicado
- [x] Detecção de conflito de idempotency key
- [x] 17 testes / 77 assertions

### Próximas etapas

- [ ] Testes de concorrência real
- [ ] Estornos com operação compensatória
- [ ] Split Payment IBS/CBS
- [ ] API HTTP REST

---

## Roadmap financeiro

### Concorrência real

Executar testes com operações simultâneas para validar:

- row locking;
- consistência dos saldos;
- ausência de lost updates;
- comportamento diante de deadlocks.

---

### Estornos

Estornos não deverão alterar ou apagar movimentações antigas.

Uma reversão será registrada como nova operação relacionada à original:

```text
TRANSFER original
       ↓
REVERSAL
       ↓
lançamentos compensatórios
```

---

### Split Payment IBS/CBS

O projeto deverá incluir uma implementação de estudo do **Split Payment relacionado ao IBS/CBS**, considerando a evolução do modelo tributário brasileiro.

A intenção é utilizar a infraestrutura financeira já construída para explorar:

- segregação entre valor comercial e tributos;
- rastreabilidade no ledger;
- operações vinculadas;
- idempotência;
- conciliação;
- retries;
- pagamentos parcelados;
- integração simulada com especificações oficiais.

A implementação será baseada na documentação oficial disponível quando essa etapa for iniciada.

---

### API HTTP

Após consolidar as regras financeiras e suas garantias, o domínio será exposto por uma API HTTP REST.

A camada HTTP não deverá conter as regras financeiras, funcionando como porta de entrada para os casos de uso já existentes.

---

## Objetivo técnico

Este projeto está sendo desenvolvido para aprofundar conhecimentos de backend PHP sem depender inicialmente das abstrações fornecidas por frameworks.

A intenção é implementar e compreender diretamente conceitos como:

- orientação a objetos;
- domínio;
- persistência;
- transações;
- concorrência;
- autorização;
- segurança;
- integridade de dados;
- ledger financeiro;
- idempotência;
- testes automatizados;
- arquitetura;
- APIs REST.

Frameworks poderão ser utilizados posteriormente para comparação, depois que os fundamentos estiverem implementados diretamente em PHP.
