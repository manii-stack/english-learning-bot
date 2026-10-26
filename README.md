# 🤖 AI English Tutor Telegram Bot & Multi-Language Web Admin Panel
### ربات هوشمند اصلاح و آموزش زبان انگلیسی تلگرام همراه با پنل مدیریت چندزبانه تحت وب

![PHP Version](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777BB4?logo=php&logoColor=white)
![Google Gemini](https://img.shields.io/badge/AI-Google%20Gemini-4285F4?logo=google&logoColor=white)
![Telegram Bot](https://img.shields.io/badge/Telegram-Bot%20API-24A1DE?logo=telegram&logoColor=white)
![Languages](https://img.shields.io/badge/Languages-7%20Supported-success)
![License](https://img.shields.io/badge/License-MIT-blue)
![Developer](https://img.shields.io/badge/Developer-Mohammadreza%20Sarmadi-orange)

---

## 👨‍💻 Developer & Author / توسعه‌دهنده
- **Developer:** **Mohammadreza Sarmadi** (محمدرضا سرمدی)
- **GitHub:** [@manii-stack](https://github.com/manii-stack)

---

## 🌐 Languages / زبان‌ها
- [English](#-english-documentation)
- [فارسی (Persian)](#-مستندات-فارسی)

---

# 🇬🇧 English Documentation

### Overview
**AI English Tutor Telegram Bot** is a high-performance, enterprise-grade PHP application designed for Telegram group chats and individual learning. Powered by Google Gemini AI, the bot passively monitors chat conversations, identifies grammatical mistakes, typos, and awkward phrases, and replies with friendly corrections, native alternatives, and concise explanations.

It includes an **autonomous Web Administration Panel (`admin.php`)** featuring **Multi-Key API Rotation**, **Secondary Fallback Model Selection**, **1-Click Webhook Registration**, and **7-Language Localization** with automatic RTL/LTR direction switching.

---

### ✨ Key Features

1. **🔑 Multi-Key Rotation & Zero Downtime (Failover Architecture):**
   - Add dozens of Google Gemini API keys in batch with a single copy-paste.
   - The bot automatically rotates keys upon quota exhaustion (HTTP 429 Rate Limit) without dropping any user messages.
   - Real-time health metrics per key: Success count, total requests, error codes, and last-used timestamp.

2. **🧠 Smart Dual-Model Quota Optimization:**
   - **Primary Model:** High-speed `gemini-3.5-flash-lite` (default).
   - **Secondary Model:** Reliable `gemini-3.1-flash-lite` (default fallback).
   - Since Google provides independent rate limits per model, when a key runs out of quota on the primary model, the bot automatically utilizes the secondary model on the *same key* before switching to the next key.

3. **🌍 Multilingual Admin Dashboard (7 Languages):**
   - Switch between **English, Persian, Turkish, French, German, Spanish, and Arabic** directly from the top navigation dropdown.
   - Dynamic layout direction support (`dir="ltr"` and `dir="rtl"`).
   - 100% self-hosted local typography and Bootstrap Icons (no external CDN dependencies).

4. **🔄 Inline Telegram "Try Another Way" Button:**
   - Adds an interactive inline button below corrected messages allowing group members to request alternative colloquial or professional phrasing for the same sentence.

5. **⚡ Smart Passive Reaction:**
   - If a sentence is already grammatically flawless and natural, the bot silently attaches a thumbs-up (👍) reaction instead of cluttering the chat with unnecessary text.

6. **🧪 Live AI Simulator & Debugging:**
   - Test any sentence directly from the admin panel to inspect model responses, key rotation behavior, and raw JSON payloads in real time.

---

### 📂 Directory Structure

```text
├── admin.php               # Master web administration dashboard
├── config.sample.php       # Sample configuration template
├── settings.php            # JSON-backed configuration and multi-key rotation engine
├── settings.sample.json    # Initial settings & model registry template
├── gemini.php              # Google Gemini AI communication & failover service
├── telegram.php            # Telegram Bot API wrapper (replies, reactions, inline keys)
├── translations.php        # 7-language i18n dictionary and helper class
├── webhook.php             # Incoming Telegram update processor
├── set_webhook.php         # Quick webhook setup helper
├── .htaccess               # Apache rules securing configuration and log files
├── assets/
│   ├── fonts/              # Self-hosted typography (IRANYekan WOFF2)
│   └── icons/              # Self-hosted Bootstrap Icons (CSS & WOFF2)
└── README.md               # Complete project documentation
```

---

### 🚀 Quick Installation Guide

#### 1. Clone the Repository
```bash
git clone https://github.com/manii-stack/english-learning-bot.git
cd english-learning-bot
```

#### 2. Configuration Setup
Copy the sample files:
```bash
cp config.sample.php config.php
cp settings.sample.json settings.json
```
Set proper file permissions on your hosting (e.g., cPanel, GoDaddy, Ubuntu, or Nginx):
```bash
chmod 664 settings.json
touch bot_log.txt && chmod 664 bot_log.txt
```

#### 3. Log In to Admin Panel
Open your browser and navigate to:
```text
https://your-domain.com/admin.php
```
- **Default Password:** `admin123`
- *(You can change this password immediately under the "Bot Settings" tab).*

#### 4. Configure Bot & Webhook
1. In the **Admin Panel**:
   - Go to **Bot Settings** and enter your Telegram Bot Token (obtained from `@BotFather`).
   - Go to **API Keys** and paste your Google Gemini API keys (obtained free from [Google AI Studio](https://aistudio.google.com/)).
   - Go to **Overview** and click **Register / Update Webhook**.
2. Add your bot as an **Administrator** in your Telegram group with "Send Messages" and "Add Reactions" permissions.

---

# 🇮🇷 مستندات فارسی

### معرفی پروژه
**ربات هوشمند آموزش و اصلاح زبان انگلیسی** یک اسکریپت حرفه‌ای و مقیاس‌پذیر تحت وب به زبان PHP است که با اتصال به پیشرفته‌ترین مدل‌های هوش مصنوعی **Google Gemini**، مکالمات گروه‌های تلگرام را پایش کرده و اشتباهات گرامری، املایی و نگارشی را شناسایی می‌کند. ربات به شکلی دوستانه و کاربردی، شکل صحیح، اصطلاحات محاوره‌ای معادل و توضیح گرامری کوتاه ارائه می‌دهد.

این پروژه مجهز به یک **پنل مدیریت تحت وب پیشرفته (`admin.php`)** با قابلیت **چرخش خودکار بین ده‌ها کلید API**، **پشتیبانی از مدل دوم جایگزین (Fallback)**، **ثبت ۱-کلیکه وب‌هوک** و **پشتیبانی کامل از ۷ زبان زنده دنیا** می‌باشد.

---

### 🌟 امکانات برجسته

1. **🔑 چرخش خودکار بین ده‌ها کلید (Multi-Key Rotation & Failover):**
   - امکان افزودن دسته‌ای نامحدود کلید API رایگان از Google AI Studio با یک کپی/پیست ساده.
   - در صورت اتمام سهمیه درخواستی یک کلید (خطای ۴۲۹)، ربات بدون هیچ‌گونه قطعی یا از دست رفتن پیام‌ها فوراً به کلید بعدی سوییچ می‌کند.
   - نمایش آمار دقیق هر کلید: تعداد درخواست‌های موفق، کدهای خطا و آخرین زمان استفاده.

2. **🧠 سهمیه دوگانه با مدل اول و دوم (Fallback Model):**
   - **مدل اصلی (Primary):** مدل بسیار سریع `gemini-3.5-flash-lite` (پیش‌فرض).
   - **مدل دوم (Secondary):** مدل پایدار و قدرتمند `gemini-3.1-flash-lite` (پیش‌فرض دوم).
   - گوگل برای هر مدل سهمیه RPM و RPD مستقلی ارائه می‌دهد؛ بنابراین با اتمام سهمیه مدل اول روی یک کلید، ربات ابتدا از سهمیه مدل دوم همان کلید استفاده کرده و سپس سراغ کلید بعدی می‌رود.

3. **🌍 پنل مدیریت ۷ زبانه با تغییر جهت خودکار (RTL / LTR):**
   - امکان انتخاب آنی زبان پنل از طریق منوی آبشاری بالای صفحه بین:
     - **فارسی (Persian)**
     - **انگلیسی (English)**
     - **ترکی (Turkish)**
     - **فرانسوی (French)**
     - **آلمانی (German)**
     - **اسپانیایی (Spanish)**
     - **عربی (Arabic)**
   - بهره‌گیری از فونت استاندارد ایران‌یکان و آیکون‌های محلی Bootstrap Icons بدون نیاز به CDN خارجی.

4. **🔄 دکمه شیشه‌ای «بررسی مجدد» در تلگرام (Retry Button):**
   - قرارگیری دکمه اینلاین شیشه‌ای زیر هر پیام تصحیح‌شده تا اعضای گروه با یک کلیک بتوانند معادل‌های غیررسمی، عامیانه یا رسمی متفاوتی را برای همان جمله دریافت نمایند.

5. **👍 ری‌اکشن هوشمند به جملات بی‌نقص:**
   - در صورتی که جمله کاربر از نظر گرامری کاملاً صحیح و روان باشد، ربات گروه را شلوغ نکرده و تنها یک ری‌اکشن لایک (👍) روی پیام ثبت می‌کند.

6. **🧪 شبیه‌ساز زنده هوش مصنوعی:**
   - بخش تست زنده در پنل مدیریت برای بررسی نحوه پاسخ مدل، چرخش کلیدها و خروجی تلگرام بدون نیاز به تست داخل گروه.

---

### 🚀 راهنمای نصب و راه‌اندازی سریع

#### ۱. کلون مخزن گیت‌هاب
```bash
git clone https://github.com/manii-stack/english-learning-bot.git
cd english-learning-bot
```

#### ۲. آماده‌سازی فایل‌های تنظیمات
فایل‌های نمونه را کپی کنید:
```bash
cp config.sample.php config.php
cp settings.sample.json settings.json
```
دسترسی نوشتن فایل‌ها را روی هاست (سی‌پنل، دایرکت‌ادمین، هاست ابری یا VPS) بررسی کنید:
```bash
chmod 664 settings.json
touch bot_log.txt && chmod 664 bot_log.txt
```

#### ۳. ورود به پنل مدیریت
در مرورگر آدرس فایل را باز کنید:
```text
https://your-domain.com/admin.php
```
- **رمز عبور پیش‌فرض:** `admin123`
- *(می‌توانید پسورد را بلافاصله از تب «تنظیمات ربات» تغییر دهید).*

#### ۴. تنظیم کلیدها و وب‌هوک
1. در تب **«تنظیمات ربات»**، توکن تلگرام خود را از BotFather@ وارد کرده و ذخیره کنید.
2. در تب **«مدیریت کلیدها»**، کلیدهای رایگان خود را از [Google AI Studio](https://aistudio.google.com/) وارد نمایید.
3. در تب **«وضعیت سیستم»**، دکمه **«ثبت / بروزرسانی وب‌هوک»** را بزنید.
4. ربات را به گروه انگلیسی خود اضافه کرده و دسترسی ارسال پیام به آن بدهید.

---

## 🔒 امنیت (Security)
- فایل‌های حساس مانند `settings.json`، `config.php` و `bot_log.txt` توسط کانفیگ `.htaccess` در سرورهای آپاچی/لایت‌اسپید از دسترسی مستقیم مرورگر مسدود شده‌اند.
- پشتیبانی از توکن مخفی وب‌هوک (`X-Telegram-Bot-Api-Secret-Token`) برای جلوگیری از درخواست‌های جعلی.
- محافظت پنل ادمین با هش و سشن امنیتی PHP.

---

## 📄 License
This project is open-sourced under the [MIT License](LICENSE).

---

## 👨‍💻 Author & Attribution
Developed with ❤️ by **Mohammadreza Sarmadi**
- GitHub: [https://github.com/manii-stack](https://github.com/manii-stack)
