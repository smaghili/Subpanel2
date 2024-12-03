from telethon import TelegramClient, events
from telethon.tl.functions.messages import GetBotCallbackAnswerRequest
import re
from datetime import datetime
import asyncio
import sys
import json
import logging

# تنظیم لاگینگ
logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

async def main():
    try:
        if len(sys.argv) <= 1:
            logger.error("Bot ID not provided")
            print(json.dumps({"error": "لطفا شناسه ربات را وارد کنید"}))
            return

        # تنظیمات اولیه
        api_id = "23933986"
        api_hash = "f61a82f32627f793c85704c163bf2547"
        session_file = '/var/www/sessions/telegram_session'
        bot_id = sys.argv[1]
        
        logger.info(f"Starting client with bot_id: {bot_id}")
        
        # ایجاد کلاینت با استفاده از session موجود
        client = TelegramClient(session_file, api_id, api_hash)
        await client.connect()
        
        # بررسی وجود session
        if not await client.is_user_authorized():
            logger.error("No valid session found")
            print(json.dumps({"error": "لطفا ابتدا احراز هویت کنید"}))
            await client.disconnect()
            return
            
        logger.info("Client started successfully")
        
        try:
            # ارسال دستور /services به ربات
            logger.info("Sending /services command")
            await client.send_message(bot_id, '/services')
            await asyncio.sleep(1)
            
            # دریافت پیام حاوی دکمه‌ها
            logger.info("Getting messages")
            message = await client.get_messages(bot_id, limit=1)
            
            if message and message[0].reply_markup:
                logger.info("Message received with reply markup")
                # کلیک روی دکمه
                await message[0].click(0, 0)
                await asyncio.sleep(1)
                
                # دریافت مستقیم آخرین پیام
                logger.info("Getting response message")
                response = await client.get_messages(bot_id, limit=1)
                
                if response and response[0].text:
                    logger.info("Response received with text")
                    text = response[0].text
                    logger.debug(f"Response text: {text}")
                    
                    # استخراج اطلاعات
                    total_volume = re.search(r'📦 حجم سرویس : (\d+(?:\.\d+)?)', text)
                    used_volume = re.search(r'📥 حجم مصرفی سرویس : (\d+(?:\.\d+)?)', text)
                    expiry_date = re.search(r'📆 تاریخ انقضای سرویس : (\d{4}/\d{2}/\d{2})', text)
                    
                    result = {
                        'total_volume': float(total_volume.group(1)) if total_volume else 0,
                        'used_volume': float(used_volume.group(1)) if used_volume else 0,
                        'expiry_date': expiry_date.group(1) if expiry_date else ''
                    }
                    logger.info("Data extracted successfully")
                    print(json.dumps(result))
                else:
                    logger.error("No text in response message")
                    print(json.dumps({"error": "پیام دریافتی از ربات خالی است"}))
            else:
                logger.error("No reply markup in message")
                print(json.dumps({"error": "دکمه‌ای در پیام ربات یافت نشد"}))
    
        except Exception as e:
            logger.error(f"Error in Telegram operations: {str(e)}")
            print(json.dumps({"error": f"خطا در عملیات تلگرام: {str(e)}"}))
        
        finally:
            await client.disconnect()
            logger.info("Client disconnected")
    
    except Exception as e:
        logger.error(f"Critical error: {str(e)}")
        print(json.dumps({"error": f"خطای بحرانی: {str(e)}"}))

# اجرای اسکریپت
if __name__ == "__main__":
    asyncio.run(main()) 