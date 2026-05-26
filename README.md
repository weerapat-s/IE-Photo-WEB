# IE-Photo Booking System

ระบบจองอุปกรณ์ถ่ายภาพและสตูดิโอ สำหรับนักศึกษา IE KMITL

**URL (Production):** https://ie-photo-web-production.up.railway.app

---

## โครงสร้างระบบ

```
IE-Photo-WEB/
├── admin/                  # หน้าสำหรับผู้ดูแลระบบ
│   ├── dashboard.php       # แดชบอร์ดสรุปภาพรวม
│   ├── bookings.php        # จัดการคำขอจอง (อนุมัติ/ปฏิเสธ/คืน)
│   ├── inventory.php       # คลังอุปกรณ์
│   └── users.php           # จัดการผู้ใช้
├── member/                 # หน้าสำหรับสมาชิก
│   ├── feed.php            # หน้าหลักหลัง login
│   ├── my_bookings.php     # ประวัติการจองของฉัน
│   ├── borrow_form.php     # ฟอร์มยืมอุปกรณ์
│   └── profile.php         # โปรไฟล์ผู้ใช้
├── auth/
│   ├── login.php           # เข้าสู่ระบบ
│   ├── register.php        # สมัครสมาชิก
│   └── logout.php
├── guest/
│   └── studio_booking.php  # จองสตูดิโอ (ไม่ต้อง login)
├── config/
│   └── database.php        # การเชื่อมต่อ MySQL (PDO)
├── includes/
│   ├── header.php
│   └── footer.php
├── assets/
│   └── css/glassmorphism.css   # Design system (Glassmorphism)
├── uploads/                # ไฟล์อัปโหลด (return images)
└── Dockerfile              # สำหรับ deploy บน Railway
```

---

## Stack & Technologies

| ส่วนประกอบ | รายละเอียด |
|-----------|-----------|
| Backend | PHP 8.2 (php:8.2-cli Docker image) |
| Database | MySQL 9.x (Railway managed) |
| Frontend | Vanilla HTML/CSS/JS + Glassmorphism design |
| Icons | Phosphor Icons |
| Fonts | Google Fonts (Inter + Kanit) |
| Hosting | Railway.app |
| Build | Docker (Dockerfile) |
| Version Control | GitHub — `weerapat-s/IE-Photo-WEB` |

---

## Database

### Connection (config/database.php)

ใช้ environment variables จาก Railway:

```php
$host     = getenv('MYSQLHOST')     ?: 'localhost';
$dbname   = getenv('MYSQLDATABASE') ?: 'iephotoo_booking';
$username = getenv('MYSQLUSER')     ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: '';
$port     = getenv('MYSQLPORT')     ?: '3306';
```

### Tables

| Table | คำอธิบาย |
|-------|---------|
| `users` | ข้อมูลผู้ใช้งาน (member + admin) |
| `equipments` | รายการอุปกรณ์ถ่ายภาพ |
| `studios` | ห้องสตูดิโอ |
| `bookings` | คำขอจอง/ยืม |
| `feeds` | Activity feed / log ต่างๆ |
| `feed_likes` | Likes บน feed |
| `urgent_contacts` | ข้อมูลติดต่อฉุกเฉิน |
| `email_consents` | ยินยอมรับอีเมล |
| `tasks` | งานที่ assign |

### Booking Status Flow

```
pending → approved → pending_return → returned
       ↘ rejected
       ↘ cancelled (โดยผู้ใช้)
```

---

## Railway Deployment

### Project Info

| รายการ | ค่า |
|-------|-----|
| Project | perfect-charm |
| Project ID | ea32da69-d9fd-4cb9-af56-819fc2d12548 |
| Environment | production |
| PHP Service ID | 9193a493-c73d-4b2d-9d66-48a35df1134a |
| MySQL Service | MySQL-Vh9X (ID: 068816d9-3a90-4c1b-b500-a18bdf5f5d56) |
| Domain | ie-photo-web-production.up.railway.app |

### MySQL Connection

| รายการ | ค่า |
|-------|-----|
| TCP Proxy Host | zephyr.proxy.rlwy.net |
| TCP Proxy Port | 33159 |
| Internal Host | mysql-vh9x.railway.internal |
| User | root |
| Database | railway |

