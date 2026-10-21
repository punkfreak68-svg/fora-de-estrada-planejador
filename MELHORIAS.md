# Fora de Estrada — lista de melhorias

Anotações do Luiz durante o teste de campo (iniciado em 18/10/2026, bairro Sapezeiro).
Situação: app Android v6.1 / APK build 2 passou em todos os testes de campo (GPS com tela apagada, notificação, botão voltar, voz, modo dia, bateria).

## Melhorias anotadas

1. **App Android: a tela fica por baixo das barras do celular** (18/10, 08:46, com print)
   - Em cima: a pílula "Modo Livre" / "No caminho" e o botão "Limite: 50m" ficam por baixo da hora, dos ícones e da bateria.
   - Embaixo: os botões "Salvar Trajeto" e "Encerrar Navegação" ficam por baixo da barra de navegação do Android (os botões de voltar e início).
   - Acontece no Modo Livre, na navegação com rota e também no painel inicial (print das 11:02: a hora fica por cima do logo e o "Auto" fica por baixo dos ícones); ou seja, em todas as telas do app.
   - Nota técnica: o Android 15 desenha o app de ponta a ponta (edge-to-edge). Correção: reservar o espaço das barras (capacitor.config `android.adjustMarginsForEdgeToEdge`, ou insets/safe-area no CSS), o que exige um APK novo.

2. **Desvio de atoleiro: registrar o obstáculo e guardar o caminho alternativo** (18/10, 08:47 e 08:49, com print)
   - Situação: na trilha havia um atoleiro intransitável. O Luiz desviou por um caminho ao norte (laço azul no print) e voltou à rota laranja logo depois. O ponto do atoleiro fica no trecho reto laranja, perto de onde ele saiu da rota.
   - O Luiz não quer perder essa anotação.
   - **Decisão do Luiz (08:49): não trocar o trecho; guardar OS DOIS caminhos.** Seco, dá para passar pelo trecho original; molhado, usa o desvio. Conceito: "caminho seco" × "caminho molhado".
   - Ideias para o desenho (a detalhar):
     a) Ao encerrar, o app detecta o desvio (saiu da rota e voltou) e pergunta "Guardar este desvio como caminho alternativo?". Ele vira uma **variante** da rota, ligada aos pontos de saída e de volta, com um nome/tipo: "Caminho molhado (desvio de atoleiro)". O trecho original pode ser marcado como "Caminho seco – só passa sem chuva".
     b) No mapa, a variante aparece com outro estilo (por exemplo, tracejada e de outra cor), com uma legenda.
     c) **Marcação de perigo** com tipos (atoleiro, ponte caída, porteira fechada, erosão, água funda...), ícone no mapa e aviso por voz ao se aproximar ("Atenção: atoleiro em 200 metros. Há um desvio para tempo molhado").
     d) Antes de navegar, ou na bifurcação, escolher "Seco" ou "Molhado". Talvez sugerir conforme a chuva dos últimos dias; já usamos o Open-Meteo para o clima. **O Luiz aprovou com entusiasmo (08:49): "Sensacional a sugestão de seco ou molhado".**
     e) Data no aviso ("atoleiro visto em 18/10/2026") e opção de marcar como "resolvido".
     f) Exportar GPX e Comunidade levam as variantes e os avisos juntos.
   - Verificado (19/10): na navegação com rota, o "Marcar local" grava o ponto direto na rota salva. Funciona.

3. **Tomada de Tempo → "Rally": salvar os tempos e fazer ranking** (18/10, 08:51)
   - O Luiz acha que a tela "Tomada de Tempo" deveria se chamar **Rally**. **Nome A CONFIRMAR depois com o Luiz (pediu às 08:53 para deixar para depois).**
     - Atenção: já existe o "Modo Rally" (botão da bandeira na navegação, roadbook/tulipas). Decidir os nomes para não confundir. Exemplos: "Rally" para a cronometragem e "Roadbook" para o modo da bandeira; ou juntar os dois numa área "Rally".
   - Problema relatado: **não salva o tempo do percurso**.
     - Verificar no código: se existe histórico de corridas (há `timingShareText(route, run)`) e se ele se perde ou não aparece para o usuário.
   - Pedido: **classificar os tempos** (ranking por percurso).
   - Ideias (a detalhar):
     a) Histórico de cada percurso cronometrado: data, tempo total, parciais por trecho, velocidade média, veículo, condição (seco/molhado — liga com o item 2).
     b) Ranking pessoal do percurso: melhor tempo (recorde), posição de cada tentativa, diferença para o recorde ("+12,4 s").
     c) Durante a corrida, comparar com o recorde em tempo real (à frente ou atrás, "fantasma").
     d) Futuro: ranking entre amigos ou na Comunidade para o mesmo percurso.
     e) Guardar na conta, para não perder ao trocar de celular.

