import os
from telethon import TelegramClient, events
from telethon.tl.functions.messages import GetBotCallbackAnswerRequest
import re
from datetime import datetime
import asyncio
import sys
import json
import logging
from logging.handlers import RotatingFileHandler

# تنظیمات امن‌تر برای لاگینگ
log_file = '/var/www/logs/telegram_service.log'
os.makedirs(os.path.dirname(log_file), exist_ok=True)

handler = RotatingFileHandler(log_file, maxBytes=10*1024*1024, backupCount=5)
formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
handler.setFormatter(formatter)

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)  # تغییر سطح لاگ به INFO
logger.addHandler(handler)

# خواندن کردنشیال‌ها از فایل محیطی
def get_credentials():
    try:
        with open('/var/www/config/.env', 'r') as f:
            config = dict(line.strip().split('=') for line in f if line.strip() and not line.startswith('#'))
        return config.get('API_ID'), config.get('API_HASH')
    except Exception as e:
        logger.error("Error reading credentials", exc_info=False)
        return None, None

async def main():
    try:
        if len(sys.argv) <= 1:
            logger.error("Bot ID not provided")
            print(json.dumps({"error": "شناسه ربات وارد نشده است"}))
            return

        # دریافت کردنشیال‌ها به صورت امن
        api_id, api_hash = get_credentials()
        if not api_id or not api_hash:
            print(json.dumps({"error": "خطا در خواندن تنظیمات"}))
            return

        session_file = '/var/www/sessions/telegram_session'
        bot_id = sys.argv[1]

        # بررسی امنیتی ورودی
        if not bot_id.isalnum():
            logger.warning("Invalid bot ID format")
            print(json.dumps({"error": "فرمت شناسه ربات نامعتبر است"}))
            return
        
        logger.info("Starting client")
        
        # تنظیم دسترسی‌های فایل سشن
        if os.path.exists(session_file):
            os.chmod(session_file, 0o600)
        
        client = TelegramClient(session_file, api_id, api_hash)
        await client.connect()
        
        if not await client.is_user_authorized():
            logger.error("No valid session found")
            print(json.dumps({"error": "نشست معتبر یافت نشد"}))
            await client.disconnect()
            return
            
        try:
            await client.send_message(bot_id, '/services')
            await asyncio.sleep(1)
            
            message = await client.get_messages(bot_id, limit=1)
            
            if message and message[0].reply_markup:
                await message[0].click(0, 0)
                await asyncio.sleep(1)
                
                response = await client.get_messages(bot_id, limit=1)
                
                if response and response[0].text:
                    text = response[0].text
                    
                    # استخراج اطلاعات با validation
                    total_volume = re.search(r'📦 حجم سرویس : (\d+(?:\.\d+)?)', text)
                    used_volume = re.search(r'📥 حجم مصرفی سرویس : (\d+(?:\.\d+)?)', text)
                    expiry_date = re.search(r'📆 تاریخ انقضای سرویس : (\d{4}/\d{2}/\d{2})', text)
                    
                    if not all([total_volume, used_volume]):
                        raise ValueError("مقادیر حجم یافت نشد")
                    
                    result = {
                        'total_volume': float(total_volume.group(1)),
                        'used_volume': float(used_volume.group(1)),
                        'expiry_date': expiry_date.group(1) if expiry_date else ''
                    }
                    
                    # اعتبارسنجی مقادیر
                    if result['total_volume'] < 0 or result['used_volume'] < 0:
                        raise ValueError("مقادیر حجم نامعتبر")
                    
                    print(json.dumps(result))
                else:
                    print(json.dumps({"error": "پاسخی از ربات دریافت نشد"}))
            else:
                print(json.dumps({"error": "منوی ربات در دسترس نیست"}))
    
        except ValueError as ve:
            logger.error(f"Validation error: {str(ve)}")
            print(json.dumps({"error": str(ve)}))
        except Exception as e:
            logger.error("Operation error", exc_info=False)
            print(json.dumps({"error": "خطا در عملیات"}))
        
        finally:
            await client.disconnect()
    
    except Exception as e:
        logger.error("Critical error", exc_info=False)
        print(json.dumps({"error": "خطای سیستمی"}))

if __name__ == "__main__":
    asyncio.run(main()) 