> **หมายเหตุ:** ใช้ TCP Proxy แทน Internal Hostname เนื่องจาก Docker-based service ไม่มี private networking อัตโนมัติ (เฉพาะ Nixpacks เท่านั้น)

### Environment Variables บน PHP Service

```
MYSQLHOST     = zephyr.proxy.rlwy.net
MYSQLPORT     = 33159
MYSQLUSER     = root
MYSQLPASSWORD = gPcfLouEcoLaxRAdOFMjLintugZsiSSw
MYSQLDATABASE = railway
```

### Dockerfile

```dockerfile
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mysqli mbstring gd

WORKDIR /app
COPY . /app

CMD php -S 0.0.0.0:$PORT -t /app
```

> Railway inject `$PORT` ให้อัตโนมัติตอน runtime

---

## วิธี Deploy ครั้งแรก (ขั้นตอนที่ผ่านมา)

### 1. ติดตั้ง Railway CLI และ login

```bash
# ติดตั้ง Railway CLI (Windows)
# ดาวน์โหลดจาก https://railway.app/cli
railway login    # login ผ่าน GitHub OAuth
```

### 2. สร้าง Project และ MySQL

```bash
cd C:\xampp\htdocs\IE-Photo-WEB
railway init                          # สร้าง project ใหม่ชื่อ perfect-charm
railway add --database mysql --json   # เพิ่ม MySQL service
```

### 3. Push Code ขึ้น GitHub

```bash
git init
git remote add origin https://github.com/weerapat-s/IE-Photo-WEB.git
git add .
git commit -m "Initial commit"
git push origin main
```

### 4. สร้าง PHP Service และเชื่อม MySQL

ผ่าน Railway GraphQL API:

```bash
cd C:\Users\weerapat
node railway_create_service.js    # สร้าง PHP service จาก GitHub repo
node railway_link_mysql.js        # ตั้งค่า MySQL env vars + redeploy
```

### 5. Import Database

แก้ไข credentials ใน `C:\Users\weerapat\import_db.js` แล้วรัน:

```bash
cd C:\Users\weerapat
npm install mysql2    # ครั้งแรกเท่านั้น
node import_db.js
```

ผลลัพธ์ที่ถูกต้อง: `Tables: bookings, email_consents, equipments, feed_likes, feeds, studios, tasks, urgent_contacts, users`

---

# สุดท้าย

ระบบมันทำงานได้อยู่แล้ว

ถ้าพัง:

* 10% = บัคจริง
* 90% = มึงแก้อะไรแปลกๆ เอง

---

# Changelog — สิ่งที่แก้ไขทั้งหมด

---

## 🔐 Security Fixes

### `admin/users.php`
- เพิ่ม CSRF token ใน **ทั้งสอง form** (Desktop Table + Mobile Cards)
  - ก่อนหน้านี้ Desktop form ขาด `<input type="hidden" name="csrf_token">` ทำให้ได้รับ error "คำขอไม่ถูกต้อง"
- เพิ่มการ generate และ validate CSRF token ด้วย `hash_equals()` + `bin2hex(random_bytes(32))`
- เพิ่มฟีเจอร์ **รีเซ็ตรหัสผ่าน**: admin เปิด modal กรอกรหัสใหม่ + ยืนยัน พร้อม real-time check ตรงกัน
- เพิ่มฟีเจอร์ **ลบบัญชี**: modal ยืนยันก่อนลบ — ไม่สามารถลบบัญชีตัวเองได้
- แสดงสถานะยืนยันอีเมล (badge) ในตารางและ mobile card

### `config/database.php`
- `display_errors` แสดงเฉพาะบน localhost เท่านั้น — production ไม่โชว์ error ให้ user เห็น
- Error message บน production ซ่อน DB details ไว้ แสดงแค่ "ระบบขัดข้องชั่วคราว"

### `admin/bookings.php`
- ป้องกัน XSS: เพิ่ม `htmlspecialchars($success)`
- เปลี่ยนการตรวจสอบ MIME type ของรูปภาพจาก extension เป็น `finfo_file()` (ตรวจ content จริง)
- แก้ logic สถานะอุปกรณ์: reject → **ไม่** เปลี่ยนสถานะอุปกรณ์, คืนของ → available, อนุมัติ → borrowed

