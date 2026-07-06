# ⚽ Mundial 2026 — Apuestas

App web de apuestas para el Mundial 2026 entre amigos: frontend en un solo
`index.html` (vanilla JS) + un backend PHP mínimo que comparte el estado entre
todos los participantes y hace de proxy a API-Football para resultados en vivo.

## 🚀 Ver la app

👉 **https://drsoporte.cl/mundial/**

## 🧱 Arquitectura

| Archivo | Rol |
|---|---|
| `index.html` | Toda la interfaz y la lógica de juego (vanilla JS, sin dependencias) |
| `api.php` | Estado compartido: jugadores, apuestas, resultados. Persiste en un JSON fuera del web root, con locks de archivo |
| `live.php` | Proxy con caché a [API-Football](https://www.api-football.com/) (marcadores en vivo, calendario KO, goleadores, arqueros, posiciones) |
| `schedule.json` | Horario `matchId → kickoff` (hora CDMX): candado del servidor para bloquear apuestas una vez iniciado cada partido |
| `.htaccess` | Cabeceras de seguridad, bloqueo de archivos sensibles y `Cache-Control` de `index.html` |

**No versionados** (secretos/datos): `live-config.php` (API key), `apifootball.key`,
`state.json` (apuestas de los usuarios), carpetas de caché.

## ✨ Funciones

- 👥 **Registro con aprobación** — cada participante se inscribe con nombre, monto y clave; el admin aprueba
- 💰 **Pozo y premios** — el pozo suma todos los montos (mínimo **$10.000 CLP**) y se reparte 70/20/10 ponderado por monto apostado
- ⚽ **72 partidos de grupos** — predicción Local / Empate / Visita
- 🏆 **Eliminación completa** — 16avos → Octavos → Cuartos → Semis → 3er lugar → Final, con bracket que avanza solo
- 🌐 **Resultados automáticos** — API-Football vía `live.php` (respaldo: openfootball/worldcup.json)
- 📊 **Estadísticas en vivo** — posiciones oficiales, top goleadores y top arqueros del torneo
- 📺 **Panel en vivo por partido** — marcador, alineaciones, eventos y estadísticas
- 🕐 **Horario Chile** — el calendario interno va en hora CDMX (UTC-6) y se muestra en America/Santiago
- 🔒 **Apuestas bloqueadas al inicio de cada partido**, validado en cliente **y** servidor
- 🔄 **Auto-actualización** — la página detecta versiones nuevas y se recarga sola

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

| Lugar | % posición |
|---|---|
| 🥇 1° Lugar | 70 % |
| 🥈 2° Lugar | 20 % |
| 🥉 3° Lugar | 10 % |

> El premio de cada uno se calcula como `% de su posición × su monto apostado`,
> normalizado al pozo total, así el reparto siempre suma el 100 % del pozo.

## 🔐 Seguridad

- PIN de admin y claves de jugador guardadas **hasheadas** (SHA-256 con sal fija de app)
- Cada usuario solo puede escribir **su propia** predicción (verificación de clave por request)
- Rate-limiting por IP en lecturas, escrituras, auth y registro
- Validación de entrada en servidor (formato de `matchId`, valores de apuesta, montos)
- Datos y API key fuera del web root; `.htaccess` bloquea archivos sensibles
- El estado público expone solo hashes reducidos a "tiene/no tiene clave"

## 🛠 Desarrollo local

```bash
php -S localhost:3737
# abre http://localhost:3737
```

Sin `live-config.php`/`apifootball.key` el panel en vivo y las estadísticas
quedan deshabilitados, pero la app de apuestas funciona igual.

## 📦 Despliegue

Subir por FTP al hosting (Apache + PHP 8): `index.html`, `api.php`, `live.php`,
`schedule.json`, `.htaccess`. La API key va en `<data>/apifootball.key` o en
`live-config.php` (retorna el string de la key), nunca al repositorio.

---
Hecho con ❤️ para el Mundial 2026 🏆
