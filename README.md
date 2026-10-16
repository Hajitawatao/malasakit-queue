# Malasakit Queueing System — R1MC

Web-based queueing para sa Malasakit Center: **touchscreen kiosk**, **TV display** na may boses, at **caller sa bawat PC station**.

**Stack:** Laravel 12 (PHP 8.2+) · MySQL/MariaDB (XAMPP) · Vue 3 + Vite · Sanctum (login) · Web Speech API (boses) · lahat sa **port 88**

> Pang-install sa XAMPP: basahin ang **INSTALL.txt** (setup.bat → start.bat).

## Mga serbisyo (4)

| Code   | Pangalan        | Dati                               |
|--------|-----------------|------------------------------------|
| DOH    | MSS / DOH-MAIP  | Pinagsama ang Malasakit SW + DOH/MAIP |
| DSWD   | DSWD            |                                    |
| PHIC   | PhilHealth      |                                    |
| PAGCOR | PAGCOR          |                                    |
| ~~PCSO~~ | tinanggal     |                                    |

Palitan sa `database/seeders/DatabaseSeeder.php`.

## Ticket numbering

Nagre-reset kada araw, hiwalay kada **service** at **lane**:

| Lane      | Prefix | Para kanino                       |
|-----------|--------|-----------------------------------|
| Fast Lane | `F`    | Senior, PWD, buntis               |
| Priority  | `P`    | May referral / endorsement        |
| Regular   | `R`    | Lahat ng iba                      |

Kaya puwedeng sabay na may `DSWD F001` at `PAGCOR F001`. Laging kasama ang pangalan ng service sa display, printout at boses para walang kalituhan.

## Tatlong screen (iisang Vue SPA)

| URL         | Saan nakabukas            | Ginagawa |
|-------------|---------------------------|----------|
| `/kiosk?key=XXXX` | Touchscreen sa entrance | Pili ng service → lane → print ticket |
| `/display`  | TV sa waiting area        | Now Serving kada service, susunod na numero, malaking flash + boses tuwing may tinawag |
| `/counter`  | PC ng bawat bintana       | Login → Call Next / Recall / Dumating na / Tapos / No-show |

### Counter shortcut keys
`F1` Recall · `F2` Call Next · `F3` Fast Lane · `F4` Priority · `F5` Dumating na · `F6` Tapos · `F7` No-show

## Paano tinutukoy kung sino ang susunod

Ang **Call Next** ay *weighted round-robin*: default na **2 Fast : 1 Priority : 1 Regular** kada ikot (config `config/queue_system.php` o `.env`). Kapag walang laman ang isang lane, lilipat sa susunod. Ang **F3/F4** ay pilit na kukuha sa lane na iyon.

Bakit hindi purong "fast muna lagi": kapag maraming senior sa umaga, hindi na matatawag ang regular hanggang tanghali.

Pang-iwas sa dobleng tawag: `SELECT ... FOR UPDATE` sa ticket at sa number sequence, kaya ligtas kahit sabay pumindot ang dalawang bintana o dalawang tao sa kiosk.

## Daloy ng data

```
Kiosk ──POST /api/kiosk/tickets──▶ TicketIssuer ──▶ tickets (waiting)
                                         │
                                         └─▶ QueueUpdated ─┐
Counter ─POST /api/counter/call-next─▶ QueueCaller          │  Reverb (ws)
                                         ├─▶ TicketCalled ──┼──▶ TV: flash + boses
                                         └─▶ QueueUpdated ──┴──▶ TV/Counter: refetch snapshot
```
**Live updates = polling sa port 88** (walang websocket): TV bawat 2s, counter bawat 3s, kiosk bawat 10s. Ang TV ang tumitingin sa `calls` (huling 2 minuto) para malaman kung sino ang babasahin ng boses. Ang `TicketCalled`/`QueueUpdated` events ay naka-handa pa rin kung gusto ng websocket balang araw (`BROADCAST_CONNECTION=reverb`).

## Database

- `services` — 4 na ahensya
- `counters` — bawat bintana/PC (puwedeng marami kada service)
- `users` — staff; `counter_id` ang nagtatakda kung saang pila lang siya puwedeng tumawag
- `ticket_sequences` — huling numero kada service + lane + araw (naka-lock kapag nag-issue)
- `tickets` — status: `waiting → called → serving → done | no_show`, may timestamps para sa reports (waiting time, service time)

## Setup

```bash
composer create-project laravel/laravel malasakit-queue
cd malasakit-queue
# kopyahin ang mga file mula sa folder na ito (papalitan ang User.php, bootstrap/app.php, routes/*)

php artisan install:api          # Sanctum + routes/api.php
php artisan install:broadcasting # Reverb + laravel-echo + pusher-js
npm i vue vue-router axios @vitejs/plugin-vue
```

`vite.config.js` — idagdag ang `vue()` plugin:
```js
import vue from '@vitejs/plugin-vue'
plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }), vue()]
```

`config/services.php`:
```php
'queue_device_key' => env('QUEUE_DEVICE_KEY'),
```

`.env`:
```
APP_TIMEZONE=Asia/Manila
DB_CONNECTION=mysql
SESSION_DRIVER=database
SANCTUM_STATEFUL_DOMAINS=queue.local,192.168.1.10
QUEUE_DEVICE_KEY=palitan-ng-mahabang-random-string
BROADCAST_CONNECTION=reverb
QUEUE_WEIGHT_FAST=2
QUEUE_WEIGHT_PRIORITY=1
QUEUE_WEIGHT_REGULAR=1
```

```bash
php artisan migrate --seed       # gagawa ng doh1, dswd1, phic1, pagcor1, admin (password: changeme)
npm run build
php artisan reverb:start         # patakbuhin bilang Windows service / supervisor
```

Ilagay ang mga logo sa `public/logos/` at chime sa `public/sounds/chime.mp3`.

## Hardware setup (mahalaga)

**Kiosk printer — silent printing.** Hindi kayang mag-print ng browser nang walang dialog maliban kung naka-kiosk mode ang Chrome. Shortcut ng kiosk:
```
chrome.exe --kiosk --kiosk-printing "http://queue.local/kiosk?key=XXXX"
```
Gawing **default printer** ng Windows ang thermal printer (80mm), paper size 80mm, margins none.

**TV display — boses.** Hindi magpapatunog ang Chrome hangga't walang click, kaya may "I-tap para simulan" screen tuwing bubuksan. Para hindi na kailangan:
```
chrome.exe --kiosk --autoplay-policy=no-user-gesture-required "http://queue.local/display"
```
Ang boses ay galing sa Windows voices (English). Bihira ang may Filipino voice; ang Tagalog na linya ay babasahin ng English voice, kaya pakinggan muna kung ayos. Kung hindi, puwedeng palitan ng pre-recorded MP3 o cloud TTS sa `resources/js/lib/speech.js`.

## Hindi pa kasama (next steps)
- Admin page (CRUD ng services/counters/users) — sa ngayon seeder/tinker muna
- Reports: dami ng na-serve, average waiting time kada service/lane (nasa `tickets` na ang data)
- Transfer ng ticket sa ibang service (hal. DOH → PhilHealth na hindi na kukuha ulit ng numero)