### `admin/contact_manage.php`
- เพิ่ม `htmlspecialchars()` ให้ `$custom_msg` ก่อน embed ใน email (ป้องกัน XSS injection)

### `admin/tasks.php`
- ป้องกัน XSS: เพิ่ม `htmlspecialchars($success)`
- แก้ email template: ใช้ student_id จริงของผู้รับงาน แทน hardcode `'Admin'`

### `admin/inventory.php`
- ป้องกัน XSS: เพิ่ม `htmlspecialchars($success)`

### `member/borrow_form.php`
- เปลี่ยนการตรวจสอบ MIME type เป็น `finfo_file()` (ป้องกัน extension spoofing)
- เพิ่มการตรวจ booking conflict: ไม่ให้จองอุปกรณ์ซ้ำช่วงเวลาเดิม

### `member/my_bookings.php`
- เปลี่ยนการตรวจสอบ MIME type เป็น `finfo_file()`
- ลบ dead code: `$newStatus = $return_image ? 'pending_return' : 'pending_return'`

### `member/profile.php`
- ป้องกัน PHP 8.1+ TypeError: เพิ่ม `$user['phone'] ?? ''` (NULL safety)
- เปลี่ยนการตรวจสอบ MIME type เป็น `finfo_file()`

---

## 🐛 Bug Fixes

### `guest/studio_booking.php`
- แก้ query `status = 'open'` → `status = 'available'` (ก่อนหน้าสตูดิโอไม่แสดงเลย เพราะ DB เก็บค่า `'available'`)
- เพิ่มการตรวจ booking conflict ก่อนสร้าง booking ใหม่

### `member/contact_list.php`
- ลบ column `contact_status` ออกจาก query (column ไม่มีใน DB → หน้าพังทันที)
- เพิ่ม `first_name`, `last_name` ใน query แสดงชื่อ-นามสกุลแทน

### `admin/contact_manage.php`
- เปลี่ยนจาก `INNER JOIN` → `LEFT JOIN` (ก่อนหน้าการจองสตูดิโอหายไปจากหน้าจัดการ)

### `auth/logout.php`
- แก้ redirect path `../auth/login.php` → `login.php` (path ซ้ำซ้อน)

### `includes/footer.php`
- แก้ `showToast()` ให้รองรับทั้ง `'danger'` และ `'error'` → แสดง ❌ และ border สีแดง

### `assets/js/main.js`
- ลบ `showToast()` ที่ define ซ้ำออก (canonical อยู่ใน `footer.php` แล้ว)

---

## ✨ New Features

### `auth/register.php`
- เพิ่มช่อง **ชื่อจริง** และ **นามสกุล** (แสดงแบบ side-by-side ด้วย `form-row`)
- เพิ่มปุ่ม show/hide password (`togglePwd()`)
- เพิ่ม validation: `mb_strlen()` สำหรับชื่อ-นามสกุล, phone regex `/^0[0-9]{8,9}$/`, password max 255
- Sticky values: ค่าที่กรอกไว้จะยังอยู่เมื่อ form มี error

### `auth/login.php`
- เพิ่ม validation: ถ้ากรอก email (มี `@`) ต้องเป็น `@kmitl.ac.th` เท่านั้น
- เพิ่ม JS real-time check: พิมพ์ email ผิด domain → ขึ้น hint + disable ปุ่ม login ทันที
- อัปเดต placeholder ให้ชัดเจนขึ้น
- Sticky value สำหรับ identifier field

---

## วิธีอัปเดตโค้ด (Deploy ใหม่)

```bash
cd C:\xampp\htdocs\IE-Photo-WEB

# แก้ไขโค้ด...

git add .
git commit -m "อธิบายการเปลี่ยนแปลง"
git push origin main
```

Railway จะ **build และ deploy อัตโนมัติ** ทุกครั้งที่ push ขึ้น GitHub

> ถ้า auto-deploy ไม่ทำงาน ให้รัน `railway up --detach` ใน folder โปรเจกต์

