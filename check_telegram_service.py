from telethon import TelegramClient, events
from telethon.tl.functions.messages import GetBotCallbackAnswerRequest
import re
from datetime import datetime
import asyncio
import sys
import json

# تنظیمات اولیه
api_id = "23933986"
api_hash = "f61a82f32627f793c85704c163bf2547"
bot_username = "SvnProBot"

async def main():
    # ایجاد کلاینت
    client = await TelegramClient('session_name', api_id, api_hash).start()
    
    try:
        # دریافت bot_id از آرگومان‌های خط فرمان
        bot_id = sys.argv[1] if len(sys.argv) > 1 else "SvnProBot"
        
        # ارسال دستور /services به ربات
        await client.send_message(bot_id, '/services')
        await asyncio.sleep(1)
        
        # دریافت پیام حاوی دکمه‌ها
        message = await client.get_messages(bot_id, limit=1)
        if message and message[0].reply_markup:
            # کلیک روی دکمه
            await message[0].click(0, 0)
            await asyncio.sleep(1)
            
            # دریافت مستقیم آخرین پیام
            response = await client.get_messages(bot_id, limit=1)
            if response and response[0].text:
                text = response[0].text
                
                # استخراج اطلاعات
                total_volume = re.search(r'📦 حجم سرویس : (\d+(?:\.\d+)?)', text)
                used_volume = re.search(r'📥 حجم مصرفی سرویس : (\d+(?:\.\d+)?)', text)
                expiry_date = re.search(r'📆 تاریخ انقضای سرویس : (\d{4}/\d{2}/\d{2})', text)
                
                # به جای print، خروجی JSON تولید می‌کنیم
                result = {
                    'total_volume': float(total_volume.group(1)) if total_volume else 0,
                    'used_volume': float(used_volume.group(1)) if used_volume else 0,
                    'expiry_date': expiry_date.group(1) if expiry_date else ''
                }
                print(json.dumps(result))
    
    except Exception as e:
        print(f"خطا: {str(e)}")
    
    finally:
        await client.disconnect()

# اجرای اسکریپت
asyncio.run(main()) 