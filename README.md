# ⚽ Mundial 2026 — Apuestas

App web de apuestas para el Mundial 2026 entre amigos. Sin registro, sin servidor, funciona directo en el navegador.

## 🚀 Ver la app

👉 **[mounstruo77.github.io/mundial-2026-apuestas](https://mounstruo77.github.io/mundial-2026-apuestas)**

## ✨ Funciones

- 👥 **Inscripción con monto** — cada participante entra con su nombre y su apuesta (mínimo **$10.000 CLP**, sin tope)
- 💰 **Pozo y premios** — el pozo es la suma de todos los montos y se reparte entre 1°, 2° y 3° lugar
- 🔒 **Participantes fijos** — una vez inscrito nadie puede borrarte; **solo el administrador (con PIN)** elimina participantes
- ⚽ **72 partidos de grupos** — predicciones Local / Empate / Visita
- 🏆 **Eliminación completa** — 16avos → Octavos → Cuartos → Semis → Final
- 🔄 **Bracket automático** — los ganadores avanzan solos de fase en fase
- 🌐 **Resultados automáticos** — sincroniza con openfootball/worldcup.json
- 🕐 **Horario Chile** — todas las horas en America/Santiago
- 🔒 **Apuestas bloqueadas** al iniciar cada partido
- 💾 **Backups automáticos** — nunca pierdas tus datos
- 📊 **Tabla general** con podio 🥇🥈🥉 en tiempo real

## 📋 Sistema de puntos

| Fase | Puntos |
|---|---|
| Fase de grupos | 3 pts |
| Dieciseisavos | 4 pts |
| Octavos | 6 pts |
| Cuartos | 9 pts |
| Semifinales | 12 pts |
| Tercer lugar | 14 pts |
| Final | 20 pts |

## 💰 Premios del pozo

El **pozo** es la suma de todos los montos apostados. Al terminar el Mundial se reparte entre los 3 primeros de la Tabla General, **ponderado por el monto apostado** (quien apuesta más y entra al podio gana proporcionalmente más):

| Lugar | % posición |
|---|---|
| 🥇 1° Lugar | 70 % |
| 🥈 2° Lugar | 20 % |
| 🥉 3° Lugar | 10 % |

> El premio de cada uno se calcula como `% de su posición × su monto apostado`, normalizado al pozo total. Así el reparto siempre suma el 100 % del pozo.

## 🔑 Administrador

El borrado de participantes está protegido con un **PIN de administrador** (se configura la primera vez desde el botón *Modo administrador* en la pestaña Jugadores). Sin el PIN solo se puede inscribir y apostar, no eliminar. Es una protección pensada para evitar borrados accidentales entre amigos.

---
Hecho con ❤️ para el Mundial 2026 🏆