---

## Scripts ที่ใช้บ่อย

ไฟล์ทั้งหมดอยู่ที่ `C:\Users\weerapat\`

### import_db.js

นำเข้าฐานข้อมูลจาก `C:\xampp\htdocs\IE-Photo-WEB\iephotoo_booking.sql` ไปยัง Railway MySQL

```bash
cd C:\Users\weerapat
node import_db.js
```

### railway_link_mysql.js

ตั้งค่า MySQL environment variables บน PHP service และ trigger redeploy

```bash
node railway_link_mysql.js
```

### railway_create_service.js

สร้าง PHP service ใหม่บน Railway จาก GitHub repo (ใช้ครั้งแรกครั้งเดียว)

---

## Railway API Token

Token เก็บอยู่ที่ `C:\Users\weerapat\.railway\config.json`:

```json
{
  "user": {
    "accessToken": "KF-_hAr3EGlMOB40volUZmpFl1sXMFynY3E5yXlsUUp",
    "refreshToken": "K31pKD7PgmhTMk5Wa_sC4XnJDFyXVkmOR3yJTjkX8Y9"
  }
}
```

ใช้ token นี้ในการเรียก Railway GraphQL API:

```javascript
const TOKEN = 'KF-_hAr3EGlMOB40volUZmpFl1sXMFynY3E5yXlsUUp';
// Authorization: Bearer TOKEN
```

> ถ้า Token หมดอายุ ให้รัน `railway login` แล้วดู accessToken ใหม่จาก config.json

---

## การ Debug บน Railway

### ดู Logs แบบ Real-time

```bash
cd C:\xampp\htdocs\IE-Photo-WEB
railway logs --service ie-photo-web
```

### ดู Status ทุก Service

```bash
railway status
```

### ดู Deployment Logs ผ่าน Node.js

```javascript
const TOKEN = 'KF-_hAr3EGlMOB40volUZmpFl1sXMFynY3E5yXlsUUp';
// query { deploymentLogs(deploymentId: "...", limit: 50) { message severity } }
```

---

## ปัญหาที่เจอระหว่าง Deploy และวิธีแก้

### ปัญหา 1: "could not find driver" (pdo_mysql)

**สาเหตุ:** Railway ใช้ FrankenPHP (Nixpacks) ซึ่งไม่มี pdo_mysql ใน build

**วิธีแก้:** ใช้ `Dockerfile` แทน Nixpacks เพื่อ install `docker-php-ext-install pdo_mysql`

---

### ปัญหา 2: "Connection timed out" (mysql.railway.internal)

**สาเหตุ:** Docker-based service ไม่มี Railway private networking (`.railway.internal` DNS ไม่ resolve)

**วิธีแก้:** ใช้ TCP Proxy (`zephyr.proxy.rlwy.net:33159`) แทน internal hostname

---

### ปัญหา 3: "MySQL server has gone away"

**สาเหตุ:** MySQL service CRASHED — ไม่ใช่ SSL issue แต่เป็น resource/volume ของ Railway

**วิธีแก้:**

```javascript
// ลบ MySQL service เก่าผ่าน API
gql('mutation ServiceDelete($id: String!) { serviceDelete(id: $id) }', { id: 'SERVICE_ID' })

// สร้าง MySQL ใหม่ผ่าน CLI
railway add --database mysql --json