4. **Durante a navegação, não dá para voltar ao menu principal** (18/10, 08:53)
   - Navegando, o Luiz não consegue ir ao painel/menu sem encerrar a navegação.
   - Causa provável: a tela de navegação não tem botão de "voltar ao menu"; e, no app Android (v6.0), o botão voltar do celular só minimiza durante a navegação (isso foi feito de propósito, para não perder a navegação sem querer).
   - Ideia: um botão "Menu" ou "Início" na navegação que vai ao painel **sem parar a navegação** (o GPS continua gravando). No painel aparece uma faixa fixa "🧭 Navegação em andamento — toque para voltar", com distância e tempo. Assim dá para ver rotas, mandar a posição etc. e voltar.
   - Cuidado: o "Encerrar Navegação" continua sendo o único jeito de parar.

5. **Zoom no mapa demora a carregar (fica cinza)** (18/10, 09:02, com print)
   - Na navegação, ao dar zoom, o mapa ficou todo cinza por um tempo; apareciam só a rota laranja e o trajeto azul.
   - Prováveis causas: sinal fraco na roça e imagens de satélite não guardadas para aquele nível de zoom.
   - Ideias:
     a) Mostrar o nível anterior "esticado" enquanto o novo carrega (Leaflet: keepBuffer maior, updateWhenZooming/updateWhenIdle, zoom "fantasma" do nível de baixo).
     b) Limitar o zoom máximo ao que foi baixado para uso offline (ou avisar "sem mapa baixado neste zoom").
     c) Baixar automaticamente, ao iniciar a navegação, a faixa da rota em mais níveis de zoom, se houver internet.
     d) Mostrar um indicador "carregando mapa…" em vez do cinza mudo.

6. **Marcar um ponto (atoleiro) longe de onde estou** (18/10, 09:02, com print)
   - O Luiz precisava marcar um atoleiro, mas não conseguia chegar até ele para usar o "Marcar local" (o pin só marca a posição atual). No print ele estava "Fora da rota (123 m)", desviando; o atoleiro fica na rota laranja, perto de onde o trajeto azul saiu dela (canto inferior esquerdo do print).
   - Ideia: **tocar e segurar no mapa** para marcar ali (atoleiro/perigo/ponto), com tipo, nota e foto. Também: mover o pin arrastando depois de criado.
   - Liga com o item 2 (marcação de perigo, seco × molhado).
   - Segundo atoleiro do passeio de hoje (o primeiro foi o do item 2).

7. **Navegação com rota não salva o caminho percorrido** (18/10, 09:16, com print do resumo)
   - Fim do passeio: 25,8 km percorridos, 1h25 no total (50 min em movimento, 35 min parado), média de 30,8 km/h, máxima de 86 km/h, altitude máxima de 634 m.
   - Confirmado no código: no resumo da navegação com rota só existe "Voltar ao Início". O botão "Salvar Trajeto" aparece só no Modo Livre, e a sessão salva (v5.9) é apagada ao encerrar. **O trajeto azul se perde ao sair do resumo**, com os desvios dos atoleiros junto. O que sobra do passeio de hoje são os prints.
   - Correção (prioridade alta):
     a) Botão "Salvar trajeto percorrido" no resumo da navegação com rota.
     b) Guardar automaticamente um **histórico de passeios** (data, rota, trajeto, estatísticas, marcações), mesmo sem tocar em nada.
     c) Não apagar a sessão até o usuário salvar ou descartar.

8. **Não dá para marcar pontos enquanto dirige** (18/10, 09:16)
   - Houve mais atoleiros/desvios que o Luiz não conseguiu marcar, porque estava atento à estrada (no print, vários trechos onde o azul sai do laranja: o grande laço no alto à esquerda e trechos no alto à direita).
   - Ideias:
     a) **Desvios detectados automaticamente**: no resumo, o app lista "Você saiu da rota em N lugares" e mostra cada trecho no mapa. Em cada um o usuário escolhe: atoleiro (caminho seco × molhado, item 2), rota errada, atalho, ignorar.
     b) **Marcação rápida sem tirar o olho da estrada**: um botão grande de um toque ("marcar aqui", nota depois), comando de voz ("marcar atoleiro") ou botão de volume/fone bluetooth.
     c) Marcar "depois" no mapa do resumo, tocando e segurando (item 6).

