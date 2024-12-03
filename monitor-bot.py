from telethon import TelegramClient, events
from telethon.tl.functions.messages import GetBotCallbackAnswerRequest
import re
from datetime import datetime
import jdatetime
import asyncio
import json
import sys
import os

# تنظیمات اولیه
api_id = "23933986"
api_hash = "f61a82f32627f793c85704c163bf2547"
session_dir = '/var/www/sessions'
session_file = os.path.join(session_dir, 'tel_session')

# تبدیل تاریخ شمسی به میلادی
def convert_persian_date(persian_date_str):
    try:
        # تبدیل رشته به اجزای عددی
        year, month, day = map(int, persian_date_str.split('/'))
        # تبدیل تاریخ شمسی به میلادی
        persian_date = jdatetime.date(year, month, day)
        gregorian_date = persian_date.togregorian()
        return gregorian_date.strftime('%Y-%m-%d')
    except Exception as e:
        print(f"Error converting date: {str(e)}")
        return None

# خواندن نام بات از فایل
def get_bot_username():
    try:
        with open('/var/www/config/bot_id.txt', 'r') as f:
            return f.read().strip()
    except:
        return None

async def check_service():
    # چک کردن وجود فایل سشن
    if not os.path.exists(f"{session_file}.session"):
        error_result = {
            "error": "Session file not found! Please create a session first.",
            "total_volume": 0,
            "used_volume": 0,
            "expiry_date": None,
            "days_left": 0
        }
        print(json.dumps(error_result))
        return
        
    # خواندن نام بات
    bot_username = get_bot_username()
    if not bot_username:
        error_result = {
            "error": "Bot username not found! Please set it in check_configs.php first.",
            "total_volume": 0,
            "used_volume": 0,
            "expiry_date": None,
            "days_left": 0
        }
        print(json.dumps(error_result))
        return

    # استفاده از session file موجود
    client = TelegramClient(session_file, api_id, api_hash)
    
    try:
        await client.connect()
        
        # چک کردن اعتبار سشن
        if not await client.is_user_authorized():
            error_result = {
                "error": "Session is invalid or expired. Please create a new session.",
                "total_volume": 0,
                "used_volume": 0,
                "expiry_date": None,
                "days_left": 0
            }
            print(json.dumps(error_result))
            return
            
        # ارسال دستور /services به ربات
        await client.send_message(bot_username, '/services')
        await asyncio.sleep(1)
        
        # دریافت پیام حاوی دکمه‌ها
        message = await client.get_messages(bot_username, limit=1)
        if message and message[0].reply_markup:
            await message[0].click(0, 0)
            await asyncio.sleep(1)
            
            # دریافت مستقیم آخرین پیام
            response = await client.get_messages(bot_username, limit=1)
            if response and response[0].text:
                text = response[0].text
                
                # استخراج اطلاعات
                total_volume = re.search(r'📦 حجم سرویس : (\d+(?:\.\d+)?)', text)
                used_volume = re.search(r'📥 حجم مصرفی سرویس : (\d+(?:\.\d+)?)', text)
                expiry_date = re.search(r'📆 تاریخ انقضای سرویس : (\d{4}/\d{2}/\d{2})', text)
                
                # تبدیل تاریخ شمسی به میلادی و محاسبه روزهای باقیمانده
                days_left = 0
                if expiry_date:
                    gregorian_date = convert_persian_date(expiry_date.group(1))
                    if gregorian_date:
                        expiry = datetime.strptime(gregorian_date, '%Y-%m-%d')
                        now = datetime.now()
                        days_left = (expiry - now).days
                
                # ساخت دیکشنری برای خروجی
                result = {
                    "total_volume": float(total_volume.group(1)) if total_volume else 0,
                    "used_volume": float(used_volume.group(1)) if used_volume else 0,
                    "expiry_date": expiry_date.group(1) if expiry_date else None,
                    "days_left": max(0, days_left)  # اگر منفی بود، صفر برمی‌گرداند
                }
                
                # چاپ نتیجه به صورت JSON
                print(json.dumps(result))
                
    except Exception as e:
        error_result = {
            "error": str(e),
            "total_volume": 0,
            "used_volume": 0,
            "expiry_date": None,
            "days_left": 0
        }
        print(json.dumps(error_result))
    
    finally:
        await client.disconnect()

def main():
    # حذف بخش init و اجرای مستقیم چک سرویس
    asyncio.run(check_service())

if __name__ == "__main__":
    main() 