// Import ข้อมูลใหม่
node import_db.js
```

---

### ปัญหา 4: "Plugin 'mysql_native_password' is not loaded"

**สาเหตุ:** MySQL 9.x ถอด `mysql_native_password` ออกแล้ว

**วิธีแก้:** ใช้ `caching_sha2_password` (default ใน MySQL 9.x) — PHP PDO เชื่อมต่อได้ปกติถ้า MySQL ไม่บังคับ SSL (`require_secure_transport=OFF`)

---

### ปัญหา 5: Railway API Token หมดอายุ

**สาเหตุ:** OAuth access token หมดอายุ (ดู `tokenExpiresAt` ใน config.json)

**วิธีแก้:**

```bash
railway login    # login ใหม่ผ่าน GitHub OAuth
# ดู token ใหม่ใน C:\Users\weerapat\.railway\config.json → user.accessToken
```

---

### ปัญหา 6: node_modules ติดไปใน git

**สาเหตุ:** ลืม .gitignore ก่อน commit

**วิธีแก้:**

```bash
git rm -r --cached node_modules/ package.json package-lock.json
git add .gitignore
git commit -m "Remove node_modules from tracking"
```

---

### ปัญหา 7: Git push rejected (remote has newer commits)

**วิธีแก้:**

```bash
git stash
git pull origin main
git stash pop
# แก้ conflict (ถ้ามี)
git add .
git commit -m "Merge"
git push origin main
```

---

### ปัญหา 8: Cloudflare Tunnel URL เปลี่ยนทุกครั้ง

**สาเหตุ:** `cloudflared tunnel --url` สร้าง URL ชั่วคราวที่เปลี่ยนทุก session

**วิธีแก้:** Deploy บน Railway แทน — ได้ domain ถาวร

---

## UX Improvements ที่ทำ

### ทุกหน้า (Global)
- Glassmorphism design system
- Phosphor Icons (ph-bold)
- Responsive: Desktop Table + Mobile Cards
- แก้ `<div class="main-content animate-in">` → `<div class="animate-in">` ป้องกัน double padding

### member/feed.php
- Quick action cards (gradient orange = ยืมอุปกรณ์, gradient purple = จองสตูดิโอ)
- Active booking count banner
- `relTime($datetime)` — relative time ("2 ชั่วโมงที่แล้ว")
- Empty state พร้อม CTA link

### member/my_bookings.php
- Stat cards 4 ใบ (ทั้งหมด / รอ / อนุมัติ / คืนแล้ว)
- Banner เตือนถ้ามี booking ที่ approved
- Return modal + upload หลักฐานรูปภาพ
- `mbStatusBadge($status)` helper function

### member/borrow_form.php
- Step indicator 4 ขั้นตอน (เลือกอุปกรณ์ → กำหนดเวลา → แนบเอกสาร → ส่งคำขอ)
- Upload zone เปลี่ยนสีเป็นเขียวเมื่อเลือกไฟล์แล้ว
- Live validation วันที่ end ต้องมากกว่า start

### member/profile.php
- แสดงวันที่สมัครสมาชิก
- Logout button (danger color) ใน page header

### admin/bookings.php
- Filter tabs พร้อม count badge (All / Pending / Approved / Rejected / Returned)
- รองรับทั้ง `?filter=` และ `?status=` parameter
- Scroll + highlight booking row จาก dashboard link
- `bStatusBadge($status)` helper function

### admin/inventory.php
- Stat cards มี border-top สี + icon
- Equipment count badge ใน section header
- Auto-focus input เมื่อเปิด add form

### admin/users.php
- Member count badge ใน page header
- ลบ duplicate `<div class="bg-orbs">` ที่ซ้ำกัน

---

## Local Development (XAMPP)

```
URL: http://localhost/IE-Photo-WEB/auth/login.php
Database: iephotoo_booking (localhost MySQL)
```

`config/database.php` ใช้ค่า localhost อัตโนมัติถ้าไม่มี env vars:

```php
$host   = getenv('MYSQLHOST') ?: 'localhost';
$dbname = getenv('MYSQLDATABASE') ?: 'iephotoo_booking';
$user   = getenv('MYSQLUSER') ?: 'root';
$pass   = getenv('MYSQLPASSWORD') ?: '';
$port   = getenv('MYSQLPORT') ?: '3306';
```

---

## หมายเหตุสำคัญ

1. **MySQL อาจ Crash** ได้บน Railway free tier เนื่องจาก resource limits — ถ้าเว็บใช้งานไม่ได้ให้ดู Railway dashboard และ redeploy MySQL

2. **ต้อง reimport ข้อมูลถ้า MySQL ตาย** — Railway persist volume แต่ถ้า volume เสียหรือ service ถูกลบ ให้รัน `node import_db.js` ใหม่

3. **uploads/ ไม่ persistent** — ไฟล์รูปภาพที่ผู้ใช้อัปโหลด (หลักฐานการคืน) จะหายเมื่อ redeploy — ควรย้ายไป cloud storage (S3 / Cloudflare R2) ในอนาคต

4. **Railway Free Tier** ให้ $5 credit/เดือน — ถ้าเกิน Railway จะ pause services อัตโนมัติ

5. **TCP Proxy URL อาจเปลี่ยน** ถ้าลบ MySQL แล้วสร้างใหม่ — ต้องอัปเดต env vars บน PHP service ด้วย

---

*อัปเดตล่าสุด: 2026-05-25*

---

### ngrok → Cloudflare Tunnel
- เปลี่ยนจาก ngrok free tier ไปใช้ **Cloudflare Tunnel (cloudflared)**
- ngrok free tier มี browser warning interstitial — CSS/JS ไม่โหลดบน Instagram/LINE in-app browser และ iOS Safari
- cloudflared ไม่มี warning page → CSS โหลดได้ทุก browser ทุก device

**วิธีรัน Cloudflare Tunnel:**
```powershell
# เปิด Apache
Start-Process "C:\xampp\apache\bin\httpd.exe" -WindowStyle Hidden

