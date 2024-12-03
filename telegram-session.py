from telethon import TelegramClient
import asyncio
import os

# تنظیمات اولیه
api_id = "23933986"
api_hash = "f61a82f32627f793c85704c163bf2547"
session_dir = '/var/www/sessions'
session_file = os.path.join(session_dir, 'tel_session')

async def create_session():
    # ساخت دایرکتوری اگر وجود نداشت
    if not os.path.exists(session_dir):
        try:
            os.makedirs(session_dir)
            os.chmod(session_dir, 0o777)  # اعطای دسترسی کامل
        except Exception as e:
            print(f"Error creating sessions directory: {str(e)}")
            return
    
    # چک کردن وجود فایل سشن
    if os.path.exists(f"{session_file}.session"):
        print("Session file already exists!")
        return
        
    try:
        client = TelegramClient(session_file, api_id, api_hash)
        await client.start()
        print("Session created successfully!")
        await client.disconnect()
    except Exception as e:
        print(f"Error creating session: {str(e)}")

# اجرای اسکریپت
asyncio.run(create_session()) 