# Consumos de Casa (homeexpenses)

App Nextcloud para registar o que a casa consome: leituras do contador da eletricidade, do
contador da água, e garrafas de gás desde que entram até ficarem vazias.

Não é uma app de contas a pagar. Para faturas com data-limite e estado de pagamento existe a
app **Contas** (`bills`). Esta mede **consumo**.

## A ideia

### Os três registos valem mais do que a fatura

Em Portugal é normal ter um contador **tri-horário** — o mostrador tem Vazio, Cheias e Ponta —
com um contrato **bi-horário**, que só fatura dois escalões. Isso funciona porque as horas de
Vazio coincidem nas duas opções, e *Fora de Vazio = Cheias + Ponta*:

| | Vazio | Resto |
|---|---|---|
| Bi-horário, dias úteis | 00:00–07:00 | Fora de Vazio 07:00–24:00 |
| Tri-horário, dias úteis (inverno) | 00:00–07:00 | Cheias 07:00–09:30, 12:00–18:30, 21:00–24:00 · Ponta 09:30–12:00, 18:30–21:00 |

A fatura soma dois dos registos e deita fora a distinção. Esta app guarda os três em bruto, e
por isso consegue responder **com exatidão, não por estimativa**, à pergunta *"o tri-horário
sairia mais barato do que o bi-horário que tenho?"* — é aritmética sobre kWh medidos:

```
custo_bi  = V·preço_vazio + (C+P)·preço_fora_vazio
custo_tri = V·preço_vazio + C·preço_cheias + P·preço_ponta
```

É o endpoint `GET /api/v1/meters/{id}/compare`.

Repara no que isto **evita**: a app não precisa de saber tabela nenhuma de horários, porque o
contador já fez a separação. Fica imune, de caminho, à alteração dos períodos horários que a
ERSE vai fazer em 2027.

### Nada do que se deriva é guardado

Consumo entre leituras, nível da garrafa, duração de um ciclo e comparação de tarifários são
sempre calculados a partir dos valores em bruto. Guardados, ficariam errados na primeira vez
que se corrigisse uma leitura lançada com um dígito trocado — e nada no sistema saberia que
tinha de os recalcular.

### Decisões que evitam números errados

- **A data é a que está no contador**, não a de hoje (`readAt`). Lançar hoje uma leitura de há
  um mês com a data de hoje faz o consumo por dia ficar errado.
- **Estimativas são marcadas** (`isEstimate`). Misturadas com leituras reais sem distinção,
  estragam qualquer média.
- **A tara é por garrafa, não por tipo.** Vem gravada na gola e varia entre garrafas iguais.
  Sem ela o nível devolve `null` em vez de usar um valor nominal, que daria um erro
  sistemático de meio quilo.
- **Uma leitura que desce é assinalada, não corrigida em silêncio.** Só se corrige a volta ao
  zero (99999 → 0) quando o contador declara quantos dígitos tem.
- **Um escalão sem preço não vale zero.** O tarifário é marcado como não comparável, porque um
  total errado para baixo é pior do que um total ausente: parece credível.

### Uma só superfície de API

A interface web usa exatamente os mesmos endpoints OCS que um agente externo usaria. Não há um
conjunto de rotas para o SPA e outro para a API, e por isso não há como divergirem.

## API

Autenticação por **app password** (Definições → Segurança → Criar nova app password), com o
cabeçalho `OCS-APIRequest: true` em todos os pedidos.

```bash
curl -u 'utilizador:app-password' -H 'OCS-APIRequest: true' \
  https://nuvem.exemplo/ocs/v2.php/apps/homeexpenses/api/v1/help
```

O endpoint `/help` é público e descreve a API toda — conceitos, endpoints e as armadilhas do
domínio. É por aí que um agente começa.

### Começar

```bash
BASE=https://nuvem.exemplo/ocs/v2.php/apps/homeexpenses/api/v1
AUTH='-u utilizador:app-password -H OCS-APIRequest:true -H Content-Type:application/json'

# Contador tri-horário, contrato bi-horário -- o caso normal
curl $AUTH -X POST "$BASE/meters" -d '{
  "name": "Contador da luz", "kind": "electricity",
  "tariffOption": "bi", "registerCodes": ["V","C","P"], "digits": 5
}'

# Leitura (a data é a do contador)
curl $AUTH -X POST "$BASE/meters/1/readings" -d '{
  "readAt": "2026-10-01", "values": {"V": 12345, "C": 23456, "P": 3456}
}'

# Os dois tarifários a comparar
curl $AUTH -X POST "$BASE/tariffs" -d '{
  "name": "Bi-horário", "tariffOption": "bi", "validFrom": "2026-01-01",
  "prices": {"V": 0.10, "FV": 0.20}, "standingChargeDay": 0.25
}'
curl $AUTH -X POST "$BASE/tariffs" -d '{
  "name": "Tri-horário", "tariffOption": "tri", "validFrom": "2026-01-01",
  "prices": {"V": 0.10, "C": 0.18, "P": 0.30}, "standingChargeDay": 0.25
}'

# A resposta
curl $AUTH "$BASE/meters/1/compare"
```