# เปิด MySQL
Start-Process "C:\xampp\mysql\bin\mysqld.exe" -WorkingDirectory "C:\xampp\mysql\bin" -WindowStyle Hidden

# เปิด Cloudflare Tunnel
C:\Users\weerapat\cloudflared.exe tunnel --url http://localhost:80
```

> URL จะเปลี่ยนทุกครั้งที่รีสตาร์ท ถ้าอยากได้ URL คงที่ให้สมัคร Cloudflare account ฟรีแล้วสร้าง Named Tunnel

---

## 📋 สรุปไฟล์ที่ถูกแก้ไข

| ไฟล์ | สิ่งที่แก้ |
|---|---|
| `assets/css/glassmorphism.css` | z-index, touch fix, overflow, nav-overlay |
| `includes/header.php` | Navbar JS rewrite |
| `includes/footer.php` | showToast รองรับ danger/error |
| `assets/js/main.js` | ลบ showToast ซ้ำ |
| `config/database.php` | display_errors, production error message |
| `auth/login.php` | @kmitl.ac.th validation, sticky value |
| `auth/register.php` | เพิ่มชื่อ-นามสกุล, password toggle |
| `auth/logout.php` | แก้ redirect path |
| `guest/studio_booking.php` | status fix, conflict check |
| `member/contact_list.php` | ลบ column ที่ไม่มีใน DB |
| `member/borrow_form.php` | MIME check, conflict check |
| `member/my_bookings.php` | MIME check, ลบ dead code |
| `member/profile.php` | NULL safety, MIME check |
| `admin/users.php` | CSRF token ครบทั้ง 2 form |
| `admin/bookings.php` | XSS, MIME check, equipment status |
| `admin/contact_manage.php` | LEFT JOIN, XSS fix |
| `admin/tasks.php` | XSS, email student_id |
| `admin/inventory.php` | XSS fix |

---

## 🔒 Security & Stability Hardening (2026-05-26)

### บัคที่ตรวจพบจาก Code Review (3 จุด)

#### 1. `admin/bookings.php` — `throw new Exception` อยู่นอก try/catch → HTTP 500
- **สาเหตุ:** ตรวจขนาดไฟล์เกิน 8MB แล้ว `throw new Exception(...)` แต่ block นั้นอยู่นอก try/catch → server crash 500 ทันที
- **แก้:** เปลี่ยนเป็น `$error = 'ไฟล์ใหญ่เกิน 8 MB'` แล้วใช้ `if (empty($error)):` ... `endif;` คลุม transaction block

#### 2. `member/my_bookings.php` — fallthrough หลังตั้ง `$error` → DB update ยังทำงานต่อ
- **สาเหตุ:** ไฟล์เกิน 8MB ตั้ง `$error` แต่ code ด้านล่างยังรัน `UPDATE bookings SET status = 'pending_return'` ต่อ → booking เปลี่ยนสถานะโดยไม่มีรูปหลักฐาน
- **แก้:** เพิ่ม `if (empty($error)):` guard ครอบ `$checkStmt` และ transaction block ทั้งหมด

#### 3. `member/my_bookings.php` — feed message แสดง "พร้อมหลักฐาน" เสมอแม้ไม่มีรูป
- **สาเหตุ:** `$feedReturnMsg` ถูก hardcode เป็น "ส่งคืนอุปกรณ์ 📦 พร้อมหลักฐาน" โดยไม่ check ว่ามีรูปจริงหรือเปล่า
- **แก้:** เปลี่ยนเป็น ternary: `$return_image ? "...พร้อมหลักฐาน..." : "...(ไม่มีรูป)..."`

---

### CSRF Protection — ป้องกัน Cross-Site Request Forgery ทุกหน้า

**`config/database.php`** — เพิ่ม helper functions 3 ตัวที่ global scope:
```php
function csrf_token(): string   // สร้าง/คืน token ใน $_SESSION['csrf_token']
function csrf_input(): string   // คืน <input type="hidden" name="_csrf" value="...">
function csrf_verify(): bool    // ตรวจ hash_equals($stored, $submitted)
```
- ใช้ `bin2hex(random_bytes(32))` — cryptographically secure
- ตรวจสอบด้วย `hash_equals()` ป้องกัน timing attack

**ไฟล์ที่เพิ่ม CSRF:**
| ไฟล์ | สิ่งที่เพิ่ม |
|---|---|
| `auth/login.php` | `csrf_verify()` ต้นฟอร์ม + `<?php echo csrf_input();?>` ในฟอร์ม |
| `auth/register.php` | `csrf_verify()` + `csrf_input()` ในฟอร์ม |
| `member/borrow_form.php` | `csrf_verify()` + `csrf_input()` |
| `member/my_bookings.php` | `csrf_verify()` + `csrf_input()` ในทุก form (cancel, return modal, mobile) |
| `member/profile.php` | `csrf_verify()` + `csrf_input()` |
| `member/my_tasks.php` | `csrf_verify()` + `csrf_input()` |
| `guest/studio_booking.php` | `csrf_verify()` + `csrf_input()` |
| `admin/bookings.php` | `csrf_verify()` + `csrf_input()` ในทุก form (approve, reject, return) |
| `admin/inventory.php` | `csrf_verify()` + `csrf_input()` ในทุก 5 form |
| `admin/tasks.php` | `csrf_verify()` + `csrf_input()` ในทุก form (create, update, delete) |
| `admin/contact_manage.php` | `csrf_verify()` + `csrf_input()` ในทุก form |

> `admin/users.php` มีระบบ CSRF เดิมอยู่แล้วด้วย `hash_equals()` + `$_SESSION['csrf_token']` — compatible กัน ไม่ต้องแก้

---

### Login Rate Limiting — ป้องกัน Brute Force

**`config/database.php`** — เพิ่ม constants + helper functions:
```php
define('LOGIN_MAX_ATTEMPTS', 5);   // พยายามได้ 5 ครั้ง
define('LOGIN_LOCKOUT_SECS', 300); // ล็อก 5 นาที
function login_attempts(): int     // อ่านจำนวนครั้งจาก $_SESSION
function login_locked(): bool      // ตรวจว่าถูก lock อยู่หรือเปล่า (auto-reset เมื่อหมดเวลา)
function login_record_fail(): void // เพิ่มนับ + บันทึก timestamp
function login_reset(): void       // reset counter หลัง login สำเร็จ
function login_wait_secs(): int    // คำนวณวินาทีที่เหลือ
```

**`auth/login.php`** — เพิ่ม flow:
```php
if (!csrf_verify()) { $error = '...'; }
elseif (login_locked()) { $error = 'พยายามมากเกินไป รอ X วินาที'; }
else {
    if (password_verify(...)) { login_reset(); ... }
    else { login_record_fail(); usleep(300000); $error = '...'; }
}
```
- `usleep(300000)` = หน่วงเวลา 300ms ทุกครั้ง login ผิด → ป้องกัน timing attack

---

### `.htaccess` — Session Cookie & HTTP Security Headers (ไฟล์ใหม่)

สร้างไฟล์ `C:\xampp\htdocs\IE-Photo-WEB\.htaccess`:

```apache
# Session cookie hardening
php_value session.cookie_httponly  1    # JS ไม่เข้าถึง cookie ได้
php_value session.cookie_samesite  Strict
php_value session.use_strict_mode  1
php_value session.use_only_cookies 1
php_value session.use_trans_sid    0    # ปิด session ID ใน URL