9. **Painel inicial: mostrar o tempo do último trajeto feito** (18/10, 11:02, com print do painel)
   - No cartão "Última rota" (hoje mostra Distância 21,3 km, Pontos 339, Marcações 4 da rota "Zoia Dgua x Chacara", pasta Trilhas SBO), acrescentar o **tempo do último percurso realizado**.
   - Vale para os dois modos: **passeio** (navegação) e **rally** (Tomada de Tempo).
   - Ideias:
     a) Passeio: "Última vez: 18/10 · 1h25 (50 min em movimento) · 25,8 km". Depende do histórico de passeios do item 7.
     b) Rally: "Seu melhor: 12:34 · última: 13:02 (+28 s)". Depende de salvar os tempos (item 3).
     c) Tocar abre o histórico/ranking daquela rota.

10. **Do asfalto para a trilha: Waze e Fora de Estrada juntos** (20/10, conversa sobre o Radarbot)
   - Como o Luiz usa hoje: Waze no asfalto (radares e trânsito) e, quando entra no mato, muda para o Fora de Estrada.
   - Ideias (a decidir):
     a) Botão **"Ir até a largada pelo Waze"** (ou Google Maps) na rota: abre o Waze já navegando até o primeiro ponto da trilha.
     b) Modo **"a caminho da largada"**: se a navegação for iniciada longe do começo, o app não grita "fora da rota". Mostra a distância até a largada e avisa "Chegou na trilha" quando você entra nela, já começando a gravar.
     c) **Radares no trecho de asfalto** dentro do próprio Fora de Estrada, usando dados abertos do OpenStreetMap (radares fixos cadastrados, grátis, com atribuição). Cobertura menor que a do Waze/Radarbot e sem radar móvel/blitz.
     d) **Limite de velocidade da via** (dado do OpenStreetMap, quando existir) no painel da navegação.
     - Descartado pelo Luiz (20/10): transformar o Fora de Estrada em navegador completo de estrada (rotas curva a curva no asfalto). Ele continua usando o Waze no asfalto.
     e) **Alertas da comunidade** estilo Radarbot, para a trilha: atoleiro, porteira fechada, ponte caída, compartilhados entre quem usa a mesma rota. Liga com o item 2f.

11. **"Estou perdido": achar a saída / a estrada mais próxima** (20/10)
   - Pedido do Luiz: perdido no mato, poder escolher um destino próximo e o app mostrar a estrada mais próxima, asfaltada ou de terra.
   - Ideias (a decidir), pensando que no mato quase nunca tem sinal:
     a) **Voltar pelo caminho que fiz** (funciona sem internet): o trajeto gravado vira a rota de volta, com seta, distância e voz, até o último ponto conhecido ou o início.
     b) **Bússola até um ponto** (sem internet): tocar no mapa (ou num ponto salvo, como o carro ou o acampamento) e o app mostra seta, distância e rumo em linha reta.
     c) **Estrada mais próxima:** busca as estradas do OpenStreetMap em volta (asfalto, terra, carreador) e mostra a mais perto, com o tipo, a distância e a direção. Com internet, na hora; sem internet, só se as estradas da região tiverem sido baixadas antes junto com o mapa offline da rota.
     d) **Pedir ajuda:** junto do "Enviar minha posição" (SMS funciona sem internet), um botão de emergência com as coordenadas e o 193.

## Status
Ordem combinada com o Luiz (19/10): 7 e 1 → 4 e 6 → 2 e 8 → 3 e 9 → 5.

- ✅ **Item 7 — FEITO na v6.2 (19/10):** histórico de passeios automático (Rotas Salvas › Passeios feitos), botão "Salvar como rota" no resumo da navegação com rota, e o passeio de 18/10 recuperado da sessão anterior.
- ✅ **Item 1 — FEITO na v6.2 (19/10), precisa do APK novo (build 3):** margens para as barras do Android 15 (capacitor `adjustMarginsForEdgeToEdge: auto`) e faixas escuras atrás da hora e dos botões.
- ✅ **Item 4 — FEITO na v6.3 (19/10):** botão da casinha na navegação vai ao menu sem parar; faixa verde "🧭 Navegando: … · GPS gravando" em todas as telas leva de volta; "Navegar"/"Iniciar navegação" levam à navegação em andamento em vez de começar outra; voltar do Android também leva de volta.
- ✅ **Item 6 — FEITO na v6.3 (19/10):** segurar o dedo no mapa da navegação abre a marcação naquele ponto (o editor já tem os tipos Atoleiro, Porteira, Água/travessia, Perigo…).
- ✅ **Item 2 — FEITO na v6.4 (19/10):** desvio guardado como "caminho molhado" (roxo) sem apagar o trecho principal; atoleiro marcado no trecho seco com a data; antes de navegar pergunta ☀️ Seco × 🌧️ Molhado com sugestão pela chuva dos últimos 3 dias (Open-Meteo, ≥10 mm = molhado); no molhado o trecho com atoleiro fica vermelho tracejado; andar no desvio não conta como fora da rota; aviso por voz perto de atoleiro/perigo/água/porteira.
  - Ficou para depois: 2e "marcar como resolvido" (o campo já existe nas marcações; falta o botão) e 2f levar os desvios no GPX/Comunidade.