### Importar faturas

A fatura é lida pelo seu **QR code fiscal ATCUD**, por um serviço externo
(`qrcode.appa8.com`, configurável). Tem de ser o PDF original do fornecedor — não
uma fotografia nem uma impressão.

```bash
curl -u 'utilizador:app-password' -H 'OCS-APIRequest: true' \
  -F 'file=@fatura.pdf' -F 'meterId=1' \
  "$BASE/invoices/import"
```

A resposta traz `{created, existing, warnings}`. **Lê sempre os avisos**: uma linha de
energia que não entre nos totais aparece lá, e ignorá-la deixa o total a menos com ar de
certo.

Três coisas que uma fatura da EDP faz, e que moldam o que a app guarda:

- **As linhas vêm partidas por taxa de IVA, não por registo.** "Consumo real Vazio"
  aparece duas vezes — parte do consumo leva IVA reduzido. Quem tratar cada linha como um
  registo fica com metade do consumo. A proporção 6%/23% **não é calculada por regra**: é
  lida da fatura, porque a regra legal muda.
- **Um período de faturação pode conter duas tarifas.** Numa mudança de tarifário a meio
  do mês, a mesma fatura traz "Simples" de um intervalo e "Vazio"/"Fora vazio" de outro.
- **Um PDF traz vários documentos fiscais.** A eletricidade e a Contribuição Audiovisual
  são faturas separadas. O ATCUD distingue-as, e torna a importação idempotente.

> **Importar faturas não substitui ler o contador.** A fatura só traz os escalões que o
> contrato fatura: num contrato bi-horário traz `V` e `FV`, nunca `C` e `P` separados. As
> duas fontes são complementares — as leituras respondem a *"o tri-horário compensava?"*,
> a fatura diz quanto custam a potência, as redes e os impostos.

### Previsão

```bash
curl $AUTH "$BASE/meters/1/forecast?days=30"
```

Projeta consumo e, havendo fatura de onde derivar o modelo de custo, também o valor a
pagar. Usa as duas fontes **sem as somar**: períodos sobrepostos são o mesmo consumo
contado duas vezes, e ganha a leitura.

Lê `confidence` e `caveats` antes dos números. Com menos de dois períodos devolve
`projected` a `null` em vez de um valor — um número errado com duas casas decimais é pior
do que a ausência dele, porque parece credível. E enquanto não houver um ano de histórico,
a resposta diz que a sazonalidade não está contabilizada, e em que direção o erro vai.

### Garrafas de gás

```bash
curl $AUTH -X POST "$BASE/gas/types" -d '{
  "brand": "Galp", "gasType": "butano", "nominalKg": 13, "defaultTareKg": 15.2
}'

# Garrafa nova. A tara é a gravada NESTA garrafa.
curl $AUTH -X POST "$BASE/gas/cycles" -d '{
  "bottleTypeId": 1, "installedAt": "2026-10-01",
  "appliance": "ambos", "tareKg": 15.1, "pricePaid": 34.00
}'

# Pesagem: peso TOTAL na balança
curl $AUTH -X POST "$BASE/gas/cycles/1/weighings" -d '{
  "weighedAt": "2026-10-20", "grossKg": 24.3
}'

# Acabou
curl $AUTH -X PUT "$BASE/gas/cycles/1" -d '{"removedAt": "2026-12-05"}'
```

Com duas pesagens, o ritmo de consumo passa a ser medido em vez de estimado, e a app diz quando
a garrafa deve acabar. Uma garrafa que alimente esquentador **e** fogão não permite separar o
consumo dos dois — nenhuma pesagem o consegue — e o campo `appliance` serve para comparar
ciclos, não para atribuir consumo.

## Desenvolvimento

```bash
composer install        # dependências PHP de desenvolvimento
npm install && npm run build

composer run test:unit  # ou: vendor/bin/phpunit tests -c tests/phpunit.xml
```

Os testes são unitários puros sobre `ConsumptionService`, que não tem dependências nem toca na
base de dados. Correm **sem um Nextcloud à volta** — `tests/bootstrap.php` usa os stubs do
pacote `nextcloud/ocp` quando não encontra um servidor, o que os torna executáveis em CI.

## Licença

AGPL-3.0-or-later