# HTTP Security Headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options       "SAMEORIGIN"
Header always set Referrer-Policy       "strict-origin-when-cross-origin"

# ปิดการเข้าถึงไฟล์ sensitive
<FilesMatch "\.(env|log|sql|md|json|lock)$">
    Require all denied
</FilesMatch>

# ปิด Directory listing
Options -Indexes

# บล็อกการเข้าถึง config/ และ includes/ โดยตรง
RewriteRule ^config/  - [F,L]
RewriteRule ^includes/ - [F,L]
```

> เหตุผล: `session_start()` ถูกเรียกก่อน `require_once database.php` ทุกหน้า → `ini_set()` ใน PHP ไม่สามารถตั้งค่า cookie flags ของ session ที่เปิดไปแล้วได้ → ต้องใช้ `.htaccess` `php_value` ที่ apply ก่อน PHP script ทำงาน

---

### File Upload Hardening

**`admin/bookings.php`** + **`member/my_bookings.php`** + **`member/borrow_form.php`**:
- จำกัดขนาดไฟล์ **8 MB** ก่อนประมวลผล (ป้องกัน DoS จากไฟล์ขนาดใหญ่)
- ตรวจ return value ของ `move_uploaded_file()` — ถ้า return false → ตั้ง error ไม่บันทึก path

**`member/profile.php`**:
- จำกัดขนาดไฟล์ **4 MB** สำหรับรูปโปรไฟล์
- เพิ่ม `if (!$error)` guard ก่อนเริ่ม upload processing

**`member/borrow_form.php`**:
- เพิ่ม `htmlspecialchars($success, ENT_QUOTES, 'UTF-8')` สำหรับ success message — ป้องกัน XSS จากชื่ออุปกรณ์
- จัดการ `UPLOAD_ERR_NO_FILE` แยกจาก error อื่น

---

### `guest/studio_booking.php` — Validation & Error Isolation

- เพิ่ม `filter_var($guest_email, FILTER_VALIDATE_EMAIL)` ก่อน INSERT
- ครอบ INSERT ด้วย try/catch — error แสดงให้ user เห็นแทน 500
- แยก email notification ออกเป็น try/catch อิสระ — ถ้าส่ง email ล้มเหลวจะ `error_log()` เท่านั้น ไม่ rollback booking

---

### `admin/bookings.php` — Query Performance

- เพิ่ม `LIMIT 300` ใน main SELECT query — ป้องกันหน้าพังถ้ามี booking จำนวนมากมาก

---

### สรุปไฟล์ที่ถูกแก้ในรอบนี้

| ไฟล์ | สิ่งที่แก้ |
|---|---|
| `.htaccess` | **ไฟล์ใหม่** — session cookie hardening + HTTP headers + block sensitive paths |
| `config/database.php` | CSRF helper functions + login rate limiting + `define()` แทน `const` |
| `auth/login.php` | CSRF verify + rate limiting + `usleep(300000)` timing delay |
| `auth/register.php` | CSRF verify + csrf_input |
| `guest/studio_booking.php` | CSRF + email validation + try/catch + non-critical email isolation |
| `member/borrow_form.php` | CSRF + 8MB size limit + move_uploaded_file check + htmlspecialchars($success) |
| `member/my_bookings.php` | CSRF + fallthrough guard + feed message fix + 8MB limit |
| `member/profile.php` | CSRF + 4MB size limit + error guard |
| `member/my_tasks.php` | CSRF verify + csrf_input |
| `admin/bookings.php` | CSRF + exception→error fix + if(empty($error)) guard + LIMIT 300 |
| `admin/inventory.php` | CSRF verify + csrf_input ทุก form |
| `admin/tasks.php` | CSRF verify + csrf_input ทุก form |
| `admin/contact_manage.php` | CSRF verify + csrf_input ทุก form |

*อัปเดต: 2026-05-26 — commit `83c3db1` (security: CSRF protection + stability hardening across all pages)*