- ✅ **Item 8 — FEITO em parte na v6.4 (19/10):** 8a desvios detectados sozinhos no resumo e no histórico (↪ Desvios), com opções Atoleiro/caminho molhado, Caminho alternativo, Ignorar — dá para classificar o passeio de 18/10 recuperado; 8c marcar depois segurando no mapa (item 6).
  - Ficou para depois: 8b marcação rápida por voz ou botão do fone (perguntar ao Luiz se ainda precisa).
- ✅ **Item 3 — FEITO na v6.5 (19/10):** a causa provável do "não salva" era que a tomada encerrada antes da chegada era descartada; agora fica no histórico como incompleta. Histórico com 🏆 Ranking (1º 🥇, 2º 🥈… e diferença para o recorde) e 📅 Por data; a lista mostra o melhor tempo e a última tomada. O "fantasma" ao vivo (à frente/atrás do recorde) já existia.
  - **Nome "Rally" × "Tomada de Tempo": A CONFIRMAR com o Luiz** (não mudei).
  - Ficou para depois: guardar seco/molhado na tomada; ranking na conta/Comunidade.
- ✅ **Item 9 — FEITO na v6.5 (19/10):** cartão da última rota mostra "🧭 Último passeio: data · tempo (andando) · km" e "⏱️ Rally: melhor · última (+Δ)"; tocar abre o histórico/ranking. A "última rota" passa a ser a última navegada.
- ✅ **Item 5 — FEITO na v6.5 (19/10):** na navegação, camada reserva por baixo com as imagens guardadas no aparelho (zoom 13–16, esticadas em zoom maior, sem gastar internet) e aviso "Carregando mapa…" após 1,5 s.
- ✅ **Item 10 — FEITO na v6.6 (20/10), ideias a e b:** link "ir até a largada pelo Waze" no confirmar e botão "Abrir no Waze" na navegação; modo "a caminho da largada" (sem alarme, sem gravar até entrar na trilha; aviso "Chegou na trilha" por voz e começa a gravar). Ideias c–e (radares, limite de velocidade, alertas da comunidade) ficaram para depois.
- ✅ **Item 11 — FEITO na v6.6 (20/10):** botão 🆘 (boia vermelha) na navegação: voltar pelo caminho que fiz (linha verde + seta, sem internet), ir até um ponto (largada, fim, onde comecei, marcações ou tocar no mapa; sem internet), estrada mais próxima (Overpass/OpenStreetMap, 5 km, terra e asfalto, precisa de internet) e pedir ajuda (coordenadas, enviar posição, ligar 193/190/192).
  - Ficou para depois: baixar as estradas junto com o mapa offline, para a "estrada mais próxima" funcionar sem sinal. Lembrar de liberar overpass-api.de e waze.com na revisão da CSP.
  - v6.6.1: estradas em volta da rota guardadas no aparelho ao iniciar a navegação (funciona sem sinal).
  - ✅ v7.1 (21/10): intermediário no servidor (api/roads.php) — busca no Overpass com paciência, tenta servidores reserva e grupos menores, guarda por quadrado do mapa (~3 km; asfalto ~22 km) por 90 dias. 1ª busca num lugar ~40 s, depois 0,2 s. Se o servidor falhar, o app ainda pergunta direto ao Overpass.
  - ✅ 21/10: CSP passou a bloquear (sai do Report-Only), com api.github.com e Overpass liberados.
- **Pendências para conversar:** nome Rally (item 3); 2e botão "resolvido" no atoleiro; 2f desvios no GPX/Comunidade; 8b marcação rápida por voz/botão do fone; testar no celular o APK novo (item 1).
