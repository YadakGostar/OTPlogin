# OTP Login for Joomla 6+

**Version:** 1.0.0  
**Publisher:** Reza Esfandiari  
**Website:** https://www.yadakgostar.co  
**License:** GPL-2.0-or-later  
**Requirements:** Joomla 6+, PHP 8.3+

OTP login and registration extension for Joomla 6+ and the latest PHP. Publisher: Reza Esfandiari — https://www.yadakgostar.co

---

## فارسی

افزونه ورود و ثبت‌نام OTP ویژه جوملا ۶ و آخرین نسخه PHP. ناشر: Reza Esfandiari — https://www.yadakgostar.co

---

## Highlights / ویژگی‌های برجسته

1. **ورود و ثبت‌نام با کد یک‌بارمصرف (OTP)** از طریق پیامک  
2. **پشتیبانی IPPanel (ایران)** و **Twilio (بین‌المللی)** با سوییچ ساده در تنظیمات  
3. **سازگاری با کاربران قدیمی** جوملا و فروشگاه Hikashop (تشخیص شماره)  
4. **امنیت NIST-oriented:** کد کوتاه‌عمر، سقف تلاش، محدودیت نشست، ضدربات (Proof-of-Work) بدون سرویس خارجی  
5. **عکس پرسنلی زنده** فقط از دوربین (بدون آپلود فایل مخرب) + اصلاح جهت EXIF  
6. **ورود با Passkey / اثرانگشت (WebAuthn)** و **ورود با QR** محدود به نشست دستگاه  
7. **اعلان تلگرام** برای ثبت‌نام، ورود و خطای پیامک  
8. **داشبورد مدیریت مدرن:** آمار پیامک، نمودار ماهانه، کاربران آنلاین واقعی، لاگ خطا  
9. **رابط کاربری RTL/LTR** با تم گرادیان و Glass Morphism  
10. **سیاست رمز عبور** (رمزهای ضعیف/رایج رد می‌شوند؛ بررسی HIBP اختیاری)

---

## Install

1. Extension → Install → upload `pkg_otplogin-1.0.0.zip`  
2. Configure **Components → OTP Login** (SMS provider, limits)  
3. Publish the **OTP Login** module on your site  
4. Enable plugins: Authentication – OTP Login, User – OTP Login Photo  

---

## Feedback

The publisher warmly welcomes suggestions and improvements from the Joomla community.  
ناشر مشتاقانه منتظر پیشنهادات و بهبود افزونه از سوی دوستداران جوملا است.

- Website: https://www.yadakgostar.co  
- Author: Reza Esfandiari

---

## Security notes

- Prefer HTTPS in production (required for camera, Passkeys, PoW).  
- SMS is a restricted authenticator per NIST SP 800-63B; keep OTP short-lived and rate-limited.  
- Store API keys in environment variables when possible (`OTPLOGIN_IPPANEL_KEY`, `OTPLOGIN_TWILIO_SID`, `OTPLOGIN_TWILIO_TOKEN`).
