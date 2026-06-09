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

## 🌐 Modo online compartido (con servidor PHP)

La app tiene **dos modos**:

- **Modo local** (GitHub Pages o abrir el archivo): los datos se guardan solo en tu navegador. Sirve para llevar el control desde un único dispositivo.
- **Modo online compartido** (en un hosting con PHP, como cPanel): **todos ven y editan lo mismo en tiempo casi real**. Requiere subir `api.php` junto a `index.html`.

### Cómo funciona el modo online

- `api.php` guarda el estado compartido en un archivo JSON **fuera de la carpeta web** (no accesible por URL).
- El **administrador** (PIN) gestiona jugadores, montos y resultados.
- Cada jugador recibe un **código personal** (lo genera la app y lo ve el admin) para entrar y editar **solo sus** predicciones.
- Las predicciones de los demás se **ocultan hasta que el partido empieza** (para no copiar).
- La pantalla se actualiza sola cada pocos segundos (*polling*).

### Despliegue

1. Sube `index.html` y `api.php` a una carpeta de tu hosting (ej. `public_html/mundial/`).
2. Abre `https://tudominio/mundial/`.
3. Pulsa **Modo administrador** y crea tu PIN.
4. Inscribe a los jugadores y comparte a cada uno su **código personal**.

> ⚠️ GitHub Pages **no ejecuta PHP**, así que ahí la app funciona solo en modo local. El modo compartido necesita un hosting con PHP.

---
Hecho con ❤️ para el Mundial 2026 🏆
